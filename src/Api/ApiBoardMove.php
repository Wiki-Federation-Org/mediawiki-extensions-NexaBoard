<?php

namespace MediaWiki\Extension\NexaBoard\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiMain;
use MediaWiki\Api\ApiUsageException;
use MediaWiki\Extension\NexaBoard\BoardBlock;
use MediaWiki\Extension\NexaBoard\BoardManager;
use MediaWiki\Extension\NexaBoard\Store\MessageStore;
use MediaWiki\Extension\NexaBoard\Store\ThreadStore;
use Wikimedia\ParamValidator\ParamValidator;

class ApiBoardMove extends ApiBase {

	public function __construct(
		ApiMain $mainModule,
		string $moduleName,
		private readonly BoardManager $manager,
		private readonly MessageStore $messageStore,
		private readonly ThreadStore $threadStore
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

		$params         = $this->extractRequestParams();
		$msgId          = (int)$params['msgid'];
		$targetThreadId = (int)$params['targetthread'];
		$reason         = trim( $params['reason'] ?? '' );

		$msg = $this->messageStore->getById( $msgId );
		if ( !$msg ) {
			$this->dieWithError( [ 'apierror-invalidparameter', 'msgid' ], 'invalidmsg' );
		}

		if ( (int)$msg->nbm_is_op ) {
			$this->dieWithError( 'nexaboard-error-move-op', 'cannotmoveop' );
		}

		$sourceThread = $this->threadStore->getById( (int)$msg->nbm_thread_id );
		if ( $sourceThread ) {
			$block = BoardBlock::affectingThread( $user, $sourceThread );
			if ( $block ) {
				$this->dieBlocked( $block );
			}
		}

		if ( !$this->threadStore->getById( $targetThreadId ) ) {
			$this->dieWithError( [ 'apierror-invalidparameter', 'targetthread' ], 'invalidthread' );
		}

		try {
			$this->manager->moveMessage( $msgId, $targetThreadId, $user, $reason );
		} catch ( \RuntimeException $e ) {
			$this->dieWithError( 'nexaboard-error-crossboard', 'crossboard' );
		}

		$this->getResult()->addValue( null, $this->getModuleName(), [
			'result'       => 'success',
			'msgid'        => $msgId,
			'targetthread' => $targetThreadId,
		] );
	}

	public function getAllowedParams(): array {
		return [
			'msgid' => [
				ParamValidator::PARAM_TYPE     => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'targetthread' => [
				ParamValidator::PARAM_TYPE     => 'integer',
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
