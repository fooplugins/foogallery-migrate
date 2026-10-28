<?php
// Loaded only in isolated ReleaseCompatibilityTest child processes.
namespace {
    function download_url( $url, $timeout = 300 ) { return $GLOBALS['foogallery_migrate_test_temp_file']; }
    function wp_handle_sideload( $file, $overrides = array() ) { return array( 'error' => 'Test sideload refusal' ); }
    function wp_tempnam( $file ) { return $GLOBALS['foogallery_migrate_test_temp_file']; }
    function wp_http_validate_url( $url ) { return $url; }
    function wp_delete_file( $file ) {
        $GLOBALS['foogallery_migrate_test_deleted_files'][] = $file;
        if ( is_file( $file ) ) { unlink( $file ); }
    }
}
namespace FooPlugins\FooGalleryMigrate\Objects {
    function copy( $source, $target ) {
        return empty( $GLOBALS['foogallery_migrate_test_copy_failure'] ) ? \copy( $source, $target ) : false;
    }
}
