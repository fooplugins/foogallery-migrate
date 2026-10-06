<?php
namespace FooPlugins\FooGalleryMigrate;
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Shared history shape. Table operations are explicitly injected, not authority selection.
 * OptionMigratedStore remains the sole option-history owner. Bulk deletion,
 * complete exports and public engine adoption are not yet admitted.
 */
class MigratedHistoryRepository {
	private $store;
	private $backend;
	private $settings;
	public function __construct( $backend, $settings ) {
		if ( ( ! $backend instanceof LegacyOptionBackend && ! $backend instanceof LegacyTableBackend ) || ! $settings instanceof MigratorSettings ) {
			throw new \RuntimeException( 'Unsupported history backend. No option fallback.' );
		}
		$this->backend = $backend; $this->settings = $settings;
		$this->store = $backend instanceof LegacyOptionBackend ? new OptionMigratedStore( false, $settings, $backend ) : null;
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
		if ( ! is_string( $type ) || '' === $type || strlen( $type ) > 32 || ! is_string( $plugin_name ) || '' === (string) $source_id ) { throw new \RuntimeException( 'Unindexed or ambiguous table source identity is not admitted. No option fallback.' ); }
		$read = $this->table_read( 'source', array( 'type' => $type, 'plugin' => $plugin_name, 'id' => $source_id ) );
		$row = $read['result']; return $row['found'] ? $this->hydrate( $row['value'] ) : false;
	}
	public function find_by_source_url( $type, $source_url ) { return $this->option_store()->find_by_source_url( $type, $source_url ); }
	public function upsert_batch( $records, $companions = array() ) {
		if ( null !== $this->store ) { return $this->store->upsert_batch( $records, $companions ); }
		if ( ! is_array( $records ) || ! is_array( $companions ) || array_intersect( array_keys( $companions ),
			array( MigratorSettings::KEY_MIGRATED, MigratorSettings::KEY_MIGRATED_REVISION, MigratorSettings::KEY_PLUGINS, MigratorSettings::KEY_GALLERIES, MigratorSettings::KEY_ALBUMS ) ) ) { return false; }
		if ( count( $records ) > 100 || count( $companions ) > 98 ) { throw new \RuntimeException( 'History mutation exceeds the bounded batch size.' ); }
		if ( ! count( $records ) && ! count( $companions ) ) { return 0; }
		$request = array();
		foreach ( $records as $record ) {
			$object = is_array( $record ) && isset( $record['object'] ) ? $record['object'] : $record;
			if ( ! is_object( $object ) || ! method_exists( $object, 'unique_identifier' ) || ! method_exists( $object, 'type' ) ) { return false; }
			$key = (string) $object->unique_identifier(); if ( '' === $key ) { return false; }
			// Object identifiers use the existing option map's PHP key semantics.
			$key = array_keys( array( $key => true ) )[0];
			$request[] = array( 'key' => $key, 'value' => $this->settings->compact_migrated_object( $object ),
				'parent' => is_array( $record ) && ! empty( $record['parent_key'] ) ? (string) $record['parent_key'] : null,
				'fallback_parent' => ! empty( $object->migration_parent_key ) ? (string) $object->migration_parent_key : null );
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
	public function delete_for_retry( $identifier ) { return $this->option_store()->delete_for_retry( $identifier ); }
	public function clear() { return $this->option_store()->clear(); }
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
		if ( 'map' !== $entries[0]['head']['state_kind'] || ! array_key_exists( 'wrapper', $root ) ) { throw new \RuntimeException( 'Verified indexed history wrapper required; old header-root layouts are not admitted.' ); }
		if ( 'scalar' !== $entries[1]['head']['state_kind'] || ! is_int( $entries[1]['root']['value'] ) || $entries[1]['root']['value'] < 0 ) { throw new \RuntimeException( 'Invalid logical history revision.' ); }
		return $read;
	}
	private function hydrate( $record ) {
		$object = $this->settings->hydrate_migrated_object( $record );
		if ( ! is_object( $object ) ) { return false; }
		if ( isset( $record['migration_parent_key'] ) ) { $object->migration_parent_key = $record['migration_parent_key']; }
		return $object;
	}
}
