<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use FooPlugins\FooGalleryMigrate\StorageCapacityBudget;
use FooPlugins\FooGalleryMigrate\StorageCapacityPolicy;
use FooPlugins\FooGalleryMigrate\StorageControl;
use FooPlugins\FooGalleryMigrate\StorageConversion;
use FooPlugins\FooGalleryMigrate\StorageScaleAdmission;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/StorageSnapshotMaintenanceAdmissionTest.php';
require_once __DIR__ . '/StorageCapacityPolicyTest.php';

/** Lifetime/ordering regressions over unit spies, not native qualification. */
class StorageRepresentationLifetimeTest extends TestCase {
	private function manager( $db, $scale = null ) {
		$GLOBALS['wpdb'] = $db; $GLOBALS['foogallery_migrate_test_blog_id'] = 7;
		$m = new StorageConversion( array( 'max_raw_bytes' => 1048576, 'amplification' => 1, 'reserve_bytes' => 1 ), new SnapshotAdmissionBaseline(), $scale );
		foreach ( array( 'connection' => $db, 'gate' => new LifetimeGate(), 'deadline' => microtime( true ) + 10 ) as $name => $value ) { $this->put( $m, $name, $value ); }
		$this->call( $m, 'start_memory' ); return $m;
	}
	private function put( $m, $name, $value ) { ( new \ReflectionProperty( StorageConversion::class, $name ) )->setValue( $m, $value ); }
	private function call( $m, $name, ...$args ) { return ( new \ReflectionMethod( StorageConversion::class, $name ) )->invokeArgs( $m, $args ); }
	protected function tearDown(): void { unset( $GLOBALS['wpdb'], $GLOBALS['foogallery_migrate_test_blog_id'] ); }

	public function test_option_raw_buffer_is_released_before_logical_hashing(): void {
		$db = new LifetimeOptionDb(); $m = $this->manager( $db ); $codec = new LifetimeMemoryCodec(); $this->put( $m, 'codec', $codec );
		$before = memory_get_usage( false ); $result = $this->call( $m, 'option_snapshot' );
		$this->assertSame( array( 'large' => str_repeat( 'x', LifetimeOptionDb::BYTES ) ), $result['value'] );
		// A freshly hydrated string is live, but NOT an additional serialized
		// string of the same size. Loose gap is a unit lifetime check, not a bound.
		$this->assertLessThan( LifetimeOptionDb::BYTES * 1.5, $codec->at_digest - $before );
		$this->assertGreaterThan( LifetimeOptionDb::BYTES, $codec->at_digest - $before );
	}

	public function test_refreshed_metadata_uses_real_bounded_reader_after_dirty_publication(): void {
		$db = new MaintenanceAdmissionWpdb(); $m = $this->manager( $db ); $this->call( $m, 'storage' );
		$codec = new LifetimeReaderCodec(); $this->put( $m, 'codec', $codec );
		$result = $this->call( $m, 'source_metadata', 'table', true );
		$this->assertSame( ( new LegacyStateCodec() )->snapshot_digest( array( 'bucket' => $db->value ) ), $result['logical'] );
		$this->assertTrue( $db->published ); $this->assertSame( 1, $codec->readers );
	}

	public function test_refreshed_metadata_multiple_heads_remains_ordered_and_physically_bound(): void {
		$db = new LifetimeHeadsDb(); $m = $this->manager( $db ); $snapshot = new LifetimeSnapshot(); $this->put( $m, 'snapshot', $snapshot );
		$this->put( $m, 'codec', new LifetimeReaderCodec() );
		$result = $this->call( $m, 'source_metadata', 'table', true );
		$this->assertSame( array( 'a', 'b' ), $snapshot->reads );
		$this->assertSame( array( array( 'a', 1 ), array( 'b', 1 ) ), $snapshot->verified );
		$this->assertSame( ( new LegacyStateCodec() )->snapshot_digest( array( 'a' => array( 'a' ), 'b' => array( 'b' ) ) ), $result['logical'] );
		$db->change = true; $db->head_reads = 0;
		$this->expectException( \RuntimeException::class ); $this->call( $m, 'source_metadata', 'table', true );
	}

	public function test_refreshed_metadata_rejects_noncontiguous_order(): void {
		$db = new LifetimeHeadsDb(); $db->bad_order = true; $m = $this->manager( $db ); $this->put( $m, 'snapshot', new LifetimeSnapshot() );
		$this->expectException( \RuntimeException::class ); $this->call( $m, 'source_metadata', 'table', true );
	}

