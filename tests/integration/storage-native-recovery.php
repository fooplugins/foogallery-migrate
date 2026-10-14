<?php
/** Included only after real base/core output has committed and its writer drained.
 * Deterministic handled SQL exceptions, NOT PHP death or stale-owner recovery.
 * This fixture is excluded from production packages.
 */
if ( ! defined( 'FOOGM_STORAGE_TEST_DISPOSABLE' ) || true !== FOOGM_STORAGE_TEST_DISPOSABLE || ! isset( $scenario, $target, $response, $assert ) ) { throw new RuntimeException( 'Owned native output fixture required.' ); }
require_once __DIR__ . '/storage-pinned-fixture.php';
fgm_fixture_pin_gate();
$phase = substr( $scenario, strlen( 'base-recovery-' ) );
$next_phase = array( 'copying' => 'verified', 'verified' => 'flipped', 'flipped' => 'stable' );
if ( ! isset( $next_phase[$phase] ) ) { throw new RuntimeException( 'Unknown recovery boundary.' ); }
$fired = false;
$interrupt = function ( $sql ) use ( &$fired, $next_phase, $phase ) {
	$set = stripslashes( substr( $sql, 0, (int) strpos( $sql, ' WHERE ' ) ) );
	if ( ! $fired && 0 === strpos( $sql, 'UPDATE' ) && false !== strpos( $sql, "'" . \FooPlugins\FooGalleryMigrate\StorageAuthority::CONTROL_OPTION . "'" ) && false !== strpos( $set, '"phase":"' . $next_phase[$phase] . '"' ) ) {
		$fired = true; throw new RuntimeException( 'Owned handled conversion interruption.' );
	}
	return $sql;
};
add_filter( 'query', $interrupt );
try { $failed = $response( $target, $blog ); }
finally { remove_filter( 'query', $interrupt ); }
$label = $mode . '/' . $phase;
$assert( $fired && false === $failed['success'], 'native output public switch refuses interrupted conversion ' . $label );
$controls = new \FooPlugins\FooGalleryMigrate\StorageControl( $observer, $blog );
$pending = \FooPlugins\FooGalleryMigrate\StorageControl::decode( $controls->read( \FooPlugins\FooGalleryMigrate\StorageAuthority::CONTROL_OPTION ), $blog );
$assert( $phase === $pending['phase'] && ( 'flipped' === $phase ? $target : $mode ) === $pending['backend'], 'pending public conversion records exact authoritative phase ' . $label );
$assert( null === $controls->read( \FooPlugins\FooGalleryMigrate\StorageAuthority::MAINTENANCE_OPTION ) && array() === $writers(), 'handled interruption proves owner release and writer drain without deleting evidence ' . $label );
$assert( is_wp_error( \FooPlugins\FooGalleryMigrate\StorageAuthority::runtime_record() ) && is_wp_error( \FooPlugins\FooGalleryMigrate\StorageAuthority::option_mode_error() ), 'pending conversion permits no runtime or option fallback ' . $label );
try { new \FooPlugins\FooGalleryMigrate\MigratorEngine(); throw new LogicException( 'Pending authority admitted engine.' ); }
catch ( RuntimeException $error ) { $assert( true, 'fresh engine refuses pending public conversion ' . $label ); }

// Capture every migration-control/source/backup option and all shared rows,
// including other blogs. Denied recovery must not acquire ownership or alter them.
$capture = function () use ( $observer, $names, $tables ) {
	$rows = array();
	foreach ( $names as $name ) { $rows[$name] = $observer->get_results( $observer->prepare( 'SELECT * FROM `' . $observer->options . '` WHERE option_name=%s', $name ), ARRAY_A ); }
	foreach ( $tables as $table ) { $rows[$table] = $observer->get_results( 'SELECT * FROM `' . $table . '` ORDER BY id', ARRAY_A ); }
	return $rows;
};
$before_denial = $capture();
$user = get_current_user_id(); wp_set_current_user( 0 );
try { $denied = $response( $target, $blog, 'recover' ); }
finally { wp_set_current_user( $user ); }
$assert( false === $denied['success'] && 'foogallery_migrate_storage_forbidden' === $denied['data']['code'] && $before_denial === $capture(), 'anonymous public recovery preserves native output and every state byte ' . $label );
$denied = $response( $target, $blog, 'recover', 'table' === $target ? 'option_import' : 'table_export' );
$assert( false === $denied['success'] && 'foogallery_migrate_storage_invalid_nonce' === $denied['data']['code'] && $before_denial === $capture(), 'switch-purpose nonce cannot recover native output ' . $label );
$denied = $response( $target, $blog, 'recover', null, $blog + 1 );
$assert( false === $denied['success'] && 'foogallery_migrate_storage_invalid_request' === $denied['data']['code'] && $before_denial === $capture(), 'cross-blog public recovery preserves complete scoped state ' . $label );

