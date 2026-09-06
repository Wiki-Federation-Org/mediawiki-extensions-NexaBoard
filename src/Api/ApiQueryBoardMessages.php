<?php

namespace MediaWiki\Extension\NexaBoard\Api;

use MediaWiki\Api\ApiQuery;
use MediaWiki\Api\ApiQueryBase;
use MediaWiki\Extension\NexaBoard\Store\MessageStore;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Read the wikitext source of board messages.
 *
 * The rendered HTML is already on the page; this exists so the inline editor can
 * load what the author actually typed instead of round-tripping the parsed
 * output. Deleted messages are never returned.
 */
class ApiQueryBoardMessages extends ApiQueryBase {

	public function __construct(
		ApiQuery $query,
		string $moduleName,
		private readonly MessageStore $messageStore
	) {
		parent::__construct( $query, $moduleName, 'mwm' );
	}

	public function execute(): void {
		$params = $this->extractRequestParams();
		$ids    = array_map( 'intval', (array)$params['ids'] );

		$out = [];

		foreach ( $ids as $msgId ) {
			$msg = $this->messageStore->getById( $msgId );

			if ( !$msg || (int)$msg->nbm_deleted ) {
				continue;
			}

			$out[] = [
				'id'     => (int)$msg->nbm_id,
				'thread' => (int)$msg->nbm_thread_id,
				'isop'   => (bool)(int)$msg->nbm_is_op,
				'body'   => $msg->nbm_body,
			];
		}

		$this->getResult()->addValue( 'query', $this->getModuleName(), $out );
	}

	public function getAllowedParams(): array {
		return [
			'ids' => [
				ParamValidator::PARAM_TYPE      => 'integer',
				ParamValidator::PARAM_ISMULTI   => true,
				ParamValidator::PARAM_REQUIRED  => true,
			],
		];
	}

	public function getCacheMode( $params ): string {
		return 'public';
	}
}
