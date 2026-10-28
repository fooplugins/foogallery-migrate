<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\StorageScaleAdmission;
use FooPlugins\FooGalleryMigrate\LegacyRecordCodec;
use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/StorageScaleAdmissionTest.php';

class RecordFootprintPrimitiveTest extends TestCase {
	private function budget( $limit = '128M', $used = 0, $ceiling = 134217728 ) {
		$p = StorageScaleAdmissionTest::policy(); $p['memory_ceiling_bytes'] = $ceiling;
		return StorageScaleAdmissionTest::admission( $p, null, function () use ( $limit, $used ) { return array( $limit, $used ); } );
	}
	public function test_additional_is_not_raw_amplification_and_is_cumulative(): void {
		$b = $this->budget( '-1', 0, 33554442 );
		$this->assertTrue( $b->admit_additional( 9 ) );
		$this->assertTrue( $b->admit_additional( 1 ) );
		$this->expectException( \RuntimeException::class ); $b->admit_additional( 1 );
	}
	public function test_each_finite_ceiling_and_retained_usage_apply(): void {
		foreach ( array( array( '33554442', 0, 134217728 ), array( '-1', 0, 33554442 ), array( '128M', 100, 33554542 ) ) as $args ) {
			$b = $this->budget( $args[0], $args[1], $args[2] );
			$this->assertTrue( $b->admit_additional( 10 ) );
			try { $b->admit_additional( 1 ); $this->fail( 'Ceiling bypassed.' ); }
			catch ( \RuntimeException $e ) { $this->assertNotEmpty( $e->getMessage() ); }
		}
	}
	public function test_unknown_negative_float_overflow_and_checked_math(): void {
		foreach ( array( null, -1, 1.0, '1', PHP_INT_MAX ) as $bad ) {
			try { $this->budget()->admit_additional( $bad ); $this->fail( 'Unknown allocation admitted.' ); }
			catch ( \RuntimeException $e ) { $this->assertNotEmpty( $e->getMessage() ); }
		}
		$this->assertSame( PHP_INT_MAX, StorageScaleAdmission::checked_add( PHP_INT_MAX, 0 ) );
		$this->assertSame( PHP_INT_MAX, StorageScaleAdmission::checked_multiply( PHP_INT_MAX, 1 ) );
		$this->assertSame( 0, StorageScaleAdmission::checked_multiply( PHP_INT_MAX, 0 ) );
		foreach ( array( array( PHP_INT_MAX, 1 ), array( -1, 0 ), array( 1.0, 0 ) ) as $args ) {
			try { StorageScaleAdmission::checked_add( $args[0], $args[1] ); $this->fail(); }
			catch ( \RuntimeException $e ) { $this->assertNotEmpty( $e->getMessage() ); }
		}
		$this->expectException( \RuntimeException::class ); StorageScaleAdmission::checked_multiply( PHP_INT_MAX, 2 );
	}
	public function test_unknown_memory_samples_are_refused(): void {
		foreach ( array( array( 'unknown', 0 ), array( '128M', null ), array( '128M', 0.0 ), array( '128M', -1 ), array( '128M', 0, 0 ) ) as $sample ) {
			$calls = 0;
			$b = StorageScaleAdmissionTest::admission( null, null, function () use ( &$calls, $sample ) { return ++$calls === 1 ? array( '128M', 0 ) : $sample; } );
			try { $b->admit_additional( 0 ); $this->fail(); }
			catch ( \RuntimeException $e ) { $this->assertNotEmpty( $e->getMessage() ); }
		}
	}
	public function test_original20k_descriptor_observes_bytes_without_row_or_tag_hydration(): void {
		$v = StorageA7Fixture::value( 20000 );
		$b = StorageScaleAdmissionTest::admission(); $spy = new FootprintNoHydrationCodec( 1048576, 64, 10000, $b );
		$c = new LegacyRecordCodec( $spy, 24576, 16777216, $b );
		$d = $c->describe_tree( 'gallery-009', $v['galleries']['items']['gallery-009'], 'retaining-row' );
		$this->assertSame( 20208, $d['records'] ); $this->assertSame( 15070354, $d['encoded_bytes'] );
		$this->assertSame( 0, $spy->calls ); $this->assertNull( $d['additional_allocation_bytes'] );
	}
	public function test_descriptor_matches_split_bytes_without_encoding_or_decoding(): void {
		$spy = new FootprintNoHydrationCodec(); $codec = new LegacyRecordCodec( $spy );
		$record = array( 'children' => array( 'a' => null, 7 => array( 'title' => "\xff\0/", 'progress' => 1.5 ) ), 'title' => 'root', 'progress' => true, 9 => array( '', false, -2 ) );
		$d = $codec->describe_tree( 'root', $record, 'retaining-row' );
		$this->assertSame( 0, $spy->calls );
		$rows = array(); $real = new LegacyRecordCodec();
		$s = $real->flatten_tree( 'galleries', 'root', $record, 0, function ( $row ) use ( &$rows ) { $rows[] = $row; } );
		$this->assertSame( $s['record_count'], $d['records'] );
		$this->assertSame( $s['payload_bytes'], $d['payload_bytes'] );
		$this->assertSame( $s['encoded_bytes'], $d['encoded_bytes'] );
		$this->assertNull( $d['additional_allocation_bytes'] );
		$this->assertSame( 3, $d['retained_output_rows'] );
		$sink = $codec->describe_tree( 'root', $record, 'repository-sink' );
		$this->assertSame( 0, $sink['retained_output_rows'] );
		$this->assertSame( $d['encoded_bytes'], $sink['encoded_bytes'] );
		$r = $codec->describe_rows( $rows, 'caller-rows-rebuild' );
		$this->assertSame( $s['encoded_bytes'], $r['encoded_bytes'] );
		$this->assertSame( 3, $r['node_index_entries'] );
		$this->assertSame( 2, $r['adjacency_entries'] );
		$this->assertSame( 0, $spy->calls );
	}
	public function test_many_tiny_nodes_width_depth_and_nonfinite_values(): void {
		$c = new LegacyRecordCodec(); $tree = array( 'children' => array_fill( 0, 100, array( 'x' => '' ) ) );
		$d = $c->describe_tree( 'root', $tree, 'repository-sink' );
		$this->assertSame( 101, $d['records'] );
		$this->assertSame( 100, $d['max_siblings'] );
		$this->assertSame( 2, $d['active_row_frames'] );
		$this->assertGreaterThan( 1000, $d['tagged_arrays'] );
		foreach ( array( new \stdClass(), INF, NAN ) as $bad ) {
			try { $c->describe_tree( 'root', $bad, 'repository-sink' ); $this->fail(); }
			catch ( \RuntimeException $e ) { $this->assertNotEmpty( $e->getMessage() ); }
		}
		$deep = null; for ( $i = 0; $i < 66; ++$i ) { $deep = array( $deep ); }
		$this->expectException( \RuntimeException::class ); $c->describe_tree( 'root', $deep, 'repository-sink' );
	}
	public function test_corruption_and_unknown_ownership_refused_before_hydration(): void {
		$spy = new FootprintNoHydrationCodec(); $c = new LegacyRecordCodec( $spy );
		$rows = array(); ( new LegacyRecordCodec() )->flatten_tree( 'galleries', 'root', array( 'x' => 1 ), 0, function ( $row ) use ( &$rows ) { $rows[] = $row; } );
		$rows[0]['payload_hash'] = str_repeat( '0', 64 );
		try { $c->describe_rows( $rows, 'caller-rows-rebuild' ); $this->fail(); }
		catch ( \RuntimeException $e ) { $this->assertSame( 0, $spy->calls ); }
		$this->expectException( \RuntimeException::class ); $c->describe_tree( 'root', null, 'arbitrary-hook' );
	}
	public function test_descriptor_exact_one_over_shape_and_byte_boundaries(): void {
		$c = new LegacyRecordCodec();
		$tree = array( 'children' => array( 'kid' => array( 'plugin_name' => "\xff/", 'ID' => '007', 'progress' => -0.0 ) ), 'title' => 'x' );
		$d = $c->describe_tree( 'root', $tree, 'retaining-row' );
		$rows = array(); $s = $c->flatten_tree( 'galleries', 'root', $tree, 0, function ( $r ) use ( &$rows ) { $rows[] = $r; } );
		$this->assertSame( $s['encoded_bytes'], $d['encoded_bytes'] );
		$this->assertSame( 3, $d['logical_max_width'] );
		$exact = new LegacyRecordCodec( null, 2, $d['encoded_bytes'] );
		$this->assertSame( $d, $exact->describe_tree( 'root', $tree, 'retaining-row' ) );
		$this->assertSame( $s['encoded_bytes'], $exact->describe_rows( $rows, 'caller-rows-rebuild' )['encoded_bytes'] );
		foreach ( array( new LegacyRecordCodec( null, 1 ), new LegacyRecordCodec( null, 2, $d['encoded_bytes'] - 1 ) ) as $lower ) {
			foreach ( array( 'tree', 'rows' ) as $kind ) {
				try { if ( 'tree' === $kind ) { $lower->describe_tree( 'root', $tree, 'retaining-row' ); } else { $lower->describe_rows( $rows, 'caller-rows-rebuild' ); } $this->fail(); }
				catch ( \RuntimeException $e ) { $this->assertNotEmpty( $e->getMessage() ); }
			}
		}
		// Independent native JSON observation of the ACTUAL tagged payloads.
		$arrays = 0; $entries = 0; $nodes = 0; $raw_bytes = 0; $b64_bytes = 0;
		$count = function ( $tag ) use ( &$count, &$arrays, &$entries, &$nodes, &$raw_bytes, &$b64_bytes ) {
			++$nodes; ++$arrays;
			if ( 'string' === $tag[0] ) { $raw_bytes += strlen( base64_decode( $tag[1] ) ); }
			if ( 'string' === $tag[0] || 'float' === $tag[0] ) { $b64_bytes += strlen( $tag[1] ); }
			if ( 'array' === $tag[0] ) {
				++$arrays; $entries += count( $tag[1] ); $arrays += count( $tag[1] );
				foreach ( $tag[1] as $pair ) { $count( $pair[0] ); $count( $pair[1] ); }
			}
		};
		foreach ( $rows as $r ) { foreach ( array( 'payload', 'mutable_payload' ) as $f ) { $envelope = json_decode( $r[$f], true ); $count( $envelope['value'] ); } }
		$this->assertSame( $raw_bytes, $d['raw_string_bytes'] ); $this->assertSame( $b64_bytes, $d['base64_bytes'] );
		$this->assertSame( $arrays, $d['tagged_arrays'] ); $this->assertSame( $entries, $d['tagged_entries'] ); $this->assertSame( $nodes, $d['tagged_nodes'] );
	}
	public function test_raw_logical_shape_counts(): void {
		$d = ( new LegacyRecordCodec() )->describe_tree( 'root', array( 'x' => '' ), 'repository-sink' );
		$this->assertSame( 3, $d['logical_nodes'] ); $this->assertSame( 1, $d['logical_arrays'] );
		$this->assertSame( 1, $d['logical_entries'] ); $this->assertSame( 1, $d['logical_string_bytes'] );
		$this->assertSame( 1, $d['logical_max_width'] ); $this->assertSame( 1, $d['logical_max_depth'] );
	}
	public function test_scalar_bytes_padding_slashes_and_logical_depth_boundaries(): void {
		$c = new LegacyRecordCodec();
		foreach ( array( '', 'x', 'xy', 'xyz', "\xff\xff\xff", PHP_INT_MIN, PHP_INT_MAX, null, true, false, -0.0, 1.7976931348623157e308, array( 'children' => 'not-rows' ), array( 'ID' => null, 'plugin_name' => null ) ) as $v ) {
			$d = $c->describe_tree( 'key', $v, 'repository-sink' );
			$s = $c->flatten_tree( 'galleries', 'key', $v, 0, function () {} );
			$this->assertSame( $s['encoded_bytes'], $d['encoded_bytes'] );
		}
		$v = null; for ( $i = 0; $i < 63; ++$i ) { $v = array( $v ); }
		$this->assertSame( 64, $c->describe_tree( 'key', array( 'x' => $v ), 'repository-sink' )['logical_max_depth'] );
		$this->expectException( \RuntimeException::class ); $c->describe_tree( 'key', array( array( 'x' => $v ) ), 'repository-sink' );
	}
	public function test_unknown_coefficients_refuse_before_any_row_or_hydration(): void {
		$spy = new FootprintNoHydrationCodec(); $c = new LegacyRecordCodec( $spy ); $emitted = 0;
		$d = $c->describe_tree( 'root', array( 'children' => array( null ) ), 'repository-sink' );
		try { $this->budget()->admit_additional( $d['additional_allocation_bytes'] ); $c->flatten_tree( 'galleries', 'root', null, 0, function () use ( &$emitted ) { ++$emitted; } ); $this->fail(); }
		catch ( \RuntimeException $e ) { $this->assertSame( 0, $emitted ); $this->assertSame( 0, $spy->calls ); }
	}
	public function test_primitive_never_credits_new_sample_or_resets_work(): void {
		$used = 0; $p = StorageScaleAdmissionTest::policy(); $p['memory_ceiling_bytes'] = 33554452;
		$b = StorageScaleAdmissionTest::admission( $p, null, function () use ( &$used ) { return array( '-1', $used ); } );
		$b->admit_additional( 10 ); $used = 10;
		try { $b->admit_additional( 1 ); $this->fail( 'Realized delta credited back.' ); }
		catch ( \RuntimeException $e ) { $this->assertNotEmpty( $e->getMessage() ); }
		$work = new \ReflectionProperty( $b, 'work' ); $work->setValue( $b, 16777215 );
		$b->admit_additional( 0 );
		$this->expectException( \RuntimeException::class ); $b->admit_additional( 0 );
	}
	public function test_descriptor_and_primitive_share_deadline_work_and_snapshot_counters(): void {
		$now = 100.0; $clock = function () use ( &$now ) { return $now; };
		$p = StorageScaleAdmissionTest::policy(); $p['max_rows'] = 2;
		$b = StorageScaleAdmissionTest::admission( $p, $clock );
		$b->admit_snapshot( str_repeat( 'a', 64 ), 2, 1 );
		$b->admit_additional( 1 );
		$c = new LegacyRecordCodec( null, 2, 16777216, $b );
		$c->describe_tree( 'root', null, 'repository-sink' );
		try { $b->admit_snapshot( str_repeat( 'b', 64 ), 1, 1 ); $this->fail(); }
		catch ( \RuntimeException $e ) { $this->assertNotEmpty( $e->getMessage() ); }
		$now = 111.0;
		try { $c->describe_tree( 'root', null, 'repository-sink' ); $this->fail(); }
		catch ( \RuntimeException $e ) { $this->assertNotEmpty( $e->getMessage() ); }
		$this->expectException( \RuntimeException::class ); $b->admit_additional( 0 );
	}
}
class FootprintNoHydrationCodec extends LegacyStateCodec {
	public $calls = 0;
	public function encode( $value ) { ++$this->calls; throw new \RuntimeException( 'Unexpected encoding.' ); }
	public function encode_record_payload( $value ) { ++$this->calls; throw new \RuntimeException( 'Unexpected encoding.' ); }
	public function decode( $value ) { ++$this->calls; throw new \RuntimeException( 'Unexpected decoding.' ); }
	public function decode_record_payload( $value ) { ++$this->calls; throw new \RuntimeException( 'Unexpected decoding.' ); }
}
