<?php
/**
 * Table-backed migrated object storage.
 *
 * @package FooPlugins\FooGalleryMigrate
 */

namespace FooPlugins\FooGalleryMigrate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'FooPlugins\FooGalleryMigrate\MigratedStore' ) ) {

	/**
	 * Stores migrated objects without rewriting the complete migration history.
	 */
	class MigratedStore {

		const SCHEMA_VERSION = 1;
		const SCHEMA_OPTION = 'foogallery-migrate-store-schema-version';
		const MIGRATION_OPTION = 'foogallery-migrate-store-migration-version';
		const LEGACY_BACKUP_OPTION = 'foogallery-migrate-migrated-legacy-backup';

		/** @var object|null */
		private $wpdb;

		/** @var MigratorSettings */
		private $settings;

		/** @var array Request-only fallback used when WordPress DB is unavailable. */
		private $memory_rows = array();

		/** @var array Request-local operation counters. */
		private $diagnostics = array(
			'read_queries'            => 0,
			'write_queries'           => 0,
			'rows_written'            => 0,
			'full_history_hydrations' => 0,
			'legacy_hydrations'       => 0,
		);

		/**
		 * @param object|null      $wpdb WordPress database connection.
		 * @param MigratorSettings $settings Object serializer.
		 */
		public function __construct( $wpdb = null, $settings = null ) {
			if ( null === $wpdb ) {
				global $wpdb;
			}

			$this->wpdb = is_object( $wpdb ) ? $wpdb : null;
			$this->settings = $settings instanceof MigratorSettings ? $settings : new MigratorSettings();
		}

		/**
		 * Returns the current site's table name.
		 *
		 * @return string
		 */
		public function table_name() {
			$prefix = $this->wpdb && isset( $this->wpdb->prefix ) ? $this->wpdb->prefix : '';

			return $prefix . 'foogallery_migrate_objects';
		}

		/**
		 * Creates or upgrades the current site's table.
		 *
		 * @return bool
		 */
		public function install_schema() {
			if ( ! $this->wpdb ) {
				return false;
			}

			if ( ! function_exists( 'dbDelta' ) ) {
				$upgrade_file = ABSPATH . 'wp-admin/includes/upgrade.php';
				if ( file_exists( $upgrade_file ) ) {
					require_once $upgrade_file;
				}
			}

			if ( ! function_exists( 'dbDelta' ) ) {
				return false;
			}

			$charset_collate = method_exists( $this->wpdb, 'get_charset_collate' ) ? $this->wpdb->get_charset_collate() : '';
			$table = $this->table_name();
			$sql = "CREATE TABLE {$table} (
				object_key varchar(191) NOT NULL,
				parent_key varchar(191) DEFAULT NULL,
				object_type varchar(32) NOT NULL DEFAULT '',
				plugin_name varchar(191) NOT NULL DEFAULT '',
				status varchar(32) NOT NULL DEFAULT '',
				payload longtext NOT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (object_key),
				KEY parent_key (parent_key),
				KEY object_type (object_type),
				KEY plugin_name (plugin_name),
				KEY status (status)
			) {$charset_collate};";

			dbDelta( $sql );
			if ( ! $this->schema_is_ready() ) {
				return false;
			}

			update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );

