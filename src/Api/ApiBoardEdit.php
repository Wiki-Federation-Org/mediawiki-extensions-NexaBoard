<?php

namespace MediaWiki\Extension\NexaBoard\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiMain;
use MediaWiki\Extension\NexaBoard\BoardBlock;
use MediaWiki\Extension\NexaBoard\BoardManager;
use MediaWiki\Extension\NexaBoard\Store\MessageStore;
use MediaWiki\Extension\NexaBoard\Store\ThreadStore;
use MediaWiki\MediaWikiServices;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Edit the body of a thread's originating post or of a reply. Editing the
 * originating post may also retitle the thread.
 */
class ApiBoardEdit extends ApiBase {

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

		$params = $this->extractRequestParams();
		$msgId  = (int)$params['msgid'];
		$body   = trim( $params['body'] );
		$title  = $params['title'] !== null ? trim( $params['title'] ) : null;
		$reason = trim( $params['reason'] ?? '' );

		$msg = $this->messageStore->getById( $msgId );
		if ( !$msg ) {
			$this->dieWithError( [ 'apierror-invalidparameter', 'msgid' ], 'invalidmsg' );
		}

		$editThread = $this->threadStore->getById( (int)$msg->nbm_thread_id );
		if ( $editThread ) {
			$block = BoardBlock::affectingThread( $user, $editThread );
			if ( $block ) {
				$this->dieBlocked( $block );
			}
		}
		if ( (int)$msg->nbm_deleted ) {
			$this->dieWithError( 'nexaboard-error-edit-deleted', 'deletedmsg' );
		}

		$this->assertCanEdit( $user, $msg );

		$config = MediaWikiServices::getInstance()->getMainConfig();

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

		// A title is only meaningful on the originating post; ignore it elsewhere.
		if ( $title !== null && (int)$msg->nbm_is_op ) {
			if ( $title === '' ) {
				$this->dieWithError( 'nexaboard-error-notitle', 'notitle' );
			}
			$maxTitle = $config->get( 'NexaBoardMaxTitleLength' );
			if ( mb_strlen( $title ) > $maxTitle ) {
				$this->dieWithError( [ 'nexaboard-error-toolong', $maxTitle ], 'titletoolong' );
			}
			if ( strlen( $title ) > ThreadStore::MAX_TITLE_BYTES ) {
				$this->dieWithError( 'nexaboard-error-toolong-storage', 'titletoolongbytes' );
			}
		} else {
			$title = null;
		}

		try {
			$ok = $this->manager->editMessage( $msgId, $user, $body, $title, $reason );
		} catch ( \Exception $e ) {
			$this->dieWithError( 'nexaboard-error-generic', 'dbfail' );
		}

		if ( !$ok ) {
			$this->dieWithError( 'nexaboard-error-generic', 'editfailed' );
		}

		$this->getResult()->addValue( null, $this->getModuleName(), [
			'result'   => 'success',
			'msgid'    => $msgId,
			'threadid' => (int)$msg->nbm_thread_id,
		] );
	}

	/**
	 * Authors may edit their own messages; moderators may edit anyone's. The
	 * board's owner is deliberately not included — owning the page you were
	 * written on is not a licence to rewrite what others said on it.
	 */
	private function assertCanEdit( $user, $msg ): void {
		if ( $user->isAllowed( 'nexaboard-edit-others' ) ) {
			return;
		}

		if (
			$user->getId() === (int)$msg->nbm_author_id
			&& $user->isAllowed( 'nexaboard-edit-own' )
		) {
			$thread = $this->threadStore->getById( (int)$msg->nbm_thread_id );

			// A closed thread is frozen for its participants.
			if ( $thread && (int)$thread->nbt_status !== ThreadStore::STATUS_OPEN ) {
				$this->dieWithError( 'nexaboard-error-edit-closed', 'threadclosed' );
			}
			return;
		}

		$this->dieWithError( 'apierror-permissiondenied-generic', 'permissiondenied' );
	}

	public function getAllowedParams(): array {
		return [
			'msgid' => [
				ParamValidator::PARAM_TYPE     => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'body' => [
				ParamValidator::PARAM_TYPE     => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'title' => [
				ParamValidator::PARAM_TYPE => 'string',
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
