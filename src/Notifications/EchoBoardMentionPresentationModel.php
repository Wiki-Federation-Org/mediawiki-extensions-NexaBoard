<?php

namespace MediaWiki\Extension\NexaBoard\Notifications;

use MediaWiki\Extension\Notifications\Formatters\EchoEventPresentationModel;
use MediaWiki\Language\RawMessage;
use MediaWiki\Extension\NexaBoard\BoardAnchor;

class EchoBoardMentionPresentationModel extends EchoEventPresentationModel {

	public function getIconType() {
		return 'mention';
	}

	public function getHeaderMessage() {

		$msg = $this->getMessageWithAgent( 'notification-header-nexaboard-mention' );
		$msg->plaintextParams( $this->event->getExtraParam( 'board-owner-name' ) ?: '' );
		return $msg;
	}

	public function getBodyMessage() {
		$content = $this->event->getExtraParam( 'content' );
		if ( $content !== null && $content !== '' ) {
			$msg = new RawMessage( '$1' );
			$msg->plaintextParams( $content );
			return $msg;
		}
		return false;
	}

	public function getPrimaryLink() {
		$boardOwnerName = $this->event->getExtraParam( 'board-owner-name' );
		if ( !$boardOwnerName ) {
			return false;
		}

		$threadId = (int)$this->event->getExtraParam( 'post-id' );
		$url = $threadId
			? BoardAnchor::threadUrl( $boardOwnerName, $threadId )
			: BoardAnchor::boardUrl( $boardOwnerName );

		return [
			'url'   => $url,
			'label' => $this->msg( 'notification-link-nexaboard-view-thread' )->text(),
		];
	}

	public function getSecondaryLinks() {
		return array_values( array_filter( [ $this->getAgentLink() ] ) );
	}
}
