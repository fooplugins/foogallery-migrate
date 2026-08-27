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
			update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );

			return (int) get_option( self::SCHEMA_OPTION, 0 ) === self::SCHEMA_VERSION;
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
					' ON DUPLICATE KEY UPDATE parent_key=VALUES(parent_key),object_type=VALUES(object_type),plugin_name=VALUES(plugin_name),status=VALUES(status),payload=VALUES(payload),updated_at=VALUES(updated_at)';
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

			return $this->hydrate_row( $row );
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

			unset( $active[ MigratorSettings::KEY_MIGRATED ] );
			update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $active, false );
			if ( get_option( FOOGALLERY_MIGRATE_OPTION_DATA, array() ) !== $active ) {
				return false;
			}

			update_option( self::MIGRATION_OPTION, self::SCHEMA_VERSION, false );

			return true;
		}

		/**
		 * Verifies all migrated keys without hydrating their payloads.
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

			if ( ! $this->wpdb ) {
				return count( array_intersect_key( $this->memory_rows, $keys ) ) === count( $keys );
			}

			$verified = 0;
			foreach ( array_chunk( array_keys( $keys ), 500 ) as $chunk ) {
				$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
				$sql = 'SELECT COUNT(*) FROM ' . $this->table_name() . ' WHERE object_key IN (' . $placeholders . ')';
				$prepared = call_user_func_array( array( $this->wpdb, 'prepare' ), array_merge( array( $sql ), $chunk ) );
				$verified += (int) $this->wpdb->get_var( $prepared );
				$this->diagnostics['read_queries']++;
			}

			return $verified === count( $keys );
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
