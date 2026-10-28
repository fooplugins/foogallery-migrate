<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\StorageControl;
use FooPlugins\FooGalleryMigrate\StorageConversion;
use FooPlugins\FooGalleryMigrate\StorageMaintenanceGate;
use FooPlugins\FooGalleryMigrate\StorageScalarObserver;
use FooPlugins\FooGalleryMigrate\StorageOperations;
use FooPlugins\FooGalleryMigrate\StoragePreflight;
use FooPlugins\FooGalleryMigrate\StorageScaleAdmission;
use FooPlugins\FooGalleryMigrate\StorageRetirement;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/StorageCoordinatorScaleTest.php';

/** Real public recovery and snapshot preflight over query/gate spies, NOT native SQL. */
class StorageRefusalDiagnosticTest extends TestCase {
	private $saved = array();
	private $observer = array();
	private $instances;
	private $db;
	private $gate;

	protected function setUp(): void {
		foreach ( array( 'wpdb', 'foogallery_migrate_test_blog_id', 'foogallery_migrate_test_capabilities', 'foogallery_migrate_test_nonce' ) as $name ) {
			$this->saved[$name] = array( array_key_exists( $name, $GLOBALS ), isset( $GLOBALS[$name] ) ? $GLOBALS[$name] : null );
		}
		foreach ( array( 'milestones_enabled', 'milestones_valid', 'milestones', 'refusal_authorized', 'refusal' ) as $name ) {
			$p = new \ReflectionProperty( StorageScalarObserver::class, $name ); $this->observer[$name] = $p->getValue();
		}
		$GLOBALS['foogallery_migrate_test_blog_id'] = 7;
		$GLOBALS['foogallery_migrate_test_capabilities'] = array( 'manage_options' => true );
		$GLOBALS['foogallery_migrate_test_nonce'] = array( 'action' => 'foogallery_migrate_storage_recover_7', 'value' => 'valid', 'result' => 1 );
		$this->db = new RefusalDiagnosticConnection(); $GLOBALS['wpdb'] = $this->db;
		$this->gate = new CoordinatorSourceGate( $this->db );
		$p = new \ReflectionProperty( StorageMaintenanceGate::class, 'instances' ); $this->instances = $p->getValue();
		$instances = $this->instances; $instances[spl_object_hash( $this->db ) . ':7:wp_'] = $this->gate; $p->setValue( null, $instances );
	}
	protected function tearDown(): void {
		foreach ( $this->saved as $name => $saved ) { if ( $saved[0] ) { $GLOBALS[$name] = $saved[1]; } else { unset( $GLOBALS[$name] ); } }
		foreach ( $this->observer as $name => $value ) { ( new \ReflectionProperty( StorageScalarObserver::class, $name ) )->setValue( null, $value ); }
		( new \ReflectionProperty( StorageMaintenanceGate::class, 'instances' ) )->setValue( null, $this->instances );
	}
	private function request() { return array( 'blog_id' => 7, 'scope' => 'site', 'target' => 'table', 'confirmation' => 'recover-storage', 'nonce' => 'valid' ); }
	private function recover( $scaled = false ) {
		// Genuine raw read violation, independent of the corrected derived domain.
		$policy = array( 'max_raw_bytes' => strlen( $this->db->raw ) - 1, 'amplification' => 1, 'reserve_bytes' => 1 );
		$scale = $scaled ? new StorageScaleAdmission( StorageScaleAdmissionTest::policy(), $policy, microtime( true ) + 10.0 ) : null;
		return ( new StorageConversion( $policy, null, $scale ) )->recover( $this->request() );
	}
	private function arm() { ( new \ReflectionProperty( StorageScalarObserver::class, 'milestones_enabled' ) )->setValue( null, true ); }
	private function refusal() { return ( new \ReflectionProperty( StorageScalarObserver::class, 'refusal' ) )->getValue(); }
	private function authorize() { $this->assertTrue( StorageConversion::authorize_request( $this->request(), true ) ); }
	/** @dataProvider domains */
	public function test_raw_above_unchanged_raw_cap_refuses_with_fixed_origin_before_masking( $scaled ): void {
		( new \ReflectionProperty( StorageScalarObserver::class, 'milestones_enabled' ) )->setValue( null, true );
		$source = $this->db->raw; $control = $this->db->control;
		$this->assertGreaterThan( strlen( $source ), $this->db->encoded_bytes );
		$result = $this->recover( $scaled );
		$this->assertSame( 'foogallery_migrate_storage_recovery_required', $result->get_error_code() );
		$this->assertSame( 'Migration storage conversion was refused. Retain the recorded authority and all storage evidence; no fallback or expired-owner takeover was attempted.', $result->get_error_message() );
		$this->assertSame( $source, $this->db->raw ); $this->assertSame( $control, $this->db->control );
		$this->assertSame( 0, $this->db->writes ); $this->assertTrue( $this->gate->released );
		$this->assertNotContains( 'source-decoded', array_column( ( new \ReflectionProperty( StorageScalarObserver::class, 'milestones' ) )->getValue(), 'name' ) );
		$names = array_column( ( new \ReflectionProperty( StorageScalarObserver::class, 'milestones' ) )->getValue(), 'name' );
		foreach ( array( 'snapshot-preflight', 'snapshot-staged', 'control-flipped', 'retained-backup' ) as $name ) { $this->assertNotContains( $name, $names ); }
		$this->assertNotContains( 'START TRANSACTION', $this->db->queries );
		// Real raw reads remain denied before payload fetch/decoding.
		$this->assertSame( array( 'reason' => 'raw-budget', 'phase' => 'conversion-budget' ),
			( new \ReflectionProperty( StorageScalarObserver::class, 'refusal' ) )->getValue() );
	}
	public static function domains(): array { return array( 'unscaled' => array( false ), 'operation-owned-scale' => array( true ) ); }