// Post-flip recovery must use the recorded destination, not stale retained data.
// Only the invocation-owned NONauthoritative backend is deliberately changed.
if ( 'flipped' === $phase ) {
	if ( 'table' === $mode ) {
		( new \FooPlugins\FooGalleryMigrate\LegacyTableBackend( new \FooPlugins\FooGalleryMigrate\LegacyTransactionConnection( $observer ), $blog ) )->mutate_scalars( array( 'fixture' => 'inactive-recovery-fixture' ) );
	} else {
		$assert( 1 === $observer->query( $observer->prepare( 'UPDATE `' . $observer->options . '` SET option_value=%s WHERE option_name=%s', serialize( array( 'fixture' => 'inactive-recovery-fixture' ) ), \FooPlugins\FooGalleryMigrate\StorageAuthority::STORAGE_BACKUP_OPTION ) ), 'owned inactive backup changed after flip ' . $label );
	}
	$inactive = $capture();
}

// Separate PHP request: no parent engine, gate, object cache or transport is
// adopted. Authorization is exercised through the real public admin-post hook.
$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/storage-native-recovery-worker.php' ) . ' ' . escapeshellarg( rtrim( ABSPATH, '/' ) );
$process = proc_open( $command, array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Could not start owned public recovery request.' ); }
fwrite( $pipes[0], json_encode( array( 'blog_id' => $blog, 'plugin' => $root, 'target' => $target, 'identifier' => $object->unique_identifier(), 'migrated_id' => (int) $object->migrated_id ) ) ); fclose( $pipes[0] );
$out = stream_get_contents( $pipes[1] ); fclose( $pipes[1] ); $err = stream_get_contents( $pipes[2] ); fclose( $pipes[2] ); $rc = proc_close( $process );
$worker = json_decode( $out, true );
$assert( 0 === $rc && '' === $err && is_array( $worker ) && isset( $worker['pid'], $worker['response'], $worker['history_retained'], $worker['progress'] ) && $worker['pid'] !== getmypid(), 'independent native public recovery process completed ' . $label );
$result = $worker['response'];
$assert( true === $result['success'] && $target === $result['data']['backend'] && 'stable' === $result['data']['phase'] && $blog === $result['data']['blog_id'], 'fresh purpose-authorized public recovery reaches only scoped recorded destination ' . $label );
$assert( true === $worker['history_retained'] && 100 === $worker['progress'], 'separate fresh engine retains real completed output and checkpoint ' . $label );
$stable = \FooPlugins\FooGalleryMigrate\StorageControl::decode( $controls->read( \FooPlugins\FooGalleryMigrate\StorageAuthority::CONTROL_OPTION ), $blog );
$assert( $stable['logical_digest'] === $pending['logical_digest'] && $stable['epoch'] === $pending['epoch'] + ( 'flipped' === $phase ? 0 : 1 ) && \FooPlugins\FooGalleryMigrate\StorageControl::runtime_ready( $stable, $controls->read( \FooPlugins\FooGalleryMigrate\StorageAuthority::AUTHORITY_OPTION ) ), 'independent committed recovery parity epoch and sentinel proof ' . $label );
$assert( null === $controls->read( \FooPlugins\FooGalleryMigrate\StorageAuthority::MAINTENANCE_OPTION ) && array() === $writers(), 'fresh public recovery releases only its own admission ' . $label );
if ( 'flipped' === $phase ) {
	$after_recovery = $capture();
	if ( 'table' === $mode ) { foreach ( $tables as $table ) { $assert( $inactive[$table] === $after_recovery[$table], 'post-flip public recovery neither trusts nor rewrites inactive table ' . $label ); } }
	else { $name = \FooPlugins\FooGalleryMigrate\StorageAuthority::STORAGE_BACKUP_OPTION; $assert( $inactive[$name] === $after_recovery[$name], 'post-flip public recovery neither trusts nor rewrites inactive backup ' . $label ); }
}
wp_cache_flush(); unset( $GLOBALS['foogallery_migrate_engine_instance'] );
