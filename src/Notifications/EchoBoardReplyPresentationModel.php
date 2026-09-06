<?php

namespace MediaWiki\Extension\NexaBoard\Notifications;

use MediaWiki\Extension\Notifications\Formatters\EchoEventPresentationModel;
use MediaWiki\Extension\NexaBoard\BoardAnchor;

class EchoBoardReplyPresentationModel extends EchoEventPresentationModel {

	public function getIconType() {
		return 'nexaboard';
	}

	public function getHeaderMessage() {

		$msg = $this->getMessageWithAgent( 'notification-header-nexaboard-reply' );
		$msg->plaintextParams( $this->event->getExtraParam( 'post-title' ) ?: '' );
		return $msg;
	}

	public function getBodyMessage() {
		return false;
	}

	public function getPrimaryLink() {
		$boardOwnerName = $this->event->getExtraParam( 'board-owner-name' );
		if ( !$boardOwnerName ) {
			return false;
		}

		// Deep-link to the reply itself, falling back to the thread it lives in.
		$replyId  = (int)$this->event->getExtraParam( 'reply-id' );
		$threadId = (int)$this->event->getExtraParam( 'post-id' );

		if ( $replyId ) {
			$url = BoardAnchor::messageUrl( $boardOwnerName, $replyId );
		} elseif ( $threadId ) {
			$url = BoardAnchor::threadUrl( $boardOwnerName, $threadId );
		} else {
			$url = BoardAnchor::boardUrl( $boardOwnerName );
		}

		return [
			'url'   => $url,
			'label' => $this->msg( 'notification-link-nexaboard-view-thread' )->text(),
		];
	}

	public function getSecondaryLinks() {
		return array_values( array_filter( [ $this->getAgentLink() ] ) );
	}
}
