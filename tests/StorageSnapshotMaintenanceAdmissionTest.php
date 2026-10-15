<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\LegacyCollectionEnvelope;
use FooPlugins\FooGalleryMigrate\LegacyRecordCodec;
use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use FooPlugins\FooGalleryMigrate\LegacyTableSnapshot;
use FooPlugins\FooGalleryMigrate\StorageConversion;
use FooPlugins\FooGalleryMigrate\StorageMaintenanceGate;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/StorageSnapshotAdmissionTest.php';

/** Production coordinator/snapshot paths over a query spy, NOT native SQL. */
class StorageSnapshotMaintenanceAdmissionTest extends TestCase {
	private function coordinator( $db, $memory ) {
		$GLOBALS['wpdb'] = $db; $GLOBALS['foogallery_migrate_test_blog_id'] = 7;
		$manager = new StorageConversion( array( 'max_raw_bytes' => 1048576, 'amplification' => 1, 'reserve_bytes' => 1 ), $memory );
		$r = new \ReflectionClass( $manager );
		foreach ( array( 'connection' => $db, 'gate' => new MaintenanceAdmissionGate(), 'deadline' => microtime( true ) + 10 ) as $name => $value ) {
			$r->getProperty( $name )->setValue( $manager, $value );
		}
		$r->getMethod( 'start_memory' )->invoke( $manager ); $r->getMethod( 'storage' )->invoke( $manager );
		$db->queries = array(); return $manager;
	}
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['foogallery_migrate_test_blog_id'] );
	}
	public function test_coordinator_dirty_head_refuses_before_root_or_record_payload(): void {
		$db = new MaintenanceAdmissionWpdb(); $memory = new SnapshotAdmissionBaseline();
		$manager = $this->coordinator( $db, $memory ); $memory->observed_bytes = 134217728;
		$previous = ini_get( 'memory_limit' ); ini_set( 'memory_limit', '128M' );
		try {
			( new \ReflectionMethod( $manager, 'table_snapshot' ) )->invoke( $manager, true );
			$this->fail( 'Dirty coordinator snapshot bypassed memory refusal.' );
		} catch ( \RuntimeException $error ) {
			$this->assertSame( 'Retain recorded authority and all conversion evidence; storage recovery is required.', $error->getMessage() );
		} finally { ini_set( 'memory_limit', $previous ); }
		$this->assertFalse( $db->payload_fetched ); $this->assertFalse( $db->published );
	}
	public function test_coordinator_ordinary_dirty_collection_publishes_and_reads_complete_value(): void {
		$db = new MaintenanceAdmissionWpdb(); $memory = new SnapshotAdmissionBaseline();
		$manager = $this->coordinator( $db, $memory ); $before = $memory->samples;
		$result = ( new \ReflectionMethod( $manager, 'table_snapshot' ) )->invoke( $manager, true );
		$this->assertSame( array( 'bucket' => $db->value ), $result['value'] );
		$this->assertTrue( $db->published ); $this->assertContains( 'COMMIT', $db->queries );
		$this->assertGreaterThan( $before + 5, $memory->samples );
		foreach ( $db->queries as $sql ) {
			if ( 0 === strpos( $sql, 'SELECT' ) && false !== strpos( $sql, ' FROM ' ) ) { $this->assertStringContainsString( 'blog_id = 7', $sql ); }
		}
	}
	public function test_direct_maintenance_refusal_precedes_root_hydration(): void {
		$db = new MaintenanceAdmissionWpdb(); $seen = array();
		$snapshot = new LegacyTableSnapshot( $db, 7, 100, 1048576, null, function ( $bytes ) use ( &$seen ) { $seen[] = $bytes; return false; } );
		try { $snapshot->verify_digest( 'bucket', 1 ); $this->fail( 'Maintenance bypassed refusal.' ); }
		catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'memory admission', $error->getMessage() ); }
		$this->assertCount( 1, $seen ); $this->assertGreaterThan( 0, $seen[0] );
		$this->assertFalse( $db->payload_fetched ); $this->assertFalse( $db->published );
	}
	/** @dataProvider maintenanceSeams */
	public function test_material_maintenance_phases_are_rechecked( $seam ): void {
		$db = new MaintenanceAdmissionWpdb(); $seen = 0;
		$snapshot = new LegacyTableSnapshot( $db, 7, 100, 1048576, null, function ( $bytes ) use ( $db, $seam, &$seen ) {
			++$seen;
			return 'page' === $seam ? ! $db->stats_fetched : ! $db->records_fetched;
		} );
		try { $snapshot->verify_digest( 'bucket', 1 ); $this->fail( 'Material maintenance seam bypassed refusal.' ); }
		catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'memory admission', $error->getMessage() ); }
		$this->assertGreaterThan( 1, $seen ); $this->assertFalse( $db->published );
		if ( 'page' === $seam ) { $this->assertFalse( $db->records_fetched ); }
		else { $this->assertTrue( $db->records_fetched ); }
	}
	public static function maintenanceSeams(): array { return array( array( 'page' ), array( 'digest' ) ); }

	/** @dataProvider publicationFailures */
	public function test_maintenance_callback_failure_in_publication_transaction_cleans_up( $class ): void {
		$db = new MaintenanceAdmissionWpdb(); $fault = new $class( 'maintenance callback failure' );
		$snapshot = new LegacyTableSnapshot( $db, 7, 100, 1048576, null, function ( $bytes ) use ( $db, $fault ) {
			if ( in_array( 'START TRANSACTION', $db->queries, true ) ) { throw $fault; } return true;
		} );
		try { $snapshot->verify_digest( 'bucket', 1 ); $this->fail( 'Missing callback failure.' ); }
		catch ( \Throwable $error ) { $this->assertSame( $fault, $error ); }
		$this->assertSame( 'ROLLBACK', end( $db->queries ) ); $this->assertFalse( $db->published );
		$this->assertFalse( ( new \ReflectionProperty( $snapshot, 'busy' ) )->getValue( $snapshot ) );
	}
	public static function publicationFailures(): array {
		return array( array( '\\RuntimeException' ), array( '\\Error' ), array( '\\TypeError' ) );
	}
}

