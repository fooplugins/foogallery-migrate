<?php
/**
 * Inert bounded compact map/scalar repository on the approved shared schema.
 * No provisioning, authority selection, option fallback or runtime hydration.
 * Caller must own the manager's writer guard and this idle pinned connection.
 * This is not yet an admitted engine backend (lists/wrappers need integration).
 *
 * @package FooPlugins\FooGalleryMigrate
 */
namespace FooPlugins\FooGalleryMigrate;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class LegacyTableRepository {
	private $db;
	private $blog;
	private $codec;
	private $records;
	private $reader;
	private $definitions;
	private $state;
	private $table;
	private $max_rows;
	private $max_bytes;
	private $max_seconds;
	private $deadline;
	private $used_rows;
	private $used_bytes;
	private $busy = false;
	private $tainted = false;

	/** Budgets span the complete call, including old AND new affected trees. */
	public function __construct( $db, $blog, $max_rows = 10000, $max_bytes = 16777216, $max_seconds = 10 ) {
		if ( ! $db instanceof LegacyTransactionConnection ) { $this->fail( 'A pinned non-replaying connection is required.' ); }
		foreach ( array( $blog, $max_rows, $max_bytes, $max_seconds ) as $n ) {
			if ( ! is_int( $n ) || $n < 1 ) { $this->fail( 'Invalid scope or operation budget.' ); }
		}
		$this->db = $db; $this->blog = $blog; $this->max_rows = $max_rows; $this->max_bytes = $max_bytes; $this->max_seconds = $max_seconds;
		$this->codec = new LegacyStateCodec();
		$this->records = new LegacyRecordCodec( $this->codec, $max_rows, $max_bytes );
		$this->reader = new LegacyTableReader( $db, $blog, $this->codec );
		$this->definitions = ( new LegacyStorageSchema( $db ) )->definitions();
		$tables = array_keys( $this->definitions ); $this->state = $tables[0]; $this->table = $tables[1];
	}

	/** Missing is distinct from a stored false/null. No full collection read. */
	public function root( $state_key, $key ) {
		$rows = $this->roots( $state_key, array( $key ) );
		return $rows[0];
	}

	/** One shared head and operation budget, NOT N independently bounded reads. */
	public function roots( $state_key, $keys ) {
		$this->start_budget(); $this->typed_key( $state_key );
		if ( ! is_array( $keys ) || count( $keys ) > LegacyTableReader::MAX_ROWS ) { $this->fail( 'Need at most 100 exact root keys.' ); }
		$seen = array();
		foreach ( $keys as $key ) {
			$this->typed_key( $key ); $hash = $this->codec->key_hash( $key );
			if ( isset( $seen[$hash] ) ) { $this->fail( 'Duplicate root key.' ); }
			$seen[$hash] = true;
			$this->consume( 0, strlen( $this->codec->key_value( $key ) ) );
		}
		if ( ! count( $keys ) ) { return array(); }
		$head = $this->head( $state_key ); $this->collection( $head ); $result = array();
		foreach ( $keys as $key ) {
			$tree = $this->tree( $state_key, $head, $key );
			$result[] = array( 'found' => false !== $tree, 'key' => $key, 'value' => false === $tree ? null : $tree['value'] );
		}
		$this->unchanged( $state_key, $head );
		return $result;
	}

	/** Existing small values/checkpoints only. Missing heads are health errors. */
	public function scalar( $state_key ) {
		$value = $this->read_scalar( $state_key );
		if ( ! $value['found'] ) { $this->fail( 'Missing state head; no option fallback.' ); }
		return $value['value'];
	}

	/** Physical presence only. Caller must verify the authoritative head manifest. */
	public function read_scalar( $state_key ) {
		$this->start_budget(); $this->typed_key( $state_key );
		$head = $this->reader->head( $state_key ); $this->tick();
		if ( false === $head ) { return array( 'found' => false, 'value' => null ); }
		if ( $head['revision'] < 1 || $head['payload_bytes'] < $head['root_payload_bytes'] ) { $this->fail( 'Invalid scalar head.' ); }
		$root = $this->root_metadata( $head );
		if ( 'scalar' !== $head['state_kind'] || array_keys( $root ) !== array( 'version', 'kind', 'value' ) || 0 !== $head['record_count'] ) {
			$this->fail( 'Not a scalar state head.' );
		}
		$this->verify_scalar( $head, $root['value'] );
		$this->unchanged( $state_key, $head );
		return array( 'found' => true, 'value' => $root['value'] );
	}

