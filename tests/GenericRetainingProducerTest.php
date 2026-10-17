<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\GenericRetainingModel;
use PHPUnit\Framework\TestCase;

/** Finite host qualification only. Does not skip or replace original acceptance. */
class GenericRetainingProducerTest extends TestCase {
 protected function setUp(): void {
  if ( PHP_VERSION !== '8.5.11' || PHP_OS !== 'Darwin' || php_uname( 'm' ) !== 'arm64' ||
   hash_file( 'sha256', PHP_BINARY ) !== GenericRetainingModel::BINARY_SHA256 ) {
   $this->markTestSkipped( 'Finite retaining profile unsupported; original acceptance remains required.' );
  }
 }

 private function witness( $shape, $size, $ballast = 0, $copies = 1 ) {
  $root = getenv( 'FOOGM_AUDIT_RUNTIME_ROOT' ) ?: dirname( __DIR__ );
  $args = array( PHP_BINARY, '-d', 'memory_limit=128M', '-d', 'opcache.enable_cli=0',
   __DIR__ . '/tools/generic-retaining-phase-probe.php', $root, $shape, (string) $size, (string) $ballast, (string) $copies );
  exec( implode( ' ', array_map( 'escapeshellarg', $args ) ) . ' 2>&1', $lines, $status );
  $r = json_decode( implode( "\n", $lines ), true );
  $this->assertIsArray( $r, implode( "\n", $lines ) );
  $this->assertTrue( $r['supported'] );
  $this->assertSame( '128M', $r['limit'] );
  $this->assertSame( 33554432, $r['reserve'] );
  foreach ( $r['loaded'] as $binding ) {
   $this->assertSame( realpath( $root ) . '/includes', dirname( $binding['path'] ) );
   $this->assertSame( hash_file( 'sha256', $binding['path'] ), $binding['sha256'] );
  }
  return array( $status, $r );
 }

 /** Descriptors selected before calibration, not the original fixture. */
 public static function heldOut() {
  return array( array( 'wide', 1537 ), array( 'deep', 17 ), array( 'binary', 65537 ),
   array( 'scalar', 393217 ), array( 'fields', 193 ), array( 'sibling-cow', 3073 ) );
 }

 /** @dataProvider heldOut */
 public function test_finite_held_out_retaining_roundtrip_and_component_peaks( $shape, $size ): void {
  list( $status, $r ) = $this->witness( $shape, $size );
  $this->assertSame( 0, $status, json_encode( $r ) );
  $this->assertSame( 'complete', $r['stage'] ); // Child executes exact serialize parity.
  $this->assertSame( $r['stats']['record_count'], $r['retained_rows'] );
  $flatten = $r['flatten_plan']['future'] + $r['flatten_plan']['private'] + $r['flatten_plan']['external'];
  $rebuild = $r['rebuild_plan']['future'] + $r['rebuild_plan']['private'];
  foreach ( array( 'live', 'true' ) as $kind ) {
   $this->assertLessThanOrEqual( $flatten, $r['flatten_after']['peak_' . $kind] - $r['flatten_before'][$kind] );
   $this->assertLessThanOrEqual( $rebuild, $r['rebuild_after']['peak_' . $kind] - $r['rebuild_before'][$kind] );
   $this->assertLessThanOrEqual( $r['rebuild_plan']['parts']['row_workspace'],
    $r['rebuild_descriptor_after']['peak_' . $kind] - $r['rebuild_descriptor_before'][$kind] );
  }
  $this->assertLessThanOrEqual( 134217728, $r['parity_after']['peak_true'] + $r['reserve'] );
 }

 public function test_distinct_copy_has_independent_pending_and_live_retention(): void {
  list( $status, $r ) = $this->witness( 'rows', 1000, 0, 2 );
  $this->assertSame( 0, $status );
  $this->assertSame( 1001, $r['distinct_rows'] );
  $this->assertGreaterThan( 0, $r['external_distinct_copy_pending'] );
  $this->assertSame( 'complete', $r['stage'] );
  list( $status, $r ) = $this->witness( 'rows', 10000, 33554432, 2 );
  $this->assertSame( 1, $status );
  $this->assertSame( 'flatten', $r['stage'] );
  $this->assertSame( 0, $r['retained_rows'] );
  $this->assertStringContainsString( 'admission refused', $r['failure'] );
 }

 public function test_live_ballast_is_never_a_source_credit(): void {
  list( $status, $r ) = $this->witness( 'original', 20000, 33554432 );
  $this->assertSame( 1, $status );
  $this->assertSame( 'flatten', $r['stage'] );
  $this->assertSame( 0, $r['retained_rows'] );
  $this->assertSame( 33554432, $r['ballast_bytes'] );
 }
}
