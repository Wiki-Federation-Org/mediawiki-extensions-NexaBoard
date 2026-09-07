<?php

namespace MediaWiki\Extension\NexaBoard\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiMain;
use MediaWiki\Api\ApiUsageException;
use MediaWiki\Extension\NexaBoard\BoardBlock;
use MediaWiki\Extension\NexaBoard\BoardManager;
use MediaWiki\Extension\NexaBoard\Store\ThreadStore;
use Wikimedia\ParamValidator\ParamValidator;

class ApiBoardMerge extends ApiBase {

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
		if ( !$user->isAllowed( 'nexaboard-merge' ) ) {
			$this->dieWithError( 'apierror-permissiondenied-generic', 'permissiondenied' );
		}

		$params   = $this->extractRequestParams();
		$targetId = (int)$params['targetthread'];
		$sources  = array_map( 'intval', $params['sourcethread'] );
		$reason   = trim( $params['reason'] ?? '' );

		$mergeable = [ ThreadStore::STATUS_OPEN, ThreadStore::STATUS_CLOSED ];

		$target = $this->threadStore->getById( $targetId );
		if ( !$target ) {
			$this->dieWithError( [ 'apierror-invalidparameter', 'targetthread' ], 'invalidthread' );
		}
		if ( !in_array( (int)$target->nbt_status, $mergeable, true ) ) {
			$this->dieWithError( 'nexaboard-error-merge-target-state', 'targetnotmergeable' );
		}

		$block = BoardBlock::affectingThread( $user, $target );
		if ( $block ) {
			$this->dieBlocked( $block );
		}

		// Everything is validated before anything is merged: the manager applies
		// the sources one at a time, so failing halfway would leave some merged
		// and the rest not, behind an error saying the whole thing failed.
		foreach ( $sources as $srcId ) {
			if ( $srcId === $targetId ) {
				$this->dieWithError( 'nexaboard-error-merge-self', 'mergeself' );
			}
			$source = $this->threadStore->getById( $srcId );
			if ( !$source ) {
				$this->dieWithError( [ 'apierror-invalidparameter', 'sourcethread' ], 'invalidsource' );
			}
			if ( !in_array( (int)$source->nbt_status, $mergeable, true ) ) {
				$this->dieWithError( 'nexaboard-error-merge-source-state', 'sourcenotmergeable' );
			}
		}

		try {
			$this->manager->mergeThreads( $sources, $targetId, $user, $reason );
		} catch ( \RuntimeException $e ) {
			$this->dieWithError( 'nexaboard-error-crossboard', 'crossboard' );
		} catch ( \Exception $e ) {
			$this->dieWithError( 'nexaboard-error-generic', 'dbfail' );
		}

		$this->getResult()->addValue( null, $this->getModuleName(), [
			'result'       => 'success',
			'targetthread' => $targetId,
		] );
	}

	public function getAllowedParams(): array {
		return [
			'targetthread' => [
				ParamValidator::PARAM_TYPE     => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'sourcethread' => [
				ParamValidator::PARAM_TYPE      => 'integer',
				ParamValidator::PARAM_REQUIRED  => true,
				ParamValidator::PARAM_ISMULTI   => true,
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
