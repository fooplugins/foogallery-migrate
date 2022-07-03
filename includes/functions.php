<?php
/**
 * Contains all the Global common functions used throughout the plugin
 */

use FooPlugins\FooGalleryMigrate\Migrator;

/**
 * Custom Autoloader used throughout FooGallery Migrate
 *
 * @param $class
 */
function foogallery_migrate_autoloader( $class ) {
	/* Only autoload classes from this namespace */
	if ( false === strpos( $class, FOOGM_NAMESPACE ) ) {
		return;
	}

	/* Remove namespace from class name */
	$class_file = str_replace( FOOGM_NAMESPACE . '\\', '', $class );

	/* Convert sub-namespaces into directories */
	$class_path = explode( '\\', $class_file );
	$class_file = array_pop( $class_path );
	$class_path = strtolower( implode( '/', $class_path ) );

	/* Convert class name format to file name format */
	$class_file = foogallery_migrate_uncamelize( $class_file );
	$class_file = str_replace( '_', '-', $class_file );
	$class_file = str_replace( '--', '-', $class_file );

	/* Load the class */
	require_once FOOGM_DIR . '/includes/' . $class_path . '/class-' . $class_file . '.php';
}

/**
 * Convert a CamelCase string to camel_case
 *
 * @param $str
 *
 * @return string
 */
function foogallery_migrate_uncamelize( $str ) {
	$str    = lcfirst( $str );
	$lc     = strtolower( $str );
	$result = '';
	$length = strlen( $str );
	for ( $i = 0; $i < $length; $i ++ ) {
		$result .= ( $str[ $i ] == $lc[ $i ] ? '' : '_' ) . $lc[ $i ];
	}

	return $result;
}

/**
 * Returns the singleton instance of the migrator.
 *
 * @return Migrator
 */
function foogallery_migrate_migrator_instance() {
    global $foogallery_migrate_migrator_instance;

    if ( !isset( $foogallery_migrate_migrator_instance ) ) {
        $foogallery_migrate_migrator_instance = new Migrator();
    }

    return $foogallery_migrate_migrator_instance;
}