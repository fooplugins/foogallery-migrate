<?php
/**
 * FooGallery Migrate uninstall handler.
 *
 * @package FooPlugins\FooGalleryMigrate
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! defined( 'FOOGM_NAMESPACE' ) ) {
	define( 'FOOGM_NAMESPACE', 'FooPlugins\\FooGalleryMigrate' );
	define( 'FOOGM_DIR', __DIR__ );
	define( 'FOOGM_PATH', plugin_dir_path( __FILE__ ) );
}

require_once FOOGM_PATH . 'includes/constants.php';
// Uninstall runs after the normal plugin bootstrap has been removed. Keep this
// dependency list direct and independent of Composer and the admin engine.
require_once FOOGM_PATH . 'includes/class-storage-scalar-observer.php';
require_once FOOGM_PATH . 'includes/class-legacy-transaction-connection.php';
require_once FOOGM_PATH . 'includes/class-legacy-option-backend.php';
require_once FOOGM_PATH . 'includes/class-migrator-settings.php';
require_once FOOGM_PATH . 'includes/class-migrated-store.php';
require_once FOOGM_PATH . 'includes/class-storage-authority.php';
require_once FOOGM_PATH . 'includes/class-storage-maintenance-gate.php';
require_once FOOGM_PATH . 'includes/class-legacy-storage-schema.php';
require_once FOOGM_PATH . 'includes/class-storage-cleanup.php';

if ( function_exists( 'is_multisite' ) && is_multisite() ) {
	// First-release multisite remains on the existing option-storage behavior.
	\FooPlugins\FooGalleryMigrate\MigratedStore::uninstall( true );
} else {
	\FooPlugins\FooGalleryMigrate\StorageCleanup::uninstall();
}
