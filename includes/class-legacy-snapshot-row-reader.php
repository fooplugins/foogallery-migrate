<?php
/** Private scoped snapshot transport. No whole-generation driver result. */
namespace FooPlugins\FooGalleryMigrate;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class LegacySnapshotRowReader {
	const WINDOW_ROWS = 32;
	private $db;
	private $table;
	private $columns;
	private $bytes_sql;
	private $scope;
	private $window_bytes;
	private $admit;
	private $witness;

	public function __construct( $db, $table, $columns, $bytes_sql, $scope, $max_bytes, $admit, $witness = null ) {
		$this->db = $db; $this->table = $table; $this->columns = $columns;
		$this->bytes_sql = $bytes_sql; $this->scope = $scope;
		$this->window_bytes = min( LegacyTableReader::MAX_RECORD_BYTES, $max_bytes );
		$this->admit = $admit; $this->witness = $witness;
		if ( null !== $witness && ( ! $witness instanceof SnapshotPageWitness || ! $witness->scope_matches( $scope ) ) ) { $this->fail( 'Wrong producer witness scope.' ); }
	}

	/** Stable parent/order/id seek, independent of insertion/preorder IDs.
	 * The first result contains only fixed-width unsigned metadata. Payload SQL
	 * is independently bounded by rows * maximum column bytes, not by a
	 * post-fetch sum. Every column/projection remains in the payload SELECT.
	 */
	public function page( $parent, $order, $id ) {
		call_user_func( $this->admit, 0 );
		$where = 'blog_id = %d AND state_id = %d AND generation = %d AND parent_node_hash ';
		$args = $this->scope;
		if ( null === $parent ) { $where .= 'IS NULL'; }
		else { $where .= '= UNHEX(%s)'; $args[] = $parent; }
		$where .= ' AND (item_order > %d OR (item_order = %d AND id > %d))';
		$args[] = $order; $args[] = $order; $args[] = $id;
		$tail = ' FROM `' . $this->table . '` WHERE ' . $where;
		$seek = ' ORDER BY item_order ASC, id ASC LIMIT %d';
		$metadata = $this->read( $this->db->prepare( 'SELECT id, item_order, (' . $this->bytes_sql . ') AS window_bytes' . $tail . $seek, array_merge( $args, array( self::WINDOW_ROWS ) ) ) );
		$maximum = 0;
		foreach ( $metadata as $m ) {
			$size = $this->number( $m['window_bytes'] );
			if ( $size > $this->window_bytes ) { $this->fail( 'Oversized row before payload fetch.' ); }
			$maximum = max( $maximum, $size );
		}
		if ( ! count( $metadata ) ) { return array(); }
		$limit = min( count( $metadata ), (int) floor( $this->window_bytes / max( 1, $maximum ) ) );
		// mysqli's buffered result and associative rows coexist until free().
		// No wpdb get_results/query is called by the pinned production adapter;
		// it neither fills nor releases the caller's preexisting last_result.
		$workspace = 2 * $limit * ( $maximum + 8192 ) + 16384;
		call_user_func( $this->admit, $workspace );
		$sql = 'SELECT ' . $this->columns . $tail . ' AND (' . $this->bytes_sql . ') <= %d' . $seek;
		StorageScalarObserver::acquisition( 4, $workspace );
		$rows = $this->read( $this->db->prepare( $sql, array_merge( $args, array( $maximum, $limit ) ) ) );
		StorageScalarObserver::acquisition( 5, $workspace );
		if ( count( $rows ) !== $limit ) { $this->fail( 'Missing or changed window rows.' ); }
		foreach ( $rows as $i => $row ) {
			if ( $this->number( $row['id'] ) !== $this->number( $metadata[$i]['id'] ) || $this->number( $row['item_order'] ) !== $this->number( $metadata[$i]['item_order'] ) ) { $this->fail( 'Window cursor changed.' ); }
			foreach ( array( 'blog_id', 'state_id', 'generation' ) as $n => $field ) {
				if ( $this->number( $row[$field] ) !== $this->scope[$n] ) { $this->fail( 'Window escaped scope.' ); }
			}
			$actual_parent = null === $row['parent_node_hash'] ? null : strtolower( $row['parent_node_hash'] );
			if ( $actual_parent !== $parent ) { $this->fail( 'Window escaped parent.' ); }
		}
		// Authenticate EVERY row before returning any raw column for native decode.
		if ( null !== $this->witness ) { $this->witness->authenticate( $rows ); }
		call_user_func( $this->admit, $workspace );
		return $rows;
	}

	public function uses_witness( $witness ) { return null !== $witness && $this->witness === $witness; }

	private function read( $sql ) {
		$rows = $this->db->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) || ! empty( $this->db->last_error ) || count( $rows ) > self::WINDOW_ROWS ) { $this->fail( 'Window read failed.' ); }
		return $rows;
	}
	private function number( $v ) {
		if ( ( ! is_int( $v ) && ! is_string( $v ) ) || (string) (int) $v !== (string) $v || (int) $v < 0 ) { $this->fail( 'Invalid window integer.' ); }
		return (int) $v;
	}
	private function fail( $message ) { throw new \RuntimeException( 'Legacy snapshot row source: ' . $message ); }
}
