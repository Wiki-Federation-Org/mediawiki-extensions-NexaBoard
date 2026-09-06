<?php

namespace MediaWiki\Extension\NexaBoard;

use MediaWiki\Extension\NexaBoard\Notifications\BoardNotificationManager;
use MediaWiki\Extension\NexaBoard\Store\FollowStore;
use MediaWiki\Extension\NexaBoard\Store\MessageStore;
use MediaWiki\Extension\NexaBoard\Store\ThreadStore;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Timestamp\ConvertibleTimestamp;

class BoardManager {

	public function __construct(
		private readonly ThreadStore $threadStore,
		private readonly MessageStore $messageStore,
		private readonly FollowStore $followStore,
		private readonly BoardNotificationManager $notificationManager,
		private readonly IConnectionProvider $dbProvider,
		private readonly UserFactory $userFactory
	) {}

	/**
	 * Write one entry to Special:Log.
	 *
	 * The target is the board owner's user page. Callers hold a board user *id*,
	 * so the name is resolved here — passing the raw id produced targets like
	 * "User:2".
	 */
	private function logAction(
		string $action,
		User $performer,
		int $boardUserId,
		array $params = [],
		string $reason = ''
	): void {
		$boardOwner = $this->userFactory->newFromId( $boardUserId );
		$target    = Title::makeTitleSafe( NS_USER, $boardOwner->getName() );

		if ( !$target ) {
			return;
		}

		$logEntry = new ManualLogEntry( 'nexaboard', $action );
		$logEntry->setPerformer( $performer );
		$logEntry->setTarget( $target );
		$logEntry->setComment( $reason );
		$logEntry->setParameters( $params );

		$logId = $logEntry->insert();
		$logEntry->publish( $logId );
	}

	public function createThread(
		int $boardUserId,
		User $author,
		string $title,
		string $body,
		NotificationMode $notify = NotificationMode::Send
	): array {
		$now = ConvertibleTimestamp::now( TS_MW );
		$dbw = $this->dbProvider->getPrimaryDatabase();

		$dbw->startAtomic( __METHOD__ );
		try {
			$threadId = $this->threadStore->insert( $boardUserId, $title, $now );
			$msgId    = $this->messageStore->insert(
				$threadId, true, $author->getId(), $author->getName(), $body, $now
			);
			$dbw->endAtomic( __METHOD__ );
		} catch ( \Exception $e ) {
			$dbw->rollback( __METHOD__ );
			throw $e;
		}

		// The board owner follows threads on their own board by default, so they
		// hear about replies even when they never posted in the thread.
		if ( $boardUserId !== $author->getId() ) {
			$this->followStore->setFollowing( $threadId, $boardUserId, true, $now );
		}

		if ( $notify === NotificationMode::Send ) {
			$boardOwner = $this->userFactory->newFromId( $boardUserId );

			$this->notificationManager->onThreadCreated(
				$threadId, $author, $boardOwner, $title, $body
			);
		}

		return [ 'thread_id' => $threadId, 'msg_id' => $msgId ];
	}

	public function reply(
		int $threadId,
		User $author,
		string $body,
		?int $quoteId = null,
		NotificationMode $notify = NotificationMode::Send,
		?int $parentId = null
	): int {
		$thread = $this->threadStore->getById( $threadId );

		if ( !$thread || (int)$thread->nbt_status !== ThreadStore::STATUS_OPEN ) {
			throw new \RuntimeException( 'Thread not found or not open' );
		}

		// A reply may only be attached to a live message in the same thread.
		if ( $parentId !== null ) {
			$parent = $this->messageStore->getById( $parentId );
			if (
				!$parent
				|| (int)$parent->nbm_thread_id !== $threadId
				|| (int)$parent->nbm_deleted
			) {
				throw new \RuntimeException( 'Parent message not found in this thread' );
			}
		}

		$now   = ConvertibleTimestamp::now( TS_MW );
		$msgId = $this->messageStore->insert(
			$threadId, false, $author->getId(), $author->getName(), $body, $now,
			$parentId, $quoteId
		);

		$this->threadStore->incrementReplyCount( $threadId, $now );

		if ( $notify === NotificationMode::Send ) {
			$boardOwner = $this->userFactory->newFromId( (int)$thread->nbt_board_user_id );

			$this->notificationManager->onReplyCreated(
				$threadId, $msgId, $author, $boardOwner,
				$thread->nbt_title, $body, $parentId
			);
		}

		return $msgId;
	}

