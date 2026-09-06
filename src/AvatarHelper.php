<?php

namespace MediaWiki\Extension\NexaBoard;

use ExtensionRegistry;
use MediaWiki\User\User;

class AvatarHelper {

	private static array $cache = [];

	private static array $palette = [
		'#e74c3c', '#e67e22', '#f1c40f', '#2ecc71',
		'#1abc9c', '#3498db', '#9b59b6', '#e91e63',
		'#00bcd4', '#8bc34a', '#ff5722', '#607d8b',
		'#795548', '#673ab7', '#009688', '#f44336',
	];

	public static function getAvatarData( User $user ): array {
		$userId = $user->getId();

		if ( isset( self::$cache[$userId] ) ) {
			return self::$cache[$userId];
		}

		$name    = $user->getName();
		$initial = mb_strtoupper( mb_substr( $name, 0, 1 ) );
		$color   = self::generateColor( $name );
		$url     = null;

		if ( ExtensionRegistry::getInstance()->isLoaded( 'UserProfileV2' ) ) {
			try {
				$avatar = new \Telepedia\UserProfileV2\Avatar\UserProfileV2Avatar( $userId );
				$raw    = $avatar->getAvatarUrl( [ 'raw' => true ] );

				if ( $raw && strpos( $raw, 'default.png' ) === false ) {
					$url = $raw;
				}
			} catch ( \Exception $e ) {

			}
		}

		$data = [
			'url'       => $url,
			'initial'   => $initial,
			'color'     => $color,
			'hasAvatar' => ( $url !== null ),
		];

		self::$cache[$userId] = $data;

		return $data;
	}

	public static function generateColor( string $username ): string {
		$index = abs( crc32( $username ) ) % count( self::$palette );
		return self::$palette[$index];
	}
}
