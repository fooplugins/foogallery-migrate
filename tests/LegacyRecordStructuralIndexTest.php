<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\LegacyRecordCodec;
use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use PHPUnit\Framework\TestCase;

class LegacyRecordStructuralIndexTest extends TestCase {
	private function rows( $codec ) {
		$record = array( 'children' => array( 'a' => array( 'title' => 'A' ), 'b' => array( 'title' => 'B' ), 'c' => array( 'title' => 'C' ) ), 'title' => 'root' );
		$rows = array();
		$codec->flatten_tree( 'galleries', 'root', $record, 0, function ( $row ) use ( &$rows ) { $rows[] = $row; } );
		return array( $record, $rows );
	}

	public function test_hydration_interleaves_with_completed_records_not_an_all_row_decoded_cache(): void {
		$spy = new StructuralIndexCodecSpy(); $codec = new LegacyRecordCodec( $spy );
		list( $record, $rows ) = $this->rows( $codec );
		$before = serialize( $rows ); $spy->observe = true;
		$this->assertSame( $record, $codec->rebuild_tree( 'galleries', $rows[0]['node_hash'], $rows ) );
		$this->assertSame( array( 2, 3, 4, 4 ), $spy->decoded_at_digest );
		$this->assertSame( $before, serialize( $rows ) );
	}

	public function test_all_encoded_structure_is_validated_before_first_hydration(): void {
		$spy = new StructuralIndexCodecSpy(); $codec = new LegacyRecordCodec( $spy );
		list( $record, $rows ) = $this->rows( $codec );
		$rows[3]['payload_hash'] = str_repeat( '0', 64 ); $spy->observe = true;
		try { $codec->rebuild_tree( 'galleries', $rows[0]['node_hash'], $rows ); $this->fail( 'Corrupt encoded row was accepted.' ); }
		catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'integrity', $error->getMessage() ); }
		$this->assertSame( 0, $spy->decoded );
	}

	public function test_sparse_string_positions_and_database_normalization_leave_rows_immutable(): void {
		$codec = new LegacyRecordCodec(); list( $record, $rows ) = $this->rows( $codec );
		$indexed = array();
		foreach ( array_reverse( $rows ) as $position => $row ) {
			foreach ( array( 'node_hash', 'parent_node_hash', 'payload_hash', 'logical_hash' ) as $field ) {
				if ( null !== $row[$field] ) { $row[$field] = strtoupper( $row[$field] ); }
			}
			foreach ( array( 'depth', 'item_order', 'payload_bytes' ) as $field ) { $row[$field] = (string) $row[$field]; }
			$indexed['position-' . $position] = $row;
		}
		$before = serialize( $indexed );
		$this->assertSame( $record, $codec->rebuild_tree( 'galleries', $rows[0]['node_hash'], $indexed ) );
		$this->assertSame( $before, serialize( $indexed ) );
	}
}

class StructuralIndexCodecSpy extends LegacyStateCodec {
	public $observe = false;
	public $decoded = 0;
	public $decoded_at_digest = array();
	public function decode_record_payload( $json ) {
		if ( $this->observe ) { ++$this->decoded; }
		return parent::decode_record_payload( $json );
	}
	public function snapshot_digest( $value ) {
		if ( $this->observe ) { $this->decoded_at_digest[] = $this->decoded; }
		return parent::snapshot_digest( $value );
	}
}
