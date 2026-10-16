<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\RepositoryFlattenModel;
use FooPlugins\FooGalleryMigrate\StoragePreflight;
use PHPUnit\Framework\TestCase;

/** Characterizes a blocker; passing these tests does NOT turn original20K green. */
class RepositoryFlattenIncompatibilityTest extends TestCase {
	public function test_unchanged_aggregate_policy_cannot_fit_even_without_source_or_pending(): void {
		$policy = array( 'max_raw_bytes' => 33554432, 'amplification' => 8, 'reserve_bytes' => 33554432 );
		$this->assertSame( 154117264, 15070354 * $policy['amplification'] + $policy['reserve_bytes'] );
		$this->assertTrue( is_wp_error( StoragePreflight::decode_budget( 15070354, '128M', 0, $policy ) ) );
		$this->assertFalse( is_wp_error( StoragePreflight::decode_budget( 12582912, '128M', 0, $policy ) ) );
		$this->assertTrue( is_wp_error( StoragePreflight::decode_budget( 12582913, '128M', 0, $policy ) ) );
	}

	public function test_actual_original20k_and_model_pending_both_keep_aggregate_refusal(): void {
		// The subprocess deliberately has NO wp_json_encode, unlike this bootstrap.
		if ( PHP_VERSION !== '8.5.11' || PHP_OS !== 'Darwin' || php_uname( 'm' ) !== 'arm64' ||
			hash_file( 'sha256', PHP_BINARY ) !== RepositoryFlattenModel::BINARY_SHA256 ) {
			$this->markTestSkipped( 'Exact finite native-host profile required; not WordPress/literal-floor coverage.' );
		}
		foreach ( array( 'original', 'planned' ) as $mode ) {
			$output = array();
			$command = escapeshellarg( PHP_BINARY ) . ' -d opcache.enable_cli=0 -d memory_limit=128M ' .
				escapeshellarg( __DIR__ . '/tools/repository-flatten-incompatibility.php' ) . ' ' .
				escapeshellarg( dirname( __DIR__ ) ) . ' ' . escapeshellarg( __DIR__ . '/support/StorageA7Fixture.php' ) . ' ' . $mode;
			exec( $command . ' 2>&1', $output, $exit );
			$report = json_decode( implode( "\n", $output ), true );
			$this->assertIsArray( $report, implode( "\n", $output ) );
			$this->assertSame( 1, $exit, 'Original acceptance must stay RED, not be redefined as success.' );
			$this->assertSame( 'BLOCKED_ORIGINAL20K_FLATTEN', $report['status'] );
			$this->assertSame( 0, $report['emitted'] );
			$this->assertSame( 20208, $report['descriptor']['records'] );
			$this->assertSame( 15070354, $report['descriptor']['encoded_bytes'] );
			$this->assertSame( 154117264, $report['minimum_legacy_aggregate_requirement_even_with_zero_usage'] );
			$this->assertTrue( $report['runtime']['native_json'] );
			$this->assertSame( '128M', $report['runtime']['memory_limit'] );
			$this->assertSame( 8, $report['original_policy']['amplification'] );
			$this->assertSame( 33554432, $report['original_policy']['reserve_bytes'] );
			$this->assertStringContainsString( 'admission refused', $report['failure']['message'] );
			$frame = $report['failure']['trace'][2];
			$this->assertSame( 'FooPlugins\\FooGalleryMigrate\\LegacyRecordCodec::admit', $frame['function'] );
			$lines = file( $frame['file'] );
			$this->assertSame( '$this->admit( $preflight[\'encoded_bytes\'] );', trim( $lines[$frame['line'] - 1] ) );
		}
	}
}
