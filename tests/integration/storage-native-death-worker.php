<?php
/** Invocation-owned modern native public receiver; parent kills only this PID. */
if ( 'cli' !== PHP_SAPI || 2 !== $argc || ! is_file( $argv[1] . '/wp-load.php' ) || ! preg_match( '/^fgm-preflight-[a-z0-9-]+\.localhost$/D', basename( dirname( $argv[1] ) ) ) ) { exit( 2 ); }
$input = json_decode( fgets( STDIN, 4096 ), true );
if ( ! is_array( $input ) || array_keys( $input ) !== array( 'blog_id', 'plugin', 'target', 'phase', 'mode', 'migrated_id' ) || ! is_int( $input['blog_id'] ) || $input['blog_id'] < 1 || ! is_string( $input['plugin'] ) ||
	! in_array( $input['target'], array( 'option', 'table' ), true ) || ! in_array( $input['phase'], array( 'copying', 'verified', 'flipped', 'stable' ), true ) || ! in_array( $input['mode'], array( 'hold', 'probe' ), true ) || ! is_int( $input['migrated_id'] ) || $input['migrated_id'] < 1 ||
	! in_array( basename( $input['plugin'] ), array( 'foogallery-migrate-source', 'foogallery-migrate-package' ), true ) || realpath( dirname( $input['plugin'] ) ) !== realpath( $argv[1] . '/wp-content/plugins' ) || ! is_file( $input['plugin'] . '/migrate.php' ) ) { exit( 3 ); }
define( 'WP_USE_THEMES', false ); define( 'DISABLE_WP_CRON', true ); define( 'DOING_AJAX', true );
require $argv[1] . '/wp-load.php';
if ( 0 !== strpos( DB_NAME, 'cove_fgm_preflight_' ) || realpath( ABSPATH ) !== realpath( $argv[1] ) ) { exit( 4 ); }
if ( is_multisite() && get_current_blog_id() !== $input['blog_id'] ) { switch_to_blog( $input['blog_id'] ); }
if ( get_current_blog_id() !== $input['blog_id'] || ( is_multisite() && ! get_site( $input['blog_id'] ) ) ) { exit( 5 ); }
wp_set_current_user( 1 );
if ( ! current_user_can( 'manage_options' ) || ( is_multisite() && ! is_super_admin() ) ) { exit( 6 ); }
require_once $input['plugin'] . '/migrate.php';
if ( ! is_admin() ) { new \FooPlugins\FooGalleryMigrate\Init(); }
use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\StorageMaintenanceGate;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;

class FgmNativeDeathConnection extends LegacyTransactionConnection {
	public $phase;
	public function query( $sql ) {
		$next = array( 'copying' => 'verified', 'verified' => 'flipped', 'flipped' => 'stable' );
		$set = stripslashes( substr( $sql, 0, (int) strpos( $sql, ' WHERE ' ) ) );
		$phase_cas = isset( $next[$this->phase] ) && 0 === strpos( $sql, 'UPDATE' ) && false !== strpos( $sql, "'" . StorageAuthority::CONTROL_OPTION . "'" ) && false !== strpos( $set, '"phase":"' . $next[$this->phase] . '"' );
		$stable_release = 'stable' === $this->phase && 0 === strpos( $sql, 'DELETE FROM' ) && false !== strpos( $sql, "'" . StorageAuthority::MAINTENANCE_OPTION . "'" );
		if ( $phase_cas || $stable_release ) {
			$thread = (int) $this->get_var( 'SELECT CONNECTION_ID()' );
			if ( $thread < 1 || ! $this->is_current() ) { throw new RuntimeException( 'Owned session unavailable.' ); }
			register_shutdown_function( function () { fwrite( STDOUT, "SHUTDOWN_HANDLER_RAN\n" ); } );
			// Public wp_send_json buffering must not swallow the handshake.
			fwrite( STDOUT, json_encode( array( 'pid' => getmypid(), 'thread' => $thread, 'phase' => $this->phase, 'database' => DB_NAME ) ) . "\n" ); fflush( STDOUT );
			stream_set_timeout( STDIN, 30 ); fgets( STDIN, 128 );
			throw new RuntimeException( 'Parent did not kill the owned receiver.' );
		}
		return parent::query( $sql );
	}
}
if ( 'hold' === $input['mode'] ) {
	global $wpdb;
	$pin = new FgmNativeDeathConnection( $wpdb ); $pin->phase = $input['phase'];
	$gate = StorageMaintenanceGate::current();
	$property = new ReflectionProperty( $gate, 'connection' ); $property->setValue( $gate, $pin );
}
// Explicitly synthetic fixture calibration, never shipped production approval.
$profile = function () { return array( 'version' => 1, 'status' => 'approved', 'fingerprint' => \FooPlugins\FooGalleryMigrate\StorageCalibration::fingerprint(), 'evidence_sha256' => str_repeat( 'a', 64 ), 'policy' => array( 'max_raw_bytes' => 1048576, 'amplification' => 64, 'reserve_bytes' => 33554432 ) ); };
add_filter( 'foogallery_migrate_storage_calibration', $profile );
$die = function () { return function () { throw new RuntimeException( 'owned-json-response' ); }; };
add_filter( 'wp_die_ajax_handler', $die ); $_SERVER['REQUEST_METHOD'] = 'POST';
$recover = 'probe' === $input['mode'];
$purpose = $recover ? 'recover' : ( 'table' === $input['target'] ? 'option_import' : 'table_export' );
$_POST = array( 'action' => 'foogallery_migrate_storage', 'operation' => $recover ? 'recover' : 'switch', 'blog_id' => (string) $input['blog_id'], 'scope' => 'site', 'target' => $input['target'], 'confirmation' => $recover ? 'recover-storage' : 'switch-to-' . $input['target'], 'nonce' => wp_create_nonce( 'foogallery_migrate_storage_' . $purpose . '_' . $input['blog_id'] ) );
ob_start();
try { do_action( 'admin_post_foogallery_migrate_storage' ); throw new RuntimeException( 'Missing public JSON response.' ); }
catch ( RuntimeException $error ) { if ( 'owned-json-response' !== $error->getMessage() ) { throw $error; } }
finally { $raw = ob_get_clean(); remove_filter( 'wp_die_ajax_handler', $die ); remove_filter( 'foogallery_migrate_storage_calibration', $profile ); }
$response = json_decode( $raw, true );
if ( ! $recover || ! is_array( $response ) || false !== $response['success'] || 'foogallery_migrate_storage_busy' !== $response['data']['code'] ) { exit( 7 ); }
$blocked = false;
try { new \FooPlugins\FooGalleryMigrate\MigratorEngine(); }
catch ( RuntimeException $error ) { $blocked = true; }
$post = get_post( $input['migrated_id'] );
$retained = $post && 'foogallery' === $post->post_type && is_array( get_post_meta( $post->ID, '_foogallery_attachments', true ) );
if ( ! $blocked || ! is_wp_error( StorageAuthority::runtime_record() ) || ! is_wp_error( StorageAuthority::option_mode_error() ) || ! $retained ) { exit( 8 ); }
fwrite( STDOUT, json_encode( array( 'pid' => getmypid(), 'response' => $response, 'runtime_blocked' => $blocked, 'output_retained' => (bool) $retained ) ) . "\n" );