			return (int) get_option( self::SCHEMA_OPTION, 0 ) === self::SCHEMA_VERSION;
		}

		/**
		 * Confirms that dbDelta created the table shape required by runtime queries.
		 *
		 * @return bool
		 */
		private function schema_is_ready() {
			$table = $this->table_name();
			$found = $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $table !== $found ) {
				return false;
			}

			$columns = $this->wpdb->get_results( 'SHOW COLUMNS FROM `' . $table . '`', ARRAY_A );
			$indexes = $this->wpdb->get_results( 'SHOW INDEX FROM `' . $table . '`', ARRAY_A );
			$column_names = array();
			$index_names = array();

			foreach ( is_array( $columns ) ? $columns : array() as $column ) {
				if ( isset( $column['Field'] ) ) {
					$column_names[] = $column['Field'];
				}
			}
			foreach ( is_array( $indexes ) ? $indexes : array() as $index ) {
				if ( isset( $index['Key_name'] ) ) {
					$index_names[] = $index['Key_name'];
				}
			}

			$required_columns = array( 'object_key', 'parent_key', 'object_type', 'plugin_name', 'status', 'payload', 'created_at', 'updated_at' );
			$required_indexes = array( 'PRIMARY', 'parent_key', 'object_type', 'plugin_name', 'status' );

			return empty( array_diff( $required_columns, array_unique( $column_names ) ) ) &&
				empty( array_diff( $required_indexes, array_unique( $index_names ) ) );
		}

		/** @return bool */
		public static function install_current_site() {
			$store = new self();
			return $store->install_schema() && $store->migrate_legacy();
		}

		/** @return void */
		public static function activate( $network_wide = false ) {
			if ( $network_wide && function_exists( 'is_multisite' ) && is_multisite() ) {
				$site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
				foreach ( $site_ids as $site_id ) {
					switch_to_blog( $site_id );
					self::install_current_site();
					restore_current_blog();
				}
				return;
			}

			self::install_current_site();
		}

		/** @return void */
		public static function uninstall( $network_wide = false ) {
			if ( $network_wide && function_exists( 'is_multisite' ) && is_multisite() ) {
				$site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
				foreach ( $site_ids as $site_id ) {
					switch_to_blog( $site_id );
					$store = new self();
					$store->uninstall_schema();
					restore_current_blog();
				}
				return;
			}

			$store = new self();
			$store->uninstall_schema();
		}

		/**
		 * Removes only the current site's dedicated store and store-owned options.
		 *
		 * @return bool
		 */
		public function uninstall_schema() {
			if ( ! $this->wpdb ) {
				return false;
			}

			$table = $this->table_name();
			if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
				return false;
			}

			if ( false === $this->wpdb->query( 'DROP TABLE IF EXISTS `' . $table . '`' ) ) {
				return false;
			}

			delete_option( self::SCHEMA_OPTION );
			delete_option( self::MIGRATION_OPTION );
			delete_option( self::LEGACY_BACKUP_OPTION );
			delete_option( FOOGALLERY_MIGRATE_OPTION_DATA );

			return true;
		}

		/**
		 * Converts an external identifier to the indexed storage key.
		 *
		 * @param string $key External object identifier.
		 * @return string
		 */
		public function storage_key( $key ) {
			$key = (string) $key;

			return strlen( $key ) <= 191 ? $key : 'sha256:' . hash( 'sha256', $key );
		}

		/**
		 * Upserts a request batch with one database write.
		 *
		 * @param array $records Rows containing object and optional parent_key.
		 * @return int|false Number of records queued, or false on failure.
		 */
		public function upsert_batch( $records ) {
			if ( ! is_array( $records ) || empty( $records ) ) {
				return 0;
			}

			$rows = array();
			foreach ( $records as $record ) {
				$object = is_array( $record ) && isset( $record['object'] ) ? $record['object'] : $record;
				if ( ! is_object( $object ) || ! method_exists( $object, 'unique_identifier' ) || ! method_exists( $object, 'type' ) ) {
					continue;
				}

				$external_key = (string) $object->unique_identifier();
				if ( '' === $external_key ) {
					continue;
				}

				$parent_key = is_array( $record ) && ! empty( $record['parent_key'] ) ? $this->storage_key( $record['parent_key'] ) : null;
				$plugin_name = '';
				if ( isset( $object->plugin ) && is_object( $object->plugin ) && method_exists( $object->plugin, 'name' ) ) {
					$plugin_name = (string) $object->plugin->name();
				}

				$payload = $this->settings->compact_migrated_object( $object );
				$payload = function_exists( 'wp_json_encode' ) ? wp_json_encode( $payload ) : json_encode( $payload );
				if ( false === $payload ) {
					return false;
				}

				$rows[] = array(
					'object_key'  => $this->storage_key( $external_key ),
					'parent_key'  => $parent_key,
					'object_type' => (string) $object->type(),
					'plugin_name' => $plugin_name,
					'status'      => isset( $object->migration_status ) ? (string) $object->migration_status : '',
					'payload'     => $payload,
					'created_at'  => gmdate( 'Y-m-d H:i:s' ),
					'updated_at'  => gmdate( 'Y-m-d H:i:s' ),
				);
			}

			if ( empty( $rows ) ) {
				return 0;
			}

			if ( ! $this->wpdb ) {
				foreach ( $rows as $row ) {
					if ( isset( $this->memory_rows[ $row['object_key'] ]['created_at'] ) ) {
						$row['created_at'] = $this->memory_rows[ $row['object_key'] ]['created_at'];
						if ( null === $row['parent_key'] ) {
							$row['parent_key'] = $this->memory_rows[ $row['object_key'] ]['parent_key'];
						}
					}
					$this->memory_rows[ $row['object_key'] ] = $row;
				}
			} else {
				$placeholders = array();
				$values = array();
				foreach ( $rows as $row ) {
					$placeholders[] = '(%s,%s,%s,%s,%s,%s,%s,%s)';
					foreach ( array( 'object_key', 'parent_key', 'object_type', 'plugin_name', 'status', 'payload', 'created_at', 'updated_at' ) as $field ) {
						$values[] = $row[ $field ];
					}
				}

				$sql = 'INSERT INTO ' . $this->table_name() .
					' (object_key,parent_key,object_type,plugin_name,status,payload,created_at,updated_at) VALUES ' .
					implode( ',', $placeholders ) .
					' ON DUPLICATE KEY UPDATE parent_key=COALESCE(VALUES(parent_key),parent_key),object_type=VALUES(object_type),plugin_name=VALUES(plugin_name),status=VALUES(status),payload=VALUES(payload),updated_at=VALUES(updated_at)';
				$prepared = call_user_func_array( array( $this->wpdb, 'prepare' ), array_merge( array( $sql ), $values ) );
				if ( false === $this->wpdb->query( $prepared ) ) {
					return false;
				}
			}

			$this->diagnostics['write_queries']++;
			$this->diagnostics['rows_written'] += count( $rows );

			return count( $rows );
		}

		/**
		 * Loads one migrated object by its indexed key.
		 *
		 * @param string $object_key External object identifier.
		 * @return object|false
		 */
		public function get( $object_key ) {
			$storage_key = $this->storage_key( $object_key );
			$this->diagnostics['read_queries']++;

			if ( ! $this->wpdb ) {
				$row = isset( $this->memory_rows[ $storage_key ] ) ? $this->memory_rows[ $storage_key ] : false;
			} else {
				$sql = $this->wpdb->prepare(
					'SELECT payload FROM ' . $this->table_name() . ' WHERE object_key = %s LIMIT 1',
					$storage_key
				);
				$row = $this->wpdb->get_row( $sql, ARRAY_A );
			}

			$object = $this->hydrate_row( $row );
			if ( false !== $object || ! $this->legacy_fallback_needed() ) {
				return $object;
			}

			$legacy = $this->settings->get_migrator_setting( MigratorSettings::KEY_MIGRATED, array() );
			return is_array( $legacy ) && isset( $legacy[ $object_key ] ) && is_object( $legacy[ $object_key ] )
				? $legacy[ $object_key ]
				: false;
		}

		/** @return bool */
		public function has( $object_key ) {
			$storage_key = $this->storage_key( $object_key );
			$this->diagnostics['read_queries']++;

			if ( ! $this->wpdb ) {
				if ( isset( $this->memory_rows[ $storage_key ] ) ) {
					return true;
				}
				return false !== $this->get( $object_key );
			}

			$sql = $this->wpdb->prepare(
				'SELECT 1 FROM ' . $this->table_name() . ' WHERE object_key = %s LIMIT 1',
				$storage_key
			);

			if ( (bool) $this->wpdb->get_var( $sql ) ) {
				return true;
			}

			return false !== $this->get( $object_key );
		}

		/** @return bool */
		private function legacy_fallback_needed() {
			if ( self::SCHEMA_VERSION === (int) get_option( self::MIGRATION_OPTION, 0 ) ) {
				return false;
			}

			$data = get_option( FOOGALLERY_MIGRATE_OPTION_DATA, array() );
			return is_array( $data ) && array_key_exists( MigratorSettings::KEY_MIGRATED, $data );
		}

		/**
		 * Loads only records belonging to one parent.
		 *
		 * @param string $parent_key External parent identifier.
		 * @return array
		 */
		public function get_by_parent( $parent_key ) {
			$storage_key = $this->storage_key( $parent_key );
			$this->diagnostics['read_queries']++;
			$rows = array();

			if ( ! $this->wpdb ) {
				foreach ( $this->memory_rows as $row ) {
					if ( isset( $row['parent_key'] ) && $storage_key === $row['parent_key'] ) {
						$rows[] = $row;
					}
				}
			} else {
				$sql = $this->wpdb->prepare(
					'SELECT payload FROM ' . $this->table_name() . ' WHERE parent_key = %s ORDER BY object_key',
					$storage_key
				);
				$rows = $this->wpdb->get_results( $sql, ARRAY_A );
			}

			return $this->hydrate_rows( $rows );
		}

		/**
		 * Loads the complete history for explicit log/export operations only.
		 *
		 * @param string $object_type Optional type filter.
		 * @return array
		 */
		public function get_all( $object_type = '' ) {
			$this->diagnostics['read_queries']++;
			$this->diagnostics['full_history_hydrations']++;
			$rows = array();

			if ( ! $this->wpdb ) {
				foreach ( $this->memory_rows as $row ) {
					if ( '' === $object_type || $object_type === $row['object_type'] ) {
						$rows[] = $row;
					}
				}
			} else if ( '' === $object_type ) {
				$rows = $this->wpdb->get_results( 'SELECT payload FROM ' . $this->table_name() . ' ORDER BY created_at, object_key', ARRAY_A );
			} else {
				$sql = $this->wpdb->prepare(
					'SELECT payload FROM ' . $this->table_name() . ' WHERE object_type = %s ORDER BY created_at, object_key',
					$object_type
				);
				$rows = $this->wpdb->get_results( $sql, ARRAY_A );
			}

			return $this->hydrate_rows( $rows, true );
		}

		/** @return int */
		public function count() {
			$this->diagnostics['read_queries']++;
			if ( ! $this->wpdb ) {
				return count( $this->memory_rows );
			}

			return (int) $this->wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->table_name() );
		}

		/** @return array */
		public function summary() {
			$this->diagnostics['read_queries']++;
			$summary = array();
			if ( ! $this->wpdb ) {
				foreach ( $this->memory_rows as $row ) {
					$type = $row['object_type'];
					if ( ! isset( $summary[ $type ] ) ) {
						$summary[ $type ] = array( 'count' => 0, 'errors' => 0 );
					}
					$summary[ $type ]['count']++;
					if ( 'error' === $row['status'] ) {
						$summary[ $type ]['errors']++;
					}
				}
				return $summary;
			}

			$rows = $this->wpdb->get_results(
				"SELECT object_type, COUNT(*) AS object_count, SUM(status = 'error') AS error_count FROM " . $this->table_name() . ' GROUP BY object_type',
				ARRAY_A
			);
			foreach ( $rows as $row ) {
				$summary[ $row['object_type'] ] = array(
					'count'  => (int) $row['object_count'],
					'errors' => (int) $row['error_count'],
				);
			}

			return $summary;
		}

		/** @return bool */
		public function delete( $object_key ) {
			$storage_key = $this->storage_key( $object_key );
			if ( ! $this->wpdb ) {
				if ( ! isset( $this->memory_rows[ $storage_key ] ) ) {
					return false;
				}
				unset( $this->memory_rows[ $storage_key ] );
			} else {
				$sql = $this->wpdb->prepare( 'DELETE FROM ' . $this->table_name() . ' WHERE object_key = %s', $storage_key );
				if ( false === $this->wpdb->query( $sql ) ) {
					return false;
				}
			}
			$this->diagnostics['write_queries']++;
			return true;
		}

		/**
		 * Removes one gallery and only its errored child rows for retry.
		 *
		 * @param string $gallery_key Gallery identifier.
		 * @return bool
		 */
		public function delete_for_retry( $gallery_key ) {
			$storage_key = $this->storage_key( $gallery_key );
			if ( ! $this->wpdb ) {
				foreach ( $this->memory_rows as $key => $row ) {
					if ( $key === $storage_key || ( isset( $row['parent_key'] ) && $storage_key === $row['parent_key'] && 'error' === $row['status'] ) ) {
						unset( $this->memory_rows[ $key ] );
					}
				}
			} else {
				$sql = $this->wpdb->prepare(
					"DELETE FROM " . $this->table_name() . " WHERE object_key = %s OR (parent_key = %s AND status = 'error')",
					$storage_key,
					$storage_key
				);
				if ( false === $this->wpdb->query( $sql ) ) {
					return false;
				}
			}
			$this->diagnostics['write_queries']++;
			return true;
		}

		/** @return bool */
		public function clear() {
			if ( ! $this->wpdb ) {
				$this->memory_rows = array();
			} else if ( false === $this->wpdb->query( 'DELETE FROM ' . $this->table_name() ) ) {
				return false;
			}
			$this->diagnostics['write_queries']++;
			return true;
		}

		/**
		 * Copies the legacy migrated collection into the table and only then removes
		 * it from the frequently rewritten active option. The exact source payload is
		 * retained in a non-autoloaded backup option for recovery.
		 *
		 * @return bool
		 */
		public function migrate_legacy() {
			$active = get_option( FOOGALLERY_MIGRATE_OPTION_DATA, array() );
			if ( ! is_array( $active ) || ! array_key_exists( MigratorSettings::KEY_MIGRATED, $active ) ) {
				update_option( self::MIGRATION_OPTION, self::SCHEMA_VERSION, false );
				return true;
			}

			$legacy_payload = $active[ MigratorSettings::KEY_MIGRATED ];
			update_option( self::LEGACY_BACKUP_OPTION, $legacy_payload, false );
			if ( get_option( self::LEGACY_BACKUP_OPTION, null ) !== $legacy_payload ) {
				return false;
			}

			$this->diagnostics['legacy_hydrations']++;
			$objects = $this->settings->get_migrator_setting( MigratorSettings::KEY_MIGRATED, array() );
			if ( ! is_array( $objects ) ) {
				return false;
			}

			$parent_keys = array();
			foreach ( $objects as $object ) {
				if (
					! is_object( $object ) ||
					! method_exists( $object, 'unique_identifier' ) ||
					! method_exists( $object, 'has_children' ) ||
					! $object->has_children() ||
					! method_exists( $object, 'get_children' )
				) {
					continue;
				}

				foreach ( $object->get_children() as $child ) {
					if ( is_object( $child ) && method_exists( $child, 'unique_identifier' ) ) {
						$parent_keys[ (string) $child->unique_identifier() ] = (string) $object->unique_identifier();
					}
			}
			}

			$records = array();
			foreach ( $objects as $object ) {
				if ( ! is_object( $object ) || ! method_exists( $object, 'unique_identifier' ) ) {
					return false;
				}

				$key = (string) $object->unique_identifier();
				$records[] = array(
					'object'     => $object,
					'parent_key' => isset( $parent_keys[ $key ] ) ? $parent_keys[ $key ] : null,
				);
			}

			foreach ( array_chunk( $records, 250 ) as $batch ) {
				if ( false === $this->upsert_batch( $batch ) ) {
					return false;
				}
			}

			if ( ! $this->verify_records( $records ) ) {
				return false;
			}

			$latest = get_option( FOOGALLERY_MIGRATE_OPTION_DATA, array() );
			if ( ! is_array( $latest ) || ! array_key_exists( MigratorSettings::KEY_MIGRATED, $latest ) || $latest[ MigratorSettings::KEY_MIGRATED ] !== $legacy_payload ) {
				return false;
			}

			unset( $latest[ MigratorSettings::KEY_MIGRATED ] );
			update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $latest, false );
			if ( get_option( FOOGALLERY_MIGRATE_OPTION_DATA, array() ) !== $latest ) {
				return false;
			}

			update_option( self::MIGRATION_OPTION, self::SCHEMA_VERSION, false );

			return true;
		}

		/**
		 * Verifies all migrated keys and confirms each stored payload is readable.
		 *
		 * @param array $records Migrated records.
		 * @return bool
		 */
		private function verify_records( $records ) {
			$keys = array();
			foreach ( $records as $record ) {
				$object = isset( $record['object'] ) ? $record['object'] : null;
				if ( is_object( $object ) && method_exists( $object, 'unique_identifier' ) ) {
					$keys[ $this->storage_key( $object->unique_identifier() ) ] = true;
				}
			}

			if ( empty( $keys ) ) {
				return empty( $records );
			}

			$verified = array();
			foreach ( array_chunk( array_keys( $keys ), 500 ) as $chunk ) {
				if ( ! $this->wpdb ) {
					$rows = array_intersect_key( $this->memory_rows, array_fill_keys( $chunk, true ) );
				} else {
					$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
					$sql = 'SELECT object_key,payload FROM ' . $this->table_name() . ' WHERE object_key IN (' . $placeholders . ')';
					$prepared = call_user_func_array( array( $this->wpdb, 'prepare' ), array_merge( array( $sql ), $chunk ) );
					$rows = $this->wpdb->get_results( $prepared, ARRAY_A );
					$this->diagnostics['read_queries']++;
				}

				foreach ( is_array( $rows ) ? $rows : array() as $row ) {
					if ( empty( $row['object_key'] ) || false === $this->hydrate_row( $row ) ) {
						return false;
					}
					$verified[ $row['object_key'] ] = true;
				}
			}

			return count( array_intersect_key( $verified, $keys ) ) === count( $keys );
		}

		/**
		 * Hydrates a stored database row.
		 *
		 * @param array|false|null $row Stored row.
		 * @return object|false
		 */
		private function hydrate_row( $row ) {
			if ( ! is_array( $row ) || ! isset( $row['payload'] ) ) {
				return false;
			}

			$record = json_decode( $row['payload'], true );
			if ( ! is_array( $record ) ) {
				return false;
			}

			$object = $this->settings->hydrate_migrated_object( $record );

			return is_object( $object ) ? $object : false;
		}

		/** @return array */
		private function hydrate_rows( $rows, $preserve_keys = false ) {
			$objects = array();
			if ( ! is_array( $rows ) ) {
				return $objects;
			}

			foreach ( $rows as $row ) {
				$object = $this->hydrate_row( $row );
				if ( false === $object ) {
					continue;
				}
				if ( $preserve_keys && method_exists( $object, 'unique_identifier' ) ) {
					$objects[ $object->unique_identifier() ] = $object;
				} else {
					$objects[] = $object;
				}
			}

			return $objects;
		}

		/** @return array */
		public function diagnostics() {
			return $this->diagnostics;
		}

		/** @return void */
		public function reset_diagnostics() {
			foreach ( $this->diagnostics as $key => $value ) {
				$this->diagnostics[ $key ] = 0;
			}
		}
	}
}
