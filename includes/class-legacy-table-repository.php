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

	/** Evaluate the result while still guarded, then release on every return. */
	private function finish_history_read( $result ) {
		$this->history_read_active = false; return $result;
	}

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
		if ( $this->history_read_active || ! is_array( $request ) || ! in_array( $operation, array( 'identifiers', 'presence', 'children', 'source', 'page', 'export', 'count', 'revision', 'log_summary', 'summary' ), true ) ) { $this->fail( 'Unsupported history read.' ); }
		if ( in_array( $operation, array( 'log_summary', 'summary' ), true ) && count( $request ) ) { $this->fail( 'Malformed logical summary request.' ); }
		$this->start_budget(); $this->history_read_active = true;
		try {
			if ( $this->history_pair_absent() ) {
				$result = $this->empty_history_read( $operation, $request );
				if ( ! $this->history_pair_absent() ) { $this->fail( 'Empty history changed during bounded read.' ); }
				return $this->finish_history_read( $result );
			}
			$keys = array( 'migrated', 'migrated-revision' ); $context = $this->metadata_many( $keys );
			if ( 'map' !== $context[0]['head']['state_kind'] || ! isset( $context[0]['root']['wrapper'] ) ) { $this->fail( 'Indexed history wrapper required; old header-root layouts are not admitted.' ); }
			switch ( $operation ) {
				case 'identifiers': $result = $this->identifiers( 'migrated', $request ); break;
				case 'presence': $result = $this->identifier_presence( 'migrated', $request ); break;
				case 'source':
					if ( array_keys( $request ) !== array( 'type', 'plugin', 'id' ) ) { $this->fail( 'Malformed source request.' ); }
					$result = $this->history_source_identity( $request['type'], $request['plugin'], $request['id'] ); break;
				case 'children':
					if ( array_keys( $request ) !== array( 'parent', 'keys' ) || ! is_array( $request['keys'] ) || count( $request['keys'] ) > $this->max_rows ) { $this->fail( 'Malformed child request.' ); }
					$result = $this->history_children( $request['parent'], $request['keys'] );
					break;
				case 'page':
				case 'export':
					if ( array_keys( $request ) !== array( 'limit', 'filters', 'cursor' ) ) { $this->fail( 'Malformed page request.' ); }
					if ( 'export' === $operation && count( $request['filters'] ) ) { $this->fail( 'Raw exports do not filter records.' ); }
					$result = 'export' === $operation ? $this->read_page( 'migrated', $request['limit'], $request['cursor'], array(), false, true, true )
						: $this->history_page( 'migrated', $request['limit'], $request['filters'], $request['cursor'] ); break;
				case 'count': $result = $this->count_roots( 'migrated' ); break;
				case 'log_summary': $result = $this->history_log_summary( $context[0] ); break;
				case 'summary': $result = $this->history_type_summary( $context[0] ); break;
				default: $result = null;
			}
			if ( $this->metadata_many( $keys ) !== $context ) { $this->fail( 'History changed during bounded read.' ); }
			return $this->finish_history_read( array( 'context' => $context, 'result' => $result ) );
		} catch ( \Exception $error ) { $this->history_read_active = false; throw $error; }
		catch ( \Throwable $error ) { $this->history_read_active = false; throw $error; }
	}

	/** Missing history is distinct from a partial pair, shadow head or orphan row. */
	private function history_pair_absent() {
		$history = $this->reader->head( 'migrated' ); $revision = $this->reader->head( 'migrated-revision' );
		if ( false !== $history && false !== $revision ) { return false; }
		if ( false !== $history || false !== $revision ) { $this->fail( 'Partial history heads; no empty fallback.' ); }
		$shadow = $this->read( $this->db->prepare( 'SELECT id FROM `' . $this->state . '` WHERE blog_id = %d AND '
			. '(state_key_value = %s OR state_key_value = %s) LIMIT 1', array( $this->blog,
				$this->codec->key_value( 'migrated' ), $this->codec->key_value( 'migrated-revision' ) ) ) );
		if ( count( $shadow ) ) { $this->fail( 'History head identity is corrupt.' ); }
		$orphan = $this->read( $this->db->prepare( 'SELECT r.id FROM `' . $this->table . '` r LEFT JOIN `' . $this->state . '` s '
			. 'ON s.id = r.state_id AND s.blog_id = r.blog_id WHERE r.blog_id = %d AND s.id IS NULL LIMIT 1', array( $this->blog ) ) );
		if ( count( $orphan ) ) { $this->fail( 'Orphan table record; no empty fallback.' ); }
		return true;
	}

	/** Read-only logical defaults; the first write publishes real heads atomically. */
	private function empty_history_read( $operation, $request ) {
		$root = ( new LegacyCollectionEnvelope( $this->codec ) )->split( 'migrated', 'map', array() )['root'];
		$context = array(
			array( 'key' => 'migrated', 'head' => array( 'id' => 0, 'active_generation' => 0, 'revision' => 0,
				'state_kind' => 'map', 'virtual_absent' => true ), 'root' => $root ),
			array( 'key' => 'migrated-revision', 'head' => array( 'id' => 0, 'active_generation' => 0, 'revision' => 0,
				'state_kind' => 'scalar', 'virtual_absent' => true ),
				'root' => array( 'version' => 1, 'kind' => 'scalar', 'value' => 0 ) ) );
		$result = null;
		if ( 'identifiers' === $operation || 'presence' === $operation ) {
			if ( count( $request ) > 100 ) { $this->fail( 'Invalid empty-history identifiers.' ); }
			$this->consume( 0, strlen( $this->codec->key_value( 'migrated' ) ) );
			$seen = array(); $result = array();
			foreach ( $request as $key ) {
				$this->typed_key( $key ); $hash = $this->codec->key_hash( $key );
				if ( isset( $seen[$hash] ) ) { $this->fail( 'Duplicate history identifier.' ); } $seen[$hash] = true;
				$this->consume( 0, strlen( $this->codec->key_value( $key ) ) );
				$row = array( 'found' => false, 'key' => $key );
				if ( 'identifiers' === $operation ) { $row['value'] = null; }
				$result[] = $row;
			}
		} elseif ( 'children' === $operation ) {
			if ( array_keys( $request ) !== array( 'parent', 'keys' ) || ! is_array( $request['keys'] ) || count( $request['keys'] ) > $this->max_rows ) { $this->fail( 'Malformed empty-history children request.' ); }
			$this->typed_key( $request['parent'] ); $this->consume( 0, strlen( $this->codec->key_value( $request['parent'] ) ) );
			foreach ( array_chunk( $request['keys'], 100 ) as $batch ) {
				$this->consume( 0, strlen( $this->codec->key_value( 'migrated' ) ) ); $seen = array();
				foreach ( $batch as $key ) {
					$this->typed_key( $key ); $hash = $this->codec->key_hash( $key );
					if ( isset( $seen[$hash] ) ) { $this->fail( 'Duplicate history child identifier.' ); } $seen[$hash] = true;
					$this->consume( 0, strlen( $this->codec->key_value( $key ) ) );
				}
			}
			$result = array();
		} elseif ( 'source' === $operation ) {
			if ( array_keys( $request ) !== array( 'type', 'plugin', 'id' ) || ! is_string( $request['type'] ) || '' === $request['type'] ||
				strlen( $request['type'] ) > 32 || ( null !== $request['plugin'] && ! is_string( $request['plugin'] ) ) || ! is_scalar( $request['id'] ) ) { $this->fail( 'Malformed empty-history source request.' ); }
			$this->consume( 0, strlen( $this->codec->key_value( 'migrated' ) ) );
			$identity = null === $request['plugin'] || '' === (string) $request['id']
				? array( $request['type'], $request['plugin'], $request['id'] )
				: array( 'type' => $request['type'], 'plugin' => $request['plugin'], 'id' => (string) $request['id'] );
			$this->consume( 0, strlen( $this->codec->encode( $identity ) ) );
			$result = array( 'found' => false, 'key' => null, 'value' => null );
		} elseif ( 'page' === $operation || 'export' === $operation ) {
			if ( array_keys( $request ) !== array( 'limit', 'filters', 'cursor' ) || ! is_array( $request['filters'] ) || ! is_int( $request['limit'] ) || $request['limit'] < 1 ||
				$request['limit'] > 100 || null !== $request['cursor'] || ( 'export' === $operation && count( $request['filters'] ) ) ) { $this->fail( 'Malformed empty-history page.' ); }
			$this->byte_limit = min( $this->max_bytes, 4194304 );
			$this->consume( 0, strlen( $this->codec->key_value( 'migrated' ) ) );
			$this->filters( $request['filters'] );
			$result = array( 'rows' => array(), 'cursor' => null, 'revision' => 0 );
		} elseif ( 'count' === $operation ) { $result = 0; }
		elseif ( 'log_summary' === $operation ) { $result = array( 'total' => 0, 'count' => 0, 'by_type' => array(), 'types' => array(),
			'by_status' => array(), 'statuses' => array(), 'completed' => 0, 'error' => 0 ); }
		elseif ( 'summary' === $operation ) { $result = array(); }
		return array( 'context' => $context, 'result' => $result );
	}

	/** The first logical history write creates history, revision and companions
	 * together with absent-head guards. A failed transaction leaves no half-pair.
	 */
	private function empty_history_mutate( $operation, $request, $companions ) {
		if ( 'status' === $operation || 'delete' === $operation ) { return false; }
		if ( 'retry' === $operation || 'clear' === $operation ) { return true; }
		$order = $this->next_state_order(); $changes = array(); $guards = array(
			array( 'key' => 'migrated', 'head' => false ), array( 'key' => 'migrated-revision', 'head' => false ) );
		if ( count( $request ) ) {
			$items = array();
			foreach ( $request as $record ) {
				$payload = $record['value'];
				$parent = null !== $record['parent'] ? $record['parent'] : $record['fallback_parent'];
				if ( null !== $parent ) { $payload['migration_parent_key'] = $parent; }
				$items[$record['key']] = $payload;
			}
			$changes[] = array( 'key' => 'migrated', 'order' => $order++, 'kind' => 'map',
				'value' => array( MigratorSettings::COMPACT_MARKER => MigratorSettings::COMPACT_VERSION,
					'type' => 'migratable', 'items' => $items ), 'expected_revision' => null );
			$changes[] = array( 'key' => 'migrated-revision', 'order' => $order++, 'kind' => 'scalar',
				'value' => 1, 'expected_revision' => null );
		}
		foreach ( $companions as $key => $value ) {
			$head = $this->reader->head( $key ); $guards[] = array( 'key' => $key, 'head' => $head );
			if ( false !== $head ) {
				$entry = $this->metadata_many( array( $key ) )[0];
				if ( 'scalar' !== $entry['head']['state_kind'] ) { $this->fail( 'Existing scalar companion head required.' ); }
				if ( serialize( $entry['root']['value'] ) === serialize( $value ) ) { continue; }
				$changes[] = array( 'key' => $key, 'order' => $head['state_order'], 'kind' => 'scalar',
					'value' => $value, 'expected_revision' => $head['revision'] );
			} else {
				$changes[] = array( 'key' => $key, 'order' => $order++, 'kind' => 'scalar',
					'value' => $value, 'expected_revision' => null );
			}
		}
		if ( count( $changes ) ) { $this->replace_bounded( $changes, $guards ); }
		return count( $request );
	}

	/** Closed logical mutations over an admitted indexed history wrapper.
	 * One budget spans metadata, old/new affected roots and atomic publication.
	 * All read dependencies are locked/CAS-checked, even unchanged companions.
	 */
	public function history_mutate( $operation, $request, $companions = array() ) {
		if ( $this->history_read_active || ! in_array( $operation, array( 'upsert', 'status', 'delete', 'retry', 'clear' ), true ) || ! is_array( $request ) ||
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
				if ( ! count( $request ) && ! count( $companions ) ) { return $this->finish_history_read( 0 ); }
			} elseif ( 'clear' === $operation ) {
				if ( count( $request ) ) { $this->fail( 'Malformed history clear.' ); }
			} else {
				if ( array_keys( $request ) !== ( 'status' === $operation ? array( 'key', 'status' ) : array( 'key' ) ) ) { $this->fail( 'Malformed history mutation key.' ); }
				$this->typed_key( $request['key'] ); $identifiers[] = $request['key'];
				if ( 'status' === $operation && ( ! is_string( $request['status'] ) || strlen( $request['status'] ) > 32 ) ) { $this->fail( 'Unindexed status is not admitted.' ); }
			}
			if ( $this->history_pair_absent() ) { return $this->finish_history_read( $this->empty_history_mutate( $operation, $request, $companions ) ); }
			$context = $this->metadata_many( $keys ); $history = $context[0]; $revision = $context[1];
			if ( 'map' !== $history['head']['state_kind'] || ! isset( $history['root']['wrapper'] ) ) { $this->fail( 'Indexed history wrapper required.' ); }
			if ( 'scalar' !== $revision['head']['state_kind'] || ! is_int( $revision['root']['value'] ) || $revision['root']['value'] < 0 ) { $this->fail( 'Invalid logical history revision.' ); }
			foreach ( array_slice( $context, 2 ) as $entry ) { if ( 'scalar' !== $entry['head']['state_kind'] ) { $this->fail( 'Existing scalar companion heads required.' ); } }
			// Explicit clear is an atomic empty-generation publication. Old rows
			// remain byte-identical for the separate guarded maintenance owner;
			// neither decode nor delete an arbitrarily large previous generation.
			if ( 'clear' === $operation ) {
				if ( $this->metadata_many( $keys ) !== $context ) { $this->fail( 'History changed before clear.' ); }
				if ( 0 === $history['root']['root_count'] ) { return $this->finish_history_read( true ); }
				if ( $revision['root']['value'] >= PHP_INT_MAX ) { $this->fail( 'Logical history revision exhausted.' ); }
				$changes = array(); $empty_root = $history['root']; $empty_root['root_count'] = 0;
				foreach ( $context as $entry ) {
					$value = 'migrated' === $entry['key'] ? ( new LegacyCollectionEnvelope() )->rebuild( $empty_root, array() ) : $revision['root']['value'] + 1;
					$changes[] = array( 'key' => $entry['key'], 'order' => $entry['head']['state_order'], 'kind' => $entry['head']['state_kind'], 'value' => $value, 'expected_revision' => $entry['head']['revision'] );
				}
				if ( $this->max_rows <= $this->used_rows || $this->byte_limit <= $this->used_bytes ) { $this->fail( 'Clear publication budget exhausted.' ); }
				$this->tick();
				( new LegacyTableSnapshot( $this->db, $this->blog, $this->max_rows - $this->used_rows, $this->byte_limit - $this->used_bytes, $this->deadline ) )->replace_many( $changes );
				return $this->finish_history_read( true );
			}
			// Retry uses the exact parent key plus the indexed ERROR domain only.
			// Parent association has no v1 index; all error candidates share ONE
			// cumulative row/byte/time budget. Refuse overflow, never partial retry.
			if ( 'retry' === $operation ) {
				$parent = $this->identifiers( 'migrated', array( $request['key'] ) )[0];
				$selected = array( $parent['key'] => $parent ); $cursor = null;
				do {
					$page = $this->history_page( 'migrated', 100, array( 'migration_status' => 'error' ), $cursor );
					foreach ( $page['rows'] as $row ) {
						$value = $row['value'];
						if ( $row['key'] === $request['key'] || ( is_array( $value ) && isset( $value['migration_parent_key'], $value['migration_status'] ) &&
							$request['key'] === $value['migration_parent_key'] && 'error' === $value['migration_status'] ) ) {
							$selected[$row['key']] = array( 'found' => true, 'key' => $row['key'], 'value' => $value );
						}
					}
					$cursor = $page['cursor'];
				} while ( null !== $cursor );
				$old = array_values( $selected );
			} else { $old = $this->identifiers( 'migrated', array_values( $identifiers ) ); }
			$working = array();
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
			} elseif ( in_array( $operation, array( 'retry', 'clear' ), true ) ) {
				foreach ( $old as $row ) { $working[$row['key']]['found'] = false; }
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
			return $this->finish_history_read( $result );
		} catch ( \Exception $error ) { $this->history_read_active = false; throw $error; }
		catch ( \Throwable $error ) { $this->history_read_active = false; throw $error; }
	}

	/** Existing scalar/checkpoint heads, one aggregate budget and atomic CAS. */
	public function mutate_scalars( $values ) {
		if ( $this->history_read_active || ! is_array( $values ) || count( $values ) > 100 ) { $this->fail( 'Invalid bounded scalar changes.' ); }
		if ( ! count( $values ) ) { return true; }
		$this->start_budget(); $this->history_read_active = true;
		try {
			foreach ( $values as $key => $value ) {
				$this->typed_key( $key );
				if ( in_array( $key, array( 'migrated', 'migrated-revision', 'plugins', 'galleries', 'albums' ), true ) ) { $this->fail( 'Scalar facade cannot replace reserved collections or history revision.' ); }
				$encoded = $this->codec->encode( array( 'version' => 1, 'kind' => 'scalar', 'value' => $value ) );
				if ( strlen( $encoded ) > LegacyTableReader::MAX_RECORD_BYTES ) { $this->fail( 'Scalar row byte budget exceeded.' ); }
				$this->consume( 0, strlen( $encoded ) );
			}
			$context = array(); $missing = false; $changes = array(); $guards = array();
			foreach ( $values as $key => $value ) {
				$head = $this->reader->head( $key ); $this->tick();
				$guards[] = array( 'key' => $key, 'head' => $head );
				if ( false === $head ) { $missing = true; $context[] = array( 'key' => $key, 'head' => false ); }
				else { $context[] = $this->metadata_many( array( $key ) )[0]; }
			}
			$order = $missing ? $this->next_state_order() : 0; $replacements = array();
			foreach ( $context as $entry ) {
				if ( false === $entry['head'] ) {
					$replacements[] = array( 'key' => $entry['key'], 'kind' => 'scalar', 'order' => $order++, 'value' => $values[$entry['key']], 'expected_revision' => null ); continue;
				}
				if ( 'scalar' !== $entry['head']['state_kind'] ) { $this->fail( 'Existing scalar heads required; no collection fallback.' ); }
				$value = $values[$entry['key']];
				if ( serialize( $value ) !== serialize( $entry['root']['value'] ) ) {
					$changes[] = array( 'key' => $entry['key'], 'expected_revision' => $entry['head']['revision'], 'value' => $value );
					$replacements[] = array( 'key' => $entry['key'], 'kind' => 'scalar', 'order' => $entry['head']['state_order'], 'value' => $value, 'expected_revision' => $entry['head']['revision'] );
				}
			}
			foreach ( $guards as $guard ) { if ( $this->reader->head( $guard['key'] ) !== $guard['head'] ) { $this->fail( 'Scalar read dependency changed.' ); } $this->tick(); }
			if ( $missing ) { $this->replace_bounded( $replacements, $guards ); }
			else if ( count( $changes ) ) { $this->patch( $changes, false, $context ); }
			return $this->finish_history_read( true );
		} catch ( \Exception $error ) { $this->history_read_active = false; throw $error; }
		catch ( \Throwable $error ) { $this->history_read_active = false; throw $error; }
	}

	private function next_state_order() {
		$rows = $this->read( $this->db->prepare( 'SELECT MAX(state_order) AS last_order FROM `' . $this->state . '` WHERE blog_id = %d', array( $this->blog ) ) );
		if ( count( $rows ) !== 1 ) { $this->fail( 'Cannot read state order.' ); }
		$order = null === $rows[0]['last_order'] ? 0 : $this->number( $rows[0]['last_order'] ) + 1;
		if ( ! is_int( $order ) || $order >= PHP_INT_MAX ) { $this->fail( 'State order exhausted.' ); } return $order;
	}

	private function replace_bounded( $changes, $guards ) {
		$this->tick();
		if ( $this->max_rows <= $this->used_rows || $this->byte_limit <= $this->used_bytes ) { $this->fail( 'Replacement publication budget exhausted.' ); }
		( new LegacyTableSnapshot( $this->db, $this->blog, $this->max_rows - $this->used_rows, $this->byte_limit - $this->used_bytes, $this->deadline ) )->replace_many( $changes, $guards );
	}

	/** Delete only a verified scalar; all retained generations must be empty. */
	public function delete_scalar( $key ) {
		$this->typed_key( $key );
		if ( in_array( $key, array( 'migrated', 'migrated-revision', 'plugins', 'galleries', 'albums' ), true ) ) { $this->fail( 'Cannot delete reserved collection/history through scalar facade.' ); }
		if ( $this->history_read_active ) { $this->fail( 'Reentrant scalar deletion.' ); }
		$this->start_budget();
		$row = $this->read_scalar( $key ); if ( ! $row['found'] ) { return true; }
		$head = $this->head( $key );
		( new LegacyStorageSchema( $this->db ) )->verify();
		$mode = $this->read( 'SELECT @@SESSION.autocommit AS autocommit' );
		if ( count( $mode ) !== 1 || $this->number( $mode[0]['autocommit'] ) !== 1 ) { $this->fail( 'Idle autocommit connection required.' ); }
		$this->write( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' ); $this->busy = true;
		try { $this->write( 'START TRANSACTION' ); }
		catch ( \Exception $error ) { $this->busy = false; $this->tainted = true; $this->fail( 'Transaction start uncertain.' ); }
		try {
			$this->lock_identity( $key );
			if ( $this->head( $key ) !== $head ) { $this->fail( 'Scalar deletion dependency changed.' ); }
			$root = $this->root_metadata( $head ); $this->verify_scalar( $head, $root['value'] );
			$rows = $this->read( $this->db->prepare( 'SELECT id FROM `' . $this->table . '` WHERE blog_id = %d AND state_id = %d LIMIT 1', array( $this->blog, $head['id'] ) ) );
			if ( count( $rows ) ) { $this->fail( 'Scalar has retained collection records; explicit recovery required.' ); }
			if ( 1 !== $this->write( $this->db->prepare( 'DELETE FROM `' . $this->state . '` WHERE blog_id = %d AND id = %d AND revision = %d AND active_generation = %d', array( $this->blog, $head['id'], $head['revision'], $head['active_generation'] ) ) ) ) { $this->fail( 'Scalar delete CAS failed.' ); }
			if ( false !== $this->reader->head( $key ) ) { $this->fail( 'Scalar deletion readback failed.' ); } $this->tick();
		} catch ( \Exception $error ) {
			$ok = $this->db->query( 'ROLLBACK' ); $this->busy = false;
			if ( false === $ok || ! empty( $this->db->last_error ) ) { $this->tainted = true; $this->fail( 'Rollback uncertain.' ); } throw $error;
		}
		try { $this->write( 'COMMIT' ); }
		catch ( \Exception $error ) { $this->busy = false; $this->tainted = true; $this->db->query( 'ROLLBACK' ); $this->fail( 'Commit outcome uncertain; reconcile, never replay.' ); }
		$this->busy = false; return true;
	}

	/** Independently indexed findings and bounded checkpoint, on the existing head. */
	private function content_context() {
		$key = ContentScanRepository::STATE_KEY; $head = $this->reader->head( $key ); $this->tick();
		if ( false === $head ) {
			foreach ( array( 'block-shortcode', 'block-shortcode_scan_progress' ) as $legacy ) {
				if ( false !== $this->reader->head( $legacy ) ) { $this->fail( 'Legacy content state requires explicit reset or conversion before bounded access.' ); }
			}
			$split = ( new LegacyCollectionEnvelope() )->split( $key, 'map', array( 'items' => array(), 'progress' => array() ) );
			return array( 'key' => $key, 'head' => false, 'root' => $split['root'] );
		}
		if ( 'map' !== $head['state_kind'] ) { $this->fail( 'Legacy scalar content state requires explicit reset or conversion before bounded access.' ); }
		$root = $this->collection( $head );
		if ( ! isset( $root['wrapper']['content'] ) ) { $this->fail( 'Indexed content envelope required.' ); }
		return array( 'key' => $key, 'head' => $head, 'root' => $root );
	}

	/** SQL offset selects only small root selectors; only requested trees decode. */
	public function content_window( $offset, $limit, $keys = null, $plugin = null ) {
		if ( ! is_int( $offset ) || $offset < 0 || ! is_int( $limit ) || $limit < 1 || $limit > 100 ||
			( null !== $keys && ( ! is_array( $keys ) || count( $keys ) > 100 ) ) ||
			( null !== $plugin && ( null !== $keys || ! is_string( $plugin ) || '' === $plugin ) ) || $this->history_read_active ) { $this->fail( 'Invalid content window.' ); }
		if ( null !== $keys ) { foreach ( $keys as $key ) { $this->typed_key( $key ); } }
		$this->start_budget(); $this->history_read_active = true;
		try {
			$entry = $this->content_context(); $items = array(); $head = $entry['head'];
			if ( false !== $head ) {
				if ( null === $keys ) {
					$filter = '';
					$args = array( $this->blog, $head['id'], $head['active_generation'] );
					if ( null !== $plugin ) {
						$encoded = $this->codec->encode( $plugin ); $this->consume( 0, strlen( $encoded ) );
						$filter = ' source_plugin_hash = UNHEX(%s) AND'; $args[] = $this->codec->key_hash( $plugin );
					}
					$args[] = $limit; $args[] = $offset;
					$rows = $this->read( $this->db->prepare( 'SELECT HEX(node_hash) AS node_hash FROM `' . $this->table . '` '
						. ( null === $plugin ? '' : 'FORCE INDEX (`source_plugin`) ' )
						. 'WHERE blog_id = %d AND state_id = %d AND generation = %d AND' . $filter . ' parent_node_hash IS NULL '
						. 'ORDER BY item_order ASC, id ASC LIMIT %d OFFSET %d', $args ) );
					if ( count( $rows ) > $limit ) { $this->fail( 'Content selector page exceeds limit.' ); }
					foreach ( $rows as $row ) {
						$tree = $this->tree_hash( $entry['key'], $head, $this->hex( $row['node_hash'] ) );
						if ( false === $tree || ( null !== $plugin && ( ! is_array( $tree['value'] ) || ! isset( $tree['value']['plugin_name'] ) || $tree['value']['plugin_name'] !== $plugin ) ) ) {
							$this->fail( 'Content tree or source-plugin projection changed.' );
						}
						if ( array_key_exists( $tree['key'], $items ) ) { $this->fail( 'Duplicate content selector in page.' ); }
						$items[$tree['key']] = $tree['value'];
					}
				} else {
					$selected = array();
					foreach ( $keys as $key ) { $tree = $this->tree( $entry['key'], $head, $key ); if ( false !== $tree ) { $selected[] = $tree; } }
					usort( $selected, function ( $a, $b ) { return $a['order'] === $b['order'] ? 0 : ( $a['order'] < $b['order'] ? -1 : 1 ); } );
					foreach ( $selected as $tree ) { $items[$tree['key']] = $tree['value']; }
				}
			}
			if ( $this->reader->head( $entry['key'] ) !== $head ) { $this->fail( 'Content changed during window read.' ); }
			return $this->finish_history_read( array( 'items' => $items, 'progress' => $entry['root']['wrapper']['values']['progress'], 'total' => $entry['root']['root_count'], 'revision' => false === $head ? null : $head['revision'] ) );
		} catch ( \Exception $error ) { $this->history_read_active = false; throw $error; }
		catch ( \Throwable $error ) { $this->history_read_active = false; throw $error; }
	}

	/** One budget for all retained selected findings; every tree admits metadata before payload. */
	public function content_selected( $keys ) {
		if ( $this->history_read_active || ! is_array( $keys ) || count( $keys ) > $this->max_rows ) { $this->fail( 'Invalid selected content keys.' ); }
		$this->start_budget(); $this->history_read_active = true;
		try {
			$seen = array();
			foreach ( $keys as $key ) {
				$this->typed_key( $key ); $hash = $this->codec->key_hash( $key );
				if ( isset( $seen[$hash] ) ) { $this->fail( 'Duplicate selected content key.' ); }
				$seen[$hash] = true; $this->consume( 0, strlen( $this->codec->key_value( $key ) ) );
			}
			$entry = $this->content_context(); $head = $entry['head']; $items = array();
			if ( false !== $head ) {
				foreach ( $keys as $key ) {
					$tree = $this->tree( $entry['key'], $head, $key );
					if ( false !== $tree ) { $items[$tree['key']] = $tree['value']; }
				}
			}
			if ( $this->reader->head( $entry['key'] ) !== $head ) { $this->fail( 'Content changed during selected read.' ); }
			return $this->finish_history_read( array( 'items' => $items, 'progress' => $entry['root']['wrapper']['values']['progress'],
				'total' => $entry['root']['root_count'], 'revision' => false === $head ? null : $head['revision'] ) );
		} catch ( \Exception $error ) { $this->history_read_active = false; throw $error; }
		catch ( \Throwable $error ) { $this->history_read_active = false; throw $error; }
	}

	/** Count indexed content findings for one exact source plugin, not every tree. */
	public function content_count_plugin( $plugin ) {
		if ( ! is_string( $plugin ) || '' === $plugin || $this->history_read_active ) { $this->fail( 'Invalid content plugin count.' ); }
		$this->start_budget(); $this->history_read_active = true;
		try {
			$encoded = $this->codec->encode( $plugin );
			$this->consume( 0, strlen( $encoded ) );
			$entry = $this->content_context(); $head = $entry['head'];
			if ( false === $head ) {
				if ( false !== $this->reader->head( ContentScanRepository::STATE_KEY ) ) { $this->fail( 'Content changed during plugin count.' ); }
				return $this->finish_history_read( 0 );
			}
			$hash = $this->codec->key_hash( $plugin );
			$args = array( $this->blog, $head['id'], $head['active_generation'], $hash );
			$totals = $this->read( $this->db->prepare( 'SELECT COUNT(*) AS total FROM `' . $this->table . '` FORCE INDEX (`source_plugin`) '
				. 'WHERE blog_id = %d AND state_id = %d AND generation = %d AND source_plugin_hash = UNHEX(%s) AND parent_node_hash IS NULL', $args ) );
			if ( count( $totals ) !== 1 ) { $this->fail( 'Invalid content plugin count.' ); }
			$expected = $this->number( $totals[0]['total'] );
			if ( $expected > $this->max_rows || $expected > $entry['root']['root_count'] ) { $this->fail( 'Content plugin count exceeds root budget.' ); }
			$last_order = 0; $last_id = 0; $count = 0;
			do {
				$rows = $this->read( $this->db->prepare( 'SELECT id, item_order, OCTET_LENGTH(source_plugin_value) AS plugin_bytes '
					. 'FROM `' . $this->table . '` FORCE INDEX (`source_plugin`) WHERE blog_id = %d AND state_id = %d AND generation = %d '
					. 'AND source_plugin_hash = UNHEX(%s) AND parent_node_hash IS NULL '
					. 'AND (item_order > %d OR (item_order = %d AND id > %d)) ORDER BY item_order ASC, id ASC LIMIT 100',
					array_merge( $args, array( $last_order, $last_order, $last_id ) ) ) );
				if ( count( $rows ) > 100 || count( $rows ) > $expected - $count ) { $this->fail( 'Content plugin index changed during count.' ); }
				$page_bytes = 0; $metadata = array(); $clauses = array();
				$raw_args = array( $this->blog, $head['id'], $head['active_generation'], $hash );
				foreach ( $rows as $row ) {
					$id = $this->number( $row['id'] ); $order = $this->number( $row['item_order'] );
					$bytes = $this->number( $row['plugin_bytes'] );
					if ( $id < 1 || ( $order < $last_order || ( $order === $last_order && $id <= $last_id ) ) ||
						$bytes < 1 || $bytes > LegacyTableReader::MAX_RECORD_BYTES || $bytes > 4194304 - $page_bytes ) {
						$this->fail( 'Invalid content plugin projection size or order.' );
					}
					$this->consume( 1, $bytes ); $page_bytes += $bytes;
					$metadata[] = array( 'id' => $id, 'order' => $order, 'bytes' => $bytes );
					$clauses[] = '(id = %d AND OCTET_LENGTH(source_plugin_value) = %d)';
					$raw_args[] = $id; $raw_args[] = $bytes;
					$last_order = $order; $last_id = $id;
				}
				if ( count( $metadata ) ) {
					$raw = $this->read( $this->db->prepare( 'SELECT id, item_order, HEX(source_plugin_hash) AS plugin_hash, source_plugin_value '
						. 'FROM `' . $this->table . '` WHERE blog_id = %d AND state_id = %d AND generation = %d '
						. 'AND source_plugin_hash = UNHEX(%s) AND parent_node_hash IS NULL AND (' . implode( ' OR ', $clauses ) . ') '
						. 'ORDER BY item_order ASC, id ASC LIMIT 100', $raw_args ) );
					if ( count( $raw ) !== count( $metadata ) ) { $this->fail( 'Content plugin projection changed during bounded read.' ); }
					foreach ( $metadata as $index => $expected_row ) {
						$value = $raw[$index];
						if ( $this->number( $value['id'] ) !== $expected_row['id'] || $this->number( $value['item_order'] ) !== $expected_row['order'] ||
							$this->hex( $value['plugin_hash'] ) !== $hash || ! is_string( $value['source_plugin_value'] ) ||
							strlen( $value['source_plugin_value'] ) !== $expected_row['bytes'] || $value['source_plugin_value'] !== $encoded ||
							$this->codec->decode( $value['source_plugin_value'] ) !== $plugin ) {
							$this->fail( 'Content plugin hash collision or raw projection mismatch.' );
						}
					}
					$count += count( $metadata );
				}
			} while ( count( $rows ) === 100 );
			if ( $count !== $expected ) { $this->fail( 'Content plugin index changed during count.' ); }
			$this->unchanged( ContentScanRepository::STATE_KEY, $head );
			return $this->finish_history_read( $count );
		} catch ( \Exception $error ) { $this->history_read_active = false; throw $error; }
		catch ( \Throwable $error ) { $this->history_read_active = false; throw $error; }
	}

	/** A replacement is an explicit operation; never import/repair during reads. */
	/** Preserve missing in the full compatibility facade; not used by UI paging. */
	public function read_content_state() {
		$head = $this->reader->head( ContentScanRepository::STATE_KEY );
		// Logical false is readable without interpreting any old scalar array as
		// a bounded collection. content_context() intentionally still refuses it.
		if ( false !== $head && 'scalar' === $head['state_kind'] ) {
			$this->start_budget(); $root = $this->root_metadata( $head );
			if ( array_keys( $root ) !== array( 'version', 'kind', 'value' ) || false !== $root['value'] || 0 !== $head['record_count'] ) {
				$this->fail( 'Legacy scalar content state requires explicit reset or conversion before access.' );
			}
			$this->verify_scalar( $head, false );
			$this->unchanged( ContentScanRepository::STATE_KEY, $head );
			return array( 'found' => true, 'value' => false );
		}
		return array( 'found' => false !== $head, 'value' => false === $head ? null : $this->content_scan_state() );
	}

	public function replace_content_state( $value, $expected_revision = null ) {
		$this->start_budget(); $key = ContentScanRepository::STATE_KEY;
		( new LegacyCollectionEnvelope() )->split( $key, 'map', $value );
		$head = $this->reader->head( $key );
		if ( null !== $expected_revision && ( false === $head || $head['revision'] !== $expected_revision ) ) { $this->fail( 'Stale content snapshot.' ); }
		$this->replace_bounded( array( array( 'key' => $key, 'kind' => 'map', 'order' => false === $head ? $this->next_state_order() : $head['state_order'], 'value' => $value, 'expected_revision' => false === $head ? null : $head['revision'] ) ), array( array( 'key' => $key, 'head' => $head ) ) );
		return true;
	}

	/** One affected batch and checkpoint CAS; occurrence retries retain selectors. */
	public function content_batch( $items, $progress, $append, $expected_revision = null ) {
		if ( ! is_array( $items ) || count( $items ) > $this->max_rows || ! is_array( $progress ) || ! is_bool( $append ) || $this->history_read_active ) { $this->fail( 'Invalid content batch.' ); }
		$this->start_budget(); $this->history_read_active = true;
		try {
			$entry = $this->content_context(); $head = $entry['head'];
			if ( null !== $expected_revision && ( false === $head || $expected_revision !== $head['revision'] ) ) { $this->fail( 'Stale content checkpoint.' ); }
			if ( false === $head ) {
				$value = array( 'items' => $items, 'progress' => $progress );
				$this->replace_content_state( $value ); return $this->finish_history_read( true );
			}
			$patches = array(); $seen = array(); $next = $entry['root']['wrapper']['next_key'];
			foreach ( $items as $key => $item ) {
				if ( $append ) {
					$occurrence = ContentScanRepository::occurrence_key( $item );
					if ( isset( $seen[$occurrence] ) ) { continue; } $seen[$occurrence] = true;
					$rows = $this->read( $this->db->prepare( 'SELECT HEX(node_hash) AS node_hash FROM `' . $this->table . '` WHERE blog_id = %d AND state_id = %d AND generation = %d AND parent_node_hash IS NULL AND legacy_identifier_hash = UNHEX(%s) ORDER BY item_order ASC, id ASC LIMIT 1',
						array( $this->blog, $head['id'], $head['active_generation'], $this->codec->key_hash( $occurrence ) ) ) );
					if ( count( $rows ) ) {
						$tree = $this->tree_hash( $entry['key'], $head, $this->hex( $rows[0]['node_hash'] ) );
						if ( false === $tree || ContentScanRepository::occurrence_key( $tree['value'] ) !== $occurrence ) { $this->fail( 'Occurrence index collision.' ); } continue;
					}
					if ( $next >= PHP_INT_MAX ) { $this->fail( 'Content selector exhausted.' ); } $key = $next++;
				} else { $this->typed_key( $key ); if ( false === $this->tree( $entry['key'], $head, $key ) ) { $this->fail( 'Missing selected finding.' ); } }
				$patches[] = array( 'key' => $key, 'value' => $item );
			}
			$change = array( 'key' => $entry['key'], 'expected_revision' => $head['revision'], 'patches' => $patches, 'content_progress' => $progress );
			$this->patch( array( $change ), false, array( array( 'key' => $entry['key'], 'head' => $head ) ) );
			return $this->finish_history_read( true );
		} catch ( \Exception $error ) { $this->history_read_active = false; throw $error; }
		catch ( \Throwable $error ) { $this->history_read_active = false; throw $error; }
	}

	/** Full snapshot compatibility remains bounded, never used by the table UI.
	 * These established states remain scalars; only this bounded bucket is read.
	 */
	public function content_scan_state() {
		if ( $this->history_read_active ) { $this->fail( 'Reentrant content scan read.' ); }
		$this->start_budget(); $this->history_read_active = true;
		try {
			if ( false !== $this->reader->head( ContentScanRepository::STATE_KEY ) && 'map' === $this->reader->head( ContentScanRepository::STATE_KEY )['state_kind'] ) {
				$entry = $this->metadata_many( array( ContentScanRepository::STATE_KEY ) )[0];
				return $this->finish_history_read( $this->collection_value( ContentScanRepository::STATE_KEY, $entry ) );
			}
			$context = $this->partial_state_context( ContentScanRepository::STATE_KEY, true );
			$this->verify_partial_dependencies( $context['guards'] ); return $this->finish_history_read( $context['value'] );
		} catch ( \Exception $error ) { $this->history_read_active = false; throw $error; }
		catch ( \Throwable $error ) { $this->history_read_active = false; throw $error; }
	}
	public function change_content_scan( $change ) {
		if ( ! is_callable( $change ) ) { $this->fail( 'Invalid content mutation.' ); }
		$head = $this->reader->head( ContentScanRepository::STATE_KEY );
		$value = call_user_func( $change, $this->content_scan_state() );
		return false === $value ? false : $this->replace_content_state( $value, false === $head ? null : $head['revision'] );
	}
	public function change_image_tag_queue( $change ) { return $this->change_partial_state( ImageTagQueueRepository::STATE_KEY, $change, false ); }

	private function partial_state_context( $key, $content ) {
		$guards = array(); $head = $this->reader->head( $key ); $this->tick();
		$guards[] = array( 'key' => $key, 'head' => $head );
		$row = $this->read_scalar( $key );
		$value = $row['found'] ? $row['value'] : array();
		if ( $content && ! $row['found'] ) {
			$legacy = array();
			foreach ( array( 'block-shortcode', 'block-shortcode_scan_progress' ) as $legacy_key ) {
				$guards[] = array( 'key' => $legacy_key, 'head' => $this->reader->head( $legacy_key ) );
				$legacy[$legacy_key] = $this->read_scalar( $legacy_key );
			}
			$value = array( 'items' => is_array( $legacy['block-shortcode']['value'] ) ? $legacy['block-shortcode']['value'] : array(), 'progress' => is_array( $legacy['block-shortcode_scan_progress']['value'] ) ? $legacy['block-shortcode_scan_progress']['value'] : array() );
		}
		if ( $content && ( ! is_array( $value ) || ! isset( $value['items'], $value['progress'] ) || ! is_array( $value['items'] ) || ! is_array( $value['progress'] ) ) ) { $this->fail( 'Invalid content scan state. No legacy fallback.' ); }
		return array( 'head' => $head, 'value' => $value, 'guards' => $guards );
	}

	private function verify_partial_dependencies( $guards ) {
		foreach ( $guards as $guard ) { if ( $this->reader->head( $guard['key'] ) !== $guard['head'] ) { $this->fail( 'Partial state dependency changed.' ); } $this->tick(); }
	}

	private function change_partial_state( $key, $change, $content ) {
		if ( $this->history_read_active || ! is_callable( $change ) ) { $this->fail( 'Invalid partial state mutation.' ); }
		$this->start_budget(); $this->history_read_active = true;
		try {
			$context = $this->partial_state_context( $key, $content );
			if ( ! is_array( $context['value'] ) ) { return $this->finish_history_read( false ); }
			$value = call_user_func( $change, $context['value'] ); $this->tick();
			if ( false === $value ) { return $this->finish_history_read( false ); }
			if ( ! is_array( $value ) ) { $this->fail( 'Invalid partial state result.' ); }
			$this->verify_partial_dependencies( $context['guards'] );
			if ( false !== $context['head'] && serialize( $value ) === serialize( $context['value'] ) ) { return $this->finish_history_read( true ); }
			if ( false === $context['head'] ) {
				$this->replace_bounded( array( array( 'key' => $key, 'kind' => 'scalar', 'order' => $this->next_state_order(), 'value' => $value, 'expected_revision' => null ) ), $context['guards'] );
			} else {
				$this->patch( array( array( 'key' => $key, 'value' => $value, 'expected_revision' => $context['head']['revision'] ) ), false, $context['guards'] );
			}
			return $this->finish_history_read( true );
		} catch ( \Exception $error ) { $this->history_read_active = false; throw $error; }
		catch ( \Throwable $error ) { $this->history_read_active = false; throw $error; }
	}

	/** Physical missing versus null/false, with one aggregate read budget. */
	public function read_scalars( $keys ) {
		if ( $this->history_read_active || ! is_array( $keys ) || count( $keys ) > 100 ) { $this->fail( 'Invalid scalar read keys.' ); }
		$this->start_budget(); $this->history_read_active = true;
		try {
			$seen = array(); $result = array(); $heads = array();
			foreach ( $keys as $key ) {
				$this->typed_key( $key ); $hash = $this->codec->key_hash( $key );
				if ( isset( $seen[$hash] ) ) { $this->fail( 'Duplicate scalar read key.' ); } $seen[$hash] = true;
				$this->consume( 1, strlen( $this->codec->key_value( $key ) ) );
				$heads[] = $this->reader->head( $key ); $this->tick();
				$row = $this->read_scalar( $key ); $row['key'] = $key;
				$result[] = array( 'found' => $row['found'], 'key' => $key, 'value' => $row['value'] );
			}
			foreach ( $keys as $index => $key ) { if ( $this->reader->head( $key ) !== $heads[$index] ) { $this->fail( 'Scalar read changed.' ); } $this->tick(); }
			return $this->finish_history_read( $result );
		} catch ( \Exception $error ) { $this->history_read_active = false; throw $error; }
		catch ( \Throwable $error ) { $this->history_read_active = false; throw $error; }
	}

	/** Existing gallery/album collections only; never a whole-history facade. */
	private function collection_key( $key ) {
		if ( ! in_array( $key, array( 'plugins', 'galleries', 'albums' ), true ) ) { $this->fail( 'Unsupported collection dispatch.' ); }
	}

	/** Complete bounded collection compatibility; no whole-state/history hydration. */
	public function collection_snapshot( $key ) {
		$row = $this->read_collection( $key ); return $row['found'] ? $row['value'] : array();
	}

	/** Preserve absence for existing settings callers' lazy discovery defaults. */
	public function read_collection( $key ) {
		$this->collection_key( $key );
		if ( $this->history_read_active ) { $this->fail( 'Reentrant collection read.' ); }
		$this->start_budget(); $this->history_read_active = true;
		try {
			if ( false === $this->reader->head( $key ) ) {
				$rows = $this->read( $this->db->prepare( 'SELECT id FROM `' . $this->state . '` WHERE blog_id = %d LIMIT 1', array( $this->blog ) ) );
				if ( ! count( $rows ) ) { $this->fail( 'Missing site state scope; no option fallback.' ); }
				if ( false !== $this->reader->head( $key ) ) { $this->fail( 'Missing collection changed.' ); } return $this->finish_history_read( array( 'found' => false, 'value' => null ) );
			}
			$entry = $this->metadata_many( array( $key ) )[0];
			return $this->finish_history_read( array( 'found' => true, 'value' => $this->collection_value( $key, $entry ) ) );
		} catch ( \Exception $error ) { $this->history_read_active = false; throw $error; }
		catch ( \Throwable $error ) { $this->history_read_active = false; throw $error; }
	}

	private function collection_value( $key, $entry ) {
		if ( ! in_array( $entry['head']['state_kind'], array( 'map', 'list' ), true ) || ! isset( $entry['root']['wrapper'] ) ) { $this->fail( 'Indexed collection envelope required.' ); }
		$items = array(); $cursor = null; $rows = 0; $payload = $entry['head']['root_payload_bytes'];
		do {
			$page = $this->export_page( $key, 100, $cursor );
			if ( $page['revision'] !== $entry['head']['revision'] ) { $this->fail( 'Collection changed during snapshot.' ); }
			foreach ( $page['rows'] as $row ) {
				if ( array_key_exists( $row['key'], $items ) || ( 'list' === $entry['head']['state_kind'] && $row['key'] !== count( $items ) ) ) { $this->fail( 'Invalid collection ordering.' ); }
				$items[$row['key']] = $row['value'];
			}
			$rows += $page['record_count']; $payload += $page['payload_bytes']; $cursor = $page['cursor'];
		} while ( count( $page['rows'] ) );
		if ( count( $items ) !== $entry['root']['root_count'] || $rows !== $entry['head']['record_count'] || $payload !== $entry['head']['payload_bytes'] ) { $this->fail( 'Collection snapshot statistics mismatch.' ); }
		$this->unchanged( $key, $entry['head'] );
		return ( new LegacyCollectionEnvelope() )->rebuild( $entry['root'], $items );
	}

	/** Existing caller replacement: affected-root patches when order is stable,
	 * otherwise bounded staged replacement with the same atomic checkpoint.
	 */
	public function replace_collection( $key, $value, $companions = array() ) {
		$this->collection_key( $key );
		if ( $this->history_read_active || ! is_array( $companions ) || count( $companions ) > 1 || ( count( $companions ) && array_keys( $companions ) !== array( $key . '-state' ) ) ) { $this->fail( 'Invalid collection checkpoint.' ); }
		if ( 'plugins' === $key && count( $companions ) ) { $this->fail( 'Plugin detection has no collection checkpoint.' ); }
		$this->start_budget(); $this->history_read_active = true;
		try {
			$context = array(); $guards = array(); $stage = false;
			foreach ( array_merge( array( $key ), array_keys( $companions ) ) as $state_key ) {
				$head = $this->reader->head( $state_key ); $this->tick(); $guards[] = array( 'key' => $state_key, 'head' => $head );
				if ( false === $head ) { $stage = true; $context[] = array( 'key' => $state_key, 'head' => false ); }
				else { $context[] = $this->metadata_many( array( $state_key ) )[0]; }
			}
			$entry = $context[0]; $kind = false === $entry['head'] ? 'map' : $entry['head']['state_kind'];
			$envelope = new LegacyCollectionEnvelope(); $new = $envelope->split( $key, $kind, $value );
			$old_value = false === $entry['head'] ? null : $this->collection_value( $key, $entry );
			$old = false === $entry['head'] ? array( 'root' => $new['root'], 'items' => array() ) : $envelope->split( $key, $kind, $old_value );
			$before = $old['root']; $after = $new['root']; unset( $before['root_count'], $after['root_count'] );
			if ( serialize( $before ) !== serialize( $after ) ) { $stage = true; }
			$expected_keys = array();
			foreach ( $old['items'] as $item_key => $item ) { if ( array_key_exists( $item_key, $new['items'] ) ) { $expected_keys[] = $item_key; } }
			foreach ( $new['items'] as $item_key => $item ) { if ( ! array_key_exists( $item_key, $old['items'] ) ) { $expected_keys[] = $item_key; } }
			if ( $expected_keys !== array_keys( $new['items'] ) || ( 'list' === $kind && count( $new['items'] ) < count( $old['items'] ) ) ) { $stage = true; }
			$patches = array();
			foreach ( $old['items'] as $item_key => $item ) { if ( ! array_key_exists( $item_key, $new['items'] ) ) { $patches[] = array( 'key' => $item_key, 'delete' => true ); } }
			foreach ( $new['items'] as $item_key => $item ) { if ( ! array_key_exists( $item_key, $old['items'] ) || serialize( $item ) !== serialize( $old['items'][$item_key] ) ) { $patches[] = array( 'key' => $item_key, 'value' => $item ); } }
			$collection_changed = false === $entry['head'] || serialize( $old_value ) !== serialize( $value );
			$changes = ! $stage && count( $patches ) ? array( array( 'key' => $key, 'expected_revision' => $entry['head']['revision'], 'patches' => $patches ) ) : array();
			$order = $stage ? $this->next_state_order() : 0; $replacements = array();
			if ( $collection_changed ) { $replacements[] = array( 'key' => $key, 'kind' => $kind, 'order' => false === $entry['head'] ? $order++ : $entry['head']['state_order'], 'value' => $value, 'expected_revision' => false === $entry['head'] ? null : $entry['head']['revision'] ); }
			foreach ( array_slice( $context, 1 ) as $companion ) {
				if ( false !== $companion['head'] && 'scalar' !== $companion['head']['state_kind'] ) { $this->fail( 'Scalar collection checkpoint required.' ); }
				if ( false === $companion['head'] || serialize( $companion['root']['value'] ) !== serialize( $companions[$companion['key']] ) ) {
					if ( ! $stage ) { $changes[] = array( 'key' => $companion['key'], 'expected_revision' => $companion['head']['revision'], 'value' => $companions[$companion['key']] ); }
					$replacements[] = array( 'key' => $companion['key'], 'kind' => 'scalar', 'order' => false === $companion['head'] ? $order++ : $companion['head']['state_order'], 'value' => $companions[$companion['key']], 'expected_revision' => false === $companion['head'] ? null : $companion['head']['revision'] );
				}
			}
			$this->verify_partial_dependencies( $guards );
			if ( $stage && count( $replacements ) ) { $this->replace_bounded( $replacements, $guards ); }
			else if ( count( $changes ) ) { $this->patch( $changes, 'list' === $kind, $context ); }
			return $this->finish_history_read( true );
		} catch ( \Exception $error ) { $this->history_read_active = false; throw $error; }
		catch ( \Throwable $error ) { $this->history_read_active = false; throw $error; }
	}

	/** Exact containing-root update; unrelated roots are neither fetched nor written. */
	public function update_collection_record( $key, $locator, $value ) {
		$this->collection_key( $key );
		if ( $this->history_read_active || ! is_array( $locator ) || array_keys( $locator ) !== array( 'root', 'path' ) || ! is_array( $locator['path'] ) ) { $this->fail( 'Invalid nested collection locator.' ); }
		$this->typed_key( $locator['root'] ); foreach ( $locator['path'] as $child ) { $this->typed_key( $child ); }
		$this->start_budget(); $this->history_read_active = true;
		try {
			if ( false === $this->reader->head( $key ) ) { return $this->finish_history_read( false ); }
			$context = $this->metadata_many( array( $key ) ); $entry = $context[0];
			if ( ! isset( $entry['root']['wrapper'] ) ) { $this->fail( 'Indexed collection envelope required.' ); }
			$row = $this->roots( $key, array( $locator['root'] ) )[0]; if ( ! $row['found'] ) { return $this->finish_history_read( false ); }
			$ok = true; $replacement = $this->replace_collection_child( $row['value'], $locator['path'], $value, $ok );
			if ( ! $ok ) { return $this->finish_history_read( false ); }
			if ( $this->metadata_many( array( $key ) ) !== $context ) { $this->fail( 'Collection root changed.' ); }
			if ( $this->codec->encode( $replacement ) !== $this->codec->encode( $row['value'] ) ) { $this->patch( array( array( 'key' => $key, 'expected_revision' => $entry['head']['revision'], 'patches' => array( array( 'key' => $locator['root'], 'value' => $replacement ) ) ) ), 'list' === $entry['head']['state_kind'], $context ); }
			return $this->finish_history_read( true );
		} catch ( \Exception $error ) { $this->history_read_active = false; throw $error; }
		catch ( \Throwable $error ) { $this->history_read_active = false; throw $error; }
	}

	private function replace_collection_child( $node, $path, $value, &$ok ) {
		$this->tick(); if ( ! count( $path ) ) { return $value; }
		$key = array_shift( $path );
		if ( ! is_array( $node ) || ! isset( $node['children'] ) || ! is_array( $node['children'] ) || ! array_key_exists( $key, $node['children'] ) ) { $ok = false; return $node; }
		$node['children'][$key] = $this->replace_collection_child( $node['children'][$key], $path, $value, $ok ); return $node;
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

	/** Loaded-parent history lookup. Selector work is fixed per 100 requested keys,
	 * including proof that a missing identifier has no unprojected exact root.
	 * Only selected trees are hydrated; the complete history is never read.
	 */
	private function history_children( $parent, $keys ) {
		$this->typed_key( $parent ); $this->consume( 0, strlen( $this->codec->key_value( $parent ) ) );
		$this->consume( 0, strlen( $this->codec->key_value( 'migrated' ) ) );
		$seen = array(); $result = array();
		foreach ( $keys as $key ) {
			$this->typed_key( $key ); $hash = $this->codec->key_hash( $key );
			if ( isset( $seen[$hash] ) ) { $this->fail( 'Duplicate history child identifier.' ); }
			$seen[$hash] = true; $this->consume( 0, strlen( $this->codec->key_value( $key ) ) );
		}
		if ( ! count( $keys ) ) { return $result; }
		$head = $this->head( 'migrated' ); $this->collection( $head ); $state_hash = $this->codec->key_hash( 'migrated' );
		foreach ( array_chunk( $keys, 100 ) as $batch ) {
			$hashes = array(); $nodes = array(); $by_hash = array();
			foreach ( $batch as $key ) {
				$hash = $this->codec->key_hash( $key ); $node = $this->codec->node_hash( $state_hash, null, $key );
				$hashes[] = $hash; $nodes[$hash] = $node; $by_hash[$hash] = $key;
			}
			$slots = implode( ', ', array_fill( 0, count( $batch ), 'UNHEX(%s)' ) );
			$sql = 'SELECT HEX(node_hash) AS node_hash, HEX(legacy_identifier_hash) AS identifier_hash '
				. 'FROM `' . $this->table . '` WHERE blog_id = %d AND state_id = %d AND generation = %d '
				. 'AND parent_node_hash IS NULL AND legacy_identifier_hash IN (' . $slots . ') LIMIT 101';
			$rows = $this->read( $this->db->prepare( $sql, array_merge( array( $this->blog,
				$head['id'], $head['active_generation'] ), $hashes ) ) );
			if ( count( $rows ) > count( $batch ) ) { $this->fail( 'Duplicate history identifier.' ); }
			$found = array();
			foreach ( $rows as $row ) {
				$hash = $this->hex( $row['identifier_hash'] );
				if ( ! array_key_exists( $hash, $by_hash ) || isset( $found[$hash] ) ) { $this->fail( 'Identifier metadata collision or corruption.' ); }
				if ( $this->hex( $row['node_hash'] ) !== $nodes[$hash] ) {
					$this->fail( 'Identifier metadata collision or corruption.' );
				}
				$found[$hash] = true;
			}
			$missing = array();
			foreach ( $hashes as $hash ) { if ( ! isset( $found[$hash] ) ) { $missing[] = $nodes[$hash]; } }
			if ( count( $missing ) ) {
				$slots = implode( ', ', array_fill( 0, count( $missing ), 'UNHEX(%s)' ) );
				$unprojected = $this->read( $this->db->prepare( 'SELECT id FROM `' . $this->table . '` WHERE blog_id = %d AND state_id = %d AND generation = %d '
					. 'AND parent_node_hash IS NULL AND node_hash IN (' . $slots . ') LIMIT 1',
					array_merge( array( $this->blog, $head['id'], $head['active_generation'] ), $missing ) ) );
				if ( count( $unprojected ) ) { $this->fail( 'Missing identifier projection.' ); }
			}
			$trees = $this->history_child_trees( $head, $found, $nodes, $by_hash );
			foreach ( $batch as $key ) {
				$hash = $this->codec->key_hash( $key );
				if ( ! isset( $found[$hash] ) ) { continue; }
				$tree = $trees[$hash];
				if ( is_array( $tree['value'] ) && isset( $tree['value']['migration_parent_key'] ) &&
					$parent === $tree['value']['migration_parent_key'] ) { $result[] = array( 'found' => true, 'key' => $key, 'value' => $tree['value'] ); }
			}
		}
		$this->unchanged( 'migrated', $head ); return $result;
	}

	/** Batch flat, selected roots after aggregate metadata admission. Nested
	 * historical trees retain the existing bounded traversal and codec proof.
	 */
	private function history_child_trees( $head, $found, $nodes, $keys ) {
		if ( ! count( $found ) ) { return array(); }
		$scope = array( $this->blog, $head['id'], $head['active_generation'] );
		$record_bytes = $this->record_bytes();
		$select = 'SELECT id, blog_id, state_id, generation, item_order, HEX(node_hash) AS node_hash, '
			. 'HEX(parent_node_hash) AS parent_node_hash, payload_bytes, ' . $record_bytes . ' AS encoded_bytes '
			. 'FROM `' . $this->table . '` WHERE blog_id = %d AND state_id = %d AND generation = %d AND parent_node_hash IS NULL';
		$selected = array(); foreach ( $found as $hash => $unused ) { $selected[] = $nodes[$hash]; }
		$slots = implode( ', ', array_fill( 0, count( $selected ), 'UNHEX(%s)' ) );
		$meta = $this->read( $this->db->prepare( $select . ' AND node_hash IN (' . $slots . ') LIMIT 101', array_merge( $scope, $selected ) ) );
		if ( count( $meta ) !== count( $selected ) ) { $this->fail( 'Selected history roots changed or duplicated.' ); }
		$by_node = array(); $total = 0;
		foreach ( $meta as $row ) {
			$node = $this->hex( $row['node_hash'] ); $bytes = $this->number( $row['encoded_bytes'] );
			if ( ! in_array( $node, $selected, true ) || isset( $by_node[$node] ) || null !== $row['parent_node_hash'] ||
				$bytes > LegacyTableReader::MAX_RECORD_BYTES || $bytes > $this->max_bytes - $this->used_bytes - $total ) { $this->fail( 'Invalid selected history root metadata.' ); }
			foreach ( array( 'blog_id' => $this->blog, 'state_id' => $head['id'], 'generation' => $head['active_generation'] ) as $field => $expected ) {
				if ( $this->number( $row[$field] ) !== $expected ) { $this->fail( 'Selected history root scope changed.' ); }
			}
			$this->number( $row['id'] ); $this->number( $row['item_order'] ); $this->number( $row['payload_bytes'] );
			$total += $bytes; $by_node[$node] = $row;
		}
		if ( count( $meta ) > $this->max_rows - $this->used_rows ) { $this->fail( 'Selected history row budget exceeded.' ); }
		$parents = $this->read( $this->db->prepare( 'SELECT HEX(parent_node_hash) AS parent_hash FROM `' . $this->table . '` '
			. 'WHERE blog_id = %d AND state_id = %d AND generation = %d AND parent_node_hash IN (' . $slots . ') '
			. 'GROUP BY parent_node_hash LIMIT 101', array_merge( $scope, $selected ) ) );
		if ( count( $parents ) > count( $selected ) ) { $this->fail( 'Selected history child probe exceeded its bound.' ); }
		$nested = array();
		foreach ( $parents as $row ) {
			$node = $this->hex( $row['parent_hash'] );
			if ( ! isset( $by_node[$node] ) || isset( $nested[$node] ) ) { $this->fail( 'Selected history child projection changed.' ); }
			$nested[$node] = true;
		}
		$flat = array(); $flat_bytes = 0;
		foreach ( $selected as $node ) {
			if ( isset( $nested[$node] ) ) { continue; }
			$flat[] = $by_node[$node]; $flat_bytes += $this->number( $by_node[$node]['encoded_bytes'] );
		}
		$trees = array();
		if ( count( $flat ) ) {
			$this->consume( count( $flat ), $flat_bytes );
			$columns = array();
			foreach ( $this->definitions[$this->table]['columns'] as $name => $spec ) { $columns[] = 'binary(32)' === $spec[0] ? 'HEX(`' . $name . '`) AS `' . $name . '`' : '`' . $name . '`'; }
			$ids = array(); $cases = array(); $args = $scope;
			foreach ( $flat as $row ) { $ids[] = '%d'; $args[] = $this->number( $row['id'] ); }
			foreach ( $flat as $row ) { $cases[] = 'WHEN %d THEN %d';
				$args[] = $this->number( $row['id'] ); $args[] = $this->number( $row['encoded_bytes'] ); }
			$rows = $this->read( $this->db->prepare( 'SELECT ' . implode( ', ', $columns ) . ', ' . $record_bytes . ' AS encoded_bytes '
				. 'FROM `' . $this->table . '` WHERE blog_id = %d AND state_id = %d AND generation = %d AND parent_node_hash IS NULL '
				. 'AND id IN (' . implode( ', ', $ids ) . ') AND (' . $record_bytes . ') = CASE id '
				. implode( ' ', $cases ) . ' ELSE -1 END LIMIT 101', $args ) );
			if ( count( $rows ) !== count( $flat ) ) { $this->fail( 'Sized history roots changed or disappeared.' ); }
			foreach ( $rows as $row ) {
				$node = $this->hex( $row['node_hash'] );
				if ( ! isset( $by_node[$node] ) || isset( $nested[$node] ) ) { $this->fail( 'Selected history root collision.' ); }
				foreach ( $by_node[$node] as $field => $expected ) {
					$actual = $row[$field];
					if ( null !== $expected && substr( $field, -5 ) === '_hash' ) { $actual = $this->hex( $actual ); $expected = $this->hex( $expected ); }
					else if ( null !== $expected ) { $actual = $this->number( $actual ); $expected = $this->number( $expected ); }
					if ( $actual !== $expected ) { $this->fail( 'Selected history root metadata changed.' ); }
				}
				$value = $this->records->rebuild_tree( 'migrated', $node, array( $row ) );
				$key_bytes = base64_decode( $row['key_value'], true );
				$key = 'integer' === $row['key_type'] ? (int) $key_bytes : $key_bytes;
				$hash = $this->hex( $row['legacy_identifier_hash'] );
				if ( isset( $trees[$hash] ) || ! array_key_exists( $hash, $keys ) || $keys[$hash] !== $key ||
					$row['legacy_identifier_value'] !== $this->codec->key_value( $key ) || $hash !== $this->codec->key_hash( $key ) ) {
					$this->fail( 'History identifier projection mismatch.' );
				}
				$trees[$hash] = array( 'key' => $key, 'value' => $value );
			}
		}
		foreach ( $selected as $node ) {
			if ( ! isset( $nested[$node] ) ) { continue; }
			$tree = $this->tree_hash( 'migrated', $head, $node );
			if ( false === $tree ) { $this->fail( 'Nested history root disappeared.' ); }
			$hash = $this->codec->key_hash( $tree['key'] );
			if ( ! array_key_exists( $hash, $keys ) || $keys[$hash] !== $tree['key'] ) { $this->fail( 'Nested history identifier collision.' ); }
			$trees[$hash] = $tree;
		}
		if ( count( $trees ) !== count( $found ) ) { $this->fail( 'Selected history root count changed.' ); }
		return $trees;
	}

	/** First eligible root in legacy insertion order, exact or image absint semantics. */
	public function source_identities( $state_key, $identities, $numeric = false ) {
		return $this->read_source_identities( $state_key, $identities, $numeric, true );
	}

	/** Option history's exact identity policy requires a positive target, not
	 * the independently stored migrated flag. Preserve physical API defaults.
	 */
	public function history_source_identity( $type, $plugin, $id ) {
		if ( ! is_scalar( $id ) ) { $this->fail( 'Non-scalar source ID is not admitted.' ); }
		if ( null === $plugin || '' === (string) $id ) { return $this->history_filtered_source_identity( $type, $plugin, $id ); }
		$rows = $this->read_source_identities( 'migrated', array( array( 'type' => $type, 'plugin' => $plugin, 'id' => $id ) ), false, false );
		return $rows[0];
	}

	/** Schema v1 hashes null and empty scalar IDs alike and omits null-plugin
	 * hashes. Bound eligible index-bucket candidates before fetching trees, then
	 * apply the option's exact fields and scalar-ID predicate canonically.
	 * No change to physical lookup policy or persisted projections is needed.
	 */
	private function history_filtered_source_identity( $type, $plugin, $id ) {
		$this->start_budget();
		if ( ! is_string( $type ) || '' === $type || strlen( $type ) > 32 || ( null !== $plugin && ! is_string( $plugin ) ) || ! is_scalar( $id ) || ( null !== $plugin && '' !== (string) $id ) ) { $this->fail( 'Invalid filtered source identity.' ); }
		$this->consume( 0, strlen( $this->codec->encode( array( $type, $plugin, $id ) ) ) );
		$head = $this->head( 'migrated' ); $this->collection( $head );
		$predicate = null === $plugin ? 'source_identity_hash IS NULL AND source_plugin_hash IS NULL' : 'source_identity_hash = UNHEX(%s)';
		$args = array( $this->blog, $head['id'], $head['active_generation'] );
		if ( null !== $plugin ) { $args[] = $this->codec->snapshot_digest( array( 'source-exact-v1', $type, $plugin, '' ) ); }
		$rows = array();
		foreach ( array( 0, 1 ) as $flag ) {
			$selected = $this->read( $this->db->prepare( 'SELECT id, item_order, HEX(node_hash) AS node_hash FROM `' . $this->table . '` FORCE INDEX (`source_identity`) '
				. 'WHERE blog_id = %d AND state_id = %d AND generation = %d AND ' . $predicate . ' AND is_migrated = %d '
				. 'AND migrated_id > 0 AND parent_node_hash IS NULL ORDER BY item_order ASC, id ASC LIMIT 101',
				array_merge( $args, array( $flag ) ) ) );
			if ( count( $selected ) > 100 - count( $rows ) ) { $this->fail( 'Filtered source identity exceeds 100 candidates; no partial answer is admitted.' ); }
			$this->consume( count( $selected ), strlen( $this->codec->encode( $selected ) ) );
			foreach ( $selected as $row ) {
				$row['id'] = $this->number( $row['id'] ); $row['item_order'] = $this->number( $row['item_order'] ); $row['node_hash'] = $this->hex( $row['node_hash'] );
				$rows[] = $row;
			}
		}
		usort( $rows, function ( $a, $b ) {
			if ( $a['item_order'] !== $b['item_order'] ) { return $a['item_order'] < $b['item_order'] ? -1 : 1; }
			return $a['id'] === $b['id'] ? 0 : ( $a['id'] < $b['id'] ? -1 : 1 );
		} );
		$result = array( 'found' => false, 'key' => null, 'value' => null );
		foreach ( $rows as $row ) {
			$tree = $this->tree_hash( 'migrated', $head, $row['node_hash'] ); $v = false === $tree ? null : $tree['value'];
			if ( ! is_array( $v ) || ! isset( $v['migrated_id'] ) || ( isset( $v['ID'] ) && ! is_scalar( $v['ID'] ) ) ||
				( isset( $v['plugin_name'] ) ? $v['plugin_name'] : null ) !== $plugin ||
				( null !== $plugin && ( ! isset( $v['object_type'] ) || $v['object_type'] !== $type || ! array_key_exists( 'ID', $v ) || (string) $v['ID'] !== '' ) ) ||
				(int) $v['migrated_id'] < 1 || $tree['order'] !== $row['item_order'] || $this->number( $tree['rows'][0]['id'] ) !== $row['id'] ) {
				$this->fail( 'Filtered source hash collision or projection mismatch.' );
			}
			if ( isset( $v['object_type'], $v['ID'] ) && $v['object_type'] === $type && is_scalar( $v['ID'] ) && (string) $v['ID'] === (string) $id ) {
				$result = array( 'found' => true, 'key' => $tree['key'], 'value' => $v ); break;
			}
		}
		$this->unchanged( 'migrated', $head ); return $result;
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

	/** Logical option log semantics, not physical flags/hydration defaults.
	 * Empty projected statuses collapse absent/null/empty canonical values;
	 * refuse that domain without scanning payloads or changing the schema.
	 * Only verified projection writes/maintenance establish canonical parity.
	 */
	private function history_log_summary( $entry ) {
		$head = $entry['head']; $groups = array(); $totals = array();
		foreach ( array( 'object_type', 'migration_status' ) as $column ) {
			// Schema v1's VARBINARY projections preserve case and trailing spaces.
			$sql = 'SELECT `' . $column . '` AS value, COUNT(*) AS count, MIN(item_order) AS first_order FROM `' . $this->table . '`'
				. ' WHERE blog_id = %d AND state_id = %d AND generation = %d AND parent_node_hash IS NULL AND object_type <> %s'
				. ' GROUP BY `' . $column . '` ORDER BY first_order ASC LIMIT 101';
			$rows = $this->read( $this->db->prepare( $sql, array( $this->blog, $head['id'], $head['active_generation'], '' ) ) );
			if ( count( $rows ) > 100 ) { $this->fail( 'Logical log summary exceeds distinct-group budget.' ); }
			// 80 bytes cover the 32-byte value plus both unsigned count/order fields.
			$this->consume( count( $rows ), count( $rows ) * 80 ); $counts = array(); $total = 0; $previous = null;
			foreach ( $rows as $row ) {
				if ( ! is_string( $row['value'] ) || strlen( $row['value'] ) > 32 || array_key_exists( $row['value'], $counts ) ) { $this->fail( 'Invalid logical summary group.' ); }
				if ( '' === $row['value'] ) { $this->fail( 'Ambiguous empty status projection is not admitted for logical summaries. No payload scan or option fallback.' ); }
				$count = $this->number( $row['count'] ); $order = $this->number( $row['first_order'] );
				if ( $count < 1 || ( null !== $previous && $order <= $previous ) ) { $this->fail( 'Invalid logical summary count/order.' ); }
				$counts[$row['value']] = $count; $total = $this->add( $total, $count ); $previous = $order;
			}
			$groups[$column] = $counts; $totals[$column] = $total;
		}
		if ( $totals['object_type'] !== $totals['migration_status'] || $totals['object_type'] > $entry['root']['root_count'] ) { $this->fail( 'Inconsistent logical summary totals.' ); }
		$types = $groups['object_type']; $statuses = $groups['migration_status']; $total = $totals['object_type'];
		return array( 'total' => $total, 'count' => $total, 'by_type' => $types, 'types' => $types, 'by_status' => $statuses, 'statuses' => $statuses,
			'completed' => isset( $statuses['completed'] ) ? $statuses['completed'] : 0, 'error' => isset( $statuses['error'] ) ? $statuses['error'] : 0 );
	}

	/** Per-type option semantics: only exact status 'error' increments errors.
	 * Empty types collapse explicit empty strings with missing/null/opaque roots
	 * in schema v1. Refuse those histories, rather than invent a payload scan.
	 * Empty statuses are safe here: all contribute zero errors, unlike log_summary.
	 */
	private function history_type_summary( $entry ) {
		$head = $entry['head'];
		$sql = 'SELECT object_type AS value, COUNT(*) AS count, SUM(migration_status = %s) AS errors, MIN(item_order) AS first_order FROM `' . $this->table . '`'
			. ' WHERE blog_id = %d AND state_id = %d AND generation = %d AND parent_node_hash IS NULL'
			. ' GROUP BY object_type ORDER BY first_order ASC LIMIT 101';
		$rows = $this->read( $this->db->prepare( $sql, array( 'error', $this->blog, $head['id'], $head['active_generation'] ) ) );
		if ( count( $rows ) > 100 ) { $this->fail( 'Logical per-type summary exceeds distinct-group budget.' ); }
		// 96 bytes cover the 32-byte type plus three unsigned aggregate fields.
		$this->consume( count( $rows ), count( $rows ) * 96 ); $summary = array(); $total = 0; $previous = null;
		foreach ( $rows as $row ) {
			if ( ! is_string( $row['value'] ) || strlen( $row['value'] ) > 32 || array_key_exists( $row['value'], $summary ) ) { $this->fail( 'Invalid logical per-type group.' ); }
			if ( '' === $row['value'] ) { $this->fail( 'Ambiguous empty type projection is not admitted for per-type summaries. No payload scan or option fallback.' ); }
			$count = $this->number( $row['count'] ); $errors = $this->number( $row['errors'] ); $order = $this->number( $row['first_order'] );
			if ( $count < 1 || $errors > $count || ( null !== $previous && $order <= $previous ) ) { $this->fail( 'Invalid logical per-type count/order.' ); }
			$summary[$row['value']] = array( 'count' => $count, 'errors' => $errors ); $total = $this->add( $total, $count ); $previous = $order;
		}
		if ( $total !== $entry['root']['root_count'] ) { $this->fail( 'Inconsistent logical per-type totals.' ); }
		return $summary;
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

	/** Gallery/album form context; absence retains the old lazy-discovery decision. */
	public function collection_view_context( $state_key ) {
		$this->collection_key( $state_key ); $this->start_budget(); $this->typed_key( $state_key );
		$head = $this->reader->head( $state_key );
		if ( false === $head ) {
			if ( false !== $this->reader->head( $state_key ) ) { $this->fail( 'Missing collection scope changed.' ); }
			return array( 'found' => false, 'total' => 0, 'head' => false );
		}
		$root = $this->collection( $head );
		if ( ! in_array( $head['state_kind'], array( 'map', 'list' ), true ) || ! isset( $root['wrapper'] ) ) { $this->fail( 'Indexed form collection required.' ); }
		$rows = $this->read( $this->db->prepare( 'SELECT COUNT(*) AS total FROM `' . $this->table . '` WHERE blog_id = %d AND state_id = %d AND generation = %d AND parent_node_hash IS NULL',
			array( $this->blog, $head['id'], $head['active_generation'] ) ) );
		if ( count( $rows ) !== 1 || $this->number( $rows[0]['total'] ) !== $root['root_count'] ) { $this->fail( 'Form root count mismatch.' ); }
		$this->unchanged( $state_key, $head );
		return array( 'found' => true, 'total' => $root['root_count'], 'head' => $head );
	}

	/** Fixed-size selector offset for a visible form page; skipped roots never decode. */
	public function collection_view_page( $state_key, $offset, $limit ) {
		$this->collection_key( $state_key );
		if ( ! is_int( $offset ) || $offset < 0 || ! is_int( $limit ) || $limit < 1 || $limit > LegacyTableReader::MAX_ROWS ) { $this->fail( 'Invalid form page.' ); }
		$this->start_budget(); $this->typed_key( $state_key ); $this->byte_limit = min( $this->max_bytes, 4194304 );
		$head = $this->head( $state_key ); $root = $this->collection( $head );
		if ( ! in_array( $head['state_kind'], array( 'map', 'list' ), true ) || ! isset( $root['wrapper'] ) ) { $this->fail( 'Indexed form collection required.' ); }
		if ( $offset > $root['root_count'] ) { $this->fail( 'Form page starts beyond collection.' ); }
		$selected = $this->read( $this->db->prepare( 'SELECT id, item_order, HEX(node_hash) AS node_hash FROM `' . $this->table . '` '
			. 'WHERE blog_id = %d AND state_id = %d AND generation = %d AND parent_node_hash IS NULL ORDER BY item_order ASC, id ASC LIMIT %d OFFSET %d',
			array( $this->blog, $head['id'], $head['active_generation'], $limit, $offset ) ) );
		if ( count( $selected ) !== min( $limit, $root['root_count'] - $offset ) ) { $this->fail( 'Form selector page count changed.' ); }
		$rows = array(); $previous = -1;
		foreach ( $selected as $row ) {
			$id = $this->number( $row['id'] ); $order = $this->number( $row['item_order'] );
			if ( $id < 1 || $order <= $previous ) { $this->fail( 'Duplicate or invalid form root order.' ); }
			$tree = $this->tree_hash( $state_key, $head, $this->hex( $row['node_hash'] ) );
			if ( false === $tree || $this->number( $tree['rows'][0]['id'] ) !== $id || $tree['order'] !== $order ) { $this->fail( 'Form root changed during page.' ); }
			$rows[] = array( 'key' => $tree['key'], 'value' => $tree['value'] ); $previous = $order;
		}
		$this->unchanged( $state_key, $head );
		return array( 'rows' => $rows, 'head' => $head );
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
					if ( array_key_exists( 'content_progress', $change ) ) {
						if ( ! isset( $root['wrapper']['content'] ) ) { $this->fail( 'Content envelope required.' ); }
						$root['wrapper']['values']['progress'] = $change['content_progress'];
					}
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
							if ( isset( $root['wrapper']['content'] ) && is_int( $patch['key'] ) && $patch['key'] >= $root['wrapper']['next_key'] ) { $root['wrapper']['next_key'] = $this->add( $patch['key'], 1 ); }
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
			if ( ! is_array( $change ) || ! isset( $change['key'], $change['expected_revision'] ) || count( $change ) !== ( array_key_exists( 'content_progress', $change ) ? 4 : 3 ) ||
				! is_int( $change['expected_revision'] ) || $change['expected_revision'] < 1 || $change['expected_revision'] >= PHP_INT_MAX ) { $this->fail( 'Invalid change or revision.' ); }
			$this->typed_key( $change['key'] ); $hash = $this->codec->key_hash( $change['key'] );
			if ( array_key_exists( 'content_progress', $change ) && ( ContentScanRepository::STATE_KEY !== $change['key'] || ! is_array( $change['content_progress'] ) || array_key_exists( 'value', $change ) ) ) { $this->fail( 'Invalid content checkpoint patch.' ); }
			if ( isset( $result[$hash] ) ) { $this->fail( 'Duplicate state change.' ); }
			$this->consume( 0, strlen( $this->codec->key_value( $change['key'] ) ) );
			if ( array_key_exists( 'value', $change ) ) {
				$encoded = $this->codec->encode( array( 'version' => 1, 'kind' => 'scalar', 'value' => $change['value'] ) );
				if ( strlen( $encoded ) > LegacyTableReader::MAX_RECORD_BYTES ) { $this->fail( 'Scalar row byte budget exceeded.' ); }
				$this->consume( 0, strlen( $encoded ) );
			} else {
				if ( ! isset( $change['patches'] ) || ! is_array( $change['patches'] ) || ( ! count( $change['patches'] ) && ! array_key_exists( 'content_progress', $change ) ) || count( $change['patches'] ) > $this->max_rows ) { $this->fail( 'Invalid affected patches.' ); }
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
