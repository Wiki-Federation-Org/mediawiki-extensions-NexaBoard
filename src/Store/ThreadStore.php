<?php

namespace MediaWiki\Extension\NexaBoard\Store;

use stdClass;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\SelectQueryBuilder;

class ThreadStore {

	public const STATUS_OPEN    = 0;
	public const STATUS_CLOSED  = 1;
	public const STATUS_DELETED = 2;
	public const STATUS_MERGED  = 3;

	public function __construct(
		private readonly IConnectionProvider $dbProvider
	) {}

	public function insert( int $boardUserId, string $title, string $now ): int {
		$dbw = $this->dbProvider->getPrimaryDatabase();

		$dbw->newInsertQueryBuilder()
			->insertInto( 'nexaboard_thread' )
			->row( [
				'nbt_board_user_id' => $boardUserId,
				'nbt_title'        => $title,
				'nbt_status'       => self::STATUS_OPEN,
				'nbt_reply_count'  => 0,
				'nbt_created'      => $now,
				'nbt_updated'      => $now,
				'nbt_closed_by'    => null,
				'nbt_deleted_by'   => null,
				'nbt_merged_into'  => null,
			] )
			->caller( __METHOD__ )
			->execute();

		return (int)$dbw->insertId();
	}

	public function getById( int $threadId ): ?stdClass {
		$dbr = $this->dbProvider->getReplicaDatabase();

		$row = $dbr->newSelectQueryBuilder()
			->select( '*' )
			->from( 'nexaboard_thread' )
			->where( [ 'nbt_id' => $threadId ] )
			->caller( __METHOD__ )
			->fetchRow();

		return $row ?: null;
	}

	/**
	 * One page of a board's threads.
	 *
	 * nbt_updated only has second granularity, so it is not unique: several
	 * threads routinely share a timestamp. Ordering and paging therefore use
	 * (nbt_updated, nbt_id) as a composite key — with the timestamp alone, tied
	 * rows come back in arbitrary order and a "< cursor" page boundary silently
	 * skips every thread sharing the boundary second.
	 *
	 * @param ?array $cursor [ timestamp, id ] from the previous page, or null
	 */
	public function getByBoardUser(
		int $boardUserId,
		int $limit,
		?array $cursor = null,
		bool $includeDeleted = false,
		bool $oldestFirst = false
	): array {
		$dbr = $this->dbProvider->getReplicaDatabase();

		$statuses = [ self::STATUS_OPEN, self::STATUS_CLOSED ];
		if ( $includeDeleted ) {
			$statuses[] = self::STATUS_DELETED;
		}

		$conds = [
			'nbt_board_user_id' => $boardUserId,
			'nbt_status'       => $statuses,
		];

		$qb = $dbr->newSelectQueryBuilder()
			->select( [
				'nbt_id', 'nbt_board_user_id', 'nbt_title', 'nbt_status',
				'nbt_reply_count', 'nbt_created', 'nbt_updated',
				'nbt_closed_by', 'nbt_deleted_by', 'nbt_merged_into',
			] )
			->from( 'nexaboard_thread' )
			->where( $conds )
			->orderBy(
				[ 'nbt_updated', 'nbt_id' ],
				$oldestFirst ? SelectQueryBuilder::SORT_ASC : SelectQueryBuilder::SORT_DESC
			)
			->limit( $limit )
			->caller( __METHOD__ );

		if ( $cursor !== null ) {
			[ $ts, $id ] = $cursor;
			$op = $oldestFirst ? '>' : '<';

			// (updated, id) strictly past the cursor, in the sort direction.
			$qb->andWhere( $dbr->orExpr( [
				$dbr->expr( 'nbt_updated', $op, $ts ),
				$dbr->andExpr( [
					$dbr->expr( 'nbt_updated', '=', $ts ),
					$dbr->expr( 'nbt_id', $op, $id ),
				] ),
			] ) );
		}

		$result = [];
		foreach ( $qb->fetchResultSet() as $row ) {
			$result[] = $row;
		}

		return $result;
	}

