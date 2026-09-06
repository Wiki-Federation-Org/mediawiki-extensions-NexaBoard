<?php

namespace MediaWiki\Extension\NexaBoard\Tests\Integration;

use MediaWiki\Extension\NexaBoard\NotificationMode;
use MediaWiki\Extension\NexaBoard\Store\FollowStore;
use MediaWiki\Extension\NexaBoard\Store\MessageStore;
use MediaWiki\Extension\NexaBoard\Store\ThreadStore;
use MediaWiki\Extension\NexaBoard\BoardManager;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;
use RuntimeException;

/**
 * @group Database
 * @group NexaBoard
 * @covers \MediaWiki\Extension\NexaBoard\BoardManager
 * @covers \MediaWiki\Extension\NexaBoard\Store\FollowStore
 */
class BoardManagerTest extends MediaWikiIntegrationTestCase {

	private function manager(): BoardManager {
		return $this->getServiceContainer()->get( 'NexaBoard.Manager' );
	}

	private function threadStore(): ThreadStore {
		return $this->getServiceContainer()->get( 'NexaBoard.ThreadStore' );
	}

	private function messageStore(): MessageStore {
		return $this->getServiceContainer()->get( 'NexaBoard.MessageStore' );
	}

	private function followStore(): FollowStore {
		return $this->getServiceContainer()->get( 'NexaBoard.FollowStore' );
	}

	private function actor(): User {
		return $this->getTestSysop()->getUser();
	}

	/**
	 * A thread with an originating post and one reply, notifications suppressed.
	 */
	private function seedThread( User $actor, string $title = 'Subject' ): array {
		return $this->manager()->createThread(
			$actor->getId(), $actor, $title, 'Body', NotificationMode::Suppress
		);
	}

	public function testReplyRecordsItsParent(): void {
		$actor = $this->actor();
		$thread = $this->seedThread( $actor );

		$first = $this->manager()->reply(
			$thread['thread_id'], $actor, 'First', null, NotificationMode::Suppress
		);
		$second = $this->manager()->reply(
			$thread['thread_id'], $actor, 'Nested', null, NotificationMode::Suppress, $first
		);

		$row = $this->messageStore()->getById( $second );
		$this->assertSame( $first, (int)$row->nbm_parent_id );
	}

	public function testReplyRejectsParentFromAnotherThread(): void {
		$actor = $this->actor();
		$a = $this->seedThread( $actor, 'A' );
		$b = $this->seedThread( $actor, 'B' );

		$this->expectException( RuntimeException::class );
		$this->manager()->reply(
			$a['thread_id'], $actor, 'Bad parent', null,
			NotificationMode::Suppress, $b['msg_id']
		);
	}

	public function testCloseThenReopenRoundTrips(): void {
		$actor = $this->actor();
		$thread = $this->seedThread( $actor );
		$id = $thread['thread_id'];

		$this->assertTrue( $this->manager()->closeThread( $id, $actor ) );
		$this->assertSame(
			ThreadStore::STATUS_CLOSED,
			(int)$this->threadStore()->getById( $id )->nbt_status
		);

		$this->assertTrue( $this->manager()->reopenThread( $id, $actor ) );

		$row = $this->threadStore()->getById( $id );
		$this->assertSame( ThreadStore::STATUS_OPEN, (int)$row->nbt_status );
		$this->assertNull( $row->nbt_closed_by, 'reopening clears who closed it' );
	}

	public function testClosedThreadRefusesReplies(): void {
		$actor = $this->actor();
		$thread = $this->seedThread( $actor );
		$this->manager()->closeThread( $thread['thread_id'], $actor );

		$this->expectException( RuntimeException::class );
		$this->manager()->reply(
			$thread['thread_id'], $actor, 'Too late', null, NotificationMode::Suppress
		);
	}

	public function testUndeleteRestoresAClosedThreadToClosed(): void {
		$actor = $this->actor();
		$thread = $this->seedThread( $actor );
		$id = $thread['thread_id'];

		$this->manager()->closeThread( $id, $actor );
		$this->manager()->deleteThread( $id, $actor );
		$this->assertTrue( $this->manager()->undeleteThread( $id, $actor ) );

		$this->assertSame(
			ThreadStore::STATUS_CLOSED,
			(int)$this->threadStore()->getById( $id )->nbt_status,
			'a thread closed before deletion must not silently reopen'
		);
	}

