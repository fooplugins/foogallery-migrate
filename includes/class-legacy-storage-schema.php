<?php
/**
 * Inert shared-schema definition and strict health inspection.
 * This class never provisions, upgrades or selects a backend. A future explicit
 * manager must acquire the installation-wide lock before executing this DDL,
 * call dbDelta(), verify(), and only then persist the main-site schema version.
 *
 * @package FooPlugins\FooGalleryMigrate
 */
namespace FooPlugins\FooGalleryMigrate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LegacyStorageSchema {
	const VERSION = 1;
	const VERSION_OPTION = 'foogallery_migrate_storage_schema_version';
	const LOCK_OPTION = 'foogallery_migrate_storage_schema_lock';
	private $wpdb;

	public function __construct( $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/** Column specs are (type, nullable, auto_increment); indexes (unique, columns). */
	public function definitions() {
		if ( ! is_object( $this->wpdb ) || ! isset( $this->wpdb->base_prefix ) ||
			! preg_match( '/^[A-Za-z0-9_]+$/D', $this->wpdb->base_prefix ) ) {
			$this->fail( 'Invalid installation table prefix.' );
		}
		$big = array( 'bigint unsigned', false );
		$hash = array( 'binary(32)', false );
		$optional_hash = array( 'binary(32)', true );
		$text = array( 'longtext', false );
		$optional_text = array( 'longtext', true );
		$word = array( 'varbinary(32)', false );
		$flag = array( 'tinyint unsigned', false );
		$date = array( 'datetime', false );
		$state = array(
			'columns' => array(
				'id' => array( 'bigint unsigned', false, true ), 'blog_id' => $big,
				'state_key_hash' => $hash, 'state_key_value' => $text, 'state_order' => $big,
				'state_kind' => $word, 'active_generation' => $big, 'revision' => $big,
				'codec_version' => $big, 'record_count' => $big, 'payload_bytes' => $big,
				'snapshot_digest' => $optional_hash, 'digest_revision' => $big, 'digest_status' => $word,
				'root_payload' => $text, 'created_gmt' => $date, 'updated_gmt' => $date,
			),
			'indexes' => array(
				'PRIMARY' => array( true, array( 'id' ) ),
				'state_key' => array( true, array( 'blog_id', 'state_key_hash' ) ),
				'state_order' => array( true, array( 'blog_id', 'state_order' ) ),
				'updated' => array( false, array( 'blog_id', 'updated_gmt' ) ),
			),
		);
		$scope = array( 'blog_id', 'state_id', 'generation' );
		$records = array(
			'columns' => array(
				'id' => array( 'bigint unsigned', false, true ), 'blog_id' => $big, 'state_id' => $big,
				'generation' => $big, 'row_kind' => $word, 'node_hash' => $hash,
				'legacy_identifier_hash' => $optional_hash, 'legacy_identifier_value' => $optional_text,
				'dedupe_hash' => $optional_hash, 'parent_node_hash' => $optional_hash,
				'key_type' => array( 'varbinary(8)', false ), 'key_value' => $text,
				'item_order' => $big, 'depth' => $big, 'object_type' => $word,
				'source_plugin_hash' => $optional_hash, 'source_plugin_value' => $optional_text,
				'source_id_value' => $optional_text, 'source_id_numeric' => array( 'bigint unsigned', true ),
				'source_identity_hash' => $optional_hash, 'source_numeric_identity_hash' => $optional_hash,
				'migration_status' => $word, 'is_selected' => $flag, 'is_migrated' => $flag,
				'migrated_id' => $big, 'has_error' => $flag, 'payload' => $text, 'mutable_payload' => $text,
				'payload_hash' => $hash, 'logical_hash' => $hash, 'payload_bytes' => $big,
				'created_gmt' => $date, 'updated_gmt' => $date,
			),
			'indexes' => array(
				'PRIMARY' => array( true, array( 'id' ) ),
				'node' => array( true, array_merge( $scope, array( 'node_hash' ) ) ),
				'page' => array( false, array_merge( $scope, array( 'parent_node_hash', 'item_order', 'id' ) ) ),
				'legacy_identifier' => array( false, array_merge( $scope, array( 'legacy_identifier_hash' ) ) ),
				'source_plugin' => array( false, array_merge( $scope, array( 'source_plugin_hash', 'item_order', 'id' ) ) ),
				'source_identity' => array( false, array_merge( $scope, array( 'source_identity_hash', 'is_migrated', 'item_order', 'id' ) ) ),
				'source_numeric' => array( false, array_merge( $scope, array( 'source_numeric_identity_hash', 'item_order', 'id' ) ) ),
				'dedupe' => array( true, array_merge( $scope, array( 'dedupe_hash' ) ) ),
				'work' => array( false, array_merge( $scope, array( 'migration_status', 'is_selected', 'item_order', 'id' ) ) ),
				'log_page' => array( false, array_merge( $scope, array( 'object_type', 'item_order', 'id' ) ) ),
				'aggregate' => array( false, array_merge( $scope, array( 'object_type', 'migration_status' ) ) ),
			),
		);
		return array( $this->wpdb->base_prefix . StorageAuthority::SHARED_STATE_TABLE_SUFFIX => $state,
			$this->wpdb->base_prefix . StorageAuthority::SHARED_RECORDS_TABLE_SUFFIX => $records );
	}

	/** dbDelta-compatible DDL only. No query or option writes happen here. */
	public function statements() {
		$statements = array();
		$charset = $this->wpdb->get_charset_collate();
		foreach ( $this->definitions() as $table => $definition ) {
			$parts = array();
			foreach ( $definition['columns'] as $column => $spec ) {
				$parts[] = $column . ' ' . $spec[0] . ( $spec[1] ? ' DEFAULT NULL' : ' NOT NULL' ) . ( ! empty( $spec[2] ) ? ' AUTO_INCREMENT' : '' );
			}
			foreach ( $definition['indexes'] as $name => $spec ) {
				$parts[] = ( 'PRIMARY' === $name ? 'PRIMARY KEY ' : ( $spec[0] ? 'UNIQUE KEY ' : 'KEY ' ) . $name ) . ' (' . implode( ',', $spec[1] ) . ')';
			}
			$statements[] = 'CREATE TABLE ' . $table . " (\n" . implode( ",\n", $parts ) . "\n) ENGINE=InnoDB " . $charset . ';';
		}
		return $statements;
	}

	/** Read real metadata, including types, engine, uniqueness and index order. */
	public function verify() {
		foreach ( $this->definitions() as $table => $definition ) {
			$status = $this->read( $this->wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $this->wpdb->esc_like( $table ) ) );
			if ( count( $status ) !== 1 || $status[0]['Name'] !== $table || strcasecmp( $status[0]['Engine'], 'InnoDB' ) !== 0 ) {
				$this->fail( 'Shared tables must exist and use InnoDB.' );
			}
			$columns = array();
			foreach ( $this->read( 'SHOW COLUMNS FROM `' . $table . '`' ) as $column ) {
				$columns[ $column['Field'] ] = $column;
			}
			foreach ( $definition['columns'] as $name => $spec ) {
				if ( ! isset( $columns[ $name ] ) || $this->type( $columns[ $name ]['Type'] ) !== $spec[0] ||
					$columns[ $name ]['Null'] !== ( $spec[1] ? 'YES' : 'NO' ) ||
					( ! empty( $spec[2] ) && 'auto_increment' !== strtolower( $columns[ $name ]['Extra'] ) ) ) {
					$this->fail( 'Required column definition mismatch: ' . $name );
				}
			}
			$indexes = array();
			foreach ( $this->read( 'SHOW INDEX FROM `' . $table . '`' ) as $index ) {
				$name = $index['Key_name'];
				if ( ! isset( $indexes[ $name ] ) ) {
					$indexes[ $name ] = array( 'unique' => 0 === (int) $index['Non_unique'], 'columns' => array(), 'valid' => true );
				}
				$indexes[ $name ]['columns'][ (int) $index['Seq_in_index'] ] = $index['Column_name'];
				$indexes[ $name ]['valid'] = $indexes[ $name ]['valid'] && null === $index['Sub_part'] &&
					'BTREE' === strtoupper( $index['Index_type'] ) && $indexes[ $name ]['unique'] === ( 0 === (int) $index['Non_unique'] );
			}
			foreach ( $definition['indexes'] as $name => $spec ) {
				if ( ! isset( $indexes[ $name ] ) ) {
					$this->fail( 'Missing required index: ' . $name );
				}
				ksort( $indexes[ $name ]['columns'] );
				if ( ! $indexes[ $name ]['valid'] || $indexes[ $name ]['unique'] !== $spec[0] || array_values( $indexes[ $name ]['columns'] ) !== $spec[1] ) {
					$this->fail( 'Required index definition mismatch: ' . $name );
				}
			}
		}
		return true;
	}

	private function type( $type ) {
		// MariaDB reports integer display widths; they do not change the type.
		return preg_replace( '/\b(bigint|tinyint)\([0-9]+\)/', '$1', strtolower( $type ) );
	}

	private function read( $sql ) {
		$result = $this->wpdb->get_results( $sql, ARRAY_A );
		if ( ! empty( $this->wpdb->last_error ) || ! is_array( $result ) ) {
			$this->fail( 'Schema inspection failed; no backend selection was changed.' );
		}
		return $result;
	}

	private function fail( $message ) {
		throw new \RuntimeException( 'Legacy storage schema: ' . $message );
	}
}
