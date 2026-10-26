<?php
namespace FooPlugins\FooGalleryMigrate;
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Shared history shape. Table operations are explicitly injected, not authority selection.
 * OptionMigratedStore remains the sole option-history owner. Explicit bounded
 * maintenance/export is admitted; unbounded hydration and public selection are not.
 */
class MigratedHistoryRepository {
	private $store;
	private $backend;
	private $settings;
	/** Explicit dependency injection only; never selects persistent authority. */
	public function state_backend() { return $this->backend; }
	public function __construct( $backend, $settings ) {
		if ( ( ! $backend instanceof LegacyOptionBackend && ! $backend instanceof LegacyTableBackend ) || ! $settings instanceof MigratorSettings ) {
			throw new \RuntimeException( 'Unsupported history backend. No option fallback.' );
		}
		$this->backend = $backend; $this->settings = $settings;
		$this->store = $backend instanceof LegacyOptionBackend ? new OptionMigratedStore( $settings, $backend ) : null;
	}
	public function get( $identifier ) {
		if ( null !== $this->store ) { return $this->store->get( $identifier ); }
		$rows = $this->get_many( array( $identifier ) ); return $rows[0]['found'] ? $rows[0]['value'] : false;
	}
	public function has( $identifier ) {
		if ( null !== $this->store ) { return $this->store->has( $identifier ); }
		$this->identifier_keys( array( $identifier ) );
		$read = $this->table_read( 'presence', array( $identifier ) ); return $read['result'][0]['found'];
	}
	public function get_many( $identifiers ) {
		if ( null !== $this->store ) { return $this->store->get_many( $identifiers ); }
		$keys = $this->identifier_keys( $identifiers );
		$read = $this->table_read( 'identifiers', $keys ); $rows = $read['result'];
		foreach ( $rows as &$row ) { if ( $row['found'] ) { $row['value'] = $this->hydrate( $row['value'] ); } }
		return $rows;
	}
	public function find_by_source_identity( $type, $plugin_name, $source_id ) {
		if ( null !== $this->store ) { return $this->store->find_by_source_identity( $type, $plugin_name, $source_id ); }
		if ( ! is_scalar( $source_id ) ) { return false; }
		if ( ! is_string( $type ) || '' === $type || strlen( $type ) > 32 || ( null !== $plugin_name && ! is_string( $plugin_name ) ) ) { throw new \RuntimeException( 'Unindexed or ambiguous table source identity is not admitted. No option fallback.' ); }
		$read = $this->table_read( 'source', array( 'type' => $type, 'plugin' => $plugin_name, 'id' => $source_id ) );
		$row = $read['result']; return $row['found'] ? $this->hydrate( $row['value'] ) : false;
	}
	public function find_by_source_url( $type, $source_url ) {
		if ( null !== $this->store ) { return $this->store->find_by_source_url( $type, $source_url ); }
		if ( ! is_string( $source_url ) || '' === $source_url ) { return false; }
		if ( ! is_string( $type ) || '' === $type || strlen( $type ) > 32 ) { throw new \RuntimeException( 'Invalid bounded source URL type.' ); }
		$result = $this->scan_source_urls( $type, array( $source_url ), $this->tag_build_identity(), true );
		return $result[0];
	}
	/** Immutable logical and physical history identity for one tag build. */
	public function tag_build_identity() {
		if ( null !== $this->store ) { throw new \RuntimeException( 'Table history context required.' ); }
		$read = $this->table_read( 'revision' ); $entries = $read['context'];
		return array( 'history' => $entries[0]['head'], 'revision_head' => $entries[1]['head'],
			'logical_revision' => $entries[1]['root']['value'] );
	}
	/** Positive image targets in first source order, ignoring the separate migrated flag. */
	public function tag_numeric_targets( $identities, $pin ) {
		if ( null !== $this->store || ! is_array( $identities ) || count( $identities ) > 100 || $this->tag_build_identity() !== $pin ) { throw new \RuntimeException( 'Stale or invalid tag numeric lookup.' ); }
		$read = $this->table_read( 'tag_numeric', $identities );
		if ( $this->tag_build_identity() !== $pin ) { throw new \RuntimeException( 'History changed during tag numeric lookup.' ); }
		if ( ! is_array( $read['result'] ) || count( $read['result'] ) !== count( $identities ) ) { throw new \RuntimeException( 'Malformed tag numeric result.' ); }
		$result = array(); foreach ( $read['result'] as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['found'] ) || ( $row['found'] && ( ! isset( $row['value'] ) || ! is_array( $row['value'] ) || ! isset( $row['value']['migrated_id'] ) || absint( $row['value']['migrated_id'] ) < 1 ) ) ) { throw new \RuntimeException( 'Malformed tag numeric target.' ); }
			$result[] = $row['found'] ? absint( $row['value']['migrated_id'] ) : 0;
		} return $result;
	}
	/** Exact/case-sensitive URL fallback, one bounded requested set at a time. */
	public function tag_source_urls( $urls, $pin ) {
		if ( null !== $this->store ) { throw new \RuntimeException( 'Table URL lookup required.' ); }
		return $this->scan_source_urls( 'image', $urls, $pin, false );
	}
	private function scan_source_urls( $type, $urls, $pin, $objects ) {
		if ( ! is_array( $urls ) || count( $urls ) > 100 || $this->tag_build_identity() !== $pin ) { throw new \RuntimeException( 'Stale or oversized tag URL lookup.' ); }
		$needed = array(); $bytes = 0;
		foreach ( $urls as $url ) {
			if ( ! is_string( $url ) || '' === $url || strlen( $url ) > 1048576 || strlen( $url ) > 16777216 - $bytes ) { throw new \RuntimeException( 'Tag URL request exceeds bounded bytes.' ); }
			$bytes += strlen( $url ); $needed[base64_encode( $url )] = true;
		}
		$found = array(); $cursor = null;
		while ( count( $found ) < count( $needed ) ) {
			$page = $this->page_log( $type, '', 100, $cursor );
			if ( $this->tag_build_identity() !== $pin ) { throw new \RuntimeException( 'History changed during tag URL scan.' ); }
			foreach ( $page['rows'] as $row ) {
				$object = $row['value'];
				if ( ! is_object( $object ) || ! isset( $object->migrated_id, $object->source_url ) ||
					! is_scalar( $object->source_url ) || ( $objects && ! is_string( $object->source_url ) ) ||
					absint( $object->migrated_id ) < 1 ) { continue; }
				$key = base64_encode( (string) $object->source_url );
				if ( isset( $needed[$key] ) && ! array_key_exists( $key, $found ) ) { $found[$key] = $objects ? $object : absint( $object->migrated_id ); }
			}
			$next = $page['cursor'];
			if ( null !== $next && ( ! count( $page['rows'] ) || $next === $cursor ) ) { throw new \RuntimeException( 'Unadvanced bounded tag URL page.' ); }
			$cursor = $next; if ( null === $cursor ) { break; }
		}
		if ( $this->tag_build_identity() !== $pin ) { throw new \RuntimeException( 'History changed before tag URL result.' ); }
		$result = array(); foreach ( $urls as $url ) { $key = base64_encode( $url ); $result[] = isset( $found[$key] ) ? $found[$key] : ( $objects ? false : 0 ); }
		return $result;
	}
	public function upsert_batch( $records, $companions = array() ) {
		if ( null !== $this->store ) { return $this->store->upsert_batch( $records, $companions ); }
		if ( ! is_array( $records ) || ! is_array( $companions ) || array_intersect( array_keys( $companions ),
			array( MigratorSettings::KEY_MIGRATED, MigratorSettings::KEY_MIGRATED_REVISION, MigratorSettings::KEY_PLUGINS, MigratorSettings::KEY_GALLERIES, MigratorSettings::KEY_ALBUMS ) ) ) { return false; }
		if ( count( $records ) > 10000 || count( $companions ) > 98 ) { throw new \RuntimeException( 'History mutation exceeds the bounded operation size.' ); }
		if ( ! count( $records ) && ! count( $companions ) ) { return 0; }
		$request = array(); $request_bytes = 0; $codec = new LegacyStateCodec();
		foreach ( $records as $record ) {
			$object = is_array( $record ) && isset( $record['object'] ) ? $record['object'] : $record;
			if ( ! is_object( $object ) || ! method_exists( $object, 'unique_identifier' ) || ! method_exists( $object, 'type' ) ) { return false; }
			$key = (string) $object->unique_identifier(); if ( '' === $key ) { return false; }
			// Object identifiers use the existing option map's PHP key semantics.
			$key = array_keys( array( $key => true ) )[0];
			$entry = array( 'key' => $key, 'value' => $this->settings->compact_migrated_object( $object ),
				'parent' => is_array( $record ) && ! empty( $record['parent_key'] ) ? (string) $record['parent_key'] : null,
				'fallback_parent' => ! empty( $object->migration_parent_key ) ? (string) $object->migration_parent_key : null );
			$bytes = strlen( $codec->encode( $entry ) );
			if ( $bytes > 16777216 - $request_bytes ) { throw new \RuntimeException( 'History mutation request byte budget exceeded.' ); }
			$request_bytes += $bytes; $request[] = $entry;
		}
		return $this->backend->history_mutate( 'upsert', $request, $companions );
	}
	public function upsert( $record, $companions = array() ) { return $this->upsert_batch( array( $record ), $companions ); }
	public function upsert_many( $records, $companions = array() ) { return $this->upsert_batch( $records, $companions ); }
	public function iterate_page( $limit = 100, $cursor = null ) { return $this->page_log( '', '', $limit, $cursor ); }
	public function page_log( $type = '', $status = '', $limit = 100, $cursor = null ) {
		if ( null !== $this->store ) { return $this->store->page_log( $type, $status, $limit, $cursor ); }
		if ( ! is_string( $type ) || ! is_string( $status ) || strlen( $type ) > 32 || strlen( $status ) > 32 || ! is_int( $limit ) || $limit < 1 || $limit > 100 ) { throw new \RuntimeException( 'Invalid bounded history page.' ); }
		if ( null !== $cursor && ( ! is_array( $cursor ) || array_keys( $cursor ) !== array( 'physical', 'revision_head' ) || ! is_array( $cursor['physical'] ) || ! is_array( $cursor['revision_head'] ) ) ) { throw new \RuntimeException( 'Malformed logical history cursor.' ); }
		$filters = array();
		if ( '' !== $type ) { $filters['object_type'] = $type; } if ( '' !== $status ) { $filters['migration_status'] = $status; }
		$read = $this->table_read( 'page', array( 'limit' => $limit, 'filters' => $filters, 'cursor' => null === $cursor ? null : $cursor['physical'] ) );
		$context = $read['context']; $page = $read['result']; $revision_head = $context[1]['head'];
		$revision_identity = array( 'id' => $revision_head['id'], 'generation' => $revision_head['active_generation'], 'revision' => $revision_head['revision'] );
		if ( null !== $cursor && $cursor['revision_head'] !== $revision_identity ) { throw new \RuntimeException( 'Stale logical history revision cursor.' ); }
		if ( $page['revision'] !== $context[0]['head']['revision'] ) { throw new \RuntimeException( 'History changed during page read.' ); }
		foreach ( $page['rows'] as &$row ) { $row['value'] = $this->hydrate( $row['value'] ); }
		if ( null !== $page['cursor'] ) { $page['cursor'] = array( 'physical' => $page['cursor'], 'revision_head' => $revision_identity ); }
		$page['revision'] = $context[1]['root']['value']; return $page;
	}
	public function count() {
		if ( null !== $this->store ) { return $this->store->count(); }
		$read = $this->table_read( 'count' ); return $read['result'];
	}
	public function update_status( $identifier, $status ) {
		if ( null !== $this->store ) { return $this->store->set_status( $identifier, $status ); }
		if ( ! is_string( $status ) ) { return false; }
		$this->identifier_keys( array( $identifier ) );
		if ( strlen( $status ) > 32 ) { throw new \RuntimeException( 'Unindexed table status is not admitted.' ); }
		return $this->backend->history_mutate( 'status', array( 'key' => $identifier, 'status' => $status ) );
	}
	public function set_status( $identifier, $status ) { return $this->update_status( $identifier, $status ); }
	public function delete( $identifier ) {
		if ( null !== $this->store ) { return $this->store->delete( $identifier ); }
		$this->identifier_keys( array( $identifier ) );
		return $this->backend->history_mutate( 'delete', array( 'key' => $identifier ) );
	}
	public function delete_for_retry( $identifier ) {
		if ( null !== $this->store ) { return $this->store->delete_for_retry( $identifier ); }
		$this->identifier_keys( array( $identifier ) );
		return $this->backend->history_mutate( 'retry', array( 'key' => $identifier ) );
	}
	public function clear() {
		return null !== $this->store ? $this->store->clear() : $this->backend->history_mutate( 'clear', array() );
	}
	/** Explicit lossless export, distinct from hydrated runtime log pages. */
	public function export_page( $limit = 100, $cursor = null ) {
		if ( null !== $this->store ) { return $this->store->export_page( $limit, $cursor ); }
		if ( ! is_int( $limit ) || $limit < 1 || $limit > 100 ) { throw new \RuntimeException( 'Invalid bounded history export.' ); }
		if ( null !== $cursor && ( ! is_array( $cursor ) || array_keys( $cursor ) !== array( 'physical', 'revision_head' ) || ! is_array( $cursor['physical'] ) || ! is_array( $cursor['revision_head'] ) ) ) { throw new \RuntimeException( 'Malformed logical history export cursor.' ); }
		$read = $this->table_read( 'export', array( 'limit' => $limit, 'filters' => array(), 'cursor' => null === $cursor ? null : $cursor['physical'] ) );
		$context = $read['context']; $page = $read['result']; $head = $context[1]['head'];
		$identity = array( 'id' => $head['id'], 'generation' => $head['active_generation'], 'revision' => $head['revision'] );
		if ( null !== $cursor && $cursor['revision_head'] !== $identity ) { throw new \RuntimeException( 'Stale logical history export cursor.' ); }
		if ( $page['revision'] !== $context[0]['head']['revision'] ) { throw new \RuntimeException( 'History changed during export.' ); }
		return array( 'root' => $context[0]['root'], 'rows' => $page['rows'], 'cursor' => null === $page['cursor'] ? null : array( 'physical' => $page['cursor'], 'revision_head' => $identity ), 'revision' => $context[1]['root']['value'] );
	}
	public function revision() {
		if ( null !== $this->store ) { return $this->store->revision(); }
		$read = $this->table_read( 'revision' ); return $read['context'][1]['root']['value'];
	}
	public function log_summary() {
		if ( null !== $this->store ) { return $this->store->log_summary(); }
		$read = $this->table_read( 'log_summary' ); return $read['result'];
	}
	public function summary() {
		if ( null !== $this->store ) { return $this->store->summary(); }
		$read = $this->table_read( 'summary' ); return $read['result'];
	}
	public function log_page( $type = '', $status = '', $offset = 0, $limit = 100 ) { return $this->option_store()->log_page( $type, $status, $offset, $limit ); }
	public function read_snapshot() { return $this->get_all(); }
	// Existing engine compatibility names. OptionMigratedStore remains the sole owner.
	public function get_all( $type = '' ) { return $this->option_store()->get_all( $type ); }
	public function get_by_parent( $identifier ) { return $this->option_store()->get_by_parent( $identifier ); }
	/** Loaded-parent seam: no unindexed whole-history scan on tables. */
	public function get_children_for_parent( $identifier, $keys ) {
		if ( null !== $this->store ) { return $this->store->get_by_parent( $identifier ); }
		$this->identifier_keys( array( $identifier ) );
		if ( ! is_array( $keys ) || count( $keys ) > 10000 ) { throw new \RuntimeException( 'Loaded child lookup exceeds its bounded envelope.' ); }
		foreach ( array_chunk( $keys, 100 ) as $batch ) { $this->identifier_keys( $batch ); }
		$seen = array();
		foreach ( $keys as $key ) { $typed = serialize( $key ); if ( isset( $seen[$typed] ) ) { throw new \RuntimeException( 'Duplicate loaded child identity.' ); } $seen[$typed] = true; }
		$children = array();
		$read = $this->table_read( 'children', array( 'parent' => $identifier, 'keys' => $keys ) );
		foreach ( $read['result'] as $row ) { $object = $this->hydrate( $row['value'] ); if ( is_object( $object ) ) { $children[] = $object; } }
		return $children;
	}
	public function manages_revision() { return true; }

	private function option_store() {
		if ( null === $this->store ) { throw new \RuntimeException( 'This table history operation is not admitted yet. No option fallback.' ); }
		return $this->store;
	}
	private function identifier_keys( $identifiers ) {
		if ( ! is_array( $identifiers ) || count( $identifiers ) > 100 ) { throw new \RuntimeException( 'Need at most 100 history identifiers.' ); }
		$codec = new LegacyStateCodec(); $unique = array();
		foreach ( $identifiers as $identifier ) {
			if ( ( ! is_int( $identifier ) && ! is_string( $identifier ) ) || array_keys( array( $identifier => true ) ) !== array( $identifier ) || array_key_exists( $identifier, $unique ) ) { throw new \RuntimeException( 'Invalid, coercible or duplicate typed history identifier.' ); }
			$codec->key_value( $identifier ); $unique[$identifier] = $identifier;
		}
		return array_values( $unique );
	}
	private function table_read( $operation, $request = array() ) {
		$read = $this->backend->history_read( $operation, $request ); $entries = $read['context'];
		$root = $entries[0]['root'];
		$empty = ! empty( $entries[0]['head']['virtual_absent'] ) && ! empty( $entries[1]['head']['virtual_absent'] ) &&
			0 === $entries[0]['head']['revision'] && 0 === $entries[1]['head']['revision'] &&
			$root === array( 'version' => 1, 'kind' => 'map', 'root_count' => 0 );
		if ( 'map' !== $entries[0]['head']['state_kind'] || ( ! $empty && ! array_key_exists( 'wrapper', $root ) ) ) { throw new \RuntimeException( 'Verified indexed history wrapper required; old header-root layouts are not admitted.' ); }
		if ( 'scalar' !== $entries[1]['head']['state_kind'] || ! is_int( $entries[1]['root']['value'] ) || $entries[1]['root']['value'] < 0 ) { throw new \RuntimeException( 'Invalid logical history revision.' ); }
		if ( $empty && 0 !== $entries[1]['root']['value'] ) { throw new \RuntimeException( 'Invalid empty logical history revision.' ); }
		return $read;
	}
	private function hydrate( $record ) {
		$object = $this->settings->hydrate_migrated_object( $record );
		if ( ! is_object( $object ) ) { return false; }
		if ( isset( $record['migration_parent_key'] ) ) { $object->migration_parent_key = $record['migration_parent_key']; }
		return $object;
	}
}
