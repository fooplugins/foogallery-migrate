<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\StorageCalibration;
use FooPlugins\FooGalleryMigrate\StorageOperations;
use FooPlugins\FooGalleryMigrate\StorageConversion;
use FooPlugins\FooGalleryMigrate\StorageScaleAdmission;
use FooPlugins\FooGalleryMigrate\StorageRetirement;
use FooPlugins\FooGalleryMigrate\StorageMemoryBaseline;
use FooPlugins\FooGalleryMigrate\StorageMaintenanceGate;
use FooPlugins\FooGalleryMigrate\StorageScalarObserver;
use FooPlugins\FooGalleryMigrate\LegacyOptionDecoder;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/StorageSnapshotMaintenanceAdmissionTest.php';
require_once __DIR__ . '/StorageScaleAdmissionTest.php';
require_once __DIR__ . '/support/StorageA7OptionsFixture.php';

/** Coordinator query spies/reflection, not native SQL or calibration approval. */
class StorageCoordinatorScaleTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['wpdb'] = new CoordinatorCaptureWpdb();
		$GLOBALS['wp_version'] = '6.9-test';
		$GLOBALS['foogallery_migrate_test_blog_id'] = 7;
		$GLOBALS['foogallery_migrate_test_capabilities'] = array( 'manage_options' => true );
		$GLOBALS['foogallery_migrate_test_nonce'] = array( 'action' => 'foogallery_migrate_storage_option_import_7', 'value' => 'valid', 'result' => 1 );
	}
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['wp_version'], $GLOBALS['foogallery_migrate_test_blog_id'], $GLOBALS['foogallery_migrate_test_capabilities'], $GLOBALS['foogallery_migrate_test_nonce'] );
	}
	private function request() { return array( 'blog_id' => 7, 'scope' => 'site', 'target' => 'table', 'confirmation' => 'switch-to-table', 'nonce' => 'valid' ); }
	private function profile() {
		// Test routing ONLY. No measured or production approval is asserted.
		return array( 'version' => 1, 'status' => 'approved', 'fingerprint' => StorageCalibration::fingerprint(),
			'evidence_sha256' => str_repeat( 'a', 64 ), 'policy' => StorageScaleAdmissionTest::decode_policy() );
	}
	private function get( $object, $name ) { return ( new \ReflectionProperty( $object, $name ) )->getValue( $object ); }
	private function set( $object, $name, $value ) { ( new \ReflectionProperty( $object, $name ) )->setValue( $object, $value ); }
	private function invoke( $object, $method, ...$args ) { return ( new \ReflectionMethod( $object, $method ) )->invokeArgs( $object, $args ); }
	private function captured( $receiver = null ) {
		$db = $GLOBALS['wpdb']; $db->manager = null;
		$result = ( null === $receiver ? new StorageOperations( $this->profile() ) : $receiver )->dispatch( 'switch', $this->request() );
		$this->assertSame( 'foogallery_migrate_storage_recovery_required', $result->get_error_code() );
		$this->assertInstanceOf( StorageConversion::class, $db->manager );
		return $db->manager;
	}
	public function test_public_gate_constructs_one_fresh_domain_only_after_trusted_calibration(): void {
		$receiver = new StorageOperations( $this->profile() );
		$first = $this->captured( $receiver ); $second = $this->captured( $receiver );
		$a = $this->get( $first, 'scale' ); $b = $this->get( $second, 'scale' );
		$this->assertInstanceOf( StorageScaleAdmission::class, $a ); $this->assertNotSame( $a, $b );
		$this->assertSame( $a->deadline(), $this->get( $first, 'deadline' ) );
		$this->assertTrue( $this->get( $first, 'codec' )->uses_storage_scale( $a ) );
		$this->assertSame( $this->get( $first, 'memory' ), $this->get( $a, 'baseline' ) );
		$this->assertNull( $this->get( $a, 'clock' ) ); $this->assertNull( $this->get( $a, 'memory' ) );
		$this->assertSame( 24576, $a->limit( 'max_rows' ) );
		$this->assertGreaterThan( 0, $this->get( $a, 'work' ) );
		// Explicit old profiles remain private diagnostic fixtures and require identity.
		foreach ( array( array_merge( $this->profile(), array( 'status' => 'measurement_only' ) ), array_merge( $this->profile(), array( 'fingerprint' => str_repeat( '0', 64 ) ) ) ) as $profile ) {
			$GLOBALS['wpdb']->manager = null;
			$this->assertSame( 'foogallery_migrate_storage_calibration_required', ( new StorageOperations( $profile ) )->dispatch( 'switch', $this->request() )->get_error_code() );
			$this->assertNull( $GLOBALS['wpdb']->manager );
		}
		$previous_limit = ini_get( 'memory_limit' );
		try {
			ini_set( 'memory_limit', '128M' );
			$GLOBALS['wpdb']->manager = null;
			$this->assertSame( 'foogallery_migrate_storage_decode_refused', ( new StorageOperations() )->dispatch( 'switch', $this->request() )->get_error_code() );
			$this->assertNull( $GLOBALS['wpdb']->manager ); // Low memory refuses before the SQL spy.
		} finally { ini_set( 'memory_limit', $previous_limit ); }
		$r = $this->request(); $r['scale'] = StorageScaleAdmissionTest::policy();
		$this->assertSame( 'foogallery_migrate_storage_invalid_request', $receiver->dispatch( 'switch', $r )->get_error_code() );
	}
	public function test_real_public_dispatch_reaches_source_decoded_before_any_authority_write(): void {
		$db = new CoordinatorSourceConnection(); $db->raw = serialize( StorageA7OptionsFixture::value() ); $GLOBALS['wpdb'] = $db;
		$gate = new CoordinatorSourceGate( $db ); $property = new \ReflectionProperty( StorageMaintenanceGate::class, 'instances' );
		$old = $property->getValue(); $key = spl_object_hash( $db ) . ':7:wp_'; $instances = $old; $instances[$key] = $gate; $property->setValue( null, $instances );
		$enabled = new \ReflectionProperty( StorageScalarObserver::class, 'milestones_enabled' );
		$records = new \ReflectionProperty( StorageScalarObserver::class, 'milestones' );
		$previous = $records->getValue(); $was_enabled = $enabled->getValue(); $enabled->setValue( null, true ); $records->setValue( null, array() );
		try {
			$result = ( new StorageOperations( $this->profile() ) )->dispatch( 'switch', $this->request() );
			$this->assertSame( 'foogallery_migrate_storage_recovery_required', $result->get_error_code() );
			$names = array_column( $records->getValue(), 'name' );
			$this->assertContains( 'source-decoded', $names ); $this->assertContains( 'source-verified', $names );
			$this->assertSame( 0, $db->writes ); $this->assertTrue( $gate->released );
		} finally { $property->setValue( null, $old ); $enabled->setValue( null, $was_enabled ); $records->setValue( null, $previous ); }
	}
	public function test_coordinator_decodes_and_encodes_20k_on_the_same_domain_without_raising_defaults(): void {
		$manager = $this->captured(); $scale = $this->get( $manager, 'scale' );
		$value = StorageA7OptionsFixture::value(); $raw = serialize( $value );
		try { ( new LegacyOptionDecoder() )->decode( $raw ); $this->fail( 'Unscaled decoder changed.' ); }
		catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'Unsupported', $error->getMessage() ); }
		$decoder = $this->invoke( $manager, 'option_decoder' );
		$this->assertSame( $scale, $this->get( $decoder, 'scale' ) );
		$before = $this->get( $scale, 'work' );
		$this->assertSame( $raw, serialize( $decoder->decode( $raw ) ) );
		$middle = $this->get( $scale, 'work' ); $this->assertGreaterThan( $before, $middle );
		$this->assertSame( $raw, $decoder->encode( $value, function ( $bytes ) use ( $manager ) { $this->invoke( $manager, 'budget', $bytes ); } ) );
		$this->assertGreaterThan( $middle, $this->get( $scale, 'work' ) );
		$this->assertSame( StorageA7OptionsFixture::LOGICAL_SHA256, $this->get( $manager, 'codec' )->snapshot_digest( $value ) );
		foreach ( array( 'O:8:"stdClass":0:{}', 'a:1:{i:0;R:1;}' ) as $bad ) {
			try { $decoder->decode( $bad ); $this->fail( 'Unsafe token admitted.' ); }
			catch ( \RuntimeException $error ) { $this->assertNotEmpty( $error->getMessage() ); }
		}
	}
	public function test_snapshot_codec_rows_heads_and_deadline_share_the_operation_domain(): void {
		$manager = $this->captured(); $scale = $this->get( $manager, 'scale' );
		$db = new MaintenanceAdmissionWpdb(); $GLOBALS['wpdb'] = $db;
		$this->set( $manager, 'db', $db ); $this->set( $manager, 'connection', $db ); $this->set( $manager, 'gate', new MaintenanceAdmissionGate() );
		$this->invoke( $manager, 'storage' ); $snapshot = $this->get( $manager, 'snapshot' );
		$this->assertSame( $scale, $this->get( $snapshot, 'scale' ) );
		$this->assertTrue( $this->get( $snapshot, 'codec' )->uses_storage_scale( $scale ) );
		$this->assertSame( $scale->deadline(), $this->get( $snapshot, 'deadline' ) );
		$this->assertSame( 24576, $this->get( $snapshot, 'max_rows' ) );
		$db->head['record_count'] = 20008;
		$this->assertCount( 1, $this->invoke( $manager, 'heads' ) );
		$db->head['record_count'] = 24577;
		try { $this->invoke( $manager, 'heads' ); $this->fail( 'Aggregate row boundary bypassed.' ); }
		catch ( \RuntimeException $error ) { $this->assertFalse( $db->payload_fetched ); }
		$this->set( $scale, 'work', 16777216 );
		try { $this->invoke( $manager, 'option_decoder' )->decode( 'a:0:{}' ); $this->fail( 'Work reset.' ); }
		catch ( \RuntimeException $error ) { $this->assertGreaterThan( 16777216, $this->get( $scale, 'work' ) ); }
	}
	public function test_physical_retained_census_counts_all_generations_and_aggregate_bytes(): void {
		foreach ( array( 'per-table', 'aggregate', 'bytes' ) as $boundary ) {
			$p = StorageScaleAdmissionTest::policy(); $p['max_physical_rows_per_table'] = 3; $p['max_physical_rows'] = 4; $p['max_physical_bytes'] = 100;
			$scale = StorageScaleAdmissionTest::admission( $p ); $db = new CoordinatorCensusConnection();
			$db->counts = 'per-table' === $boundary ? array( 4 ) : array( 3, 2 );
			if ( 'bytes' === $boundary ) { $db->counts = array( 1, 1 ); $db->bytes = 51; }
			$retirement = new StorageRetirement( $db, 'wp_', 7, StorageScaleAdmissionTest::decode_policy(), $scale->deadline(), null, $scale );
			try { $retirement->capture(); $this->fail( 'Retained ' . $boundary . ' bypassed.' ); }
			catch ( \RuntimeException $error ) { $this->assertNotEmpty( $error->getMessage() ); }
			$this->assertStringNotContainsString( 'active_generation', implode( '\n', $db->queries ) );
			$this->assertStringContainsString( 'blog_id = 7', implode( '\n', $db->queries ) );
		}
	}
	public function test_native_memory_keeps_receiver_high_water_and_fingerprint_binds_models(): void {
		$baseline = new CoordinatorHighWater();
		$scale = new StorageScaleAdmission( StorageScaleAdmissionTest::policy(), StorageScaleAdmissionTest::decode_policy(), microtime( true ) + 10.0, null, null, $baseline );
		$before = $this->get( $scale, 'native_high_water' ); $baseline->value = 1; $scale->admit_memory( 0 );
		$this->assertGreaterThanOrEqual( $before, $this->get( $scale, 'native_high_water' ) );
		$this->assertGreaterThanOrEqual( memory_get_usage( true ), $this->get( $scale, 'native_high_water' ) );
		$identity = StorageCalibration::identity();
		foreach ( array( 'storage-scale-admission', 'generic-retaining-model', 'repository-flatten-model', 'snapshot-stats-model' ) as $name ) {
			$this->assertSame( hash_file( 'sha256', dirname( __DIR__ ) . '/includes/class-' . $name . '.php' ), $identity[$name] );
		}
	}
}
class CoordinatorCaptureWpdb extends SnapshotTestWpdb {
	public $prefix = 'wp_'; public $base_prefix = 'wp_'; public $manager;
	public function is_current() { return true; }
	public function prepare( $sql, $args ) {
		foreach ( debug_backtrace( DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS, 16 ) as $frame ) {
			if ( isset( $frame['object'] ) && $frame['object'] instanceof StorageConversion ) { $this->manager = $frame['object']; }
		}
		throw new \RuntimeException( 'Query spy stops before SQL; no native product success.' );
	}
}
class CoordinatorCensusConnection extends SnapshotTestWpdb {
	public $prefix = 'wp_'; public $base_prefix = 'wp_'; public $last_error = ''; public $queries = array(); public $counts = array(); public $bytes = 1;
	public function __construct() {}
	public function is_current() { return true; }
	public function prepare( $sql, $args ) { return str_replace( '%d', '7', $sql ); }
	public function get_results( $sql, $format ) {
		if ( 0 === strpos( $sql, 'SHOW ' ) ) { return parent::get_results( $sql, $format ); }
		$this->queries[] = $sql;
		if ( false !== strpos( $sql, 'COUNT(*)' ) ) { return array( array( 'n' => array_shift( $this->counts ) ) ); }
		return array( array( 'id' => '1', 'bytes' => (string) $this->bytes, 'digest' => str_repeat( 'a', 64 ) ) );
	}
}
class CoordinatorSourceGate extends MaintenanceAdmissionGate {
	private $db; public $released = false;
	public function __construct( $db ) { $this->db = $db; }
	public function connection() { return $this->db; }
	public function acquire_maintenance() { return true; }
	public function release_maintenance() { $this->released = true; return true; }
	public function maintenance_identity() { throw new \RuntimeException( 'Stop before authority mutation.' ); }
}
class CoordinatorSourceConnection extends SnapshotTestWpdb {
	public $prefix = 'wp_'; public $raw; public $writes = 0;
	public function is_current() { return true; }
	public function query( $sql ) { ++$this->writes; throw new \RuntimeException( 'No writes in this source-decoded spy.' ); }
	public function get_var( $sql ) {
		$this->queries[] = $sql;
		if ( false === strpos( $sql, "option_name = '" . FOOGALLERY_MIGRATE_OPTION_DATA . "'" ) ) { return null; }
		if ( 0 === strpos( $sql, 'SELECT SHA2(' ) ) { return hash( 'sha256', $this->raw ); }
		if ( 0 === strpos( $sql, 'SELECT LENGTH(' ) ) { return (string) strlen( $this->raw ); }
		if ( 0 === strpos( $sql, "SELECT CONCAT('v:'" ) ) { return 'v:' . $this->raw; }
		return null;
	}
}
class CoordinatorHighWater extends StorageMemoryBaseline {
	public $value = 67108864;
	public function sample() { return $this->value; }
}
