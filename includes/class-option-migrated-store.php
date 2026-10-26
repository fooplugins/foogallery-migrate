<?php
/**
 * Migrated-history seam for sites that have not opted in to table storage.
 * This intentionally retains the legacy option's whole-history cost.
 *
 * @package FooPlugins\FooGalleryMigrate
 */
namespace FooPlugins\FooGalleryMigrate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OptionMigratedStore extends MigratedStore {
	private $option_settings;
	private $option_backend;

	public function __construct( $wpdb = null, $settings = null, $backend = null ) {
		if ( null !== $backend && ! $backend instanceof LegacyOptionBackend ) {
			throw new \RuntimeException( 'Unsupported option-history backend. No fallback.' );
		}
		$this->option_backend = null === $backend ? new LegacyOptionBackend() : $backend;
		$this->option_settings = $settings instanceof MigratorSettings ? $settings : new MigratorSettings();
		// Never attach this backend to a database history table.
		parent::__construct( false, $this->option_settings );
	}

	/** @return array Raw compact records, preserving unknown records. */
	private function records() {
		return $this->records_from( $this->option_backend->read_all() );
	}

	private function records_from( $data ) {
		$history = array_key_exists( MigratorSettings::KEY_MIGRATED, $data ) ? $data[ MigratorSettings::KEY_MIGRATED ] : array();
		if ( ! is_array( $history ) ) {
			throw new \RuntimeException( 'Migration history is unreadable. Restore a verified database backup before continuing.' );
		}
		if ( array_key_exists( MigratorSettings::COMPACT_MARKER, $history ) ) {
			if ( MigratorSettings::COMPACT_VERSION !== $history[ MigratorSettings::COMPACT_MARKER ] ||
				! isset( $history['type'], $history['items'] ) || 'migratable' !== $history['type'] || ! is_array( $history['items'] ) ) {
				throw new \RuntimeException( 'Migration history has an unsupported format. No state was changed.' );
			}
			return $history['items'];
		}
		// Legacy runtime objects must not be compacted by a read.
		return $history;
	}

	/** @return bool */
	private function save_records( $records, $companions = array() ) {
		return $this->option_backend->mutate( function ( $data ) use ( $records, $companions ) {
			$previous = $this->records_from( $data );
			$changed = serialize( $previous ) !== serialize( $records );
			$original = $data;
			foreach ( $companions as $key => $value ) { $data[$key] = $value; }
			if ( ! $changed ) { return serialize( $original ) === serialize( $data ) ? true : $data; }
			$revision = array_key_exists( MigratorSettings::KEY_MIGRATED_REVISION, $data ) && false !== $data[ MigratorSettings::KEY_MIGRATED_REVISION ]
				? absint( $data[ MigratorSettings::KEY_MIGRATED_REVISION ] ) : ( empty( $previous ) ? 0 : 1 );
			$history = isset( $data[MigratorSettings::KEY_MIGRATED][MigratorSettings::COMPACT_MARKER] )
				? $data[MigratorSettings::KEY_MIGRATED] : array( MigratorSettings::COMPACT_MARKER => MigratorSettings::COMPACT_VERSION, 'type' => 'migratable' );
			$history['items'] = $records;
			$data[ MigratorSettings::KEY_MIGRATED ] = $history;
			$data[ MigratorSettings::KEY_MIGRATED_REVISION ] = $revision + 1;
			return $data;
		} );
	}

	private function hydrate( $record ) {
		if ( is_object( $record ) ) {
			return $record;
		}
		$object = $this->option_settings->hydrate_migrated_object( $record );
		if ( ! is_object( $object ) ) {
			return false;
		}
		if ( isset( $record['migration_parent_key'] ) ) {
			$object->migration_parent_key = $record['migration_parent_key'];
		}
		return $object;
	}

