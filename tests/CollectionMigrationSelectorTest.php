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
	private function repository( $db, $bytes = 16777216, $rows = 10000 ) {
		$repo = new LegacyTableRepository( $db, 1, $rows, $bytes );
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
	public function test_exact_uid_selects_only_one_typed_root_without_unrelated_payload(): void {
		$db = new TurnSelectorConnection( 'galleries', '09', 'queued', 18, 1 );
		$context = $this->repository( $db )->targeted_collection_context( 'galleries', 'gallery_Fixture_7' );
		$this->assertTrue( $context['found'] );
		$this->assertSame( '09', $context['root']['key'] );
		$this->assertSame( 1, $db->payload_reads );
		$this->assertSame( 0, $db->writes );
		$db = new TurnSelectorConnection( 'galleries', 7, 'queued', 18, 1 );
		$missing = $this->repository( $db )->targeted_collection_context( 'galleries', 'gallery_Fixture_8' );
		$this->assertFalse( $missing['found'] );
		$this->assertSame( 0, $db->payload_reads );
		$db = new TurnSelectorConnection( 'galleries', 7, 'queued', 18, 1 ); $db->mode = 'absent';
		$this->assertSame( array( 'found' => false, 'absent' => true ),
			$this->repository( $db )->targeted_collection_context( 'galleries', 'gallery_Fixture_7' ) );
	}
	public function test_targeted_checkpoint_keeps_original_budget_and_head_before_write(): void {
		$db = new TurnSelectorConnection( 'galleries', '09', 'queued', 18, 1 );
		$repo = $this->repository( $db ); $context = $repo->targeted_collection_context( 'galleries', 'gallery_Fixture_7' );
		$db->mode = 'stale'; $db->head_reads = 2;
		try { $repo->checkpoint_targeted_collection( 'galleries', $context, $context['root']['value'] ); $this->fail( 'Expected stale refusal.' ); }
		catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'State changed', $error->getMessage() ); $this->assertSame( 0, $db->writes ); }
		$db = new TurnSelectorConnection( 'galleries', '09', 'queued', 18, 1 );
		$repo = $this->repository( $db ); $context = $repo->targeted_collection_context( 'galleries', 'gallery_Fixture_7' );
		$context['budget']['bytes'] = 16777216;
		try { $repo->checkpoint_targeted_collection( 'galleries', $context, $context['root']['value'] ); $this->fail( 'Expected aggregate refusal.' ); }
		catch ( \RuntimeException $error ) { $this->assertStringContainsString( 'budget exceeded', $error->getMessage() ); $this->assertSame( 0, $db->writes ); }
	}
	public function test_exact_uid_lookup_ignores_more_than_20k_unrelated_roots_and_missing_uid_reads_no_tree(): void {
		$db = new TurnSelectorConnection( 'galleries', '09', 'queued', 18000, 20208 );
		$context = $this->repository( $db )->targeted_collection_context( 'galleries', 'gallery_Fixture_7' );
		$this->assertTrue( $context['found'] ); $this->assertSame( '09', $context['root']['key'] );
		$this->assertSame( 1, $db->identity_rows ); $this->assertSame( 1, $db->identity_raw_reads );
		$this->assertSame( 1, $db->payload_reads ); $this->assertLessThan( 10, $context['budget']['rows'] );
		$this->assertStringContainsString( 'FORCE INDEX (`source_identity`)', $db->identity_sql[0] );
		$this->assertStringContainsString( 'AND is_migrated = %d', $db->identity_sql[1] );
		$this->assertStringContainsString( 'ORDER BY item_order ASC, id ASC LIMIT %d', $db->identity_sql[1] );
		$db = new TurnSelectorConnection( 'galleries', '09', 'queued', 18000, 20208 );
		$missing = $this->repository( $db )->targeted_collection_context( 'galleries', 'gallery_Fixture_8' );
		$this->assertFalse( $missing['found'] ); $this->assertSame( 0, $db->identity_rows + $db->identity_raw_reads + $db->payload_reads + $db->writes );
	}
	public function test_ambiguous_underscore_splits_choose_first_persisted_root_and_checked_returns_all(): void {
		$db = new TurnSelectorConnection( 'galleries', 'irrelevant', 'queued', 100, 20208 );
		$base = array( 'object_type' => 'gallery', 'migration_status' => 'queued', 'part_of_migration' => true, 'migrated' => false );
		$db->add_root( 'galleries', 'later', array_merge( $base, array( 'plugin_name' => 'P_A', 'ID' => '1_2' ) ), 18, 13 );
		$db->add_root( 'galleries', 'first', array_merge( $base, array( 'plugin_name' => 'P', 'ID' => 'A_1_2', 'migrated' => true ) ), 7, 14 );
		$repo = $this->repository( $db ); $uid = 'gallery_P_A_1_2';
		$this->assertSame( 'first', $repo->targeted_collection_context( 'galleries', $uid )['root']['key'] );
		$this->assertSame( 1, $db->payload_reads );
		$this->assertSame( array( 'first', 'later' ), array_column( $repo->checked_collection_context( 'galleries', $uid )['roots'], 'key' ) );
		$this->assertSame( 3, $db->payload_reads ); $this->assertSame( 0, $db->writes );
	}
	public function test_duplicate_exact_identity_and_typed_source_ids_keep_first_and_all_semantics(): void {
		$db = new TurnSelectorConnection( 'albums', 'irrelevant', 'queued', 100, 20208 );
		$base = array( 'object_type' => 'album', 'plugin_name' => 'P_Q', 'ID' => 7,
			'migration_status' => 'queued', 'part_of_migration' => true, 'migrated' => false );
		$db->add_root( 'albums', 21, $base, 40, 13 );
		$db->add_root( 'albums', '09', array_merge( $base, array( 'ID' => '7', 'migrated' => true ) ), 3, 14 );
		$repo = $this->repository( $db ); $uid = 'album_P_Q_7';
		$this->assertSame( '09', $repo->targeted_collection_context( 'albums', $uid )['root']['key'] );
		$this->assertSame( 1, $db->payload_reads );
		$this->assertFalse( $repo->targeted_collection_context( 'albums', 'album_P_Q_07' )['found'] );
		$this->assertSame( 1, $db->payload_reads );
		$db = new TurnSelectorConnection( 'galleries', 'irrelevant', 'queued', 100, 20208 );
		$base['object_type'] = 'gallery'; $base['ID'] = null;
		$db->add_root( 'galleries', 'null-id', $base, 2, 13 );
		$db->add_root( 'galleries', 'false-id', array_merge( $base, array( 'ID' => false ) ), 8, 14 );
		$repo = $this->repository( $db );
		$this->assertSame( 'null-id', $repo->targeted_collection_context( 'galleries', 'gallery_P_Q_' )['root']['key'] );
		$this->assertSame( array( 'null-id', 'false-id' ), array_column( $repo->checked_collection_context( 'galleries', 'gallery_P_Q_' )['roots'], 'key' ) );
	}
	public function test_indexed_identity_projection_and_stale_head_refuse_before_mutation(): void {
		foreach ( array( 'bad-plugin-hash', 'bad-migrated-mutable', 'stale' ) as $mode ) {
			$db = new TurnSelectorConnection( 'galleries', '09', 'queued', 18, 20208 ); $db->mode = $mode;
			try { $this->repository( $db )->targeted_collection_context( 'galleries', 'gallery_Fixture_7' ); $this->fail( 'Expected indexed refusal.' ); }
			catch ( \RuntimeException $error ) {
				$this->assertStringContainsString( 'stale' === $mode ? 'State changed' : 'projection mismatch', $error->getMessage() );
				$this->assertSame( 0, $db->writes );
			}
		}
	}
	public function test_checked_duplicate_candidates_obey_row_budget_before_tree_payload(): void {
		$db = new TurnSelectorConnection( 'galleries', 'first', 'queued', 1, 20208 );
		$record = array( 'object_type' => 'gallery', 'plugin_name' => 'Fixture', 'ID' => '7',
			'migration_status' => 'queued', 'part_of_migration' => true, 'migrated' => false );
		$db->add_root( 'galleries', 'second', $record, 2, 13 );
		$db->add_root( 'galleries', 'third', $record, 3, 14 );
		try { $this->repository( $db, 16777216, 2 )->checked_collection_context( 'galleries', 'gallery_Fixture_7' ); $this->fail( 'Expected duplicate row budget refusal.' ); }
		catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( 'row/encoded byte budget exceeded', $error->getMessage() );
			$this->assertSame( 0, $db->identity_raw_reads + $db->payload_reads + $db->writes );
		}
	}
	public function test_checked_exact_uid_pages_121_duplicates_in_persisted_order_while_targeted_reads_one_tree(): void {
		$db = new TurnSelectorConnection( 'galleries', 'unrelated', 'queued', 19000, 20208 );
		$record = array( 'object_type' => 'gallery', 'plugin_name' => 'Bulk', 'ID' => 7,
			'migration_status' => 'queued', 'part_of_migration' => true, 'migrated' => false );
		$keys = array();
		for ( $i = 0; $i < 121; ++$i ) {
			$key = 0 === $i % 2 ? 1000 + $i : 'key-' . $i;
			$keys[] = $key; $db->add_root( 'galleries', $key, $record, $i + 1, $i + 100 );
		}
		$repo = $this->repository( $db ); $uid = 'gallery_Bulk_7';
		$this->assertSame( $keys[0], $repo->targeted_collection_context( 'galleries', $uid )['root']['key'] );
		$this->assertSame( 1, $db->payload_reads ); $this->assertSame( 1, $db->identity_rows );
		$context = $repo->checked_collection_context( 'galleries', $uid );
		$this->assertSame( $keys, array_column( $context['roots'], 'key' ) );
		$this->assertSame( 122, $db->payload_reads );
		$this->assertSame( 122, $db->identity_rows );
		$this->assertSame( array( 1, 0, 100, 21, 0 ), $db->identity_pages );
		$this->assertSame( 0, $db->writes );
		$this->assertGreaterThan( 0, count( array_filter( $db->identity_sql, function ( $sql ) {
			return false !== strpos( $sql, 'item_order > %d OR' );
		} ) ) );
	}
	public function test_many_uid_splits_charge_input_work_before_repeated_hashing(): void {
		$db = new TurnSelectorConnection( 'galleries', '09', 'queued', 18, 20208 );
		$uid = 'gallery_' . str_repeat( 'a_', 256 ) . 'tail';
		try { $this->repository( $db, 4096 )->targeted_collection_context( 'galleries', $uid ); $this->fail( 'Expected candidate work refusal.' ); }
		catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( 'budget exceeded', $error->getMessage() );
			$this->assertLessThanOrEqual( 15, count( $db->identity_sql ) );
			$this->assertSame( 0, $db->identity_raw_reads + $db->payload_reads + $db->writes );
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
		if ( 'absent' === $this->db->mode ) { return false; }
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
	public $identity_sql = array();
	public $identity_rows = 0;
	public $identity_pages = array();
	public $identity_raw_reads = 0;
	public $none = false;
	public $mode = '';
	private $root_payload;
	private $row;
	private $roots = array();
	private $args;
	public function __construct( $bucket, $key, $status, $order, $root_count = 121 ) {
		$codec = new LegacyStateCodec();
		$record = array( 'object_type' => 'albums' === $bucket ? 'album' : 'gallery', 'plugin_name' => 'Fixture', 'ID' => '7',
			'migration_status' => $status, 'part_of_migration' => true, 'migrated' => false, 'opaque' => 'unchanged' );
		$this->add_root( $bucket, $key, $record, $order, 12 );
		$payload = array( MigratorSettings::COMPACT_MARKER => MigratorSettings::COMPACT_VERSION, 'type' => 'migratable', 'items' => array() );
		$root = ( new LegacyCollectionEnvelope( $codec ) )->split( $bucket, 'map', $payload )['root']; $root['root_count'] = $root_count;
		$this->root_payload = $codec->encode( $root );
		$this->head = array( 'id' => 5, 'blog_id' => 1, 'state_order' => 0, 'state_kind' => 'map', 'active_generation' => 1,
			'revision' => 1, 'codec_version' => 1, 'record_count' => $root_count, 'payload_bytes' => 142000,
			'root_payload_bytes' => strlen( $this->root_payload ), 'state_key_value' => $codec->key_value( $bucket ) );
	}
	public function add_root( $bucket, $key, $record, $order, $id ) {
		$codec = new LegacyStateCodec();
		$rows = array(); ( new LegacyRecordCodec( $codec ) )->flatten_tree( $bucket, $key, $record, $order,
			function ( $row ) use ( &$rows ) { $rows[] = $row; } );
		$row = $rows[0]; $row['id'] = (string) $id; $row['blog_id'] = '1'; $row['state_id'] = '5'; $row['generation'] = '1';
		$row['encoded_bytes'] = (string) ( strlen( $row['payload'] ) + strlen( $row['mutable_payload'] ) + strlen( $row['key_value'] ) +
			strlen( $row['source_plugin_value'] ) + strlen( $row['source_id_value'] ) );
		$this->roots[$id] = $row;
		if ( 12 === $id ) { $this->row = $row; }
	}
	public function prepare( $sql, $args ) { $this->args = $args; return $sql; }
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
		if ( false !== strpos( $sql, 'FORCE INDEX (`source_identity`)' ) ) {
			$this->identity_sql[] = $sql;
			if ( false !== strpos( $sql, 'is_migrated NOT IN (0,1)' ) ) { return array(); }
			$selected = array();
			foreach ( $this->roots as $row ) {
				if ( $this->args[3] !== $row['source_identity_hash'] || (int) $this->args[4] !== (int) $row['is_migrated'] ) { continue; }
				if ( false !== strpos( $sql, 'item_order > %d OR' ) && ( (int) $row['item_order'] < $this->args[5] ||
					( (int) $row['item_order'] === $this->args[5] && (int) $row['id'] <= $this->args[7] ) ) ) { continue; }
				$selected[] = $row;
			}
			usort( $selected, function ( $a, $b ) { return (int) $a['item_order'] === (int) $b['item_order']
				? (int) $a['id'] - (int) $b['id'] : (int) $a['item_order'] - (int) $b['item_order']; } );
			$selected = array_slice( $selected, 0, $this->args[count( $this->args ) - 1] ); $this->identity_rows += count( $selected );
			$this->identity_pages[] = count( $selected );
			$result = array(); foreach ( $selected as $row ) {
				$result[] = array( 'id' => $row['id'], 'item_order' => (string) $row['item_order'], 'node_hash' => $row['node_hash'],
					'object_type' => $row['object_type'], 'migration_status' => $row['migration_status'],
					'is_selected' => $row['is_selected'], 'is_migrated' => $row['is_migrated'],
					'plugin_bytes' => (string) strlen( $row['source_plugin_value'] ),
					'id_bytes' => (string) strlen( $row['source_id_value'] ), 'mutable_bytes' => (string) strlen( $row['mutable_payload'] ),
					'plugin_hash' => 'bad-plugin-hash' === $this->mode ? str_repeat( '0', 64 ) : $row['source_plugin_hash'],
					'identity_hash' => $row['source_identity_hash'] );
			} return $result;
		}
		if ( false !== strpos( $sql, 'SELECT id, item_order, HEX(node_hash) AS node_hash, object_type' ) ) {
			return array( array( 'id' => '12', 'item_order' => (string) $this->row['item_order'], 'node_hash' => $this->row['node_hash'],
				'object_type' => $this->row['object_type'], 'migration_status' => $this->row['migration_status'],
				'is_selected' => $this->row['is_selected'], 'plugin_bytes' => (string) strlen( $this->row['source_plugin_value'] ),
				'id_bytes' => (string) strlen( $this->row['source_id_value'] ), 'mutable_bytes' => (string) strlen( $this->row['mutable_payload'] ),
				'plugin_hash' => $this->row['source_plugin_hash'], 'identity_hash' => $this->row['source_identity_hash'] ) );
		}
		if ( false !== strpos( $sql, 'SELECT id, source_plugin_value, source_id_value, mutable_payload FROM' ) ) {
			++$this->identity_raw_reads;
			$result = array(); foreach ( $this->roots as $row ) {
				for ( $i = 3; $i < count( $this->args ); $i += 4 ) {
					if ( (int) $row['id'] !== (int) $this->args[$i] ) { continue; }
					$mutable = $row['mutable_payload'];
					if ( 'bad-migrated-mutable' === $this->mode ) {
						$value = ( new LegacyStateCodec() )->decode( $mutable ); $value['migrated'] = true;
						$mutable = ( new LegacyStateCodec() )->encode( $value );
					}
					$result[] = array( 'id' => $row['id'], 'source_plugin_value' => $row['source_plugin_value'],
						'source_id_value' => $row['source_id_value'], 'mutable_payload' => $mutable );
				}
			} return $result;
		}
		if ( false !== strpos( $sql, 'SELECT id, blog_id, state_id, generation, item_order' ) && false !== strpos( $sql, 'parent_node_hash IS NULL LIMIT 2' ) ) {
			$result = array(); foreach ( $this->roots as $row ) { if ( $row['node_hash'] === $this->args[3] ) { $result[] = $this->metadata( $row ); } }
			return $result;
		}
		if ( false !== strpos( $sql, 'SELECT id, blog_id, state_id, generation, item_order' ) && false !== strpos( $sql, 'parent_node_hash = UNHEX' ) ) { return array(); }
		if ( false !== strpos( $sql, 'AS encoded_bytes FROM' ) && false !== strpos( $sql, 'AND id = %d' ) ) {
			++$this->payload_reads; foreach ( $this->roots as $row ) { if ( (int) $row['id'] === (int) $this->args[3] ) { return array( $this->full_row( $row ) ); } }
			return array();
		}
		throw new \RuntimeException( 'Unexpected turn selector SQL.' );
	}
	private function metadata( $row ) {
		$meta = array();
		foreach ( array( 'id', 'blog_id', 'state_id', 'generation', 'item_order', 'node_hash', 'parent_node_hash', 'payload_bytes', 'encoded_bytes' ) as $field ) {
			$meta[$field] = $row[$field];
		}
		return $meta;
	}
	private function full_row( $row ) {
		$definitions = ( new LegacyStorageSchema( $this ) )->definitions(); $tables = array_keys( $definitions );
		foreach ( $definitions[$tables[1]]['columns'] as $name => $spec ) {
			if ( ! array_key_exists( $name, $row ) ) { $row[$name] = $spec[1] ? null : ( false !== strpos( $spec[0], 'int' ) ? '0' : '' ); }
		}
		return $row;
	}
	public function query( $sql ) { ++$this->writes; throw new \RuntimeException( 'Selector attempted a write.' ); }
}