	private function capacity( $fixed = 100, $ceiling = 10000 ) {
		$c = StorageCapacityPolicyTest::capacity_class(); $c['limits']['memory_ceiling_bytes'] = $ceiling;
		foreach ( StorageCapacityPolicy::phases() as $p ) { foreach ( array( 'zend', 'native' ) as $domain ) {
			$c['phase_envelopes'][$p][$domain] = array( 'fixed_bytes' => 'commit_cleanup_response' === $p ? $fixed : 1,
				'per_dimension' => array_fill_keys( StorageCapacityPolicy::dimensions(), 0 ) );
		} }
		$b = new StorageCapacityBudget( $c, null, function () { return array( 'limit' => '-1', 'zend' => 100, 'live' => 100, 'rss' => 100, 'peak' => 100 ); } );
		$limits = array( 'version' => 1 );
		foreach ( array( 'max_tokens', 'max_rows', 'max_bytes', 'max_depth', 'max_heads', 'max_physical_rows_per_table', 'max_physical_rows', 'max_physical_bytes', 'memory_ceiling_bytes', 'max_seconds' ) as $key ) { $limits[$key] = $c['limits'][$key]; }
		$s = new StorageScaleAdmission( $limits, array( 'max_raw_bytes' => 16777216, 'amplification' => 1, 'reserve_bytes' => 10 ), $b->deadline(), null, null, null, $b );
		return array( $s, $b );
	}
	private function stack( $b ) { return ( new \ReflectionProperty( $b, 'stack' ) )->getValue( $b ); }
	private function record() { return array( 'backend' => 'table', 'to' => 'table', 'epoch' => 1, 'blog_id' => 7, 'table_id' => str_repeat( 'a', 32 ), 'schema' => 1, 'codec' => 1, 'source_digest' => hash( 'sha256', 'present:' . hash( 'sha256', serialize( array() ) ) ) ); }
	private function with_capacity( $fixed = 100, $ceiling = 10000 ) {
		list( $scale, $b ) = $this->capacity( $fixed, $ceiling ); $db = new LifetimeWriteDb(); $db->budget = $b;
		$m = $this->manager( $db, $scale ); $control = new LifetimeControl(); $control->sentinel = json_encode( StorageControl::sentinel( $this->record() ) ); $this->put( $m, 'controls', $control );
		return array( $m, $db, $b );
	}
	/** @dataProvider materialMethods */
	public function test_backup_and_exact_cleanup_refuse_before_any_material_sql( $method ): void {
		list( $m, $db, $b ) = $this->with_capacity( 1000, 1000 );
		try { $this->call( $m, $method, $this->record() ); $this->fail( 'Unadmitted material phase executed.' ); }
		catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'capacity budget refused', $error->getMessage() ); }
		$this->assertSame( array(), $db->seen ); $this->assertFalse( $db->deleted );
	}
	public static function materialMethods(): array { return array( array( 'retain_and_select' ), array( 'cleanup_source' ) ); }

	/** @dataProvider materialMethods */
	public function test_backup_and_cleanup_keep_parent_forecast_until_buffers_unwind( $method ): void {
		list( $m, $db, $b ) = $this->with_capacity(); $parent = new \stdClass(); $t = $b->open( $parent, 'stage', array() );
		if ( 'retain_and_select' === $method ) { ( new \ReflectionProperty( StorageConversion::class, 'controls' ) )->getValue( $m )->sentinel = null; }
		$this->call( $m, $method, $this->record() );
		foreach ( $db->seen as $event ) { $this->assertGreaterThanOrEqual( 2, $event['depth'] ); $this->assertTrue( $event['material'] ); }
		$this->assertCount( 1, $this->stack( $b ) ); $b->close( $parent, $t ); $this->assertSame( array(), $this->stack( $b ) );
		if ( 'cleanup_source' === $method ) { $this->assertTrue( $db->deleted ); $this->assertStringContainsString( 'BINARY option_value = BINARY', $db->delete_sql ); }
	}

	/** @dataProvider exceptionalMethods */
	public function test_failed_material_scope_aborts_instead_of_releasing_forecast( $method, $class ): void {
		list( $m, $db, $b ) = $this->with_capacity(); $db->fault = new $class( 'owned material failure' );
		try { $this->call( $m, $method, $this->record() ); $this->fail( 'Missing fault.' ); }
		catch ( \Throwable $error ) { $this->assertSame( $db->fault, $error ); }
		$this->assertTrue( ( new \ReflectionProperty( $b, 'failed' ) )->getValue( $b ) );
		$this->assertNotEmpty( $this->stack( $b ) ); $this->assertFalse( $db->deleted );
	}
	public static function exceptionalMethods(): array {
		$out = array(); foreach ( self::materialMethods() as $method ) { foreach ( array( '\\RuntimeException', '\\Error', '\\TypeError' ) as $c ) { $out[] = array( $method[0], $c ); } } return $out;
	}

	public function test_split_scalar_hash_updates_preserve_old_domain_for_typed_values(): void {
		$c = new LegacyStateCodec();
		foreach ( array( null, false, true, 1, -1, -0.0, 1.25, "\0\xff", str_repeat( 'x', 100000 ), array( '00' => false, -1 => array( null, 1.25 ) ) ) as $v ) {
			$h = hash_init( 'sha256' ); hash_update( $h, "legacy-logical-v1\0" ); $this->old_digest( $h, $v, $c );
			$this->assertSame( hash_final( $h ), $c->snapshot_digest( $v ) );
		}
	}
	private function old_digest( $h, $v, $c ) {
		if ( is_array( $v ) ) { hash_update( $h, 'array:' . count( $v ) . ':' ); foreach ( $v as $k => $item ) { $this->old_digest( $h, $k, $c ); $this->old_digest( $h, $item, $c ); } }
		else { $e = $c->encode( $v ); hash_update( $h, 'value:' . strlen( $e ) . ':' . $e ); }
	}
}

