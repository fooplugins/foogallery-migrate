<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/SnapshotScaleConnection.php';

/** Same observable contracts run against the original and compact host backing. Not SQL coverage. */
class SnapshotScaleConnectionContractTest extends TestCase {
	private function values() {
		return array( 'blog_id' => 7, 'state_id' => 12, 'generation' => 1,
			'node_hash' => str_repeat( 'a', 64 ), 'parent_node_hash' => null,
			'payload' => "\0\xffp", 'mutable_payload' => '', 'key_value' => "\x80k",
			'legacy_identifier_value' => null, 'source_plugin_value' => '', 'source_id_value' => "\0id",
			'payload_bytes' => 3, 'item_order' => 0, 'record_kind' => 'image', 'key_type' => 'string',
			'legacy_identifier_hash' => null, 'source_plugin_hash' => '', 'source_id_hash' => str_repeat( 'b', 64 ),
			'created_gmt' => '2026-10-19 00:00:00', 'updated_gmt' => '' );
	}
	private function insert( $db, $values, $table = 'records' ) {
		$columns = array(); $expressions = array(); $args = array();
		foreach ( $values as $key => $value ) {
			$columns[] = '`' . $key . '`'; $expressions[] = null === $value ? 'NULL' : ( is_int( $value ) ? '%d' : '%s' );
			if ( null !== $value ) { $args[] = $value; }
		}
		$sql = 'INSERT INTO `wp_foogallery_migrate_' . $table . '` (' . implode( ',', $columns ) . ') VALUES (' . implode( ',', $expressions ) . ')';
		$this->assertSame( 1, $db->query( $db->prepare( $sql, $args ) ) );
		$values['id'] = $db->insert_id; return $values;
	}
	private function named( $row ) { $out = array(); foreach ( $row as $k => $v ) { $out[$k] = $v; } return $out; }
	private function driver( $row ) { foreach ( $row as $k => $v ) { if ( is_int( $v ) ) { $row[$k] = (string) $v; } } return $row; }
	private function read( $db, $args ) {
		return $db->get_results( $db->prepare( 'SELECT * FROM `wp_foogallery_migrate_records` WHERE blog_id = %d AND state_id = %d AND generation = %d AND bytes <= %d ORDER BY id ASC LIMIT %d', $args ), ARRAY_A );
	}
	private function rejected( $call, $message ) {
		try { $call(); } catch ( \LogicException $error ) { $this->assertStringContainsString( $message, $error->getMessage() ); return; }
		$this->fail( 'Expected fail-closed host contract.' );
	}
	public function test_lossless_named_values_order_types_and_independent_driver_rows(): void {
		$db = new SnapshotScaleConnection(); $this->assertSame( array(), $db->records );
		$values = $this->values(); $expected = $this->insert( $db, $values );
		$values['payload'] = 'caller changed';
		$this->assertIsArray( $db->records ); $this->assertCount( 1, $db->records );
		$this->assertSame( $expected, $this->named( $db->records[0] ) );
		foreach ( $expected as $key => $value ) { $this->assertSame( $value, $db->records[0][$key], $key ); }
		$this->assertSame( isset( $expected['parent_node_hash'] ), isset( $db->records[0]['parent_node_hash'] ) );
		$this->assertSame( 8, SnapshotScaleConnection::bytes( $db->records[0] ) );
		$rows = $this->read( $db, array( 7, 12, 1, 8, 2 ) );
		$this->assertSame( array( $this->driver( $expected ) ), $rows );
		$rows[0]['payload'] = 'driver changed'; unset( $rows[0]['key_value'] );
		$this->assertSame( $expected, $this->named( $db->records[0] ) );
		$this->assertSame( array( $this->driver( $expected ) ), $this->read( $db, array( 7, 12, 1, 8, 2 ) ) );
		// Exercise exact-SQL layout reuse, another null pattern, and reordered columns.
		$second = $this->values(); $second['payload'] = ''; $second['parent_node_hash'] = '';
		$second = $this->insert( $db, array_reverse( $second, true ) );
		$this->assertSame( $second, $this->named( $db->records[1] ) );
		$third = $this->insert( $db, $this->values() );
		$this->assertSame( $third, $this->named( $db->records[2] ) );
	}
	public function test_every_schema_column_and_typed_arguments_survive_layout_reuse(): void {
		$db = new SnapshotScaleConnection();
		$definitions = ( new \FooPlugins\FooGalleryMigrate\LegacyStorageSchema( $db ) )->definitions();
		$columns = $definitions['wp_foogallery_migrate_records']['columns'];
		foreach ( array( false, true, false ) as $nullable ) {
			$values = array();
			foreach ( $columns as $column => $spec ) {
				if ( 'id' === $column ) { continue; }
				$values[$column] = $nullable && $spec[1] ? null : ( false !== strpos( $spec[0], 'int' ) ? 0 : "\0\xff" . $column );
			}
			$values['blog_id'] = 7; $values['state_id'] = 12; $values['generation'] = 1;
			// A string resembling an integer is not converted by the host backing/driver.
			$values['source_id_value'] = '00012'; $payload = $values['payload']; $values['payload'] =& $payload;
			$expected = $this->insert( $db, $values ); unset( $values['payload'] );
			// The expectation is detached too, so caller mutation cannot mask aliasing.
			$expected = $this->named( $expected ); $payload = 'mutated caller reference';
			$this->assertSame( $expected, $this->named( $db->records[count( $db->records ) - 1] ) );
			$this->assertSame( '00012', $db->records[count( $db->records ) - 1]['source_id_value'] );
		}
		$expected_rows = array(); foreach ( $db->records as $row ) { $expected_rows[] = $this->driver( $this->named( $row ) ); }
		$this->assertSame( $expected_rows, $this->read( $db, array( 7, 12, 1, 10000, 10 ) ) );
	}
	public function test_scope_byte_limit_counts_corruption_and_event_timing(): void {
		$db = new SnapshotScaleConnection(); $seen = array();
		$db->after_event = function ( $name, $connection ) use ( &$seen ) { $seen[] = array( $name, count( $connection->records ), $connection->emitted, $connection->fetches, $connection->fetched_rows ); };
		$one = $this->insert( $db, $this->values() );
		$two = $this->values(); $two['payload'] = str_repeat( 'x', 20 ); $two['payload_bytes'] = 20; $this->insert( $db, $two );
		foreach ( array( 'blog_id', 'state_id', 'generation' ) as $field ) { $other = $this->values(); ++$other[$field]; $this->insert( $db, $other ); }
		$this->assertSame( array( 'record-insert', 1, 1, 0, 0 ), $seen[0] );
		$this->assertSame( array( $this->driver( $one ) ), $this->read( $db, array( 7, 12, 1, 8, 10 ) ) );
		$this->assertSame( array(), $this->read( $db, array( 7, 12, 1, 7, 10 ) ) );
		$this->assertSame( array(), $this->read( $db, array( 7, 12, 1, 100, 0 ) ) );
		$this->assertCount( 1, $this->read( $db, array( 7, 12, 1, 100, 1 ) ) );
		$stats = $db->get_results( $db->prepare( 'SELECT COUNT(*) AS record_count FROM `wp_foogallery_migrate_records`', array( 7, 12, 1 ) ), ARRAY_A );
		$this->assertSame( array( array( 'record_count' => 2, 'payload_bytes' => 23, 'encoded_bytes' => 33 ) ), $stats );
		$db->corrupt_readback = true;
		$this->assertCount( 1, $this->read( $db, array( 7, 12, 1, 100, 10 ) ) );
		$this->assertSame( 5, $db->fetches ); $this->assertSame( 4, $db->fetched_rows ); $this->assertSame( 49, $db->fetched_bytes );
		$this->assertSame( array( 'fetch', 5, 5, 5, 4 ), end( $seen ) );
		$this->assertSame( array( 'record-insert' => 5, 'fetch' => 5, 'stats' => 1 ), $db->events );
	}
	public function test_prepared_handles_are_single_outstanding_and_consumed_once(): void {
		$db = new SnapshotScaleConnection();
		$old = $db->prepare( 'SELECT @@SESSION.autocommit AS autocommit', array() );
		$new = $db->prepare( 'SELECT @@SESSION.autocommit AS autocommit', array() );
		$this->assertSame( 'host-prepared-1', $old ); $this->assertSame( 'host-prepared-2', $new );
		$this->rejected( function () use ( $db, $old ) { $db->get_results( $old, ARRAY_A ); }, 'Unsupported host read' );
		$this->assertSame( array( array( 'autocommit' => 1 ) ), $db->get_results( $new, ARRAY_A ) );
		$this->rejected( function () use ( $db, $new ) { $db->get_results( $new, ARRAY_A ); }, 'Unsupported host read' );
		$handle = $db->prepare( 'COMMIT', array() ); $this->assertSame( 0, $db->query( $handle ) );
		$this->rejected( function () use ( $db, $handle ) { $db->query( $handle ); }, 'Unsupported host write' );
	}
	private function head( $db ) {
		return $this->insert( $db, array( 'blog_id' => 7, 'state_key_hash' => 'hash', 'state_key_value' => "\xffkey",
			'active_generation' => 1, 'revision' => 1, 'root_payload' => "\0root", 'digest_status' => 'current',
			'snapshot_digest' => 'digest1', 'digest_revision' => 1 ), 'state' );
	}
	private function root_update( $db, $args ) { return $db->query( $db->prepare( 'UPDATE `wp_foogallery_migrate_state` SET root_payload = %s', $args ) ); }
	private function publish( $db, $args ) { return $db->query( $db->prepare( 'UPDATE `wp_foogallery_migrate_state` SET active_generation = %d', $args ) ); }
	public function test_head_cas_metadata_limits_and_schema_delegation(): void {
		$db = new SnapshotScaleConnection(); $head = $this->head( $db ); $id = $head['id'];
		$this->rejected( function () use ( $db ) { $this->head( $db ); }, 'Duplicate host head' );
		foreach ( array( array( 'new', 8, $id, 1, 1 ), array( 'new', 7, $id, 2, 1 ), array( 'new', 7, $id, 1, 2 ), array( 'new', 7, 999, 1, 1 ) ) as $args ) { $this->assertSame( 0, $this->root_update( $db, $args ) ); }
		$this->assertSame( 0, $this->root_update( $db, array( "\0root", 7, $id, 1, 1 ) ) );
		$this->assertSame( 1, $this->root_update( $db, array( '', 7, $id, 1, 1 ) ) );
		$args = array( 2, 2, 0, 'map', 1, 3, 99, 'digest2', 2, "\0new", '2026-10-19', 7, $id, 1, 1, 'hash' );
		foreach ( array( 11, 12, 13, 14, 15 ) as $index ) { $bad = $args; $bad[$index] = 999; $this->assertSame( 0, $this->publish( $db, $bad ) ); }
		$this->assertSame( 1, $this->publish( $db, $args ) ); $this->assertSame( 0, $this->publish( $db, $args ) );
		foreach ( array( 'active_generation', 'revision', 'state_order', 'state_kind', 'codec_version', 'record_count', 'payload_bytes', 'snapshot_digest', 'digest_revision', 'root_payload', 'updated_gmt' ) as $i => $field ) { $this->assertSame( $args[$i], $db->heads[$id][$field] ); }
		$sql = 'SELECT head FROM state WHERE state_key_hash = UNHEX(%s)';
		$read = $db->get_results( $db->prepare( $sql, array( 3, 7, 'hash' ) ), ARRAY_A );
		$this->assertNull( $read[0]['state_key_value'] ); $this->assertSame( 4, $read[0]['root_payload_bytes'] ); $this->assertArrayNotHasKey( 'root_payload', $read[0] );
		$read = $db->get_results( $db->prepare( $sql, array( 4, 7, 'hash' ) ), ARRAY_A ); $this->assertSame( "\xffkey", $read[0]['state_key_value'] );
		$this->assertSame( array(), $db->get_results( $db->prepare( $sql, array( 4, 8, 'hash' ) ), ARRAY_A ) );
		$lock = $db->get_results( $db->prepare( 'SELECT id FROM state WHERE state_key_hash = UNHEX(%s)', array( 7, 'hash' ) ), ARRAY_A ); $this->assertSame( $id, $lock[0]['id'] );
		foreach ( array( array( 7, $id, 3 ), array( 8, $id, 4 ), array( 7, 999, 4 ) ) as $bad ) { $this->assertSame( array(), $db->get_results( $db->prepare( 'SELECT root_payload FROM state', $bad ), ARRAY_A ) ); }
		$this->assertSame( array( array( 'root_payload' => "\0new" ) ), $db->get_results( $db->prepare( 'SELECT root_payload FROM state', array( 7, $id, 4 ) ), ARRAY_A ) );
		$this->assertSame( array( array( 'snapshot_digest' => 'digest2', 'digest_revision' => 2, 'digest_status' => 'current' ) ), $db->get_results( $db->prepare( 'SELECT HEX(snapshot_digest) FROM state', array( 7, $id, 2, 2 ) ), ARRAY_A ) );
		foreach ( array( array( 8, $id, 2, 2 ), array( 7, $id, 1, 2 ), array( 7, $id, 2, 1 ), array( 7, 999, 2, 2 ) ) as $bad ) { $this->assertSame( array(), $db->get_results( $db->prepare( 'SELECT HEX(snapshot_digest) FROM state', $bad ), ARRAY_A ) ); }
		$schema = new \FooPlugins\FooGalleryMigrate\LegacyStorageSchema( $db );
		foreach ( $schema->definitions() as $table => $definition ) { $this->assertCount( count( $definition['columns'] ), $db->get_results( 'SHOW COLUMNS FROM `' . $table . '`', ARRAY_A ) ); }
	}
	public function test_rollback_cow_snapshots_commit_and_nontransactional_counters(): void {
		$db = new SnapshotScaleConnection(); $head = $this->head( $db ); $this->insert( $db, $this->values() );
		$heads = $db->heads; $records = $db->records;
		$this->assertSame( 0, $db->query( 'START TRANSACTION' ) );
		$this->root_update( $db, array( 'changed', 7, $head['id'], 1, 1 ) ); $this->insert( $db, $this->values() );
		$staged = $db->records;
		$this->assertSame( 0, $db->query( 'ROLLBACK' ) );
		$this->assertSame( $heads, $db->heads ); $this->assertSame( $records, $db->records ); $this->assertCount( 2, $staged );
		$this->assertSame( 2, $db->emitted ); $this->assertSame( 3, $db->insert_id );
		$this->assertSame( 0, $db->query( 'START TRANSACTION' ) ); $this->insert( $db, $this->values() );
		$this->assertSame( 4, $db->insert_id ); $db->query( 'COMMIT' ); $this->assertCount( 2, $db->records ); $this->assertCount( 1, $records );
		// An immutable adapter cannot mutate aliases in saved/external snapshots.
		if ( $records[0] instanceof \ArrayAccess ) {
			$this->rejected( function () use ( $records ) { $records[0]['payload'] = 'bad'; }, 'immutable' );
			$this->rejected( function () use ( $records ) { unset( $records[0]['payload'] ); }, 'immutable' );
			$copy = $records[0]['payload']; $copy[0] = 'x';
			$this->assertSame( "\0\xffp", $records[0]['payload'] );
		}
		$db->query( 'START TRANSACTION' );
		$db->after_event = function ( $event ) { if ( 'publish' === $event ) { throw new \RuntimeException( 'after publish' ); } };
		try { $this->publish( $db, array( 2, 2, 0, 'map', 1, 3, 99, 'digest2', 2, 'new', '', 7, $head['id'], 1, 1, 'hash' ) ); $this->fail(); }
		catch ( \RuntimeException $error ) { $this->assertSame( 'after publish', $error->getMessage() ); }
		$db->query( 'ROLLBACK' ); $this->assertSame( $heads, $db->heads ); $this->assertSame( 1, $db->events['publish'] );
		$empty = new SnapshotScaleConnection(); $empty->query( 'START TRANSACTION' ); $this->insert( $empty, $this->values() ); $empty->query( 'ROLLBACK' ); $this->assertSame( array(), $empty->records );
	}
	public function test_constructor_reentry_preserves_exact_external_and_rollback_snapshots(): void {
		foreach ( array( array(), array( 'replacement' ) ) as $replacement ) {
			$db = new SnapshotScaleConnection(); $expected = $this->insert( $db, $this->values() );
			$records = $db->records; $db->query( 'START TRANSACTION' ); $rejected = false;
			try { $db->records[0]->__construct( array( 'payload' => 0 ), $replacement ); }
			catch ( \LogicException $error ) { $rejected = true; }
			$external = $this->named( $records[0] );
			$db->query( 'ROLLBACK' );
			// Compare detached named values, not object identity shared by both snapshots.
			$this->assertSame( array( $expected, $expected ), array( $external, $this->named( $db->records[0] ) ) );
			$this->assertSame( array( "\0\xffp", "\0\xffp" ), array( $records[0]['payload'], $db->records[0]['payload'] ) );
			$this->assertTrue( $rejected );
			$this->assertSame( array( $this->driver( $expected ) ), $this->read( $db, array( 7, 12, 1, 8, 2 ) ) );
		}
	}
	public function test_empty_row_constructor_initializes_only_once(): void {
		foreach ( array( array(), array( 'payload' => 0 ) ) as $layout ) {
			$row = new SnapshotScaleRow( array(), array() ); $rejected = false;
			try { $row->__construct( $layout, array( "\0\xffchanged" ) ); }
			catch ( \LogicException $error ) { $rejected = true; }
			$this->assertSame( array(), $this->named( $row ) );
			$this->assertTrue( $rejected );
		}
	}
	public function test_unsupported_sql_including_delete_remains_fail_closed(): void {
		$db = new SnapshotScaleConnection(); $this->insert( $db, $this->values() ); $records = $db->records;
		foreach ( array( 'DELETE FROM `wp_foogallery_migrate_records`', 'TRUNCATE TABLE records', 'UPDATE records SET payload = 1' ) as $sql ) { $this->rejected( function () use ( $db, $sql ) { $db->query( $db->prepare( $sql, array() ) ); }, 'Unsupported host write' ); }
		$this->rejected( function () use ( $db ) { $db->get_results( 'SELECT unsupported', ARRAY_A ); }, 'Unsupported host read' );
		$this->assertSame( $records, $db->records );
	}
}
