<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\StorageScaleAdmission;
use FooPlugins\FooGalleryMigrate\LegacyOptionDecoder;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/StorageA7Fixture.php';
require_once __DIR__ . '/LegacyOptionDecoderTest.php';

class StorageScaleAdmissionTest extends TestCase {
	public static function policy() {
		return array( 'version' => 1, 'max_tokens' => 262144, 'max_rows' => 24576, 'max_bytes' => 16777216,
			'max_depth' => 64, 'max_heads' => 100, 'max_physical_rows_per_table' => 24576,
			'max_physical_rows' => 49152, 'max_physical_bytes' => 33554432,
			'memory_ceiling_bytes' => 2147483647, 'max_seconds' => 10 );
	}
	public static function decode_policy() {
		return array( 'max_raw_bytes' => 8388608, 'amplification' => 8, 'reserve_bytes' => 33554432 );
	}
	public static function admission( $limits = null, $clock = null, $memory = null ) {
		return new StorageScaleAdmission( null === $limits ? self::policy() : $limits, self::decode_policy(),
			(null === $clock ? microtime( true ) : call_user_func( $clock )) + 10.0, $clock, $memory );
	}
	public function test_original_20k_decode_encode_exact_raw_and_logical_parity(): void {
		$value = StorageA7Fixture::value( 20000 ); $raw = serialize( $value );
		try { ( new LegacyOptionDecoder() )->decode( $raw ); $this->fail( 'Default admission unexpectedly raised.' ); }
		catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'Unsupported', $error->getMessage() ); }
		$budget = self::admission();
		$decoder = $budget->decoder( strlen( $raw ) );
		$this->assertSame( $raw, serialize( $decoder->decode( $raw ) ) );
		$this->assertSame( $raw, $decoder->encode( $value, function () {} ) );
	}
	public function test_policy_rejects_unknown_missing_noninteger_and_excess_fields(): void {
		foreach ( self::policy() as $key => $value ) {
			foreach ( array( null, (string) $value, 0, PHP_INT_MAX ) as $bad ) {
				$p = self::policy(); if ( null === $bad ) { unset( $p[$key] ); } else { $p[$key] = $bad; }
				try { self::admission( $p ); $this->fail( 'Invalid scale admitted: ' . $key ); }
				catch ( \RuntimeException $error ) { $this->assertNotEmpty( $error->getMessage() ); }
			}
		}
		$p = self::policy(); $p['request_override'] = true;
		$this->expectException( \RuntimeException::class ); self::admission( $p );
	}
	public function test_no_naked_decoder_cap_raise(): void {
		$this->expectException( \RuntimeException::class ); new LegacyOptionDecoder( 8388608, 64, 262144 );
	}
	public function test_finite_memory_even_with_unlimited_php_and_overflow_refusal(): void {
		$p = self::policy(); $p['memory_ceiling_bytes'] = 33554433;
		$memory = function () { return array( '-1', 1 ); };
		$b = self::admission( $p, null, $memory );
		try { $b->admit_memory( 1 ); $this->fail( 'Unlimited memory bypassed finite policy.' ); }
		catch ( \RuntimeException $error ) { $this->assertNotEmpty( $error->getMessage() ); }
		$this->expectException( \RuntimeException::class ); self::admission()->admit_memory( PHP_INT_MAX );
	}
	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState enabled
	 */
	public function test_original_20k_tree_roundtrip_and_shared_mid_traversal_deadline(): void {
		// Unrelated suite allocator backing must not preempt this finite deadline scenario.
		// Preserve parent globals/INI (including its finite budget), not allocator backing.
		$value = StorageA7Fixture::value( 20000 ); $tree = $value['galleries']['items']['gallery-009'];
		$budget = self::admission();
		$codec = new \FooPlugins\FooGalleryMigrate\LegacyRecordCodec( null, 24576, 16777216, $budget );
		$rows = array();
		$stats = $codec->flatten_tree( 'galleries', 'gallery-009', $tree, 0, function ( $row ) use ( &$rows ) { $rows[] = $row; } );
		$this->assertSame( 20208, $stats['record_count'] );
		$this->assertSame( 15070354, $stats['encoded_bytes'] );
		$this->assertSame( serialize( $tree ), serialize( $codec->rebuild_tree( 'galleries', $rows[0]['node_hash'], $rows ) ) );
		$now = 100.0; $calls = 0;
		$clock = function () use ( &$now, &$calls ) { if ( ++$calls > 40 ) { $now = 111.0; } return $now; };
		$b = self::admission( null, $clock ); $emitted = 0;
		$codec = new \FooPlugins\FooGalleryMigrate\LegacyRecordCodec( null, 24576, 16777216, $b );
		try { $codec->flatten_tree( 'galleries', 'gallery-009', $tree, 0, function () use ( &$emitted ) { ++$emitted; } ); $this->fail( 'No shared deadline during preflight.' ); }
		catch ( \RuntimeException $error ) { $this->assertSame( 0, $emitted ); $this->assertGreaterThan( 40, $calls ); }
	}
	public function test_independent_token_depth_raw_byte_and_head_exact_plus_one_limits(): void {
		$value = array( 'key' => array( 'value' => 'x' ) ); $raw = serialize( $value );
		$p = self::policy(); $p['max_tokens'] = 5; $p['max_depth'] = 2; $p['max_heads'] = 1;
		$b = self::admission( $p ); $decoder = $b->decoder( strlen( $raw ) );
		$this->assertSame( $raw, serialize( $decoder->decode( $raw ) ) );
		$this->assertSame( $raw, $decoder->encode( $value, function () {} ) );
		foreach ( array( 'max_tokens' => 4, 'max_depth' => 1 ) as $key => $limit ) {
			$lower = $p; $lower[$key] = $limit;
			foreach ( array( 'decode', 'encode' ) as $method ) {
				try { $subject = self::admission( $lower )->decoder( strlen( $raw ) );
					if ( 'decode' === $method ) { $subject->decode( $raw ); } else { $subject->encode( $value, function () {} ); }
					$this->fail( $key . ' exceeded.' );
				} catch ( \RuntimeException $error ) { $this->assertNotEmpty( $error->getMessage() ); }
			}
		}
		foreach ( array( serialize( array( 'a' => null, 'b' => null ) ), $raw . 'x' ) as $bad ) {
			try { $decoder->decode( $bad ); $this->fail( 'Heads or bytes exceeded.' ); }
			catch ( \RuntimeException $error ) { $this->assertNotEmpty( $error->getMessage() ); }
		}
		try { $b->decoder( strlen( $raw ) - 1 )->decode( $raw ); $this->fail(); }
		catch ( \RuntimeException $error ) { $this->assertNotEmpty( $error->getMessage() ); }
	}
	public function test_finite_row_byte_boundaries_and_low_memory_emit_nothing(): void {
		$tree = array( 'object_type' => 'gallery', 'children' => array( 'x' => array( 'object_type' => 'image' ) ) );
		$rows = array(); $p = self::policy(); $p['max_rows'] = 2;
		$codec = new \FooPlugins\FooGalleryMigrate\LegacyRecordCodec( null, 2, 16777216, self::admission( $p ) );
		$stats = $codec->flatten_tree( 'galleries', 'key', $tree, 0, function ( $row ) use ( &$rows ) { $rows[] = $row; } );
		$p['max_bytes'] = $stats['encoded_bytes'];
		$exact = new \FooPlugins\FooGalleryMigrate\LegacyRecordCodec( null, 2, $p['max_bytes'], self::admission( $p ) );
		$this->assertSame( $stats, $exact->flatten_tree( 'galleries', 'key', $tree, 0, function () {} ) );
		$this->assertSame( $tree, $exact->rebuild_tree( 'galleries', $rows[0]['node_hash'], $rows ) );
		foreach ( array( 'max_rows', 'max_bytes' ) as $key ) {
			$lower = $p; --$lower[$key]; $emitted = 0;
			$limited = new \FooPlugins\FooGalleryMigrate\LegacyRecordCodec( null, $lower['max_rows'], $lower['max_bytes'], self::admission( $lower ) );
			try { $limited->flatten_tree( 'galleries', 'key', $tree, 0, function () use ( &$emitted ) { ++$emitted; } ); $this->fail( $key . ' exceeded.' ); }
			catch ( \RuntimeException $error ) { $this->assertSame( 0, $emitted ); }
			try { $limited->rebuild_tree( 'galleries', $rows[0]['node_hash'], $rows ); $this->fail( $key . ' readback exceeded.' ); }
			catch ( \RuntimeException $error ) { $this->assertNotEmpty( $error->getMessage() ); }
		}
		$large = StorageA7Fixture::value( 20000 ); $emitted = 0;
		$memory = function () { return array( '128M', 33554432 ); };
		$limited = new \FooPlugins\FooGalleryMigrate\LegacyRecordCodec( null, 24576, 16777216, self::admission( null, null, $memory ) );
		try { $limited->flatten_tree( 'galleries', 'gallery-009', $large['galleries']['items']['gallery-009'], 0, function () use ( &$emitted ) { ++$emitted; } ); $this->fail( 'Low memory emitted rows.' ); }
		catch ( \RuntimeException $error ) { $this->assertSame( 0, $emitted ); }
	}
	public function test_scale_keeps_forbidden_tokens_aliases_and_malformed_input_refused(): void {
		$decoder = self::admission()->decoder( 8388608 );
		foreach ( LegacyOptionDecoderTest::refused_payloads() as $case ) {
			try { $decoder->decode( $case[0] ); $this->fail( 'Unsupported token admitted.' ); }
			catch ( \RuntimeException $error ) { $this->assertNotEmpty( $error->getMessage() ); }
		}
		$item = array( 'x' ); $aliases = array( &$item, &$item );
		$this->expectException( \RuntimeException::class ); $decoder->encode( $aliases, function () {} );
	}
	public function test_policy_copies_references_and_rejects_unshared_codec_and_bad_deadlines(): void {
		$p = self::policy(); $tokens = 10; $p['max_tokens'] =& $tokens;
		$budget = self::admission( $p ); $tokens = PHP_INT_MAX;
		$this->assertSame( 10, $budget->limit( 'max_tokens' ) );
		$clock = function () { return 100.0; };
		foreach ( array( 100, 100.0, 111.0, INF, NAN ) as $deadline ) {
			try { new StorageScaleAdmission( self::policy(), self::decode_policy(), $deadline, $clock ); $this->fail( 'Invalid deadline.' ); }
			catch ( \RuntimeException $error ) { $this->assertNotEmpty( $error->getMessage() ); }
		}
		$this->expectException( \RuntimeException::class );
		new \FooPlugins\FooGalleryMigrate\LegacyRecordCodec( new \FooPlugins\FooGalleryMigrate\LegacyStateCodec(), 24576, 16777216, self::admission() );
	}
	public function test_rebuild_deadline_mid_metadata_pass_and_multiplication_overflow(): void {
		$value = StorageA7Fixture::value( 1000 ); $rows = array();
		$codec = new \FooPlugins\FooGalleryMigrate\LegacyRecordCodec( null, 24576, 16777216, self::admission() );
		$codec->flatten_tree( 'galleries', 'gallery-009', $value['galleries']['items']['gallery-009'], 0, function ( $row ) use ( &$rows ) { $rows[] = $row; } );
		$calls = 0;
		$clock = function () use ( &$calls ) { return ++$calls > 40 ? 111.0 : 100.0; };
		$limited = new \FooPlugins\FooGalleryMigrate\LegacyRecordCodec( null, 24576, 16777216, self::admission( null, $clock ) );
		try { $limited->rebuild_tree( 'galleries', $rows[0]['node_hash'], $rows ); $this->fail( 'Rebuild traversal deadline was not polled.' ); }
		catch ( \RuntimeException $error ) { $this->assertGreaterThan( 40, $calls ); }
		$policy = self::decode_policy(); $policy['amplification'] = PHP_INT_MAX; $policy['reserve_bytes'] = 1;
		$clock = function () { return 100.0; };
		$budget = new StorageScaleAdmission( self::policy(), $policy, 110.0, $clock, function () { return array( '-1', 0 ); } );
		$this->expectException( \RuntimeException::class ); $budget->admit_memory( 2 );
	}
	public function test_deadline_exhausts_during_real_traversal_not_only_at_entry(): void {
		$now = 100.0; $calls = 0;
		$clock = function () use ( &$now, &$calls ) { if ( ++$calls > 40 ) { $now = 111.0; } return $now; };
		$b = self::admission( null, $clock );
		try { $b->decoder( 8388608 )->decode( serialize( array( array_fill( 0, 1000, null ) ) ) ); $this->fail( 'Expired traversal admitted.' ); }
		catch ( \RuntimeException $error ) { $this->assertGreaterThan( 40, $calls ); $this->assertStringContainsString( 'scale', $error->getMessage() ); }
	}
}
