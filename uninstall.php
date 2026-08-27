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

$network_wide = false;
if ( function_exists( 'is_multisite' ) && is_multisite() ) {
	if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$network_wide = is_plugin_active_for_network( plugin_basename( FOOGM_PATH . 'migrate.php' ) );
}
foogallery_migrate_uninstall( $network_wide );