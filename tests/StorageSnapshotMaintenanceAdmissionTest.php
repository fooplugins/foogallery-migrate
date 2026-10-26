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
	public function test_never_proved_dirty_head_is_verified_before_bounded_reverse_read(): void {
		$db = new MaintenanceAdmissionWpdb();
		$snapshot = new LegacyTableSnapshot( $db, 7, 100, 1048576 );
		$verified = $snapshot->verify_digest( 'bucket', 1 );
		$this->assertTrue( $db->published );
		$this->assertSame( 1, $verified['digest_revision'] );
		$this->assertSame( ( new LegacyStateCodec() )->snapshot_digest( $db->value ), $verified['logical_digest'] );
		$this->assertSame( $db->value, $snapshot->read_bounded( 'bucket' ) );
	}
	public function test_dirty_head_can_retain_only_a_previously_proved_hash(): void {
		$db = new MaintenanceAdmissionWpdb(); ++$db->head['revision'];
		$old_digest = ( new LegacyStateCodec() )->snapshot_digest( $db->value );
		$db->maintenance_digest_fields = array( 'digest_status' => 'dirty', 'digest_revision' => 1,
			'logical_digest' => strtoupper( $old_digest ) );
		$snapshot = new LegacyTableSnapshot( $db, 7, 100, 1048576 );
		$method = new \ReflectionMethod( $snapshot, 'maintenance_head' );
		$head = $method->invoke( $snapshot, 'bucket' );
		$this->assertSame( 2, $head['revision'] ); $this->assertSame( 1, $head['digest_revision'] );
		$this->assertSame( $old_digest, $head['logical_digest'] );
	}
	/** @dataProvider inconsistentDigestMetadata */
	public function test_dirty_and_current_digest_metadata_must_be_coherent( $status, $revision, $digest ): void {
		$db = new MaintenanceAdmissionWpdb(); $db->maintenance_digest_fields = array( 'digest_status' => $status,
			'digest_revision' => $revision, 'logical_digest' => $digest );
		$snapshot = new LegacyTableSnapshot( $db, 7, 100, 1048576 );
		try { $snapshot->verify_digest( 'bucket', 1 ); $this->fail( 'Malformed digest metadata was accepted.' ); }
		catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'Invalid digest revision/hash', $error->getMessage() ); }
		$this->assertFalse( $db->records_fetched ); $this->assertFalse( $db->published );
		$this->assertNotContains( 'START TRANSACTION', $db->queries );
	}
	public static function inconsistentDigestMetadata(): array {
		$hash = str_repeat( 'a', 64 );
		return array( array( 'dirty', 1, null ), array( 'dirty', 0, $hash ),
			array( 'dirty', 1, $hash ), array( 'dirty', 0, 'not-a-hash' ),
			array( 'current', 1, null ), array( 'current', 0, $hash ) );
	}
	public function test_current_digest_mismatch_refuses_instead_of_recertifying(): void {
		$db = new MaintenanceAdmissionWpdb(); $db->published = true;
		$db->maintenance_digest_fields = array( 'digest_status' => 'current', 'digest_revision' => 1,
			'logical_digest' => str_repeat( '0', 64 ) );
		$snapshot = new LegacyTableSnapshot( $db, 7, 100, 1048576 );
		try { $snapshot->verify_digest( 'bucket', 1 ); $this->fail( 'Changed current digest accepted.' ); }
		catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'Current logical digest mismatch', $error->getMessage() ); }
		$this->assertNotContains( 'COMMIT', $db->queries );
	}
	public function test_never_proved_dirty_head_refuses_corrupt_physical_record(): void {
		$db = new MaintenanceAdmissionWpdb();
		$records = new \ReflectionProperty( $db, 'rows' ); $rows = $records->getValue( $db );
		$rows[0]['payload'] .= 'X'; $records->setValue( $db, $rows );
		$snapshot = new LegacyTableSnapshot( $db, 7, 100, 1048576 );
		try { $snapshot->verify_digest( 'bucket', 1 ); $this->fail( 'Corrupt dirty row was certified.' ); }
		catch ( \RuntimeException $error ) { $this->assertNotEmpty( $error->getMessage() ); }
		$this->assertFalse( $db->published ); $this->assertNotContains( 'COMMIT', $db->queries );
	}
	public function test_owning_metadata_reads_real_bounded_snapshot(): void {
		$db = new MaintenanceAdmissionWpdb(); $memory = new SnapshotAdmissionBaseline();
		$manager = $this->coordinator( $db, $memory ); $db->published = true;
		$snapshot = ( new \ReflectionProperty( $manager, 'snapshot' ) )->getValue( $manager );
		$this->assertSame( LegacyTableSnapshot::class, get_class( $snapshot ) );
		$this->assertTrue( ( new \ReflectionMethod( $snapshot, 'read' ) )->isPrivate() );
		$result = ( new \ReflectionMethod( $manager, 'source_metadata' ) )->invoke( $manager, 'table', false );
		$this->assertSame( ( new LegacyStateCodec() )->snapshot_digest( array( 'bucket' => $db->value ) ), $result['logical'] );
		$this->assertSame( hash( 'sha256', json_encode( $db->get_results( 'ORDER BY state_order LIMIT 101', ARRAY_A ) ) ), $result['physical'] );
		$this->assertTrue( $db->records_fetched );
		$this->assertNotContains( 'START TRANSACTION', $db->queries );
		foreach ( $db->queries as $sql ) {
			if ( 0 === strpos( $sql, 'SELECT' ) ) { $this->assertStringContainsString( 'blog_id = 7', $sql ); }
		}
	}
	/** @dataProvider owningMetadataFailures */
	public function test_owning_metadata_preserves_bounded_reader_refusals( $seam ): void {
		$db = new OwningMetadataWpdb(); $memory = new SnapshotAdmissionBaseline();
		$manager = $this->coordinator( $db, $memory ); $db->published = true; $db->dirty_digest = 'dirty' === $seam;
		if ( 'digest' === $seam ) { $db->corrupt_digest = true; }
		if ( 'memory' === $seam ) { $memory->observed_bytes = 134217728; }
		if ( 'owner' === $seam ) { $GLOBALS['foogallery_migrate_test_blog_id'] = 8; }
		$previous = ini_get( 'memory_limit' ); ini_set( 'memory_limit', '128M' );
		try {
			( new \ReflectionMethod( $manager, 'source_metadata' ) )->invoke( $manager, 'table', false );
			$this->fail( 'Owning metadata bypassed bounded reader refusal.' );
		} catch ( \RuntimeException $error ) {
			$messages = array( 'dirty' => 'Snapshot digest is absent, dirty or stale.', 'digest' => 'digest',
				'memory' => 'Retain recorded authority', 'owner' => 'Retain recorded authority' );
			$this->assertStringContainsString( $messages[$seam], $error->getMessage() );
		} finally { ini_set( 'memory_limit', $previous ); }
		$this->assertNotContains( 'START TRANSACTION', $db->queries );
		if ( 'dirty' === $seam ) { $this->assertFalse( $db->records_fetched ); }
		if ( in_array( $seam, array( 'memory', 'owner' ), true ) ) { $this->assertFalse( $db->payload_fetched ); }
		if ( 'owner' === $seam ) { $this->assertSame( array(), $db->queries ); }
	}
	public static function owningMetadataFailures(): array { return array( array( 'dirty' ), array( 'digest' ), array( 'memory' ), array( 'owner' ) ); }
	public function test_direct_maintenance_refusal_precedes_root_hydration(): void {
		$db = new MaintenanceAdmissionWpdb(); $seen = array();
		$snapshot = new LegacyTableSnapshot( $db, 7, 100, 1048576, null, function ( $bytes ) use ( &$seen ) { $seen[] = $bytes; return false; } );
		try { $snapshot->verify_digest( 'bucket', 1 ); $this->fail( 'Maintenance bypassed refusal.' ); }
		catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'memory admission', $error->getMessage() ); }
		$this->assertCount( 1, $seen ); $this->assertGreaterThan( 0, $seen[0] );
		$this->assertFalse( $db->payload_fetched ); $this->assertFalse( $db->published );
	}
	public function test_cursor_double_honours_order_id_ties_limits_and_window_projection(): void {
		$db = new MaintenanceAdmissionWpdb();
		$property = new \ReflectionProperty( $db, 'rows' ); $row = $property->getValue( $db )[0];
		$property->setValue( $db, array( array_merge( $row, array( 'id' => 9, 'item_order' => 1 ) ),
			array_merge( $row, array( 'id' => 2 ) ), $row ) );
		$select = 'SELECT id, item_order, (OCTET_LENGTH(payload)) AS window_bytes FROM `wp_foogallery_legacy_records` WHERE parent_node_hash IS NULL AND ';
		$seek = function ( $order, $id, $limit ) use ( $db, $select ) {
			return $db->get_results( $select . '(item_order > ' . $order . ' OR (item_order = ' . $order . ' AND id > ' . $id . ')) ORDER BY item_order ASC, id ASC LIMIT ' . $limit, ARRAY_A );
		};
		$this->assertSame( array( array( 'id' => 1, 'item_order' => 0, 'window_bytes' => strlen( $row['payload'] ) ) ), $seek( 0, 0, 1 ) );
		$this->assertSame( array( 2, 9 ), array_column( $seek( 0, 1, 32 ), 'id' ) );
		$this->assertSame( array( 9 ), array_column( $seek( 0, 2, 32 ), 'id' ) );
		$this->assertSame( array(), $seek( 1, 9, 32 ) );
		$this->assertFalse( $db->records_fetched );
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
	public $maintenance_digest_fields = null;
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
			$fields = array( 'root_payload' => $this->root, 'digest_status' => $this->published ? 'current' : 'dirty',
				'digest_revision' => $this->published ? 1 : 0,
				'logical_digest' => $this->published ? ( new LegacyStateCodec() )->snapshot_digest( $this->value ) : null );
			return array( null === $this->maintenance_digest_fields ? $fields : array_replace( $fields, $this->maintenance_digest_fields ) );
		}
		if ( false !== strpos( $sql, 'AS snapshot_digest' ) ) {
			return array( array( 'snapshot_digest' => ( new LegacyStateCodec() )->snapshot_digest( $this->value ), 'digest_revision' => 1, 'digest_status' => 'current' ) );
		}
		if ( false !== strpos( $sql, 'SELECT root_payload FROM' ) ) { $this->payload_fetched = true; return array( array( 'root_payload' => $this->root ) ); }
		if ( false !== strpos( $sql, 'AS record_count' ) ) { $this->stats_fetched = true; return array( $this->stats ); }
		if ( 0 === strpos( $sql, 'SELECT' ) && false !== strpos( $sql, 'legacy_records`' ) ) {
			if ( false !== strpos( $sql, 'parent_node_hash = UNHEX' ) ) { return array(); }
			$rows = $this->rows;
			if ( preg_match( '/\(item_order > (\d+) OR \(item_order = (\d+) AND id > (\d+)\)\)/', $sql, $cursor ) ) {
				$rows = array_values( array_filter( $rows, function ( $row ) use ( $cursor ) {
					return $row['item_order'] > (int) $cursor[1] || ( $row['item_order'] === (int) $cursor[2] && $row['id'] > (int) $cursor[3] );
				} ) );
			}
			usort( $rows, function ( $a, $b ) { return $a['item_order'] === $b['item_order'] ? $a['id'] <=> $b['id'] : $a['item_order'] <=> $b['item_order']; } );
			if ( preg_match( '/LIMIT (\d+)/', $sql, $limit ) ) { $rows = array_slice( $rows, 0, (int) $limit[1] ); }
			if ( false !== strpos( $sql, '`payload`' ) ) { $this->records_fetched = true; return $rows; }
			$fields = false !== strpos( $sql, 'SELECT id, item_order' ) ? array( 'id', 'item_order', 'node_hash' ) :
				array( 'id', 'blog_id', 'state_id', 'generation', 'item_order', 'node_hash', 'parent_node_hash', 'payload_bytes', 'encoded_bytes' );
			if ( false !== strpos( $sql, 'AS window_bytes' ) ) { $fields = array( 'id', 'item_order', 'window_bytes' ); }
			return array_map( function ( $row ) use ( $fields, $sql ) {
				if ( in_array( 'window_bytes', $fields, true ) ) {
					preg_match_all( '/OCTET_LENGTH\(`?(\w+)`?\)/', $sql, $columns ); $row['window_bytes'] = 0;
					foreach ( $columns[1] as $column ) { $row['window_bytes'] += isset( $row[$column] ) ? strlen( $row[$column] ) : 0; }
				}
				$projected = array(); foreach ( $fields as $field ) { $projected[$field] = $row[$field]; } return $projected;
			}, $rows );
		}
		array_pop( $this->queries ); return parent::get_results( $sql, $format );
	}
}

class OwningMetadataWpdb extends MaintenanceAdmissionWpdb {
	public $corrupt_digest = false;
	public $dirty_digest = false;
	public function get_results( $sql, $format ) {
		$rows = parent::get_results( $sql, $format );
		if ( false !== strpos( $sql, 'AS snapshot_digest' ) ) {
			if ( $this->corrupt_digest ) { $rows[0]['snapshot_digest'] = str_repeat( '0', 64 ); }
			if ( $this->dirty_digest ) { $rows[0]['digest_status'] = 'dirty'; }
		}
		return $rows;
	}
}
