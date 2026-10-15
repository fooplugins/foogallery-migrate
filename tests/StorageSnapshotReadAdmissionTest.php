<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\LegacyCollectionEnvelope;
use FooPlugins\FooGalleryMigrate\LegacyRecordCodec;
use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use FooPlugins\FooGalleryMigrate\LegacyTableSnapshot;
use FooPlugins\FooGalleryMigrate\StorageMemoryBaseline;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/LegacyTableSnapshotTest.php';

/** Real read/rebuild/digest consumers; physical transport is explicitly a spy. */
class StorageSnapshotReadAdmissionTest extends TestCase {
	public function test_ordinary_nested_binary_collection_keeps_complete_parity(): void {
		$db = new SnapshotReadAdmissionWpdb(); $seen = array();
		$snapshot = new LegacyTableSnapshot( $db, 7, 100, 1048576, null, function ( $bytes ) use ( &$seen, $db ) {
			$seen[] = array( $bytes, $db->fetched ); return true;
		} );
		$this->assertSame( $db->value, $snapshot->read_bounded( 'bucket' ) );
		$this->assertGreaterThanOrEqual( 6, count( $seen ) );
		$this->assertFalse( $seen[0][1] );
		$this->assertTrue( $seen[2][1] );
		$this->assertGreaterThan( 0, min( array_column( $seen, 0 ) ) );
		foreach ( $db->queries as $sql ) { $this->assertStringContainsString( 'blog_id = 7', $sql ); }
	}

	public function test_denied_read_budget_never_fetches_root_payload_or_driver_rows(): void {
		$db = new SnapshotReadAdmissionWpdb();
		$snapshot = new LegacyTableSnapshot( $db, 7, 100, 1048576, null, function ( $bytes ) { return false; } );
		try { $snapshot->read_bounded( 'bucket' ); $this->fail( 'Read bypassed refusal.' ); }
		catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'memory admission', $error->getMessage() ); }
		$this->assertFalse( $db->fetched );
		$this->assertStringNotContainsString( 'SELECT root_payload FROM', implode( "\n", $db->queries ) );
	}

	public function test_driver_backing_is_sampled_before_rebuild_and_high_water_survives_refusal(): void {
		$db = new SnapshotReadAdmissionWpdb(); $db->allocate_driver_slab = true;
		$memory = new StorageMemoryBaseline(); $before = $memory->sample(); $during = 0;
		$snapshot = new LegacyTableSnapshot( $db, 7, 100, 1048576, null, function ( $bytes ) use ( $db, $memory, &$during ) {
			if ( $db->fetched ) { $during = $memory->sample(); return false; } return true;
		} );
		try { $snapshot->read_bounded( 'bucket' ); $this->fail( 'Read rebuilt despite refusal.' ); }
		catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'memory admission', $error->getMessage() ); }
		$this->assertGreaterThan( $before, $during );
		$db->driver_slab = null;
		$this->assertGreaterThanOrEqual( $during, $memory->sample() );
	}

	public function test_each_rebuilt_root_is_rechecked_with_previous_outputs_still_live(): void {
		$db = new SnapshotReadAdmissionWpdb(); $after_fetch = 0;
		$snapshot = new LegacyTableSnapshot( $db, 7, 100, 1048576, null, function ( $bytes ) use ( $db, &$after_fetch ) {
			if ( $db->fetched && ++$after_fetch === 3 ) { return false; } return true;
		} );
		try { $snapshot->read_bounded( 'bucket' ); $this->fail( 'Second root bypassed refusal.' ); }
		catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'memory admission', $error->getMessage() ); }
		$this->assertSame( 3, $after_fetch );
	}
}

class SnapshotReadAdmissionWpdb extends SnapshotTestWpdb {
	public $value;
	public $fetched = false;
	public $allocate_driver_slab = false;
	public $driver_slab;
	private $root;
	private $rows = array();
	private $stats = array( 'record_count' => 0, 'payload_bytes' => 0, 'encoded_bytes' => 0 );
	public function __construct() {
		$this->value = array( 'one' => array( 'nested' => array( 1, 2, 3 ) ), 'two' => array( 'binary' => "\0\xff" ) );
		$split = ( new LegacyCollectionEnvelope() )->split( 'bucket', 'map', $this->value );
		$codec = new LegacyStateCodec(); $this->root = $codec->encode( $split['root'] ); $order = 0;
		foreach ( $split['items'] as $key => $value ) {
			$stats = ( new LegacyRecordCodec() )->flatten_tree( 'bucket', $key, $value, $order++, function ( $row ) {
				$row['id'] = count( $this->rows ) + 1; $row['blog_id'] = 7; $row['state_id'] = 4; $row['generation'] = 1; $this->rows[] = $row;
			} );
			foreach ( $stats as $name => $amount ) { $this->stats[$name] += $amount; }
		}
		$this->head = array( 'id' => 4, 'blog_id' => 7, 'state_key_value' => $codec->key_value( 'bucket' ), 'state_order' => 0,
			'state_kind' => 'map', 'active_generation' => 1, 'revision' => 1, 'codec_version' => 1, 'record_count' => $this->stats['record_count'],
			'payload_bytes' => $this->stats['payload_bytes'] + strlen( $this->root ), 'root_payload_bytes' => strlen( $this->root ) );
	}
	public function get_results( $sql, $format ) {
		$this->queries[] = $sql;
		if ( false !== strpos( $sql, 'AS root_payload_bytes' ) ) { return array( $this->head ); }
		if ( false !== strpos( $sql, 'AS snapshot_digest' ) ) {
			return array( array( 'snapshot_digest' => ( new LegacyStateCodec() )->snapshot_digest( $this->value ), 'digest_revision' => 1, 'digest_status' => 'current' ) );
		}
		if ( false !== strpos( $sql, 'AS record_count' ) ) { return array( $this->stats ); }
		if ( false !== strpos( $sql, 'SELECT root_payload FROM' ) ) { return array( array( 'root_payload' => $this->root ) ); }
		if ( false !== strpos( $sql, 'legacy_records`' ) ) {
			$this->fetched = true;
			if ( $this->allocate_driver_slab ) { $this->driver_slab = str_repeat( 'd', 8388608 ); }
			return $this->rows;
		}
		throw new \LogicException( 'Unexpected snapshot read spy query: ' . $sql );
	}
}