	public function closeThread( int $threadId, User $closer, string $reason = '' ): bool {
		$thread = $this->threadStore->getById( $threadId );
		if ( !$thread ) {
			return false;
		}

		$now = ConvertibleTimestamp::now( TS_MW );
		if ( !$this->threadStore->close( $threadId, $closer->getId(), $now ) ) {
			return false;
		}

		$this->logAction(
			'close', $closer, (int)$thread->nbt_board_user_id,
			[ '4::thread' => $threadId, '5::title' => $thread->nbt_title ],
			$reason
		);

		return true;
	}

	public function reopenThread( int $threadId, User $actor, string $reason = '' ): bool {
		$thread = $this->threadStore->getById( $threadId );
		if ( !$thread ) {
			return false;
		}

		$now = ConvertibleTimestamp::now( TS_MW );
		if ( !$this->threadStore->reopen( $threadId, $now ) ) {
			return false;
		}

		$this->logAction(
			'reopen', $actor, (int)$thread->nbt_board_user_id,
			[ '4::thread' => $threadId, '5::title' => $thread->nbt_title ],
			$reason
		);

		return true;
	}

	/**
	 * Move a whole thread onto another user's board.
	 *
	 * Unlike merge and move, which deliberately refuse to cross boards, this is
	 * the sanctioned way across: a thread posted on the wrong person's board is
	 * carried over with its replies rather than deleted and retyped.
	 *
	 * @throws \RuntimeException if the thread is gone, the target is not a real
	 *   user, or the thread is already on that board.
	 */
	public function transferThread(
		int $threadId,
		int $targetBoardUserId,
		User $actor,
		string $reason = ''
	): bool {
		$thread = $this->threadStore->getById( $threadId );
		if ( !$thread ) {
			throw new \RuntimeException( 'Thread not found' );
		}

		$sourceBoardId = (int)$thread->nbt_board_user_id;
		if ( $sourceBoardId === $targetBoardUserId ) {
			throw new \RuntimeException( 'Thread is already on that board' );
		}

		$targetOwner = $this->userFactory->newFromId( $targetBoardUserId );
		if ( !$targetOwner || !$targetOwner->isRegistered() ) {
			throw new \RuntimeException( 'Target user not found' );
		}

		$now = ConvertibleTimestamp::now( TS_MW );
		if ( !$this->threadStore->setBoardUser( $threadId, $targetBoardUserId, $now ) ) {
			return false;
		}

		// The receiving board owner inherits the implicit follow that the original
		// owner got when the thread was created, so they hear about replies.
		$this->followStore->setFollowing( $threadId, $targetBoardUserId, true, $now );

		$sourceOwner = $this->userFactory->newFromId( $sourceBoardId );

		$this->logAction(
			'transfer', $actor, $targetBoardUserId,
			[
				'4::thread' => $threadId,
				'5::title'  => $thread->nbt_title,
				'6::from'   => $sourceOwner ? $sourceOwner->getName() : '',
			],
			$reason
		);

		return true;
	}

	public function deleteThread( int $threadId, User $deleter, string $reason = '' ): bool {
		$thread = $this->threadStore->getById( $threadId );
		if ( !$thread ) {
			return false;
		}

		$now = ConvertibleTimestamp::now( TS_MW );
		if ( !$this->threadStore->delete( $threadId, $deleter->getId(), $now ) ) {
			return false;
		}

		$this->logAction(
			'delete', $deleter, (int)$thread->nbt_board_user_id,
			[ '4::thread' => $threadId, '5::title' => $thread->nbt_title ],
			$reason
		);

		return true;
	}

	public function undeleteThread( int $threadId, User $actor, string $reason = '' ): bool {
		$thread = $this->threadStore->getById( $threadId );
		if ( !$thread ) {
			return false;
		}

		$now = ConvertibleTimestamp::now( TS_MW );
		if ( !$this->threadStore->undelete( $threadId, $now ) ) {
			return false;
		}

		$this->logAction(
			'undelete', $actor, (int)$thread->nbt_board_user_id,
			[ '4::thread' => $threadId, '5::title' => $thread->nbt_title ],
			$reason
		);

		return true;
	}

