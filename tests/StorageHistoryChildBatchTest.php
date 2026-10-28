<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\LegacyCollectionEnvelope;
use FooPlugins\FooGalleryMigrate\LegacyRecordCodec;
use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use FooPlugins\FooGalleryMigrate\LegacyTableBackend;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use PHPUnit\Framework\TestCase;

/** Fixed-page child selectors on a coherent, populated pair of history heads. */
class StorageHistoryChildBatchTest extends TestCase {
	private function read( $db, $keys, $bytes = 16777216, $rows = 10000 ) {
		return ( new LegacyTableBackend( $db, 1, $rows, $bytes ) )->history_read( 'children',
			array( 'parent' => 'Gallery', 'keys' => $keys ) );
	}
	private function refuses( $db, $keys, $bytes = 16777216, $rows = 10000 ) {
		try { $this->read( $db, $keys, $bytes, $rows ); $this->fail( 'Expected bounded child read to refuse.' ); }
		catch ( \RuntimeException $error ) { $this->assertNotSame( '', $error->getMessage() ); }
		$this->assertSame( 0, $db->writes );
	}
	public function test_missing_keys_use_two_fixed_queries_per_hundred_not_per_key(): void {
		$db = new ChildBatchConnection(); $keys = array();
		for ( $i = 0; $i < 250; ++$i ) { $keys[] = 'image-' . $i; }
		$this->assertSame( array(), $this->read( $db, $keys )['result'] );
		$this->assertSame( array( 100, 100, 50 ), $db->selector_sizes );
		$this->assertSame( array( 100, 100, 50 ), $db->missing_sizes );
		$this->assertFalse( $db->selector_payload_fetched );
		$this->assertSame( 0, $db->tree_reads );
		$this->assertSame( 0, $db->writes );
	}
	public function test_exact_typed_missing_keys_and_invalid_or_duplicate_keys(): void {
		$db = new ChildBatchConnection();
		$this->assertSame( array(), $this->read( $db, array( '09', 9, 'missing' ) )['result'] );
		$this->assertSame( array( 3 ), $db->selector_sizes );
		$this->refuses( new ChildBatchConnection(), array( 'same', 'same' ) );
		$this->refuses( new ChildBatchConnection(), array( '9' ) );
		$this->refuses( new ChildBatchConnection(), array( true ) );
	}
	public function test_oversized_input_and_selector_overflow_refuse_without_tree_hydration(): void {
		$this->refuses( new ChildBatchConnection(), array( str_repeat( 'x', 2000 ) ), 512 );
		$this->refuses( new ChildBatchConnection(), array_fill( 0, 101, 'duplicate' ), 16777216, 100 );
		$db = new ChildBatchConnection(); $db->mode = 'overflow'; $this->refuses( $db, array( 'one' ) );
		$this->assertSame( 0, $db->tree_reads );
	}
	public function test_missing_projection_collision_stale_head_and_sql_failure_refuse(): void {
		foreach ( array( 'unprojected', 'collision', 'stale', 'sql_failure' ) as $mode ) {
			$db = new ChildBatchConnection(); $db->mode = $mode;
			$this->refuses( $db, array( 'one', 'two' ) );
			$this->assertSame( 0, $db->tree_reads );
		}
	}
	public function test_hundred_flat_matches_use_one_sized_payload_query_and_keep_caller_order(): void {
		$db = new ChildBatchConnection(); $keys = array();
		for ( $i = 0; $i < 100; ++$i ) {
			$key = 'image-' . $i; $keys[] = $key;
			$db->seed( $key, array( 'object_type' => 'image', 'migration_parent_key' => 'Gallery', 'ordinal' => $i ), $i );
		}
		$requested = array_reverse( $keys ); $result = $this->read( $db, $requested )['result'];
		$this->assertSame( $requested, array_column( $result, 'key' ) );
		$this->assertSame( array_fill( 0, 100, true ), array_column( $result, 'found' ) );
		$this->assertSame( array( 100 ), $db->selector_sizes );
		$this->assertSame( 1, $db->root_meta_reads );
		$this->assertSame( 1, $db->parent_probes );
		$this->assertSame( 1, $db->payload_reads );
		$this->assertSame( 0, $db->tree_reads );
	}
	public function test_mixed_flat_nested_and_wrong_parent_rebuild_exact_selected_trees(): void {
		$db = new ChildBatchConnection();
		$db->seed( 'flat', array( 'object_type' => 'image', 'migration_parent_key' => 'Gallery', 'opaque' => "\xff\0" ), 0 );
		$db->seed( 'other', array( 'object_type' => 'image', 'migration_parent_key' => 'Elsewhere' ), 1 );
		$db->seed( 'nested', array( 'object_type' => 'image', 'migration_parent_key' => 'Gallery',
			'children' => array( '09' => array( 'bytes' => "\0\xff" ), 9 => false ) ), 2 );
		$result = $this->read( $db, array( 'nested', 'other', 'flat', 'missing' ) )['result'];
		$this->assertSame( array( 'nested', 'flat' ), array_column( $result, 'key' ) );
		$this->assertSame( array( '09', 9 ), array_keys( $result[0]['value']['children'] ) );
		$this->assertSame( "\0\xff", $result[0]['value']['children']['09']['bytes'] );
		$this->assertSame( "\xff\0", $result[1]['value']['opaque'] );
		$this->assertSame( 1, $db->payload_reads );
		$this->assertGreaterThan( 0, $db->tree_reads );
	}
	public function test_flat_batch_faults_refuse_before_or_during_sized_payload_read(): void {
		foreach ( array( 'meta_missing', 'meta_duplicate', 'meta_oversize', 'meta_sum', 'unexpected_child', 'payload_missing', 'payload_changed',
			'projection_corrupt', 'key_corrupt' ) as $mode ) {
			$db = new ChildBatchConnection();
			$db->seed( 'one', array( 'migration_parent_key' => 'Gallery' ), 0 );
			$db->seed( 'two', array( 'migration_parent_key' => 'Gallery' ), 1 );
			$db->mode = $mode; $this->refuses( $db, array( 'one', 'two' ), 'meta_sum' === $mode ? 1048576 : 16777216 );
			if ( in_array( $mode, array( 'meta_missing', 'meta_duplicate', 'meta_oversize', 'meta_sum', 'unexpected_child' ), true ) ) {
				$this->assertSame( 0, $db->payload_reads, $mode . ' fetched payload before admission.' );
			}
		}
	}
}

