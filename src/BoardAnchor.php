<?php

namespace MediaWiki\Extension\NexaBoard;

use MediaWiki\SpecialPage\SpecialPage;

/**
 * Anchor and permalink conventions for threads and messages.
 *
 * Threads and messages have no page of their own, so a permalink is the board
 * page plus a fragment. Both the rendered board and the Echo notifications build
 * their links here so the two can never drift apart.
 */
class BoardAnchor {

	public const THREAD_PREFIX  = 'nexaboard-thread-';
	public const MESSAGE_PREFIX = 'nexaboard-message-';

	/**
	 * The board page itself, with no fragment.
	 */
	public static function boardUrl( string $boardOwnerName ): string {
		return SpecialPage::getTitleFor( 'NexaBoard', $boardOwnerName )->getFullURL();
	}

	public static function threadFragment( int $threadId ): string {
		return self::THREAD_PREFIX . $threadId;
	}

	public static function messageFragment( int $msgId ): string {
		return self::MESSAGE_PREFIX . $msgId;
	}

	/**
	 * Permalink to a thread on a given user's board.
	 */
	public static function threadUrl( string $boardOwnerName, int $threadId ): string {
		return self::boardUrl( $boardOwnerName ) . '#' . self::threadFragment( $threadId );
	}

	/**
	 * Permalink to an individual message (the OP or a reply) on a board.
	 */
	public static function messageUrl( string $boardOwnerName, int $msgId ): string {
		return self::boardUrl( $boardOwnerName ) . '#' . self::messageFragment( $msgId );
	}
}
