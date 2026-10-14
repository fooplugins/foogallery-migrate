<?php
/**
 * Bootstrap authority checks. No control record is created implicitly.
 * The shared-table conversion path must replace this conservative gate before
 * table opt-in is offered; existing PR15 tables are never treated as empty data.
 *
 * @package FooPlugins\FooGalleryMigrate
 */
namespace FooPlugins\FooGalleryMigrate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StorageAuthority {
	const CONTROL_OPTION = 'foogallery_migrate_storage_control';
	const AUTHORITY_OPTION = 'foogallery_migrate_storage_authority';
	const MAINTENANCE_OPTION = 'foogallery_migrate_storage_maintenance';
	const STORAGE_BACKUP_OPTION = 'foogallery-migrate-data-storage-backup';
	const STATE_TABLE_SUFFIX = 'foogallery_migrate_state';
	const SHARED_STATE_TABLE_SUFFIX = 'foogallery_migrate_legacy_state';
	const SHARED_RECORDS_TABLE_SUFFIX = 'foogallery_migrate_legacy_records';

	/**
	 * @return false|\WP_Error False only for unambiguous legacy option authority.
	 */
	public static function option_mode_error( $writer = null, $maintenance = false ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! is_callable( array( $wpdb, 'get_var' ) ) ||
			! is_callable( array( $wpdb, 'prepare' ) ) || ! is_callable( array( $wpdb, 'esc_like' ) ) ||
			! isset( $wpdb->prefix ) || ! preg_match( '/^[A-Za-z0-9_]+$/', $wpdb->prefix ) ) {
			return self::recovery_error();
		}
		// Existence only: do not hydrate arbitrary controls or trust option caches.
		$markers = array( self::CONTROL_OPTION, self::AUTHORITY_OPTION,
			'foogallery_migrate_storage', MigratedStore::SCHEMA_OPTION, MigratedStore::MIGRATION_OPTION );
		// An already-admitted request may finish behind a newly acquired gate.
		$owned_maintenance = true === $maintenance && $writer instanceof StorageMaintenanceGate &&
			true === $writer->maintenance_ready( array( 'FooPlugins\\FooGalleryMigrate\\MigratorEngine', 'storage_maintenance_is_quiescent' ) );
		if ( ! $owned_maintenance && ( ! $writer instanceof StorageMaintenanceGate || true !== $writer->assert_writer() ) ) {
			$markers[] = self::MAINTENANCE_OPTION;
		}
		$found = $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 AS present FROM `' . $wpdb->prefix . 'options` WHERE option_name IN (' . implode( ', ', array_fill( 0, count( $markers ), '%s' ) ) . ') LIMIT 1', $markers
		) );
		if ( ! empty( $wpdb->last_error ) || null !== $found ) { return self::recovery_error(); }
		foreach ( array( MigratedStore::TABLE_SUFFIX, self::STATE_TABLE_SUFFIX ) as $suffix ) {
			$table = $wpdb->prefix . $suffix;
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( ! empty( $wpdb->last_error ) || null !== $found ) {
				return self::recovery_error();
			}
		}
		$base = isset( $wpdb->base_prefix ) ? $wpdb->base_prefix : $wpdb->prefix;
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $base ) ) {
			return self::recovery_error();
		}
		$blog_id = function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1;
		foreach ( array( self::SHARED_STATE_TABLE_SUFFIX, self::SHARED_RECORDS_TABLE_SUFFIX ) as $suffix ) {
			$table = $base . $suffix;
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( ! empty( $wpdb->last_error ) || ( null !== $found && $table !== $found ) ) {
				return self::recovery_error();
			}
			if ( null !== $found ) {
				$rows = $wpdb->get_var( $wpdb->prepare( 'SELECT 1 AS present FROM `' . $table . '` WHERE blog_id = %d LIMIT 1', $blog_id ) );
				if ( ! empty( $wpdb->last_error ) || null !== $rows ) {
					return self::recovery_error();
				}
			}
		}
		// Backups are evidence of a prior/pending conversion, not active authority.
		// Probe row existence only: a backup can be huge or contain legacy objects.
		$backup = $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 AS present FROM `' . $wpdb->prefix . 'options` WHERE option_name IN (%s, %s) LIMIT 1',
			MigratedStore::LEGACY_BACKUP_OPTION, self::STORAGE_BACKUP_OPTION
		) );
		if ( ! empty( $wpdb->last_error ) || null !== $backup ) {
			return self::recovery_error();
		}
		return false;
	}

	/** Fresh validated runtime authority. Null means unambiguous virgin options;
	 * unknown/interrupted/mismatched evidence is NEVER an option fallback.
	 */
	public static function runtime_record( $writer = null ) {
		global $wpdb;
		try {
			$blog = get_current_blog_id(); $control = new StorageControl( $wpdb, $blog );
			$raw = $control->read( self::CONTROL_OPTION );
			if ( null === $raw ) {
				$error = self::option_mode_error( $writer );
				return is_wp_error( $error ) ? $error : null;
			}
			$r = StorageControl::decode( $raw, $blog );
			if ( ! StorageControl::runtime_ready( $r, $control->read( self::AUTHORITY_OPTION ) ) ) { return self::recovery_error(); }
			$markers = array( 'foogallery_migrate_storage', MigratedStore::SCHEMA_OPTION, MigratedStore::MIGRATION_OPTION );
			if ( ! $writer instanceof StorageMaintenanceGate || true !== $writer->assert_writer() ) { $markers[] = self::MAINTENANCE_OPTION; }
			$found = $wpdb->get_var( $wpdb->prepare( 'SELECT 1 AS present FROM `' . $wpdb->prefix . 'options` WHERE option_name IN (' . implode( ', ', array_fill( 0, count( $markers ), '%s' ) ) . ') LIMIT 1', $markers ) );
			if ( ! empty( $wpdb->last_error ) || null !== $found ) { return self::recovery_error(); }
			foreach ( array( MigratedStore::TABLE_SUFFIX, self::STATE_TABLE_SUFFIX ) as $suffix ) {
				$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . $suffix ) ) );
				if ( ! empty( $wpdb->last_error ) || null !== $found ) { return self::recovery_error(); }
			}
			$live = $wpdb->get_var( $wpdb->prepare( 'SELECT 1 AS present FROM `' . $wpdb->prefix . 'options` WHERE option_name = %s LIMIT 1', FOOGALLERY_MIGRATE_OPTION_DATA ) );
			if ( ! empty( $wpdb->last_error ) || ( 'table' === $r['backend'] ? null !== $live : null === $live ) ) { return self::recovery_error(); }
			return $r;
		} catch ( \Exception $error ) { return self::recovery_error(); }
		catch ( \Throwable $error ) { return self::recovery_error(); }
	}

	/** Compatibility entrypoint now admits only the validated current backend. */
	public static function admit_option_writer( $gate = null ) {
		try {
			if ( null === $gate ) { $gate = StorageMaintenanceGate::current(); }
			if ( ! $gate instanceof StorageMaintenanceGate ) { return self::recovery_error(); }
			$result = $gate->admit_request_writer();
			if ( is_wp_error( $result ) ) { return $result; }
			$error = self::runtime_record( $gate );
			if ( is_wp_error( $error ) ) { return $error; }
			return $gate->assert_writer();
		} catch ( \RuntimeException $error ) { return self::recovery_error(); }
	}

	private static function recovery_error() {
		return new \WP_Error(
			'foogallery_migrate_storage_recovery_required',
			__( 'Migration storage requires recovery before continuing. No migration state was changed. Retain the database and contact your site administrator; do not delete storage markers, restore the legacy history snapshot over current state, or restart migrations from stale options.', 'foogallery-migrate' )
		);
	}
}
