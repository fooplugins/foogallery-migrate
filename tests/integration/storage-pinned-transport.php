<?php
/** Native pinned-coordinator session-loss receipts, NOT hard-PHP-death proof. */
if ( ! defined( 'ABSPATH' ) || ! defined( 'FOOGM_STORAGE_TEST_DISPOSABLE' ) || true !== FOOGM_STORAGE_TEST_DISPOSABLE || ! defined( 'FOOGM_STORAGE_TEST_ROOT' ) ) { throw new RuntimeException( 'Disposable fixture required.' ); }
require_once FOOGM_STORAGE_TEST_ROOT . '/migrate.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\StorageControl;
use FooPlugins\FooGalleryMigrate\StorageConversion;
use FooPlugins\FooGalleryMigrate\StorageMaintenanceGate;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;

class FgmKilledTransport extends LegacyTransactionConnection {
	public $options;
	public $before;
	public function query( $sql ) { if ( is_callable( $this->before ) ) { call_user_func( $this->before, $sql, false ); } return parent::query( $sql ); }
	public function get_results( $sql, $format ) { if ( is_callable( $this->before ) ) { call_user_func( $this->before, $sql, true ); } return parent::get_results( $sql, $format ); }
}

global $wpdb, $wp_version;
$original = $wpdb; $blog = (int) get_current_blog_id();
// An independent observer never uses the victim's reconnect/readback path.
$observer_wpdb = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$observer_wpdb->prefix = $wpdb->prefix; $observer_wpdb->base_prefix = $wpdb->base_prefix;
$observer = new FgmKilledTransport( $observer_wpdb ); $observer->options = $wpdb->options;
$schema = new LegacyStorageSchema( $observer_wpdb ); $tables = array_keys( $schema->definitions() );
$names = array( FOOGALLERY_MIGRATE_OPTION_DATA, StorageAuthority::CONTROL_OPTION, StorageAuthority::AUTHORITY_OPTION, StorageAuthority::MAINTENANCE_OPTION, StorageAuthority::STORAGE_BACKUP_OPTION );
foreach ( $tables as $table ) {
	if ( null !== $observer->get_var( $observer->prepare( 'SHOW TABLES LIKE %s', $observer->esc_like( $table ) ) ) ) { throw new RuntimeException( 'Refusing existing tables.' ); }
}
foreach ( $names as $name ) {
	if ( null !== $observer->get_var( $observer->prepare( 'SELECT 1 FROM `' . $observer->options . '` WHERE option_name = %s', $name ) ) ) { throw new RuntimeException( 'Refusing existing storage evidence.' ); }
}
$checks = array();
$check = function ( $yes, $label ) use ( &$checks ) { if ( ! $yes ) { throw new RuntimeException( 'FAIL: ' . $label ); } $checks[] = $label; };
$reset = function () use ( $observer, $names, $tables, $blog ) {
	foreach ( $names as $name ) { $observer->query( $observer->prepare( 'DELETE FROM `' . $observer->options . '` WHERE option_name = %s', $name ) ); wp_cache_delete( $name, 'options' ); }
	wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' );
	foreach ( array_reverse( $tables ) as $table ) { $observer->query( $observer->prepare( 'DELETE FROM `' . $table . '` WHERE blog_id = %d', $blog ) ); }
	unset( $GLOBALS['foogallery_migrate_engine_instance'] );
};
$raw = function () use ( $observer, $names, $tables, $blog ) {
	$result = array();
	foreach ( $names as $name ) { $result[$name] = $observer->get_results( $observer->prepare( 'SELECT option_name, option_value, autoload FROM `' . $observer->options . '` WHERE option_name = %s', $name ), ARRAY_A ); }
	foreach ( $tables as $table ) { $result[$table] = $observer->get_results( $observer->prepare( 'SELECT * FROM `' . $table . '` WHERE blog_id = %d ORDER BY id', $blog ), ARRAY_A ); }
	if ( ! empty( $observer->last_error ) ) { throw new RuntimeException( 'Observer failed.' ); }
	return $result;
};
$request = function () use ( $blog ) { return array( 'blog_id' => $blog, 'scope' => 'site', 'target' => 'table', 'confirmation' => 'switch-to-table', 'nonce' => wp_create_nonce( 'foogallery_migrate_storage_option_import_' . $blog ) ); };
$policy = array( 'max_raw_bytes' => 1048576, 'amplification' => 64, 'reserve_bytes' => 33554432 );
$created = array(); $capture = null;
try {
	foreach ( $schema->statements() as $index => $ddl ) { $created[] = $tables[$index]; dbDelta( $ddl ); }
	$check( $schema->verify(), 'fixture schema verified' );
	foreach ( array( 'owner-readback', 'copying-before-write', 'control-readback', 'delete-source', 'stable-before-write' ) as $point ) {
		$reset();
		// Fresh fixture request/connection; product code never replaces a lost pin.
		$victim = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$victim->prefix = $observer->prefix; $victim->base_prefix = $observer->base_prefix; $victim->options = $observer->options;
		$wpdb = $victim;
		$source = serialize( array( 'checkpoint' => array( 'cursor' => '009' ), 'migrated' => array( '009' => array( 'ID' => '009' ) ) ) );
		$observer->query( $observer->prepare( 'INSERT INTO `' . $observer->options . "` (option_name, option_value, autoload) VALUES (%s, %s, 'no')", FOOGALLERY_MIGRATE_OPTION_DATA, $source ) );
		$gate = StorageMaintenanceGate::current(); $transport = new FgmKilledTransport( $victim );
		$property = new ReflectionProperty( $gate, 'connection' ); if ( PHP_VERSION_ID < 80100 ) { $property->setAccessible( true ); } $property->setValue( $gate, $transport );
		$fired = false; $before = null;
		$transport->before = function ( $sql, $read ) use ( $point, &$fired, &$before, $raw, $observer, $victim, $blog ) {
			$is_control = false !== strpos( $sql, StorageAuthority::CONTROL_OPTION );
			$match = 'owner-readback' === $point ? $read && false !== strpos( $sql, 'AS marker' ) :
				( 'copying-before-write' === $point ? ! $read && 0 === strpos( $sql, 'INSERT INTO' ) && $is_control :
				( 'control-readback' === $point ? $read && $is_control && false !== strpos( $sql, "CONCAT('v:'" ) :
				( 'delete-source' === $point ? ! $read && 0 === strpos( $sql, 'DELETE FROM' ) && false !== strpos( $sql, "'" . FOOGALLERY_MIGRATE_OPTION_DATA . "'" ) :
				! $read && 0 === strpos( $sql, 'UPDATE' ) && $is_control && false !== strpos( stripslashes( substr( $sql, 0, (int) strpos( $sql, ' WHERE ' ) ) ), '"phase":"stable"' ) ) ) );
			$owner = $observer->get_var( $observer->prepare( 'SELECT option_value FROM `' . $observer->options . '` WHERE option_name = %s', StorageAuthority::MAINTENANCE_OPTION ) );
			$control = $observer->get_var( $observer->prepare( 'SELECT option_value FROM `' . $observer->options . '` WHERE option_name = %s', StorageAuthority::CONTROL_OPTION ) );
			if ( $fired || ! $match || ! is_string( $owner ) || ( 'control-readback' === $point && ! is_string( $control ) ) ) { return; }
			$fired = true; $before = $raw();
			$pipes = array();
			$process = proc_open( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/storage-pinned-kill-worker.php' ) . ' ' . escapeshellarg( rtrim( ABSPATH, '/' ) ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
			if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Kill worker unavailable.' ); }
			fwrite( $pipes[0], json_encode( array( 'blog_id' => $blog, 'thread' => (int) $victim->dbh->thread_id, 'owner' => $owner ) ) ); fclose( $pipes[0] );
			$out = stream_get_contents( $pipes[1] ); fclose( $pipes[1] ); $err = stream_get_contents( $pipes[2] ); fclose( $pipes[2] );
			if ( 0 !== proc_close( $process ) || "OWNED_SESSION_KILLED\n" !== $out || '' !== $err ) { throw new RuntimeException( 'Owned session kill failed.' ); }
		};
		$queries = array();
		$capture = function ( $sql ) use ( &$queries ) { $queries[] = $sql; return $sql; };
		add_filter( 'query', $capture );
		$result = ( new StorageConversion( $policy ) )->switch_backend( $request() );
		remove_filter( 'query', $capture ); $capture = null;
		$check( $fired && is_wp_error( $result ), $point . ': separate-process session loss refuses actual coordinator' );
		$check( $before === $raw(), $point . ': all persisted option/control/owner/table evidence unchanged after loss' );
		$check( array() === $queries, $point . ': coordinator and gate execute no wpdb query/reconnect path' );
		$check( ! $transport->is_current(), $point . ': uncertain transport latched unusable' );
		$transport->before = null;
		// The fixture explicitly reconnects WordPress, NOT the product pin.
		$check( true === $victim->db_connect( false ), $point . ': fixture establishes a replacement WordPress connection' );
		$check( false === $transport->query( 'SELECT 1' ) && ! $transport->is_current(), $point . ': lost pin never executes on replacement' );
		$check( is_wp_error( $gate->release_maintenance() ) && is_wp_error( $gate->acquire_maintenance() ), $point . ': same request cannot release or reacquire ownership' );
		$check( $before === $raw(), $point . ': refused continuation retains exact evidence' );
	}
	$reset();
} finally {
	if ( null !== $capture ) { remove_filter( 'query', $capture ); }
	$wpdb = $original;
	$reset();
	foreach ( array_reverse( $created ) as $table ) { $observer->query( 'DROP TABLE `' . $table . '`' ); }
}
echo json_encode( array( 'status' => 'pass', 'blog_id' => $blog, 'checks' => $checks, 'check_count' => count( $checks ), 'wordpress' => $wp_version, 'php' => PHP_VERSION, 'database' => $original->db_version() ) ) . PHP_EOL;
