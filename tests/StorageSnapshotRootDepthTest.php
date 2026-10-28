<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\TableSnapshot;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/StorageScaleAdmissionTest.php';
require_once __DIR__ . '/support/SnapshotScaleConnection.php';

/** Collection metadata must not consume the complete state's logical depth. */
class StorageSnapshotRootDepthTest extends TestCase {
	public static function boundaries(): array {
		return array( 'compact2' => array( 2 ), 'header3' => array( 3 ), 'header4' => array( 4 ), 'header8' => array( 8 ) );
	}

	private function budget( $depth ) {
		$p = StorageScaleAdmissionTest::policy(); $p['max_depth'] = $depth;
		return StorageScaleAdmissionTest::admission( $p, function () { return 100.0; } );
	}
	private function snapshot( $db, $budget ) {
		return new TableSnapshot( $db, 7, $budget->limit( 'max_rows' ), $budget->limit( 'max_bytes' ), null, null, $budget );
	}
	private function changes( $value ) {
		return array( array( 'key' => 'galleries', 'order' => 0, 'kind' => 'map', 'value' => $value, 'expected_revision' => null ) );
	}
	private function wrapper( $depth ) {
		$value = array( '_foogallery_migrate_compact' => 1, 'type' => 'migratable', 'items' => array() );
		if ( $depth > 2 ) {
			$header = 'x';
			for ( $i = 2; $i < $depth; ++$i ) { $header = array( 'x' => $header ); }
			$value['unknown'] = $header;
		}
		return $value;
	}
	/** Independent depth includes the complete state's outer array and array keys. */
	private function depth( $value ) {
		$depth = 0;
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) { $depth = max( $depth, 1 + $this->depth( $key ), 1 + $this->depth( $item ) ); }
		}
		return $depth;
	}
	private function decoder_parity( $value, $budget, $depth ) {
		$raw = serialize( array( 'galleries' => $value ) );
		$this->assertSame( $depth, $this->depth( array( 'galleries' => $value ) ) );
		$this->assertSame( $raw, serialize( $budget->decoder( strlen( $raw ) )->decode( $raw ) ) );
	}
	/** @dataProvider boundaries */
	public function test_complete_exact_depth_write_and_read( $depth ): void {
		$value = $this->wrapper( $depth ); $budget = $this->budget( $depth );
		$this->decoder_parity( $value, $budget, $depth );
		$db = new SnapshotScaleConnection(); $snapshot = $this->snapshot( $db, $budget );
		$snapshot->replace_many( $this->changes( $value ) );
		$this->assertSame( $value, $snapshot->read_bounded( 'galleries' ) );
		$this->assertSame( 1, $db->events['commit'] );
		$this->assertArrayNotHasKey( 'rollback', $db->events );
	}
	/** Stored data is produced by the real writer, not hand-crafted JSON. @dataProvider boundaries */
	public function test_authentic_stored_exact_depth_read( $depth ): void {
		$value = $this->wrapper( $depth ); $db = new SnapshotScaleConnection();
		$this->snapshot( $db, $this->budget( $depth + 1 ) )->replace_many( $this->changes( $value ) );
		$heads = $db->heads; $records = $db->records;
		$this->assertSame( $value, $this->snapshot( $db, $this->budget( $depth ) )->read_bounded( 'galleries' ) );
		$this->assertSame( $heads, $db->heads ); $this->assertSame( $records, $db->records );
	}
	/** The representation allowance must not admit logical +1. @dataProvider boundaries */
	public function test_complete_depth_plus_one_write_and_authentic_read_refuse( $depth ): void {
		$value = $this->wrapper( $depth + 1 ); $raw = serialize( array( 'galleries' => $value ) );
		foreach ( array( 'decoder', 'write', 'read' ) as $operation ) {
			$db = new SnapshotScaleConnection();
			if ( 'read' === $operation ) { $this->snapshot( $db, $this->budget( $depth + 2 ) )->replace_many( $this->changes( $value ) ); }
			$heads = $db->heads; $records = $db->records; $db->events = array();
			try {
				$budget = $this->budget( $depth );
				if ( 'decoder' === $operation ) { $budget->decoder( strlen( $raw ) )->decode( $raw ); }
				elseif ( 'write' === $operation ) { $this->snapshot( $db, $budget )->replace_many( $this->changes( $value ) ); }
				else { $this->snapshot( $db, $budget )->read_bounded( 'galleries' ); }
				$this->fail( 'Logical +1 admitted: ' . $operation );
			} catch ( \RuntimeException $error ) {
				$this->assertStringContainsString( 'decoder' === $operation ? 'Unsupported or unbounded' : 'depth', $error->getMessage() );
			}
			$this->assertSame( $heads, $db->heads ); $this->assertSame( $records, $db->records );
			if ( 'read' !== $operation ) { $this->assertSame( array(), $db->events ); $this->assertSame( array(), $db->queries ); }
			else { $this->assertSame( 0, $db->events['commit'] ?? 0 ); }
		}
	}
	public function test_root_allowance_does_not_change_public_or_no_scale_physical_limits(): void {
		$root = array( 'wrapper' => array( 'values' => array( 'header' => 'x' ) ) ); // Physical depth 3.
		$codec = new \FooPlugins\FooGalleryMigrate\LegacyStateCodec( 1048576, 64, 10000, $this->budget( 2 ) );
		$json = $codec->encode_collection_root( $root );
		$this->assertSame( $root, $codec->decode_collection_root( $json ) );
		$default = new \FooPlugins\FooGalleryMigrate\LegacyStateCodec();
		$this->assertSame( $default->encode( $root ), $default->encode_collection_root( $root ) );
		$this->assertSame( $default->decode( $json ), $default->decode_collection_root( $json ) );
		$no_scale = new \FooPlugins\FooGalleryMigrate\LegacyStateCodec( 1048576, 2 );
		foreach ( array( $codec, $no_scale ) as $subject ) {
			$operations = array( 'encode', 'decode', 'inspect_json' );
			if ( $subject === $no_scale ) { $operations = array_merge( $operations, array( 'encode_collection_root', 'decode_collection_root' ) ); }
			foreach ( $operations as $method ) {
				try { $subject->$method( 0 === strpos( $method, 'encode' ) ? $root : $json ); $this->fail( 'Physical limit changed: ' . $method ); }
				catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'depth', $error->getMessage() ); }
			}
		}
		$deeper = array( 'wrapper' => $root ); // Physical depth 4 exceeds the bounded allowance.
		foreach ( array( 'encode_collection_root', 'decode_collection_root' ) as $method ) {
			try { $codec->$method( 'encode_collection_root' === $method ? $deeper : $default->encode( $deeper ) ); $this->fail( 'Unbounded root allowance.' ); }
			catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'depth', $error->getMessage() ); }
		}
	}
	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_original20k_with_logical8_unknown_header_write_and_authentic_read(): void {
		ini_set( 'memory_limit', '256M' ); $this->assertSame( '256M', ini_get( 'memory_limit' ) );
		$original = StorageA7Fixture::value( 20000 );
		$this->assertSame( '6022f4ad5a1e8ef9a39a5b5284dc576c7eb75d02a0d69949a58e3967ca9dd4be', hash( 'sha256', serialize( $original ) ) );
		$value = $original['galleries']; $value['unknown'] = $this->wrapper( 8 )['unknown'];
		$budget = $this->budget( 8 ); $this->decoder_parity( $value, $budget, 8 );
		$db = new SnapshotScaleConnection(); $snapshot = $this->snapshot( $db, $budget );
		$snapshot->replace_many( $this->changes( $value ) );
		$this->assertSame( $value, $snapshot->read_bounded( 'galleries' ) );
		$this->assertSame( $value, $this->snapshot( $db, $this->budget( 8 ) )->read_bounded( 'galleries' ) );
		$this->assertSame( 20208, $db->emitted ); $this->assertCount( 20208, $db->records );
		$this->assertSame( 1, $db->events['commit'] );
		$this->assertArrayNotHasKey( 'rollback', $db->events );
	}
}
