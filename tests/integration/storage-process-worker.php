<?php
/** Invocation-owned disposable PHP worker. Parent alone kills its proc_open PID. */
if ( 'cli' !== PHP_SAPI || 2 !== $argc || ! is_file( $argv[1] . '/wp-load.php' ) ||
	! preg_match( '/^fgm-preflight-[a-z0-9-]+\.localhost$/D', basename( dirname( $argv[1] ) ) ) ) { exit( 2 ); }
$input = json_decode( fgets( STDIN, 4096 ), true );
$points = array( 'option-writer', 'table-before-commit', 'table-after-commit', 'copying', 'snapshot-commit', 'flipped', 'source-deleted', 'stable' );
if ( ! is_array( $input ) || array_keys( $input ) !== array( 'blog_id', 'plugin', 'mode', 'point' ) ||
	! is_int( $input['blog_id'] ) || $input['blog_id'] < 1 || ! is_string( $input['plugin'] ) ||
	! in_array( $input['mode'], array( 'hold', 'probe' ), true ) || ! in_array( $input['point'], $points, true ) ||
	! in_array( basename( $input['plugin'] ), array( 'foogallery-migrate-source', 'foogallery-migrate-package' ), true ) ||
	realpath( dirname( $input['plugin'] ) ) !== realpath( $argv[1] . '/wp-content/plugins' ) ||
	! is_file( $input['plugin'] . '/migrate.php' ) ) { exit( 3 ); }
define( 'WP_USE_THEMES', false );
define( 'DISABLE_WP_CRON', true );
require $argv[1] . '/wp-load.php';
if ( 0 !== strpos( DB_NAME, 'cove_fgm_preflight_' ) || realpath( ABSPATH ) !== realpath( $argv[1] ) ) { exit( 4 ); }
if ( is_multisite() && get_current_blog_id() !== $input['blog_id'] ) { switch_to_blog( $input['blog_id'] ); }
if ( get_current_blog_id() !== $input['blog_id'] || ( is_multisite() && ! get_site( $input['blog_id'] ) ) ) { exit( 5 ); }
wp_set_current_user( 1 );
if ( ! current_user_can( 'manage_options' ) || ( is_multisite() && ! is_super_admin() ) ) { exit( 6 ); }
require_once $input['plugin'] . '/migrate.php';

use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\StorageConversion;
use FooPlugins\FooGalleryMigrate\StorageMaintenanceGate;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;
use FooPlugins\FooGalleryMigrate\MigratorEngine;

