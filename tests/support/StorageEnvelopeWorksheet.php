<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

/**
 * Offline algebra for a proposed envelope, NOT a runtime/profile evaluator.
 * Costs/caps must be independently justified; there are deliberately no defaults,
 * no payload decoder, no SQL, no filter/receiver registration and no approval.
 * All fixtures use invented unit costs only to exercise checked arithmetic.
 */
class StorageEnvelopeWorksheet {
	public static function plan( $caps, $memory_costs, $time_costs, $budget ) {
		$dimensions = array( 'raw_bytes', 'nodes', 'depth', 'encoded_bytes', 'rows', 'response_bytes' );
		$budgets = array( 'integer_max', 'memory_limit_bytes', 'memory_used_bytes', 'reserve_bytes', 'deadline_us', 'elapsed_us', 'cleanup_us' );
		self::shape( $budget, $budgets );
		$max = $budget['integer_max'];
		if ( ! is_int( $max ) || $max < 1 || $max > PHP_INT_MAX ) { self::fail(); }
		foreach ( $budget as $amount ) { self::integer( $amount, $max, 0 ); }
		foreach ( array( 'memory_limit_bytes', 'deadline_us', 'reserve_bytes', 'cleanup_us' ) as $key ) {
			self::integer( $budget[$key], $max, 1 );
		}
		foreach ( array( $caps, $memory_costs, $time_costs ) as $values ) { self::shape( $values, $dimensions ); }
		$memory = self::add( $budget['memory_used_bytes'], $budget['reserve_bytes'], $max );
		$time = self::add( $budget['elapsed_us'], $budget['cleanup_us'], $max );
		foreach ( $dimensions as $dimension ) {
			self::integer( $caps[$dimension], $max, 0 );
			self::integer( $memory_costs[$dimension], $max, 1 );
			self::integer( $time_costs[$dimension], $max, 1 );
			$memory = self::add( $memory, self::multiply( $caps[$dimension], $memory_costs[$dimension], $max ), $max );
			$time = self::add( $time, self::multiply( $caps[$dimension], $time_costs[$dimension], $max ), $max );
		}
		if ( $memory > $budget['memory_limit_bytes'] || $time > $budget['deadline_us'] ) { self::fail(); }
		return array( 'status' => 'measurement_only', 'conversion_ready' => false,
			'conditional_peak_bytes' => $memory, 'conditional_total_us' => $time );
	}

	private static function shape( $values, $keys ) {
		if ( ! is_array( $values ) || count( $values ) !== count( $keys ) || array_diff( $keys, array_keys( $values ) ) ) { self::fail(); }
	}
	private static function integer( $value, $max, $min ) {
		if ( ! is_int( $value ) || $value < $min || $value > $max ) { self::fail(); }
	}
	private static function add( $left, $right, $max ) {
		if ( $right > $max - $left ) { self::fail(); }
		return $left + $right;
	}
	private static function multiply( $left, $right, $max ) {
		// PHP promotes native overflow to float. Never cast it back, and separately
		// enforce a smaller proposed architecture ceiling on this modern host.
		$product = $left * $right;
		if ( ! is_int( $product ) || $product > $max ) { self::fail(); }
		return $product;
	}
	private static function fail() {
		throw new \InvalidArgumentException( 'Missing, unbounded, overflowing or exhausted envelope evidence. This worksheet never grants conversion admission.' );
	}
}
