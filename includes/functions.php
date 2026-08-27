<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Contains all the Global common functions used throughout the plugin
 */

use FooPlugins\FooGalleryMigrate\MigratorEngine;
use FooPlugins\FooGalleryMigrate\MigratedStore;

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

    $file_to_load = FOOGM_DIR . '/includes/' . $class_path . '/class-' . $class_file . '.php';

    if ( defined( 'WP_DEBUG' ) && true === WP_DEBUG ) {
        if ( !file_exists( $file_to_load ) ) {
            error_log( 'failed to load : ' . $file_to_load . ' for ' . $class);
            return;
        }
    }

	/* Load the class */
    require_once $file_to_load;
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
 * @return MigratorEngine
 */
function foogallery_migrate_migrator_instance() {
    global $foogallery_migrate_engine_instance;

    if ( !isset( $foogallery_migrate_engine_instance ) ) {
        $foogallery_migrate_engine_instance = new MigratorEngine();
    }

    return $foogallery_migrate_engine_instance;
}

/**
 * Installs/upgrades and migrates the current site's dedicated object store.
 *
 * @return bool
 */
function foogallery_migrate_install_store_current_site() {
    $store = new MigratedStore();

    return $store->install_schema() && $store->migrate_legacy();
}

/**
 * Activates the store for one site or every site in a network.
 *
 * @param bool $network_wide Whether the plugin is network activated.
 * @return void
 */
function foogallery_migrate_activate( $network_wide = false ) {
    if ( $network_wide && function_exists( 'is_multisite' ) && is_multisite() ) {
        $site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
        foreach ( $site_ids as $site_id ) {
            switch_to_blog( $site_id );
            foogallery_migrate_install_store_current_site();
            restore_current_blog();
        }
        return;
    }

    foogallery_migrate_install_store_current_site();
}

/**
 * Runs inexpensive schema/version checks on admin requests.
 *
 * @return void
 */
function foogallery_migrate_upgrade_store() {
    $data = get_option( FOOGALLERY_MIGRATE_OPTION_DATA, array() );
    $legacy_pending = is_array( $data ) && array_key_exists( \FooPlugins\FooGalleryMigrate\MigratorSettings::KEY_MIGRATED, $data );

    if (
        MigratedStore::SCHEMA_VERSION !== (int) get_option( MigratedStore::SCHEMA_OPTION, 0 ) ||
        MigratedStore::SCHEMA_VERSION !== (int) get_option( MigratedStore::MIGRATION_OPTION, 0 ) ||
        $legacy_pending
    ) {
        foogallery_migrate_install_store_current_site();
    }
}

/**
 * Removes store-owned data for one site or every site in a network.
 *
 * @param bool $network_wide Whether the plugin is network active.
 * @return void
 */
function foogallery_migrate_uninstall( $network_wide = false ) {
    if ( $network_wide && function_exists( 'is_multisite' ) && is_multisite() ) {
        $site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
        foreach ( $site_ids as $site_id ) {
            switch_to_blog( $site_id );
            $store = new MigratedStore();
            $store->uninstall_schema();
            restore_current_blog();
        }
        return;
    }

    $store = new MigratedStore();
    $store->uninstall_schema();
}

function foogallery_migrate_array_to_table($arr, $first=true, $sub_arr=false){
    if ( !is_array( $arr ) ) {
        return '';
    }
    $width = ($sub_arr) ? 'width="100%"' : '' ;
    $table = ($first) ? '<table class="foogallery-migrate-table" '.$width.'>' : '';
    $rows = array();
    foreach ( $arr as $key => $value ) {
        $value_type = gettype($value);
        switch ($value_type) {
            case 'string':
                $val = (in_array($value, array(""))) ? "&nbsp;" : $value;
                $rows[] = "<tr><th>{$key}</th><td>{$val}</td></tr>";
                break;
            case 'integer':
                $val = (in_array($value, array(""))) ? "&nbsp;" : $value;
                $rows[] = "<tr><th>{$key}</th><td>{$value}</td></tr>";
                break;
            case 'array':
                if (gettype($key) == "integer"):
                    $rows[] = foogallery_migrate_array_to_table($value, false);
                elseif (gettype($key) == "string"):
                    $rows[] = "<tr><th>{$key}</th><td>" .
                        foogallery_migrate_array_to_table($value, true, true) . "</td>";
                endif;
                break;
            default:
                # code...
                break;
        }
    }
    $ROWS = implode("\n", $rows);
    $table .= ($first) ? $ROWS . '</table>' : $ROWS;
    return $table;
}

function foogallery_migrate_get_available_plugins() {
    $plugins = array();

    $plugins[] = new \FooPlugins\FooGalleryMigrate\Plugins\Envira();
    $plugins[] = new \FooPlugins\FooGalleryMigrate\Plugins\Modula();
    $plugins[] = new \FooPlugins\FooGalleryMigrate\Plugins\Nextgen();
    $plugins[] = new \FooPlugins\FooGalleryMigrate\Plugins\Robo();
    $plugins[] = new \FooPlugins\FooGalleryMigrate\Plugins\Photo();
    $plugins[] = new \FooPlugins\FooGalleryMigrate\Plugins\Aigpl();
    $plugins[] = new \FooPlugins\FooGalleryMigrate\Plugins\WordPressCore();

    return $plugins;
}

/**
 * Build an admin URL for the FooGallery Migrate screen.
 *
 * @param string $tab  Optional active tab.
 * @param array  $args Optional extra query arguments.
 * @return string
 */
function foogallery_migrate_admin_url( $tab = '', $args = array() ) {
    $query_args = array(
        'post_type' => defined( 'FOOGALLERY_CPT_GALLERY' ) ? FOOGALLERY_CPT_GALLERY : 'foogallery',
        'page'      => 'foogallery-migrate',
    );

    if ( '' !== $tab ) {
        $query_args['tab'] = sanitize_key( $tab );
    }

    if ( is_array( $args ) && ! empty( $args ) ) {
        $query_args = array_merge( $query_args, $args );
    }

    return add_query_arg( $query_args, admin_url( 'edit.php' ) );
}