	public function deleteMessage( int $msgId, User $deleter, string $reason = '' ): bool {
		$msg = $this->messageStore->getById( $msgId );
		if ( !$msg ) {
			return false;
		}

		// Deleting the originating post would leave a thread that renders as
		// nothing; that is what deleting the thread is for.
		if ( (int)$msg->nbm_is_op ) {
			throw new \RuntimeException( 'Cannot delete the originating post; delete the thread instead' );
		}

		if ( !$this->messageStore->softDelete( $msgId, $deleter->getId() ) ) {
			return false;
		}

		$threadId = (int)$msg->nbm_thread_id;
		$this->threadStore->decrementReplyCount( $threadId );

		$thread = $this->threadStore->getById( $threadId );

		$this->logAction(
			'deletemsg', $deleter, $thread ? (int)$thread->nbt_board_user_id : 0,
			[ '4::msgid' => $msgId, '5::thread' => $threadId ],
			$reason
		);

		return true;
	}

	public function restoreMessage( int $msgId, User $actor, string $reason = '' ): bool {
		$msg = $this->messageStore->getById( $msgId );
		if ( !$msg || !$this->messageStore->restore( $msgId ) ) {
			return false;
		}

		$threadId = (int)$msg->nbm_thread_id;
		$this->threadStore->recalcReplyCount( $threadId );

		$thread = $this->threadStore->getById( $threadId );

		$this->logAction(
			'undeletemsg', $actor, $thread ? (int)$thread->nbt_board_user_id : 0,
			[ '4::msgid' => $msgId, '5::thread' => $threadId ],
			$reason
		);

		return true;
	}

	/**
	 * Soft-delete every reply in a thread, keeping the thread and its
	 * originating post.
	 *
	 * @return int Number of replies deleted
	 */
	public function deleteAllReplies( int $threadId, User $deleter, string $reason = '' ): int {
		$thread = $this->threadStore->getById( $threadId );
		if ( !$thread ) {
			return 0;
		}

		$count = $this->messageStore->softDeleteReplies( $threadId, $deleter->getId() );
		if ( !$count ) {
			return 0;
		}

		$this->threadStore->recalcReplyCount( $threadId );

		$this->logAction(
			'deletereplies', $deleter, (int)$thread->nbt_board_user_id,
			[ '4::thread' => $threadId, '5::count' => $count ],
			$reason
		);

		return $count;
	}

	/**
	 * Edit a message body, and the thread title too when editing the OP.
	 */
	public function editMessage(
		int $msgId,
		User $editor,
		string $body,
		?string $title = null,
		string $reason = ''
	): bool {
		$msg = $this->messageStore->getById( $msgId );
		if ( !$msg || (int)$msg->nbm_deleted ) {
			return false;
		}

		$threadId = (int)$msg->nbm_thread_id;
		$now      = ConvertibleTimestamp::now( TS_MW );
		$dbw      = $this->dbProvider->getPrimaryDatabase();

		$dbw->startAtomic( __METHOD__ );
		try {
			$this->messageStore->updateBody( $msgId, $body, $now, $editor->getId() );

			if ( $title !== null && (int)$msg->nbm_is_op ) {
				$this->threadStore->setTitle( $threadId, $title, $now );
			}

			$dbw->endAtomic( __METHOD__ );
		} catch ( \Exception $e ) {
			$dbw->rollback( __METHOD__ );
			throw $e;
		}

		$thread = $this->threadStore->getById( $threadId );

		$this->logAction(
			'edit', $editor, $thread ? (int)$thread->nbt_board_user_id : 0,
			[ '4::msgid' => $msgId, '5::thread' => $threadId ],
			$reason
		);

		return true;
	}

	public function setFollowing( int $threadId, User $user, bool $following ): void {
		$this->followStore->setFollowing(
			$threadId, $user->getId(), $following, ConvertibleTimestamp::now( TS_MW )
		);
	}