	/**
	 * Move a whole thread onto another user's board.
	 *
	 * Deleted threads are excluded: a thread nobody can see should not be
	 * handed to a board owner who cannot see it either.
	 */
	public function setBoardUser( int $threadId, int $boardUserId, string $now ): bool {
		$dbw = $this->dbProvider->getPrimaryDatabase();

		$dbw->newUpdateQueryBuilder()
			->update( 'nexaboard_thread' )
			->set( [
				'nbt_board_user_id' => $boardUserId,
				'nbt_updated'       => $now,
			] )
			->where( [
				'nbt_id'     => $threadId,
				'nbt_status' => [ self::STATUS_OPEN, self::STATUS_CLOSED ],
			] )
			->caller( __METHOD__ )
			->execute();

		return $dbw->affectedRows() > 0;
	}

	public function close( int $threadId, int $closedBy, string $now ): bool {
		$dbw = $this->dbProvider->getPrimaryDatabase();

		$dbw->newUpdateQueryBuilder()
			->update( 'nexaboard_thread' )
			->set( [
				'nbt_status'    => self::STATUS_CLOSED,
				'nbt_closed_by' => $closedBy,
				'nbt_updated'   => $now,
			] )
			->where( [
				'nbt_id'     => $threadId,
				'nbt_status' => self::STATUS_OPEN,
			] )
			->caller( __METHOD__ )
			->execute();

		return (bool)$dbw->affectedRows();
	}

	/**
	 * Reopen a closed thread. Scoped to closed threads so this can never
	 * resurrect a deleted or merged one.
	 */
	public function reopen( int $threadId, string $now ): bool {
		$dbw = $this->dbProvider->getPrimaryDatabase();

		$dbw->newUpdateQueryBuilder()
			->update( 'nexaboard_thread' )
			->set( [
				'nbt_status'    => self::STATUS_OPEN,
				'nbt_closed_by' => null,
				'nbt_updated'   => $now,
			] )
			->where( [
				'nbt_id'     => $threadId,
				'nbt_status' => self::STATUS_CLOSED,
			] )
			->caller( __METHOD__ )
			->execute();

		return (bool)$dbw->affectedRows();
	}

	public function delete( int $threadId, int $deletedBy, string $now ): bool {
		$dbw = $this->dbProvider->getPrimaryDatabase();

		$dbw->newUpdateQueryBuilder()
			->update( 'nexaboard_thread' )
			->set( [
				'nbt_status'     => self::STATUS_DELETED,
				'nbt_deleted_by' => $deletedBy,
				'nbt_updated'    => $now,
			] )
			->where( [
				'nbt_id'     => $threadId,
				'nbt_status' => [ self::STATUS_OPEN, self::STATUS_CLOSED ],
			] )
			->caller( __METHOD__ )
			->execute();

		return (bool)$dbw->affectedRows();
	}

	/**
	 * Restore a deleted thread. One that was closed before deletion goes back to
	 * closed rather than silently reopening for replies.
	 */
	public function undelete( int $threadId, string $now ): bool {
		$row = $this->getById( $threadId );
		if ( !$row || (int)$row->nbt_status !== self::STATUS_DELETED ) {
			return false;
		}

		$dbw = $this->dbProvider->getPrimaryDatabase();

		$dbw->newUpdateQueryBuilder()
			->update( 'nexaboard_thread' )
			->set( [
				'nbt_status'     => $row->nbt_closed_by !== null
					? self::STATUS_CLOSED
					: self::STATUS_OPEN,
				'nbt_deleted_by' => null,
				'nbt_updated'    => $now,
			] )
			->where( [
				'nbt_id'     => $threadId,
				'nbt_status' => self::STATUS_DELETED,
			] )
			->caller( __METHOD__ )
			->execute();

		return (bool)$dbw->affectedRows();
	}

