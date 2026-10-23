<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\LegacyCollectionEnvelope;
use FooPlugins\FooGalleryMigrate\LegacyRecordCodec;
use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use FooPlugins\FooGalleryMigrate\LegacyTableReader;
use FooPlugins\FooGalleryMigrate\LegacyTableRepository;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use PHPUnit\Framework\TestCase;

/** Fixed projection and one affected tree, never a 121-root payload traversal. */
class CollectionMigrationSelectorTest extends TestCase {
	private function repository( $db, $bytes = 16777216 ) {
		$repo = new LegacyTableRepository( $db, 1, 10000, $bytes );
		$property = new \ReflectionProperty( $repo, 'reader' ); $property->setAccessible( true );
		$property->setValue( $repo, new TurnSelectorReader( $db ) );
		return $repo;
	}
	public function test_first_eligible_uses_persisted_order_across_queued_and_started(): void {
		$db = new TurnSelectorConnection( 'galleries', '09', 'queued', 18 );
		$context = $this->repository( $db )->migration_turn_context( 'galleries' );
		$this->assertSame( '09', $context['root']['key'] );
		$this->assertSame( 'queued', $context['root']['value']['migration_status'] );
		$this->assertSame( array( 'queued' => 3, 'completed' => 1, 'errors' => 1 ), $context['counts'] );
		$this->assertSame( 1, $db->payload_reads );
		$this->assertSame( 0, $db->writes );
		$this->assertStringContainsString( "migration_status IN ('queued','started')", $db->selector_sql );
		$this->assertStringContainsString( 'ORDER BY item_order ASC, id ASC LIMIT 1', $db->selector_sql );
	}
	public function test_album_started_and_no_eligible_cases_keep_exact_summary_without_root_payload_scan(): void {
		$db = new TurnSelectorConnection( 'albums', 7, 'started', 45 );
		$context = $this->repository( $db )->migration_turn_context( 'albums' );
		$this->assertSame( 7, $context['root']['key'] );
		$this->assertSame( 'started', $context['root']['value']['migration_status'] );
		$db = new TurnSelectorConnection( 'galleries', '09', 'queued', 18 ); $db->none = true;
		$context = $this->repository( $db )->migration_turn_context( 'galleries' );
		$this->assertNull( $context['root'] );
		$this->assertSame( 0, $db->payload_reads );
	}
	public function test_changed_head_or_corrupt_projection_refuses_without_writes(): void {
		foreach ( array( 'stale', 'projection' ) as $mode ) {
			$db = new TurnSelectorConnection( 'galleries', '09', 'queued', 18 ); $db->mode = $mode;
			try { $this->repository( $db )->migration_turn_context( 'galleries' ); $this->fail( 'Expected bounded refusal.' ); }
			catch ( \RuntimeException $error ) {
				$this->assertStringContainsString( 'stale' === $mode ? 'State changed' : 'projection changed', $error->getMessage() );
				$this->assertSame( 0, $db->writes );
			}
		}
	}
	public function test_checkpoint_reuses_retained_byte_budget_and_refuses_stale_head_before_write(): void {
		$db = new TurnSelectorConnection( 'galleries', '09', 'queued', 18 ); $db->none = true;
		$repo = $this->repository( $db ); $context = $repo->migration_turn_context( 'galleries' );
		$full = $context; $full['budget']['bytes'] = 16777216;
		try { $repo->checkpoint_migration_turn( 'galleries', $full, null,
			array( 'queued' => 3, 'completed' => 1, 'progress' => 100 ) ); $this->fail( 'Expected shared byte refusal.' ); }
		catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'budget exceeded', $error->getMessage() ); $this->assertSame( 0, $db->writes ); }
		$db->mode = 'stale'; $db->head_reads = 2;
		try { $repo->checkpoint_migration_turn( 'galleries', $context, null,
			array( 'queued' => 3, 'completed' => 1, 'progress' => 100 ) ); $this->fail( 'Expected stale head refusal.' ); }
		catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'State changed', $error->getMessage() ); $this->assertSame( 0, $db->writes ); }
	}
	public function test_nested_repository_read_cannot_reset_retained_affected_tree_budget(): void {
		$db = new TurnSelectorConnection( 'galleries', '09', 'queued', 18 );
		$repo = $this->repository( $db, 1900 ); $context = $repo->migration_turn_context( 'galleries' );
		$this->assertGreaterThan( 1000, $context['budget']['bytes'] );
		$this->assertSame( 1, $db->payload_reads );
		$this->assertTrue( $repo->collection_view_context( 'galleries' )['found'] );
		$used = new \ReflectionProperty( $repo, 'used_bytes' ); $used->setAccessible( true );
		$this->assertLessThan( $context['budget']['bytes'], $used->getValue( $repo ) );
		try { $repo->checkpoint_migration_turn( 'galleries', $context, $context['root']['value'],
			array( 'queued' => 3, 'completed' => 1, 'progress' => 100 ) ); $this->fail( 'Expected retained old/new budget refusal.' ); }
		catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( 'budget exceeded', $error->getMessage() );
			$this->assertSame( 1, $db->payload_reads );
			$this->assertSame( 0, $db->writes );
		}
	}
	public function test_existing_companion_change_and_absent_to_present_refuse_before_any_checkpoint_write(): void {
		foreach ( array( false, true ) as $existing ) {
			$db = new TurnSelectorConnection( 'galleries', '09', 'queued', 18 ); $db->none = true;
			if ( $existing ) { $db->state_head = array( 'id' => 6, 'revision' => 1, 'state_kind' => 'scalar' ); }
			$repo = $this->repository( $db ); $context = $repo->migration_turn_context( 'galleries' );
			$db->state_head = array( 'id' => 6, 'revision' => $existing ? 2 : 1, 'state_kind' => 'scalar' );
			try { $repo->checkpoint_migration_turn( 'galleries', $context, null,
				array( 'queued' => 3, 'completed' => 1, 'progress' => 100 ) ); $this->fail( 'Expected companion race refusal.' ); }
			catch ( \RuntimeException $error ) {
				$this->assertStringContainsString( 'companion changed', $error->getMessage() );
				$this->assertSame( 0, $db->writes );
			}
		}
	}
	public function test_companion_change_during_context_refuses_before_output(): void {
		$db = new TurnSelectorConnection( 'galleries', '09', 'queued', 18 ); $db->none = true; $db->mode = 'companion-during-read';
		try { $this->repository( $db )->migration_turn_context( 'galleries' ); $this->fail( 'Expected incoherent context.' ); }
		catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( 'companion changed', $error->getMessage() );
			$this->assertSame( 0, $db->writes );
		}
	}
}

