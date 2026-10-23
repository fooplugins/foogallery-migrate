<?php
/** Single owned CLI receiver. The parent kills this process before the verified->flipped CAS. */
if ( 'cli' !== PHP_SAPI || 3 !== $argc || ! preg_match( '~^/(?:private/)?tmp/foogallery-migrate-r0\.[A-Za-z0-9]+$~D', $argv[1] ) ||
	! preg_match( '/^[a-f0-9]{64}$/D', $argv[2] ) ) { exit( 2 ); }
$site = realpath( $argv[1] );
$manifest_path = $site ? $site . '/.fgm-r0-owner.json' : '';
$manifest = is_file( $manifest_path ) && ! is_link( $manifest_path ) ? json_decode( file_get_contents( $manifest_path ), true ) : null;
if ( ! $site || ! preg_match( '~^/(?:private/)?tmp/foogallery-migrate-r0\.[A-Za-z0-9]+$~D', $site ) ||
	! is_array( $manifest ) || ! isset( $manifest['kind'], $manifest['site'], $manifest['database'], $manifest['host'],
	$manifest['repo'], $manifest['container'], $manifest['container_id'] ) ||
	$manifest['kind'] !== 'foogallery-migrate-r0-v1' || realpath( $manifest['site'] ) !== $site ||
	! preg_match( '/^fgm_r0_[a-z0-9]+$/D', $manifest['database'] ) ||
	! preg_match( '/^127\.0\.0\.1:[0-9]{2,5}$/D', $manifest['host'] ) ||
	! preg_match( '/^fgm-r0-[A-Za-z0-9]+$/D', $manifest['container'] ) ||
	! preg_match( '/^[a-f0-9]{64}$/D', $manifest['container_id'] ) ||
	realpath( dirname( __DIR__, 2 ) ) !== realpath( $manifest['repo'] ) ||
	realpath( $site . '/wp-content/plugins/foogallery-migrate' ) !== realpath( $manifest['repo'] ) ||
	! is_file( $site . '/wp-load.php' ) ) { exit( 3 ); }
$container = array(); $code = 0;
exec( 'docker inspect --format ' . escapeshellarg( '{{.Id}} {{index .Config.Labels "foogallery-migrate-r0"}} {{.State.Running}}' ) .
	' ' . escapeshellarg( $manifest['container'] ) . ' 2>/dev/null', $container, $code );
if ( 0 !== $code || count( $container ) !== 1 || trim( $container[0] ) !== $manifest['container_id'] . ' owned true' ) { exit( 4 ); }
define( 'WP_USE_THEMES', false ); define( 'DISABLE_WP_CRON', true ); define( 'WP_MAX_MEMORY_LIMIT', '256M' );
ini_set( 'memory_limit', '256M' ); require $site . '/wp-load.php';
global $wpdb;
if ( is_multisite() || realpath( ABSPATH ) !== $site || DB_NAME !== $manifest['database'] || DB_HOST !== $manifest['host'] ||
	$wpdb->get_var( 'SELECT DATABASE()' ) !== $manifest['database'] || $wpdb->last_error || ini_get( 'memory_limit' ) !== '256M' ) { exit( 5 ); }
wp_set_current_user( 1 );
if ( ! current_user_can( 'manage_options' ) || ! class_exists( 'FooPlugins\\FooGalleryMigrate\\StorageCli' ) ) { exit( 6 ); }
$journal_name = 'fgm_assisted_recovery_v1';
$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `' . $wpdb->options . '` WHERE option_name=%s', $journal_name ) );
$journal = is_string( $raw ) ? maybe_unserialize( $raw ) : null;
if ( ! is_array( $journal ) || hash( 'sha256', $raw ) !== $argv[2] || $journal['phase'] !== 'armed' ||
	$journal['identity'] !== hash( 'sha256', $site . '|' . $manifest['database'] . '|' . $manifest['container_id'] ) ||
	! isset( $journal['source_files'] ) || ! is_array( $journal['source_files'] ) ) { exit( 7 ); }
foreach ( $journal['source_files'] as $file => $hash ) {
	$path = $manifest['repo'] . '/' . $file;
	if ( ! is_file( $path ) || is_link( $path ) || hash_file( 'sha256', $path ) !== $hash ) { exit( 8 ); }
}
if ( $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `' . $wpdb->options . '` WHERE option_name=%s',
	\FooPlugins\FooGalleryMigrate\StorageAuthority::MAINTENANCE_OPTION ) ) !== null || $wpdb->last_error ) { exit( 9 ); }
$writer = $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM `' . $wpdb->options . '` WHERE option_name LIKE %s LIMIT 1',
	$wpdb->esc_like( \FooPlugins\FooGalleryMigrate\StorageMaintenanceGate::WRITER_PREFIX ) . '%' ) );
if ( null !== $writer || $wpdb->last_error ) { exit( 9 ); }
$control_raw = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `' . $wpdb->options . '` WHERE option_name=%s',
	\FooPlugins\FooGalleryMigrate\StorageAuthority::CONTROL_OPTION ) );
$control = \FooPlugins\FooGalleryMigrate\StorageControl::decode( $control_raw, get_current_blog_id() );
if ( ! is_array( $control ) || $control['phase'] !== 'verified' || $control['backend'] !== 'option' ||
	$control['to'] !== 'table' || hash( 'sha256', $control_raw ) !== $journal['control_sha256'] ) { exit( 10 ); }
class FgmAssistedHoldConnection extends \FooPlugins\FooGalleryMigrate\LegacyTransactionConnection {
	public function query( $sql ) {
		$set = stripslashes( substr( $sql, 0, (int) strpos( $sql, ' WHERE ' ) ) );
		if ( 0 === strpos( $sql, 'UPDATE' ) && false !== strpos( $sql, "'" . \FooPlugins\FooGalleryMigrate\StorageAuthority::CONTROL_OPTION . "'" ) &&
			false !== strpos( $set, '"phase":"flipped"' ) ) {
			$thread = (int) $this->get_var( 'SELECT CONNECTION_ID()' );
			if ( $thread < 1 || ! $this->is_current() ) { throw new RuntimeException( 'Owned SQL session lost.' ); }
			register_shutdown_function( function () { fwrite( STDOUT, "SHUTDOWN_HANDLER_RAN\n" ); } );
			fwrite( STDOUT, json_encode( array( 'pid' => getmypid(), 'thread' => $thread, 'database' => DB_NAME,
				'phase' => 'verified', 'next' => 'flipped' ) ) . "\n" ); fflush( STDOUT );
			stream_set_timeout( STDIN, 30 ); fgets( STDIN, 128 );
			throw new RuntimeException( 'Parent did not kill the exact held receiver.' );
		}
		return parent::query( $sql );
	}
}
$gate = \FooPlugins\FooGalleryMigrate\StorageMaintenanceGate::current();
$pin = new FgmAssistedHoldConnection( $wpdb );
$property = new ReflectionProperty( $gate, 'connection' ); $property->setValue( $gate, $pin );
$result = \FooPlugins\FooGalleryMigrate\StorageCli::execute( 'recover', array( 'target' => 'table', 'confirm' => 'recover-storage' ) );
fwrite( STDERR, 'Unexpected public recovery return: ' . ( is_wp_error( $result ) ? $result->get_error_code() : 'success' ) . "\n" );
exit( 11 );
