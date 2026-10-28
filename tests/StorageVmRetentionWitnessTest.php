<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use PHPUnit\Framework\TestCase;

/** Source-path witnesses, NOT a PHP5 runtime or a certified arena envelope. */
class StorageVmRetentionWitnessTest extends TestCase {
	private function witness( $version ) {
		require_once __DIR__ . '/support/StorageVmRetentionWitness.php';
		return new StorageVmRetentionWitness( $version );
	}
	/** @dataProvider versions */
	public function test_carved_returned_page_requires_another_segment( $version ): void {
		$w = $this->witness( $version );
		$w->allocate( 'parent', 130968 ); $w->allocate( 'child', 130968 );
		$this->assertSame( 262144, $w->snapshot()['arena_bytes'] );
		$w->release( 'child' );
		// 8192 is larger than the small cache domain and the 144-byte tail.
		// It therefore carves the coalesced returned-page region via large search.
		$w->allocate( 'material', 8192 ); $w->allocate( 'child_again', 130968 );
		$r = $w->snapshot();
		$this->assertSame( 2, $r['live_vm_pages'] );
		$this->assertSame( 2, $r['peak_live_vm_pages'] );
		$this->assertSame( 3, $r['vm_allocations'] );
		$this->assertSame( 524288, $r['arena_bytes'] );
		$this->assertSame( 8208, $r['ordinary_live_bytes'] );
		$this->assertGreaterThan( 2 * 131008 + 8208, $r['arena_bytes'] );
		// Releasing the parent restores a whole default-page hole. Reuse must
		// be modeled too: the witness does NOT claim unbounded arena growth.
		$w->release( 'parent' ); $w->allocate( 'reused', 130968 );
		$this->assertSame( 524288, $w->snapshot()['arena_bytes'] );
		$w->release( 'reused' ); $w->release( 'child_again' );
		$this->assertSame( 262144, $w->snapshot()['arena_bytes'] );
		$w->release( 'material' );
		$this->assertSame( 0, $w->snapshot()['arena_bytes'] );
	}
	/** @dataProvider versions */
	public function test_uncarved_page_is_reused_without_segment_growth( $version ): void {
		$w = $this->witness( $version );
		$w->allocate( 'parent', 130968 ); $w->allocate( 'child', 130968 );
		for ( $i = 0; $i < 8; ++$i ) { $w->release( 'child' ); $w->allocate( 'child', 130968 ); }
		$r = $w->snapshot();
		$this->assertSame( 262144, $r['arena_bytes'] );
		$this->assertSame( 2, $r['peak_live_vm_pages'] );
		$this->assertSame( 10, $r['vm_allocations'] );
	}
	/** @dataProvider versions */
	public function test_repeated_reservation_checks_do_not_count_retained_top_as_reservation( $version ): void {
		$w = $this->witness( $version );
		// Hypothetical R/C descriptors, NOT compiler coefficients for the helper.
		$gap = $w->repeated_child( 16384, 32768, '5.6.20' === $version ? 16384 : 32768, 8 );
		$this->assertSame( 8, $gap['page_acquisitions'] );
		$this->assertSame( 1, $gap['peak_live_child_pages'] );
		$this->assertSame( 0, $w->repeated_child( 32768, 32768, 32768, 8 )['page_acquisitions'] );
	}
	/** @dataProvider versions */
	public function test_candidate_exposes_fixed_helper_reservation_obligation_without_certifying_fit( $version ): void {
		require_once __DIR__ . '/support/StoragePhasePeakCandidate.php';
		$d = array( 'source_version' => $version, 'abi' => 'lp64-nts-nondebug', 'allocator' => 'zend-mm',
			'segment_bytes' => 262144, 'driver' => 'none', 'precision' => 14, 'serialize_precision' => 17 );
		$b = array( 'integer_max' => PHP_INT_MAX, 'limit_bytes' => 134217728, 'baseline_bytes' => 16777216, 'reserve_bytes' => 8388608 );
		$c = array( 'caller_frames' => 32, 'caller_arguments' => 8, 'metadata_bytes' => 1024, 'argument_string_bytes' => 1024,
			'primitive_caller_arguments' => true, 'hooks_disabled' => true, 'helpers_loaded' => true,
			'pcre_patterns_prepared' => true, 'object_store_free_slots' => 1 );
		$r = StoragePhasePeakCandidate::option_headroom( 'a:0:{}', $d, $b, $c );
		$this->assertArrayHasKey( 'vm_retention_obligation', $r );
		$this->assertSame( 'fixed_helper_reservation_and_retained_top_unresolved', $r['vm_retention_obligation']['status'] );
		$this->assertSame( array( 'decode', 'scan', 'unsigned', 'token', 'literal', 'fail' ), $r['vm_retention_obligation']['methods'] );
		$this->assertSame( '5.6.20' === $version, $r['vm_retention_obligation']['reservation_includes_released_argument_tail'] );
		$this->assertFalse( $r['vm_retention_obligation']['historical_arena_bound_proven'] );
		$this->assertFalse( $r['admitted'] ); $this->assertFalse( $r['fits_justified_helper_budget'] );
		$this->assertContains( 'exceptional_VM_page_retention', $r['missing_headroom_terms'] );
	}
	public function test_witness_does_not_silently_model_small_cache_or_oversized_pages(): void {
		$w = $this->witness( '5.6.20' );
		foreach ( array( 32, 131072 ) as $request ) {
			try { $w->allocate( 'outside', $request ); $this->fail( 'Unsupported allocator path.' ); }
			catch ( \InvalidArgumentException $error ) { $this->assertSame( 'witness_domain', $error->getMessage() ); }
		}
	}
	public static function versions(): array { return array( array( '5.4.0' ), array( '5.6.20' ) ); }
}