	public function mergeThreads(
		array $sourceThreadIds,
		int $targetThreadId,
		User $actor,
		string $reason = ''
	): void {
		$sourceThreadIds = array_values( array_unique( array_map( 'intval', $sourceThreadIds ) ) );
		$sourceThreadIds = array_filter( $sourceThreadIds, static fn( $id ) => $id !== $targetThreadId );

		if ( !$sourceThreadIds ) {
			return;
		}

		$target = $this->threadStore->getById( $targetThreadId );
		if ( !$target ) {
			throw new \RuntimeException( 'Target thread not found' );
		}

		$boardUserId = (int)$target->nbt_board_user_id;

		// Threads may only be merged within one board; merging across boards would
		// silently move one user's messages onto another user's page.
		foreach ( $sourceThreadIds as $srcId ) {
			$source = $this->threadStore->getById( $srcId );
			if ( !$source ) {
				throw new \RuntimeException( "Source thread $srcId not found" );
			}
			if ( (int)$source->nbt_board_user_id !== $boardUserId ) {
				throw new \RuntimeException( 'Threads belong to different boards' );
			}
		}

		$now = ConvertibleTimestamp::now( TS_MW );
		$dbw = $this->dbProvider->getPrimaryDatabase();

		$dbw->startAtomic( __METHOD__ );
		try {
			// Deleted messages move too; leaving them behind would strand them on
			// a thread that no longer renders.
			$messages = $this->messageStore->getByThreadIds( $sourceThreadIds );

			foreach ( $messages as $msg ) {
				$this->messageStore->moveToThread( (int)$msg->nbm_id, $targetThreadId );
			}

			foreach ( $sourceThreadIds as $srcId ) {
				$this->threadStore->markMerged( $srcId, $targetThreadId, $now );
			}

			$this->threadStore->recalcReplyCount( $targetThreadId );
			$this->threadStore->bumpUpdated( $targetThreadId, $now );

			$dbw->endAtomic( __METHOD__ );
		} catch ( \Exception $e ) {
			$dbw->rollback( __METHOD__ );
			throw $e;
		}

		$this->logAction(
			'merge', $actor, $boardUserId,
			[
				'4::target'  => $targetThreadId,
				'5::sources' => implode( ', ', $sourceThreadIds ),
				'6::count'   => count( $sourceThreadIds ),
			],
			$reason
		);
	}

	public function moveMessage(
		int $msgId,
		int $targetThreadId,
		User $actor,
		string $reason = ''
	): void {
		$msg = $this->messageStore->getById( $msgId );
		if ( !$msg ) {
			throw new \RuntimeException( 'Message not found' );
		}

		$sourceThreadId = (int)$msg->nbm_thread_id;

		if ( $sourceThreadId === $targetThreadId ) {
			return;
		}

		$source = $this->threadStore->getById( $sourceThreadId );
		$target = $this->threadStore->getById( $targetThreadId );

		if ( !$source || !$target ) {
			throw new \RuntimeException( 'Thread not found' );
		}

		if ( (int)$source->nbt_board_user_id !== (int)$target->nbt_board_user_id ) {
			throw new \RuntimeException( 'Threads belong to different boards' );
		}

		$now = ConvertibleTimestamp::now( TS_MW );
		$dbw = $this->dbProvider->getPrimaryDatabase();

		$dbw->startAtomic( __METHOD__ );
		try {
			$this->messageStore->moveToThread( $msgId, $targetThreadId );

			if ( !(int)$msg->nbm_is_op ) {
				$this->threadStore->decrementReplyCount( $sourceThreadId );
				$this->threadStore->incrementReplyCount( $targetThreadId, $now );
			}

			$this->threadStore->bumpUpdated( $sourceThreadId, $now );
			$this->threadStore->bumpUpdated( $targetThreadId, $now );

			$dbw->endAtomic( __METHOD__ );
		} catch ( \Exception $e ) {
			$dbw->rollback( __METHOD__ );
			throw $e;
		}

		$this->logAction(
			'move', $actor, (int)$source->nbt_board_user_id,
			[
				'4::msgid' => $msgId,
				'5::from'  => $sourceThreadId,
				'6::to'    => $targetThreadId,
			],
			$reason
		);
	}
}
