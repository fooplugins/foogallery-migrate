<?php
namespace FooPlugins\FooGalleryMigrate\Tests;
use FooPlugins\FooGalleryMigrate\StorageCapacityBudget;
use FooPlugins\FooGalleryMigrate\StorageScaleAdmission;
use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use FooPlugins\FooGalleryMigrate\LegacyRecordCodec;
use PHPUnit\Framework\TestCase;

/** Scratch reservations are memory, not persisted physical byte footprints. */
class StorageCapacityScratchAdmissionTest extends TestCase {
	private function scale( $ceiling = 134217728, $capacity_enabled = true ) {
		$class = StorageCapacityPolicyTest::capacity_class();
		$class['limits']['max_physical_bytes'] = 262144;
		$class['limits']['memory_ceiling_bytes'] = $ceiling;
		$clock = function () { return 1.0; };
		$memory = function () { return array( 'limit' => '-1', 'zend' => 100, 'live' => 100, 'rss' => 100, 'peak' => 100 ); };
		$capacity = $capacity_enabled ? new StorageCapacityBudget( $class, $clock, $memory ) : null;
		$limits = array( 'version' => 1 );
		foreach ( array( 'max_tokens', 'max_rows', 'max_bytes', 'max_depth', 'max_heads', 'max_physical_rows_per_table', 'max_physical_rows', 'max_physical_bytes', 'memory_ceiling_bytes', 'max_seconds' ) as $key ) { $limits[$key] = $class['limits'][$key]; }
		return new StorageScaleAdmission( $limits, array( 'max_raw_bytes' => 4096, 'amplification' => 1, 'reserve_bytes' => 10 ), 11.0, $clock, function () { return array( '-1', 100 ); }, null, $capacity );
	}
	public function test_tiny_conversion_keeps_full_record_workspace_above_physical_storage_cap(): void {
		$scale = $this->scale(); $rows = 0;
		$codec = new LegacyStateCodec( 1048576, 64, 10000, $scale );
		$records = new LegacyRecordCodec( $codec, 128, 131072, $scale );
		$this->assertTrue( $records->walk_conversion_tree( 'items', 'key', array( 'ID' => '0', 'unknown' => array( false, null, -0.0, '009', "雪\0" ) ), 0, function () use ( &$rows ) { ++$rows; return true; } ) );
		$this->assertSame( 1, $rows );
		$this->assertSame( 262144, $scale->limit( 'max_physical_bytes' ) );
	}
	public function test_full_workspace_is_still_charged_against_finite_memory(): void {
		$this->expectException( \RuntimeException::class );
		$this->scale( 1048576 )->admit_memory( 1048576 );
	}
	public function test_capacity_scratch_never_disables_persisted_snapshot_byte_cap(): void {
		$this->expectException( \RuntimeException::class );
		$this->scale()->admit_snapshot( str_repeat( 'a', 64 ), 1, 262145 );
	}
	public function test_legacy_noncapacity_physical_guard_is_unchanged(): void {
		$this->expectException( \RuntimeException::class );
		$this->scale( 134217728, false )->admit_memory( 1048576 );
	}
	public function test_capacity_invalid_scratch_still_refuses(): void {
		foreach ( array( -1, '1', 1.0, null, PHP_INT_MAX ) as $bad ) {
			try { $this->scale()->admit_memory( $bad ); $this->fail( 'Invalid or excessive scratch admitted.' ); }
			catch ( \RuntimeException $error ) { $this->assertNotEmpty( $error->getMessage() ); }
		}
	}
}
