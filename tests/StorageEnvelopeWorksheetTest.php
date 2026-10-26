<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use PHPUnit\Framework\TestCase;

/** Offline evidence algebra only; never a production calibration/profile. */
class StorageEnvelopeWorksheetTest extends TestCase {
	protected function setUp(): void {
		$this->assertFileExists( __DIR__ . '/support/StorageEnvelopeWorksheet.php' );
		require_once __DIR__ . '/support/StorageEnvelopeWorksheet.php';
	}

	private function counts() {
		return array( 'raw_bytes' => 10, 'nodes' => 5, 'depth' => 2, 'encoded_bytes' => 20, 'rows' => 3, 'response_bytes' => 4 );
	}
	private function costs( $cost = 2 ) { return array_fill_keys( array_keys( $this->counts() ), $cost ); }
	private function budget() {
		return array( 'integer_max' => PHP_INT_MAX, 'memory_limit_bytes' => 1000, 'memory_used_bytes' => 100,
			'reserve_bytes' => 200, 'deadline_us' => 1000, 'elapsed_us' => 100, 'cleanup_us' => 200 );
	}
	private function plan( $counts = null, $memory = null, $time = null, $budget = null ) {
		return StorageEnvelopeWorksheet::plan( null === $counts ? $this->counts() : $counts,
			null === $memory ? $this->costs() : $memory, null === $time ? $this->costs( 3 ) : $time,
			null === $budget ? $this->budget() : $budget );
	}
	private function refused( $counts = null, $memory = null, $time = null, $budget = null ) {
		$this->expectException( \InvalidArgumentException::class );
		$this->plan( $counts, $memory, $time, $budget );
	}

	public function test_sums_all_simultaneously_live_dimensions_without_approving_conversion(): void {
		$result = $this->plan();
		$this->assertSame( 388, $result['conditional_peak_bytes'] );
		$this->assertSame( 432, $result['conditional_total_us'] );
		$this->assertSame( 'measurement_only', $result['status'] );
		$this->assertFalse( $result['conversion_ready'] );
		$this->assertArrayNotHasKey( 'policy', $result );
	}
	public function test_exact_budget_boundary_includes_reserve_and_cleanup(): void {
		$b = $this->budget(); $b['memory_limit_bytes'] = 388; $b['deadline_us'] = 432;
		$this->assertSame( 388, $this->plan( null, null, null, $b )['conditional_peak_bytes'] );
	}
	public function test_one_byte_below_boundary_refuses(): void {
		$b = $this->budget(); $b['memory_limit_bytes'] = 387; $this->refused( null, null, null, $b );
	}
	public function test_one_microsecond_below_boundary_refuses(): void {
		$b = $this->budget(); $b['deadline_us'] = 431; $this->refused( null, null, null, $b );
	}
	public function test_already_exhausted_reserve_refuses_even_with_no_payload(): void {
		$b = $this->budget(); $b['memory_used_bytes'] = 801;
		$this->refused( $this->costs( 0 ), null, null, $b );
	}
	public function test_unknown_dimension_is_not_silently_dropped(): void {
		$c = $this->counts(); $c['unbounded_sql'] = 1; $this->refused( $c );
	}
	public function test_missing_cost_cannot_be_zero_filled(): void {
		$m = $this->costs(); unset( $m['rows'] ); $this->refused( null, $m );
	}
	public function test_unproven_zero_cost_refuses(): void {
		$t = $this->costs(); $t['nodes'] = 0; $this->refused( null, null, $t );
	}
	public function test_string_or_float_is_not_integer_evidence(): void {
		$c = $this->counts(); $c['raw_bytes'] = '10'; $this->refused( $c );
	}
	public function test_unknown_or_unlimited_memory_refuses(): void {
		$b = $this->budget(); $b['memory_limit_bytes'] = -1; $this->refused( null, null, null, $b );
	}
	public function test_negative_elapsed_time_refuses_clock_reversal(): void {
		$b = $this->budget(); $b['elapsed_us'] = -1; $this->refused( null, null, null, $b );
	}
	public function test_32_bit_sum_overflow_refuses_on_a_modern_64_bit_host(): void {
		$b = $this->budget(); $b['integer_max'] = 2147483647; $b['memory_limit_bytes'] = 2147483647;
		$b['memory_used_bytes'] = 2147483600; $this->refused( null, null, null, $b );
	}
	public function test_32_bit_product_overflow_refuses(): void {
		$b = $this->budget(); $b['integer_max'] = 2147483647; $b['memory_limit_bytes'] = 2147483647;
		$c = $this->counts(); $c['raw_bytes'] = 2147483647; $this->refused( $c, null, null, $b );
	}
	public function test_native_integer_product_overflow_is_not_cast_back_from_float(): void {
		$c = $this->counts(); $c['raw_bytes'] = PHP_INT_MAX; $this->refused( $c );
	}
	public function test_float_cost_refuses(): void {
		$m = $this->costs(); $m['raw_bytes'] = 2.0; $this->refused( null, $m );
	}
	public function test_missing_deadline_refuses(): void {
		$b = $this->budget(); unset( $b['deadline_us'] ); $this->refused( null, null, null, $b );
	}
	public function test_increasing_any_resource_never_decreases_either_bound(): void {
		$before = $this->plan();
		foreach ( $this->counts() as $key => $value ) {
			$c = $this->counts(); ++$c[$key]; $after = $this->plan( $c );
			$this->assertGreaterThan( $before['conditional_peak_bytes'], $after['conditional_peak_bytes'] );
			$this->assertGreaterThan( $before['conditional_total_us'], $after['conditional_total_us'] );
		}
	}
}
