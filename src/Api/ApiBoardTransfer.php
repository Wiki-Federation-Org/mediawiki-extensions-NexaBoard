<?php

namespace MediaWiki\Extension\NexaBoard\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiMain;
use MediaWiki\Extension\NexaBoard\BoardManager;
use MediaWiki\Extension\NexaBoard\Store\ThreadStore;
use MediaWiki\User\UserFactory;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Move a whole thread onto another user's board.
 *
 * Merge and move both refuse to cross boards; this is the one sanctioned way
 * across, for a thread that was posted on the wrong person's board.
 */
class ApiBoardTransfer extends ApiBase {

	public function __construct(
		ApiMain $mainModule,
		string $moduleName,
		private readonly BoardManager $manager,
		private readonly ThreadStore $threadStore,
		private readonly UserFactory $userFactory
	) {
		parent::__construct( $mainModule, $moduleName );
	}

	public function execute(): void {
		$user = $this->getUser();

		if ( !$user->isRegistered() ) {
			$this->dieWithError( 'apierror-mustbeloggedin-generic', 'notloggedin' );
		}
		if ( !$user->isAllowed( 'nexaboard-move' ) ) {
			$this->dieWithError( 'apierror-permissiondenied-generic', 'permissiondenied' );
		}

		$params     = $this->extractRequestParams();
		$threadId   = (int)$params['threadid'];
		$targetName = trim( $params['targetuser'] );
		$reason     = trim( $params['reason'] ?? '' );

		$thread = $this->threadStore->getById( $threadId );
		if ( !$thread ) {
			$this->dieWithError( [ 'apierror-invalidparameter', 'threadid' ], 'invalidthread' );
		}

		$target = $this->userFactory->newFromName( $targetName );
		if ( !$target || !$target->isRegistered() ) {
			$this->dieWithError( 'nexaboard-error-transfer-nouser', 'nosuchuser' );
		}

		if ( $target->getId() === (int)$thread->nbt_board_user_id ) {
			$this->dieWithError( 'nexaboard-error-transfer-same', 'sameboard' );
		}

		try {
			$ok = $this->manager->transferThread( $threadId, $target->getId(), $user, $reason );
		} catch ( \RuntimeException $e ) {
			$this->dieWithError( 'nexaboard-error-transfer-failed', 'transferfailed' );
		}

		if ( !$ok ) {
			$this->dieWithError( 'nexaboard-error-transfer-failed', 'transferfailed' );
		}

		$this->getResult()->addValue( null, $this->getModuleName(), [
			'result'     => 'success',
			'threadid'   => $threadId,
			'targetuser' => $target->getName(),
		] );
	}

	public function getAllowedParams(): array {
		return [
			'threadid' => [
				ParamValidator::PARAM_TYPE     => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'targetuser' => [
				ParamValidator::PARAM_TYPE     => 'string',
				ParamValidator::PARAM_REQUIRED => true,
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
