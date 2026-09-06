<?php

namespace MediaWiki\Extension\NexaBoard\Store;

use stdClass;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\SelectQueryBuilder;

class MessageStore {

	/** Byte capacity of nbm_body. See ThreadStore::MAX_TITLE_BYTES. */
	public const MAX_BODY_BYTES = 16777215;

	private const COLS = [
		'nbm_id', 'nbm_thread_id', 'nbm_is_op', 'nbm_parent_id',
		'nbm_author_id', 'nbm_author_name', 'nbm_body', 'nbm_quote_id',
		'nbm_created', 'nbm_edited', 'nbm_edited_by', 'nbm_deleted', 'nbm_deleted_by',
	];

	public function __construct(
		private readonly IConnectionProvider $dbProvider
	) {}

	public function insert(
		int $threadId,
		bool $isOp,
		int $authorId,
		string $authorName,
		string $body,
		string $now,
		?int $parentId = null,
		?int $quoteId = null
	): int {
		$dbw = $this->dbProvider->getPrimaryDatabase();

		$dbw->newInsertQueryBuilder()
			->insertInto( 'nexaboard_message' )
			->row( [
				'nbm_thread_id'   => $threadId,
				'nbm_is_op'       => (int)$isOp,
				'nbm_parent_id'   => $parentId,
				'nbm_author_id'   => $authorId,
				'nbm_author_name' => $authorName,
				'nbm_body'        => $body,
				'nbm_quote_id'    => $quoteId,
				'nbm_created'     => $now,
				'nbm_edited'      => null,
				'nbm_deleted'     => 0,
				'nbm_deleted_by'  => null,
			] )
			->caller( __METHOD__ )
			->execute();

		return (int)$dbw->insertId();
	}

	public function getById( int $msgId ): ?stdClass {
		$dbr = $this->dbProvider->getReplicaDatabase();

		$row = $dbr->newSelectQueryBuilder()
			->select( self::COLS )
			->from( 'nexaboard_message' )
			->where( [ 'nbm_id' => $msgId ] )
			->caller( __METHOD__ )
			->fetchRow();

		return $row ?: null;
	}

	public function getOpByThread( int $threadId ): ?stdClass {
		$dbr = $this->dbProvider->getReplicaDatabase();

		$row = $dbr->newSelectQueryBuilder()
			->select( self::COLS )
			->from( 'nexaboard_message' )
			->where( [
				'nbm_thread_id' => $threadId,
				'nbm_is_op'     => 1,
			] )
			->caller( __METHOD__ )
			->fetchRow();

		return $row ?: null;
	}

	public function getRepliesByThread( int $threadId, bool $includeDeleted = false ): array {
		$dbr = $this->dbProvider->getReplicaDatabase();

		$conds = [
			'nbm_thread_id' => $threadId,
			'nbm_is_op'     => 0,
		];
		if ( !$includeDeleted ) {
			$conds['nbm_deleted'] = 0;
		}

		$result = [];
		foreach (
			$dbr->newSelectQueryBuilder()
				->select( self::COLS )
				->from( 'nexaboard_message' )
				->where( $conds )
				->orderBy( 'nbm_created', SelectQueryBuilder::SORT_ASC )
				->caller( __METHOD__ )
				->fetchResultSet() as $row
		) {
			$result[] = $row;
		}

		return $result;
	}

	public function getByThreadIds( array $threadIds, bool $includeDeleted = true ): array {
		if ( !$threadIds ) {
			return [];
		}

		$dbr = $this->dbProvider->getReplicaDatabase();

		$conds = [ 'nbm_thread_id' => $threadIds ];
		if ( !$includeDeleted ) {
			$conds['nbm_deleted'] = 0;
		}

		$result = [];
		foreach (
			$dbr->newSelectQueryBuilder()
				->select( self::COLS )
				->from( 'nexaboard_message' )
				->where( $conds )
				->orderBy( 'nbm_created', SelectQueryBuilder::SORT_ASC )
				->caller( __METHOD__ )
				->fetchResultSet() as $row
		) {
			$result[] = $row;
		}

		return $result;
	}

	public function softDelete( int $msgId, int $deletedBy ): bool {
		$dbw = $this->dbProvider->getPrimaryDatabase();

		$dbw->newUpdateQueryBuilder()
			->update( 'nexaboard_message' )
			->set( [
				'nbm_deleted'    => 1,
				'nbm_deleted_by' => $deletedBy,
			] )
			->where( [ 'nbm_id' => $msgId, 'nbm_deleted' => 0 ] )
			->caller( __METHOD__ )
			->execute();

		return (bool)$dbw->affectedRows();
	}

	public function restore( int $msgId ): bool {
		$dbw = $this->dbProvider->getPrimaryDatabase();

		$dbw->newUpdateQueryBuilder()
			->update( 'nexaboard_message' )
			->set( [
				'nbm_deleted'    => 0,
				'nbm_deleted_by' => null,
			] )
			->where( [ 'nbm_id' => $msgId, 'nbm_deleted' => 1 ] )
			->caller( __METHOD__ )
			->execute();

		return (bool)$dbw->affectedRows();
	}

	/**
	 * Soft-delete every reply in a thread, leaving the originating post alone.
	 *
	 * @return int Number of replies deleted
	 */
	public function softDeleteReplies( int $threadId, int $deletedBy ): int {
		$dbw = $this->dbProvider->getPrimaryDatabase();

		$dbw->newUpdateQueryBuilder()
			->update( 'nexaboard_message' )
			->set( [
				'nbm_deleted'    => 1,
				'nbm_deleted_by' => $deletedBy,
			] )
			->where( [
				'nbm_thread_id' => $threadId,
				'nbm_is_op'     => 0,
				'nbm_deleted'   => 0,
			] )
			->caller( __METHOD__ )
			->execute();

		return $dbw->affectedRows();
	}

	public function updateBody( int $msgId, string $body, string $now, int $editorId ): bool {
		$dbw = $this->dbProvider->getPrimaryDatabase();

		$dbw->newUpdateQueryBuilder()
			->update( 'nexaboard_message' )
			->set( [
				'nbm_body'      => $body,
				'nbm_edited'    => $now,
				'nbm_edited_by' => $editorId,
			] )
			->where( [ 'nbm_id' => $msgId ] )
			->caller( __METHOD__ )
			->execute();

		return (bool)$dbw->affectedRows();
	}

	public function moveToThread( int $msgId, int $targetThreadId ): void {
		$dbw = $this->dbProvider->getPrimaryDatabase();

		$dbw->newUpdateQueryBuilder()
			->update( 'nexaboard_message' )
			->set( [
				'nbm_thread_id' => $targetThreadId,
				'nbm_is_op'     => 0,
			] )
			->where( [ 'nbm_id' => $msgId ] )
			->caller( __METHOD__ )
			->execute();
	}

	public function getParticipantIds( int $threadId, int $excludeId ): array {
		$dbr = $this->dbProvider->getReplicaDatabase();

		$ids = [];
		foreach (
			$dbr->newSelectQueryBuilder()
				->select( 'nbm_author_id' )
				->from( 'nexaboard_message' )
				->where( [
					'nbm_thread_id' => $threadId,
					'nbm_deleted'   => 0,
				] )
				->caller( __METHOD__ )
				->fetchResultSet() as $row
		) {
			$uid = (int)$row->nbm_author_id;
			if ( $uid !== $excludeId ) {
				$ids[$uid] = true;
			}
		}

		return array_keys( $ids );
	}
}
