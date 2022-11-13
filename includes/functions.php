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

function foogallery_migrate_array_to_table($arr, $first=true, $sub_arr=false){
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