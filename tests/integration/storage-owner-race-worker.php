<?php
/** Separate-process SQL racer for an invocation-owned disposable WordPress site. */
if ( 'cli' !== PHP_SAPI || 2 !== $argc || ! is_file( $argv[1] . '/wp-load.php' ) ) { exit( 2 ); }
$input = json_decode( stream_get_contents( STDIN, 2048 ), true );
if ( ! is_array( $input ) || array_keys( $input ) !== array( 'blog_id', 'action', 'owner' ) ||
	! is_int( $input['blog_id'] ) || $input['blog_id'] < 1 || ! is_string( $input['owner'] ) || strlen( $input['owner'] ) > 512 ||
	! in_array( $input['action'], array( 'owner-replace', 'writer-add' ), true ) ) { exit( 3 ); }
define( 'WP_USE_THEMES', false );
require $argv[1] . '/wp-load.php';
if ( ! preg_match( '/^fgm-preflight-[a-z0-9-]+\.localhost$/D', basename( dirname( rtrim( ABSPATH, '/' ) ) ) ) ||
	0 !== strpos( DB_NAME, 'cove_fgm_preflight_' ) ) { exit( 6 ); }
if ( is_multisite() && get_current_blog_id() !== $input['blog_id'] ) { switch_to_blog( $input['blog_id'] ); }
if ( get_current_blog_id() !== $input['blog_id'] ) { exit( 4 ); }
global $wpdb;
// Resolve actual product name from the exact, invocation-owned plugin source.
require_once __DIR__ . '/../../includes/class-storage-authority.php';
$name = \FooPlugins\FooGalleryMigrate\StorageAuthority::MAINTENANCE_OPTION;
$table = $wpdb->options;
if ( 'owner-replace' === $input['action'] ) {
	$sql = $wpdb->prepare( 'UPDATE `' . $table . '` SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s', 'fixture-lost-owner', $name, $input['owner'] );
} else {
	$sql = "INSERT INTO `" . $table . "` (option_name, option_value, autoload) VALUES ('foogallery_migrate_storage_writer_fixture_race', 'fixture-undrained-writer', 'no')";
}
$result = $wpdb->query( $sql );
if ( 1 !== $result || ! empty( $wpdb->last_error ) ) { exit( 5 ); }
echo 'OWNED_RACE_APPLIED' . PHP_EOL;
