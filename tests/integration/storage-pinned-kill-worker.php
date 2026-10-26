<?php
/** Kill only the supplied, live owner session inside an invocation-owned site. */
if ( 'cli' !== PHP_SAPI || 2 !== $argc || ! is_file( $argv[1] . '/wp-load.php' ) ) { exit( 2 ); }
$input = json_decode( stream_get_contents( STDIN, 2048 ), true );
if ( ! is_array( $input ) || array_keys( $input ) !== array( 'blog_id', 'thread', 'owner' ) ||
	! is_int( $input['blog_id'] ) || $input['blog_id'] < 1 || ! is_int( $input['thread'] ) || $input['thread'] < 1 ||
	! is_string( $input['owner'] ) || strlen( $input['owner'] ) > 512 ) { exit( 3 ); }
define( 'WP_USE_THEMES', false );
require $argv[1] . '/wp-load.php';
if ( ! preg_match( '/^fgm-preflight-[a-z0-9-]+\.localhost$/D', basename( dirname( rtrim( ABSPATH, '/' ) ) ) ) ||
	0 !== strpos( DB_NAME, 'cove_fgm_preflight_' ) ) { exit( 4 ); }
if ( is_multisite() && get_current_blog_id() !== $input['blog_id'] ) { switch_to_blog( $input['blog_id'] ); }
if ( get_current_blog_id() !== $input['blog_id'] ) { exit( 5 ); }
global $wpdb;
$name = 'foogallery_migrate_storage_maintenance';
$owner = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `' . $wpdb->options . '` WHERE option_name = %s', $name ) );
$database = $wpdb->get_var( $wpdb->prepare( 'SELECT DB FROM information_schema.PROCESSLIST WHERE ID = %d', $input['thread'] ) );
if ( $owner !== $input['owner'] || DB_NAME !== $database || $input['thread'] === (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' ) ) { exit( 6 ); }
if ( false === $wpdb->query( 'KILL CONNECTION ' . $input['thread'] ) || ! empty( $wpdb->last_error ) ) { exit( 7 ); }
echo 'OWNED_SESSION_KILLED' . PHP_EOL;
