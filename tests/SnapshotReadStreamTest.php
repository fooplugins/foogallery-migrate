<?php
namespace FooPlugins\FooGalleryMigrate\Tests;
use FooPlugins\FooGalleryMigrate\SnapshotReadProfile;
use FooPlugins\FooGalleryMigrate\SnapshotReadTraceStream;
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/SnapshotReadProfileLifecycleTest.php';

/** Transport/schema tests ONLY. Synthetic data never authorizes measured admission. */
class SnapshotReadStreamTest extends TestCase {
	public function test_old_complete_json_cap_cannot_hold_original_required_events(): void {
		list( $cell, $raw ) = SnapshotReadProfileLifecycleTest::fixture( 1 );
		$event = json_decode( $raw[0], true )['events'][0];
		$minimal = array_fill_keys( array_keys( $event ), 0 );
		$this->assertSame( 485034, 3 * ( 20208 * 4 * 2 + 7 * 2 ) );
		$this->assertGreaterThan( 16777216, 485034 * ( strlen( json_encode( $minimal ) ) + 1 ) );
		$raw[0] = str_repeat( ' ', 16777216 ) . $raw[0];
		$cell['trace_sha256'][0] = hash( 'sha256', $raw[0] );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'raw trace hash/bound' );
		SnapshotReadProfile::qualify( $cell, $raw );
	}
	public function test_legacy_trailing_null_is_not_silently_truncated(): void {
		list( $cell, $raw ) = SnapshotReadProfileLifecycleTest::fixture( 1 );
		$t = json_decode( $raw[0], true ); $t['events'][] = null;
		$raw[0] = json_encode( $t ); $cell['trace_sha256'][0] = hash( 'sha256', $raw[0] );
		$this->expectException( \RuntimeException::class ); $this->expectExceptionMessage( 'sample object' );
		SnapshotReadProfile::qualify( $cell, $raw );
	}
	public function test_nonzero_repeat_spread_allocator_margin_and_tight_capacity(): void {
		list( $cell, $raw ) = SnapshotReadProfileLifecycleTest::fixture( 2 );
		$traces = array_map( function ( $json ) { return json_decode( $json, true ); }, $raw );
		foreach ( $traces as $repeat => &$t ) {
			foreach ( $t['events'] as &$e ) {
				if ( $e['edge'] === 'after' ) { $e['residual'] = ( $repeat + 1 ) * 4096; $e['allocator_growth'] = ( $repeat + 1 ) * 1024; $e['peak'] += $e['residual']; $e['allocated'] += $e['allocator_growth']; }
			} unset( $e );
		} unset( $t );
		$cell['observer']['allocator_growth_quantum'] = 3072; $margin = 12288 + 8192 + 3072 + 1024;
		$maximum_guard = 0;
		foreach ( $traces as $t ) { foreach ( $t['events'] as $e ) { $maximum_guard = max( $maximum_guard, max( $e['allocated'], $e['observer_backing'], $e['native_high_water'] ) + $e['remaining'] + $e['private'] + $e['page'] + $e['pending'] + $e['reserve'] ); } }
		foreach ( $traces as &$t ) { foreach ( $t['events'] as &$e ) { $e['limit'] = $maximum_guard + $margin; } unset( $e ); } unset( $t );
		list( $cell, $raw ) = SnapshotReadProfileLifecycleTest::seal( $cell, $traces );
		$this->assertSame( $margin, SnapshotReadProfile::qualify( $cell, $raw )->margin() ); $paths = array();
		try {
			foreach ( $traces as $i => $t ) {
				$events = $t['events']; unset( $t['events'] ); $path = tempnam( sys_get_temp_dir(), 'snapshot-stream-' ); $paths[] = $path;
				$writer = SnapshotReadTraceStream::writer( $path, $t ); foreach ( $events as $e ) { $writer->append( $e ); } $cell['trace_sha256'][$i] = $writer->finish();
			}
			$this->assertSame( $margin, SnapshotReadProfile::qualify_files( $cell, $paths )->margin() );
			foreach ( $traces as &$t ) { foreach ( $t['events'] as &$e ) { --$e['limit']; } unset( $e ); } unset( $t );
			list( $cell, $raw ) = SnapshotReadProfileLifecycleTest::seal( $cell, $traces );
			$this->expectException( \RuntimeException::class ); $this->expectExceptionMessage( 'finite measured cell exceeds capacity' ); SnapshotReadProfile::qualify( $cell, $raw );
		} finally { foreach ( $paths as $path ) { unlink( $path ); } }
	}
	public static function invalid_cases() { return SnapshotReadProfileLifecycleTest::invalid_cases(); }
	/** @dataProvider invalid_cases */
	public function test_stream_retains_existing_lifecycle_and_arithmetic_refusals( $name ): void {
		list( $cell, $raw ) = SnapshotReadProfileLifecycleTest::counterexample( $name ); $paths = array();
		try {
			foreach ( $raw as $i => $json ) {
				$t = json_decode( $json, true ); $events = $t['events']; unset( $t['events'] );
				$path = tempnam( sys_get_temp_dir(), 'snapshot-stream-' ); $paths[] = $path;
				$writer = SnapshotReadTraceStream::writer( $path, $t );
				foreach ( $events as $e ) { $writer->append( $e ); }
				$cell['trace_sha256'][$i] = $writer->finish();
			}
			if ( $name === 'wrong-hash' ) { $cell['trace_sha256'][0] = str_repeat( '0', 64 ); }
			$this->expectException( \RuntimeException::class );
			SnapshotReadProfile::qualify_files( $cell, $paths );
		} finally { foreach ( $paths as $path ) { unlink( $path ); } }
	}
	public static function framing_cases() { return array_map( function ( $v ) { return array( $v ); }, array( 'corrupt', 'truncate', 'trailing', 'reorder', 'missing-footer', 'wrong-count', 'source', 'runtime-ini', 'connection', 'input', 'oversized-line' ) ); }
	/** @dataProvider framing_cases */
	public function test_stream_refuses_corruption_framing_and_binding( $mode ): void {
		list( $cell, $raw ) = SnapshotReadProfileLifecycleTest::fixture( 1 ); $paths = array();
		try {
			foreach ( $raw as $i => $json ) {
				$t = json_decode( $json, true ); $events = $t['events']; unset( $t['events'] );
				if ( $i === 0 && in_array( $mode, array( 'source', 'runtime-ini' ), true ) ) { $t['runtime'][$mode === 'source' ? 'product_compiler_sha256' : 'ini_sha256'] = str_repeat( '0', 64 ); }
				if ( $i === 0 && in_array( $mode, array( 'connection', 'input' ), true ) ) { $t['identity'][$mode === 'input' ? 'digest' : 'connection'] = 'changed'; }
				if ( $i === 0 && $mode === 'reorder' ) { $tmp = $events[0]; $events[0] = $events[1]; $events[1] = $tmp; }
				$path = tempnam( sys_get_temp_dir(), 'snapshot-stream-' ); $paths[] = $path;
				$writer = SnapshotReadTraceStream::writer( $path, $t ); foreach ( $events as $e ) { $writer->append( $e ); }
				$cell['trace_sha256'][$i] = $writer->finish();
			}
			$bytes = file_get_contents( $paths[0] );
			if ( $mode === 'corrupt' ) { $bytes = str_replace( '8388608', '8388609', $bytes ); }
			if ( $mode === 'truncate' ) { $bytes = substr( $bytes, 0, -1 ); }
			if ( $mode === 'trailing' ) { $bytes .= "{}\n"; }
			if ( $mode === 'missing-footer' ) { $bytes = substr( $bytes, 0, strrpos( rtrim( $bytes ), "\n" ) + 1 ); }
			if ( $mode === 'wrong-count' ) { $bytes = preg_replace( '/\{"end":\d+\}/', '{"end":0}', $bytes ); }
			if ( $mode === 'oversized-line' ) { $bytes = str_repeat( ' ', 65536 ) . $bytes; }
			file_put_contents( $paths[0], $bytes );
			// Re-seal malformed framing: schema/count/order/bindings must bite even with a valid external digest.
			if ( $mode !== 'corrupt' ) { $cell['trace_sha256'][0] = hash( 'sha256', $bytes ); }
			$this->expectException( \RuntimeException::class ); SnapshotReadProfile::qualify_files( $cell, $paths );
		} finally { foreach ( $paths as $path ) { unlink( $path ); } }
	}
	public function test_stream_matches_legacy_content_and_margin(): void {
		list( $cell, $raw ) = SnapshotReadProfileLifecycleTest::fixture( 2 );
		$expected = SnapshotReadProfile::qualify( $cell, $raw )->margin(); $paths = array();
		try {
			foreach ( $raw as $i => $json ) {
				$t = json_decode( $json, true ); $events = $t['events']; unset( $t['events'] );
				$path = tempnam( sys_get_temp_dir(), 'snapshot-stream-' ); $paths[] = $path;
				$writer = SnapshotReadTraceStream::writer( $path, $t );
				foreach ( $events as $e ) { $writer->append( $e ); }
				$cell['trace_sha256'][$i] = $writer->finish();
				$reader = SnapshotReadTraceStream::reader( $path, $cell['trace_sha256'][$i], count( $events ) );
				$this->assertSame( $t, $reader->header() ); $actual = array();
				while ( null !== ( $e = $reader->next_event() ) ) { $actual[] = $e; }
				$this->assertSame( $events, $actual );
			}
			$this->assertSame( $expected, SnapshotReadProfile::qualify_files( $cell, $paths )->margin() );
		} finally { foreach ( $paths as $path ) { unlink( $path ); } }
	}
}
