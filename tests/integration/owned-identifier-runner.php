<?php
/** CLI bootstrap for named SQL harnesses on manifest-bound disposable R0 sites. */
if ( 'cli' !== PHP_SAPI || 3 !== $argc ) { throw new RuntimeException( 'Owned site and named harness required.' ); }
$site = realpath( $argv[1] );
if ( ! $site || ! preg_match( '#^/(private/)?tmp/foogallery-migrate-r0\.[A-Za-z0-9]+$#D', $site ) ) { throw new RuntimeException( 'Not an owned R0 site path.' ); }
$owner = json_decode( file_get_contents( $site . '/.fgm-r0-owner.json' ), true );
if ( ! is_array( $owner ) || 'foogallery-migrate-r0-v1' !== $owner['kind'] || realpath( $owner['site'] ) !== $site ||
	! in_array( $argv[2], array( 'output-boundaries', 'identifier-sql', 'identifier-conversion', 'legacy-storage-snapshots', 'legacy-history-mutations', 'history-retirement' ), true ) ) {
	throw new RuntimeException( 'Fixture identity or harness differs.' );
}
require $site . '/wp-load.php';
$allowed_host = str_replace( '127.0.0.1:', 'host.docker.internal:', $owner['host'] );
global $wpdb;
if ( DB_NAME !== $owner['database'] || ! in_array( DB_HOST, array( $owner['host'], $allowed_host ), true ) ||
	$wpdb->get_var( 'SELECT DATABASE()' ) !== $owner['database'] || $wpdb->last_error ) { throw new RuntimeException( 'Loaded database differs.' ); }
$admin = get_user_by( 'login', 'fgmr0admin' );
if ( ! $admin ) { throw new RuntimeException( 'Owned administrator absent.' ); }
wp_set_current_user( $admin->ID );
if ( ! current_user_can( 'manage_options' ) || is_multisite() ) { throw new RuntimeException( 'Owned admin/single-site scope differs.' ); }
define( 'FOOGM_STORAGE_TEST_DISPOSABLE', true );
require __DIR__ . '/' . $argv[2] . '.php';
