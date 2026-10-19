<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/support/SnapshotScaleConnection.php';

/** SQL-double equivalence only; every persisted row and index stays live. */
class SnapshotScaleIndexTest extends TestCase {
	private function insert( $db, $scope, $parent, $order, $payload = 'p' ) {
		$row = array( 'blog_id' => $scope[0], 'state_id' => $scope[1], 'generation' => $scope[2],
			'parent_node_hash' => $parent, 'item_order' => $order, 'payload' => $payload,
			'mutable_payload' => '', 'key_value' => 'k', 'legacy_identifier_value' => null,
			'source_plugin_value' => null, 'source_id_value' => null, 'payload_bytes' => strlen( $payload ) );
		$columns = array(); $expressions = array(); $args = array();
		foreach ( $row as $key => $value ) {
			$columns[] = '`' . $key . '`'; $expressions[] = null === $value ? 'NULL' : ( is_int( $value ) ? '%d' : '%s' );
			if ( null !== $value ) { $args[] = $value; }
		}
		$sql = 'INSERT INTO `wp_foogallery_migrate_legacy_records` (' . implode( ',', $columns ) . ') VALUES (' . implode( ',', $expressions ) . ')';
		$this->assertSame( 1, $db->query( $db->prepare( $sql, $args ) ) );
	}
	private function window( $db, $scope, $parent, $order, $id, $limit, $maximum = null ) {
		$args = $scope; $where = 'parent_node_hash IS NULL';
		if ( null !== $parent ) { $where = 'parent_node_hash = UNHEX(%s)'; $args[] = $parent; }
		$args = array_merge( $args, array( $order, $order, $id ) );
		$sql = null === $maximum ? 'SELECT id, item_order, bytes AS window_bytes' : 'SELECT *';
		$sql .= ' FROM `wp_foogallery_migrate_legacy_records` WHERE blog_id = %d AND state_id = %d AND generation = %d AND ' . $where;
		$sql .= ' AND (item_order > %d OR (item_order = %d AND id > %d))';
		if ( null !== $maximum ) { $sql .= ' AND bytes <= %d'; $args[] = $maximum; }
		$sql .= ' ORDER BY item_order ASC, id ASC LIMIT %d'; $args[] = $limit;
		return $db->get_results( $db->prepare( $sql, $args ), ARRAY_A );
	}
	private function oracle( $db, $scope, $parent, $order, $id, $limit, $maximum ) {
		$rows = array();
		foreach ( $db->records as $row ) {
			$r = array(); foreach ( $row as $k => $v ) { $r[$k] = $v; }
			if ( array( $r['blog_id'], $r['state_id'], $r['generation'] ) !== $scope ||
				( null === $r['parent_node_hash'] ? null : strtolower( $r['parent_node_hash'] ) ) !== $parent ||
				!( $r['item_order'] > $order || ( $r['item_order'] === $order && $r['id'] > $id ) ) ||
				( null !== $maximum && SnapshotScaleConnection::bytes( $r ) > $maximum ) ) { continue; }
			$rows[] = $r;
		}
		usort( $rows, function ( $a, $b ) { return $a['item_order'] === $b['item_order'] ? $a['id'] <=> $b['id'] : $a['item_order'] <=> $b['item_order']; } );
		$rows = array_slice( $rows, 0, $limit );
		return array_map( function ( $r ) use ( $maximum ) {
			if ( null === $maximum ) { return array( 'id' => (string) $r['id'], 'item_order' => (string) $r['item_order'], 'window_bytes' => (string) SnapshotScaleConnection::bytes( $r ) ); }
			foreach ( $r as $k => $v ) { if ( is_int( $v ) ) { $r[$k] = (string) $v; } } return $r;
		}, $rows );
	}
	private function compare( $db, $scope, $parent ) {
		foreach ( array( array( 0, 0 ), array( 2, 0 ), array( 2, 15 ), array( 9, 999 ) ) as $cursor ) {
			foreach ( array( 0, 1, 3, 32 ) as $limit ) {
				foreach ( array( null, 2, 100 ) as $maximum ) {
					$this->assertSame( $this->oracle( $db, $scope, $parent, $cursor[0], $cursor[1], $limit, $maximum ), $this->window( $db, $scope, $parent, $cursor[0], $cursor[1], $limit, $maximum ) );
				}
			}
		}
	}
	public function test_index_matches_scan_oracle_for_scopes_nonpreorder_ties_and_windows(): void {
		$db = new SnapshotScaleConnection(); $parent = str_repeat( 'a', 64 );
		foreach ( array( 7, 8 ) as $blog ) { foreach ( array( 12, 13 ) as $state ) { foreach ( array( 1, 2 ) as $generation ) {
			foreach ( array( null, $parent ) as $p ) { foreach ( array( 5, 0, 2, 2 ) as $order ) { $this->insert( $db, array( $blog, $state, $generation ), $p, $order, str_repeat( 'x', $order + 1 ) ); } }
		} } }
		$rows = array(); foreach ( $db->records as $row ) { $r = iterator_to_array( $row ); $r['id'] = 100 - $r['id']; $rows[] = $r; }
		$db->records = array_reverse( $rows );
		foreach ( array( 7, 8 ) as $blog ) { foreach ( array( 12, 13 ) as $state ) { foreach ( array( 1, 2 ) as $generation ) {
			$this->compare( $db, array( $blog, $state, $generation ), null ); $this->compare( $db, array( $blog, $state, $generation ), $parent );
		} } }
		$this->compare( $db, array( 99, 12, 1 ), null );
		$this->assertSame( 1, $db->index_builds, 'Unchanged windows must reuse the revision, not scan the corpus.' );
		$this->assertSame( 64, $db->index_row_visits );
	}
	public function test_insert_rewrite_delete_and_rollback_invalidate_even_after_prepare(): void {
		$db = new SnapshotScaleConnection(); $scope = array( 7, 12, 1 );
		$this->insert( $db, $scope, null, 0 ); $this->compare( $db, $scope, null );
		$db->query( 'START TRANSACTION' ); $this->insert( $db, $scope, null, 2, 'long payload' ); $this->compare( $db, $scope, null );
		$held = $db->records; $rows = array(); foreach ( $held as $row ) { $rows[] = iterator_to_array( $row ); }
		$rows[0]['parent_node_hash'] = str_repeat( 'B', 64 ); $rows[0]['item_order'] = 2; $rows[0]['id'] = 999;
		$rows[0]['payload'] = 'changed'; $rows[0]['blog_id'] = 8; $rows[0]['generation'] = 2;
		$handle = $db->prepare( 'SELECT id, item_order, bytes AS window_bytes FROM `wp_foogallery_migrate_legacy_records` WHERE parent_node_hash IS NULL ORDER BY item_order ASC, id ASC LIMIT %d', array( 7, 12, 1, 0, 0, 0, 32 ) );
		$db->records = $rows;
		$this->assertSame( $this->oracle( $db, $scope, null, 0, 0, 32, null ), $db->get_results( $handle, ARRAY_A ) );
		$this->compare( $db, $scope, null ); $this->compare( $db, array( 8, 12, 2 ), str_repeat( 'b', 64 ) );
		array_pop( $rows ); $db->records = $rows; $this->compare( $db, $scope, null );
		$db->query( 'ROLLBACK' ); $this->compare( $db, $scope, null );
		$this->assertCount( 2, $held ); $this->assertCount( 1, $db->records );
		$this->assertSame( 5, $db->index_builds );
	}
	public function test_metadata_hook_mutation_is_visible_and_caller_aliases_cannot_bypass_revision(): void {
		$db = new SnapshotScaleConnection(); $scope = array( 7, 12, 1 );
		$this->insert( $db, $scope, null, 0 );
		$db->after_event = function ( $event, $db ) {
			if ( 'window-metadata' !== $event ) { return; }
			$rows = array(); foreach ( $db->records as $row ) { $rows[] = iterator_to_array( $row ); }
			$rows[0]['parent_node_hash'] = str_repeat( 'c', 64 ); $db->records = $rows; $db->after_event = null;
		};
		$this->assertCount( 1, $this->window( $db, $scope, null, 0, 0, 32 ) );
		$this->assertSame( array(), $this->window( $db, $scope, null, 0, 0, 32, 100 ) );
		$this->compare( $db, $scope, str_repeat( 'c', 64 ) );
		$held = $db->records; $payload = 'rewrite'; $held[0]['payload'] =& $payload; $db->records = $held;
		$payload = 'caller changed after rewrite';
		$view = $db->records; $view[0]['parent_node_hash'] = null;
		$this->assertSame( 'rewrite', $db->records[0]['payload'] );
		$this->assertSame( str_repeat( 'c', 64 ), $db->records[0]['parent_node_hash'] );
		$this->compare( $db, $scope, str_repeat( 'c', 64 ) );
		$this->assertSame( 3, $db->index_builds );
	}
}
