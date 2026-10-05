<?php
/**
 * Inert bounded compact map/list/scalar repository on the approved shared schema.
 * No provisioning, authority selection, option fallback or runtime hydration.
 * Caller must own the manager's writer guard and this idle pinned connection.
 * This is not yet an admitted engine backend (logical wrappers need integration).
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
	private $byte_limit;
	private $busy = false;
	private $tainted = false;
	private $history_read_active = false;

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

	/** Bounded head/root metadata, with one budget and coherent multi-head read.
	 * Collection payloads are never fetched. Missing required heads fail closed.
	 */
	public function metadata_many( $keys ) {
		$this->start_budget();
		if ( ! is_array( $keys ) || ! count( $keys ) || count( $keys ) > min( $this->max_rows, LegacyTableReader::MAX_ROWS ) ) { $this->fail( 'Need 1 to 100 metadata keys within the shared row budget.' ); }
		$this->consume( count( $keys ), 0 );
		$seen = array();
		foreach ( $keys as $key ) {
			$this->typed_key( $key ); $hash = $this->codec->key_hash( $key );
			if ( isset( $seen[$hash] ) ) { $this->fail( 'Duplicate metadata key.' ); } $seen[$hash] = true;
			$this->consume( 0, strlen( $this->codec->key_value( $key ) ) );
		}
		$result = array();
		foreach ( $keys as $key ) {
			$head = $this->head( $key );
			if ( 'scalar' === $head['state_kind'] ) {
				$root = $this->root_metadata( $head );
				if ( array_keys( $root ) !== array( 'version', 'kind', 'value' ) || 0 !== $head['record_count'] ) { $this->fail( 'Invalid scalar metadata.' ); }
				$this->verify_scalar( $head, $root['value'] );
			} else { $root = $this->collection( $head ); }
			$result[] = array( 'key' => $key, 'head' => $head, 'root' => $root );
		}
		foreach ( $result as $entry ) { $this->unchanged( $entry['key'], $entry['head'] ); }
		return $result;
	}

	/** One budget covers both metadata checks and the indexed history read.
	 * Closed read primitives only: no callback or complete-history facade.
	 */
	public function history_read( $operation, $request = array() ) {
		if ( $this->history_read_active || ! is_array( $request ) || ! in_array( $operation, array( 'identifiers', 'presence', 'source', 'page', 'count', 'revision' ), true ) ) { $this->fail( 'Unsupported history read.' ); }
		$this->start_budget(); $this->history_read_active = true;
		try {
			$keys = array( 'migrated', 'migrated-revision' ); $context = $this->metadata_many( $keys );
			if ( 'map' !== $context[0]['head']['state_kind'] || ! isset( $context[0]['root']['wrapper'] ) ) { $this->fail( 'Indexed history wrapper required; old header-root layouts are not admitted.' ); }
			switch ( $operation ) {
				case 'identifiers': $result = $this->identifiers( 'migrated', $request ); break;
				case 'presence': $result = $this->identifier_presence( 'migrated', $request ); break;
				case 'source':
					if ( array_keys( $request ) !== array( 'type', 'plugin', 'id' ) ) { $this->fail( 'Malformed source request.' ); }
					$result = $this->history_source_identity( $request['type'], $request['plugin'], $request['id'] ); break;
				case 'page':
					if ( array_keys( $request ) !== array( 'limit', 'filters', 'cursor' ) ) { $this->fail( 'Malformed page request.' ); }
					$result = $this->history_page( 'migrated', $request['limit'], $request['filters'], $request['cursor'] ); break;
				case 'count': $result = $this->count_roots( 'migrated' ); break;
				default: $result = null;
			}
			if ( $this->metadata_many( $keys ) !== $context ) { $this->fail( 'History changed during bounded read.' ); }
			return array( 'context' => $context, 'result' => $result );
		} finally { $this->history_read_active = false; }
	}

	/** Closed logical mutations over an admitted indexed history wrapper.
	 * One budget spans metadata, old/new affected roots and atomic publication.
	 * All read dependencies are locked/CAS-checked, even unchanged companions.
	 */
	public function history_mutate( $operation, $request, $companions = array() ) {
		if ( $this->history_read_active || ! in_array( $operation, array( 'upsert', 'status', 'delete' ), true ) || ! is_array( $request ) ||
			! is_array( $companions ) || count( $companions ) > 98 || ( 'upsert' !== $operation && count( $companions ) ) ) { $this->fail( 'Unsupported history mutation.' ); }
		$this->start_budget(); $this->history_read_active = true;
		try {
			$keys = array( 'migrated', 'migrated-revision' ); $identifiers = array();
			foreach ( $companions as $key => $value ) {
				$this->typed_key( $key );
				if ( in_array( $key, array( 'migrated', 'migrated-revision', 'plugins', 'galleries', 'albums' ), true ) ) { $this->fail( 'Reserved history companion.' ); }
				// Reject objects/oversized indivisible values before fetching metadata.
				$encoded = $this->codec->encode( array( 'version' => 1, 'kind' => 'scalar', 'value' => $value ) );
				if ( strlen( $encoded ) > LegacyTableReader::MAX_RECORD_BYTES ) { $this->fail( 'Companion row byte budget exceeded.' ); }
				$this->consume( 0, strlen( $encoded ) ); $keys[] = $key;
			}
			if ( 'upsert' === $operation ) {
				if ( count( $request ) > 100 ) { $this->fail( 'Need at most 100 history records.' ); }
				foreach ( $request as $record ) {
					if ( ! is_array( $record ) || array_keys( $record ) !== array( 'key', 'value', 'parent', 'fallback_parent' ) || ! is_array( $record['value'] ) ||
						( null !== $record['parent'] && ! is_string( $record['parent'] ) ) || ( null !== $record['fallback_parent'] && ! is_string( $record['fallback_parent'] ) ) ) { $this->fail( 'Malformed compact history upsert.' ); }
					$this->typed_key( $record['key'] ); $identifiers[$this->codec->key_hash( $record['key'] )] = $record['key'];
					$this->consume( 0, strlen( $this->codec->encode( $record ) ) );
				}
				if ( ! count( $request ) && ! count( $companions ) ) { return 0; }
			} else {
				if ( array_keys( $request ) !== ( 'status' === $operation ? array( 'key', 'status' ) : array( 'key' ) ) ) { $this->fail( 'Malformed history mutation key.' ); }
				$this->typed_key( $request['key'] ); $identifiers[] = $request['key'];
				if ( 'status' === $operation && ( ! is_string( $request['status'] ) || strlen( $request['status'] ) > 32 ) ) { $this->fail( 'Unindexed status is not admitted.' ); }
			}
			$context = $this->metadata_many( $keys ); $history = $context[0]; $revision = $context[1];
			if ( 'map' !== $history['head']['state_kind'] || ! isset( $history['root']['wrapper'] ) ) { $this->fail( 'Indexed history wrapper required.' ); }
			if ( 'scalar' !== $revision['head']['state_kind'] || ! is_int( $revision['root']['value'] ) || $revision['root']['value'] < 0 ) { $this->fail( 'Invalid logical history revision.' ); }
			foreach ( array_slice( $context, 2 ) as $entry ) { if ( 'scalar' !== $entry['head']['state_kind'] ) { $this->fail( 'Existing scalar companion heads required.' ); } }
			$old = $this->identifiers( 'migrated', array_values( $identifiers ) ); $working = array();
			foreach ( $old as $row ) { $working[$row['key']] = $row; }
			$result = 'upsert' === $operation ? count( $request ) : true;
			if ( 'upsert' === $operation ) {
				foreach ( $request as $record ) {
					$previous = $working[$record['key']]['value']; $payload = $record['value'];
					$parent = null !== $record['parent'] ? $record['parent'] :
						( is_array( $previous ) && isset( $previous['migration_parent_key'] ) ? $previous['migration_parent_key'] : $record['fallback_parent'] );
					if ( null !== $parent ) { $payload['migration_parent_key'] = $parent; }
					$working[$record['key']] = array( 'found' => true, 'key' => $record['key'], 'value' => $payload );
				}
			} else {
				$row = $old[0];
				if ( ! $row['found'] || ( 'status' === $operation && ! is_array( $row['value'] ) ) ) { $result = false; }
				elseif ( 'delete' === $operation ) { $working[$row['key']]['found'] = false; }
				else { $working[$row['key']]['value']['migration_status'] = $request['status']; }
			}
			$patches = array();
			foreach ( $old as $row ) {
				$new = $working[$row['key']];
				if ( $row['found'] === $new['found'] && serialize( $row['value'] ) === serialize( $new['value'] ) ) { continue; }
				$patches[] = $new['found'] ? array( 'key' => $row['key'], 'value' => $new['value'] ) : array( 'key' => $row['key'], 'delete' => true );
			}
			$changes = array();
			if ( count( $patches ) ) {
				if ( $revision['root']['value'] >= PHP_INT_MAX ) { $this->fail( 'Logical history revision exhausted.' ); }
				$changes[] = array( 'key' => 'migrated', 'expected_revision' => $history['head']['revision'], 'patches' => $patches );
				$changes[] = array( 'key' => 'migrated-revision', 'expected_revision' => $revision['head']['revision'], 'value' => $revision['root']['value'] + 1 );
			}
			foreach ( array_slice( $context, 2 ) as $entry ) {
				$value = $companions[$entry['key']];
				if ( serialize( $entry['root']['value'] ) !== serialize( $value ) ) { $changes[] = array( 'key' => $entry['key'], 'expected_revision' => $entry['head']['revision'], 'value' => $value ); }
			}
			if ( $this->metadata_many( $keys ) !== $context ) { $this->fail( 'History changed during bounded mutation.' ); }
			if ( count( $changes ) ) { $this->patch( $changes, false, $context ); }
			return $result;
		} finally { $this->history_read_active = false; }
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
	public function page( $state_key, $limit, $cursor = null ) { return $this->read_page( $state_key, $limit, $cursor, array(), false ); }

	/** Filter-bound keyset cursor; never translate arbitrary offsets into full scans. */
	public function filtered_page( $state_key, $limit, $filters, $cursor = null ) { return $this->read_page( $state_key, $limit, $cursor, $filters, true ); }

	/** Logical history pages need a terminal cursor, not an extra empty page.
	 * Look ahead at one root's small selector only; never decode that tree.
	 */
	public function history_page( $state_key, $limit, $filters, $cursor = null ) {
		if ( 'migrated' !== $state_key ) { $this->fail( 'History state required.' ); }
		return $this->read_page( $state_key, $limit, $cursor, $filters, true, false, true );
	}

	/** Explicit maintenance/export only; includes verified physical accounting. */
	public function export_page( $state_key, $limit, $cursor = null ) { return $this->read_page( $state_key, $limit, $cursor, array(), false, true ); }

	private function read_page( $state_key, $limit, $cursor, $filters, $filtered, $export = false, $terminal = false ) {
		$this->start_budget(); $this->typed_key( $state_key );
		$this->byte_limit = min( $this->max_bytes, 4194304 );
		$this->consume( 0, strlen( $this->codec->key_value( $state_key ) ) );
		if ( ! is_int( $limit ) || $limit < 1 || $limit > LegacyTableReader::MAX_ROWS ) { $this->fail( 'Page limit must be 1 to 100.' ); }
		$filter = $this->filters( $filters );
		$head = $this->head( $state_key ); $this->collection( $head );
		$identity = array( 'blog' => $this->blog, 'state' => $head['id'], 'generation' => $head['active_generation'], 'revision' => $head['revision'] );
		if ( $filtered ) { $identity['filter'] = $filter['digest']; }
		$where = $filter['sql']; $args = array_merge( array( $this->blog, $head['id'], $head['active_generation'] ), $filter['args'] );
		if ( null !== $cursor ) {
			if ( ! is_array( $cursor ) || array_keys( $cursor ) !== array_merge( array_keys( $identity ), array( 'order', 'id' ) ) ) { $this->fail( 'Malformed page cursor.' ); }
			foreach ( $identity as $field => $value ) { if ( $cursor[$field] !== $value ) { $this->fail( 'Stale or cross-scope cursor.' ); } }
			if ( ! is_int( $cursor['order'] ) || $cursor['order'] < 0 || ! is_int( $cursor['id'] ) || $cursor['id'] < 1 ) { $this->fail( 'Invalid ordering cursor.' ); }
			$where .= ' AND (item_order > %d OR (item_order = %d AND id > %d))';
			$args = array_merge( $args, array( $cursor['order'], $cursor['order'], $cursor['id'] ) );
		}
		$args[] = $limit + ( $terminal ? 1 : 0 );
		$rows = $this->read( $this->db->prepare( 'SELECT id, item_order, HEX(node_hash) AS node_hash FROM `' . $this->table . '` '
			. 'WHERE blog_id = %d AND state_id = %d AND generation = %d AND parent_node_hash IS NULL' . $where . ' ORDER BY item_order ASC, id ASC LIMIT %d', $args ) );
		if ( count( $rows ) > $limit + ( $terminal ? 1 : 0 ) ) { $this->fail( 'Page row budget exceeded.' ); }
		$more = count( $rows ) > $limit;
		if ( $more ) { array_pop( $rows ); }
		$result = array(); $next = $cursor; $last_order = null === $cursor ? -1 : $cursor['order'];
		$record_count = 0; $payload_bytes = 0; $encoded_bytes = 0;
		foreach ( $rows as $row ) {
			$id = $this->number( $row['id'] ); $order = $this->number( $row['item_order'] );
			if ( $id < 1 || $order <= $last_order ) { $this->fail( 'Duplicate or invalid root ordering.' ); }
			$tree = $this->tree_hash( $state_key, $head, $this->hex( $row['node_hash'] ) );
			if ( false === $tree || $tree['rows'][0]['id'] !== (string) $id && $tree['rows'][0]['id'] !== $id || $tree['order'] !== $order ) { $this->fail( 'Root changed during page read.' ); }
			$result[] = array( 'key' => $tree['key'], 'value' => $tree['value'] );
			$record_count = $this->add( $record_count, count( $tree['rows'] ) ); $payload_bytes = $this->add( $payload_bytes, $tree['payload_bytes'] );
			$encoded_bytes = $this->add( $encoded_bytes, $tree['encoded_bytes'] );
			$next = array_merge( $identity, array( 'order' => $order, 'id' => $id ) ); $last_order = $order;
		}
		$this->unchanged( $state_key, $head );
		$page = array( 'rows' => $result, 'cursor' => $terminal && ! $more ? null : $next, 'revision' => $head['revision'] );
		if ( $export ) { $page['record_count'] = $record_count; $page['payload_bytes'] = $payload_bytes; $page['encoded_bytes'] = $encoded_bytes; }
		return $page;
	}

	/** Exact history map keys, via the approved legacy_identifier index. */
	public function identifiers( $state_key, $keys ) { return $this->read_identifiers( $state_key, $keys, false ); }

	/** Presence includes stored false/null; only sized root metadata is fetched. */
	public function identifier_presence( $state_key, $keys ) { return $this->read_identifiers( $state_key, $keys, true ); }

	private function read_identifiers( $state_key, $keys, $presence ) {
		$this->start_budget(); $this->typed_key( $state_key );
		$this->consume( 0, strlen( $this->codec->key_value( $state_key ) ) );
		if ( 'migrated' !== $state_key || ! is_array( $keys ) || count( $keys ) > 100 ) { $this->fail( 'Need at most 100 migrated identifiers.' ); }
		$seen = array();
		foreach ( $keys as $key ) {
			$this->typed_key( $key ); $hash = $this->codec->key_hash( $key );
			if ( isset( $seen[$hash] ) ) { $this->fail( 'Duplicate identifier.' ); } $seen[$hash] = true;
			$this->consume( 0, strlen( $this->codec->key_value( $key ) ) );
		}
		if ( ! count( $keys ) ) { return array(); }
		$head = $this->head( $state_key ); $this->collection( $head ); $result = array();
		foreach ( $keys as $key ) {
			$rows = $this->read( $this->db->prepare( 'SELECT HEX(node_hash) AS node_hash FROM `' . $this->table . '` WHERE blog_id = %d AND state_id = %d AND generation = %d '
				. 'AND legacy_identifier_hash = UNHEX(%s) AND parent_node_hash IS NULL LIMIT 2', array( $this->blog, $head['id'], $head['active_generation'], $this->codec->key_hash( $key ) ) ) );
			if ( count( $rows ) > 1 ) { $this->fail( 'Duplicate history identifier.' ); }
			if ( $presence ) {
				$value = $this->codec->key_value( $key ); $node = $this->codec->node_hash( $this->codec->key_hash( $state_key ), null, $key );
				$sql = 'SELECT CASE WHEN OCTET_LENGTH(legacy_identifier_value) = %d THEN legacy_identifier_value ELSE NULL END AS identifier, HEX(legacy_identifier_hash) AS identifier_hash, '
					. 'CASE WHEN OCTET_LENGTH(key_value) = %d THEN key_value ELSE NULL END AS key_value, key_type FROM `' . $this->table . '` WHERE blog_id = %d AND state_id = %d AND generation = %d AND node_hash = UNHEX(%s) AND parent_node_hash IS NULL LIMIT 2';
				$raw_key = base64_encode( (string) $key );
				$meta = $this->read( $this->db->prepare( $sql, array( strlen( $value ), strlen( $raw_key ), $this->blog, $head['id'], $head['active_generation'], $node ) ) );
				$this->consume( count( $meta ), strlen( $value ) + strlen( $raw_key ) );
				if ( count( $meta ) !== count( $rows ) || ( count( $meta ) && ( $this->hex( $rows[0]['node_hash'] ) !== $node || $meta[0]['identifier'] !== $value ||
					$this->hex( $meta[0]['identifier_hash'] ) !== $this->codec->key_hash( $key ) || $meta[0]['key_value'] !== $raw_key || $meta[0]['key_type'] !== ( is_int( $key ) ? 'integer' : 'string' ) ) ) ) { $this->fail( 'Identifier metadata collision or corruption.' ); }
				$result[] = array( 'found' => count( $meta ) === 1, 'key' => $key ); continue;
			}
			$tree = count( $rows ) ? $this->tree_hash( $state_key, $head, $this->hex( $rows[0]['node_hash'] ) ) : false;
			if ( count( $rows ) && ( false === $tree || $tree['key'] !== $key ) ) { $this->fail( 'Identifier collision or corruption.' ); }
			// A missing projection must not silently hide an existing exact map key.
			if ( ! count( $rows ) && false !== $this->tree( $state_key, $head, $key ) ) { $this->fail( 'Missing identifier projection.' ); }
			$result[] = array( 'found' => false !== $tree, 'key' => $key, 'value' => false === $tree ? null : $tree['value'] );
		}
		$this->unchanged( $state_key, $head ); return $result;
	}

	/** First eligible root in legacy insertion order, exact or image absint semantics. */
	public function source_identities( $state_key, $identities, $numeric = false ) {
		return $this->read_source_identities( $state_key, $identities, $numeric, true );
	}

	/** Option history's exact identity policy requires a positive target, not
	 * the independently stored migrated flag. Preserve physical API defaults.
	 */
	public function history_source_identity( $type, $plugin, $id ) {
		if ( ! is_scalar( $id ) || '' === (string) $id ) { $this->fail( 'Ambiguous empty/null source ID is not admitted.' ); }
		$rows = $this->read_source_identities( 'migrated', array( array( 'type' => $type, 'plugin' => $plugin, 'id' => $id ) ), false, false );
		return $rows[0];
	}

	private function read_source_identities( $state_key, $identities, $numeric, $require_migrated ) {
		$this->start_budget(); $this->typed_key( $state_key );
		$this->consume( 0, strlen( $this->codec->key_value( $state_key ) ) );
		if ( ! is_array( $identities ) || count( $identities ) > 100 || ! is_bool( $numeric ) ) { $this->fail( 'Need at most 100 source identities.' ); }
		$prepared = array();
		foreach ( $identities as $identity ) {
			if ( ! is_array( $identity ) || array_keys( $identity ) !== array( 'type', 'plugin', 'id' ) || ! is_string( $identity['type'] ) || strlen( $identity['type'] ) > 32 ||
				! is_string( $identity['plugin'] ) || ( ! is_scalar( $identity['id'] ) && null !== $identity['id'] ) || ( $numeric && 'image' !== $identity['type'] ) ) { $this->fail( 'Invalid source identity.' ); }
			$id = $numeric ? absint( $identity['id'] ) : (string) $identity['id'];
			if ( $numeric && ( ! is_int( $id ) || $id < 1 ) ) { $this->fail( 'Positive numeric image source required.' ); }
			$identity['id'] = $id; $prepared[] = $identity;
			$this->consume( 0, strlen( $this->codec->encode( $identity ) ) );
		}
		if ( ! count( $prepared ) ) { return array(); }
		$head = $this->head( $state_key ); $this->collection( $head ); $result = array();
		foreach ( $prepared as $identity ) {
			$hash = $this->codec->snapshot_digest( array( $numeric ? 'source-image-numeric-v1' : 'source-exact-v1', $identity['type'], $identity['plugin'], $identity['id'] ) );
			$column = $numeric ? 'source_numeric_identity_hash' : 'source_identity_hash';
			$rows = array();
			// Exact-source index places is_migrated before insertion order. Two
			// fixed-flag LIMIT 1 selectors avoid sorting all duplicate identities.
			foreach ( $require_migrated ? array( 1 ) : array( 0, 1 ) as $flag ) {
				$selected = $this->read( $this->db->prepare( 'SELECT id, item_order, HEX(node_hash) AS node_hash FROM `' . $this->table . '`'
					. ( $require_migrated ? '' : ' FORCE INDEX (`source_identity`)' ) . ' WHERE blog_id = %d AND state_id = %d AND generation = %d '
					. 'AND ' . $column . ' = UNHEX(%s) AND is_migrated = %d AND migrated_id > 0 AND parent_node_hash IS NULL ORDER BY item_order ASC, id ASC LIMIT 1',
					array( $this->blog, $head['id'], $head['active_generation'], $hash, $flag ) ) );
				if ( count( $selected ) > 1 ) { $this->fail( 'Source lookup exceeded row limit.' ); }
				if ( count( $selected ) ) {
					$selected[0]['id'] = $this->number( $selected[0]['id'] ); $selected[0]['item_order'] = $this->number( $selected[0]['item_order'] );
					if ( ! count( $rows ) || $selected[0]['item_order'] < $rows[0]['item_order'] || ( $selected[0]['item_order'] === $rows[0]['item_order'] && $selected[0]['id'] < $rows[0]['id'] ) ) { $rows = $selected; }
				}
			}
			$tree = count( $rows ) ? $this->tree_hash( $state_key, $head, $this->hex( $rows[0]['node_hash'] ) ) : false;
			if ( count( $rows ) ) {
				$v = false === $tree ? null : $tree['value'];
				if ( ! is_array( $v ) || ! isset( $v['object_type'], $v['plugin_name'] ) || ! array_key_exists( 'ID', $v ) || ! is_scalar( $v['ID'] ) ||
					$v['object_type'] !== $identity['type'] || $v['plugin_name'] !== $identity['plugin'] ||
					( $numeric ? absint( $v['ID'] ) : (string) $v['ID'] ) !== $identity['id'] || ( $require_migrated && empty( $v['migrated'] ) ) || (int) $v['migrated_id'] < 1 ) { $this->fail( 'Source hash collision or projection mismatch.' ); }
			}
			$result[] = array( 'found' => false !== $tree, 'key' => false === $tree ? null : $tree['key'], 'value' => false === $tree ? null : $tree['value'] );
		}
		$this->unchanged( $state_key, $head ); return $result;
	}

	/** SQL-only root projections; canonical consistency is established by verified writes/maintenance. */
	public function aggregate( $state_key, $filters = array() ) {
		$this->start_budget(); $this->typed_key( $state_key ); $filter = $this->filters( $filters );
		$head = $this->head( $state_key ); $this->collection( $head );
		$rows = $this->read( $this->db->prepare( 'SELECT COUNT(*) AS total, COALESCE(SUM(is_migrated),0) AS migrated, COALESCE(SUM(is_selected),0) AS selected, COALESCE(SUM(has_error),0) AS errors '
			. 'FROM `' . $this->table . '` WHERE blog_id = %d AND state_id = %d AND generation = %d AND parent_node_hash IS NULL' . $filter['sql'],
			array_merge( array( $this->blog, $head['id'], $head['active_generation'] ), $filter['args'] ) ) );
		if ( count( $rows ) !== 1 ) { $this->fail( 'Invalid aggregate result.' ); }
		foreach ( $rows[0] as $field => $value ) { $rows[0][$field] = $this->number( $value ); }
		foreach ( array( 'migrated', 'selected', 'errors' ) as $field ) { if ( $rows[0][$field] > $rows[0]['total'] ) { $this->fail( 'Invalid aggregate projections.' ); } }
		$this->unchanged( $state_key, $head ); return $rows[0];
	}

	/** Bounded distinct projection groups, ordered by first legacy root occurrence.
	 * Raw physical counts, NOT hydration/default-aware public log semantics.
	 */
	public function projection_summary( $state_key ) {
		$this->start_budget(); $this->typed_key( $state_key );
		$head = $this->head( $state_key ); $this->collection( $head ); $result = array();
		foreach ( array( 'object_type', 'migration_status' ) as $column ) {
			$sql = 'SELECT `' . $column . '` AS value, COUNT(*) AS count, MIN(item_order) AS first_order FROM `' . $this->table . '` WHERE blog_id = %d AND state_id = %d AND generation = %d AND parent_node_hash IS NULL AND object_type <> %s GROUP BY `' . $column . '` ORDER BY first_order ASC LIMIT 101';
			$rows = $this->read( $this->db->prepare( $sql, array( $this->blog, $head['id'], $head['active_generation'], '' ) ) );
			if ( count( $rows ) > 100 ) { $this->fail( 'Projection summary exceeds distinct-group budget.' ); }
			$this->consume( count( $rows ), count( $rows ) * 32 ); $counts = array();
			foreach ( $rows as $row ) {
				if ( ! is_string( $row['value'] ) || strlen( $row['value'] ) > 32 || array_key_exists( $row['value'], $counts ) ) { $this->fail( 'Invalid summary projection group.' ); }
				$counts[$row['value']] = $this->number( $row['count'] );
			}
			$result[$column] = $counts;
		}
		$this->unchanged( $state_key, $head ); return $result;
	}

	private function filters( $filters ) {
		if ( ! is_array( $filters ) ) { $this->fail( 'Invalid filters.' ); }
		ksort( $filters, SORT_STRING ); $sql = ''; $args = array();
		foreach ( $filters as $field => $value ) {
			if ( in_array( $field, array( 'object_type', 'migration_status' ), true ) ) {
				if ( ! is_string( $value ) || strlen( $value ) > 32 ) { $this->fail( 'Invalid indexed type/status filter.' ); } $sql .= ' AND ' . $field . ' = %s';
			} else if ( in_array( $field, array( 'is_selected', 'is_migrated', 'has_error' ), true ) ) {
				if ( ! is_int( $value ) || $value < 0 || $value > 1 ) { $this->fail( 'Invalid flag filter.' ); } $sql .= ' AND ' . $field . ' = %d';
			} else { $this->fail( 'Unsupported filter.' ); }
			$args[] = $value;
		}
		return array( 'sql' => $sql, 'args' => $args, 'digest' => $this->codec->snapshot_digest( $filters ) );
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
	public function patch_many( $changes ) { return $this->patch( $changes, false ); }

	/** List updates/appends only; deletion/reindexing requires bounded staged replacement. */
	public function patch_lists_and_scalars( $changes ) { return $this->patch( $changes, true ); }

	private function patch( $changes, $lists, $guards = array() ) {
		$this->start_budget(); $prepared = $this->preflight( $changes );
		if ( $lists ) {
			foreach ( $prepared as $change ) {
				if ( array_key_exists( 'value', $change ) ) { continue; }
				foreach ( $change['patches'] as $patch ) {
					if ( ! is_int( $patch['key'] ) || $patch['key'] < 0 || ! array_key_exists( 'value', $patch ) ) { $this->fail( 'Lists admit nonnegative index updates/appends only.' ); }
				}
			}
		}
		( new LegacyStorageSchema( $this->db ) )->verify();
		$mode = $this->read( 'SELECT @@SESSION.autocommit AS autocommit' );
		if ( count( $mode ) !== 1 || $this->number( $mode[0]['autocommit'] ) !== 1 ) { $this->fail( 'An idle autocommit connection is required.' ); }
		$this->write( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' );
		$this->busy = true;
		try { $this->write( 'START TRANSACTION' ); }
		catch ( \Exception $error ) { $this->busy = false; $this->tainted = true; $this->fail( 'Transaction start uncertain; reconcile on a fresh connection.' ); }
		try {
			ksort( $prepared, SORT_STRING ); $heads = array(); $locks = $prepared; $expected = array();
			foreach ( $guards as $entry ) {
				$hash = $this->codec->key_hash( $entry['key'] ); $expected[$hash] = $entry['head'];
				if ( ! isset( $locks[$hash] ) ) { $locks[$hash] = array( 'key' => $entry['key'], 'expected_revision' => $entry['head']['revision'] ); }
			}
			ksort( $locks, SORT_STRING );
			// Acquire ALL locks before the first consistent head read establishes a
			// REPEATABLE READ snapshot. A later lock must not validate against an
			// earlier snapshot (including unchanged read-only companions).
			$locked_ids = array();
			foreach ( $locks as $hash => $change ) {
				$locked_ids[$hash] = $this->lock_identity( $change['key'] );
			}
			foreach ( $locks as $hash => $change ) {
				$heads[$hash] = $this->head( $change['key'] );
				if ( $heads[$hash]['id'] !== $locked_ids[$hash] || $heads[$hash]['revision'] !== $change['expected_revision'] || $heads[$hash]['revision'] >= PHP_INT_MAX ) { $this->fail( 'Stale or exhausted revision.' ); }
				if ( isset( $expected[$hash] ) && $heads[$hash] !== $expected[$hash] ) { $this->fail( 'History mutation read dependency changed.' ); }
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
					if ( ( $lists ? 'list' : 'map' ) !== $head['state_kind'] ) { $this->fail( 'Patch collection kind mismatch.' ); }
					$root = $this->collection( $head );
					$envelope = new LegacyCollectionEnvelope();
					foreach ( $change['patches'] as $patch ) {
						if ( array_key_exists( 'value', $patch ) ) {
							// Account for the logical wrapper's extra depth BEFORE deleting
							// any old rows. Hash only this affected item, never the history.
							$admission = hash_init( 'sha256' );
							$envelope->digest_item( $admission, $root, $patch['key'], $patch['value'] ); hash_final( $admission );
						}
						$old = $this->tree( $change['key'], $head, $patch['key'] );
						$order = false === $old ? $this->append_order( $head ) : $old['order'];
						if ( $lists && ( $patch['key'] > $root['root_count'] || $order !== $patch['key'] ||
							( false === $old ) !== ( $patch['key'] === $root['root_count'] ) ) ) { $this->fail( 'Sparse or corrupt list; replacement required.' ); }
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
						if ( 'migrated' === $change['key'] ) { $this->consume( 0, strlen( $this->codec->key_value( $patch['key'] ) ) ); }
						$this->consume( $stats['record_count'], $stats['encoded_bytes'] );
					}
				}
			}
			$result[$hash] = $change;
		}
		return $result;
	}

	private function lock_identity( $key ) {
		$value = $this->codec->key_value( $key );
		$rows = $this->read( $this->db->prepare( 'SELECT id, CASE WHEN OCTET_LENGTH(state_key_value) <= %d THEN state_key_value ELSE NULL END AS state_key_value '
			. 'FROM `' . $this->state . '` WHERE blog_id = %d AND state_key_hash = UNHEX(%s) LIMIT 2 FOR UPDATE', array( strlen( $value ), $this->blog, $this->codec->key_hash( $key ) ) ) );
		if ( count( $rows ) !== 1 || $rows[0]['state_key_value'] !== $value ) { $this->fail( 'Missing/colliding locked head.' ); }
		return $this->number( $rows[0]['id'] );
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
		( new LegacyCollectionEnvelope() )->validate( $root, $this->codec->decode( $head['state_key_value'] ) );
		if ( $root['root_count'] > $head['record_count'] ) { $this->fail( 'Invalid collection metadata.' ); }
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
		if ( 'migrated' === $state_key && ( $root['legacy_identifier_value'] !== $this->codec->key_value( $key ) ||
			$this->hex( $root['legacy_identifier_hash'] ) !== $this->codec->key_hash( $key ) ) ) { $this->fail( 'History identifier projection mismatch.' ); }
		return array( 'key' => $key, 'value' => $value, 'rows' => $rows, 'order' => $this->number( $root['item_order'] ), 'payload_bytes' => $payload, 'encoded_bytes' => $total );
	}

	private function append_order( $head ) {
		$rows = $this->read( $this->db->prepare( 'SELECT item_order FROM `' . $this->table . '` WHERE blog_id = %d AND state_id = %d AND generation = %d AND parent_node_hash IS NULL ORDER BY item_order DESC, id DESC LIMIT 1',
			array( $this->blog, $head['id'], $head['active_generation'] ) ) );
		return count( $rows ) ? $this->add( $this->number( $rows[0]['item_order'] ), 1 ) : 0;
	}

	private function insert_record( $head, $row ) {
		if ( ! $this->busy || $this->tainted ) { $this->fail( 'Record writes require an owned transaction.' ); }
		if ( $head['state_key_value'] === $this->codec->key_value( 'migrated' ) && null === $row['parent_node_hash'] ) {
			$key = 'integer' === $row['key_type'] ? (int) base64_decode( $row['key_value'], true ) : base64_decode( $row['key_value'], true );
			$row['legacy_identifier_value'] = $this->codec->key_value( $key ); $row['legacy_identifier_hash'] = $this->codec->key_hash( $key );
		}
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
		if ( $this->history_read_active ) { $this->tick(); return; }
		if ( $this->busy || $this->tainted ) { $this->fail( 'Busy or uncertain repository; reconcile on a fresh connection.' ); }
		$this->deadline = microtime( true ) + $this->max_seconds; $this->used_rows = 0; $this->used_bytes = 0; $this->byte_limit = $this->max_bytes;
	}
	private function tick() { if ( microtime( true ) > $this->deadline ) { $this->fail( 'Operation time budget exceeded.' ); } }
	private function consume( $rows, $bytes ) {
		$this->tick();
		if ( $rows > $this->max_rows - $this->used_rows || $bytes > $this->byte_limit - $this->used_bytes ) { $this->fail( 'Operation row/encoded byte budget exceeded.' ); }
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