	public function upsert_batch( $records, $companions = array() ) {
		if ( ! is_array( $records ) || ! is_array( $companions ) || array_intersect( array_keys( $companions ),
			array( MigratorSettings::KEY_MIGRATED, MigratorSettings::KEY_MIGRATED_REVISION, MigratorSettings::KEY_PLUGINS, MigratorSettings::KEY_GALLERIES, MigratorSettings::KEY_ALBUMS ) ) ) {
			return false;
		}
		if ( empty( $records ) && empty( $companions ) ) {
			return 0;
		}
		$existing = $this->records();
		$count = 0;
		foreach ( $records as $record ) {
			$object = is_array( $record ) && isset( $record['object'] ) ? $record['object'] : $record;
			if ( ! is_object( $object ) || ! method_exists( $object, 'unique_identifier' ) || ! method_exists( $object, 'type' ) ) {
				return false;
			}
			$key = (string) $object->unique_identifier();
			if ( '' === $key ) {
				return false;
			}
			$payload = $this->option_settings->compact_migrated_object( $object );
			if ( is_array( $record ) && ! empty( $record['parent_key'] ) ) {
				$payload['migration_parent_key'] = (string) $record['parent_key'];
			} else if ( isset( $existing[ $key ] ) && null !== $this->field( $existing[ $key ], 'migration_parent_key' ) ) {
				$payload['migration_parent_key'] = $this->field( $existing[ $key ], 'migration_parent_key' );
			} else if ( ! empty( $object->migration_parent_key ) ) {
				$payload['migration_parent_key'] = (string) $object->migration_parent_key;
			}
			$existing[ $key ] = $payload;
			$count++;
		}
		return $this->save_records( $existing, $companions ) ? $count : false;
	}

	public function get( $object_key ) {
		$records = $this->records();
		return isset( $records[ $object_key ] ) ? $this->hydrate( $records[ $object_key ] ) : false;
	}

	public function has( $object_key ) {
		$records = $this->records();
		return array_key_exists( $object_key, $records );
	}

	/** Bounded hydration only. Physical option reads still load the whole option. */
	public function get_many( $keys ) {
		if ( ! is_array( $keys ) || count( $keys ) > 100 ) { throw new \RuntimeException( 'Need at most 100 exact history keys.' ); }
		$seen = array();
		foreach ( $keys as $key ) {
			if ( ! is_int( $key ) && ! is_string( $key ) ) { throw new \RuntimeException( 'Invalid history key.' ); }
			if ( array_keys( array( $key => true ) ) !== array( $key ) || array_key_exists( $key, $seen ) ) { throw new \RuntimeException( 'Coercible or duplicate history key.' ); }
			$seen[$key] = true;
		}
		$records = $this->records();
		$result = array();
		foreach ( $keys as $key ) {
			$found = array_key_exists( $key, $records );
			$result[] = array( 'found' => $found, 'key' => $key, 'value' => $found ? $this->hydrate( $records[$key] ) : null );
		}
		return $result;
	}

