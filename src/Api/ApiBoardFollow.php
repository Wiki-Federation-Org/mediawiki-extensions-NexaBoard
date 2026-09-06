<?php

namespace MediaWiki\Extension\NexaBoard\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiMain;
use MediaWiki\Extension\NexaBoard\BoardManager;
use MediaWiki\Extension\NexaBoard\Store\ThreadStore;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Follow or unfollow a thread, controlling whether its replies produce Echo
 * notifications for the current user.
 */
class ApiBoardFollow extends ApiBase {

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

		$params   = $this->extractRequestParams();
		$threadId = (int)$params['threadid'];
		$follow   = (bool)$params['follow'];

		$thread = $this->threadStore->getById( $threadId );
		if ( !$thread ) {
			$this->dieWithError( [ 'apierror-invalidparameter', 'threadid' ], 'invalidthread' );
		}

		$this->manager->setFollowing( $threadId, $user, $follow );

		$this->getResult()->addValue( null, $this->getModuleName(), [
			'result'    => 'success',
			'threadid'  => $threadId,
			'following' => $follow,
		] );
	}

	public function getAllowedParams(): array {
		return [
			'threadid' => [
				ParamValidator::PARAM_TYPE     => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'follow' => [
				ParamValidator::PARAM_TYPE    => 'boolean',
				ParamValidator::PARAM_DEFAULT => true,
			],
		];
	}

	public function mustBePosted(): bool { return true; }
	public function needsToken(): string { return 'csrf'; }
	public function isWriteMode(): bool  { return true; }
}
