<?php

namespace MediaWiki\Extension\NexaBoard\Api;

use MediaWiki\Api\ApiQuery;
use MediaWiki\Api\ApiQueryBase;
use MediaWiki\Extension\NexaBoard\Store\MessageStore;
use MediaWiki\Extension\NexaBoard\Store\ThreadStore;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Read the wikitext source of board messages.
 *
 * The rendered HTML is already on the page; this exists so the inline editor can
 * load what the author actually typed instead of round-tripping the parsed
 * output. It therefore returns a message only to somebody who could edit it —
 * source that is not on the page in front of you is not public just because the
 * message id is guessable.
 */
class ApiQueryBoardMessages extends ApiQueryBase {

	public function __construct(
		ApiQuery $query,
		string $moduleName,
		private readonly MessageStore $messageStore,
		private readonly ThreadStore $threadStore
	) {
		parent::__construct( $query, $moduleName, 'mwm' );
	}

	public function execute(): void {
		$params = $this->extractRequestParams();
		$ids    = array_map( 'intval', (array)$params['ids'] );
		$user   = $this->getUser();

		$out = [];

		foreach ( $ids as $msgId ) {
			$msg = $this->messageStore->getById( $msgId );

			if ( !$msg || (int)$msg->nbm_deleted ) {
				continue;
			}

			if ( !$this->canReadSource( $user, $msg ) ) {
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

	/**
	 * Mirrors ApiBoardEdit::assertCanEdit — whoever may rewrite a message may
	 * read its source, and nobody else.
	 *
	 * Deleting a thread does not flag its individual messages, so the thread's
	 * status has to be checked separately or soft-deleted content stays readable
	 * by id. That is the case this guard exists for.
	 */
	private function canReadSource( $user, $msg ): bool {
		if ( !$user->isRegistered() ) {
			return false;
		}

		$thread = $this->threadStore->getById( (int)$msg->nbm_thread_id );
		if ( !$thread ) {
			return false;
		}

		$status = (int)$thread->nbt_status;

		if ( $user->isAllowed( 'nexaboard-edit-others' ) ) {
			// Moderators may edit anywhere, but a merged source has already handed
			// its messages to another thread and holds nothing of its own.
			return $status !== ThreadStore::STATUS_MERGED;
		}

		return $user->getId() === (int)$msg->nbm_author_id
			&& $user->isAllowed( 'nexaboard-edit-own' )
			&& $status === ThreadStore::STATUS_OPEN;
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

	/**
	 * The response depends on who is asking, so it must never be shared by a
	 * front-end cache.
	 */
	public function getCacheMode( $params ): string {
		return 'private';
	}
}