	public function test_default_off_keeps_same_refusal_without_any_diagnostic_or_public_reader(): void {
		$this->assertFalse( $this->observer['milestones_enabled'] );
		$this->assertNull( $this->refusal() );
		$this->assertSame( 'foogallery_migrate_storage_recovery_required', $this->recover()->get_error_code() );
		$this->assertNull( $this->refusal() );
		$this->assertTrue( ( new \ReflectionProperty( StorageScalarObserver::class, 'refusal' ) )->isPrivate() );
		$this->assertSame( 0, $this->db->writes );
	}

	/** @dataProvider denials */
	public function test_denied_requests_cannot_enable_or_retain_or_read_diagnostics( $key, $value, $suffix ): void {
		$this->arm(); $this->recover(); $this->assertNotNull( $this->refusal() );
		$r = $this->request();
		if ( 'capability' === $key ) { $GLOBALS['foogallery_migrate_test_capabilities']['manage_options'] = false; }
		else { $r[$key] = $value; }
		$result = ( new StorageOperations() )->dispatch( 'recover', $r );
		$this->assertSame( 'foogallery_migrate_storage_' . $suffix, $result->get_error_code() );
		$this->assertNull( $this->refusal() );
		StorageScalarObserver::refusal( 'raw-budget', 'conversion-budget' );
		$this->assertNull( $this->refusal() );
		$this->assertFalse( ( new \ReflectionProperty( StorageScalarObserver::class, 'refusal_authorized' ) )->getValue() );
		$this->assertSame( 0, $this->db->writes );
		$this->assertStringNotContainsString( 'private-', $result->get_error_message() );
	}
	public static function denials(): array {
		return array( array( 'capability', false, 'forbidden' ), array( 'nonce', 'bad', 'invalid_nonce' ),
			array( 'confirmation', 'bad', 'invalid_request' ), array( 'blog_id', 8, 'invalid_request' ),
			array( 'scope', 'network', 'invalid_request' ), array( 'diagnostics', true, 'invalid_request' ),
			array( 'target', 'private-target', 'invalid_request' ) );
	}

	public function test_unauthorized_http_receiver_has_only_existing_public_error_fields(): void {
		$this->arm(); $this->recover(); $this->assertNotNull( $this->refusal() );
		$GLOBALS['foogallery_migrate_test_capabilities']['manage_options'] = false;
		try { StorageOperations::handle(); $this->fail( 'Expected terminal JSON response.' ); }
		catch ( \FooGalleryMigrateTestJsonResponse $response ) {
			$this->assertSame( 403, $response->status_code );
			$this->assertSame( array( 'code', 'message' ), array_keys( $response->data ) );
			$this->assertSame( 'foogallery_migrate_storage_forbidden', $response->data['code'] );
			$this->assertStringNotContainsString( 'private-', json_encode( $response->data ) );
		}
		$this->assertNull( $this->refusal() );
	}

