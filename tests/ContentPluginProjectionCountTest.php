<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\ContentScanRepository;
use FooPlugins\FooGalleryMigrate\LegacyCollectionEnvelope;
use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use FooPlugins\FooGalleryMigrate\LegacyTableRepository;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;
use PHPUnit\Framework\TestCase;

class ContentPluginProjectionCountTest extends TestCase {
	private function plugin_count( $db, $plugin = 'WordPress Core' ) {
		return ( new LegacyTableRepository( $db, 1 ) )->content_count_plugin( $plugin );
	}
	public function test_indexed_count_reads_only_matching_raw_plugin_values(): void {
		$db = new ContentPluginProjectionConnection();
		$this->assertSame( 2, $this->plugin_count( $db ) );
		$this->assertSame( 1, $db->metadata_pages );
		$this->assertSame( 1, $db->raw_reads );
		$this->assertSame( 0, $db->unrelated_reads );
		$this->assertSame( 0, $db->writes );
		$this->assertSame( 0, $this->plugin_count( new ContentPluginProjectionConnection(), 'Absent' ) );
	}
	public function test_collision_oversize_stale_and_sql_failure_refuse(): void {
		foreach ( array( 'collision', 'oversize', 'stale', 'sql_failure', 'missing_raw' ) as $mode ) {
			$db = new ContentPluginProjectionConnection(); $db->mode = $mode;
			try { $this->plugin_count( $db ); $this->fail( 'Expected indexed content refusal: ' . $mode ); }
			catch ( \RuntimeException $error ) { $this->assertNotSame( '', $error->getMessage() ); }
			$this->assertSame( 0, $db->writes );
			if ( 'oversize' === $mode ) { $this->assertSame( 0, $db->raw_reads ); }
		}
	}
	public function test_matching_values_use_one_bounded_raw_query_per_metadata_page(): void {
		$db = new ContentPluginProjectionConnection( 101 );
		$this->assertSame( 101, $this->plugin_count( $db ) );
		$this->assertSame( 2, $db->metadata_pages );
		$this->assertSame( 2, $db->raw_reads );
		$this->assertSame( 0, $db->unrelated_reads );
		$this->assertSame( 0, $db->writes );
	}
	public function test_absent_state_is_empty_but_a_new_head_refuses(): void {
		$absent = new ContentPluginProjectionConnection(); $absent->mode = 'absent';
		$this->assertSame( 0, $this->plugin_count( $absent ) );
		$this->assertSame( 0, $absent->metadata_pages );
		$appearing = new ContentPluginProjectionConnection(); $appearing->mode = 'appears';
		$this->expectException( \RuntimeException::class );
		$this->plugin_count( $appearing );
	}
	public function test_filtered_window_uses_existing_source_plugin_index_and_fixed_limit(): void {
		$db = new ContentPluginProjectionConnection();
		$page = ( new LegacyTableRepository( $db, 1 ) )->content_window( 0, 100, null, 'WordPress Core' );
		$this->assertSame( array(), $page['items'] );
		$this->assertSame( 202, $page['total'] );
		$this->assertSame( 1, $page['revision'] );
		$this->assertSame( 1, $db->selector_reads );
		$this->assertStringContainsString( 'FORCE INDEX (`source_plugin`)', $db->selector_sql );
		$this->assertStringContainsString( 'source_plugin_hash = UNHEX(%s)', $db->selector_sql );
		$this->assertStringContainsString( 'LIMIT %d OFFSET %d', $db->selector_sql );
	}
}

