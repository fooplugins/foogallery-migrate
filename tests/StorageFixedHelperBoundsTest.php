<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\LegacyOptionDecoder;
use PHPUnit\Framework\TestCase;

/** Offline source descriptors/transition witnesses, not literal runtime credit. */
class StorageFixedHelperBoundsTest extends TestCase {
	private function model( $version = '5.4.0' ) {
		require_once __DIR__ . '/support/StorageFixedHelperBounds.php';
		return new StorageFixedHelperBounds( $version );
	}
	public static function versions(): array { return array( array( '5.4.0' ), array( '5.6.20' ) ); }
	/** @dataProvider versions
	 * @group optional_host_diagnostic
	 */
	public function test_six_fixed_source_descriptors_have_evaluated_distinct_reservations( $version ): void {
		$m = $this->model( $version ); $d = $m->descriptors();
		$this->assertSame( array( 'decode', 'scan', 'unsigned', 'token', 'literal', 'fail' ), array_keys( $d ) );
		$this->assertSame( hash_file( 'sha256', dirname( __DIR__ ) . '/includes/class-legacy-option-decoder.php' ), $m->bindings()['helper'] );
		$this->assertCount( 4, $m->bindings()['sources'] );
		foreach ( $d as $method => $r ) {
			$this->assertSame( array_sum( $r['temporary_sites'] ), $r['T_upper'] );
			$this->assertSame( 0, $r['R_upper'] % 8 );
			$this->assertLessThan( 130944, $r['R_upper'] );
			$this->assertSame( '5.6.20' === $version ? 8 * $r['argument_slots_upper'] : 0, $r['R_upper'] - $r['C_upper'] );
		}
		$this->assertSame( 11, $d['scan']['CV_upper'] );
		$this->assertSame( 2, $d['literal']['call_slots_upper'] );
	}
	/** @dataProvider versions
	 * @group optional_host_diagnostic
	 */
	public function test_frame_residual_equality_just_over_and_repeated_siblings( $version ): void {
		$m = $this->model( $version );
		foreach ( $m->descriptors() as $name => $d ) {
			$this->assertSame( 0, $m->frame( $name, $d['R_upper'] )['acquired_pages'] );
			$this->assertSame( 1, $m->frame( $name, $d['R_upper'] - 8 )['acquired_pages'] );
			$max = 0;
			for ( $free = 0; $free <= 130944; $free += 8 ) {
				$r = $m->frame( $name, $free );
				$max = max( $max, $r['acquired_pages'] );
				if ( $r['acquired_pages'] && $free >= $d['R_upper'] ) { $this->fail( 'Incorrect source growth predicate.' ); }
			}
			$this->assertSame( 1, $max );
		}
		$r = $m->path( array( 'decode', 'scan', 'unsigned', 'token', 'fail' ), 0, 8 );
		$this->assertSame( 8, $r['iterations'] );
		$this->assertSame( $r['per_iteration_acquisitions'] * 8, $r['acquisitions'] );
		$this->assertSame( 0, $r['remaining_added_pages'] );
	}
	/** @group optional_host_diagnostic */
	public function test_php54_arguments_transfer_a_split_pack_and_free_its_page(): void {
		$m = $this->model();
		// An argument push consumes the final parent slot; remaining args cross
		// to a new page, then push_args transfers the split pack to ANOTHER page.
		$r = $m->arguments54( 8, 5 );
		$this->assertSame( 2, $r['acquisitions'] );
		$this->assertSame( 2, $r['peak_added_pages'] );
		$this->assertSame( 1, $r['freed_during_transfer'] );
		$this->assertSame( 48, $r['argument_bytes'] );
		$this->assertSame( 0, $r['remaining_added_pages'] );
		$this->assertSame( 0, $m->arguments54( 48, 5 )['acquisitions'] );
		$this->assertSame( 1, $m->arguments54( 40, 5 )['acquisitions'] );
	}
	/** @group optional_host_diagnostic */
	public function test_php54_argument_boundaries_across_all_parent_residuals(): void {
		$m = $this->model(); $peak = 0;
		for ( $argc = 0; $argc <= 5; ++$argc ) {
			for ( $free = 0; $free <= 130944; $free += 8 ) {
				$r = $m->arguments54( $free, $argc ); $peak = max( $peak, $r['peak_added_pages'] );
				if ( $r['remaining_added_pages'] || $r['acquisitions'] > 2 ) { $this->fail( 'Argument transfer did not unwind.' ); }
			}
		}
		$this->assertSame( 2, $peak );
	}
	/** @dataProvider versions
	 * @group optional_host_diagnostic
	 */
	public function test_deep_failure_and_sibling_paths_sweep_every_parent_residual( $version ): void {
		$m = $this->model( $version ); $peak = 0;
		foreach ( array( 16, 64 ) as $depth ) {
			$bound = $m->envelope( $depth )['transfer_added_pages_upper'];
			$path = array_merge( array( 'decode' ), array_fill( 0, $depth + 1, 'scan' ), array( 'unsigned', 'token', 'fail' ) );
			for ( $free = 0; $free <= 130944; $free += 8 ) {
				$r = $m->path( $path, $free ); $peak = max( $peak, $r['peak_added_pages'] );
				if ( $r['remaining_added_pages'] || $r['peak_added_pages'] > $bound ) { $this->fail( 'Live page bound or unwind failed.' ); }
			}
		}
		$this->assertGreaterThan( 0, $peak );
	}
	/** @dataProvider versions
	 * @group optional_host_diagnostic
	 */
	public function test_actual_maximum_depth_graph_and_failure_chain_are_bounded_not_certified( $version ): void {
		$m = $this->model( $version );
		$r = $m->envelope( 16 );
		$this->assertGreaterThan( 0, $r['stack_payload_upper'] );
		$this->assertLessThan( 130944, $r['largest_reservation_upper'] );
		$this->assertFalse( $r['arena_retention_proven'] );
		$this->assertFalse( $r['fits_justified_helper_budget'] );
		$this->assertContains( 'scanner_box_and_cache_lowering', $r['missing_terms'] );
		$this->assertContains( 'complete_trace_capacity_family', $r['missing_terms'] );
		// Modern execution of the EXISTING helper, not either legacy runtime.
		foreach ( array( 16, 64 ) as $depth ) {
			$decoder = new LegacyOptionDecoder( 16777216, $depth ); $raw = 'i:1;';
			for ( $i = 0; $i < $depth; ++$i ) { $raw = 'a:1:{i:0;' . $raw . '}'; }
			$this->assertIsArray( $decoder->decode( $raw ) );
			foreach ( array( str_replace( 'i:1;', 'O:0:', $raw ), 'a:1:{i:0;' . $raw . '}' ) as $bad ) {
				try { $decoder->decode( $bad ); $this->fail( 'Malformed or over-depth token admitted.' ); }
				catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'Retain recorded authority', $error->getMessage() ); }
			}
			$this->assertSame( array(), $decoder->decode( 'a:0:{}' ) );
		}
	}
	/** @group optional_host_diagnostic */
	public function test_fixed_hash_and_call_graph_refuse_new_constructs(): void {
		$m = $this->model();
		foreach ( array( array( 'encode' ), array( 'scan', 'decode' ) ) as $path ) {
			try { $m->path( $path, 0 ); $this->fail( 'Uncovered construct admitted.' ); }
			catch ( \InvalidArgumentException $error ) { $this->assertSame( 'fixed_helper_domain', $error->getMessage() ); }
		}
		$this->expectException( \InvalidArgumentException::class );
		new StorageFixedHelperBounds( '5.5.0' );
	}
	/** @group optional_host_diagnostic */
	public function test_unreviewed_helper_bytes_are_not_silently_lowered(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->model()->assert_helper_hash( str_repeat( '0', 64 ) );
	}
	/** @dataProvider versions
	 * @group optional_host_diagnostic
	 */
	public function test_depth16_and_ceiling_inequalities_remain_separate_from_retention( $version ): void {
		$m = $this->model( $version ); $small = $m->envelope( 16 ); $large = $m->envelope( 64 );
		$this->assertLessThan( $small['noncurrent_page_minimum_fill'], $small['stack_payload_upper'] );
		$this->assertSame( 1, $small['stable_added_pages_upper'] );
		$this->assertSame( 2, $large['stable_added_pages_upper'] );
		$this->assertSame( '5.4.0' === $version ? 2 : 1, $small['transfer_added_pages_upper'] );
		$this->assertSame( '5.4.0' === $version ? 3 : 2, $large['transfer_added_pages_upper'] );
		$this->assertSame( $small['fixed_method_cache_block_cap'], $large['fixed_method_cache_block_cap'] );
		$this->assertFalse( $small['fits_justified_helper_budget'] );
	}
}
