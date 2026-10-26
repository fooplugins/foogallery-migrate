<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use PHPUnit\Framework\TestCase;
use FooPlugins\FooGalleryMigrate\LegacyStateCodec;

/** Candidate-only estimates, not actual public-operation or DB admission. */
class StoragePhasePeakCandidateTest extends TestCase {
	private function domain() {
		return array( 'source_version' => '5.6.20', 'abi' => 'lp64-nts-nondebug', 'allocator' => 'zend-mm',
			'segment_bytes' => 262144, 'driver' => 'none', 'precision' => 14, 'serialize_precision' => 17 );
	}
	private function budget() {
		return array( 'integer_max' => PHP_INT_MAX, 'limit_bytes' => 134217728, 'baseline_bytes' => 16777216, 'reserve_bytes' => 8388608 );
	}
	private function option( $raw, $domain = null, $budget = null ) {
		require_once __DIR__ . '/support/StoragePhasePeakCandidate.php';
		return StoragePhasePeakCandidate::option( $raw, null === $domain ? $this->domain() : $domain, null === $budget ? $this->budget() : $budget );
	}
	private function table( $raw, $domain = null, $budget = null ) {
		require_once __DIR__ . '/support/StoragePhasePeakCandidate.php';
		return StoragePhasePeakCandidate::table_json( $raw, null === $domain ? $this->domain() : $domain, null === $budget ? $this->budget() : $budget );
	}
	public function test_ordinary_dense_option_has_useful_live_capacity_estimate_not_event_segment_sum(): void {
		$r = $this->option( serialize( array( 'records' => range( 1, 4000 ) ) ) );
		$this->assertSame( 'candidate_only', $r['status'] );
		$this->assertFalse( $r['admitted'] );
		$this->assertTrue( $r['fits_modeled_budget'] );
		$this->assertSame( 8003, $r['census']['nodes'] );
		$this->assertLessThan( 33554432, $r['modeled_peak_bytes'] );
		$this->assertSame( 'phase_headroom_unjustified', $r['refusal'] );
		$this->assertArrayNotHasKey( 'policy', $r );
	}
	public function test_both_source_versions_are_explicit_models_not_runtime_approval(): void {
		$d = $this->domain(); $d['source_version'] = '5.4.0';
		$this->assertSame( '5.4.0', $this->option( serialize( array( true, null, "binary\xff" ) ), $d )['domain']['source_version'] );
	}
	public function test_duplicate_overwritten_option_values_still_count_toward_peak(): void {
		$raw = 'a:2:{i:0;s:1000:"' . str_repeat( 'x', 1000 ) . '";i:0;s:1000:"' . str_repeat( 'y', 1000 ) . '";}';
		$r = $this->option( $raw );
		$this->assertSame( 5, $r['census']['nodes'] );
		$this->assertSame( 2000, $r['census']['string_bytes'] );
		$this->assertGreaterThan( $this->option( serialize( array( str_repeat( 'y', 1000 ) ) ) )['modeled_peak_bytes'], $r['modeled_peak_bytes'] );
	}
	public function test_table_tag_graph_and_distinct_logical_cow_and_target_graphs_are_live(): void {
		$r = $this->table( ( new LegacyStateCodec() )->encode( array( 'items' => range( 1, 1000 ) ) ) );
		$this->assertTrue( $r['fits_modeled_budget'] );
		$this->assertGreaterThan( 0, $r['terms']['native_graph'] );
		$this->assertGreaterThan( $r['terms']['native_graph'], $r['phases']['verification_overlap'] );
		$this->assertFalse( $r['admitted'] );
	}
	public function test_lexically_valid_but_malformed_duplicate_json_has_no_approval(): void {
		$r = $this->table( '{"version":1,"version":1,"value":["array",[]],"value":["null"]}' );
		$this->assertSame( 12, $r['census']['tokens'] );
		$this->assertFalse( $r['admitted'] );
		$this->assertFalse( $r['syntax_validated'] );
	}
	public function test_packed_table_floats_do_not_require_decimal_native_serialization(): void {
		$r = $this->table( ( new LegacyStateCodec() )->encode( array( -0.0, 1.25 ) ) );
		$this->assertTrue( $r['fits_modeled_budget'] );
		$this->assertFalse( $r['admitted'] );
	}
	public function test_exact_modeled_boundary_and_one_byte_below_include_baseline_and_reserve(): void {
		$raw = serialize( array( range( 1, 200 ) ) ); $r = $this->option( $raw );
		$b = $this->budget(); $b['limit_bytes'] = $r['modeled_total_bytes'];
		$this->assertTrue( $this->option( $raw, null, $b )['fits_modeled_budget'] );
		--$b['limit_bytes'];
		$this->assertFalse( $this->option( $raw, null, $b )['fits_modeled_budget'] );
	}
	/** @dataProvider refusedDomains */
	public function test_unknown_domains_refuse_instead_of_inventing_costs( $key, $value ): void {
		$d = $this->domain(); $d[$key] = $value;
		$this->expectException( \InvalidArgumentException::class ); $this->option( 'a:0:{}', $d );
	}
	public static function refusedDomains(): array {
		return array( array( 'source_version', '8.5.11' ), array( 'abi', '32bit' ), array( 'allocator', 'malloc' ),
			array( 'segment_bytes', 524288 ), array( 'driver', 'mysqlnd' ), array( 'precision', 1000000 ),
			array( 'serialize_precision', -1 ), array( 'unknown', true ) );
	}
	/** @dataProvider refusedOptions */
	public function test_unsafe_options_refuse_before_any_native_decode_or_float_serialization( $raw ): void {
		$this->expectException( \InvalidArgumentException::class ); $this->option( $raw );
	}
	public static function refusedOptions(): array {
		return array( array( 'a:1:{i:0;d:1.25;}' ), array( 'a:1:{i:0;O:1:"X":0:{}}' ), array( 'a:1:{i:0;R:1;}' ),
			array( 'a:9999999999999999999999999999999999999:{}' ), array( 'a:1:{i:0;s:9:"short";}' ),
			array( 'a:0:{}tail' ), array( str_repeat( 'a:1:{i:0;', 66 ) . 'N;' . str_repeat( '}', 66 ) ) );
	}
	/** @dataProvider refusedJson */
	public function test_json_pre_rejection_boundaries_do_not_hydrate( $raw ): void {
		$this->expectException( \InvalidArgumentException::class ); $this->table( $raw );
	}
	public static function refusedJson(): array {
		return array( array( str_repeat( '[', 196 ) . str_repeat( ']', 196 ) ), array( str_repeat( '9', 1000000 ) ),
			array( '[' . str_repeat( 'true,', 40005 ) . 'false]' ), array( str_repeat( ' ', 1048577 ) ) );
	}
	public function test_depth_boundary_remains_estimate_only(): void {
		$v = null; for ( $i = 0; $i < 64; ++$i ) { $v = array( $v ); }
		$this->assertFalse( $this->option( serialize( $v ) )['admitted'] );
	}
	public function test_overflow_never_casts_float_back_to_integer(): void {
		$b = $this->budget(); $b['baseline_bytes'] = PHP_INT_MAX;
		$this->expectException( \InvalidArgumentException::class ); $this->option( 'a:0:{}', null, $b );
	}
	public function test_small_integer_ceiling_refuses_before_aggregate_overflow(): void {
		$b = $this->budget(); $b['integer_max'] = 2147483647; $b['limit_bytes'] = 2147483647;
		$b['baseline_bytes'] = 2147483600;
		$this->expectException( \InvalidArgumentException::class ); $this->option( 'a:0:{}', null, $b );
	}
	public function test_segment_rounding_is_reported_as_unjustified_not_added_as_a_fake_bound(): void {
		$r = $this->option( 'a:0:{}' );
		$this->assertNull( $r['real_usage_peak_bytes'] );
		$this->assertSame( array( 'arena_slack', 'vm_temporaries', 'cleanup_reserve_evidence' ), $r['missing_headroom_terms'] );
	}
}
