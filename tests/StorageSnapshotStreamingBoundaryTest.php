<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\LegacySnapshotRowReader;
use FooPlugins\FooGalleryMigrate\LegacyTableReader;
use FooPlugins\FooGalleryMigrate\LegacyTableSnapshot;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/SnapshotScaleConnection.php';

/** Scoped SQL host tests, not native driver or completion-model qualification. */
class StorageSnapshotStreamingBoundaryTest extends TestCase {
	private function value( $bytes = 0 ) {
		$children = array();
		for ( $i = 0; $i < 70; ++$i ) { $children['child-' . $i] = array( 'object_type' => 'image', 'unknown' => $bytes ? str_repeat( 'x', $bytes ) : "\0\xff", 'children' => array() ); }
		$children[17] = false; $children["binary\0\xff"] = array( 'unknown' => array( 'nested' => null ) );
		return array( 'root' => array( 'object_type' => 'gallery', 'children' => $children, 'unknown' => array( true, 1.25 ) ) );
	}
	private function change( $value ) { return array( array( 'key' => 'bucket', 'order' => 0, 'kind' => 'map', 'value' => $value, 'expected_revision' => null ) ); }
	private function snapshot( $db, $deadline = null, $memory = null ) { return new LegacyTableSnapshot( $db, 7, 1000, 8388608, $deadline, $memory ); }

	public function test_single_root_multiwindow_nonpreorder_and_typed_binary_unknown_empty_parity(): void {
		$db = new SnapshotScaleConnection(); $value = $this->value();
		$db->after_event = function ( $event, $db ) {
			if ( 'stats' !== $event || ! count( $db->records ) ) { return; }
			// Rewrites may assign physical IDs in the opposite of preorder.
			foreach ( $db->records as $i => $row ) { $r = iterator_to_array( $row ); $r['id'] = 1000 - $i; $db->records[$i] = $r; }
			$db->after_event = null;
		};
		$snapshot = $this->snapshot( $db ); $snapshot->replace_many( $this->change( $value ) );
		$this->assertSame( serialize( $value ), serialize( $snapshot->read_bounded( 'bucket' ) ) );
		$this->assertSame( 1, $db->events['commit'] ); $this->assertGreaterThan( 3, $db->fetches );
		$this->assertLessThanOrEqual( 32, $db->max_window_rows );
		$this->assertLessThanOrEqual( LegacyTableReader::MAX_RECORD_BYTES, $db->max_window_bytes );
	}

	public function test_byte_window_restricts_rows_before_driver_allocation(): void {
		$db = new SnapshotScaleConnection(); $value = $this->value( 40000 ); $snapshot = $this->snapshot( $db );
		$snapshot->replace_many( $this->change( $value ) );
		$this->assertSame( serialize( $value ), serialize( $snapshot->read_bounded( 'bucket' ) ) );
		$this->assertLessThan( 32, $db->max_window_rows );
		$this->assertLessThanOrEqual( LegacyTableReader::MAX_RECORD_BYTES, $db->max_window_bytes );
	}

