<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use FooPlugins\FooGalleryMigrate\LegacyTableSnapshot;
use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\StorageConversion;
use FooPlugins\FooGalleryMigrate\StorageMaintenanceGate;
use FooPlugins\FooGalleryMigrate\StorageMemoryBaseline;
use FooPlugins\FooGalleryMigrate\StorageScalarObserver;
use FooPlugins\FooGalleryMigrate\StorageScaleAdmission;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/StorageRefusalDiagnosticTest.php';

/** Host accounting contracts, not native persistence or completed conversion. */
class StorageByteDomainAccountingTest extends TestCase {
	private $saved = array();
	private $observer = array();
	private $instances;
	private $db;
	private $gate;
	private $memory_limit;

	protected function setUp(): void {
		foreach ( array( 'wpdb', 'foogallery_migrate_test_blog_id', 'foogallery_migrate_test_capabilities', 'foogallery_migrate_test_nonce' ) as $name ) {
			$this->saved[$name] = array( array_key_exists( $name, $GLOBALS ), isset( $GLOBALS[$name] ) ? $GLOBALS[$name] : null );
		}
		foreach ( array( 'milestones_enabled', 'milestones_valid', 'milestones', 'refusal_authorized', 'refusal' ) as $name ) { $this->observer[$name] = $this->get( StorageScalarObserver::class, $name ); }
		$this->memory_limit = ini_get( 'memory_limit' );
		$GLOBALS['foogallery_migrate_test_blog_id'] = 7;
		$GLOBALS['foogallery_migrate_test_capabilities'] = array( 'manage_options' => true );
		$GLOBALS['foogallery_migrate_test_nonce'] = array( 'action' => 'foogallery_migrate_storage_recover_7', 'value' => 'valid', 'result' => 1 );
		$this->db = new ByteDomainConnection(); $GLOBALS['wpdb'] = $this->db;
		$this->gate = new CoordinatorSourceGate( $this->db );
		$this->instances = $this->get( StorageMaintenanceGate::class, 'instances' );
		$instances = $this->instances; $instances[spl_object_hash( $this->db ) . ':7:wp_'] = $this->gate;
		$this->set( StorageMaintenanceGate::class, 'instances', $instances );
	}
	protected function tearDown(): void {
		ini_set( 'memory_limit', $this->memory_limit );
		foreach ( $this->saved as $name => $saved ) { if ( $saved[0] ) { $GLOBALS[$name] = $saved[1]; } else { unset( $GLOBALS[$name] ); } }
		foreach ( $this->observer as $name => $value ) { $this->set( StorageScalarObserver::class, $name, $value ); }
		$this->set( StorageMaintenanceGate::class, 'instances', $this->instances );
	}
	private function get( $object, $name ) { return ( new \ReflectionProperty( $object, $name ) )->getValue( is_object( $object ) ? $object : null ); }
	private function set( $object, $name, $value ) { ( new \ReflectionProperty( $object, $name ) )->setValue( is_object( $object ) ? $object : null, $value ); }
	private function invoke( $object, $method, ...$args ) { return ( new \ReflectionMethod( $object, $method ) )->invokeArgs( $object, $args ); }
	private function names() { return array_column( $this->get( StorageScalarObserver::class, 'milestones' ), 'name' ); }
	private function arm( $enabled = true ) { $this->set( StorageScalarObserver::class, 'milestones_enabled', $enabled ); }
	private function request() { return array( 'blog_id' => 7, 'scope' => 'site', 'target' => 'table', 'confirmation' => 'recover-storage', 'nonce' => 'valid' ); }
	private function policy( $delta = 0 ) { return array( 'max_raw_bytes' => strlen( $this->db->raw ) + $delta, 'amplification' => 1, 'reserve_bytes' => 1 ); }
	private function manager( $scaled, $policy = null, $limits = null, $memory = null, $scale_memory = null ) {
		$policy = null === $policy ? $this->policy() : $policy;
		$scale = $scaled ? new StorageScaleAdmission( null === $limits ? StorageScaleAdmissionTest::policy() : $limits, $policy, microtime( true ) + 10.0, null, $scale_memory ) : null;
		return new StorageConversion( $policy, $memory, $scale );
	}
	private function retained( $source, $control ) {
		$this->assertSame( $source, $this->db->raw ); $this->assertSame( $control, $this->db->control );
		$this->assertSame( 0, $this->db->writes ); $this->assertTrue( $this->gate->released );
		$this->assertNotContains( 'START TRANSACTION', $this->db->queries );
		foreach ( array( 'snapshot-staged', 'retained-backup', 'control-flipped' ) as $name ) { $this->assertNotContains( $name, $this->names() ); }
	}
	private function recover( $manager ) {
		$source = $this->db->raw; $control = $this->db->control;
		$result = $manager->recover( $this->request() );
		$this->assertSame( 'foogallery_migrate_storage_recovery_required', $result->get_error_code() );
		$this->assertSame( 'Migration storage conversion was refused. Retain the recorded authority and all storage evidence; no fallback or expired-owner takeover was attempted.', $result->get_error_message() );
		$this->retained( $source, $control );
	}
	private function fixture( $structured ) {
		$value = $structured ? array( 'records' => array( array( 'id' => 1, 'name' => 'one' ), array( 'id' => 2, 'name' => 'two' ) ), 'checkpoint' => false ) : array( 'private-key' => str_repeat( 'private-payload', 3 ) );
		$this->db->raw = serialize( $value );
		$r = json_decode( $this->db->control, true ); $codec = new LegacyStateCodec();
		$r['source_digest'] = hash( 'sha256', 'present:' . hash( 'sha256', $this->db->raw ) ); $r['logical_digest'] = $codec->snapshot_digest( $value );
		$this->db->control = json_encode( $r );
		$changes = array(); $order = 0;
		foreach ( $value as $key => $item ) { $changes[] = array( 'key' => $key, 'order' => $order++, 'kind' => is_array( $item ) ? 'list' : 'scalar', 'value' => $item, 'expected_revision' => null ); }
		// Measure the existing representation, never inflate the policy or alter it.
		$prepared = $this->invoke( new LegacyTableSnapshot( $this->db, 7 ), 'preflight', $changes );
		return array( 'bytes' => array_sum( array_column( $prepared, 'encoded_bytes' ) ), 'rows' => array_sum( array_column( array_column( $prepared, 'stats' ), 'record_count' ) ), 'heads' => count( $prepared ) );
	}
	/** @dataProvider positives */
	public function test_raw_at_cap_derived_above_raw_cap_reaches_completed_snapshot_preflight( $scaled, $structured, $observed ): void {
		$stats = $this->fixture( $structured ); $this->arm( $observed ); $policy = $this->policy();
		$this->assertSame( strlen( $this->db->raw ), $policy['max_raw_bytes'] );
		$this->assertGreaterThan( $policy['max_raw_bytes'], $stats['bytes'] );
		$this->assertLessThanOrEqual( StorageScaleAdmissionTest::policy()['max_bytes'], $stats['bytes'] );
		$this->recover( $this->manager( $scaled ) );
		// This read occurs only AFTER real replace_many/preflight returns. Deliberate
		// host stop before transaction/staging: NOT native or completed conversion.
		$this->assertTrue( $this->db->post_preflight_stop );
		$this->assertSame( $observed, in_array( 'snapshot-preflight', $this->names(), true ) );
		$this->assertNull( $this->get( StorageScalarObserver::class, 'refusal' ) );
	}
	public static function positives(): array {
		$out = array(); foreach ( array( false, true ) as $scaled ) { foreach ( array( false, true ) as $structured ) { foreach ( array( false, true ) as $observed ) { $out[] = array( $scaled, $structured, $observed ); } } } return $out;
	}
	/** @dataProvider modes */
	public function test_raw_read_plus_one_is_still_denied_before_payload( $scaled ): void {
		$this->fixture( false ); $this->arm(); $this->recover( $this->manager( $scaled, $this->policy( -1 ) ) );
		$this->assertFalse( $this->db->source_payload_fetched ); $this->assertFalse( $this->db->post_preflight_stop );
		$this->assertSame( array( 'reason' => 'raw-budget', 'phase' => 'conversion-budget' ), $this->get( StorageScalarObserver::class, 'refusal' ) );
	}
	public static function modes(): array { return array( array( false ), array( true ) ); }
	/** @dataProvider derivedBoundaries */
	public function test_derived_exact_and_plus_one_caps_before_any_mutation( $field, $plus_one ): void {
		// Record row encoding first admits its unchanged 1 MiB maximum; scalar
		// heads isolate the exact physical-byte cap from that independent check.
		$stats = $this->fixture( 'max_physical_bytes' !== $field ); $this->arm(); $limits = StorageScaleAdmissionTest::policy();
		$amounts = array( 'max_rows' => $stats['rows'], 'max_bytes' => $stats['bytes'], 'max_heads' => $stats['heads'],
			'max_physical_rows_per_table' => max( $stats['rows'], $stats['heads'] ), 'max_physical_rows' => $stats['rows'] + $stats['heads'], 'max_physical_bytes' => $stats['bytes'] );
		$limits[$field] = $amounts[$field] - (int) $plus_one;
		$this->recover( $this->manager( true, null, $limits ) );
		$this->assertSame( ! $plus_one, $this->db->post_preflight_stop );
		$this->assertSame( ! $plus_one, in_array( 'snapshot-preflight', $this->names(), true ) );
	}
	public static function derivedBoundaries(): array {
		$out = array(); foreach ( array( 'max_rows', 'max_bytes', 'max_heads', 'max_physical_rows_per_table', 'max_physical_rows', 'max_physical_bytes' ) as $field ) { foreach ( array( false, true ) as $plus_one ) { $out[] = array( $field, $plus_one ); } } return $out;
	}
	/** @dataProvider modes */
	public function test_insufficient_derived_memory_keeps_source_control_and_owned_gate( $scaled ): void {
		$this->fixture( false ); $this->arm(); ini_set( 'memory_limit', '128M' );
		$memory = new ByteDomainMemory(); $memory->used = 134217728 - strlen( $this->db->raw ) - 1;
		$this->recover( $this->manager( $scaled, null, null, $memory ) );
		$this->assertContains( 'source-decoded', $this->names() ); $this->assertFalse( $this->db->post_preflight_stop );
		$this->assertSame( array( 'reason' => 'decode-memory', 'phase' => 'conversion-budget' ), $this->get( StorageScalarObserver::class, 'refusal' ) );
	}
	public function test_unlimited_php_still_obeys_finite_derived_ceiling(): void {
		$this->fixture( false ); $this->arm(); ini_set( 'memory_limit', '-1' ); $limits = StorageScaleAdmissionTest::policy();
		$limits['memory_ceiling_bytes'] = 100 + strlen( $this->db->raw ) + 1;
		$this->recover( $this->manager( true, null, $limits, null, function () { return array( '-1', 100 ); } ) );
		$this->assertContains( 'source-decoded', $this->names() ); $this->assertFalse( $this->db->post_preflight_stop );
		$this->assertSame( array( 'reason' => 'scale-memory', 'phase' => 'scale-memory' ), $this->get( StorageScalarObserver::class, 'refusal' ) );
	}
	/** @dataProvider reverseBoundaries */
	public function test_reverse_encode_keeps_exact_raw_cap( $scaled, $plus_one ): void {
		$this->fixture( false ); $this->arm(); $source = $this->db->raw; $control = $this->db->control;
		$manager = $this->manager( $scaled, $this->policy( $plus_one ? -1 : 0 ) );
		$this->invoke( $manager, 'start_memory' ); $this->invoke( $manager, 'begin' );
		StorageConversion::authorize_request( $this->request(), true );
		$r = json_decode( $this->db->control, true ); $r['to'] = 'option';
		try { $this->invoke( $manager, 'advance', $r, array( 'value' => unserialize( $this->db->raw ) ) ); $this->fail( 'Expected host stop before reverse write.' ); }
		catch ( \RuntimeException $error ) { $this->invoke( $manager, 'failed', $error ); }
		$this->assertSame( ! $plus_one, in_array( 'reverse-serialized', $this->names(), true ) );
		$this->retained( $source, $control );
	}
	public static function reverseBoundaries(): array { return array( array( false, false ), array( false, true ), array( true, false ), array( true, true ) ); }
	/** @dataProvider invalidBytes */
	public function test_derived_callback_invalid_or_overflow_is_denied_without_mutation( $bytes, $amplification ): void {
		$this->fixture( false ); $source = $this->db->raw; $control = $this->db->control;
		$policy = $this->policy(); $policy['amplification'] = $amplification;
		$manager = $this->manager( false, $policy );
		$this->invoke( $manager, 'start_memory' ); $this->invoke( $manager, 'begin' ); $this->invoke( $manager, 'storage' );
		$callback = $this->get( $this->get( $manager, 'snapshot' ), 'memory_admission' );
		try { $callback( $bytes ); $this->fail( 'Invalid/overflow derived budget admitted.' ); }
		catch ( \RuntimeException $error ) { $this->invoke( $manager, 'failed', $error ); }
		$this->retained( $source, $control );
	}
	public static function invalidBytes(): array { return array( array( -1, 1 ), array( '1', 1 ), array( 1.5, 1 ), array( INF, 1 ), array( PHP_INT_MAX, 1 ), array( 2, PHP_INT_MAX ) ); }
	/** @dataProvider tableReads */
	public function test_table_read_and_post_maintenance_readback_use_derived_domain( $scaled, $dirty ): void {
		$this->fixture( false ); $source = $this->db->raw; $control = $this->db->control; $manager = $this->manager( $scaled );
		$this->invoke( $manager, 'start_memory' ); $this->invoke( $manager, 'begin' );
		$codec = new LegacyStateCodec();
		$this->db->table_heads = array( array( 'id' => '4', 'key_value' => $codec->key_value( 'private-key' ), 'state_order' => '0', 'state_kind' => 'scalar', 'active_generation' => '1', 'revision' => '1', 'record_count' => '0', 'payload_bytes' => (string) $this->db->encoded_bytes, 'digest_status' => $dirty ? 'dirty' : 'current' ) );
		$reader = new ByteDomainReadProbe(); $this->set( $manager, 'snapshot', $reader );
		$result = $this->invoke( $manager, 'table_snapshot', $dirty );
		$this->assertSame( array( 'private-key' => false ), $result['value'] ); $this->assertSame( 1, $reader->reads );
		$this->assertSame( $dirty ? 1 : 0, $reader->maintenance ); $this->assertGreaterThanOrEqual( $dirty ? 3 : 2, $this->db->head_reads );
		$this->invoke( $manager, 'end' ); $this->retained( $source, $control );
	}
	/** @dataProvider modes */
	public function test_derived_callback_exact_byte_cap_and_plus_one( $scaled ): void {
		$this->fixture( false ); $source = $this->db->raw; $control = $this->db->control; ini_set( 'memory_limit', '-1' );
		$limits = StorageScaleAdmissionTest::policy(); $limits['max_bytes'] = 512;
		$manager = $this->manager( $scaled, null, $limits );
		$this->invoke( $manager, 'start_memory' ); $this->invoke( $manager, 'begin' ); $this->invoke( $manager, 'storage' );
		$callback = $this->get( $this->get( $manager, 'snapshot' ), 'memory_admission' );
		$cap = $scaled ? 512 : 16777216;
		$this->assertTrue( $callback( $cap ) );
		try { $callback( $cap + 1 ); $this->fail( 'Derived cap plus one admitted.' ); }
		catch ( \RuntimeException $error ) { $this->invoke( $manager, 'failed', $error ); }
		$this->retained( $source, $control );
	}
	/** @dataProvider modes */
	public function test_table_memory_refusal_before_read_or_after_maintenance( $after_maintenance ): void {
		$this->fixture( false ); $source = $this->db->raw; $control = $this->db->control; ini_set( 'memory_limit', '128M' );
		$memory = new ByteDomainMemory(); $manager = $this->manager( false, null, null, $memory );
		$this->invoke( $manager, 'start_memory' ); $this->invoke( $manager, 'begin' );
		$codec = new LegacyStateCodec();
		$this->db->table_heads = array( array( 'id' => '4', 'key_value' => $codec->key_value( 'private-key' ), 'state_order' => '0', 'state_kind' => 'scalar', 'active_generation' => '1', 'revision' => '1', 'record_count' => '0', 'payload_bytes' => (string) $this->db->encoded_bytes, 'digest_status' => 'dirty' ) );
		$reader = new ByteDomainReadProbe(); $this->set( $manager, 'snapshot', $reader );
		$low = 134217728 - strlen( $source ) - 1; // raw fits; derived does not.
		if ( $after_maintenance ) { $reader->after_maintenance = function () use ( $memory, $low ) { $memory->used = $low; }; }
		else { $memory->used = $low; }
		try { $this->invoke( $manager, 'table_snapshot', true ); $this->fail( 'Table memory refusal missing.' ); }
		catch ( \RuntimeException $error ) { $this->invoke( $manager, 'failed', $error ); }
		$this->assertSame( $after_maintenance ? 1 : 0, $reader->maintenance ); $this->assertSame( 0, $reader->reads );
		$this->retained( $source, $control );
	}
	/** @dataProvider pendingOrDeadline */
	public function test_derived_callback_retains_pending_owner_and_deadline_checks( $deadline ): void {
		$this->fixture( false ); $source = $this->db->raw; $control = $this->db->control; $this->arm();
		$limits = StorageScaleAdmissionTest::policy(); $limits['memory_ceiling_bytes'] = 1000;
		$manager = $this->manager( true, null, $limits, null, function () { return array( '-1', 0 ); } );
		$this->invoke( $manager, 'start_memory' ); $this->invoke( $manager, 'begin' ); $this->invoke( $manager, 'storage' );
		StorageConversion::authorize_request( $this->request(), true );
		$callback = $this->get( $this->get( $manager, 'snapshot' ), 'memory_admission' ); $scale = $this->get( $manager, 'scale' );
		$this->assertTrue( $callback( 272 ) );
		if ( $deadline ) { $this->set( $scale, 'clock', function () { return microtime( true ) + 20.0; } ); }
		else { $scale->open_retaining_phase( $this, 800 ); }
		try { $callback( 272 ); $this->fail( 'Pending/deadline refusal missing.' ); }
		catch ( \RuntimeException $error ) { $this->invoke( $manager, 'failed', $error ); }
		$this->assertSame( array( 'reason' => $deadline ? 'scale-deadline' : 'scale-memory', 'phase' => $deadline ? 'scale-tick' : 'scale-memory' ), $this->get( StorageScalarObserver::class, 'refusal' ) );
		if ( ! $deadline ) { $this->assertSame( 800, $this->get( $scale, 'retaining_pending' ) ); }
		$this->retained( $source, $control );
	}
	public static function pendingOrDeadline(): array { return array( array( false ), array( true ) ); }
	public static function tableReads(): array { return array( array( false, false ), array( false, true ), array( true, false ), array( true, true ) ); }
}

