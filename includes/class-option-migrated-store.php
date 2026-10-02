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

	public function __construct( $wpdb = null, $settings = null ) {
		$this->option_settings = $settings instanceof MigratorSettings ? $settings : new MigratorSettings();
		// Never attach this backend to a database history table.
		parent::__construct( false, $this->option_settings );
	}

	/** @return array Raw compact records, preserving unknown records. */
	private function records() {
		$data = get_option( FOOGALLERY_MIGRATE_OPTION_DATA, array() );
		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( 'Migration history options are unreadable. Restore a verified database backup before continuing.' );
		}
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
	private function save_records( $records ) {
		$data = get_option( FOOGALLERY_MIGRATE_OPTION_DATA, array() );
		if ( ! is_array( $data ) ) {
			return false;
		}
		$previous = $this->records();
		if ( $previous === $records ) {
			return true;
		}
		$revision = array_key_exists( MigratorSettings::KEY_MIGRATED_REVISION, $data ) && false !== $data[ MigratorSettings::KEY_MIGRATED_REVISION ]
			? absint( $data[ MigratorSettings::KEY_MIGRATED_REVISION ] ) : ( empty( $previous ) ? 0 : 1 );
		$data[ MigratorSettings::KEY_MIGRATED ] = array(
			MigratorSettings::COMPACT_MARKER => MigratorSettings::COMPACT_VERSION,
			'type' => 'migratable',
			'items' => $records,
		);
		$data[ MigratorSettings::KEY_MIGRATED_REVISION ] = $revision + 1;
		update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $data, false );
		return get_option( FOOGALLERY_MIGRATE_OPTION_DATA ) === $data;
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

	public function upsert_batch( $records ) {
		if ( ! is_array( $records ) ) {
			return false;
		}
		if ( empty( $records ) ) {
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
		return $this->save_records( $existing ) ? $count : false;
	}

	public function get( $object_key ) {
		$records = $this->records();
		return isset( $records[ $object_key ] ) ? $this->hydrate( $records[ $object_key ] ) : false;
	}

	public function has( $object_key ) {
		$records = $this->records();
		return array_key_exists( $object_key, $records );
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
			return isset( $record->$name ) ? $record->$name : null;
		}
		return null;
	}

	public function migrate_legacy() {
		// There is no automatic option-to-table conversion.
		return true;
	}
}