class ContentPluginProjectionConnection extends LegacyTransactionConnection {
	public $base_prefix = 'wp_';
	public $last_error = '';
	public $mode = 'normal';
	public $metadata_pages = 0;
	public $raw_reads = 0;
	public $unrelated_reads = 0;
	public $writes = 0;
	public $selector_reads = 0;
	public $selector_sql = '';
	private $args = array();
	private $codec;
	private $root;
	private $head_reads = 0;
	private $rows;
	public function __construct( $matching = 2 ) {
		$this->codec = new LegacyStateCodec();
		$items = array();
		for ( $i = 0; $i < 200; $i++ ) { $items[] = array( 'plugin_name' => 'Unrelated' ); }
		for ( $i = 0; $i < $matching; $i++ ) { $items[] = array( 'plugin_name' => 'WordPress Core' ); }
		$split = ( new LegacyCollectionEnvelope( $this->codec ) )->split( ContentScanRepository::STATE_KEY, 'map',
			array( 'items' => $items, 'progress' => array() ) );
		$this->root = $this->codec->encode_collection_root( $split['root'] );
		$this->rows = array();
		for ( $i = 0; $i < $matching; $i++ ) { $this->rows[] = array( 'id' => 101 + $i, 'item_order' => 200 + $i ); }
	}
	public function prepare( $sql, $args ) { $this->args = $args; return $sql; }
	public function get_results( $sql, $format ) {
		$this->last_error = '';
		if ( false !== strpos( $sql, 'OCTET_LENGTH(root_payload) AS root_payload_bytes' ) ) {
			$this->head_reads++;
			if ( 'absent' === $this->mode || ( 'appears' === $this->mode && $this->head_reads <= 3 ) ) { return array(); }
			return array( array( 'id' => '1', 'blog_id' => '1', 'state_order' => '0', 'state_kind' => 'map',
				'active_generation' => '1', 'revision' => 'stale' === $this->mode && $this->head_reads > 1 ? '2' : '1',
				'codec_version' => '1', 'record_count' => (string) ( 200 + count( $this->rows ) ), 'payload_bytes' => (string) strlen( $this->root ),
				'root_payload_bytes' => (string) strlen( $this->root ), 'state_key_value' => $this->codec->key_value( ContentScanRepository::STATE_KEY ) ) );
		}
		if ( false !== strpos( $sql, 'SELECT root_payload FROM' ) ) { return array( array( 'root_payload' => $this->root ) ); }
		if ( false !== strpos( $sql, 'SELECT COUNT(*) AS total' ) ) {
			return array( array( 'total' => $this->args[3] === $this->codec->key_hash( 'WordPress Core' ) ? (string) count( $this->rows ) : '0' ) );
		}
		if ( false !== strpos( $sql, 'SELECT HEX(node_hash) AS node_hash' ) ) {
			$this->selector_reads++; $this->selector_sql = $sql;
			return array();
		}
		if ( false !== strpos( $sql, 'AS plugin_bytes' ) ) {
			$this->metadata_pages++;
			if ( 'sql_failure' === $this->mode ) { $this->last_error = 'Injected SQL error'; return false; }
			if ( $this->args[3] !== $this->codec->key_hash( 'WordPress Core' ) ) { return array(); }
			$selected = array(); $last_order = $this->args[4]; $last_id = $this->args[6];
			foreach ( $this->rows as $row ) {
				if ( $row['item_order'] < $last_order || ( $row['item_order'] === $last_order && $row['id'] <= $last_id ) ) { continue; }
				$selected[] = array( 'id' => (string) $row['id'], 'item_order' => (string) $row['item_order'],
					'plugin_bytes' => 'oversize' === $this->mode ? '1048577' : (string) strlen( $this->codec->encode( 'WordPress Core' ) ) );
			}
			return array_slice( $selected, 0, 100 );
		}
		if ( false !== strpos( $sql, 'HEX(source_plugin_hash) AS plugin_hash' ) ) {
			$this->raw_reads++;
			if ( 'missing_raw' === $this->mode ) { return array(); }
			$values = array();
			for ( $index = 4; $index < count( $this->args ); $index += 2 ) {
				$id = $this->args[$index];
				if ( $id < 101 || $id >= 101 + count( $this->rows ) ) { $this->unrelated_reads++; continue; }
				$value = 'collision' === $this->mode && 101 === $id ? $this->codec->encode( 'Wrong Plugin' ) : $this->codec->encode( 'WordPress Core' );
				$values[] = array( 'id' => (string) $id, 'item_order' => (string) ( 200 + $id - 101 ),
					'plugin_hash' => $this->codec->key_hash( 'WordPress Core' ), 'source_plugin_value' => $value );
			}
			return $values;
		}
		throw new \RuntimeException( 'Unexpected content count SQL: ' . $sql );
	}
	public function query( $sql ) { $this->writes++; throw new \RuntimeException( 'Unexpected write.' ); }
}
