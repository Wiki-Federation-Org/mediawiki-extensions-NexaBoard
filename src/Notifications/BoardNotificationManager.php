<?php

namespace MediaWiki\Extension\NexaBoard\Notifications;

use MediaWiki\Extension\NexaBoard\Store\FollowStore;
use MediaWiki\Extension\NexaBoard\Store\MessageStore;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;

class BoardNotificationManager {

	public function __construct(
		private readonly UserFactory $userFactory,
		private readonly MessageStore $messageStore,
		private readonly FollowStore $followStore
	) {}

	public function onThreadCreated(
		int $threadId,
		User $author,
		User $boardOwner,
		string $title,
		string $body
	): void {
		if ( !$this->echoLoaded() ) {
			return;
		}

		if ( $author->getId() !== $boardOwner->getId() ) {
			\MediaWiki\Extension\Notifications\Model\Event::create( [
				'type'  => 'nexaboard-post',
				'agent' => $author,
				'title' => \MediaWiki\Title\Title::makeTitle( NS_USER, $boardOwner->getName() ),
				'extra' => [
					'post-id'         => $threadId,
					'post-title'      => $this->snippet( $title, 100 ),
					'board-owner-name' => $boardOwner->getName(),
					\MediaWiki\Extension\Notifications\Model\Event::RECIPIENTS_IDX => [ $boardOwner->getId() ],
				],
			] );
		}

		$this->fireMentions( $threadId, $author, $boardOwner, $body );
	}

	public function onReplyCreated(
		int $threadId,
		int $msgId,
		User $author,
		User $boardOwner,
		string $title,
		string $body,
		?int $parentId = null
	): void {
		if ( !$this->echoLoaded() ) {
			return;
		}

		$recipients = $this->resolveRecipients( $threadId, $author->getId() );

		if ( !empty( $recipients ) ) {
			\MediaWiki\Extension\Notifications\Model\Event::create( [
				'type'  => 'nexaboard-reply',
				'agent' => $author,
				'title' => \MediaWiki\Title\Title::makeTitle( NS_USER, $boardOwner->getName() ),
				'extra' => [
					'post-id'         => $threadId,
					'reply-id'        => $msgId,
					'post-title'      => $this->snippet( $title, 100 ),
					'board-owner-name' => $boardOwner->getName(),
					\MediaWiki\Extension\Notifications\Model\Event::RECIPIENTS_IDX => array_values( $recipients ),
				],
			] );
		}

		$this->fireMentions( $threadId, $author, $boardOwner, $body );
	}

	/**
	 * Who hears about activity in a thread: everyone who has posted in it, plus
	 * anyone who explicitly followed it, minus anyone who explicitly muted it
	 * and the person who just posted.
	 *
	 * @return int[]
	 */
	private function resolveRecipients( int $threadId, int $authorId ): array {
		$participants = $this->messageStore->getParticipantIds( $threadId, $authorId );
		$states       = $this->followStore->getFollowStates( $threadId );

		$recipients = array_flip( $participants );

		foreach ( $states['followers'] as $uid ) {
			$recipients[$uid] = true;
		}
		foreach ( $states['muted'] as $uid ) {
			unset( $recipients[$uid] );
		}
		unset( $recipients[$authorId] );

		return array_map( 'intval', array_keys( $recipients ) );
	}

	private function fireMentions(
		int $threadId,
		User $author,
		User $boardOwner,
		string $body
	): void {
		$mentionedIds = $this->parseMentions( $body, $author->getId() );

		if ( !$mentionedIds ) {
			return;
		}

		\MediaWiki\Extension\Notifications\Model\Event::create( [
			'type'  => 'nexaboard-mention',
			'agent' => $author,
			'title' => \MediaWiki\Title\Title::makeTitle( NS_USER, $boardOwner->getName() ),
			'extra' => [
				'post-id'         => $threadId,
				'board-owner-name' => $boardOwner->getName(),
				'content'         => $this->snippet( strip_tags( $body ), 150 ),
				\MediaWiki\Extension\Notifications\Model\Event::RECIPIENTS_IDX => array_values( $mentionedIds ),
			],
		] );
	}

	private function parseMentions( string $body, int $authorId ): array {
		if ( !preg_match_all( '/@([A-Za-z0-9_\-\.]{1,255})/', $body, $m ) ) {
			return [];
		}

		$seen = [];
		$ids  = [];

		foreach ( $m[1] as $raw ) {
			$name = str_replace( '_', ' ', $raw );

			if ( isset( $seen[$name] ) ) {
				continue;
			}
			$seen[$name] = true;

			$user = $this->userFactory->newFromName( $name );
			if ( $user && $user->getId() > 0 && $user->getId() !== $authorId ) {
				$ids[] = $user->getId();
			}
		}

		return $ids;
	}

	private function echoLoaded(): bool {
		return class_exists( \MediaWiki\Extension\Notifications\Model\Event::class );
	}

	private function snippet( string $text, int $maxLen ): string {
		if ( mb_strlen( $text ) <= $maxLen ) {
			return $text;
		}
		return mb_substr( $text, 0, $maxLen ) . '…';
	}
}
