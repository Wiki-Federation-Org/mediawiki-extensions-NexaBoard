<?php

namespace MediaWiki\Extension\NexaBoard\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiMain;
use MediaWiki\Api\ApiUsageException;
use MediaWiki\Extension\NexaBoard\BoardBlock;
use MediaWiki\Extension\NexaBoard\BoardManager;
use MediaWiki\MediaWikiServices;
use MediaWiki\Extension\NexaBoard\Store\ThreadStore;
use MediaWiki\Extension\NexaBoard\Store\MessageStore;
use Wikimedia\ParamValidator\ParamValidator;

class ApiBoardPost extends ApiBase {

	public function __construct(
		ApiMain $mainModule,
		string $moduleName,
		private readonly BoardManager $manager
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

		$params = $this->extractRequestParams();
		$title  = trim( $params['title'] );
		$body   = trim( $params['body'] );

		if ( $title === '' ) {
			$this->dieWithError( 'nexaboard-error-notitle', 'notitle' );
		}

		$config    = MediaWikiServices::getInstance()->getMainConfig();
		$maxTitle  = $config->get( 'NexaBoardMaxTitleLength' );

		if ( mb_strlen( $title ) > $maxTitle ) {
			$this->dieWithError( [ 'nexaboard-error-toolong', $maxTitle ], 'titletoolong' );
		}
		if ( strlen( $title ) > ThreadStore::MAX_TITLE_BYTES ) {
			$this->dieWithError( 'nexaboard-error-toolong-storage', 'titletoolongbytes' );
		}

		if ( $body === '' ) {
			$this->dieWithError( 'nexaboard-error-nobody', 'nobody' );
		}

		$maxBody = $config->get( 'NexaBoardMaxBodyLength' );
		if ( mb_strlen( $body ) > $maxBody ) {
			$this->dieWithError( [ 'nexaboard-error-toolong', $maxBody ], 'bodytoolong' );
		}
		if ( strlen( $body ) > MessageStore::MAX_BODY_BYTES ) {
			$this->dieWithError( 'nexaboard-error-toolong-storage', 'bodytoolongbytes' );
		}

		$boardUser = MediaWikiServices::getInstance()
			->getUserFactory()
			->newFromName( $params['boarduser'] );

		if ( !$boardUser || $boardUser->getId() === 0 ) {
			$this->dieWithError( [ 'nexaboard-user-not-found', $params['boarduser'] ], 'usernotfound' );
		}

		$block = BoardBlock::affecting( $user, $boardUser->getId() );
		if ( $block ) {
			$this->dieBlocked( $block );
		}

		try {
			$result = $this->manager->createThread(
				$boardUser->getId(), $user, $title, $body
			);
		} catch ( \Exception $e ) {
			$this->dieWithError( 'nexaboard-error-generic', 'dbfail' );
		}

		$this->getResult()->addValue( null, $this->getModuleName(), [
			'result'    => 'success',
			'threadid'  => $result['thread_id'],
		] );
	}

	public function getAllowedParams(): array {
		return [
			'boarduser' => [
				ParamValidator::PARAM_TYPE     => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'title' => [
				ParamValidator::PARAM_TYPE     => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'body' => [
				ParamValidator::PARAM_TYPE     => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
		];
	}

	public function mustBePosted(): bool { return true; }
	public function needsToken(): string { return 'csrf'; }
	public function isWriteMode(): bool  { return true; }
}
