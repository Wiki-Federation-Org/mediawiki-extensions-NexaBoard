<?php

namespace MediaWiki\Extension\NexaBoard\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiMain;
use MediaWiki\Extension\NexaBoard\BoardManager;
use MediaWiki\Extension\NexaBoard\Store\MessageStore;
use MediaWiki\Extension\NexaBoard\Store\ThreadStore;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Delete and restore threads and individual messages.
 *
 * Everything here is a soft delete: rows are flagged, never removed, so any of
 * it can be undone with undo=1.
 */
class ApiBoardDelete extends ApiBase {

	public function __construct(
		ApiMain $mainModule,
		string $moduleName,
		private readonly BoardManager $manager,
		private readonly ThreadStore $threadStore,
		private readonly MessageStore $messageStore
	) {
		parent::__construct( $mainModule, $moduleName );
	}

	public function execute(): void {
		$user = $this->getUser();

		if ( !$user->isRegistered() ) {
			$this->dieWithError( 'apierror-mustbeloggedin-generic', 'notloggedin' );
		}

		$params    = $this->extractRequestParams();
		$threadIds = array_map( 'intval', (array)( $params['threadid'] ?? [] ) );
		$msgIds    = array_map( 'intval', (array)( $params['msgid'] ?? [] ) );
		$scope     = $params['scope'];
		$undo      = (bool)$params['undo'];
		$reason    = trim( $params['reason'] ?? '' );

		if ( !$threadIds && !$msgIds ) {
			$this->dieWithError( [ 'apierror-missingparam', 'threadid' ], 'notarget' );
		}

		$result = [ 'result' => 'success', 'threads' => [], 'messages' => [], 'replies' => 0 ];

		// Everything is checked before anything is deleted. Deleting as we go
		// means one bad id partway through leaves the earlier threads gone behind
		// an error report saying the request failed.
		foreach ( $threadIds as $threadId ) {
			$thread = $this->threadStore->getById( $threadId );
			if ( !$thread ) {
				$this->dieWithError( [ 'apierror-invalidparameter', 'threadid' ], 'invalidthread' );
			}
			$this->assertCanModerateThread( $user, $thread );
		}
		foreach ( $msgIds as $msgId ) {
			$msg = $this->messageStore->getById( $msgId );
			if ( !$msg ) {
				$this->dieWithError( [ 'apierror-invalidparameter', 'msgid' ], 'invalidmsg' );
			}
			$this->assertCanModerateMessage( $user, $msg );
		}

		foreach ( $threadIds as $threadId ) {
			if ( $scope === 'replies' ) {
				$result['replies'] += $this->manager->deleteAllReplies( $threadId, $user, $reason );
				continue;
			}

			$ok = $undo
				? $this->manager->undeleteThread( $threadId, $user, $reason )
				: $this->manager->deleteThread( $threadId, $user, $reason );

			if ( $ok ) {
				$result['threads'][] = $threadId;
			}
		}

		foreach ( $msgIds as $msgId ) {
			try {
				$ok = $undo
					? $this->manager->restoreMessage( $msgId, $user, $reason )
					: $this->manager->deleteMessage( $msgId, $user, $reason );
			} catch ( \RuntimeException $e ) {
				$this->dieWithError( 'nexaboard-error-delete-op', 'deleteop' );
			}

			if ( $ok ) {
				$result['messages'][] = $msgId;
			}
		}

		$this->getResult()->addValue( null, $this->getModuleName(), $result );
	}

	/**
	 * Thread-level deletion needs the delete right. Owning the board is not
	 * enough: deletion hides a thread from everyone without the right, and the
	 * threads most worth hiding — warnings, complaints, evidence — are precisely
	 * the ones that land on the board of the person they concern. Owners close
	 * threads they are done with; removing them from view is moderation.
	 */
	private function assertCanModerateThread( $user, $thread ): void {
		if ( $user->isAllowed( 'nexaboard-delete' ) ) {
			return;
		}
		$this->dieWithError( 'apierror-permissiondenied-generic', 'permissiondenied' );
	}

	/**
	 * A message may be removed by a moderator, or by its own author. Board
	 * ownership grants nothing here either — the board a message sits on is not
	 * a licence to remove what somebody else wrote on it. This also matches what
	 * the page renders: canDeleteMessage() never offered owners the button, so
	 * the check here was quietly wider than the interface.
	 */
	private function assertCanModerateMessage( $user, $msg ): void {
		if ( $user->isAllowed( 'nexaboard-delete' ) ) {
			return;
		}

		if (
			$user->getId() === (int)$msg->nbm_author_id
			&& $user->isAllowed( 'nexaboard-edit-own' )
		) {
			return;
		}

		$this->dieWithError( 'apierror-permissiondenied-generic', 'permissiondenied' );
	}

	public function getAllowedParams(): array {
		return [
			'threadid' => [
				ParamValidator::PARAM_TYPE    => 'integer',
				ParamValidator::PARAM_ISMULTI => true,
			],
			'msgid' => [
				ParamValidator::PARAM_TYPE    => 'integer',
				ParamValidator::PARAM_ISMULTI => true,
			],
			'scope' => [
				ParamValidator::PARAM_TYPE    => [ 'thread', 'replies' ],
				ParamValidator::PARAM_DEFAULT => 'thread',
			],
			'undo' => [
				ParamValidator::PARAM_TYPE    => 'boolean',
				ParamValidator::PARAM_DEFAULT => false,
			],
			'reason' => [
				ParamValidator::PARAM_TYPE    => 'string',
				ParamValidator::PARAM_DEFAULT => '',
			],
		];
	}

	public function mustBePosted(): bool { return true; }
	public function needsToken(): string { return 'csrf'; }
	public function isWriteMode(): bool  { return true; }
}