class FgmProcessDeathConnection extends LegacyTransactionConnection {
	public $point = null;
	public function hold() {
		$thread = (int) $this->get_var( 'SELECT CONNECTION_ID()' );
		if ( $thread < 1 || ! $this->is_current() ) { throw new RuntimeException( 'Cannot identify owned worker session.' ); }
		// The handler makes a normal shutdown distinguishable from SIGKILL.
		register_shutdown_function( function () { echo "SHUTDOWN_HANDLER_RAN\n"; } );
		echo json_encode( array( 'pid' => getmypid(), 'thread' => $thread, 'point' => $this->point, 'database' => DB_NAME ) ) . "\n";
		flush(); stream_set_timeout( STDIN, 30 );
		fgets( STDIN, 128 );
		throw new RuntimeException( 'Worker was not killed at the requested boundary.' );
	}
	public function query( $sql ) {
		if ( 'table-before-commit' === $this->point && 'COMMIT' === $sql ) { $this->hold(); }
		$result = parent::query( $sql );
		if ( null === $this->point || false === $result || ! empty( $this->last_error ) ) { return $result; }
		if ( in_array( $this->point, array( 'table-after-commit', 'snapshot-commit' ), true ) && 'COMMIT' === $sql ) { $this->hold(); }
		if ( 'source-deleted' === $this->point && 1 === $result && 0 === strpos( $sql, 'DELETE FROM' ) && false !== strpos( $sql, "'" . FOOGALLERY_MIGRATE_OPTION_DATA . "'" ) ) { $this->hold(); }
		if ( in_array( $this->point, array( 'copying', 'flipped', 'stable' ), true ) && 1 === $result &&
			( 0 === strpos( $sql, 'INSERT INTO' ) || 0 === strpos( $sql, 'UPDATE' ) ) && false !== strpos( $sql, "'" . StorageAuthority::CONTROL_OPTION . "'" ) ) {
			$raw = $this->get_var( $this->prepare( 'SELECT option_value FROM `' . $this->prefix . 'options` WHERE option_name = %s', array( StorageAuthority::CONTROL_OPTION ) ) );
			$record = json_decode( $raw, true );
			if ( is_array( $record ) && isset( $record['phase'] ) && $this->point === $record['phase'] ) { $this->hold(); }
		}
		return $result;
	}
}
$checks = array();
$check = function ( $yes, $label ) use ( &$checks ) { if ( ! $yes ) { throw new RuntimeException( 'FAIL: ' . $label ); } $checks[] = $label; };
$blog = (int) get_current_blog_id();
$policy = array( 'max_raw_bytes' => 1048576, 'amplification' => 64, 'reserve_bytes' => 33554432 );
$request = function ( $recover = false ) use ( $blog ) {
	return array( 'blog_id' => $blog, 'scope' => 'site', 'target' => 'table', 'confirmation' => $recover ? 'recover-storage' : 'switch-to-table',
		'nonce' => wp_create_nonce( 'foogallery_migrate_storage_' . ( $recover ? 'recover' : 'option_import' ) . '_' . $blog ) );
};
if ( 'probe' === $input['mode'] ) {
	$conversion = new StorageConversion( $policy ); $gate = StorageMaintenanceGate::current();
	$writer = in_array( $input['point'], array( 'option-writer', 'table-before-commit', 'table-after-commit' ), true );
	$check( is_wp_error( $conversion->switch_backend( $request() ) ), 'authorized switch cannot ignore dead request evidence' );
	$check( is_wp_error( $conversion->recover( $request( true ) ) ), 'authorized recovery cannot infer drain from dead or aged owner' );
	$acquired = $gate->acquire_maintenance();
	$check( $writer ? true === $acquired : is_wp_error( $acquired ), 'fresh request may claim only absent maintenance, never take over an existing owner' );
	$check( is_wp_error( $gate->maintenance_ready( function () { return true; } ) ), 'even an affirmative engine verdict cannot override retained dead marker' );
	$check( is_wp_error( $gate->begin_writer() ), 'maintenance evidence bars new writer admission' );
	$check( is_wp_error( StorageAuthority::runtime_record() ) && is_wp_error( StorageAuthority::option_mode_error() ), 'behind maintenance there is no authoritative runtime or option fallback' );
	try { new MigratorEngine(); throw new LogicException( 'Fresh engine ignored maintenance.' ); }
	catch ( RuntimeException $error ) { $check( true, 'public fresh engine refuses guarded runtime' ); }
	$check( $writer ? true === $gate->release_maintenance() : is_wp_error( $gate->release_maintenance() ), 'fresh request releases only its newly owned maintenance, never dead owner' );
	echo json_encode( array( 'status' => 'blocked', 'pid' => getmypid(), 'checks' => $checks ) ) . "\n";
	exit( 0 );
}
// Test-only injection reuses the actual gate/backend adapter; no alternative
// product selector, synthetic control record or fabricated commit is used.
global $wpdb;
$pin = new FgmProcessDeathConnection( $wpdb ); $gate = StorageMaintenanceGate::current();
$property = new ReflectionProperty( $gate, 'connection' ); $property->setValue( $gate, $pin );
if ( in_array( $input['point'], array( 'option-writer', 'table-before-commit', 'table-after-commit' ), true ) ) {
	if ( 'option-writer' !== $input['point'] ) { $check( ! is_wp_error( ( new StorageConversion( $policy ) )->switch_backend( $request() ) ), 'actual conversion before table writer schedule' ); }
	$engine = foogallery_migrate_migrator_instance();
	$check( true === $engine->set_migrator_setting( 'checkpoint', 'admitted' ), 'actual public writer admission and first mutation' );
	$pin->point = $input['point'];
	if ( 'option-writer' === $input['point'] ) { $pin->hold(); }
	$engine->set_migrator_setting( 'checkpoint', 'committed-without-receipt' );
} else {
	$pin->point = $input['point'];
	( new StorageConversion( $policy ) )->switch_backend( $request() );
}
throw new RuntimeException( 'Requested native death boundary was not reached.' );