	/** Exact case-sensitive identity, string source ID, first positive target in legacy order. */
	public function find_by_source_identity( $type, $plugin_name, $source_id ) {
		if ( ! is_scalar( $source_id ) ) { return false; }
		foreach ( $this->records() as $record ) {
			$id = $this->field( $record, 'ID' );
			if ( $type === $this->field( $record, 'object_type' ) && $plugin_name === $this->field( $record, 'plugin_name' ) &&
				is_scalar( $id ) && (string) $source_id === (string) $id && (int) $this->field( $record, 'migrated_id' ) > 0 ) {
				$object = $this->hydrate( $record );
				if ( false !== $object ) { return $object; }
			}
		}
		return false;
	}
	public function find_by_source_url( $type, $source_url ) {
		if ( ! is_string( $source_url ) || '' === $source_url ) { return false; }
		foreach ( $this->records() as $record ) {
			if ( $type === $this->field( $record, 'object_type' ) && $source_url === $this->field( $record, 'source_url' ) && (int) $this->field( $record, 'migrated_id' ) > 0 ) {
				$object = $this->hydrate( $record );
				if ( false !== $object ) { return $object; }
			}
		}
		return false;
	}
	public function revision() {
		$data = $this->option_backend->read_all();
		return array_key_exists( MigratorSettings::KEY_MIGRATED_REVISION, $data ) && false !== $data[MigratorSettings::KEY_MIGRATED_REVISION]
			? absint( $data[MigratorSettings::KEY_MIGRATED_REVISION] ) : ( count( $this->records_from( $data ) ) ? 1 : 0 );
	}
	public function set_status( $key, $status ) {
		if ( ! is_string( $status ) ) { return false; }
		$records = $this->records();
		if ( ! array_key_exists( $key, $records ) ) { return false; }
		if ( is_object( $records[$key] ) ) { $records[$key] = clone $records[$key]; $records[$key]->migration_status = $status; }
		elseif ( is_array( $records[$key] ) ) { $records[$key]['migration_status'] = $status; }
		else { return false; }
		return $this->save_records( $records );
	}
	public function log_summary() {
		$types = array(); $statuses = array(); $total = 0;
		foreach ( $this->records() as $record ) {
			$type = $this->field( $record, 'object_type' );
			if ( ! is_string( $type ) || '' === $type ) { continue; }
			$status = $this->field( $record, 'migration_status' );
			$total++;
			$types[$type] = isset( $types[$type] ) ? $types[$type] + 1 : 1;
			if ( is_string( $status ) ) { $statuses[$status] = isset( $statuses[$status] ) ? $statuses[$status] + 1 : 1; }
		}
		return array( 'total' => $total, 'count' => $total, 'by_type' => $types, 'types' => $types, 'by_status' => $statuses, 'statuses' => $statuses,
			'completed' => isset( $statuses['completed'] ) ? $statuses['completed'] : 0, 'error' => isset( $statuses['error'] ) ? $statuses['error'] : 0 );
	}
	/** Common page envelope. Option cursor binds logical bytes, not fictional table IDs. */
	public function page_log( $type = '', $status = '', $limit = 100, $cursor = null ) {
		return $this->history_page( $type, $status, $limit, $cursor, false );
	}
	public function export_page( $limit = 100, $cursor = null ) {
		return $this->history_page( '', '', $limit, $cursor, true );
	}
	private function history_page( $type, $status, $limit, $cursor, $raw ) {
		if ( ! is_string( $type ) || ! is_string( $status ) || ! is_int( $limit ) || $limit < 1 || $limit > 100 ) { throw new \RuntimeException( 'Invalid bounded history page.' ); }
		$data = $this->option_backend->read_all();
		$records = $this->records_from( $data );
		$wrapper = array_key_exists( MigratorSettings::KEY_MIGRATED, $data ) ? $data[MigratorSettings::KEY_MIGRATED] : array();
		$revision = array_key_exists( MigratorSettings::KEY_MIGRATED_REVISION, $data ) && false !== $data[MigratorSettings::KEY_MIGRATED_REVISION]
			? absint( $data[MigratorSettings::KEY_MIGRATED_REVISION] ) : ( count( $records ) ? 1 : 0 );
		$binding = array( 'blog' => $this->current_blog_id(), 'state' => MigratorSettings::KEY_MIGRATED, 'revision' => $revision,
			'digest' => hash( 'sha256', serialize( $records ) ), 'type' => $type, 'status' => $status );
		if ( $raw ) { $binding['export'] = true; $binding['wrapper_digest'] = hash( 'sha256', serialize( $wrapper ) ); }
		$offset = 0;
		if ( null !== $cursor ) {
			if ( ! is_array( $cursor ) || array_keys( $cursor ) !== array_merge( array_keys( $binding ), array( 'offset' ) ) ||
				! is_int( $cursor['offset'] ) || $cursor['offset'] < 0 ) { throw new \RuntimeException( 'Invalid option history cursor.' ); }
			foreach ( $binding as $key => $value ) { if ( $cursor[$key] !== $value ) { throw new \RuntimeException( 'Stale or cross-scope option history cursor.' ); } }
			$offset = $cursor['offset'];
		}
		$rows = array(); $matched = 0; $more = false;
		foreach ( $records as $key => $record ) {
			if ( ( '' !== $type && $type !== $this->field( $record, 'object_type' ) ) || ( '' !== $status && $status !== $this->field( $record, 'migration_status' ) ) ) { continue; }
			if ( $matched++ < $offset ) { continue; }
			if ( count( $rows ) === $limit ) { $more = true; break; }
			$rows[] = array( 'key' => $key, 'value' => $raw ? $record : $this->hydrate( $record ) );
		}
		// Do not return mixed pages if a non-cooperating option writer changed history.
		$after = $this->option_backend->read_all();
		if ( serialize( $this->records_from( $after ) ) !== serialize( $records ) ||
			( isset( $after[MigratorSettings::KEY_MIGRATED_REVISION] ) ? $after[MigratorSettings::KEY_MIGRATED_REVISION] : null ) !==
			( isset( $data[MigratorSettings::KEY_MIGRATED_REVISION] ) ? $data[MigratorSettings::KEY_MIGRATED_REVISION] : null ) ) { throw new \RuntimeException( 'History changed during page read.' ); }
		if ( $raw && serialize( array_key_exists( MigratorSettings::KEY_MIGRATED, $after ) ? $after[MigratorSettings::KEY_MIGRATED] : array() ) !== serialize( $wrapper ) ) { throw new \RuntimeException( 'History wrapper changed during export.' ); }
		$page = array( 'rows' => $rows, 'cursor' => $more ? $binding + array( 'offset' => $offset + count( $rows ) ) : null, 'revision' => $revision );
		if ( $raw ) { $page = array( 'root' => ( new LegacyCollectionEnvelope() )->split( MigratorSettings::KEY_MIGRATED, 'map', $wrapper )['root'] ) + $page; }
		return $page;
	}
	public function iterate_page( $limit = 100, $cursor = null ) { return $this->page_log( '', '', $limit, $cursor ); }
	protected function current_blog_id() { return get_current_blog_id(); }
	public function upsert( $record, $companions = array() ) { return $this->upsert_batch( array( $record ), $companions ); }
	public function upsert_many( $records, $companions = array() ) { return $this->upsert_batch( $records, $companions ); }
	/** Offset applies after type/status filtering; keys and insertion order survive. */
	public function log_page( $type = '', $status = '', $offset = 0, $limit = 100 ) {
		if ( ! is_int( $offset ) || $offset < 0 || ! is_int( $limit ) || $limit < 1 || $limit > 100 ) { throw new \RuntimeException( 'Invalid bounded log page.' ); }
		$result = array(); $matched = 0;
		foreach ( $this->records() as $key => $record ) {
			if ( '' !== $type && $type !== $this->field( $record, 'object_type' ) || '' !== $status && $status !== $this->field( $record, 'migration_status' ) ) { continue; }
			// Same compatibility policy as get_all: opaque records do not render.
			if ( ! is_object( $record ) && ! is_string( $this->field( $record, 'object_type' ) ) ) { continue; }
			if ( $matched++ < $offset ) { continue; }
			$object = $this->hydrate( $record );
			if ( false !== $object ) { $result[$key] = $object; }
			if ( count( $result ) === $limit ) { break; }
		}
		return $result;
	}

