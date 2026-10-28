<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\LegacyOptionDecoder;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/StorageFixedHelperBounds.php';
require_once __DIR__ . '/support/StorageScannerTraceWitness.php';

class StorageScannerTraceWitnessTest extends TestCase {
	/** @group optional_host_diagnostic */
	public function test_nonreference_shared_noninterned_raw_is_copied_on_both_retained_sources(): void {
		foreach ( array( '5.4.0', '5.6.20' ) as $version ) {
			$m = new StorageScannerTraceWitness( $version );
			$r = $m->argument( 60000, 2, false, false );
			$this->assertTrue( $r['new_box'] );
			$this->assertSame( 60001, $r['new_backing_request'] );
			$this->assertSame( 60024, $r['nominal_block_bytes'] );
			$this->assertFalse( $r['covered_by_short_backing_premise'] );
			$this->assertSame( 'exception_trace_capture', $r['phase'] );
		}
	}

	/** @group optional_host_diagnostic */
	public function test_existing_reference_unshared_and_interned_controls_do_not_invent_backing_copies(): void {
		foreach ( array( '5.4.0', '5.6.20' ) as $version ) {
			$m = new StorageScannerTraceWitness( $version );
			foreach ( array( 1, 2, 19 ) as $count ) {
				foreach ( array( false, true ) as $reference ) {
					foreach ( array( false, true ) as $interned ) {
						$r = $m->argument( 60000, $count, $reference, $interned );
						$separate = ! $reference && $count > 1;
						$this->assertSame( $separate, $r['new_box'] );
						$this->assertSame( $separate && ! $interned ? 60001 : 0, $r['new_backing_request'] );
					}
				}
			}
		}
	}

	/** @group optional_host_diagnostic */
	public function test_short_backing_equality_and_just_over_do_not_mean_headroom_acceptance(): void {
		foreach ( array( '5.4.0', '5.6.20' ) as $version ) {
			$m = new StorageScannerTraceWitness( $version );
			$this->assertTrue( $m->argument( 135, 2, false, false )['covered_by_short_backing_premise'] );
			$this->assertFalse( $m->argument( 136, 2, false, false )['covered_by_short_backing_premise'] );
			$r = $m->argument( 128, 2, false, false );
			$this->assertSame( 129, $r['new_backing_request'] );
			$this->assertSame( 152, $r['nominal_block_bytes'] );
			$report = $m->report();
			$this->assertFalse( $report['arena_retention_proven'] );
			$this->assertFalse( $report['fits_justified_helper_budget'] );
			$this->assertContains( 'complete_trace_capacity_family', $report['missing_terms'] );
			$this->assertSame( 16, $report['accepted_context']['depth'] );
			$this->assertSame( 64, $report['separate_rejection_diagnostic_depth'] );
		}
	}

	/** @group optional_host_diagnostic */
	public function test_raw_copy_can_split_a_free_page_hole_but_is_not_a_new_arena_upper_bound(): void {
		foreach ( array( '5.4.0', '5.6.20' ) as $version ) {
			$r = ( new StorageScannerTraceWitness( $version ) )->report();
			$this->assertSame( 70960, $r['conditional_page_hole']['remainder_bytes'] );
			$this->assertFalse( $r['conditional_page_hole']['remainder_can_fit_vm_block'] );
			$this->assertFalse( $r['conditional_page_hole']['proves_actual_placement'] );
			$this->assertFalse( $r['conditional_page_hole']['proves_segment_growth'] );
		}
	}

	/** @group optional_host_diagnostic */
	public function test_inventory_keeps_aliases_repeating_material_and_fixed_caches_distinct(): void {
		foreach ( array( '5.4.0', '5.6.20' ) as $version ) {
			$r = ( new StorageScannerTraceWitness( $version ) )->report();
			$this->assertSame( 0, $r['ordinary_sites']['raw_alias']['new_backing_request'] );
			$this->assertSame( 2, $r['ordinary_sites']['byte_index']['request_upper'] );
			$this->assertSame( 129, $r['ordinary_sites']['token_substring']['request_upper'] );
			$this->assertSame( 3, $r['ordinary_sites']['literal_substring']['request_upper'] );
			$this->assertSame( 23, $r['ordinary_sites']['scalar_cast_and_concat']['request_upper'] );
			$this->assertSame( 6, count( $r['fixed_method_cache_requests'] ) );
			$this->assertSame( 880, array_sum( $r['fixed_method_cache_requests'] ) );
			$this->assertSame( 344952, $r['inherited_payload_inventory_not_charge'] );
			$this->assertSame( 1120, $r['inherited_cache_inventory_not_charge'] );
			$this->assertFalse( $r['ordinary_inventory_complete_allocator_charge'] );
		}
	}