	/** Keyset page; cursor binds site, state, generation and revision exactly. */
	public function page( $state_key, $limit, $cursor = null ) {
		$this->start_budget(); $this->typed_key( $state_key );
		if ( ! is_int( $limit ) || $limit < 1 || $limit > LegacyTableReader::MAX_ROWS ) { $this->fail( 'Page limit must be 1 to 100.' ); }
		$head = $this->head( $state_key ); $this->collection( $head );
		$identity = array( 'blog' => $this->blog, 'state' => $head['id'], 'generation' => $head['active_generation'], 'revision' => $head['revision'] );
		$where = ''; $args = array( $this->blog, $head['id'], $head['active_generation'] );
		if ( null !== $cursor ) {
			if ( ! is_array( $cursor ) || array_keys( $cursor ) !== array( 'blog', 'state', 'generation', 'revision', 'order', 'id' ) ) { $this->fail( 'Malformed page cursor.' ); }
			foreach ( $identity as $field => $value ) { if ( $cursor[$field] !== $value ) { $this->fail( 'Stale or cross-scope cursor.' ); } }
			if ( ! is_int( $cursor['order'] ) || $cursor['order'] < 0 || ! is_int( $cursor['id'] ) || $cursor['id'] < 1 ) { $this->fail( 'Invalid ordering cursor.' ); }
			$where = ' AND (item_order > %d OR (item_order = %d AND id > %d))';
			$args = array_merge( $args, array( $cursor['order'], $cursor['order'], $cursor['id'] ) );
		}
		$args[] = $limit;
		$rows = $this->read( $this->db->prepare( 'SELECT id, item_order, HEX(node_hash) AS node_hash FROM `' . $this->table . '` '
			. 'WHERE blog_id = %d AND state_id = %d AND generation = %d AND parent_node_hash IS NULL' . $where . ' ORDER BY item_order ASC, id ASC LIMIT %d', $args ) );
		if ( count( $rows ) > $limit ) { $this->fail( 'Page row budget exceeded.' ); }
		$result = array(); $next = $cursor; $last_order = null === $cursor ? -1 : $cursor['order'];
		foreach ( $rows as $row ) {
			$id = $this->number( $row['id'] ); $order = $this->number( $row['item_order'] );
			if ( $id < 1 || $order <= $last_order ) { $this->fail( 'Duplicate or invalid root ordering.' ); }
			$tree = $this->tree_hash( $state_key, $head, $this->hex( $row['node_hash'] ) );
			if ( false === $tree || $tree['rows'][0]['id'] !== (string) $id && $tree['rows'][0]['id'] !== $id || $tree['order'] !== $order ) { $this->fail( 'Root changed during page read.' ); }
			$result[] = array( 'key' => $tree['key'], 'value' => $tree['value'] );
			$next = array_merge( $identity, array( 'order' => $order, 'id' => $id ) ); $last_order = $order;
		}
		$this->unchanged( $state_key, $head );
		return array( 'rows' => $result, 'cursor' => $next, 'revision' => $head['revision'] );
	}

	/** SQL-only root count; physical record_count includes nested children. */
	public function count_roots( $state_key ) {
		$this->start_budget(); $this->typed_key( $state_key ); $head = $this->head( $state_key ); $root = $this->collection( $head );
		$rows = $this->read( $this->db->prepare( 'SELECT COUNT(*) AS total FROM `' . $this->table . '` WHERE blog_id = %d AND state_id = %d AND generation = %d AND parent_node_hash IS NULL',
			array( $this->blog, $head['id'], $head['active_generation'] ) ) );
		if ( count( $rows ) !== 1 || $this->number( $rows[0]['total'] ) !== $root['root_count'] ) { $this->fail( 'Root count metadata mismatch.' ); }
		$this->unchanged( $state_key, $head ); return $root['root_count'];
	}