class TurnSelectorReader extends LegacyTableReader {
	private $db;
	public function __construct( $db ) { $this->db = $db; }
	public function head( $key ) {
		if ( 'galleries-state' === $key || 'albums-state' === $key ) {
			++$this->db->state_reads;
			if ( 'companion-during-read' === $this->db->mode && $this->db->state_reads > 1 ) {
				return array( 'id' => 6, 'revision' => 1, 'state_kind' => 'scalar' );
			}
			return $this->db->state_head;
		}
		++$this->db->head_reads;
		if ( $this->db->mode === 'stale' && $this->db->head_reads > 2 ) { $head = $this->db->head; ++$head['revision']; return $head; }
		return $this->db->head;
	}
}
class TurnSelectorConnection extends LegacyTransactionConnection {
	public $base_prefix = 'wp_';
	public $last_error = '';
	public $head;
	public $head_reads = 0;
	public $state_reads = 0;
	public $state_head = false;
	public $payload_reads = 0;
	public $writes = 0;
	public $selector_sql = '';
	public $none = false;
	public $mode = '';
	private $root_payload;
	private $row;
	public function __construct( $bucket, $key, $status, $order ) {
		$codec = new LegacyStateCodec();
		$record = array( 'object_type' => 'albums' === $bucket ? 'album' : 'gallery', 'plugin_name' => 'Fixture', 'ID' => '7',
			'migration_status' => $status, 'part_of_migration' => true, 'migrated' => false, 'opaque' => 'unchanged' );
		$rows = array(); ( new LegacyRecordCodec( $codec ) )->flatten_tree( $bucket, $key, $record, $order,
			function ( $row ) use ( &$rows ) { $rows[] = $row; } );
		$this->row = $rows[0];
		$this->row['id'] = '12'; $this->row['blog_id'] = '1'; $this->row['state_id'] = '5'; $this->row['generation'] = '1';
		$this->row['encoded_bytes'] = (string) ( strlen( $this->row['payload'] ) + strlen( $this->row['mutable_payload'] ) + strlen( $this->row['key_value'] ) +
			strlen( $this->row['source_plugin_value'] ) + strlen( $this->row['source_id_value'] ) );
		$payload = array( MigratorSettings::COMPACT_MARKER => MigratorSettings::COMPACT_VERSION, 'type' => 'migratable', 'items' => array() );
		$root = ( new LegacyCollectionEnvelope( $codec ) )->split( $bucket, 'map', $payload )['root']; $root['root_count'] = 121;
		$this->root_payload = $codec->encode( $root );
		$this->head = array( 'id' => 5, 'blog_id' => 1, 'state_order' => 0, 'state_kind' => 'map', 'active_generation' => 1,
			'revision' => 1, 'codec_version' => 1, 'record_count' => 121, 'payload_bytes' => 142000,
			'root_payload_bytes' => strlen( $this->root_payload ), 'state_key_value' => $codec->key_value( $bucket ) );
	}
	public function prepare( $sql, $args ) { return $sql; }
	public function get_results( $sql, $format ) {
		if ( false !== strpos( $sql, 'SELECT root_payload FROM' ) ) { return array( array( 'root_payload' => $this->root_payload ) ); }
		if ( false !== strpos( $sql, 'SELECT COUNT(*) AS total, COALESCE(SUM(is_selected)' ) ) {
			return array( array( 'total' => '121', 'queued' => '3', 'completed' => '1', 'errors' => '1' ) );
		}
		if ( false !== strpos( $sql, 'SELECT COUNT(*) AS total FROM' ) ) { return array( array( 'total' => '121' ) ); }
		if ( false !== strpos( $sql, 'SELECT id, item_order, HEX(node_hash) AS node_hash, migration_status' ) ) {
			$this->selector_sql = $sql;
			if ( $this->none ) { return array(); }
			return array( array( 'id' => '12', 'item_order' => (string) $this->row['item_order'], 'node_hash' => $this->row['node_hash'],
				'migration_status' => 'projection' === $this->mode ? 'started' : $this->row['migration_status'],
				'is_selected' => '1', 'is_migrated' => '0' ) );
		}
		if ( false !== strpos( $sql, 'SELECT id, blog_id, state_id, generation, item_order' ) && false !== strpos( $sql, 'parent_node_hash IS NULL LIMIT 2' ) ) {
			return array( $this->metadata() );
		}
		if ( false !== strpos( $sql, 'SELECT id, blog_id, state_id, generation, item_order' ) && false !== strpos( $sql, 'parent_node_hash = UNHEX' ) ) { return array(); }
		if ( false !== strpos( $sql, 'AS encoded_bytes FROM' ) && false !== strpos( $sql, 'AND id = %d' ) ) {
			++$this->payload_reads; return array( $this->full_row() );
		}
		throw new \RuntimeException( 'Unexpected turn selector SQL.' );
	}
	private function metadata() {
		$meta = array();
		foreach ( array( 'id', 'blog_id', 'state_id', 'generation', 'item_order', 'node_hash', 'parent_node_hash', 'payload_bytes', 'encoded_bytes' ) as $field ) {
			$meta[$field] = $this->row[$field];
		}
		return $meta;
	}
	private function full_row() {
		$row = $this->row;
		$definitions = ( new LegacyStorageSchema( $this ) )->definitions(); $tables = array_keys( $definitions );
		foreach ( $definitions[$tables[1]]['columns'] as $name => $spec ) {
			if ( ! array_key_exists( $name, $row ) ) { $row[$name] = $spec[1] ? null : ( false !== strpos( $spec[0], 'int' ) ? '0' : '' ); }
		}
		return $row;
	}
	public function query( $sql ) { ++$this->writes; throw new \RuntimeException( 'Selector attempted a write.' ); }
}
