<?php

namespace MediaWiki\Extension\NexaBoard\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiMain;
use MediaWiki\Extension\NexaBoard\BoardBlock;
use MediaWiki\Extension\NexaBoard\BoardManager;
use MediaWiki\Extension\NexaBoard\Store\ThreadStore;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Close or reopen threads. Closing is the board's lock: a closed thread accepts
 * no replies until it is reopened.
 */
class ApiBoardClose extends ApiBase {

	public function __construct(
		ApiMain $mainModule,
		string $moduleName,
		private readonly BoardManager $manager,
		private readonly ThreadStore $threadStore
	) {
		parent::__construct( $mainModule, $moduleName );
	}

	public function execute(): void {
		$user = $this->getUser();

		if ( !$user->isRegistered() ) {
			$this->dieWithError( 'apierror-mustbeloggedin-generic', 'notloggedin' );
		}

		$params    = $this->extractRequestParams();
		$threadIds = array_map( 'intval', (array)$params['threadid'] );
		$reopen    = (bool)$params['reopen'];
		$reason    = trim( $params['reason'] ?? '' );

		$done = [];

		// Validate every id before touching any of them. Applying as we go means
		// a bad id halfway through leaves the earlier threads already closed
		// behind an error that says the request failed.
		$threads = [];
		foreach ( $threadIds as $threadId ) {
			$thread = $this->threadStore->getById( $threadId );
			if ( !$thread ) {
				$this->dieWithError( [ 'apierror-invalidparameter', 'threadid' ], 'invalidthread' );
			}

			$block = BoardBlock::affectingThread( $user, $thread );
			if ( $block ) {
				$this->dieBlocked( $block );
			}

			// The board's owner may close and reopen threads on their own board
			// without holding the site-wide right.
			$isBoardOwner = ( $user->getId() === (int)$thread->nbt_board_user_id );
			if ( !$isBoardOwner && !$user->isAllowed( 'nexaboard-close' ) ) {
				$this->dieWithError( 'apierror-permissiondenied-generic', 'permissiondenied' );
			}

			// You may reopen what you closed. Undoing someone else's close — a
			// moderator's, in practice — takes the right, or a board owner could
			// simply reverse a moderation decision aimed at their own board.
			if ( $reopen
				&& (int)$thread->nbt_closed_by !== $user->getId()
				&& !$user->isAllowed( 'nexaboard-close' )
			) {
				$this->dieWithError( 'nexaboard-error-reopen-notyours', 'notyourclose' );
			}

			$threads[] = $threadId;
		}

		foreach ( $threads as $threadId ) {
			$ok = $reopen
				? $this->manager->reopenThread( $threadId, $user, $reason )
				: $this->manager->closeThread( $threadId, $user, $reason );

			if ( $ok ) {
				$done[] = $threadId;
			}
		}

		$this->getResult()->addValue( null, $this->getModuleName(), [
			'result'    => 'success',
			'action'    => $reopen ? 'reopen' : 'close',
			'threadids' => $done,
		] );
	}

	public function getAllowedParams(): array {
		return [
			'threadid' => [
				ParamValidator::PARAM_TYPE       => 'integer',
				ParamValidator::PARAM_REQUIRED   => true,
				ParamValidator::PARAM_ISMULTI    => true,
			],
			'reopen' => [
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
