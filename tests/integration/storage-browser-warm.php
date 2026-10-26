<?php
/** Warm ordinary authenticated admin JS/SDK side effects before DB snapshots. */
if ( ! defined( 'FOOGM_STORAGE_TEST_DISPOSABLE' ) || true !== FOOGM_STORAGE_TEST_DISPOSABLE || ! defined( 'FOOGM_BROWSER_MODULE' ) ) { throw new RuntimeException( 'Owned browser runner required.' ); }
$token = WP_Session_Tokens::get_instance( 1 )->create( time() + 600 ); $host = parse_url( home_url( '/' ), PHP_URL_HOST );
$cookies = array(); foreach ( array( SECURE_AUTH_COOKIE => 'secure_auth', LOGGED_IN_COOKIE => 'logged_in' ) as $name => $scheme ) { $cookies[] = array( 'name' => $name, 'value' => wp_generate_auth_cookie( 1, time() + 600, $scheme, $token ), 'domain' => $host, 'path' => '/', 'secure' => true, 'httpOnly' => true, 'sameSite' => 'Lax' ); }
$private = tempnam( getenv( 'TMPDIR' ), 'fgm-warm-' ); if ( false === $private ) { throw new RuntimeException( 'No private config.' ); } chmod( $private, 0600 );
try {
	$config = array( 'warm' => true, 'playwright' => FOOGM_BROWSER_MODULE, 'url' => admin_url( 'options-general.php' ), 'cookies' => $cookies );
	if ( false === file_put_contents( $private, wp_json_encode( $config ) ) ) { throw new RuntimeException( 'Private browser configuration failed.' ); }
	$pipes = array(); $process = proc_open( array( '/usr/bin/env', 'node', __DIR__ . '/../tools/storage-admin-browser.cjs', $private ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
	if ( ! is_resource( $process ) ) { throw new RuntimeException( 'No browser warmer.' ); }
	$output = stream_get_contents( $pipes[1] ); $error = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] ); $exit = proc_close( $process );
	if ( 0 !== $exit || empty( json_decode( trim( $output ), true )['warm'] ) ) { throw new RuntimeException( 'Browser warm failed: ' . $error ); }
} finally { unlink( $private ); }
