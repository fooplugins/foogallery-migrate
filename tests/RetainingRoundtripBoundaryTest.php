<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use PHPUnit\Framework\TestCase;

/** Finite incompatibility evidence only. NEVER a substitute for original20K. */
class RetainingRoundtripBoundaryTest extends TestCase {
	protected function setUp(): void {
		if ( PHP_VERSION !== '8.5.11' || PHP_OS !== 'Darwin' || php_uname( 'm' ) !== 'arm64' ||
			hash_file( 'sha256', PHP_BINARY ) !== \FooPlugins\FooGalleryMigrate\RepositoryFlattenModel::BINARY_SHA256 ) {
			$this->markTestSkipped( 'Finite exact native-host diagnostic only; original acceptance is NOT skipped.' );
		}
	}

	private function witness( $cell, $ballast = 0, $copies = 1 ) {
		$root = getenv( 'FOOGM_RETAINING_ROOT' ) ? realpath( getenv( 'FOOGM_RETAINING_ROOT' ) ) : dirname( __DIR__ );
		$args = array( PHP_BINARY, '-d', 'memory_limit=128M', '-d', 'opcache.enable_cli=0', __DIR__ . '/tools/retaining-roundtrip-boundary.php', $root, __DIR__ . '/support/StorageA7Fixture.php', (string) $cell, (string) $ballast, (string) $copies );
		$command = implode( ' ', array_map( 'escapeshellarg', $args ) );
		exec( $command . ' 2>&1', $lines, $status );
		$report = json_decode( implode( "\n", $lines ), true );
		$receipts = getenv( 'FOOGM_RETAINING_RECEIPTS' );
		if ( $receipts ) {
			$path = $receipts . '/retaining-' . $cell . '-' . $ballast . '-' . $copies . '-' . getmypid() . '.json';
			if ( false === file_put_contents( $path, json_encode( array( 'command' => $args, 'exit_code' => $status, 'report' => $report, 'raw' => $lines ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) ) { throw new \RuntimeException( 'Cannot retain child receipt.' ); }
		}
		$this->assertIsArray( $report, implode( "\n", $lines ) );
		$this->assertSame( '128M', $report['runtime']['memory_limit'] );
		$this->assertSame( hash_file( 'sha256', PHP_BINARY ), $report['runtime']['binary_sha256'] );
		$this->assertSame( hash_file( 'sha256', __DIR__ . '/support/StorageA7Fixture.php' ), $report['fixture']['sha256'] );
		$this->assertTrue( $report['runtime']['native_json'] );
		$this->assertSame( 8, $report['policy']['amplification'] );
		$this->assertSame( 33554432, $report['policy']['reserve_bytes'] );
		foreach ( $report['loaded'] as $class => $binding ) {
			if ( false !== strpos( $class, '\\Tests\\' ) ) { continue; }
			$this->assertSame( $root . '/includes', dirname( $binding['path'] ) );
			$this->assertSame( hash_file( 'sha256', $binding['path'] ), $binding['sha256'] );
		}
		return array( $status, $report );
	}

	public function test_original_finite_cells_measure_retaining_roundtrip_without_guard_changes(): void {
		foreach ( array( 1000, 2000, 10000 ) as $cell ) {
			list( $status, $r ) = $this->witness( $cell );
			$this->assertSame( 0, $status );
			$this->assertSame( 'complete', $r['stage'] );
			$this->assertSame( $cell + 1, $r['rows_retained'] );
			$this->assertLessThanOrEqual( 100663296, $r['phases']['after_parity']['peak_true'] );
		}
		list( $status, $r ) = $this->witness( 20000 );
		$this->assertSame( 1, $status );
		$this->assertSame( 'flatten', $r['stage'] );
		$this->assertSame( 0, $r['rows_retained'] );
		$this->assertStringContainsString( 'admission refused', $r['failure']['message'] );
		$this->assertArrayNotHasKey( 'after_flatten', $r['phases'] );
	}

	public function test_live_caller_ballast_and_distinct_retained_copies_change_rebuild_admission(): void {
		foreach ( array( array( 16777216, 1 ), array( 0, 2 ) ) as $case ) {
			list( $status, $r ) = $this->witness( 10000, $case[0], $case[1] );
			$this->assertSame( 1, $status );
			$this->assertSame( 'rebuild', $r['stage'] );
			$this->assertSame( 10001, $r['rows_retained'] );
			$this->assertSame( 7396357, $r['stats']['encoded_bytes'] );
			$this->assertSame( 2 === $case[1] ? 10001 : 0, $r['duplicate_rows_retained'] );
			$this->assertArrayNotHasKey( 'after_rebuild', $r['phases'] );
			$this->assertStringContainsString( 'admission refused', $r['failure']['message'] );
		}
		list( $status, $r ) = $this->witness( 2000, 33554432, 2 );
		$this->assertSame( 0, $status );
		$this->assertSame( 'complete', $r['stage'] );
		$this->assertSame( 2001, $r['duplicate_rows_retained'] );
	}
}