	public function testDeletedThreadsAreHiddenButRecoverable(): void {
		$actor = $this->actor();
		$boardId = $actor->getId();
		$thread = $this->seedThread( $actor );

		$this->manager()->deleteThread( $thread['thread_id'], $actor );

		$visible = $this->threadStore()->getByBoardUser( $boardId, 50 );
		$this->assertCount( 0, $visible );

		$all = $this->threadStore()->getByBoardUser( $boardId, 50, null, true );
		$this->assertCount( 1, $all );
	}

	public function testEditUpdatesBodyTimestampAndTitle(): void {
		$actor = $this->actor();
		$thread = $this->seedThread( $actor );

		$this->assertTrue( $this->manager()->editMessage(
			$thread['msg_id'], $actor, 'New body', 'New title'
		) );

		$msg = $this->messageStore()->getById( $thread['msg_id'] );
		$this->assertSame( 'New body', $msg->nbm_body );
		$this->assertNotNull( $msg->nbm_edited );

		$this->assertSame(
			'New title',
			$this->threadStore()->getById( $thread['thread_id'] )->nbt_title
		);
	}

	public function testDeletingAReplyAdjustsTheReplyCount(): void {
		$actor = $this->actor();
		$thread = $this->seedThread( $actor );
		$replyId = $this->manager()->reply(
			$thread['thread_id'], $actor, 'A reply', null, NotificationMode::Suppress
		);

		$this->assertSame(
			1, (int)$this->threadStore()->getById( $thread['thread_id'] )->nbt_reply_count
		);

		$this->manager()->deleteMessage( $replyId, $actor );
		$this->assertSame(
			0, (int)$this->threadStore()->getById( $thread['thread_id'] )->nbt_reply_count
		);

		$this->manager()->restoreMessage( $replyId, $actor );
		$this->assertSame(
			1, (int)$this->threadStore()->getById( $thread['thread_id'] )->nbt_reply_count
		);
	}

	public function testTheOriginatingPostCannotBeDeletedOnItsOwn(): void {
		$actor = $this->actor();
		$thread = $this->seedThread( $actor );

		$this->expectException( RuntimeException::class );
		$this->manager()->deleteMessage( $thread['msg_id'], $actor );
	}

	public function testDeleteAllRepliesKeepsTheOriginatingPost(): void {
		$actor = $this->actor();
		$thread = $this->seedThread( $actor );
		$this->manager()->reply( $thread['thread_id'], $actor, 'One', null, NotificationMode::Suppress );
		$this->manager()->reply( $thread['thread_id'], $actor, 'Two', null, NotificationMode::Suppress );

		$deleted = $this->manager()->deleteAllReplies( $thread['thread_id'], $actor );

		$this->assertSame( 2, $deleted );
		$this->assertSame(
			0, (int)$this->threadStore()->getById( $thread['thread_id'] )->nbt_reply_count
		);
		$this->assertNotNull(
			$this->messageStore()->getOpByThread( $thread['thread_id'] ),
			'the thread itself survives'
		);
	}

	public function testFollowStateRoundTrips(): void {
		$actor = $this->actor();
		$thread = $this->seedThread( $actor );
		$id = $thread['thread_id'];

		$this->assertNull(
			$this->followStore()->getExplicitState( $id, $actor->getId() ),
			'no explicit state until the user chooses one'
		);

		$this->manager()->setFollowing( $id, $actor, true );
		$this->assertTrue( $this->followStore()->getExplicitState( $id, $actor->getId() ) );

		$this->manager()->setFollowing( $id, $actor, false );
		$this->assertFalse( $this->followStore()->getExplicitState( $id, $actor->getId() ) );

		$states = $this->followStore()->getFollowStates( $id );
		$this->assertContains( $actor->getId(), $states['muted'] );
	}

	public function testMergeMovesDeletedMessagesAndKeepsSourceTitle(): void {
		$actor = $this->actor();
		$target = $this->seedThread( $actor, 'Target' );
		$source = $this->seedThread( $actor, 'Source' );

		$replyId = $this->manager()->reply(
			$source['thread_id'], $actor, 'Doomed', null, NotificationMode::Suppress
		);
		$this->manager()->deleteMessage( $replyId, $actor );

		$this->manager()->mergeThreads(
			[ $source['thread_id'] ], $target['thread_id'], $actor
		);

		$this->assertSame(
			$target['thread_id'],
			(int)$this->messageStore()->getById( $replyId )->nbm_thread_id,
			'a deleted message must not be stranded on the merged-away thread'
		);

		$titles = $this->threadStore()->getMergedSourceTitles( [ $target['thread_id'] ] );
		$this->assertSame( [ 'Source' ], $titles[$target['thread_id']] );
	}