	/**
	 * Existing heads only. Changes are {key, expected_revision, patches} for
	 * maps, with each patch {key, value} OR {key, delete:true}; scalar changes
	 * are {key, expected_revision, value}. A map patch updates only that root
	 * tree. New keys append; surviving keys keep their order (gaps are legal).
	 * All heads/companion checkpoints commit together. Digests become dirty;
	 * no whole-history read/rehash. Lists and missing heads refuse, not normalize.
	 */
	public function patch_many( $changes ) {
		$this->start_budget(); $prepared = $this->preflight( $changes );
		( new LegacyStorageSchema( $this->db ) )->verify();
		$mode = $this->read( 'SELECT @@SESSION.autocommit AS autocommit' );
		if ( count( $mode ) !== 1 || $this->number( $mode[0]['autocommit'] ) !== 1 ) { $this->fail( 'An idle autocommit connection is required.' ); }
		$this->write( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' );
		$this->busy = true;
		try { $this->write( 'START TRANSACTION' ); }
		catch ( \Exception $error ) { $this->busy = false; $this->tainted = true; $this->fail( 'Transaction start uncertain; reconcile on a fresh connection.' ); }
		try {
			ksort( $prepared, SORT_STRING ); $heads = array();
			// Lock every companion first, in the same hash order as snapshot writes.
			foreach ( $prepared as $hash => $change ) {
				$heads[$hash] = $this->lock_head( $change['key'], $change['expected_revision'] );
			}
			$result = array();
			foreach ( $prepared as $hash => $change ) {
				$head = $heads[$hash]; $root = $this->root_metadata( $head );
				$count = $head['record_count']; $bytes = $head['payload_bytes'] - $head['root_payload_bytes'];
				if ( array_key_exists( 'value', $change ) ) {
					if ( 'scalar' !== $head['state_kind'] || $count !== 0 || array_keys( $root ) !== array( 'version', 'kind', 'value' ) ) { $this->fail( 'Scalar patch requires a scalar head.' ); }
					$this->verify_scalar( $head, $root['value'] );
					$root['value'] = $change['value'];
				} else {
					if ( 'map' !== $head['state_kind'] ) { $this->fail( 'Affected-root patches currently admit maps only.' ); }
					$root = $this->collection( $head );
					foreach ( $change['patches'] as $patch ) {
						$old = $this->tree( $change['key'], $head, $patch['key'] );
						$order = false === $old ? $this->append_order( $head ) : $old['order'];
						if ( false !== $old ) {
							foreach ( $old['rows'] as $row ) {
								if ( 1 !== $this->write( $this->db->prepare( 'DELETE FROM `' . $this->table . '` WHERE blog_id = %d AND state_id = %d AND generation = %d AND id = %d',
									array( $this->blog, $head['id'], $head['active_generation'], $this->number( $row['id'] ) ) ) ) ) { $this->fail( 'Affected row disappeared.' ); }
							}
							$count -= count( $old['rows'] ); $bytes -= $old['payload_bytes']; $root['root_count']--;
						}
						if ( array_key_exists( 'value', $patch ) ) {
							$repo = $this;
							$stats = $this->records->flatten_tree( $change['key'], $patch['key'], $patch['value'], $order, function ( $row ) use ( $repo, $head ) { $repo->insert_record( $head, $row ); } );
							$count = $this->add( $count, $stats['record_count'] ); $bytes = $this->add( $bytes, $stats['payload_bytes'] ); $root['root_count']++;
							// Verify canonical logical/projection parity before any head CAS.
							$new = $this->tree( $change['key'], $head, $patch['key'], false );
							if ( false === $new || $this->codec->snapshot_digest( $new['value'] ) !== $this->codec->snapshot_digest( $patch['value'] ) ) { $this->fail( 'Affected-tree read-back parity failed.' ); }
						}
					}
				}
				if ( $count < 0 || $bytes < 0 || $root['kind'] !== $head['state_kind'] ) { $this->fail( 'Invalid head statistics.' ); }
				$encoded = $this->codec->encode( $root );
				if ( strlen( $encoded ) > LegacyTableReader::MAX_RECORD_BYTES ) { $this->fail( 'Root payload exceeds the row byte budget.' ); }
				$digest_sql = "digest_status = 'dirty', "; $digest_args = array();
				if ( 'scalar' === $head['state_kind'] ) {
					// Small scalars have no per-record logical hash. Publish their
					// complete digest with the value, not a permanently dirty blob.
					$digest_sql = "digest_status = 'current', snapshot_digest = UNHEX(%s), digest_revision = %d, ";
					$digest_args = array( $this->codec->snapshot_digest( $root['value'] ), $head['revision'] + 1 );
				}
				$sql = 'UPDATE `' . $this->state . '` SET root_payload = %s, record_count = %d, payload_bytes = %d, revision = %d, '
					. $digest_sql . 'updated_gmt = %s WHERE blog_id = %d AND id = %d AND active_generation = %d AND revision = %d';
				$args = array_merge( array( $encoded, $count, $this->add( $bytes, strlen( $encoded ) ), $head['revision'] + 1 ), $digest_args,
					array( gmdate( 'Y-m-d H:i:s' ), $this->blog, $head['id'], $head['active_generation'], $head['revision'] ) );
				if ( 1 !== $this->write( $this->db->prepare( $sql, $args ) ) ) { $this->fail( 'Head compare-and-swap failed.' ); }
				$check = $this->head( $change['key'] );
				if ( $check['revision'] !== $head['revision'] + 1 || $check['record_count'] !== $count || $this->root_metadata( $check ) !== $root ) { $this->fail( 'Head read-back failed.' ); }
				$result[] = array( 'key' => $change['key'], 'revision' => $head['revision'] + 1 );
			}
		} catch ( \Exception $error ) {
			$ok = $this->db->query( 'ROLLBACK' ); $this->busy = false;
			if ( false === $ok || ! empty( $this->db->last_error ) ) { $this->tainted = true; $this->fail( 'Rollback uncertain; reconcile on a fresh connection.' ); }
			throw $error;
		}
		try { $this->write( 'COMMIT' ); }
		catch ( \Exception $error ) { $this->tainted = true; $this->busy = false; $this->db->query( 'ROLLBACK' ); $this->fail( 'Commit outcome uncertain; reconcile heads on a fresh connection, never replay.' ); }
		$this->busy = false; return $result;
	}

	private function preflight( $changes ) {
		if ( ! is_array( $changes ) || ! count( $changes ) || count( $changes ) > 100 ) { $this->fail( 'Need 1 to 100 changes.' ); }
		$result = array();
		foreach ( $changes as $change ) {
			if ( ! is_array( $change ) || ! isset( $change['key'], $change['expected_revision'] ) || count( $change ) !== 3 ||
				! is_int( $change['expected_revision'] ) || $change['expected_revision'] < 1 || $change['expected_revision'] >= PHP_INT_MAX ) { $this->fail( 'Invalid change or revision.' ); }
			$this->typed_key( $change['key'] ); $hash = $this->codec->key_hash( $change['key'] );
			if ( isset( $result[$hash] ) ) { $this->fail( 'Duplicate state change.' ); }
			$this->consume( 0, strlen( $this->codec->key_value( $change['key'] ) ) );
			if ( array_key_exists( 'value', $change ) ) {
				$encoded = $this->codec->encode( array( 'version' => 1, 'kind' => 'scalar', 'value' => $change['value'] ) );
				if ( strlen( $encoded ) > LegacyTableReader::MAX_RECORD_BYTES ) { $this->fail( 'Scalar row byte budget exceeded.' ); }
				$this->consume( 0, strlen( $encoded ) );
			} else {
				if ( ! isset( $change['patches'] ) || ! is_array( $change['patches'] ) || ! count( $change['patches'] ) || count( $change['patches'] ) > $this->max_rows ) { $this->fail( 'Invalid affected patches.' ); }
				$seen = array();
				foreach ( $change['patches'] as $patch ) {
					if ( ! is_array( $patch ) || ! isset( $patch['key'] ) || count( $patch ) !== 2 ||
						( ! array_key_exists( 'value', $patch ) && ( ! isset( $patch['delete'] ) || true !== $patch['delete'] ) ) ) { $this->fail( 'Invalid root patch.' ); }
					$this->typed_key( $patch['key'] ); $node = $this->codec->node_hash( $hash, null, $patch['key'] );
					if ( isset( $seen[$node] ) ) { $this->fail( 'Duplicate root patch.' ); } $seen[$node] = true;
					$this->consume( 0, strlen( $this->codec->key_value( $patch['key'] ) ) );
					if ( array_key_exists( 'value', $patch ) ) {
						$stats = $this->records->flatten_tree( $change['key'], $patch['key'], $patch['value'], 0, function () {} );
						$this->consume( $stats['record_count'], $stats['encoded_bytes'] );
					}
				}
			}
			$result[$hash] = $change;
		}
		return $result;
	}

	private function lock_head( $key, $revision ) {
		$value = $this->codec->key_value( $key );
		$rows = $this->read( $this->db->prepare( 'SELECT id, CASE WHEN OCTET_LENGTH(state_key_value) <= %d THEN state_key_value ELSE NULL END AS state_key_value '
			. 'FROM `' . $this->state . '` WHERE blog_id = %d AND state_key_hash = UNHEX(%s) LIMIT 2 FOR UPDATE', array( strlen( $value ), $this->blog, $this->codec->key_hash( $key ) ) ) );
		if ( count( $rows ) !== 1 || $rows[0]['state_key_value'] !== $value ) { $this->fail( 'Missing/colliding locked head.' ); }
		$head = $this->head( $key );
		if ( $head['id'] !== $this->number( $rows[0]['id'] ) || $head['revision'] !== $revision || $head['revision'] >= PHP_INT_MAX ) { $this->fail( 'Stale or exhausted revision.' ); }
		return $head;
	}

	private function head( $key ) {
		$this->tick(); $head = $this->reader->head( $key ); $this->tick();
		if ( false === $head || $head['revision'] < 1 || $head['payload_bytes'] < $head['root_payload_bytes'] ) { $this->fail( 'Missing or invalid head; no option fallback.' ); }
		return $head;
	}

	private function unchanged( $key, $head ) { if ( $this->head( $key ) !== $head ) { $this->fail( 'State changed; restart bounded read.' ); } }

	private function root_metadata( $head ) {
		if ( $head['root_payload_bytes'] > LegacyTableReader::MAX_RECORD_BYTES ) { $this->fail( 'Oversized root metadata.' ); }
		$this->consume( 0, $head['root_payload_bytes'] );
		$rows = $this->read( $this->db->prepare( 'SELECT root_payload FROM `' . $this->state . '` WHERE blog_id = %d AND id = %d AND revision = %d AND active_generation = %d AND OCTET_LENGTH(root_payload) = %d LIMIT 1',
			array( $this->blog, $head['id'], $head['revision'], $head['active_generation'], $head['root_payload_bytes'] ) ) );
		if ( count( $rows ) !== 1 ) { $this->fail( 'Root metadata changed or disappeared.' ); }
		$root = $this->codec->decode( $rows[0]['root_payload'] );
		if ( ! is_array( $root ) || ! isset( $root['version'], $root['kind'] ) || 1 !== $root['version'] || $root['kind'] !== $head['state_kind'] ) { $this->fail( 'Invalid root metadata.' ); }
		return $root;
	}

	private function collection( $head ) {
		$root = $this->root_metadata( $head );
		if ( ! in_array( $head['state_kind'], array( 'map', 'list' ), true ) || array_keys( $root ) !== array( 'version', 'kind', 'root_count' ) ||
			! is_int( $root['root_count'] ) || $root['root_count'] < 0 || $root['root_count'] > $head['record_count'] ) { $this->fail( 'Invalid collection metadata.' ); }
		return $root;
	}

	private function verify_scalar( $head, $value ) {
		$rows = $this->read( $this->db->prepare( 'SELECT HEX(snapshot_digest) AS digest, digest_revision, digest_status FROM `' . $this->state . '` '
			. 'WHERE blog_id = %d AND id = %d AND revision = %d AND active_generation = %d LIMIT 1',
			array( $this->blog, $head['id'], $head['revision'], $head['active_generation'] ) ) );
		if ( count( $rows ) !== 1 || 'current' !== $rows[0]['digest_status'] || $this->number( $rows[0]['digest_revision'] ) !== $head['revision'] ||
			$this->hex( $rows[0]['digest'] ) !== $this->codec->snapshot_digest( $value ) ) { $this->fail( 'Scalar digest verification failed.' ); }
	}

	private function tree( $state_key, $head, $key, $charge = true ) {
		$hash = $this->codec->node_hash( $this->codec->key_hash( $state_key ), null, $key );
		$tree = $this->tree_hash( $state_key, $head, $hash, $charge );
		if ( false !== $tree && $tree['key'] !== $key ) { $this->fail( 'Root-key collision.' ); }
		return $tree;
	}

	/** Discover/sum bounded metadata first; never fetch oversized LONGTEXT. */
	private function tree_hash( $state_key, $head, $hash, $charge = true ) {
		$base = 'blog_id = %d AND state_id = %d AND generation = %d';
		$scope = array( $this->blog, $head['id'], $head['active_generation'] );
		$select = 'SELECT id, blog_id, state_id, generation, item_order, HEX(node_hash) AS node_hash, HEX(parent_node_hash) AS parent_node_hash, payload_bytes, '
			. $this->record_bytes() . ' AS encoded_bytes FROM `' . $this->table . '` WHERE ' . $base;
		$meta = $this->read( $this->db->prepare( $select . ' AND node_hash = UNHEX(%s) AND parent_node_hash IS NULL LIMIT 2', array_merge( $scope, array( $hash ) ) ) );
		if ( ! count( $meta ) ) { return false; }
		if ( count( $meta ) !== 1 ) { $this->fail( 'Duplicate root.' ); }
		$total = 0; $payload = 0; $seen = array();
		for ( $i = 0; $i < count( $meta ); $i++ ) {
			if ( count( $meta ) > $this->max_rows ) { $this->fail( 'Affected tree row budget exceeded.' ); }
			$row = $meta[$i]; $node = $this->hex( $row['node_hash'] ); $bytes = $this->number( $row['encoded_bytes'] );
			if ( isset( $seen[$node] ) || $bytes > LegacyTableReader::MAX_RECORD_BYTES || $bytes > $this->max_bytes - $total ) { $this->fail( 'Invalid tree identity or encoded byte budget.' ); }
			$seen[$node] = true; $total += $bytes; $payload = $this->add( $payload, $this->number( $row['payload_bytes'] ) );
			foreach ( array( 'blog_id' => $this->blog, 'state_id' => $head['id'], 'generation' => $head['active_generation'] ) as $field => $expected ) {
				if ( $this->number( $row[$field] ) !== $expected ) { $this->fail( 'Tree scope mismatch.' ); }
			}
			$last = -1;
			do {
				$children = $this->read( $this->db->prepare( $select . ' AND parent_node_hash = UNHEX(%s) AND item_order > %d ORDER BY item_order ASC, id ASC LIMIT 100', array_merge( $scope, array( $node, $last ) ) ) );
				if ( count( $children ) > 100 || count( $children ) > $this->max_rows - count( $meta ) ) { $this->fail( 'Affected tree row budget exceeded.' ); }
				foreach ( $children as $child ) {
					$order = $this->number( $child['item_order'] );
					if ( $order !== $last + 1 || $this->hex( $child['parent_node_hash'] ) !== $node ) { $this->fail( 'Invalid child ordering/boundary.' ); }
					$last = $order; $meta[] = $child;
				}
			} while ( count( $children ) === 100 );
		}
		if ( $charge ) { $this->consume( count( $meta ), $total ); }
		$columns = array();
		foreach ( $this->definitions[$this->table]['columns'] as $name => $spec ) { $columns[] = 'binary(32)' === $spec[0] ? 'HEX(`' . $name . '`) AS `' . $name . '`' : '`' . $name . '`'; }
		$rows = array();
		foreach ( $meta as $expected ) {
			$found = $this->read( $this->db->prepare( 'SELECT ' . implode( ', ', $columns ) . ', ' . $this->record_bytes() . ' AS encoded_bytes FROM `' . $this->table . '` WHERE ' . $base
				. ' AND id = %d AND (' . $this->record_bytes() . ') = %d LIMIT 1', array_merge( $scope, array( $this->number( $expected['id'] ), $this->number( $expected['encoded_bytes'] ) ) ) ) );
			if ( count( $found ) !== 1 ) { $this->fail( 'Sized record changed or disappeared.' ); }
			foreach ( $expected as $field => $value ) {
				$actual = $found[0][$field];
				if ( null !== $value && substr( $field, -5 ) === '_hash' ) { $actual = $this->hex( $actual ); $value = $this->hex( $value ); }
				else if ( null !== $value ) { $actual = $this->number( $actual ); $value = $this->number( $value ); }
				if ( $actual !== $value ) { $this->fail( 'Record metadata changed.' ); }
			}
			$rows[] = $found[0];
		}
		$value = $this->records->rebuild_tree( $state_key, $hash, $rows );
		$root = $rows[0]; $key_bytes = base64_decode( $root['key_value'], true );
		$key = 'integer' === $root['key_type'] ? (int) $key_bytes : $key_bytes;
		return array( 'key' => $key, 'value' => $value, 'rows' => $rows, 'order' => $this->number( $root['item_order'] ), 'payload_bytes' => $payload );
	}

	private function append_order( $head ) {
		$rows = $this->read( $this->db->prepare( 'SELECT item_order FROM `' . $this->table . '` WHERE blog_id = %d AND state_id = %d AND generation = %d AND parent_node_hash IS NULL ORDER BY item_order DESC, id DESC LIMIT 1',
			array( $this->blog, $head['id'], $head['active_generation'] ) ) );
		return count( $rows ) ? $this->add( $this->number( $rows[0]['item_order'] ), 1 ) : 0;
	}

	private function insert_record( $head, $row ) {
		if ( ! $this->busy || $this->tainted ) { $this->fail( 'Record writes require an owned transaction.' ); }
		$row = array_merge( $row, array( 'blog_id' => $this->blog, 'state_id' => $head['id'], 'generation' => $head['active_generation'] ) );
		$columns = array(); $expressions = array(); $args = array();
		foreach ( $this->definitions[$this->table]['columns'] as $name => $spec ) {
			if ( 'id' === $name ) { continue; }
			$value = $spec[1] ? null : ( false !== strpos( $spec[0], 'int' ) ? 0 : ( 'datetime' === $spec[0] ? gmdate( 'Y-m-d H:i:s' ) : '' ) );
			if ( array_key_exists( $name, $row ) ) { $value = $row[$name]; }
			$columns[] = '`' . $name . '`';
			if ( null === $value ) { $expressions[] = 'NULL'; continue; }
			$expressions[] = 'binary(32)' === $spec[0] ? 'UNHEX(%s)' : ( false !== strpos( $spec[0], 'int' ) ? '%d' : '%s' ); $args[] = $value;
		}
		if ( 1 !== $this->write( $this->db->prepare( 'INSERT INTO `' . $this->table . '` (' . implode( ',', $columns ) . ') VALUES (' . implode( ',', $expressions ) . ')', $args ) ) ) { $this->fail( 'Record insert failed.' ); }
	}

	private function record_bytes() { return 'OCTET_LENGTH(payload) + OCTET_LENGTH(mutable_payload) + OCTET_LENGTH(key_value) + COALESCE(OCTET_LENGTH(legacy_identifier_value),0) + COALESCE(OCTET_LENGTH(source_plugin_value),0) + COALESCE(OCTET_LENGTH(source_id_value),0)'; }
	private function typed_key( $key ) {
		$this->codec->key_value( $key ); $probe = array( $key => true ); $keys = array_keys( $probe );
		if ( $keys[0] !== $key ) { $this->fail( 'Invalid or coercible typed key.' ); }
	}
	private function number( $value ) {
		if ( ( ! is_int( $value ) && ! is_string( $value ) ) || (string) (int) $value !== (string) $value || (int) $value < 0 ) { $this->fail( 'Invalid unsigned number.' ); }
		return (int) $value;
	}
	private function add( $a, $b ) { if ( $a < 0 || $b < 0 || $b > PHP_INT_MAX - $a ) { $this->fail( 'Counter/order overflow.' ); } return $a + $b; }
	private function hex( $value ) { if ( ! is_string( $value ) || ! preg_match( '/^[0-9a-f]{64}$/Di', $value ) ) { $this->fail( 'Invalid hash.' ); } return strtolower( $value ); }
	private function start_budget() {
		if ( $this->busy || $this->tainted ) { $this->fail( 'Busy or uncertain repository; reconcile on a fresh connection.' ); }
		$this->deadline = microtime( true ) + $this->max_seconds; $this->used_rows = 0; $this->used_bytes = 0;
	}
	private function tick() { if ( microtime( true ) > $this->deadline ) { $this->fail( 'Operation time budget exceeded.' ); } }
	private function consume( $rows, $bytes ) {
		$this->tick();
		if ( $rows > $this->max_rows - $this->used_rows || $bytes > $this->max_bytes - $this->used_bytes ) { $this->fail( 'Operation row/encoded byte budget exceeded.' ); }
		$this->used_rows += $rows; $this->used_bytes += $bytes;
	}
	private function read( $sql ) {
		$this->tick(); $rows = $this->db->get_results( $sql, ARRAY_A ); $this->tick();
		if ( ! is_array( $rows ) || ! empty( $this->db->last_error ) ) { $this->fail( 'Database read failed.' ); } return $rows;
	}
	private function write( $sql ) {
		$this->tick(); $result = $this->db->query( $sql );
		if ( false === $result || ! empty( $this->db->last_error ) ) { $this->fail( 'Database write failed.' ); } return $result;
	}
	private function fail( $message ) { throw new \RuntimeException( 'Legacy table repository: ' . $message ); }
}
