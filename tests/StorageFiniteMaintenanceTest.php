<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use FooPlugins\FooGalleryMigrate\TableSnapshot;
use FooPlugins\FooGalleryMigrate\StorageConversion;
use FooPlugins\FooGalleryMigrate\StorageScaleAdmission;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/StorageSnapshotMaintenanceAdmissionTest.php';
require_once __DIR__ . '/StorageScaleAdmissionTest.php';

/** Actual finite-domain classes over the scoped query spy, NOT native SQL. */
class StorageFiniteMaintenanceTest extends TestCase {
	private function scale( $limits = array(), $clock = null, $memory = null ) {
		return new StorageScaleAdmission( array_merge( StorageScaleAdmissionTest::policy(), $limits ),
			StorageScaleAdmissionTest::decode_policy(), ( null === $clock ? microtime( true ) : $clock() ) + 10.0, $clock, $memory );
	}
	protected function tearDown(): void { unset( $GLOBALS['wpdb'], $GLOBALS['foogallery_migrate_test_blog_id'] ); }
	public function test_finite_dirty_runtime_read_still_refuses_without_maintenance(): void {
		// The spy's bounded-read metadata is deliberately made dirty here.
		$db = new OwningMetadataWpdb(); $db->dirty_digest = true;
		$snapshot = new TableSnapshot( $db, 7, 100, 1048576, null, null, $this->scale() );
		try { $snapshot->read_bounded( 'bucket' ); $this->fail( 'Dirty read repaired implicitly.' ); }
		catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'dirty or stale', $error->getMessage() ); }
		$this->assertFalse( $db->published ); $this->assertFalse( $db->records_fetched );
	}
	public function test_finite_dirty_coordinator_verifies_and_reads_current_complete_value(): void {
		$db = new MaintenanceAdmissionWpdb(); $GLOBALS['wpdb'] = $db; $GLOBALS['foogallery_migrate_test_blog_id'] = 7;
		$manager = new StorageConversion( array( 'max_raw_bytes' => 8388608, 'amplification' => 8, 'reserve_bytes' => 33554432 ), new SnapshotAdmissionBaseline(), $this->scale() );
		$r = new \ReflectionClass( $manager );
		foreach ( array( 'connection' => $db, 'gate' => new MaintenanceAdmissionGate(), 'deadline' => microtime( true ) + 10.0 ) as $name => $value ) { $r->getProperty( $name )->setValue( $manager, $value ); }
		$r->getMethod( 'start_memory' )->invoke( $manager ); $r->getMethod( 'storage' )->invoke( $manager );
		$this->assertSame( TableSnapshot::class, get_class( $r->getProperty( 'snapshot' )->getValue( $manager ) ) );
		$result = $r->getMethod( 'table_snapshot' )->invoke( $manager, true );
		$this->assertSame( array( 'bucket' => $db->value ), $result['value'] );
		$this->assertTrue( $db->published ); $this->assertContains( 'COMMIT', $db->queries );
	}
	/** @dataProvider materialRefusals */
	public function test_finite_maintenance_rechecks_shared_domain_after_hydration( $seam ): void {
		$db = new FiniteMaintenanceWpdb();
		$clock = function () use ( $db, $seam ) { return 'deadline' === $seam && $db->records_fetched ? 111.0 : 100.0; };
		$memory = function () use ( $db, $seam ) { return array( '-1', 'memory' === $seam && $db->records_fetched ? 2147483647 : 0 ); };
		$scale = $this->scale( array(), $clock, $memory );
		$snapshot = new TableSnapshot( $db, 7, 100, 1048576, null, null, $scale );
		try { $snapshot->verify_digest( 'bucket', 1 ); $this->fail( 'No material finite refusal.' ); }
		catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'Finite storage', $error->getMessage() ); }
		$this->assertTrue( $db->records_fetched ); $this->assertFalse( $db->digest_written );
		$this->assertNotContains( 'START TRANSACTION', $db->queries );
	}
	public static function materialRefusals(): array { return array( array( 'memory' ), array( 'deadline' ) ); }
	/** @dataProvider refusals */
	public function test_finite_maintenance_preserves_refusals( $seam ): void {
		$db = new FiniteMaintenanceWpdb(); $now = 100.0; $used = 0;
		$limits = array();
		if ( 'bytes' === $seam ) { $limits['max_bytes'] = $db->head['payload_bytes']; }
		if ( 'tokens' === $seam ) { $limits['max_tokens'] = 2; }
		$scale = $this->scale( $limits, function () use ( &$now ) { return $now; }, function () use ( &$used ) { return array( '-1', $used ); } );
		$snapshot = new TableSnapshot( $db, 7, 100, $scale->limit( 'max_bytes' ), null, null, $scale );
		if ( 'memory' === $seam ) { $used = 2147483647; }
		if ( 'deadline' === $seam ) { $now = 111.0; }
		if ( 'stale' === $seam ) { $db->head['revision'] = 2; }
		if ( 'rows' === $seam ) { $db->head['record_count'] = 101; }
		if ( 'current-corruption' === $seam ) { $db->published = true; $db->corrupt = true; }
		if ( 'cas-revision' === $seam ) { $db->change_at_lock = true; }
		$db->queries = array();
		try { $snapshot->verify_digest( 'bucket', 1 ); $this->fail( 'Finite maintenance bypassed ' . $seam ); }
		catch ( \RuntimeException $error ) {
			$this->assertStringNotContainsString( 'not admitted for maintenance', $error->getMessage() );
			$messages = array( 'stale' => 'stale', 'rows' => 'row/byte', 'bytes' => 'row/byte', 'current-corruption' => 'not repairing corruption', 'cas-revision' => 'head changed before publication' );
			if ( isset( $messages[$seam] ) ) { $this->assertStringContainsString( $messages[$seam], $error->getMessage() ); }
		}
		$this->assertNotContains( 'COMMIT', $db->queries );
		$this->assertFalse( $db->digest_written );
		if ( in_array( $seam, array( 'rows', 'bytes', 'memory', 'deadline', 'stale' ), true ) ) { $this->assertFalse( $db->records_fetched ); }
		if ( 'cas-revision' === $seam ) { $this->assertSame( 'ROLLBACK', end( $db->queries ) ); }
	}
	public static function refusals(): array {
		return array_map( function ( $s ) { return array( $s ); }, array( 'stale', 'rows', 'bytes', 'tokens', 'memory', 'deadline', 'current-corruption', 'cas-revision' ) );
	}
}
class FiniteMaintenanceWpdb extends MaintenanceAdmissionWpdb {
	public $corrupt = false;
	public $change_at_lock = false;
	public $digest_written = false;
	public function query( $sql ) {
		if ( false !== strpos( $sql, 'SET snapshot_digest' ) ) { $this->digest_written = true; }
		return parent::query( $sql );
	}
	public function get_results( $sql, $format ) {
		if ( $this->change_at_lock && false !== strpos( $sql, 'FOR UPDATE' ) ) { ++$this->head['revision']; }
		$rows = parent::get_results( $sql, $format );
		if ( $this->corrupt && false !== strpos( $sql, 'SELECT root_payload, digest_status' ) ) { $rows[0]['logical_digest'] = str_repeat( '0', 64 ); }
		return $rows;
	}
}
