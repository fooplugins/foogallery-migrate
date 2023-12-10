<?php
/*
Plugin Name: FooGallery Migrate
Description: Migrate to FooGallery from Envira, NextGen, Modula and other gallery plugins.
Version:     1.3
Author:      FooPlugins
Author URI:  https://fooplugins.com/
Text Domain: foogallery-migrate
License:     GPL-2.0+
*/

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

// Define some essential constants.
if ( ! defined( 'FOOGM_SLUG' ) ) {
    define( 'FOOGM_SLUG', 'foogallery-migrate' );
    define( 'FOOGM_NAMESPACE', 'FooPlugins\FooGalleryMigrate' );
    define( 'FOOGM_DIR', __DIR__ );
    define( 'FOOGM_PATH', plugin_dir_path( __FILE__ ) );
    define( 'FOOGM_URL', plugin_dir_url( __FILE__ ) );
    define( 'FOOGM_FILE', __FILE__ );
    define( 'FOOGM_VERSION', '1.3' );
    define( 'FOOGM_MIN_PHP', '5.4.0' ); // Minimum of PHP 5.4 required for autoloading, namespaces, etc.
    define( 'FOOGM_MIN_WP', '5.0' );  // Minimum of WordPress 5 required.
}

// Include other essential constants.
require_once FOOGM_PATH . 'includes/constants.php';

// Include common global functions.
require_once FOOGM_PATH . 'includes/functions.php';

// Check minimum requirements before loading the plugin.
if ( require_once FOOGM_PATH . 'includes/startup-checks.php' ) {

    // Start autoloader.
    require_once FOOGM_PATH . 'vendor/autoload.php';

    spl_autoload_register( 'foogallery_migrate_autoloader' );

    if ( is_admin() ) {
        // Start the plugin!
        new FooPlugins\FooGalleryMigrate\Init();
    }
}