class ChildBatchConnection extends LegacyTransactionConnection {
	public $base_prefix = 'wp_';
	public $last_error = '';
	public $mode = 'missing';
	public $selector_sizes = array();
	public $missing_sizes = array();
	public $tree_reads = 0;
	public $root_meta_reads = 0;
	public $parent_probes = 0;
	public $payload_reads = 0;
	public $writes = 0;
	public $selector_payload_fetched = false;
	private $args = array();
	private $selected = false;
	private $codec;
	private $roots;
	private $records = array();
	public function __construct() {
		$this->codec = new LegacyStateCodec();
		$root = ( new LegacyCollectionEnvelope( $this->codec ) )->split( 'migrated', 'map',
			array( MigratorSettings::COMPACT_MARKER => 1, 'type' => 'migratable', 'items' => array() ) )['root'];
		$this->roots = array( 1 => $this->codec->encode_collection_root( $root ),
			2 => $this->codec->encode( array( 'version' => 1, 'kind' => 'scalar', 'value' => 0 ) ) );
	}
	public function seed( $key, $value, $order ) {
		$codec = new LegacyRecordCodec(); $rows = array();
		$codec->flatten_tree( 'migrated', $key, $value, $order, function ( $row ) use ( &$rows ) { $rows[] = $row; } );
		foreach ( $rows as $row ) {
			$row['id'] = 100 + count( $this->records ); $row['blog_id'] = 1; $row['state_id'] = 1; $row['generation'] = 1;
			$row['legacy_identifier_hash'] = null === $row['parent_node_hash'] ? $this->codec->key_hash( $key ) : null;
			$row['legacy_identifier_value'] = null === $row['parent_node_hash'] ? $this->codec->key_value( $key ) : null;
			$row['encoded_bytes'] = strlen( $row['payload'] ) + strlen( $row['mutable_payload'] ) + strlen( $row['key_value'] ) +
				strlen( (string) $row['legacy_identifier_value'] ) + strlen( (string) $row['source_plugin_value'] ) + strlen( (string) $row['source_id_value'] );
			$this->records[] = $row;
		}
	}
	private function roots_for_hashes( $hashes ) {
		$result = array(); foreach ( $this->records as $row ) {
			if ( null === $row['parent_node_hash'] && in_array( $row['node_hash'], $hashes, true ) ) { $result[] = $row; }
		} return $result;
	}
	private function metadata( $row ) {
		$result = array(); foreach ( array( 'id', 'blog_id', 'state_id', 'generation', 'item_order', 'node_hash',
			'parent_node_hash', 'payload_bytes', 'encoded_bytes' ) as $field ) { $result[$field] = $row[$field]; }
		return $result;
	}
	public function prepare( $sql, $args ) { $this->args = $args; return $sql; }
	public function get_results( $sql, $format ) {
		$this->last_error = '';
		if ( false !== strpos( $sql, 'OCTET_LENGTH(root_payload) AS root_payload_bytes' ) ) {
			$key = $this->args[2] === $this->codec->key_hash( 'migrated' ) ? 'migrated' : 'migrated-revision';
			$id = 'migrated' === $key ? 1 : 2; $revision = $this->selected && 'stale' === $this->mode && 1 === $id ? 2 : 1;
			$count = 1 === $id ? count( $this->records ) : 0;
			return array( array( 'id' => (string) $id, 'blog_id' => '1', 'state_order' => (string) ( $id - 1 ),
				'state_kind' => 1 === $id ? 'map' : 'scalar', 'active_generation' => '1', 'revision' => (string) $revision,
				'codec_version' => '1', 'record_count' => (string) $count, 'payload_bytes' => (string) strlen( $this->roots[$id] ),
				'root_payload_bytes' => (string) strlen( $this->roots[$id] ), 'state_key_value' => $this->codec->key_value( $key ) ) );
		}
		if ( false !== strpos( $sql, 'SELECT root_payload FROM' ) ) { return array( array( 'root_payload' => $this->roots[$this->args[1]] ) ); }
		if ( false !== strpos( $sql, 'HEX(snapshot_digest)' ) ) { return array( array( 'digest' => $this->codec->snapshot_digest( 0 ), 'digest_revision' => '1', 'digest_status' => 'current' ) ); }
		if ( false !== strpos( $sql, 'legacy_identifier_hash IN (' ) ) {
			$this->selected = true; $count = count( $this->args ) - 3; $this->selector_sizes[] = $count;
			$this->selector_payload_fetched = false !== strpos( $sql, 'legacy_identifier_value' ) || false !== strpos( $sql, 'key_value' );
			if ( 'sql_failure' === $this->mode ) { $this->last_error = 'Injected SQL failure'; return false; }
			if ( 'overflow' === $this->mode ) { return array_fill( 0, 101, array() ); }
			if ( 'collision' === $this->mode ) { return array( array( 'identifier_hash' => $this->args[3], 'node_hash' => str_repeat( '0', 64 ) ) ); }
			$result = array();
			foreach ( $this->records as $row ) {
				if ( null === $row['parent_node_hash'] && in_array( $row['legacy_identifier_hash'], array_slice( $this->args, 3 ), true ) ) {
					$result[] = array( 'identifier_hash' => $row['legacy_identifier_hash'], 'node_hash' => $row['node_hash'] );
				}
			}
			return $result;
		}
		if ( false !== strpos( $sql, 'SELECT id FROM' ) && false !== strpos( $sql, 'node_hash IN (' ) ) {
			$this->missing_sizes[] = count( $this->args ) - 3;
			return 'unprojected' === $this->mode ? array( array( 'id' => '7' ) ) : array();
		}
		if ( false !== strpos( $sql, 'node_hash IN (' ) && false !== strpos( $sql, 'AS encoded_bytes' ) ) {
			++$this->root_meta_reads; $rows = array_map( array( $this, 'metadata' ), $this->roots_for_hashes( array_slice( $this->args, 3 ) ) );
			if ( 'meta_missing' === $this->mode ) { return array(); }
			if ( 'meta_duplicate' === $this->mode ) { return array( $rows[0], $rows[0] ); }
			if ( 'meta_oversize' === $this->mode ) { $rows[0]['encoded_bytes'] = 1048577; }
			if ( 'meta_sum' === $this->mode ) { $rows[0]['encoded_bytes'] = 600000; $rows[1]['encoded_bytes'] = 600000; }
			return $rows;
		}
		if ( false !== strpos( $sql, 'GROUP BY parent_node_hash' ) ) {
			++$this->parent_probes;
			if ( 'unexpected_child' === $this->mode ) { return array( array( 'parent_hash' => str_repeat( '0', 64 ) ) ); }
			$result = array(); $seen = array();
			foreach ( $this->records as $row ) { if ( null !== $row['parent_node_hash'] &&
				in_array( $row['parent_node_hash'], array_slice( $this->args, 3 ), true ) && ! isset( $seen[$row['parent_node_hash']] ) ) {
				$seen[$row['parent_node_hash']] = true; $result[] = array( 'parent_hash' => $row['parent_node_hash'] );
			} } return $result;
		}
		if ( false !== strpos( $sql, 'CASE id' ) ) {
			++$this->payload_reads; $count = ( count( $this->args ) - 3 ) / 3; $ids = array_slice( $this->args, 3, $count ); $rows = array();
			foreach ( $this->records as $row ) { if ( in_array( $row['id'], $ids, true ) ) { $rows[] = $row; } }
			if ( 'payload_missing' === $this->mode ) { array_pop( $rows ); }
			if ( 'payload_changed' === $this->mode ) { $rows[0]['payload_bytes']++; }
			if ( 'projection_corrupt' === $this->mode ) { $rows[0]['legacy_identifier_value'] = 'corrupt'; }
			if ( 'key_corrupt' === $this->mode ) { $rows[0]['key_type'] = 'integer'; }
			return $rows;
		}
		if ( false !== strpos( $sql, 'AND node_hash = UNHEX(%s)' ) && false !== strpos( $sql, 'AS encoded_bytes' ) ) {
			++$this->tree_reads; return array_map( array( $this, 'metadata' ), $this->roots_for_hashes( array( $this->args[3] ) ) );
		}
		if ( false !== strpos( $sql, 'parent_node_hash = UNHEX(%s)' ) && false !== strpos( $sql, 'item_order >' ) ) {
			++$this->tree_reads; $result = array();
			foreach ( $this->records as $row ) { if ( $row['parent_node_hash'] === $this->args[3] && $row['item_order'] > $this->args[4] ) { $result[] = $this->metadata( $row ); } }
			return $result;
		}
		if ( false !== strpos( $sql, ' AND id = %d AND (' ) ) {
			++$this->tree_reads; foreach ( $this->records as $row ) { if ( $row['id'] === $this->args[3] && $row['encoded_bytes'] === $this->args[4] ) { return array( $row ); } }
			return array();
		}
		++$this->tree_reads; throw new \RuntimeException( 'Unexpected child SQL.' );
	}
	public function query( $sql ) { ++$this->writes; throw new \RuntimeException( 'Read attempted write.' ); }
}
