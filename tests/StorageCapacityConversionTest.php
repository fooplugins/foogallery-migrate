<?php
namespace FooPlugins\FooGalleryMigrate\Tests;
use FooPlugins\FooGalleryMigrate\StorageCapacityBudget;
use FooPlugins\FooGalleryMigrate\StorageScaleAdmission;
use FooPlugins\FooGalleryMigrate\StorageCopyCursor;
use FooPlugins\FooGalleryMigrate\StorageControl;
use FooPlugins\FooGalleryMigrate\LegacyOptionDecoder;
use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use FooPlugins\FooGalleryMigrate\LegacyRecordCodec;
use FooPlugins\FooGalleryMigrate\StorageConversion;
use PHPUnit\Framework\TestCase;

class StorageCapacityConversionTest extends TestCase {
	public function test_raw_fetch_requires_an_owned_capacity_phase_before_sql(): void {
		$old = isset( $GLOBALS['wpdb'] ) ? $GLOBALS['wpdb'] : null;
		$GLOBALS['wpdb'] = new class {
			public $prefix = 'wp_'; public $base_prefix = 'wp_'; public $reads = 0;
			public function __call( $name, $args ) { ++$this->reads; throw new \RuntimeException( 'Unexpected SQL.' ); }
		};
		try {
			$conversion = new StorageConversion( null, null, $this->scale() );
			$method = new \ReflectionMethod( $conversion, 'option_raw' ); $method->setAccessible( true );
			try { $method->invoke( $conversion ); $this->fail( 'Naked raw fetch admitted.' ); }
			catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'Retain recorded authority', $error->getMessage() ); }
			$this->assertSame( 0, $GLOBALS['wpdb']->reads );
		} finally { $GLOBALS['wpdb'] = $old; }
	}
	public function scale( $batch_rows = 1024 ) {
		$class = StorageCapacityPolicyTest::capacity_class(); $class['limits']['copy_batch_rows'] = $batch_rows;
		$clock = function () { return microtime( true ); };
		$capacity = new StorageCapacityBudget( $class, $clock, function () { return array( 'limit' => '-1', 'zend' => 100, 'live' => 100, 'rss' => 100, 'peak' => 100 ); } );
		$limits = array( 'version' => 1 );
		foreach ( array( 'max_tokens', 'max_rows', 'max_bytes', 'max_depth', 'max_heads', 'max_physical_rows_per_table', 'max_physical_rows', 'max_physical_bytes', 'memory_ceiling_bytes', 'max_seconds' ) as $key ) { $limits[$key] = $class['limits'][$key]; }
		return new StorageScaleAdmission( $limits, array( 'max_raw_bytes' => 16777216, 'amplification' => 1, 'reserve_bytes' => 10 ), $capacity->deadline(), $clock, null, null, $capacity );
	}
	public function test_reader_digest_is_the_identical_existing_typed_ordered_recursive_domain(): void {
		$c = new LegacyStateCodec();
		foreach ( array( array(), array( 0 => false, '00' => null, 's' => array( -1 => "\0\xff", '01' => -0.0 ), 3 => 1.25 ), array( 'children' => array(), 'migrated' => array( 7 => true ) ) ) as $value ) {
			$keys = array_keys( $value );
			$this->assertSame( $c->snapshot_digest( $value ), $c->snapshot_digest_reader( count( $value ), function ( $i ) use ( $value, $keys ) { return array( 'key' => $keys[$i], 'value' => $value[$keys[$i]] ); } ) );
		}
	}
	public function test_pre_hydration_statistics_bind_bytes_tokens_scalar_heads_depth_and_references_refuse(): void {
		$d = new LegacyOptionDecoder( 16777216, 64, 262144, $this->scale() );
		$value = array( 'x' => array( '00' => str_repeat( 's', 257 ), 0 => false ) ); $raw = serialize( $value ); $s = $d->structural_statistics( $raw );
		$this->assertSame( array( 'R' => strlen( $raw ), 'T' => 7, 'S' => 257, 'H' => 1, 'D' => 2 ), $s );
		$this->assertSame( $value, $d->decode( $raw ) );
		$this->expectException( \RuntimeException::class ); $d->decode( 'a:1:{i:0;R:1;}' );
	}
	private function control() {
		$r = array( 'version' => 4, 'blog_id' => 1, 'backend' => 'option', 'epoch' => 0, 'switch_id' => str_repeat( 'a', 32 ),
			'from' => 'option', 'to' => 'table', 'phase' => 'copying', 'table_id' => str_repeat( 'a', 32 ), 'source_digest' => str_repeat( 'b', 64 ),
			'logical_digest' => str_repeat( 'c', 64 ), 'schema' => 1, 'codec' => 1, 'created' => 1, 'updated' => 1, 'retained_digest' => null );
		$r['copy_cursor'] = StorageCopyCursor::initial( $r ); return $r;
	}
	public function test_v4_scalar_cursor_is_closed_bounded_and_unselectable_until_verified(): void {
		$r = $this->control(); $this->assertSame( $r, StorageControl::decode( json_encode( $r ), 1 ) );
		$this->assertLessThan( 2049, strlen( json_encode( $r ) ) ); $this->assertFalse( StorageControl::runtime_ready( $r, null ) );
		foreach ( array( 'state_order' => 101, 'state_id' => -1, 'staged_generation' => 0, 'expected_revision' => 1, 'next_root_ordinal' => 24577,
			'next_node_ordinal' => '1', 'committed_rows' => 24577, 'committed_bytes' => 16777217, 'committed_tokens' => 262145,
			'completed_heads_digest' => str_repeat( 'G', 64 ), 'payload' => array( 'forbidden' ) ) as $key => $bad ) {
			$invalid = $r; $invalid['copy_cursor'][$key] = $bad;
			try { StorageControl::decode( json_encode( $invalid ), 1 ); $this->fail( 'Invalid cursor admitted.' ); }
			catch ( \RuntimeException $e ) { $this->assertNotEmpty( $e->getMessage() ); }
		}
		$r['phase'] = 'verified';
		try { StorageControl::decode( json_encode( $r ), 1 ); $this->fail( 'Live cursor escaped copying.' ); }
		catch ( \RuntimeException $e ) { $this->assertNotEmpty( $e->getMessage() ); }
		$r['copy_cursor'] = null; $this->assertSame( $r, StorageControl::decode( json_encode( $r ), 1 ) );
		$r['verify_cursor'] = StorageCopyCursor::initial_verify();
		$this->assertSame( $r, StorageControl::decode( json_encode( $r ), 1 ) );
		$wrong = $r; $wrong['phase'] = 'copying';
		try { StorageControl::decode( json_encode( $wrong ), 1 ); $this->fail( 'Proof position escaped verified phase.' ); }
		catch ( \RuntimeException $e ) { $this->assertNotEmpty( $e->getMessage() ); }
		$wrong = $r; $wrong['verify_cursor']['next_node_ordinal'] = 1;
		try { StorageControl::decode( json_encode( $wrong ), 1 ); $this->fail( 'Unbound proof advanced.' ); }
		catch ( \RuntimeException $e ) { $this->assertNotEmpty( $e->getMessage() ); }
	}
	public function test_conversion_walk_yields_inside_one_over_10k_root_without_emitting_a_complete_root_array(): void {
		$scale = $this->scale(); $codec = new LegacyStateCodec( 1048576, 64, 10000, $scale ); $records = new LegacyRecordCodec( $codec, 24576, 16777216, $scale );
		$root = array( 'id' => 99, 'children' => array() );
		for ( $i = 0; $i < 12000; ++$i ) { $root['children'][$i] = $i; }
		$committed = 0; $passes = 0; $digest = hash_init( 'sha256' );
		do {
			$visited = 0; $batch = 0;
			$done = $records->walk_conversion_tree_from( 'active', 0, $root, 0, $committed, $visited, function ( $row, $tokens, $bytes ) use ( &$batch, &$committed, $digest ) {
				if ( $batch >= 1024 ) { return false; }
				$this->assertGreaterThan( 0, $tokens ); $this->assertGreaterThan( 0, $bytes );
				hash_update( $digest, $row['node_hash'] . $row['logical_hash'] ); ++$batch; ++$committed; return true;
			} ); ++$passes;
			$this->assertGreaterThanOrEqual( $committed, $visited );
			$this->assertLessThanOrEqual( 1024, $batch ); $this->assertLessThan( 14, $passes );
		} while ( ! $done );
		$this->assertSame( 12001, $committed ); $this->assertSame( 12, $passes );
		$direct = hash_init( 'sha256' );
		$this->assertTrue( $records->walk_conversion_tree( 'active', 0, $root, 0, function ( $row ) use ( $direct ) { hash_update( $direct, $row['node_hash'] . $row['logical_hash'] ); return true; } ) );
		$this->assertSame( hash_final( $direct ), hash_final( $digest ) );
	}
}