class LifetimeGate extends MaintenanceAdmissionGate { public function maintenance_identity() { return 'owned'; } }
class LifetimeMemoryCodec extends LegacyStateCodec {
	public $at_digest;
	public function snapshot_digest( $v ) { $this->at_digest = memory_get_usage( false ); return parent::snapshot_digest( $v ); }
}
class LifetimeReaderCodec extends LegacyStateCodec {
	public $readers = 0;
	public function snapshot_digest( $v ) { throw new \RuntimeException( 'Refreshed metadata retained an aggregate graph.' ); }
	public function snapshot_digest_reader( $n, $reader ) { ++$this->readers; return parent::snapshot_digest_reader( $n, $reader ); }
}
class LifetimeOptionDb {
	const BYTES = 524288;
	public $prefix = 'wp_'; public $base_prefix = 'wp_'; public $last_error = '';
	public function is_current() { return true; }
	public function prepare( $sql, $args ) { return $sql; }
	public function get_var( $sql ) {
		$raw = serialize( array( 'large' => str_repeat( 'x', self::BYTES ) ) );
		if ( false !== strpos( $sql, 'SELECT LENGTH' ) ) { return (string) strlen( $raw ); }
		if ( false !== strpos( $sql, 'SELECT SHA2' ) ) { return hash( 'sha256', $raw ); }
		if ( false !== strpos( $sql, "SELECT CONCAT('v:'" ) ) { return 'v:' . $raw; }
		throw new \RuntimeException( 'Unexpected SQL.' );
	}
}
class LifetimeHeadsDb extends LifetimeOptionDb {
	public $head_reads = 0; public $change = false; public $bad_order = false;
	public function get_results( $sql, $format ) {
		++$this->head_reads; $c = new LegacyStateCodec(); $out = array();
		foreach ( array( 'a', 'b' ) as $i => $key ) { $out[] = array( 'id' => $i + 1, 'key_value' => $c->encode( $key ), 'state_order' => $this->bad_order ? $i + 1 : $i,
			'revision' => $this->change && $this->head_reads > 2 ? 2 : 1, 'active_generation' => 1, 'record_count' => 1, 'payload_bytes' => 10, 'digest_status' => 'dirty' ); }
		return $out;
	}
}
class LifetimeSnapshot {
	public $reads = array(); public $verified = array();
	public function verify_digest( $k, $r, $seconds ) { $this->verified[] = array( $k, $r ); }
	public function read_bounded( $k ) { $this->reads[] = $k; return array( $k ); }
}
class LifetimeControl extends StorageControl {
	public $sentinel;
	public function __construct() {}
	public function read( $name ) { return $this->sentinel; }
	public function cas( $name, $old, $new, $guard = null, $owner = null, $deadline = null ) { return true; }
	public function maintenance_guard( $owner ) { return ' AND owned_guard = 1'; }
}
class LifetimeWriteDb extends LifetimeOptionDb {
	public $budget; public $seen = array(); public $deleted = false; public $delete_sql = ''; public $fault;
	public function get_var( $sql ) {
		$this->observe(); if ( $this->fault ) { throw $this->fault; }
		if ( false !== strpos( $sql, 'SELECT LENGTH' ) ) { return (string) strlen( serialize( array() ) ); }
		if ( false !== strpos( $sql, 'SELECT SHA2' ) ) { return hash( 'sha256', serialize( array() ) ); }
		if ( false !== strpos( $sql, "SELECT CONCAT('v:'" ) ) { return 'v:' . serialize( array() ); }
		if ( false !== strpos( $sql, 'SELECT 1 AS present' ) ) { return $this->deleted ? null : '1'; }
		throw new \RuntimeException( 'Unexpected SQL.' );
	}
	public function query( $sql ) { $this->observe(); $this->delete_sql = $sql; $this->deleted = true; return 1; }
	private function observe() {
		$stack = ( new \ReflectionProperty( $this->budget, 'stack' ) )->getValue( $this->budget ); $material = false;
		foreach ( $stack as $s ) { if ( 200 === $s['pending'] ) { $material = true; } }
		$this->seen[] = array( 'depth' => count( $stack ), 'material' => $material );
	}
}