	public function get_all( $object_type = '' ) {
		$objects = array();
		foreach ( $this->records() as $key => $record ) {
			if ( '' !== $object_type && $object_type !== $this->field( $record, 'object_type' ) ) {
				continue;
			}
			$object = $this->hydrate( $record );
			if ( false !== $object ) {
				$objects[ $key ] = $object;
			}
		}
		return $objects;
	}

	public function get_by_parent( $parent_key ) {
		$objects = array();
		foreach ( $this->records() as $record ) {
			if ( $parent_key === $this->field( $record, 'migration_parent_key' ) ) {
				$object = $this->hydrate( $record );
				if ( false !== $object ) {
					$objects[] = $object;
				}
			}
		}
		return $objects;
	}

	public function count() {
		return count( $this->records() );
	}

	public function summary() {
		$summary = array();
		foreach ( $this->records() as $record ) {
			$type = $this->field( $record, 'object_type' );
			if ( null === $type ) {
				continue;
			}
			if ( ! isset( $summary[ $type ] ) ) {
				$summary[ $type ] = array( 'count' => 0, 'errors' => 0 );
			}
			$summary[ $type ]['count']++;
			if ( 'error' === $this->field( $record, 'migration_status' ) ) {
				$summary[ $type ]['errors']++;
			}
		}
		return $summary;
	}

	public function delete( $object_key ) {
		$records = $this->records();
		if ( ! array_key_exists( $object_key, $records ) ) {
			return false;
		}
		unset( $records[ $object_key ] );
		return $this->save_records( $records );
	}

	public function delete_for_retry( $gallery_key ) {
		$records = $this->records();
		foreach ( $records as $key => $record ) {
			if ( $key === $gallery_key || ( $gallery_key === $this->field( $record, 'migration_parent_key' ) &&
				'error' === $this->field( $record, 'migration_status' ) ) ) {
				unset( $records[ $key ] );
			}
		}
		return $this->save_records( $records );
	}

	public function clear() {
		// Validate before writing: a corrupt option must not be replaced silently.
		$this->records();
		return $this->save_records( array() );
	}

	public function install_schema() {
		return true;
	}

	public function manages_revision() {
		return true;
	}

	private function field( $record, $name ) {
		if ( is_array( $record ) ) {
			return array_key_exists( $name, $record ) ? $record[ $name ] : null;
		}
		if ( is_object( $record ) ) {
			if ( 'object_type' === $name && method_exists( $record, 'type' ) ) {
				return $record->type();
			}
			if ( 'plugin_name' === $name && isset( $record->plugin ) && is_object( $record->plugin ) && method_exists( $record->plugin, 'name' ) ) {
				return $record->plugin->name();
			}
			return isset( $record->$name ) ? $record->$name : null;
		}
		return null;
	}

	public function migrate_legacy() {
		// There is no automatic option-to-table conversion.
		return true;
	}
}