class MaintenanceAdmissionGate extends StorageMaintenanceGate {
	public function __construct() {}
	public function renew_maintenance() { return true; }
	public function maintenance_ready( $engine_verdict ) { return true; }
}

class MaintenanceAdmissionWpdb extends SnapshotTestWpdb {
	public $prefix = 'wp_';
	public $value = array( 'one' => "ordinary\0\xff" );
	public $payload_fetched = false;
	public $records_fetched = false;
	public $stats_fetched = false;
	public $published = false;
	private $root;
	private $rows = array();
	private $stats;
	public function __construct() {
		$codec = new LegacyStateCodec();
		$split = ( new LegacyCollectionEnvelope() )->split( 'bucket', 'map', $this->value ); $this->root = $codec->encode( $split['root'] );
		$this->stats = ( new LegacyRecordCodec() )->flatten_tree( 'bucket', 'one', $this->value['one'], 0, function ( $row ) {
			$row['id'] = 1; $row['blog_id'] = 7; $row['state_id'] = 4; $row['generation'] = 1;
			$row['encoded_bytes'] = strlen( $row['payload'] ) + strlen( $row['mutable_payload'] ) + strlen( $row['key_value'] );
			$this->rows[] = $row;
		} );
		$this->head = array( 'id' => 4, 'blog_id' => 7, 'state_key_value' => $codec->key_value( 'bucket' ), 'state_order' => 0,
			'state_kind' => 'map', 'active_generation' => 1, 'revision' => 1, 'codec_version' => 1, 'record_count' => $this->stats['record_count'],
			'payload_bytes' => $this->stats['payload_bytes'] + strlen( $this->root ), 'root_payload_bytes' => strlen( $this->root ) );
	}
	public function is_current() { return true; }
	public function query( $sql ) {
		if ( false !== strpos( $sql, 'SET snapshot_digest' ) ) { $this->published = true; }
		return parent::query( $sql );
	}
	public function get_results( $sql, $format ) {
		$this->queries[] = $sql;
		if ( false !== strpos( $sql, 'ORDER BY state_order LIMIT 101' ) ) {
			return array( array_merge( $this->head, array( 'key_value' => $this->head['state_key_value'], 'digest_status' => $this->published ? 'current' : 'dirty' ) ) );
		}
		if ( false !== strpos( $sql, 'AS root_payload_bytes' ) ) { return array( $this->head ); }
		if ( false !== strpos( $sql, 'SELECT root_payload, digest_status' ) ) {
			$this->payload_fetched = true;
			return array( array( 'root_payload' => $this->root, 'digest_status' => $this->published ? 'current' : 'dirty',
				'digest_revision' => $this->published ? 1 : 0, 'logical_digest' => ( new LegacyStateCodec() )->snapshot_digest( $this->value ) ) );
		}
		if ( false !== strpos( $sql, 'AS snapshot_digest' ) ) {
			return array( array( 'snapshot_digest' => ( new LegacyStateCodec() )->snapshot_digest( $this->value ), 'digest_revision' => 1, 'digest_status' => 'current' ) );
		}
		if ( false !== strpos( $sql, 'SELECT root_payload FROM' ) ) { $this->payload_fetched = true; return array( array( 'root_payload' => $this->root ) ); }
		if ( false !== strpos( $sql, 'AS record_count' ) ) { $this->stats_fetched = true; return array( $this->stats ); }
		if ( 0 === strpos( $sql, 'SELECT' ) && false !== strpos( $sql, 'legacy_records`' ) ) {
			if ( false !== strpos( $sql, 'parent_node_hash = UNHEX' ) || false !== strpos( $sql, 'item_order > 0' ) ) { return array(); }
			if ( false !== strpos( $sql, '`payload`' ) ) { $this->records_fetched = true; return $this->rows; }
			$fields = false !== strpos( $sql, 'SELECT id, item_order' ) ? array( 'id', 'item_order', 'node_hash' ) :
				array( 'id', 'blog_id', 'state_id', 'generation', 'item_order', 'node_hash', 'parent_node_hash', 'payload_bytes', 'encoded_bytes' );
			return array( array_intersect_key( $this->rows[0], array_flip( $fields ) ) );
		}
		array_pop( $this->queries ); return parent::get_results( $sql, $format );
	}
}
