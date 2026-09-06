<?php

namespace MediaWiki\Extension\NexaBoard\Store;

use Wikimedia\Rdbms\IConnectionProvider;

/**
 * Explicit follow state for threads.
 *
 * Anyone who posts in a thread follows it implicitly, so a row here exists only
 * when a user has deliberately followed a thread they never posted in, or muted
 * one they did. The two are distinguished by nbf_muted.
 */
class FollowStore {

	public function __construct(
		private readonly IConnectionProvider $dbProvider
	) {}

	public function setFollowing( int $threadId, int $userId, bool $following, string $now ): void {
		$dbw = $this->dbProvider->getPrimaryDatabase();

		$dbw->newInsertQueryBuilder()
			->insertInto( 'nexaboard_follow' )
			->row( [
				'nbf_thread_id' => $threadId,
				'nbf_user_id'   => $userId,
				'nbf_muted'     => $following ? 0 : 1,
				'nbf_created'   => $now,
			] )
			->onDuplicateKeyUpdate()
			->uniqueIndexFields( [ 'nbf_thread_id', 'nbf_user_id' ] )
			->set( [
				'nbf_muted'   => $following ? 0 : 1,
				'nbf_created' => $now,
			] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * Explicit state for one user on one thread: true if followed, false if
	 * muted, null if they have never expressed a preference.
	 */
	public function getExplicitState( int $threadId, int $userId ): ?bool {
		if ( !$userId ) {
			return null;
		}

		$dbr = $this->dbProvider->getReplicaDatabase();

		$muted = $dbr->newSelectQueryBuilder()
			->select( 'nbf_muted' )
			->from( 'nexaboard_follow' )
			->where( [ 'nbf_thread_id' => $threadId, 'nbf_user_id' => $userId ] )
			->caller( __METHOD__ )
			->fetchField();

		if ( $muted === false || $muted === null ) {
			return null;
		}

		return !(int)$muted;
	}

	/**
	 * Explicit state for one user across many threads, keyed by thread id.
	 * Saves a query per thread when rendering a board.
	 *
	 * @return array<int,bool>
	 */
	public function getExplicitStates( array $threadIds, int $userId ): array {
		if ( !$threadIds || !$userId ) {
			return [];
		}

		$dbr = $this->dbProvider->getReplicaDatabase();

		$out = [];
		foreach (
			$dbr->newSelectQueryBuilder()
				->select( [ 'nbf_thread_id', 'nbf_muted' ] )
				->from( 'nexaboard_follow' )
				->where( [ 'nbf_thread_id' => $threadIds, 'nbf_user_id' => $userId ] )
				->caller( __METHOD__ )
				->fetchResultSet() as $row
		) {
			$out[(int)$row->nbf_thread_id] = !(int)$row->nbf_muted;
		}

		return $out;
	}

	/**
	 * Users who explicitly followed the thread, and users who explicitly muted
	 * it, as two lists of user ids.
	 *
	 * @return array{followers:int[],muted:int[]}
	 */
	public function getFollowStates( int $threadId ): array {
		$dbr = $this->dbProvider->getReplicaDatabase();

		$followers = [];
		$muted     = [];

		foreach (
			$dbr->newSelectQueryBuilder()
				->select( [ 'nbf_user_id', 'nbf_muted' ] )
				->from( 'nexaboard_follow' )
				->where( [ 'nbf_thread_id' => $threadId ] )
				->caller( __METHOD__ )
				->fetchResultSet() as $row
		) {
			if ( (int)$row->nbf_muted ) {
				$muted[] = (int)$row->nbf_user_id;
			} else {
				$followers[] = (int)$row->nbf_user_id;
			}
		}

		return [ 'followers' => $followers, 'muted' => $muted ];
	}

	public function deleteByThread( int $threadId ): void {
		$dbw = $this->dbProvider->getPrimaryDatabase();

		$dbw->newDeleteQueryBuilder()
			->deleteFrom( 'nexaboard_follow' )
			->where( [ 'nbf_thread_id' => $threadId ] )
			->caller( __METHOD__ )
			->execute();
	}
}
