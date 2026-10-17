<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\LegacyRecordCodec;
use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use FooPlugins\FooGalleryMigrate\GenericRetainingModel;
use FooPlugins\FooGalleryMigrate\StorageScaleAdmission;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/StorageScaleAdmissionTest.php';

class PreorderIndexLifetimeTest extends TestCase {
	private function rows( $tree ) {
		$rows = array();
		( new LegacyRecordCodec() )->flatten_tree( 'galleries', 'root', $tree, 0, function ( $row ) use ( &$rows ) { $rows[] = $row; } );
		return $rows;
	}

	private function codec( &$observations, &$budget ) {
		// Observe only scalar counts, never retain/export a producer array. The
		// trusted clock stays constant; actual memory sampling is unchanged.
		$clock = function () use ( &$observations ) {
			foreach ( debug_backtrace( 0, 8 ) as $frame ) {
				if ( isset( $frame['class'], $frame['function'], $frame['args'][5] ) &&
					LegacyRecordCodec::class === $frame['class'] && 'rebuild_node' === $frame['function'] ) {
					$observations[] = array( count( $frame['args'][5] ), isset( $frame['args'][8] ) ? $frame['args'][8] : null );
					break;
				}
			}
			return 100.0;
		};
		$budget = StorageScaleAdmissionTest::admission( null, $clock );
		$codec = new LegacyRecordCodec( null, 24576, 16777216, $budget );
		$this->assertTrue( GenericRetainingModel::supports( $codec, new LegacyStateCodec( 1048576, 64, 10000, $budget ), $budget ), 'Run on the exact retaining host; no fallback/skip credit.' );
		return $codec;
	}

	public function test_certified_cursor_covers_every_row_without_retaining_the_identity_map(): void {
		$tree = array( 'children' => array( 'a' => array( 'children' => array( 'leaf' => null ) ), 'b' => 'B' ) );
		$rows = $this->rows( $tree ); $before = serialize( $rows ); $export = $rows;
		// Database-normalized topology must still certify, with immutable caller rows.
		foreach ( $rows as &$row ) {
			foreach ( array( 'node_hash', 'parent_node_hash', 'payload_hash', 'logical_hash' ) as $field ) {
				if ( null !== $row[$field] ) { $row[$field] = strtoupper( $row[$field] ); }
			}
			foreach ( array( 'depth', 'item_order', 'payload_bytes' ) as $field ) { $row[$field] = (string) $row[$field]; }
		}
		unset( $row ); $normalized = serialize( $rows );
		$seen = array(); $budget = null; $codec = $this->codec( $seen, $budget );
		$this->assertSame( serialize( $tree ), serialize( $codec->rebuild_tree( 'galleries', $rows[0]['node_hash'], $rows ) ) );
		$this->assertNotEmpty( $seen );
		foreach ( $seen as $sample ) { $this->assertSame( 0, $sample[0], 'Certified hydration must not retain the completed identity/visited map.' ); $this->assertIsInt( $sample[1] ); }
		$this->assertSame( $normalized, serialize( $rows ) ); $this->assertSame( $before, serialize( $export ) );
		$this->assertSame( array(), ( new \ReflectionProperty( StorageScaleAdmission::class, 'retaining_phases' ) )->getValue( $budget ) );
	}

	public function test_noncertified_positions_keep_indexed_coverage_and_caller_pointer(): void {
		$tree = array( 'children' => array( 'a' => null, 'b' => array( 'children' => array( 'z' => 'Z' ) ) ) );
		$dense = $this->rows( $tree ); $rows = array();
		foreach ( array_reverse( $dense ) as $i => $row ) { $rows['position-' . $i] = $row; }
		next( $rows ); $pointer = key( $rows ); $before = serialize( $rows );
		$seen = array(); $budget = null; $codec = $this->codec( $seen, $budget );
		$this->assertSame( $tree, $codec->rebuild_tree( 'galleries', $dense[0]['node_hash'], $rows ) );
		$this->assertNotEmpty( $seen );
		foreach ( $seen as $sample ) { $this->assertSame( count( $rows ), $sample[0] ); $this->assertNull( $sample[1] ); }
		$this->assertSame( $pointer, key( $rows ) ); $this->assertSame( $before, serialize( $rows ) );
	}

	public function test_duplicate_identity_is_rejected_before_any_hydration_even_if_topology_certifies(): void {
		$rows = $this->rows( array( 'children' => array( 'a' => 'A', 'b' => 'B' ) ) );
		$rows[2] = $rows[1]; $rows[2]['item_order'] = 1;
		$seen = array(); $budget = null; $codec = $this->codec( $seen, $budget );
		try { $codec->rebuild_tree( 'galleries', $rows[0]['node_hash'], $rows ); $this->fail( 'Duplicate identity accepted.' ); }
		catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'Duplicate node identity', $error->getMessage() ); }
		$this->assertSame( array(), $seen );
		$phases = ( new \ReflectionProperty( StorageScaleAdmission::class, 'retaining_phases' ) )->getValue( $budget );
		$this->assertCount( 1, $phases ); $phase = reset( $phases );
		$this->assertGreaterThan( 0, $phase['indexes'] ); $this->assertSame( 0, $phase['next'] );
	}

	public function test_corrupt_identity_checksum_or_topology_never_returns_a_partial_tree(): void {
		$good = $this->rows( array( 'children' => array( 'a' => array( 'children' => array( 'z' => 'Z' ) ), 'b' => null ) ) );
		$cases = array();
		foreach ( array( 'node_hash', 'parent_node_hash', 'payload_hash' ) as $field ) { $bad = $good; $bad[2][$field] = str_repeat( 'f', 64 ); $cases[] = $bad; }
		$bad = $good; $bad[2]['parent_node_hash'] = $bad[2]['node_hash']; $cases[] = $bad;
		$bad = $good; $bad[2]['depth'] = 1; $cases[] = $bad;
		$bad = $good; $bad[3]['item_order'] = 0; $cases[] = $bad;
		$bad = $good; array_pop( $bad ); $cases[] = $bad;
		$bad = $good; $bad[] = $good[0]; $cases[] = $bad;
		foreach ( $cases as $rows ) {
			$seen = array(); $budget = null; $codec = $this->codec( $seen, $budget );
			try { $codec->rebuild_tree( 'galleries', $rows[0]['node_hash'], $rows ); $this->fail( 'Malformed tree accepted.' ); }
			catch ( \RuntimeException $error ) { $this->assertNotEmpty( $error->getMessage() ); }
		}
	}
}
