<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use PHPUnit\Framework\TestCase;
use FooPlugins\FooGalleryMigrate\LegacyOptionDecoder;

/** Offline conditional helper envelope, never public conversion admission. */
class StorageOptionHeadroomTest extends TestCase {
	private function domain() {
		return array( 'source_version' => '5.6.20', 'abi' => 'lp64-nts-nondebug', 'allocator' => 'zend-mm',
			'segment_bytes' => 262144, 'driver' => 'none', 'precision' => 14, 'serialize_precision' => 17 );
	}
	private function budget() {
		return array( 'integer_max' => PHP_INT_MAX, 'limit_bytes' => 134217728, 'baseline_bytes' => 16777216, 'reserve_bytes' => 8388608 );
	}
	private function context() {
		return array( 'caller_frames' => 32, 'caller_arguments' => 8, 'metadata_bytes' => 1024, 'argument_string_bytes' => 1024,
			'primitive_caller_arguments' => true, 'hooks_disabled' => true, 'helpers_loaded' => true,
			'pcre_patterns_prepared' => true, 'object_store_free_slots' => 1 );
	}
	private function estimate( $raw, $budget = null, $context = null, $domain = null ) {
		require_once __DIR__ . '/support/StoragePhasePeakCandidate.php';
		return StoragePhasePeakCandidate::option_headroom( $raw, null === $domain ? $this->domain() : $domain,
			null === $budget ? $this->budget() : $budget, null === $context ? $this->context() : $context );
	}
	public function test_ordinary_and_dense_and_nested_fit_both_real_baseline_budgets(): void {
		$nested = array( 'binary' => "\0\xff", 'short' => str_repeat( 's', 128 ) );
		for ( $i = 0; $i < 10; ++$i ) { $nested = array( $nested ); }
		foreach ( array( '5.4.0', '5.6.20' ) as $version ) {
			$d = $this->domain(); $d['source_version'] = $version;
			foreach ( array( array( 'records' => range( 1, 4000 ) ), array( 'records' => array_fill( 0, 2000, 'ab' ) ), $nested ) as $fixture ) {
				foreach ( array( array( 134217728, 16777216 ), array( 268435456, 67108864 ) ) as $pair ) {
					$b = $this->budget(); $b['limit_bytes'] = $pair[0]; $b['baseline_bytes'] = $pair[1];
					$r = $this->estimate( serialize( $fixture ), $b, null, $d );
					$this->assertSame( 'option_headroom_prototype_unproved', $r['status'] );
					$this->assertFalse( $r['headroom_proof_complete'] );
					$this->assertFalse( $r['fits_justified_helper_budget'] );
					$this->assertNull( $r['real_usage_peak_bytes'] );
					$this->assertTrue( $r['fits_modeled_budget'] );
					$this->assertFalse( $r['admitted'] );
					$this->assertGreaterThan( $r['modeled_peak_bytes'], $r['headroom_peak_bytes'] );
					$this->assertSame( $b['baseline_bytes'] + $r['headroom_peak_bytes'] + $b['reserve_bytes'], $r['headroom_total_bytes'] );
				}
			}
		}
	}
	public function test_duplicate_and_coercing_keys_count_before_native_parity_refusal(): void {
		foreach ( array( 'a:2:{i:0;s:3:"old";i:0;s:3:"new";}', 'a:1:{s:1:"0";N;}' ) as $raw ) {
			$r = $this->estimate( $raw );
			$this->assertFalse( $r['canonical_parity_proven'] );
			$this->assertSame( substr_count( $raw, ';' ) + 1, $r['census']['nodes'] );
			try { ( new LegacyOptionDecoder( 60000, 16, 8003 ) )->decode( $raw ); $this->fail( 'Noncanonical option was accepted.' ); }
			catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'Retain recorded authority', $error->getMessage() ); }
		}
	}
	public function test_real_helper_success_and_controlled_refusal_and_teardown(): void {
		$decoder = new LegacyOptionDecoder( 60000, 16, 8003 );
		$value = array( 'records' => range( 1, 4000 ), 'bytes' => "\0\xff" );
		// Use the exact 8003-node ordinary fixture for the successful decode.
		$raw = serialize( array( 'records' => $value['records'] ) );
		$this->assertSame( array( 'records' => $value['records'] ), $decoder->decode( $raw ) );
		unset( $value, $raw, $decoder );
		$decoder = new LegacyOptionDecoder( 60000, 16, 8003 );
		try { $decoder->decode( 'a:1:{i:0;s:3:"ab";}' ); $this->fail( 'Malformed input was accepted.' ); }
		catch ( \RuntimeException $error ) { unset( $error ); }
		$this->assertSame( array(), $decoder->decode( 'a:0:{}' ) );
	}
	public function test_exact_total_one_below_and_reserve_exhaustion(): void {
		$raw = serialize( array( 'records' => range( 1, 4000 ) ) ); $r = $this->estimate( $raw );
		$b = $this->budget(); $b['limit_bytes'] = $r['headroom_total_bytes'];
		$this->assertTrue( $this->estimate( $raw, $b )['fits_modeled_budget'] );
		--$b['limit_bytes']; $this->assertFalse( $this->estimate( $raw, $b )['fits_modeled_budget'] );
		$b['reserve_bytes'] = 1;
		$this->expectException( \InvalidArgumentException::class ); $this->estimate( $raw, $b );
	}
	/** @dataProvider refusedInputs */
	public function test_input_domain_refusal( $raw ): void {
		$this->expectException( \InvalidArgumentException::class ); $this->estimate( $raw );
	}
	public static function refusedInputs(): array {
		return array( array( serialize( array( str_repeat( 'x', 129 ) ) ) ), array( serialize( array( str_repeat( 'k', 65 ) => null ) ) ),
			array( serialize( array( 'records' => range( 1, 4001 ) ) ) ), array( serialize( array_fill( 0, 16, array() ) ) ),
			array( str_repeat( 'a:1:{i:0;', 17 ) . 'N;' . str_repeat( '}', 17 ) ),
			array( 'a:1:{i:0;d:1.25;}' ), array( 'a:0:{' ), array( str_repeat( 'x', 60001 ) ) );
	}
	/** @dataProvider refusedContexts */
	public function test_unknown_trace_hooks_and_growth_refuse( $key, $value ): void {
		$c = $this->context(); $c[$key] = $value;
		$this->expectException( \InvalidArgumentException::class ); $this->estimate( 'a:0:{}', null, $c );
	}
	public static function refusedContexts(): array {
		return array( array( 'caller_frames', 33 ), array( 'caller_arguments', 9 ), array( 'metadata_bytes', 1025 ),
			array( 'argument_string_bytes', 1025 ), array( 'primitive_caller_arguments', false ), array( 'hooks_disabled', false ),
			array( 'helpers_loaded', false ), array( 'pcre_patterns_prepared', false ), array( 'object_store_free_slots', 0 ), array( 'unknown', true ) );
	}
	public function test_checked_integer_overflow_and_unknown_runtime(): void {
		$b = $this->budget(); $b['baseline_bytes'] = PHP_INT_MAX;
		$this->expectException( \InvalidArgumentException::class ); $this->estimate( 'a:0:{}', $b );
	}
	public function test_smart_growth_and_hash_boundaries_remain_monotone(): void {
		$last = 0;
		foreach ( array( 0, 1, 7, 8, 9, 15, 16, 17, 77, 78, 79, 127, 128, 129, 255, 256, 257 ) as $count ) {
			$r = $this->estimate( serialize( array( 'records' => array_fill( 0, $count, 'ab' ) ) ) );
			$this->assertGreaterThanOrEqual( $last, $r['capacity_families']['parity_generations'] );
			$last = $r['capacity_families']['parity_generations'];
			$this->assertTrue( $r['fits_modeled_budget'] );
		}
	}
}
