<?php

namespace MediaWiki\Extension\NexaBoard;

use MediaWiki\Block\Block;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\User\User;

/**
 * Whether a block stops a user acting on a particular board.
 *
 * Rights and blocks are separate systems: User::isAllowed() is a group-to-right
 * lookup and knows nothing about blocks, and ApiMain::checkExecutePermissions()
 * does not check them either — core modules enforce blocks themselves. Asking
 * only isAllowed() therefore let a sitewide-blocked user post, reply and edit on
 * every board, which matters here because a board replaces the user talk page
 * that blocking is meant to close off.
 *
 * A board stands in for its owner's User talk: page, so blocks are evaluated
 * against that title. That makes existing partial blocks mean what the admin who
 * placed them expected, and keeps the own-talk-page exemption behind
 * $wgBlockAllowsUTEdit working for appeals.
 */
final class BoardBlock {

	/**
	 * The block preventing $user from acting on $boardUserId's board, or null.
	 *
	 * @param User $user The user trying to act
	 * @param int $boardUserId user_id of the board's owner
	 */
	public static function affecting( User $user, int $boardUserId ): ?Block {
		$block = $user->getBlock();
		if ( !$block ) {
			return null;
		}

		$owner = MediaWikiServices::getInstance()->getUserFactory()->newFromId( $boardUserId );
		$talk  = Title::makeTitleSafe( NS_USER_TALK, $owner->getName() );

		if ( !$talk ) {
			// No title to reason about, so fall back to the blunt question.
			return $block->isSitewide() ? $block : null;
		}

		// Your own board is your own talk page. Core lets a blocked user keep
		// editing that when $wgBlockAllowsUTEdit is on, so an appeal is still
		// possible; appliesToUsertalk() is what encodes that rule.
		if ( $user->getId() === $boardUserId && $user->getId() !== 0 ) {
			return $block->appliesToUsertalk( $talk ) ? $block : null;
		}

		return $block->appliesToTitle( $talk ) ? $block : null;
	}

	/**
	 * Convenience for the common case of holding a thread row rather than an id.
	 */
	public static function affectingThread( User $user, object $thread ): ?Block {
		return self::affecting( $user, (int)$thread->nbt_board_user_id );
	}
}