	public function test_closed_scalar_pairs_ignore_invalid_data_and_preserve_only_first_origin(): void {
		$this->arm(); $this->authorize();
		foreach ( array( '/private/path', str_repeat( 'a', 64 ), "private-payload\0\xff", array( 'key' => 'private' ), new \RuntimeException( 'private-exception' ), 123, null ) as $bad ) {
			StorageScalarObserver::refusal( $bad, 'conversion-budget' );
			StorageScalarObserver::refusal( 'raw-budget', $bad );
			$this->assertNull( $this->refusal() );
		}
		StorageScalarObserver::refusal( 'raw-budget', 'scale-tick' ); $this->assertNull( $this->refusal() );
		StorageScalarObserver::refusal( 'raw-budget', 'conversion-budget' );
		for ( $i = 0; $i < 300; ++$i ) { StorageScalarObserver::refusal( 'scale-memory', 'scale-memory' ); }
		$this->assertSame( array( 'reason' => 'raw-budget', 'phase' => 'conversion-budget' ), $this->refusal() );
		$this->assertSame( array( 'reason', 'phase' ), array_keys( $this->refusal() ) );
	}

	public function test_exact_preflight_branches_distinguish_raw_from_memory_without_leaking_inputs(): void {
		$this->arm(); $p = array( 'max_raw_bytes' => 10, 'amplification' => 2, 'reserve_bytes' => 1 );
		foreach ( array( array( 11, '-1', 0, 'raw-budget' ), array( 11, '1', 1, 'raw-budget' ),
			array( 1, '1', 0, 'decode-memory' ), array( 0, '1', 1, 'decode-memory' ),
			array( 1, '2', 0, 'decode-memory' ) ) as $case ) {
			$this->authorize();
			$this->assertSame( 'foogallery_migrate_storage_decode_refused', StoragePreflight::decode_budget( $case[0], $case[1], $case[2], $p, 'conversion-budget' )->get_error_code() );
			$this->assertSame( array( 'reason' => $case[3], 'phase' => 'conversion-budget' ), $this->refusal() );
		}
		$this->authorize();
		$this->assertSame( 'foogallery_migrate_storage_memory_unknown', StoragePreflight::decode_budget( 11, 'unknown', 0, $p, 'conversion-budget' )->get_error_code() );
		$this->assertNull( $this->refusal() ); // Do not guess raw-budget before the input guard.
	}

	public function test_actual_scale_work_deadline_and_memory_origins_are_distinct(): void {
		$this->arm();
		foreach ( array( 'scale-work', 'scale-deadline', 'scale-memory' ) as $reason ) {
			$this->authorize(); $now = 100.0;
			$b = StorageScaleAdmissionTest::admission( null, function () use ( &$now ) { return $now; } );
			if ( 'scale-work' === $reason ) { ( new \ReflectionProperty( $b, 'work' ) )->setValue( $b, 16777216 ); }
			if ( 'scale-deadline' === $reason ) { $now = 111.0; }
			try {
				if ( 'scale-memory' === $reason ) { $b->admit_additional( 2147483647 ); } else { $b->tick(); }
				$this->fail( 'Missing actual scale refusal.' );
			} catch ( \RuntimeException $error ) { $this->assertNotEmpty( $error->getMessage() ); }
			$this->assertSame( array( 'reason' => $reason, 'phase' => 'scale-memory' === $reason ? 'scale-memory' : 'scale-tick' ), $this->refusal() );
		}
	}

	public function test_each_scale_memory_guard_records_only_its_fixed_origin(): void {
		$this->arm();
		foreach ( array( 'admit_memory', 'admit_additional', 'admit_snapshot_stats_workspace', 'open_retaining_phase' ) as $guard ) {
			$this->authorize(); $limits = StorageScaleAdmissionTest::policy(); $limits['memory_ceiling_bytes'] = 33554433;
			$b = StorageScaleAdmissionTest::admission( $limits, null, function () { return array( '-1', 1 ); } );
			try {
				if ( 'open_retaining_phase' === $guard ) { $b->open_retaining_phase( new \stdClass(), 1 ); }
				else { $b->$guard( 1 ); }
				$this->fail( 'Missing actual memory guard: ' . $guard );
			} catch ( \RuntimeException $error ) { $this->assertNotEmpty( $error->getMessage() ); }
			$this->assertSame( array( 'reason' => 'scale-memory', 'phase' => 'scale-memory' ), $this->refusal(), $guard );
		}
	}

