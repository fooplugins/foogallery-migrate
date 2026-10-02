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
require_once FOOGM_PATH . 'includes/functions.php';
require_once FOOGM_PATH . 'vendor/autoload.php';

spl_autoload_register( 'foogallery_migrate_autoloader' );

$all_sites = function_exists( 'is_multisite' ) && is_multisite();
foogallery_migrate_uninstall( $all_sites );