<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\LegacyTableSnapshot;
use FooPlugins\FooGalleryMigrate\StorageScaleAdmission;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/StorageScaleAdmissionTest.php';
require_once __DIR__ . '/support/SnapshotScaleConnection.php';

class StorageSnapshotScaleTest extends TestCase {
	public static $receipt;
	public static function exact_policy() {
		$p = StorageScaleAdmissionTest::policy();
		$p['max_rows'] = 20208; $p['max_heads'] = 1; $p['max_bytes'] = 15070958;
		$p['max_physical_rows_per_table'] = 20208; $p['max_physical_rows'] = 20209;
		$p['max_physical_bytes'] = 15070958; $p['max_tokens'] = 204995; $p['max_depth'] = 8;
		return $p;
	}
	public static function lowerPolicies(): array {
		return array_map( function ( $key ) { return array( $key ); }, array( 'max_rows', 'max_bytes', 'max_physical_rows_per_table', 'max_physical_rows', 'max_physical_bytes', 'max_tokens', 'max_depth' ) );
	}
	/** Original corpus is exactly at the footprint ceiling; lowering one by one refuses. @dataProvider lowerPolicies */
	public function test_original20k_exact_plus_one_refuses_before_sql( $field ): void {
		$p = self::exact_policy(); --$p[$field]; $db = new SnapshotScaleConnection();
		$refused = false;
		try { $this->snapshot( $db, StorageScaleAdmissionTest::admission( $p ) )->replace_many( $this->changes( StorageA7Fixture::value( 20000 ) ) ); }
		catch ( \RuntimeException $error ) { $refused = true; }
		$this->assertTrue( $refused, $field ); $this->assertSame( array(), $db->queries ); $this->assertSame( array(), $db->events );
	}
	public function test_original20k_no_scale_default_refuses_with_zero_sql(): void {
		$db = new SnapshotScaleConnection(); $refused = false;
		try { ( new LegacyTableSnapshot( $db, 7 ) )->replace_many( $this->changes( StorageA7Fixture::value( 20000 ) ) ); }
		catch ( \RuntimeException $error ) { $refused = true; $this->assertStringContainsString( 'record budget', $error->getMessage() ); }
		$this->assertTrue( $refused ); $this->assertSame( array(), $db->events ); $this->assertSame( array(), $db->queries );
	}
	public function test_original20k_companion_head_and_lock_limits_do_not_reset(): void {
		$value = StorageA7Fixture::value( 20000 );
		$companion = array( 'key' => 'checkpoint', 'order' => 1, 'kind' => 'scalar', 'value' => false, 'expected_revision' => null );
		foreach ( array( 'companion', 'dependency' ) as $case ) {
			$db = new SnapshotScaleConnection(); $p = StorageScaleAdmissionTest::policy(); $p['max_heads'] = 1;
			$changes = $this->changes( $value ); $guards = array();
			if ( 'companion' === $case ) { $changes[] = $companion; } else { $guards[] = array( 'key' => 'checkpoint', 'head' => false ); }
			$refused = false;
			try { $this->snapshot( $db, StorageScaleAdmissionTest::admission( $p ) )->replace_many( $changes, $guards ); }
			catch ( \RuntimeException $error ) { $refused = true; }
			$this->assertTrue( $refused ); $this->assertSame( array(), $db->queries ); $this->assertSame( array(), $db->events );
		}
	}
	/** @dataProvider postStageFailures */
	public function test_original20k_failed_replacement_restores_existing_head_and_authority( $fault ): void {
		$value = StorageA7Fixture::value( 20000 ); $db = new SnapshotScaleConnection();
		$this->snapshot( $db, StorageScaleAdmissionTest::admission() )->replace_many( $this->changes( $value ) );
		$heads = $db->heads; $records = $db->records; $db->events = array(); $db->emitted = 0; $db->fetches = 0;
		$authority = $GLOBALS['foogallery_migrate_test_options'];
		$now = 100.0; $read_samples = 0; $read_started = false;
		$db->after_event = function ( $event, $db ) use ( &$now, &$read_started, $fault ) {
			if ( 'publish' === $event && 'after-publish' === $fault ) { throw new \RuntimeException( 'Host injected fault after CAS, before commit.' ); }
			if ( 'record-insert' === $event && 100 === $db->emitted && 'stage-deadline' === $fault ) { $now = 111.0; }
			if ( 'fetch' === $event ) { $read_started = true; }
		};
		$db->corrupt_readback = 'missing-row' === $fault;
		$memory = function () use ( &$now, &$read_samples, &$read_started, $fault ) {
			if ( $read_started && 'readback-deadline' === $fault && 3 === ++$read_samples ) { $now = 111.0; }
			return array( ini_get( 'memory_limit' ), memory_get_usage( true ) );
		};
		$b = StorageScaleAdmissionTest::admission( null, function () use ( &$now ) { return $now; }, $memory );
		$changes = $this->changes( $value ); $changes[0]['expected_revision'] = 1; $refused = false;
		try { $this->snapshot( $db, $b )->replace_many( $changes ); }
		catch ( \RuntimeException $error ) { $refused = true; }
		$this->assertTrue( $refused ); $this->assertSame( $heads, $db->heads ); $this->assertSame( $records, $db->records );
		$this->assertSame( $authority, $GLOBALS['foogallery_migrate_test_options'] );
		$this->assertSame( 1, $db->events['rollback'] );
		if ( 'after-publish' === $fault ) { $this->assertSame( 1, $db->events['publish'] ); } else { $this->assertArrayNotHasKey( 'publish', $db->events ); }
		$this->assertArrayNotHasKey( 'commit', $db->events );
		$this->assertSame( 'stage-deadline' === $fault ? 100 : 20208, $db->emitted );
		if ( 'readback-deadline' === $fault ) { $this->assertSame( 3, $read_samples ); $this->assertSame( 1, $db->fetches ); }
	}
	public static function postStageFailures(): array { return array( array( 'stage-deadline' ), array( 'readback-deadline' ), array( 'missing-row' ), array( 'after-publish' ) ); }
	public function test_original20k_read_bounded_obeys_lower_policy_and_does_not_reset_domain(): void {
		$value = StorageA7Fixture::value( 20000 ); $db = new SnapshotScaleConnection();
		$b = StorageScaleAdmissionTest::admission( self::exact_policy(), function () { return 100.0; } );
		$this->snapshot( $db, $b )->replace_many( $this->changes( $value ) ); $heads = $db->heads; $records = $db->records;
		foreach ( array( 'max_rows', 'max_bytes', 'max_tokens', 'max_depth' ) as $field ) {
			$p = self::exact_policy(); --$p[$field]; $refused = false; $fetches = $db->fetches;
			try { $this->snapshot( $db, StorageScaleAdmissionTest::admission( $p ) )->read_bounded( 'galleries' ); }
			catch ( \RuntimeException $error ) { $refused = true; }
			$this->assertTrue( $refused, $field );
			if ( 'max_rows' === $field || 'max_bytes' === $field ) { $this->assertSame( $fetches, $db->fetches ); }
		}
		$events = $db->events; $queries = $db->queries;
		$repeat = $this->changes( $value ); $repeat[0]['expected_revision'] = 1;
		foreach ( array( $repeat, array( array( 'key' => 'checkpoint', 'order' => 1, 'kind' => 'scalar', 'value' => false, 'expected_revision' => null ) ) ) as $changes ) {
			$refused = false;
			try { $this->snapshot( $db, $b )->replace_many( $changes ); }
			catch ( \RuntimeException $error ) { $refused = true; }
			$this->assertTrue( $refused ); $this->assertSame( $events, $db->events ); $this->assertSame( $queries, $db->queries );
		}
		$this->assertSame( $heads, $db->heads ); $this->assertSame( $records, $db->records );
		$refused = false;
		try { new LegacyTableSnapshot( $db, 8, 20208, 15070958, null, null, $b ); }
		catch ( \RuntimeException $error ) { $refused = true; }
		$this->assertTrue( $refused ); $this->assertSame( $events, $db->events );
	}
	public function test_forged_cloned_serialized_and_reinitialized_scale_cannot_bypass_constructor(): void {
		$db = new SnapshotScaleConnection();
		$forged = ( new \ReflectionClass( StorageScaleAdmission::class ) )->newInstanceWithoutConstructor();
		foreach ( array( new \stdClass(), $forged ) as $bad ) {
			$refused = false;
			try { new LegacyTableSnapshot( $db, 7, 24576, 16777216, null, null, $bad ); }
			catch ( \RuntimeException $error ) { $refused = true; }
			$this->assertTrue( $refused );
		}
		$b = StorageScaleAdmissionTest::admission();
		foreach ( array( 'serialize', 'clone', 'wakeup', 'reinitialize' ) as $case ) {
			$refused = false;
			try {
				if ( 'serialize' === $case ) { serialize( $b ); }
				elseif ( 'clone' === $case ) { $copy = clone $b; }
				elseif ( 'wakeup' === $case ) { $class = StorageScaleAdmission::class; unserialize( 'O:' . strlen( $class ) . ':"' . $class . '":0:{}' ); }
				else { $b->__construct( StorageScaleAdmissionTest::policy(), StorageScaleAdmissionTest::decode_policy(), microtime( true ) + 10.0 ); }
			} catch ( \RuntimeException $error ) { $refused = true; }
			$this->assertTrue( $refused, $case );
		}
		$this->assertSame( array(), $db->queries );
	}

