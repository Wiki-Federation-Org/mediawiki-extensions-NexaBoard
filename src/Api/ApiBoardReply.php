<?php

namespace MediaWiki\Extension\NexaBoard\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiMain;
use MediaWiki\Api\ApiUsageException;
use MediaWiki\Extension\NexaBoard\NotificationMode;
use MediaWiki\Extension\NexaBoard\BoardBlock;
use MediaWiki\Extension\NexaBoard\BoardManager;
use MediaWiki\Extension\NexaBoard\Store\ThreadStore;
use MediaWiki\MediaWikiServices;
use MediaWiki\Extension\NexaBoard\Store\MessageStore;
use Wikimedia\ParamValidator\ParamValidator;

class ApiBoardReply extends ApiBase {

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
		if ( !$user->isAllowed( 'nexaboard-post' ) ) {
			$this->dieWithError( 'apierror-permissiondenied-generic', 'permissiondenied' );
		}

		$params   = $this->extractRequestParams();
		$threadId = (int)$params['threadid'];
		$body     = trim( $params['body'] );
		$quoteId  = $params['quoteid'] !== null ? (int)$params['quoteid'] : null;
		$parentId = $params['parentid'] !== null ? (int)$params['parentid'] : null;

		if ( $body === '' ) {
			$this->dieWithError( 'nexaboard-error-nobody', 'nobody' );
		}

		$config  = MediaWikiServices::getInstance()->getMainConfig();
		$maxBody = $config->get( 'NexaBoardMaxBodyLength' );
		if ( mb_strlen( $body ) > $maxBody ) {
			$this->dieWithError( [ 'nexaboard-error-toolong', $maxBody ], 'bodytoolong' );
		}
		if ( strlen( $body ) > MessageStore::MAX_BODY_BYTES ) {
			$this->dieWithError( 'nexaboard-error-toolong-storage', 'bodytoolongbytes' );
		}

		$thread = $this->threadStore->getById( $threadId );
		if ( !$thread ) {
			$this->dieWithError( [ 'apierror-invalidparameter', 'threadid' ], 'invalidthread' );
		}

		$block = BoardBlock::affectingThread( $user, $thread );
		if ( $block ) {
			$this->dieBlocked( $block );
		}

		try {
			$msgId = $this->manager->reply(
				$threadId, $user, $body, $quoteId, NotificationMode::Send, $parentId
			);
		} catch ( \RuntimeException $e ) {
			if ( str_contains( $e->getMessage(), 'too deep' ) ) {
				$this->dieWithError( 'nexaboard-error-too-deep', 'toodeep' );
			}
			$this->dieWithError( [ 'apierror-invalidparameter', 'threadid' ], 'invalidthread' );
		} catch ( \Exception $e ) {
			$this->dieWithError( 'nexaboard-error-generic', 'dbfail' );
		}

		$this->getResult()->addValue( null, $this->getModuleName(), [
			'result'   => 'success',
			'threadid' => $threadId,
			'msgid'    => $msgId,
			'parentid' => $parentId,
		] );
	}

	public function getAllowedParams(): array {
		return [
			'threadid' => [
				ParamValidator::PARAM_TYPE     => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'body' => [
				ParamValidator::PARAM_TYPE     => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'quoteid' => [
				ParamValidator::PARAM_TYPE => 'integer',
			],
			'parentid' => [
				ParamValidator::PARAM_TYPE => 'integer',
			],
		];
	}

	public function mustBePosted(): bool { return true; }
	public function needsToken(): string { return 'csrf'; }
	public function isWriteMode(): bool  { return true; }
}
