<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use FooPlugins\FooGalleryMigrate\LegacyTableSnapshot;
use FooPlugins\FooGalleryMigrate\StorageConversion;
use FooPlugins\FooGalleryMigrate\StorageMemoryBaseline;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/LegacyTableSnapshotTest.php';

/** Executed phase/rollback ordering with a query spy, NOT native SQL evidence. */
class StorageSnapshotAdmissionTest extends TestCase {
	private function change() {
		return array( 'key' => 'checkpoint', 'order' => 0, 'kind' => 'scalar', 'value' => false, 'expected_revision' => null );
	}
	private function refuse() { throw new \RuntimeException( 'phase memory refusal' ); }
	private function assertRefusal( $repo, $db ) {
		try { $repo->replace_many( array( $this->change() ) ); $this->fail( 'Missing phase refusal.' ); }
		catch ( \RuntimeException $error ) { $this->assertSame( 'phase memory refusal', $error->getMessage() ); }
		$this->assertNotContains( 'COMMIT', $db->queries );
		$this->assertStringNotContainsString( 'SET active_generation', implode( "\n", $db->queries ) );
	}

	public function test_preflight_floor_refuses_before_encoding_or_sql(): void {
		$db = new SnapshotTestWpdb(); $seen = array();
		$repo = new LegacyTableSnapshot( $db, 7, 100, 1048576, null, function ( $bytes ) use ( &$seen ) {
			$seen[] = $bytes; $this->refuse();
		} );
		$this->assertRefusal( $repo, $db );
		$this->assertSame( array( 0 ), $seen );
		$this->assertSame( array(), $db->queries );
	}

	public function test_complete_encoded_preflight_is_charged_before_digest_and_sql(): void {
		$db = new SnapshotTestWpdb(); $seen = array();
		$repo = new LegacyTableSnapshot( $db, 7, 100, 1048576, null, function ( $bytes ) use ( &$seen ) {
			$seen[] = $bytes; if ( $bytes > 0 ) { $this->refuse(); } return true;
		} );
		$this->assertRefusal( $repo, $db );
		$codec = new LegacyStateCodec();
		$bytes = strlen( $codec->encode( array( 'version' => 1, 'kind' => 'scalar', 'value' => false ) ) ) + strlen( $codec->key_value( 'checkpoint' ) );
		$this->assertSame( array( 0, $bytes ), $seen );
		$this->assertSame( array(), $db->queries );
	}

	public function test_stage_refusal_rolls_back_before_any_insert(): void {
		$db = new SnapshotTestWpdb();
		$repo = new LegacyTableSnapshot( $db, 7, 100, 1048576, null, function ( $bytes ) use ( $db ) {
			if ( in_array( 'START TRANSACTION', $db->queries, true ) ) { $this->assertGreaterThan( 0, $bytes ); $this->refuse(); } return true;
		} );
		$this->assertRefusal( $repo, $db );
		$this->assertContains( 'ROLLBACK', $db->queries );
		$this->assertStringNotContainsString( 'INSERT INTO', implode( "\n", $db->queries ) );
	}

	public function test_verification_refusal_rolls_back_staging_before_payload_fetch(): void {
		$db = new SnapshotTestWpdb();
		$repo = new LegacyTableSnapshot( $db, 7, 100, 1048576, null, function ( $bytes ) use ( $db ) {
			if ( false !== strpos( implode( "\n", $db->queries ), 'INSERT INTO' ) ) { $this->assertGreaterThan( 0, $bytes ); $this->refuse(); } return true;
		} );
		$this->assertRefusal( $repo, $db );
		$this->assertContains( 'ROLLBACK', $db->queries );
		$this->assertStringNotContainsString( 'SELECT root_payload FROM', implode( "\n", $db->queries ) );
	}

	public function test_digest_refusal_sees_retained_readback_and_does_not_publish(): void {
		$db = new SnapshotTestWpdb();
		$repo = new LegacyTableSnapshot( $db, 7, 100, 1048576, null, function ( $bytes ) use ( $db ) {
			if ( false !== strpos( implode( "\n", $db->queries ), 'SELECT root_payload FROM' ) ) { $this->assertGreaterThan( 0, $bytes ); $this->refuse(); } return true;
		} );
		$this->assertRefusal( $repo, $db );
		$this->assertContains( 'ROLLBACK', $db->queries );
	}

	public function test_ordinary_scalar_keeps_commit_and_digest_semantics(): void {
		$db = new SnapshotTestWpdb(); $seen = array();
		$repo = new LegacyTableSnapshot( $db, 7, 100, 1048576, null, function ( $bytes ) use ( &$seen ) { $seen[] = $bytes; return true; } );
		$result = $repo->replace_many( array( $this->change() ) );
		$this->assertSame( array( 'key' => 'checkpoint', 'id' => 4, 'generation' => 1, 'revision' => 1 ), $result[0] );
		$this->assertContains( 'COMMIT', $db->queries );
		$this->assertNotContains( 'ROLLBACK', $db->queries );
		$this->assertGreaterThanOrEqual( 5, count( $seen ) );
		$this->assertSame( 0, $seen[0] );
		$this->assertGreaterThan( 0, $seen[1] );
		$this->assertSame( 0, end( $seen ) );
	}

	public function test_coordinator_snapshot_reuses_incoming_baseline_and_existing_policy(): void {
		$db = new SnapshotTestWpdb(); $memory = new SnapshotAdmissionBaseline();
		$previous_db = isset( $GLOBALS['wpdb'] ) ? $GLOBALS['wpdb'] : null;
		$GLOBALS['wpdb'] = $db; $db->prefix = 'wp_';
		$manager = new StorageConversion( array( 'max_raw_bytes' => 1048576, 'amplification' => 1, 'reserve_bytes' => 1 ), $memory );
		$GLOBALS['wpdb'] = $previous_db;
		$reflection = new \ReflectionClass( $manager );
		foreach ( array( 'connection' => $db, 'blog' => 7 ) as $name => $value ) {
			$property = $reflection->getProperty( $name ); $property->setValue( $manager, $value );
		}
		$reflection->getMethod( 'start_memory' )->invoke( $manager );
		$reflection->getMethod( 'storage' )->invoke( $manager );
		$storage = $reflection->getProperty( 'snapshot' )->getValue( $manager );
		$queries_before = $db->queries; $samples_before = $memory->samples;
		$previous = ini_get( 'memory_limit' ); ini_set( 'memory_limit', '128M' );
		try {
			$memory->observed_bytes = 134217728;
			$storage->replace_many( array( $this->change() ) ); $this->fail( 'Coordinator snapshot bypassed existing memory policy.' );
		} catch ( \RuntimeException $error ) {
			$this->assertSame( 'Retain recorded authority and all conversion evidence; storage recovery is required.', $error->getMessage() );
			$this->assertGreaterThan( $samples_before, $memory->samples );
			$this->assertSame( $queries_before, $db->queries );
		} finally { ini_set( 'memory_limit', $previous ); }
	}

	public function test_nonaffirmative_callback_fails_closed(): void {
		$db = new SnapshotTestWpdb();
		$repo = new LegacyTableSnapshot( $db, 7, 100, 1048576, null, function ( $bytes ) { return null; } );
		try { $repo->replace_many( array( $this->change() ) ); $this->fail( 'Nonaffirmative admission accepted.' ); }
		catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'memory admission', $error->getMessage() ); }
		$this->assertSame( array(), $db->queries );
	}
}

class SnapshotAdmissionBaseline extends StorageMemoryBaseline {
	public $observed_bytes = 0;
	public $samples = 0;
	public function sample() { ++$this->samples; return $this->observed_bytes; }
}
