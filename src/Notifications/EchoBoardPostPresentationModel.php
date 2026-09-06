<?php

namespace MediaWiki\Extension\NexaBoard\Notifications;

use MediaWiki\Extension\Notifications\Formatters\EchoEventPresentationModel;
use MediaWiki\Extension\NexaBoard\BoardAnchor;

class EchoBoardPostPresentationModel extends EchoEventPresentationModel {

	public function getIconType() {
		return 'nexaboard';
	}

	public function getHeaderMessage() {

		return $this->getMessageWithAgent( 'notification-header-nexaboard-post' );
	}

	public function getBodyMessage() {
		$title = $this->event->getExtraParam( 'post-title' );
		if ( $title !== null && $title !== '' ) {
			$msg = $this->msg( 'notification-body-nexaboard-post' );
			$msg->plaintextParams( $title );
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