	private function changes( $value ) {
		return array( array( 'key' => 'galleries', 'order' => 0, 'kind' => 'map', 'value' => $value['galleries'], 'expected_revision' => null ) );
	}
	private function snapshot( $db, $budget ) {
		return new LegacyTableSnapshot( $db, 7, $budget->limit( 'max_rows' ), $budget->limit( 'max_bytes' ), null, null, $budget );
	}
	public function test_original20k_shared_deadline_expires_mid_stage_and_rolls_back(): void {
		$value = StorageA7Fixture::value( 20000 ); $db = new SnapshotScaleConnection(); $now = 100.0;
		$budget = StorageScaleAdmissionTest::admission( null, function () use ( &$now ) { return $now; } );
		$db->after_event = function ( $event, $db ) use ( &$now ) { if ( 'record-insert' === $event && 100 === $db->emitted ) { $now = 111.0; } };
		try { $this->snapshot( $db, $budget )->replace_many( $this->changes( $value ) ); $this->fail( 'Shared scale was ignored.' ); }
		catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( 'scale admission refused', $error->getMessage() );
			$this->assertSame( 100, $db->emitted ); $this->assertSame( 1, $db->events['rollback'] );
			$this->assertSame( array(), $db->heads ); $this->assertSame( array(), $db->records ); $this->assertArrayNotHasKey( 'publish', $db->events );
		}
	}
	public function test_original20k_lower_depth_policy_is_not_ignored(): void {
		$p = StorageScaleAdmissionTest::policy(); $p['max_depth'] = 7;
		$db = new SnapshotScaleConnection();
		try { $this->snapshot( $db, StorageScaleAdmissionTest::admission( $p ) )->replace_many( $this->changes( StorageA7Fixture::value( 20000 ) ) ); $this->fail( 'Lower scale depth was ignored.' ); }
		catch ( \RuntimeException $error ) { $this->assertSame( array(), $db->events ); $this->assertSame( array(), $db->queries ); }
	}
	public function test_original20k_actual_snapshot_replace_and_readback(): void {
		$value = StorageA7Fixture::value( 20000 ); $raw = serialize( $value );
		$this->assertSame( '6022f4ad5a1e8ef9a39a5b5284dc576c7eb75d02a0d69949a58e3967ca9dd4be', hash( 'sha256', $raw ) );
		$db = new SnapshotScaleConnection(); $phase = 'preflight'; $samples = array();
		$p = self::exact_policy();
		$memory = function () use ( &$samples, &$phase ) {
			if ( ! isset( $samples[$phase] ) ) { $samples[$phase] = array( 'calls' => 0, 'max_used' => 0, 'max_allocated' => 0 ); }
			++$samples[$phase]['calls'];
			$samples[$phase]['max_used'] = max( $samples[$phase]['max_used'], memory_get_usage( false ) );
			$samples[$phase]['max_allocated'] = max( $samples[$phase]['max_allocated'], memory_get_usage( true ) );
			return array( ini_get( 'memory_limit' ), memory_get_usage( true ) );
		};
		$db->after_event = function ( $event ) use ( &$phase ) { if ( 'record-insert' === $event ) { $phase = 'stage'; } elseif ( 'fetch' === $event ) { $phase = 'driver-buffer/readback'; } };
		$started = microtime( true ); $budget = StorageScaleAdmissionTest::admission( $p, null, $memory );
		$this->assertSame( $raw, serialize( $budget->decoder( strlen( $raw ) )->decode( $raw ) ) );
		$snapshot = $this->snapshot( $db, $budget );
		$result = $snapshot->replace_many( $this->changes( $value ) );
		$this->assertCount( 1, $result ); $this->assertCount( 1, $db->heads );
		$this->assertCount( 20208, $db->records ); $this->assertSame( 20208, $db->emitted );
		$this->assertSame( array( 'galleries' => $snapshot->read_bounded( 'galleries' ) ), $value );
		$this->assertSame( $raw, serialize( array( 'galleries' => $snapshot->read_bounded( 'galleries' ) ) ) );
		$head = reset( $db->heads ); $bytes = 0; $hashes = array();
		foreach ( $db->records as $row ) { $bytes += SnapshotScaleConnection::bytes( $row ); $hashes[$row['node_hash']] = true; }
		$this->assertCount( 20208, $hashes ); $this->assertSame( 15070354, $bytes );
		$this->assertSame( 'map', $head['state_kind'] ); $this->assertSame( 1, $head['revision'] );
		$this->assertSame( 1, $db->events['commit'] ); $this->assertArrayNotHasKey( 'rollback', $db->events );
		$budget->tick();
		self::$receipt = array( 'host_only' => true, 'native_sql' => false, 'profile_approved' => false, 'method_approved' => false,
			'raw_sha256' => hash( 'sha256', $raw ), 'emitted_records' => $db->emitted, 'heads' => count( $db->heads ),
			'encoded_record_bytes' => $bytes, 'root_bytes' => strlen( $head['root_payload'] ), 'key_bytes' => strlen( $head['state_key_value'] ),
			'fetches' => $db->fetches, 'fetched_rows' => $db->fetched_rows, 'fetched_encoded_bytes' => $db->fetched_bytes,
			'events' => $db->events, 'peak_php_used' => memory_get_peak_usage( false ), 'peak_php_allocated' => memory_get_peak_usage( true ),
			'php_memory_limit' => ini_get( 'memory_limit' ), 'physical_all_generation_native_bytes' => null,
			'policy' => $p, 'memory_samples' => $samples, 'elapsed_seconds' => microtime( true ) - $started,
			'qualification' => 'Affected snapshot rows/encoded columns only. Host rows plus materialized driver-style row arrays, ancestry indexes, tree buffer and rebuilt graphs coexist; immutable strings can share PHP backing storage, unlike a native driver. Sampling is not calibrated allocation prediction or a never-OOM proof.' );
	}
}