	/** @group optional_host_diagnostic */
	public function test_source_bindings_are_specific_and_changed_helper_is_refused(): void {
		foreach ( array( '5.4.0', '5.6.20' ) as $version ) {
			$m = new StorageScannerTraceWitness( $version );
			$this->assertCount( 4, $m->bindings()['trace_sources'] );
			$this->assertSame( ( new StorageFixedHelperBounds( $version ) )->bindings()['helper'], $m->bindings()['helper_sha256'] );
		}
		$this->expectException( \InvalidArgumentException::class );
		new StorageScannerTraceWitness( '5.4.0', str_repeat( '0', 64 ) );
	}

	/** @dataProvider invalid_arguments */
	public function test_out_of_domain_inputs_refuse( $bytes, $refs, $reference, $interned ): void {
		$this->expectException( \InvalidArgumentException::class );
		( new StorageScannerTraceWitness( '5.4.0' ) )->argument( $bytes, $refs, $reference, $interned );
	}
	public static function invalid_arguments(): array {
		return array( array( -1, 2, false, false ), array( 60001, 2, false, false ),
			array( '128', 2, false, false ), array( 128, 0, false, false ), array( 128, 2.0, false, false ),
			array( 128, 2, 0, false ), array( 128, 2, false, 0 ) );
	}

	public function test_modern_scanner_site_receipts_cover_ordinary_dense_nested_sibling_and_malformed_inputs(): void {
		// Behavioral site evidence, NOT PHP5 separation/allocator execution.
		$decoder = new LegacyOptionDecoder( 60000, 16, 8003 );
		$dense = array( array_fill( 0, 4000, null ) );
		$nested = null;
		for ( $i = 0; $i < 16; ++$i ) { $nested = array( $nested ); }
		foreach ( array( array( 1, -2, 1.25, 'binary' => "\0\xff" ), $dense, $nested,
			array( array( 'a' ), array( 'b' ), array( 'c' ) ) ) as $value ) {
			$raw = serialize( $value );
			$this->assertSame( $raw, serialize( $decoder->decode( $raw ) ) );
		}
		$padding = 59000;
		for ( $i = 0; $i < 3; ++$i ) {
			$large = str_repeat( 'a:1:{i:0;', 15 ) . 'a:2:{i:0;' . serialize( str_repeat( 'x', $padding ) ) . 'i:1;O:8:"stdClass":0:{}}' . str_repeat( '}', 15 );
			$padding += 60000 - strlen( $large );
		}
		$this->assertSame( 60000, strlen( $large ) );
		foreach ( array( str_repeat( 'a:1:{i:0;', 16 ) . 'O:8:"stdClass":0:{}' . str_repeat( '}', 16 ), $large ) as $raw ) {
			try { $decoder->decode( $raw ); $this->fail( 'Malformed scanner accepted.' ); }
			catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'Unsupported or unbounded', $error->getMessage() ); }
			$this->assertSame( array(), $decoder->decode( 'a:0:{}' ) );
		}
	}

	public function test_actual_token_substring_accepts_equality_and_refuses_just_over_before_native_decode(): void {
		$decoder = new LegacyOptionDecoder( 60000, 16, 8003 );
		$token = new \ReflectionMethod( $decoder, 'token' );
		$offset = 0;
		$this->assertSame( str_repeat( '1', 128 ), $token->invokeArgs( $decoder, array( str_repeat( '1', 128 ) . ';', &$offset, ';', 128 ) ) );
		$this->assertSame( 129, $offset );
		$offset = 0;
		$this->expectException( \RuntimeException::class );
		$token->invokeArgs( $decoder, array( str_repeat( '1', 129 ) . ';', &$offset, ';', 128 ) );
	}
}