class ByteDomainConnection extends RefusalDiagnosticConnection {
	public $post_preflight_stop = false;
	public $source_payload_fetched = false;
	public $table_heads = array();
	public $head_reads = 0;
	public function get_var( $sql ) {
		if ( 0 === strpos( $sql, "SELECT CONCAT('v:'" ) && false !== strpos( $sql, FOOGALLERY_MIGRATE_OPTION_DATA ) ) { $this->source_payload_fetched = true; }
		return parent::get_var( $sql );
	}
	public function get_results( $sql, $format ) {
		if ( false !== strpos( $sql, 'ORDER BY state_order LIMIT' ) ) { $this->queries[] = $sql; ++$this->head_reads; return $this->table_heads; }
		if ( 'SELECT @@SESSION.autocommit AS autocommit' === $sql ) {
			$this->queries[] = $sql; $this->post_preflight_stop = true;
			throw new \RuntimeException( 'Intentional host stop AFTER completed snapshot preflight, BEFORE transaction; NOT native persistence/completed conversion.' );
		}
		return parent::get_results( $sql, $format );
	}
}
class ByteDomainMemory extends StorageMemoryBaseline {
	public $used = 0;
	public function sample() { return $this->used; }
}
/** Reader spy isolates coordinator's two table admission sites, not persistence. */
class ByteDomainReadProbe {
	public $reads = 0;
	public $maintenance = 0;
	public $after_maintenance;
	public function read_bounded( $key ) { ++$this->reads; return false; }
	public function verify_digest( $key, $revision, $seconds ) { ++$this->maintenance; if ( null !== $this->after_maintenance ) { call_user_func( $this->after_maintenance ); } }
}