	public function setTitle( int $threadId, string $title, string $now ): bool {
		$dbw = $this->dbProvider->getPrimaryDatabase();

		$dbw->newUpdateQueryBuilder()
			->update( 'nexaboard_thread' )
			->set( [ 'nbt_title' => $title, 'nbt_updated' => $now ] )
			->where( [ 'nbt_id' => $threadId ] )
			->caller( __METHOD__ )
			->execute();

		return (bool)$dbw->affectedRows();
	}

	/**
	 * Titles of the threads merged into each of the given threads, keyed by
	 * destination id. Merging moves the messages but leaves the source title
	 * behind on its own row; this is what surfaces it on the target.
	 *
	 * @return array<int,string[]>
	 */
	public function getMergedSourceTitles( array $threadIds ): array {
		if ( !$threadIds ) {
			return [];
		}

		$dbr = $this->dbProvider->getReplicaDatabase();

		$out = [];
		foreach (
			$dbr->newSelectQueryBuilder()
				->select( [ 'nbt_title', 'nbt_merged_into' ] )
				->from( 'nexaboard_thread' )
				->where( [
					'nbt_merged_into' => $threadIds,
					'nbt_status'      => self::STATUS_MERGED,
				] )
				->caller( __METHOD__ )
				->fetchResultSet() as $row
		) {
			$out[(int)$row->nbt_merged_into][] = $row->nbt_title;
		}

		return $out;
	}

	public function markMerged( int $sourceThreadId, int $targetThreadId, string $now ): void {
		$dbw = $this->dbProvider->getPrimaryDatabase();

		$dbw->newUpdateQueryBuilder()
			->update( 'nexaboard_thread' )
			->set( [
				'nbt_status'       => self::STATUS_MERGED,
				'nbt_merged_into'  => $targetThreadId,
				'nbt_reply_count'  => 0,
				'nbt_updated'      => $now,
			] )
			->where( [ 'nbt_id' => $sourceThreadId ] )
			->caller( __METHOD__ )
			->execute();
	}

	public function incrementReplyCount( int $threadId, string $now ): void {
		$dbw = $this->dbProvider->getPrimaryDatabase();

		$dbw->newUpdateQueryBuilder()
			->update( 'nexaboard_thread' )
			->set( [
				'nbt_reply_count = nbt_reply_count + 1',
				'nbt_updated' => $now,
			] )
			->where( [ 'nbt_id' => $threadId ] )
			->caller( __METHOD__ )
			->execute();
	}

	public function decrementReplyCount( int $threadId ): void {
		$dbw = $this->dbProvider->getPrimaryDatabase();

		$dbw->newUpdateQueryBuilder()
			->update( 'nexaboard_thread' )
			->set( [ 'nbt_reply_count = GREATEST(0, nbt_reply_count - 1)' ] )
			->where( [ 'nbt_id' => $threadId ] )
			->caller( __METHOD__ )
			->execute();
	}

	public function recalcReplyCount( int $threadId ): void {
		$dbw  = $this->dbProvider->getPrimaryDatabase();
		$dbr  = $this->dbProvider->getReplicaDatabase();

		$count = (int)$dbr->newSelectQueryBuilder()
			->select( [ 'cnt' => 'COUNT(*)' ] )
			->from( 'nexaboard_message' )
			->where( [
				'nbm_thread_id' => $threadId,
				'nbm_is_op'     => 0,
				'nbm_deleted'   => 0,
			] )
			->caller( __METHOD__ )
			->fetchField();

		$dbw->newUpdateQueryBuilder()
			->update( 'nexaboard_thread' )
			->set( [ 'nbt_reply_count' => $count ] )
			->where( [ 'nbt_id' => $threadId ] )
			->caller( __METHOD__ )
			->execute();
	}

	public function bumpUpdated( int $threadId, string $now ): void {
		$dbw = $this->dbProvider->getPrimaryDatabase();

		$dbw->newUpdateQueryBuilder()
			->update( 'nexaboard_thread' )
			->set( [ 'nbt_updated' => $now ] )
			->where( [ 'nbt_id' => $threadId ] )
			->caller( __METHOD__ )
			->execute();
	}
}
