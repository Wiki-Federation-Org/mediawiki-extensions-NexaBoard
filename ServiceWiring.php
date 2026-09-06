<?php

use MediaWiki\Extension\NexaBoard\BoardManager;
use MediaWiki\Extension\NexaBoard\Notifications\BoardNotificationManager;
use MediaWiki\Extension\NexaBoard\Store\FollowStore;
use MediaWiki\Extension\NexaBoard\Store\MessageStore;
use MediaWiki\Extension\NexaBoard\Store\ThreadStore;
use MediaWiki\MediaWikiServices;

return [

	'NexaBoard.ThreadStore' => static function ( MediaWikiServices $services ): ThreadStore {
		return new ThreadStore(
			$services->getConnectionProvider()
		);
	},

	'NexaBoard.MessageStore' => static function ( MediaWikiServices $services ): MessageStore {
		return new MessageStore(
			$services->getConnectionProvider()
		);
	},

	'NexaBoard.FollowStore' => static function ( MediaWikiServices $services ): FollowStore {
		return new FollowStore(
			$services->getConnectionProvider()
		);
	},

	'NexaBoard.NotificationManager' => static function ( MediaWikiServices $services ): BoardNotificationManager {
		return new BoardNotificationManager(
			$services->getUserFactory(),
			$services->get( 'NexaBoard.MessageStore' ),
			$services->get( 'NexaBoard.FollowStore' )
		);
	},

	'NexaBoard.Manager' => static function ( MediaWikiServices $services ): BoardManager {
		return new BoardManager(
			$services->get( 'NexaBoard.ThreadStore' ),
			$services->get( 'NexaBoard.MessageStore' ),
			$services->get( 'NexaBoard.FollowStore' ),
			$services->get( 'NexaBoard.NotificationManager' ),
			$services->getConnectionProvider(),
			$services->getUserFactory()
		);
	},

];
