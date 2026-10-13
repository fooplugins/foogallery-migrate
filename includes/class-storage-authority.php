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
	public static function option_mode_error() {
		global $wpdb;
		$missing = new \stdClass();
		// Even false/empty/corrupt persisted controls are evidence, not defaults.
		foreach ( array( self::CONTROL_OPTION, self::AUTHORITY_OPTION, self::MAINTENANCE_OPTION,
			'foogallery_migrate_storage', MigratedStore::SCHEMA_OPTION, MigratedStore::MIGRATION_OPTION ) as $option ) {
			if ( $missing !== get_option( $option, $missing ) ) {
				return self::recovery_error();
			}
		}
		if ( ! is_object( $wpdb ) || ! is_callable( array( $wpdb, 'get_var' ) ) ||
			! is_callable( array( $wpdb, 'prepare' ) ) || ! is_callable( array( $wpdb, 'esc_like' ) ) ||
			! isset( $wpdb->prefix ) || ! preg_match( '/^[A-Za-z0-9_]+$/', $wpdb->prefix ) ) {
			return self::recovery_error();
		}
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

	private static function recovery_error() {
		return new \WP_Error(
			'foogallery_migrate_storage_recovery_required',
			__( 'Migration storage requires recovery before continuing. No migration state was changed. Retain the database and contact your site administrator; do not delete storage markers, restore the legacy history snapshot over current state, or restart migrations from stale options.', 'foogallery-migrate' )
		);
	}
}
