<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\GenericRetainingModel as Model;
use FooPlugins\FooGalleryMigrate\LegacyRecordCodec as Records;
use FooPlugins\FooGalleryMigrate\LegacyStateCodec as Codec;
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/StorageScaleAdmissionTest.php';

/** Native decode allocations, not substr() approximations of retained strings. */
class NativeStringAllocatorTest extends TestCase {
	private function codec() {
		$b = StorageScaleAdmissionTest::admission();
		$c = new Codec( 1048576, 64, 10000, $b );
		$this->assertTrue( Model::supports( new Records( null, 24576, 16777216, $b ), $c, $b ), 'Requires the calibrated native lane; do not silently skip it.' );
		return $c;
	}

	public static function discontinuities(): array {
		$cases = array();
		foreach ( array( 'x', "\xff", '"' ) as $pattern ) {
			foreach ( array( 3051, 3052, 4063, 4064 ) as $n ) {
				$cases[bin2hex( $pattern ) . '-' . $n] = array( $pattern, $n );
			}
		}
		return $cases;
	}

	/** @dataProvider discontinuities */
	public function test_actual_decode_discontinuities( $pattern, $n ): void {
		$c = $this->codec();
		$value = str_repeat( $pattern, $n ); $encoded = $c->encode( $value );
		$bound = Model::string_bytes( $n );
		$before = memory_get_usage( false ); $decoded = $c->decode( $encoded );
		$growth = memory_get_usage( false ) - $before;
		$this->assertSame( $value, $decoded );
		$this->assertLessThanOrEqual( $bound, $growth, 'Native retained allocation at ' . $n );
	}

	public function test_actual_decode_heldouts_and_existing_encoded_refusal(): void {
		$c = $this->codec(); $refusals = 0;
		foreach ( array( 'x', "\xff", '"' ) as $pattern ) {
			foreach ( array( 6140, 6141, 12264, 12265, 65537, 196609, 393217 ) as $n ) {
				$value = str_repeat( $pattern, $n );
				if ( "\xff" === $pattern && 393217 === $n ) {
					try { $c->encode( $value ); $this->fail( 'Encoded byte cap bypassed.' ); }
					catch ( \RuntimeException $e ) { $this->assertStringContainsString( 'encoded byte budget', $e->getMessage() ); ++$refusals; }
					continue;
				}
				$encoded = $c->encode( $value ); $bound = Model::string_bytes( $n );
				$before = memory_get_usage( false ); $decoded = $c->decode( $encoded );
				$growth = memory_get_usage( false ) - $before;
				$this->assertSame( $value, $decoded );
				$this->assertLessThanOrEqual( $bound, $growth, 'Native heldout at ' . $n );
				unset( $decoded );
			}
		}
		$this->assertSame( 1, $refusals );
	}

	public function test_repeated_3052_retained_output_and_peak_fit_forecast(): void {
		$b = StorageScaleAdmissionTest::admission();
		$c = new Records( null, 24576, 16777216, $b ); $codec = new Codec( 1048576, 64, 10000, $b );
		$this->assertTrue( Model::supports( $c, $codec, $b ) );
		$value = array( 'children' => array_fill( 0, 2200, str_repeat( 'x', 3052 ) ) );
		$rows = array();
		$stats = $c->flatten_tree( 'galleries', 'k', $value, 0, function ( $row ) use ( &$rows ) { $rows[] = $row; } );
		$d = $c->describe_rows( $rows, 'caller-rows-rebuild' );
		$plan = Model::rebuild_plan( $rows, $d, $codec, $b );
		$forecast = $plan['future'] + $plan['private'];
		$before = memory_get_usage( false ); memory_reset_peak_usage();
		$result = $c->rebuild_tree( 'galleries', $rows[0]['node_hash'], $rows );
		$growth = memory_get_usage( false ) - $before; $peak = memory_get_peak_usage( false ) - $before;
		$this->assertSame( 2201, $stats['record_count'] );
		$this->assertSame( 9401337, $stats['encoded_bytes'] );
		$this->assertSame( serialize( $value ), serialize( $result ) );
		if ( getenv( 'FOOGM_RETAINING_RECEIPTS' ) ) {
			file_put_contents( getenv( 'FOOGM_RETAINING_RECEIPTS' ) . '/native-3052-batch.json', json_encode( array( 'stats' => $stats, 'forecast' => $forecast, 'retained' => $growth, 'peak' => $peak ) ) );
		}
		$this->assertLessThanOrEqual( $forecast, $growth );
		$this->assertLessThanOrEqual( $forecast, $peak );
	}

	public function test_allocator_rounding_overflow_remains_closed(): void {
		$this->expectException( \RuntimeException::class );
		Model::string_bytes( PHP_INT_MAX );
	}
}
