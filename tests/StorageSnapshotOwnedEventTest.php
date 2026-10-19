<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\LegacyTableSnapshot;
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/support/SnapshotScaleConnection.php';
require_once __DIR__ . '/StorageScaleAdmissionTest.php';

/** Actual snapshot producer path. Not allocator/source+ZIP qualification. */
class StorageSnapshotOwnedEventTest extends TestCase {
	private function property( $object, $name, $value = null, $set = false ) {
		$p = new \ReflectionProperty( $object, $name ); if ( PHP_VERSION_ID < 80100 ) { $p->setAccessible( true ); }
		if ( $set ) { $p->setValue( $object, $value ); }
		return $p->getValue( $object );
	}
	private function value() {
		return array( 'root' => array( 'unknown' => array( "\0\xff" => str_repeat( 'x', 240 ) ), 'children' => array(
			'branch' => array( 'children' => array( 7 => false, 'leaf' => array( 'unknown' => null ) ) ),
			'other' => 'last',
		) ) );
	}
	private function change() { return array( array( 'key' => 'bucket', 'order' => 0, 'kind' => 'map', 'value' => $this->value(), 'expected_revision' => null ) ); }
	private function snapshot( $db, $memory = null, $enabled = true ) {
		$s = new LegacyTableSnapshot( $db, 7, 100, 1048576, null, $memory );
		if ( $enabled ) { $this->property( $s, 'snapshot_events_enabled', true, true ); }
		return $s;
	}
	public function test_default_off_and_shadow_event_path_never_reduce_legacy_forecast(): void {
		$baseline = array(); $db = new SnapshotScaleConnection();
		$s = $this->snapshot( $db, function ( $bytes ) use ( &$baseline ) { $baseline[] = $bytes; return true; }, false );
		$s->replace_many( $this->change() );
		$this->assertNull( $this->property( $s, 'snapshot_owner' ) );
		$samples = array(); $db = new SnapshotScaleConnection();
		$s = $this->snapshot( $db, function ( $bytes ) use ( &$samples ) { $samples[] = $bytes; return true; } );
		$s->replace_many( $this->change() ); $owner = $this->property( $s, 'snapshot_owner' );
		$this->assertSame( 5, $this->property( $owner, 'events' ) );
		$this->assertSame( 0, $this->property( $owner, 'remaining' ) );
		$this->assertTrue( $this->property( $owner, 'complete' ) );
		$this->assertGreaterThan( 0, $this->property( $owner, 'private_bytes' ) );
		$this->assertSame( $baseline, $samples, 'Preserve every legacy sampler call and forecast input.' );
	}
	public function test_own_only_attachment_event_is_visible_at_next_observer_after_unwind(): void {
		$db = new SnapshotScaleConnection(); $s = null; $seen = array();
		$memory = function () use ( &$s, &$seen ) {
			if ( null === $s ) { return true; } $owner = $this->property( $s, 'snapshot_owner' );
			if ( null === $owner ) { return true; }
			$n = $this->property( $owner, 'events' );
			if ( $n && ! isset( $seen[$n] ) ) {
				$frames = 0; foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) { if ( 'rebuild_stream_node' === $frame['function'] ) { ++$frames; } }
				$seen[$n] = array( $this->property( $owner, 'last_depth' ), $frames, $this->property( $owner, 'last_bytes' ) );
			}
			return true;
		};
		$s = $this->snapshot( $db, $memory ); $s->replace_many( $this->change() );
		$this->assertCount( 5, $seen );
		$this->assertSame( array( 2, 2, 1, 1, 0 ), array_column( $seen, 0 ) );
		foreach ( $seen as $event ) { $this->assertLessThanOrEqual( $event[0], $event[1] ); }
		$this->assertLessThan( array_sum( array_column( $seen, 2 ) ), $seen[5][2], 'Root must not credit its whole subtree again.' );
	}
	public function test_fresh_read_ownership_and_stale_source_fallback(): void {
		$db = new SnapshotScaleConnection(); $s = $this->snapshot( $db ); $s->replace_many( $this->change() );
		$first = $this->property( $s, 'snapshot_owner' );
		$this->assertSame( $this->value(), $s->read_bounded( 'bucket' ) ); $second = $this->property( $s, 'snapshot_owner' );
		$this->assertNotSame( $first, $second ); $this->assertSame( 5, $this->property( $second, 'events' ) );
		$this->assertSame( $this->value(), $s->read_bounded( 'bucket' ) );
		$this->assertNotSame( $second, $this->property( $s, 'snapshot_owner' ) );
		$plans = $this->property( $s, 'snapshot_plans' ); $hash = key( $plans ); $plans[$hash]['digest'] = str_repeat( '0', 64 );
		$this->property( $s, 'snapshot_plans', $plans, true );
		$this->assertSame( $this->value(), $s->read_bounded( 'bucket' ) );
		$this->assertNull( $this->property( $s, 'snapshot_owner' ) );
	}
	public function test_source_binding_rejects_reused_reader_and_changed_identity(): void {
		$db = new SnapshotScaleConnection(); $s = $this->snapshot( $db ); $s->replace_many( $this->change() );
		$owner = $this->property( $s, 'snapshot_owner' ); $binding = $this->property( $owner, 'binding' );
		foreach ( array( 'blog', 'id', 'generation', 'revision', 'root', 'digest', 'key', 'stats' ) as $field ) {
			$changed = $binding; $changed[$field] = null;
			$this->assertFalse( $owner->matches( $changed ) );
		}
		$this->assertTrue( $owner->matches( $binding ) );
		$this->assertFalse( $owner->owns_source( new \stdClass() ) );
	}
	public function test_late_nonvalidation_and_fetch_exception_never_credit_root_or_close_owner(): void {
		foreach ( array( 'logical', 'projection', 'fetch' ) as $fault ) {
			$db = new SnapshotScaleConnection();
			$db->after_event = function ( $event, $db ) use ( $fault ) {
				if ( 'fetch' === $fault && 'fetch' === $event && 2 === $db->fetches ) { throw new \RuntimeException( 'owned-event-fetch' ); }
				if ( in_array( $fault, array( 'logical', 'projection' ), true ) && 'stats' === $event && count( $db->records ) ) {
					$rows = array_map( function ( $row ) { return iterator_to_array( $row ); }, $db->records );
					if ( 'logical' === $fault ) { $rows[0]['logical_hash'] = str_repeat( '0', 64 ); }
					else { $rows[0]['object_type'] = 'damaged'; }
					$db->records = $rows; $db->after_event = null;
				}
			};
			$s = $this->snapshot( $db );
			try { $s->replace_many( $this->change() ); $this->fail( 'Fault published.' ); } catch ( \RuntimeException $e ) { $this->assertNotEmpty( $e->getMessage() ); }
			$owner = $this->property( $s, 'snapshot_owner' ); $this->assertNotNull( $owner );
			$this->assertFalse( $this->property( $owner, 'complete' ) );
			$this->assertSame( 'fetch' === $fault ? 0 : 4, $this->property( $owner, 'events' ) );
			$this->assertGreaterThan( 0, $this->property( $owner, 'remaining' ) );
			$this->assertGreaterThan( 0, $this->property( $owner, 'private_bytes' ) );
			$this->assertSame( 1, $db->events['rollback'] ); $this->assertArrayNotHasKey( 'publish', $db->events );
		}
	}
	public function test_bounded_multi_page_and_empty_generation_complete_without_subtree_credit(): void {
		foreach ( array( array(), array( 'root' => array( 'children' => array_fill( 0, 36, 'child' ) ) ) ) as $value ) {
			$db = new SnapshotScaleConnection(); $s = $this->snapshot( $db ); $changes = $this->change(); $changes[0]['value'] = $value;
			$s->replace_many( $changes ); $owner = $this->property( $s, 'snapshot_owner' );
			$this->assertSame( count( $value ) ? 37 : 0, $this->property( $owner, 'events' ) );
			$this->assertSame( 0, $this->property( $owner, 'remaining' ) ); $this->assertTrue( $this->property( $owner, 'complete' ) );
			$this->assertSame( $value, $s->read_bounded( 'bucket' ) );
		}
	}
	public function test_missing_or_stale_scalar_plan_falls_back_without_reusing_completed_owner(): void {
		$db = new SnapshotScaleConnection(); $s = $this->snapshot( $db ); $s->replace_many( $this->change() );
		$original = $this->property( $s, 'snapshot_plans' ); $hash = key( $original );
		foreach ( array( 'key', 'root', 'stats' ) as $field ) {
			$plans = $original; $plans[$hash][$field] = null; $this->property( $s, 'snapshot_plans', $plans, true );
			$this->assertSame( $this->value(), $s->read_bounded( 'bucket' ) );
			$this->assertNull( $this->property( $s, 'snapshot_owner' ) );
		}
		// A separate repository has no source-derived plan, even if privately armed.
		$fresh = $this->snapshot( $db );
		$this->assertSame( $this->value(), $fresh->read_bounded( 'bucket' ) );
		$this->assertNull( $this->property( $fresh, 'snapshot_owner' ) );
	}
	public function test_scaled_final_token_digest_interruption_keeps_private_owner(): void {
		$db = new SnapshotScaleConnection(); $s = null; $now = 100.0; $ticks = 0;
		$clock = function () use ( &$s, &$now, &$ticks ) {
			if ( null === $s ) { return $now; } $owner = $this->property( $s, 'snapshot_owner' );
			if ( null !== $owner && 5 === $this->property( $owner, 'events' ) ) {
				foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) {
					if ( 'snapshot_footprint' === $frame['function'] && 3 === ++$ticks ) { $now = 111.0; }
				}
			}
			return $now;
		};
		$scale = StorageScaleAdmissionTest::admission( null, $clock, function () { return array( ini_get( 'memory_limit' ), memory_get_usage( true ) ); } );
		$s = new LegacyTableSnapshot( $db, 7, 100, 1048576, null, null, $scale );
		$this->property( $s, 'snapshot_events_enabled', true, true );
		try { $s->replace_many( $this->change() ); $this->fail( 'Token/digest failure published.' ); }
		catch ( \RuntimeException $e ) { $this->assertStringContainsString( 'scale admission refused', $e->getMessage() ); }
		$owner = $this->property( $s, 'snapshot_owner' );
		$this->assertSame( 3, $ticks ); $this->assertFalse( $this->property( $owner, 'complete' ) );
		$this->assertGreaterThan( 0, $this->property( $owner, 'private_bytes' ) );
		$this->assertSame( 1, $db->events['rollback'] ); $this->assertArrayNotHasKey( 'publish', $db->events );
	}
	public function test_descriptor_admission_cannot_replace_actual_backing_with_low_observer(): void {
		$limits = StorageScaleAdmissionTest::policy(); $limits['memory_ceiling_bytes'] = 33554433;
		$scale = StorageScaleAdmissionTest::admission( $limits, null, function () { return array( '-1', 0 ); } );
		$db = new SnapshotScaleConnection(); $s = new LegacyTableSnapshot( $db, 7, 100, 1048576, null, null, $scale );
		$this->property( $s, 'snapshot_events_enabled', true, true );
		try { $s->replace_many( $this->change() ); $this->fail( 'Low observer bypassed actual backing.' ); }
		catch ( \RuntimeException $e ) { $this->assertStringContainsString( 'scale admission refused', $e->getMessage() ); }
		$this->assertSame( array(), $db->records ); $this->assertSame( array(), $db->heads );
	}
	public function test_complete_digest_keeps_owner_private_and_full_future_on_interrupt(): void {
		$db = new SnapshotScaleConnection(); $s = null; $seen = false;
		$s = $this->snapshot( $db, function () use ( &$s, &$seen, $db ) {
			if ( ! $db->fetches || null === $s ) { return true; }
			$owner = $this->property( $s, 'snapshot_owner' );
			if ( null !== $owner && 5 === $this->property( $owner, 'events' ) ) {
				foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) {
					if ( 'read_generation' === $frame['function'] ) { return true; }
				}
				$seen = true; $this->assertFalse( $this->property( $owner, 'complete' ) );
				$this->assertGreaterThan( 0, $this->property( $owner, 'private_bytes' ) ); return false;
			}
			return true;
		} );
		try { $s->replace_many( $this->change() ); $this->fail( 'Digest interruption published.' ); } catch ( \RuntimeException $e ) { $this->assertTrue( $seen ); }
		$this->assertFalse( $this->property( $this->property( $s, 'snapshot_owner' ), 'complete' ) );
		$this->assertSame( 1, $db->events['rollback'] );
	}
}
