<?php

namespace FooPlugins\FooGalleryMigrate\Tests;

use PHPUnit\Framework\TestCase;

class ScaleBenchmarkTest extends TestCase {
	public function test_synthetic_scale_benchmark_reports_bounded_turn_costs(): void {
		$script = dirname( __DIR__ ) . '/scripts/benchmark-migrated-store.php';
		$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $script ) . ' --records=2000';
		exec( $command, $output, $exit_code );

		$this->assertSame( 0, $exit_code, implode( "\n", $output ) );
		$result = json_decode( implode( "\n", $output ), true );
		$this->assertIsArray( $result );
		$this->assertSame( 2000, $result['records'] );
		$this->assertSame( 204, $result['gallery_children'] );
		$this->assertSame( 5, $result['batch_size'] );
		$this->assertSame( 2, $result['table']['read_queries'] );
		$this->assertSame( 1, $result['table']['write_queries'] );
		$this->assertSame( 5, $result['table']['rows_written'] );
		$this->assertSame( 0, $result['table']['full_history_hydrations'] );
		$this->assertGreaterThan( $result['table']['payload_bytes_written'], $result['legacy']['serialized_bytes_rewritten'] );
		$this->assertTrue( $result['assertions']['bounded_io'] );
		$this->assertTrue( $result['assertions']['avoids_full_history'] );
	}
}
