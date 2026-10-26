<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\ContentScanRepository;
use FooPlugins\FooGalleryMigrate\LegacyCollectionEnvelope;
use FooPlugins\FooGalleryMigrate\LegacyRecordCodec;
use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use FooPlugins\FooGalleryMigrate\LegacyTableBackend;
use FooPlugins\FooGalleryMigrate\LegacyTableRepository;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use FooPlugins\FooGalleryMigrate\Migrators\ContentMigrator;
use PHPUnit\Framework\TestCase;

class ContentSelectedBudgetTest extends TestCase {
	public function test_hundred_key_page_fits_but_next_page_refuses_before_payload_and_side_effects(): void {
		$db = new ContentSelectedBudgetConnection( 101 ); $keys = $db->keys();
		$first_page_bytes = 0;
		foreach ( array_slice( $keys, 0, 100 ) as $key ) { $first_page_bytes += $db->encoded_bytes( $key ); }
		$limit = $db->root_bytes() + $db->key_bytes( $keys ) + $first_page_bytes + $db->encoded_bytes( $keys[100] ) - 1;
		$backend = new LegacyTableBackend( $db, 1, 10000, $limit );
		$engine = new ContentSelectedBudgetEngine( new ContentScanRepository( $backend, new MigratorSettings( $backend ) ) );
		$GLOBALS['foogallery_migrate_test_posts'][9902] = (object) array( 'ID' => 9902, 'post_content' => 'protected' );
		try { ( new ContentMigrator( $engine, 'block-shortcode' ) )->replace_content( $keys );
			$this->fail( 'The second selected page must refuse before its payload.' ); }
		catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'budget', $error->getMessage() ); }
		$this->assertSame( 100, $db->payload_reads );
		$this->assertSame( 0, $db->writes );
		$this->assertSame( 0, $engine->queue_writes );
		$this->assertSame( 'protected', $GLOBALS['foogallery_migrate_test_posts'][9902]->post_content );
	}
	public function test_selected_trees_share_budget_and_refuse_before_second_payload(): void {
		$db = new ContentSelectedBudgetConnection();
		$first = $db->encoded_bytes( 'a' ); $second = $db->encoded_bytes( 'b' );
		$limit = $db->root_bytes() + $db->key_bytes() + $first + $second - 1;
		$this->assertLessThan( $limit, $db->root_bytes() + $db->key_bytes() + $first );
		try { ( new LegacyTableRepository( $db, 1, 10000, $limit ) )->content_selected( array( 'a', 'b' ) );
			$this->fail( 'Cumulative encoded bytes must refuse before second payload.' ); }
		catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'budget', $error->getMessage() ); }
		$this->assertSame( 1, $db->payload_reads );
		$this->assertSame( 0, $db->writes );
		$db = new ContentSelectedBudgetConnection();
		$backend = new LegacyTableBackend( $db, 1, 10000, $limit );
		$engine = new ContentSelectedBudgetEngine( new ContentScanRepository( $backend, new MigratorSettings( $backend ) ) );
		$GLOBALS['foogallery_migrate_test_posts'][9901] = (object) array( 'ID' => 9901, 'post_content' => 'protected' );
		try { ( new ContentMigrator( $engine, 'block-shortcode' ) )->replace_content( array( 'a', 'b' ) );
			$this->fail( 'Selected action must refuse before scan or post mutation.' ); }
		catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'budget', $error->getMessage() ); }
		$this->assertSame( 1, $db->payload_reads );
		$this->assertSame( 0, $db->writes );
		$this->assertSame( 0, $engine->queue_writes );
		$this->assertSame( 'protected', $GLOBALS['foogallery_migrate_test_posts'][9901]->post_content );
	}
	public function test_selected_typed_keys_and_final_head_are_checked(): void {
		$db = new ContentSelectedBudgetConnection();
		$result = ( new LegacyTableRepository( $db, 1 ) )->content_selected( array( 'b', 'a' ) );
		$this->assertSame( array( 'b', 'a' ), array_keys( $result['items'] ) );
		$this->assertSame( 2, $result['total'] );
		$this->assertSame( 1, $result['revision'] );
		$this->assertSame( 2, $db->payload_reads );
		foreach ( array( array( 'a', 'a' ), array( '1' ), array( true ) ) as $keys ) {
			try { ( new LegacyTableRepository( new ContentSelectedBudgetConnection(), 1 ) )->content_selected( $keys );
				$this->fail( 'Invalid selected keys must refuse.' ); }
			catch ( \RuntimeException $error ) { $this->assertNotSame( '', $error->getMessage() ); }
		}
		$stale = new ContentSelectedBudgetConnection(); $stale->stale = true;
		try { ( new LegacyTableRepository( $stale, 1 ) )->content_selected( array( 'a' ) );
			$this->fail( 'Changed head must refuse.' ); }
		catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'changed', $error->getMessage() ); }
	}
}