	public function test_retained_physical_census_origins_do_not_masquerade_as_memory(): void {
		$this->arm();
		foreach ( array( 'per-table', 'aggregate', 'bytes', 'backup' ) as $boundary ) {
			$this->authorize();
			$p = StorageScaleAdmissionTest::policy(); $p['max_physical_rows_per_table'] = 3; $p['max_physical_rows'] = 4;
			// Admit the manifest workspace first, so the intended census guard is reachable.
			$p['max_physical_bytes'] = in_array( $boundary, array( 'bytes', 'backup' ), true ) ? 1024 : 8192;
			$scale = StorageScaleAdmissionTest::admission( $p ); $db = new RefusalDiagnosticCensusConnection();
			$db->counts = 'per-table' === $boundary ? array( 4 ) : array( 3, 2 );
			if ( 'bytes' === $boundary ) { $db->counts = array( 1, 1 ); $db->bytes = 600; }
			if ( 'backup' === $boundary ) { $db->counts = array( 1, 1 ); $db->backup_bytes = 1023; }
			$retirement = new StorageRetirement( $db, 'wp_', 7, StorageScaleAdmissionTest::decode_policy(), $scale->deadline(), null, $scale );
			try { $retirement->capture(); $this->fail( 'Census guard missing.' ); }
			catch ( \RuntimeException $error ) { $this->assertNotEmpty( $error->getMessage() ); }
			$this->assertSame( array( 'reason' => 'physical-census', 'phase' => 'retained-census' ), $this->refusal(), $boundary . ': ' . $error->getMessage() );
		}
	}

}

class RefusalDiagnosticCensusConnection extends CoordinatorCensusConnection {
	private $current_count = 0;
	public $backup_bytes = 0;
	public function get_results( $sql, $format ) {
		if ( $this->backup_bytes && 0 === strpos( $sql, 'SELECT option_id' ) ) {
			$this->queries[] = $sql; return array( array( 'option_id' => '1', 'bytes' => (string) $this->backup_bytes, 'digest' => str_repeat( 'a', 64 ) ) );
		}
		if ( 0 === strpos( $sql, 'SELECT id, (' ) ) {
			$this->queries[] = $sql; $rows = array();
			for ( $i = 1; $i <= $this->current_count; ++$i ) { $rows[] = array( 'id' => (string) $i, 'bytes' => (string) $this->bytes, 'digest' => str_repeat( 'a', 64 ) ); }
			return $rows;
		}
		$rows = parent::get_results( $sql, $format );
		if ( false !== strpos( $sql, 'COUNT(*)' ) ) { $this->current_count = (int) $rows[0]['n']; }
		return $rows;
	}
}

class RefusalDiagnosticConnection extends CoordinatorSourceConnection {
	public $control;
	public $encoded_bytes;
	public function __construct() {
		$value = array( 'private-key' => str_repeat( 'private-payload', 3 ) ); $this->raw = serialize( $value );
		$codec = new LegacyStateCodec();
		$this->encoded_bytes = strlen( $codec->key_value( 'private-key' ) ) + strlen( $codec->encode( array( 'version' => 1, 'kind' => 'scalar', 'value' => $value['private-key'] ) ) );
		$this->control = json_encode( array( 'version' => 2, 'blog_id' => 7, 'backend' => 'option', 'epoch' => 0,
			'switch_id' => str_repeat( 'a', 32 ), 'from' => 'option', 'to' => 'table', 'phase' => 'copying', 'table_id' => str_repeat( 'a', 32 ),
			'source_digest' => hash( 'sha256', 'present:' . hash( 'sha256', $this->raw ) ), 'logical_digest' => $codec->snapshot_digest( $value ),
			'schema' => 1, 'codec' => 1, 'created' => 1, 'updated' => 1, 'retained_digest' => null ) );
		StorageControl::decode( $this->control, 7 );
	}
	public function get_var( $sql ) {
		if ( false !== strpos( $sql, "option_name = '" . StorageAuthority::CONTROL_OPTION . "'" ) ) { $this->queries[] = $sql; return 'v:' . $this->control; }
		return parent::get_var( $sql );
	}
}