	public function testMergeAcrossBoardsIsRefused(): void {
		$actor = $this->actor();
		$mine = $this->seedThread( $actor, 'Mine' );

		$otherBoardId = $actor->getId() + 1000;
		$theirs = $this->manager()->createThread(
			$otherBoardId, $actor, 'Theirs', 'Body', NotificationMode::Suppress
		);

		$this->expectException( RuntimeException::class );
		$this->manager()->mergeThreads(
			[ $theirs['thread_id'] ], $mine['thread_id'], $actor
		);
	}

	public function testMoveAcrossBoardsIsRefused(): void {
		$actor = $this->actor();
		$mine = $this->seedThread( $actor, 'Mine' );

		$otherBoardId = $actor->getId() + 1000;
		$theirs = $this->manager()->createThread(
			$otherBoardId, $actor, 'Theirs', 'Body', NotificationMode::Suppress
		);

		$this->expectException( RuntimeException::class );
		$this->manager()->moveMessage( $theirs['msg_id'], $mine['thread_id'], $actor );
	}

	/**
	 * nbt_updated is only second-accurate, so a board routinely has several
	 * threads sharing one timestamp. Paging on the timestamp alone skipped every
	 * thread that shared a page boundary; the composite (updated, id) cursor is
	 * what stops threads disappearing between pages.
	 */
	public function testPagingIsStableWhenTimestampsAreTied(): void {
		$actor  = $this->actor();
		$boardId = $actor->getId();
		$ts     = $this->threadStore();

		$ids = [];
		for ( $i = 0; $i < 6; $i++ ) {
			$ids[] = $ts->insert( $boardId, "Tied $i", '20260101000000' );
		}

		$seen  = [];
		$cursor = null;

		// Walk the whole board two at a time, exactly as the special page does.
		for ( $page = 0; $page < 5; $page++ ) {
			$rows = $ts->getByBoardUser( $boardId, 3, $cursor );
			$more = count( $rows ) > 2;
			$rows = array_slice( $rows, 0, 2 );

			if ( !$rows ) {
				break;
			}

			foreach ( $rows as $row ) {
				$seen[] = (int)$row->nbt_id;
			}

			if ( !$more ) {
				break;
			}

			$last   = end( $rows );
			$cursor = [ $last->nbt_updated, (int)$last->nbt_id ];
		}

		sort( $ids );
		$unique = array_unique( $seen );
		sort( $unique );

		$this->assertSame( $ids, $unique, 'every thread is reachable by paging' );
		$this->assertSame( count( $seen ), count( $unique ), 'no thread is served twice' );
	}