class ContentSelectedBudgetEngine {
	public $queue_writes = 0;
	private $repository;
	public function __construct( $repository ) { $this->repository = $repository; }
	public function assert_storage_writer() {}
	public function content_scan_repository() { return $this->repository; }
	public function get_gallery_migrator() { ++$this->queue_writes; throw new \RuntimeException( 'Unexpected gallery migration.' ); }
}

class ContentSelectedBudgetConnection extends LegacyTransactionConnection {
	public $base_prefix = 'wp_';
	public $last_error = '';
	public $payload_reads = 0;
	public $writes = 0;
	public $stale = false;
	private $args = array();
	private $head_reads = 0;
	private $codec;
	private $root;
	private $rows = array();
	public function __construct( $count = 2 ) {
		$this->codec = new LegacyStateCodec();
		$items = array();
		if ( 2 === $count ) {
			$items = array( 'a' => array( 'plugin_name' => 'Example', 'pad' => str_repeat( 'a', 350 ) ),
				'b' => array( 'plugin_name' => 'Example', 'pad' => str_repeat( 'b', 350 ) ) );
		} else {
			for ( $i = 0; $i < $count; ++$i ) { $items['k' . $i] = array( 'plugin_name' => 'Example', 'pad' => str_repeat( 'x', 350 ) ); }
		}
		$root = ( new LegacyCollectionEnvelope( $this->codec ) )->split( ContentScanRepository::STATE_KEY, 'map',
			array( 'items' => $items, 'progress' => array() ) )['root'];
		$this->root = $this->codec->encode_collection_root( $root );
		$record_codec = new LegacyRecordCodec();
		foreach ( $items as $key => $value ) {
			$rows = array();
			$record_codec->flatten_tree( ContentScanRepository::STATE_KEY, $key, $value, count( $this->rows ), function ( $row ) use ( &$rows ) { $rows[] = $row; } );
			foreach ( $rows as $row ) {
				$row['id'] = 101 + count( $this->rows ); $row['blog_id'] = 1; $row['state_id'] = 1; $row['generation'] = 1;
				$row['encoded_bytes'] = strlen( $row['payload'] ) + strlen( $row['mutable_payload'] ) + strlen( $row['key_value'] ) +
					strlen( (string) $row['legacy_identifier_value'] ) + strlen( (string) $row['source_plugin_value'] ) + strlen( (string) $row['source_id_value'] );
				$this->rows[$key] = $row;
			}
		}
	}
	public function keys() { return array_keys( $this->rows ); }
	public function root_bytes() { return strlen( $this->root ); }
	public function key_bytes( $keys = null ) {
		if ( null === $keys ) { $keys = $this->keys(); }
		$total = 0; foreach ( $keys as $key ) { $total += strlen( $this->codec->key_value( $key ) ); } return $total;
	}
	public function encoded_bytes( $key ) { return $this->rows[$key]['encoded_bytes']; }
	public function prepare( $sql, $args ) { $this->args = $args; return $sql; }
	private function metadata( $row ) {
		$result = array();
		foreach ( array( 'id', 'blog_id', 'state_id', 'generation', 'item_order', 'node_hash', 'parent_node_hash', 'payload_bytes', 'encoded_bytes' ) as $field ) { $result[$field] = $row[$field]; }
		return $result;
	}
	public function get_results( $sql, $format ) {
		$this->last_error = '';
		if ( false !== strpos( $sql, 'OCTET_LENGTH(root_payload) AS root_payload_bytes' ) ) {
			++$this->head_reads;
			return array( array( 'id' => '1', 'blog_id' => '1', 'state_order' => '0', 'state_kind' => 'map',
				'active_generation' => '1', 'revision' => $this->stale && $this->head_reads > 1 ? '2' : '1',
				'codec_version' => '1', 'record_count' => (string) count( $this->rows ), 'payload_bytes' => (string) strlen( $this->root ),
				'root_payload_bytes' => (string) strlen( $this->root ), 'state_key_value' => $this->codec->key_value( ContentScanRepository::STATE_KEY ) ) );
		}
		if ( false !== strpos( $sql, 'SELECT root_payload FROM' ) ) { return array( array( 'root_payload' => $this->root ) ); }
		if ( false !== strpos( $sql, 'AND node_hash = UNHEX(%s)' ) && false !== strpos( $sql, 'AS encoded_bytes' ) ) {
			foreach ( $this->rows as $row ) { if ( $row['node_hash'] === $this->args[3] ) { return array( $this->metadata( $row ) ); } }
			return array();
		}
		if ( false !== strpos( $sql, 'parent_node_hash = UNHEX(%s)' ) && false !== strpos( $sql, 'item_order >' ) ) { return array(); }
		if ( false !== strpos( $sql, ' AND id = %d AND (' ) ) {
			++$this->payload_reads;
			foreach ( $this->rows as $row ) { if ( $row['id'] === $this->args[3] && $row['encoded_bytes'] === $this->args[4] ) { return array( $row ); } }
			return array();
		}
		throw new \RuntimeException( 'Unexpected selected-content SQL: ' . $sql );
	}
	public function query( $sql ) { ++$this->writes; throw new \RuntimeException( 'Unexpected selected-content write.' ); }
}
