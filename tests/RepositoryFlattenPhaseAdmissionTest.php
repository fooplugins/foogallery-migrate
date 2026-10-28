<?php
namespace FooPlugins\FooGalleryMigrate\Tests;
use FooPlugins\FooGalleryMigrate\StorageScaleAdmission as Admission;
use PHPUnit\Framework\TestCase;

/** Arithmetic seams only; actual native integrated measurements run out of process. */
class RepositoryFlattenPhaseAdmissionTest extends TestCase {
	private function budget( $sample, $ceiling = 134217728 ) {
		$p = array( 'version' => 1, 'max_tokens' => 262144, 'max_rows' => 24576, 'max_bytes' => 16777216,
			'max_depth' => 64, 'max_heads' => 100, 'max_physical_rows_per_table' => 24576,
			'max_physical_rows' => 49152, 'max_physical_bytes' => 33554432,
			'memory_ceiling_bytes' => $ceiling, 'max_seconds' => 10 );
		return new Admission( $p, array( 'max_raw_bytes' => 8388608, 'amplification' => 8, 'reserve_bytes' => 33554432 ),
			microtime( true ) + 10.0, null, $sample );
	}
	private function refused( $call ) {
		$refused = false;
		try { $call(); } catch ( \RuntimeException $e ) { $refused = true; }
		$this->assertTrue( $refused, 'Expected finite admission refusal.' );
	}
	public function test_pending_consumes_last_headroom_for_every_legacy_check(): void {
		foreach ( array( '134217728', '-1' ) as $effective ) {
			$b = $this->budget( function () use ( $effective ) { return array( $effective, 1 ); } );
			$this->assertTrue( $b->admit_additional( 100663295 ) );
			$this->assertTrue( $b->admit_memory( 0 ) );
			$this->refused( function () use ( $b ) { $b->admit_memory( 1 ); } );
		}
	}
	public function test_effective_ceiling_boundary_and_plus_one(): void {
		$b = $this->budget( function () { return array( '64M', 1 ); } );
		$this->assertTrue( $b->admit_additional( 33554431 ) );
		$this->expectException( \RuntimeException::class ); $b->admit_additional( 1 );
	}
	public function test_pending_survives_sample_increase_and_decrease_and_nested_legacy(): void {
		$usage = 1;
		$b = $this->budget( function () use ( &$usage ) { return array( '128M', $usage ); } );
		$b->admit_additional( 100663287 ); // leaves exactly 8 amplified bytes.
		$this->assertTrue( $b->admit_memory( 1 ) );
		$usage = 2;
		$this->refused( function () use ( $b ) { $b->admit_memory( 1 ); } );
		$usage = 1;
		$this->assertTrue( $b->admit_memory( 1 ) );
		$b->admit_additional( 1 );
		$this->expectException( \RuntimeException::class ); $b->admit_memory( 1 );
	}
	public function test_overflow_does_not_wrap_or_reset_pending(): void {
		$b = $this->budget( function () { return array( '-1', 1 ); } );
		$b->admit_additional( 100663295 );
		$this->refused( function () use ( $b ) { $b->admit_additional( PHP_INT_MAX ); } );
		$this->expectException( \RuntimeException::class ); $b->admit_memory( 1 );
	}
	public function test_nested_decoder_cannot_reuse_pending_last_headroom(): void {
		$b = $this->budget( function () { return array( '128M', 1 ); } );
		$b->admit_additional( 100663295 );
		$this->refused( function () use ( $b ) { $b->decoder( 6 )->decode( 'a:0:{}' ); } );
		$this->refused( function () use ( $b ) { $b->admit_snapshot( str_repeat( 'a', 64 ), 1, 1 ); } );
	}
	public function test_native_integrated_original_factory_and_refusal_corpus(): void {
		$root = getenv( 'FOOGM_PHASE_ROOT' ) ? realpath( getenv( 'FOOGM_PHASE_ROOT' ) ) : dirname( __DIR__ );
		$command = escapeshellarg( PHP_BINARY ) . ' -d opcache.enable_cli=0 -d memory_limit=128M ' .
			escapeshellarg( __DIR__ . '/tools/repository-flatten-phase-probe.php' ) . ' ' . escapeshellarg( $root ) . ' ' .
			escapeshellarg( __DIR__ . '/support/StorageA7Fixture.php' );
		exec( $command, $lines, $status );
		$r = json_decode( implode( "\n", $lines ), true );
		$model = 'FooPlugins\\FooGalleryMigrate\\RepositoryFlattenModel';
		if ( PHP_VERSION !== '8.5.11' || PHP_OS !== 'Darwin' || php_uname( 'm' ) !== 'arm64' ||
			hash_file( 'sha256', PHP_BINARY ) !== $model::BINARY_SHA256 ) {
			$this->assertSame( 1, $status );
			$this->assertSame( 'RED', $r['status'] );
			$this->assertArrayNotHasKey( 'original', $r );
			return; // Unsupported host MUST refuse, never a native-profile PASS/skip.
		}
		$this->assertSame( 0, $status, implode( "\n", $lines ) );
		foreach ( $r['loaded'] as $binding ) { $this->assertSame( $root, dirname( dirname( $binding['path'] ) ) ); }
		$this->assertSame( 20208, $r['original']['stats']['record_count'] );
		$this->assertSame( 15070354, $r['original']['stats']['encoded_bytes'] );
		$this->assertSame( 20208, $r['original']['sink']['rows'] );
		$this->assertSame( 'PASS', $r['status'] );
	}
}
