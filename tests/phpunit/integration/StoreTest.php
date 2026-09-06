<?php

namespace MediaWiki\Extension\NexaBoard\Tests\Integration;

use MediaWiki\Extension\NexaBoard\Store\MessageStore;
use MediaWiki\Extension\NexaBoard\Store\ThreadStore;
use MediaWikiIntegrationTestCase;

/**
 * @group Database
 * @group NexaBoard
 * @covers \MediaWiki\Extension\NexaBoard\Store\ThreadStore
 * @covers \MediaWiki\Extension\NexaBoard\Store\MessageStore
 */
class StoreTest extends MediaWikiIntegrationTestCase {

	private function threadStore(): ThreadStore {
		return $this->getServiceContainer()->get( 'NexaBoard.ThreadStore' );
	}

	private function messageStore(): MessageStore {
		return $this->getServiceContainer()->get( 'NexaBoard.MessageStore' );
	}

	public function testCreateThreadWithOpAndReply(): void {
		$ts = $this->threadStore();
		$ms = $this->messageStore();

		$boardUserId = 42;
		$threadId = $ts->insert( $boardUserId, 'Hello world', '20260101000000' );
		$this->assertGreaterThan( 0, $threadId, 'insert() returns a new thread id' );

		$opId = $ms->insert( $threadId, true, 7, 'Alice', 'Original post body', '20260101000000' );
		$replyId = $ms->insert( $threadId, false, 8, 'Bob', 'A reply', '20260101000100' );
		$this->assertGreaterThan( 0, $opId );
		$this->assertGreaterThan( 0, $replyId );

		$thread = $ts->getById( $threadId );
		$this->assertNotNull( $thread );
		$this->assertSame( 'Hello world', $thread->nbt_title );
		$this->assertSame(
			ThreadStore::STATUS_OPEN,
			(int)$thread->nbt_status
		);

		$op = $ms->getOpByThread( $threadId );
		$this->assertNotNull( $op );
		$this->assertSame( 'Original post body', $op->nbm_body );
		$this->assertSame( 1, (int)$op->nbm_is_op );

		$replies = $ms->getRepliesByThread( $threadId );
		$this->assertCount( 1, $replies );
		$this->assertSame( 'A reply', $replies[0]->nbm_body );
		$this->assertSame( 0, (int)$replies[0]->nbm_is_op );
	}

	public function testGetByBoardUserFiltersAndOrders(): void {
		$ts = $this->threadStore();
		$boardUserId = 123;

		$older = $ts->insert( $boardUserId, 'Older', '20260101000000' );
		$newer = $ts->insert( $boardUserId, 'Newer', '20260201000000' );

		$ts->insert( 999, 'Someone else', '20260301000000' );

		$list = $ts->getByBoardUser( $boardUserId, 10 );
		$this->assertCount( 2, $list, 'Only this board\'s threads are returned' );
		$this->assertSame( $newer, (int)$list[0]->nbt_id, 'Newest thread comes first' );
		$this->assertSame( $older, (int)$list[1]->nbt_id );
	}

	public function testCloseThreadIsIdempotent(): void {
		$ts = $this->threadStore();
		$threadId = $ts->insert( 99, 'Closable', '20260101000000' );

		$this->assertTrue(
			$ts->close( $threadId, 5, '20260101010000' ),
			'First close succeeds'
		);
		$this->assertSame(
			ThreadStore::STATUS_CLOSED,
			(int)$ts->getById( $threadId )->nbt_status
		);
		$this->assertFalse(
			$ts->close( $threadId, 5, '20260101020000' ),
			'Closing an already-closed thread affects no rows'
		);
	}

	public function testDeletedThreadsHiddenByDefault(): void {
		$ts = $this->threadStore();
		$boardUserId = 555;
		$threadId = $ts->insert( $boardUserId, 'To be deleted', '20260101000000' );
		$ts->delete( $threadId, 5, '20260101010000' );

		$this->assertCount(
			0,
			$ts->getByBoardUser( $boardUserId, 10 ),
			'Deleted threads are excluded by default'
		);
		$this->assertCount(
			1,
			$ts->getByBoardUser( $boardUserId, 10, null, true ),
			'Deleted threads are returned when explicitly requested'
		);
	}
}
