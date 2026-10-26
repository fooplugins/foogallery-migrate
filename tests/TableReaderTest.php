<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use FooPlugins\FooGalleryMigrate\TableReader;
use PHPUnit\Framework\TestCase;

class TableReaderTest extends TestCase {
	private $db;
	private $codec;
	protected function setUp(): void {
		$this->db = new LegacyReaderTestWpdb();
		$this->codec = new LegacyStateCodec();
		$this->db->head = array( 'id' => '3', 'blog_id' => '7', 'state_key_value' => $this->codec->key_value( 'migrated' ),
			'active_generation' => '2', 'revision' => '8', 'codec_version' => '1', 'record_count' => '1', 'payload_bytes' => '200',
			'state_order' => '0', 'state_kind' => 'map', 'root_payload_bytes' => '10' );
		$payload = $this->codec->encode( array( 'unknown' => "\xff", 'ID' => '007' ) );
		$this->db->record = array( 'id' => '9', 'blog_id' => '7', 'state_id' => '3', 'generation' => '2',
			'node_hash' => $this->codec->node_hash( $this->codec->key_hash( 'migrated' ), null, '007' ),
			'parent_node_hash' => null, 'item_order' => '0', 'key_type' => 'string', 'key_value' => base64_encode( '007' ),
			'payload' => $payload, 'mutable_payload' => $this->codec->encode( array( 'migrated' => true ) ),
			'payload_hash' => hash( 'sha256', $payload ), 'logical_hash' => str_repeat( 'a', 64 ),
			'payload_bytes' => strlen( $payload ) + strlen( $this->codec->encode( array( 'migrated' => true ) ) ),
			'legacy_identifier_value' => $this->codec->encode( 'image_MixedCase_' . str_repeat( 'x', 250 ) ),
			'source_plugin_value' => null, 'source_id_value' => null );
	}

	public function test_reader_is_inert_and_reads_only_bounded_current_blog_generation(): void {
		$reader = new TableReader( $this->db, 7, $this->codec );
		$this->assertSame( array(), $this->db->queries );
		$page = $reader->record_page( 'migrated', 1 );
		$this->assertSame( '007', $page['rows'][0]['key'] );
		$this->assertSame( array( 'unknown' => "\xff", 'ID' => '007' ), $page['rows'][0]['base'] );
		$this->assertSame( array( 'migrated' => true ), $page['rows'][0]['mutable'] );
		$this->assertSame( 8, $page['cursor']['revision'] );
		foreach ( $this->db->queries as $query ) {
			$this->assertStringContainsString( 'blog_id = 7', $query );
			$this->assertStringNotContainsString( 'SELECT *', $query );
		}
		$this->assertStringContainsString( 'state_id = 3 AND generation = 2', $this->db->queries[1] );
		$this->assertStringContainsString( 'LIMIT 1', $this->db->queries[1] );
		$this->assertStringContainsString( 'ORDER BY item_order ASC, id ASC', $this->db->queries[1] );
	}

	public function test_missing_head_is_not_an_empty_collection(): void {
		$this->db->head = null;
		$this->expectException( \RuntimeException::class );
		( new TableReader( $this->db, 7 ) )->record_page( 'migrated', 1 );
	}

	/** @dataProvider readerFaults */
	public function test_corruption_and_concurrent_changes_fail_closed( $fault ): void {
		$this->db->fault = $fault;
		$this->expectException( \RuntimeException::class );
		( new TableReader( $this->db, 7 ) )->record_page( 'migrated', 1 );
	}

	public static function readerFaults(): array {
		return array( array( 'query' ), array( 'key-collision' ), array( 'payload-hash' ), array( 'changed-head' ),
			array( 'wrong-blog' ), array( 'wrong-state' ), array( 'wrong-generation' ), array( 'unknown-codec' ), array( 'oversized' ), array( 'node-hash' ) );
	}

	public function test_oversized_rows_are_refused_before_payload_fetch(): void {
		$this->db->fault = 'oversized';
		try { ( new TableReader( $this->db, 7 ) )->record_page( 'migrated', 1 ); $this->fail(); }
		catch ( \RuntimeException $error ) { $this->assertCount( 2, $this->db->queries ); }
	}

	public function test_row_and_page_budgets_and_stale_cursors_are_refused(): void {
		$reader = new TableReader( $this->db, 7, $this->codec, 100 );
		foreach ( array( 0, 101, '1' ) as $limit ) {
			try { $reader->record_page( 'migrated', $limit ); $this->fail(); }
			catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'storage', $error->getMessage() ); }
		}
		$this->assertSame( array(), $this->db->queries );
		$this->expectException( \RuntimeException::class );
		$reader->record_page( 'migrated', 1 );
	}
}

class LegacyReaderTestWpdb {
	public $base_prefix = 'wp_';
	public $last_error = '';
	public $head;
	public $record;
	public $fault = '';
	public $queries = array();
	private $head_reads = 0;
	public function prepare( $sql, $args ) {
		foreach ( $args as $arg ) {
			$sql = preg_replace_callback( '/%[ds]/', function( $match ) use ( $arg ) { return '%d' === $match[0] ? (string) (int) $arg : "'" . addslashes( $arg ) . "'"; }, $sql, 1 );
		}
		return $sql;
	}
	public function get_results( $sql, $format ) {
		$this->queries[] = $sql;
		if ( 'query' === $this->fault ) { $this->last_error = 'fixture error'; return null; }
		if ( false !== strpos( $sql, '_state`' ) ) {
			$this->head_reads++;
			if ( null === $this->head ) { return array(); }
			$head = $this->head;
			if ( 'key-collision' === $this->fault ) { $head['state_key_value'] = 'wrong'; }
			if ( 'unknown-codec' === $this->fault ) { $head['codec_version'] = '99'; }
			if ( 'changed-head' === $this->fault && $this->head_reads > 1 ) { $head['revision'] = '9'; }
			return array( $head );
		}
		$row = $this->record;
		if ( 'payload-hash' === $this->fault ) { $row['payload_hash'] = str_repeat( '0', 64 ); }
		if ( 'node-hash' === $this->fault ) { $row['node_hash'] = str_repeat( '0', 64 ); }
		if ( 'wrong-blog' === $this->fault ) { $row['blog_id'] = '8'; }
		if ( 'wrong-state' === $this->fault ) { $row['state_id'] = '4'; }
		if ( 'wrong-generation' === $this->fault ) { $row['generation'] = '3'; }
		$row['encoded_bytes'] = strlen( $row['payload'] ) + strlen( $row['mutable_payload'] ) + strlen( $row['key_value'] ) + strlen( $row['legacy_identifier_value'] );
		if ( 'oversized' === $this->fault ) { $row['encoded_bytes'] = 99999999; }
		if ( false === strpos( $sql, ', key_type, key_value' ) ) {
			$row = array_intersect_key( $row, array_flip( array( 'id', 'blog_id', 'state_id', 'generation', 'item_order', 'node_hash', 'parent_node_hash', 'payload_hash', 'logical_hash', 'payload_bytes', 'encoded_bytes' ) ) );
		}
		return array( $row );
	}
}
