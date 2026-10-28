<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\GenericRetainingModel as Model;
use FooPlugins\FooGalleryMigrate\LegacyRecordCodec;
use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use FooPlugins\FooGalleryMigrate\StoragePreflight;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/StorageScaleAdmissionTest.php';

/** Portable release behavior alongside explicitly optional native qualification. */
class MemoryProfileConfigurationTest extends TestCase {
 private function supports( $b ) {
  return Model::supports( new LegacyRecordCodec( null, 24576, 16777216, $b ), new LegacyStateCodec( 1048576, 64, 10000, $b ), $b );
 }
 public static function limits() {
  return array( array( '128M', true ), array( '128m', true ), array( '131072K', true ), array( '134217728', true ),
   array( '256M', true ), array( '256m', true ), array( '262144K', true ), array( '268435456', true ),
   array( '64M', false ), array( '192M', false ), array( '512M', false ), array( '-1', false ) );
 }
 /**
  * Exact PHP 8.5.11 allocator qualification, not release admission.
  * @group optional_host_diagnostic
  * @dataProvider limits
  * @runInSeparateProcess
  * @preserveGlobalState disabled
  */
 public function test_effective_finite_budget_qualification( $limit, $supported ): void {
  // A fresh process must actually install every limit, including unsupported
  // 64M: the full suite can already retain more than that before this test.
  // Do not inherit its globals or lower the real qualification/reserve checks.
  $b = StorageScaleAdmissionTest::admission(); $previous = ini_get( 'memory_limit' );
  try {
   $this->assertNotFalse( ini_set( 'memory_limit', $limit ) );
   $this->assertSame( $limit, ini_get( 'memory_limit' ) );
   $this->assertSame( $supported, $this->supports( $b ) );
  } finally { ini_set( 'memory_limit', $previous ); }
 }
 public function test_small_roundtrip_preserves_rows_on_unqualified_runtime(): void {
  $b = StorageScaleAdmissionTest::admission();
  $c = new LegacyRecordCodec( null, 24576, 16777216, $b );
  $tree = array( 'children' => array( 'one' => 'x', 'two' => 'y' ) ); $rows = array();
  $stats = $c->flatten_tree( 'galleries', 'root', $tree, 0, function( $row ) use ( &$rows ) { $rows[] = $row; } );
  $this->assertSame( 3, $stats['record_count'] );
  $this->assertSame( serialize( $tree ), serialize( $c->rebuild_tree( 'galleries', $rows[0]['node_hash'], $rows ) ) );
  $this->assertSame( array_sum( array_column( $rows, 'payload_bytes' ) ), $stats['payload_bytes'] );
 }
 /** @runInSeparateProcess
  * @preserveGlobalState disabled
  */
 public function test_effective_runtime_budget_refuses_pressure_before_any_callback(): void {
  $this->assertNotFalse( ini_set( 'memory_limit', '256M' ) );
  $this->assertSame( '256M', ini_get( 'memory_limit' ) );
  $b = StorageScaleAdmissionTest::admission();
  $limit = StoragePreflight::decode_budget( 0, ini_get( 'memory_limit' ), 0, StorageScaleAdmissionTest::decode_policy() )['memory_limit_bytes'];
  $b->admit_additional( $limit - 33554432 - memory_get_usage( true ) - 1048576 );
  $calls = 0; $c = new LegacyRecordCodec( null, 24576, 16777216, $b );
  try {
   $c->flatten_tree( 'galleries', 'root', array( 'children' => array( 'one' => 'x' ) ), 0, function() use ( &$calls ) { ++$calls; } );
   $this->fail( 'Effective runtime pressure must refuse before staging or emission.' );
  } catch ( \RuntimeException $e ) { $this->assertStringContainsString( 'admission refused', $e->getMessage() ); }
  $this->assertSame( 0, $calls );
 }
 /** @group optional_host_diagnostic */
 public function test_other_qualification_refusals_are_not_bypassed_by_supported_budget(): void {
  $b = StorageScaleAdmissionTest::admission(); $this->assertTrue( $this->supports( $b ) );
  $c = new LegacyRecordCodec( null, 24576, 16777216, $b );
  $this->assertFalse( Model::supports( $c, new LegacyStateCodec( 1024, 64, 10000, $b ), $b ) );
  $this->assertFalse( Model::supports( new class( null, 24576, 16777216, $b ) extends LegacyRecordCodec {}, new LegacyStateCodec( 1048576, 64, 10000, $b ), $b ) );
  $this->assertFalse( Model::supports( $c, new class( 1048576, 64, 10000, $b ) extends LegacyStateCodec {}, $b ) );
  $this->assertFalse( Model::supports( $c, new LegacyStateCodec( 1048576, 64, 10000, $b ), null ) );
  $sampled = StorageScaleAdmissionTest::admission( null, null, function() { return array( ini_get( 'memory_limit' ), 0 ); } );
  $this->assertFalse( $this->supports( $sampled ) );
  $previous = getenv( 'USE_ZEND_ALLOC' );
  try { putenv( 'USE_ZEND_ALLOC=0' ); $this->assertFalse( $this->supports( $b ) ); }
  finally { putenv( false === $previous ? 'USE_ZEND_ALLOC' : 'USE_ZEND_ALLOC=' . $previous ); }
 }
}