	public function test_byte_bound_plus_one_refuses_before_payload_fetch(): void {
		$db = new StreamingMetadataOnlyConnection();
		$reader = new LegacySnapshotRowReader( $db, 'rows', '*', 'OCTET_LENGTH(payload)', array( 7, 1, 1 ), 100, function () {} );
		try { $reader->page( null, 0, 0 ); $this->fail( 'Oversized metadata fetched payload.' ); }
		catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'before payload fetch', $error->getMessage() ); }
		$this->assertSame( 1, $db->reads );
	}

	public static function damages(): array {
		return array_map( function ( $fault ) { return array( $fault ); }, array( 'late-projection', 'late-logical-hash', 'late-payload-hash', 'duplicate-order', 'duplicate-key', 'missing', 'orphan', 'cycle' ) );
	}
	/** @dataProvider damages */
	public function test_damaged_rows_never_publish_or_escape_as_prefix( $fault ): void {
		$db = new SnapshotScaleConnection(); $authority = $GLOBALS['foogallery_migrate_test_options'];
		$db->after_event = function ( $event, $db ) use ( $fault ) {
			if ( 'stats' !== $event || ! count( $db->records ) ) { return; }
			$rows = array_map( function ( $row ) { return iterator_to_array( $row ); }, $db->records );
			$last = count( $rows ) - 1;
			if ( 'late-projection' === $fault ) { $rows[$last]['object_type'] = 'damaged'; }
			if ( 'late-logical-hash' === $fault ) { $rows[$last]['logical_hash'] = str_repeat( '0', 64 ); }
			if ( 'late-payload-hash' === $fault ) { $rows[$last]['payload_hash'] = str_repeat( '0', 64 ); }
			if ( 'duplicate-order' === $fault ) { $rows[$last]['item_order'] = $rows[$last - 1]['item_order']; }
			if ( 'duplicate-key' === $fault ) { $rows[$last] = $rows[$last - 1]; $rows[$last]['id'] = 1000; }
			if ( 'missing' === $fault ) { array_pop( $rows ); }
			if ( 'orphan' === $fault ) { $rows[$last]['parent_node_hash'] = str_repeat( 'a', 64 ); }
			if ( 'cycle' === $fault ) { $rows[0]['parent_node_hash'] = $rows[$last]['node_hash']; }
			$db->records = $rows; $db->after_event = null;
		};
		$returned = null;
		try { $returned = $this->snapshot( $db )->replace_many( $this->change( $this->value() ) ); $this->fail( 'Damage published: ' . $fault ); }
		catch ( \RuntimeException $error ) { $this->assertNotEmpty( $error->getMessage() ); }
		$this->assertNull( $returned ); $this->assertSame( array(), $db->heads ); $this->assertSame( array(), $db->records );
		$this->assertSame( 1, $db->events['rollback'] ); $this->assertArrayNotHasKey( 'publish', $db->events ); $this->assertArrayNotHasKey( 'commit', $db->events );
		$this->assertSame( $authority, $GLOBALS['foogallery_migrate_test_options'] );
	}

	public static function interruptions(): array { return array( array( 'throw' ), array( 'memory' ), array( 'deadline' ) ); }
	/** @dataProvider interruptions */
	public function test_interrupted_fetch_or_decode_keeps_full_forecast_and_rolls_back( $fault ): void {
		$db = new SnapshotScaleConnection(); $deny = false; $seen = array();
		$db->after_event = function ( $event, $db ) use ( &$deny, $fault ) {
			if ( 'fetch' === $event && 3 === $db->fetches ) {
				if ( 'throw' === $fault ) { throw new \RuntimeException( 'mid-fetch failure' ); }
				if ( 'deadline' === $fault ) { usleep( 100000 ); }
				$deny = true;
			}
		};
		$memory = function ( $bytes ) use ( &$deny, &$seen, $db, $fault ) {
			if ( $db->fetches ) { $seen[] = $bytes; }
			return 'deadline' === $fault || ! $deny;
		};
		try { $this->snapshot( $db, microtime( true ) + ( 'deadline' === $fault ? 0.05 : 10.0 ), $memory )->replace_many( $this->change( $this->value() ) ); $this->fail( 'Interruption published.' ); }
		catch ( \RuntimeException $error ) { $this->assertNotEmpty( $error->getMessage() ); }
		$this->assertSame( 1, $db->events['rollback'] ); $this->assertArrayNotHasKey( 'publish', $db->events ); $this->assertSame( array(), $db->heads );
		$this->assertGreaterThan( 0, min( $seen ) ); $this->assertSame( min( $seen ), max( $seen ), 'Fetching/attaching must not consume future credit.' );
	}
}

class StreamingMetadataOnlyConnection {
	public $reads = 0;
	public $last_error = '';
	public function prepare( $sql, $args ) { return $sql; }
	public function get_results( $sql, $format ) {
		++$this->reads;
		if ( 0 !== strpos( $sql, 'SELECT id, item_order,' ) ) { throw new \LogicException( 'Payload must not be fetched.' ); }
		return array( array( 'id' => '1', 'item_order' => '0', 'window_bytes' => '101' ) );
	}
}