	public function testModerationActionsAreLogged(): void {
		$actor = $this->actor();
		$thread = $this->seedThread( $actor );
		$id = $thread['thread_id'];

		$this->manager()->closeThread( $id, $actor, 'because' );
		$this->manager()->reopenThread( $id, $actor );
		$this->manager()->deleteThread( $id, $actor );
		$this->manager()->undeleteThread( $id, $actor );

		$rows = $this->getDb()->newSelectQueryBuilder()
			->select( [ 'log_action', 'log_title' ] )
			->from( 'logging' )
			->where( [ 'log_type' => 'nexaboard' ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		$actions = [];
		foreach ( $rows as $row ) {
			$actions[] = $row->log_action;
			$this->assertFalse(
				ctype_digit( $row->log_title ),
				'the log target must be a user name, not a raw user id'
			);
		}

		foreach ( [ 'close', 'reopen', 'delete', 'undelete' ] as $expected ) {
			$this->assertContains( $expected, $actions );
		}
	}

	public function testTransferMovesThreadAndRepliesToAnotherBoard(): void {
		$actor  = $this->actor();
		$target = $this->getTestUser( 'sysop' )->getUser();
		$this->assertNotSame( $actor->getId(), $target->getId(), 'need two distinct users' );

		$thread = $this->seedThread( $actor, 'Wrong board' );
		$this->manager()->reply(
			$thread['thread_id'], $actor, 'A reply', null, NotificationMode::Suppress
		);

		$this->assertTrue( $this->manager()->transferThread(
			$thread['thread_id'], $target->getId(), $actor, 'moved'
		) );

		$row = $this->threadStore()->getById( $thread['thread_id'] );
		$this->assertSame( $target->getId(), (int)$row->nbt_board_user_id );
		$this->assertSame( 1, (int)$row->nbt_reply_count, 'replies travel with the thread' );

		$ids = static fn ( array $rows ) => array_map( static fn ( $r ) => (int)$r->nbt_id, $rows );
		$this->assertNotContains(
			$thread['thread_id'],
			$ids( $this->threadStore()->getByBoardUser( $actor->getId(), 50 ) ),
			'no longer listed on the original board'
		);
		$this->assertContains(
			$thread['thread_id'],
			$ids( $this->threadStore()->getByBoardUser( $target->getId(), 50 ) ),
			'listed on the destination board'
		);
	}

	public function testTransferToTheSameBoardIsRefused(): void {
		$actor  = $this->actor();
		$thread = $this->seedThread( $actor );

		$this->expectException( RuntimeException::class );
		$this->manager()->transferThread( $thread['thread_id'], $actor->getId(), $actor );
	}

	public function testTransferSubscribesTheReceivingBoardOwner(): void {
		$actor  = $this->actor();
		$target = $this->getTestUser( 'sysop' )->getUser();
		$thread = $this->seedThread( $actor );

		$this->manager()->transferThread( $thread['thread_id'], $target->getId(), $actor );

		$this->assertTrue(
			$this->followStore()->getExplicitState( $thread['thread_id'], $target->getId() ),
			'the new board owner hears about replies'
		);
	}

	public function testDeletedThreadIsNotTransferred(): void {
		$actor  = $this->actor();
		$target = $this->getTestUser( 'sysop' )->getUser();
		$thread = $this->seedThread( $actor );

		$this->manager()->deleteThread( $thread['thread_id'], $actor );

		$this->assertFalse(
			$this->manager()->transferThread( $thread['thread_id'], $target->getId(), $actor ),
			'a thread nobody can see is not handed to another board'
		);
		$this->assertSame(
			$actor->getId(),
			(int)$this->threadStore()->getById( $thread['thread_id'] )->nbt_board_user_id
		);
	}

	/**
	 * Moderators see deleted replies as tombstones, so the raw row count is not
	 * the count a reader should be shown.
	 */
	public function testDeletedRepliesLeaveAZeroReplyCount(): void {
		$actor  = $this->actor();
		$thread = $this->seedThread( $actor );

		$reply = $this->manager()->reply(
			$thread['thread_id'], $actor, 'Only reply', null, NotificationMode::Suppress
		);
		$this->assertSame(
			1, (int)$this->threadStore()->getById( $thread['thread_id'] )->nbt_reply_count
		);

		$this->manager()->deleteMessage( $reply, $actor );

		$this->assertSame(
			0,
			(int)$this->threadStore()->getById( $thread['thread_id'] )->nbt_reply_count,
			'the stored count drops'
		);
		$this->assertCount(
			0,
			$this->messageStore()->getRepliesByThread( $thread['thread_id'], false ),
			'nothing live is left to count'
		);
		$this->assertCount(
			1,
			$this->messageStore()->getRepliesByThread( $thread['thread_id'], true ),
			'but the tombstone is still there for moderators'
		);
	}


	/**
	 * A merge moves every message onto the destination, so the source is left
	 * with nothing. It must still be findable, or the merge is indistinguishable
	 * from the thread having been destroyed.
	 */
	public function testMergedSourceStaysVisibleToModerators(): void {
		$actor  = $this->actor();
		$source = $this->seedThread( $actor, 'Same headline' );
		$target = $this->seedThread( $actor, 'Same headline' );

		$this->manager()->mergeThreads(
			[ $source['thread_id'] ], $target['thread_id'], $actor, 'duplicate'
		);

		$row = $this->threadStore()->getById( $source['thread_id'] );
		$this->assertSame( ThreadStore::STATUS_MERGED, (int)$row->nbt_status );
		$this->assertSame( $target['thread_id'], (int)$row->nbt_merged_into );

		$ids = static fn ( array $rows ) => array_map( static fn ( $r ) => (int)$r->nbt_id, $rows );

		$this->assertNotContains(
			$source['thread_id'],
			$ids( $this->threadStore()->getByBoardUser( $actor->getId(), 50 ) ),
			'hidden from the ordinary board view'
		);
		$this->assertContains(
			$source['thread_id'],
			$ids( $this->threadStore()->getByBoardUser( $actor->getId(), 50, null, true ) ),
			'but a moderator can still find where it went'
		);
	}

	public function testMergedSourceKeepsNoMessagesOfItsOwn(): void {
		$actor  = $this->actor();
		$source = $this->seedThread( $actor );
		$target = $this->seedThread( $actor );

		$this->manager()->mergeThreads(
			[ $source['thread_id'] ], $target['thread_id'], $actor
		);

		$this->assertNull(
			$this->messageStore()->getOpByThread( $source['thread_id'] ),
			'the originating post moved to the destination with everything else'
		);
		$this->assertNotNull(
			$this->messageStore()->getOpByThread( $target['thread_id'] )
		);
	}


	public function testCloseRecordsWhoClosedAndReopenClearsIt(): void {
		$actor  = $this->actor();
		$thread = $this->seedThread( $actor );

		$this->manager()->closeThread( $thread['thread_id'], $actor );
		$this->assertSame(
			$actor->getId(),
			(int)$this->threadStore()->getById( $thread['thread_id'] )->nbt_closed_by,
			'a reopen check needs to know who closed it'
		);

		$this->manager()->reopenThread( $thread['thread_id'], $actor );
		$this->assertNull(
			$this->threadStore()->getById( $thread['thread_id'] )->nbt_closed_by
		);
	}

	/**
	 * A board owner may undo their own close, but not a moderator's — otherwise
	 * a moderation decision is reversible by the person it was aimed at.
	 */
	public function testOnlyTheCloserOrAModeratorMayReopen(): void {
		$moderator = $this->actor();
		$owner     = $this->getTestUser()->getUser();
		$this->assertFalse( $owner->isAllowed( 'nexaboard-close' ), 'owner holds no close right' );

		$thread = $this->manager()->createThread(
			$owner->getId(), $owner, 'Owned', 'Body', NotificationMode::Suppress
		);

		$mayReopen = static fn ( $user, $row ) =>
			(int)$row->nbt_closed_by === $user->getId()
			|| $user->isAllowed( 'nexaboard-close' );

		$this->manager()->closeThread( $thread['thread_id'], $moderator );
		$row = $this->threadStore()->getById( $thread['thread_id'] );
		$this->assertFalse( $mayReopen( $owner, $row ), 'owner cannot undo a moderator close' );
		$this->assertTrue( $mayReopen( $moderator, $row ) );

		$this->manager()->reopenThread( $thread['thread_id'], $moderator );
		$this->manager()->closeThread( $thread['thread_id'], $owner );
		$row = $this->threadStore()->getById( $thread['thread_id'] );
		$this->assertTrue( $mayReopen( $owner, $row ), 'owner may undo their own close' );
		$this->assertTrue( $mayReopen( $moderator, $row ) );
	}

	public function testEditRecordsWhoMadeIt(): void {
		$author    = $this->getTestUser()->getUser();
		$moderator = $this->actor();

		$thread = $this->manager()->createThread(
			$author->getId(), $author, 'Subject', 'Original', NotificationMode::Suppress
		);
		$msgId = $thread['msg_id'];

		$this->assertNull(
			$this->messageStore()->getById( $msgId )->nbm_edited_by,
			'never edited'
		);

		$this->manager()->editMessage( $msgId, $author, 'Author revised' );
		$msg = $this->messageStore()->getById( $msgId );
		$this->assertSame( $author->getId(), (int)$msg->nbm_edited_by );
		$this->assertSame(
			(int)$msg->nbm_author_id, (int)$msg->nbm_edited_by,
			'an author editing themselves is not disclosed as a third party'
		);

		$this->manager()->editMessage( $msgId, $moderator, 'Redacted', null, 'personal data' );
		$msg = $this->messageStore()->getById( $msgId );
		$this->assertSame( $moderator->getId(), (int)$msg->nbm_edited_by );
		$this->assertNotSame(
			(int)$msg->nbm_author_id, (int)$msg->nbm_edited_by,
			'a moderator edit is attributable'
		);
	}

}
