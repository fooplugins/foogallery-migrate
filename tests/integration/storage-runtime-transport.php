<?php
/** Native writer pin plus explicitly simulated lost receipts; NOT hard-death proof. */
if ( ! defined( 'ABSPATH' ) || ! defined( 'FOOGM_STORAGE_TEST_DISPOSABLE' ) || true !== FOOGM_STORAGE_TEST_DISPOSABLE || ! defined( 'FOOGM_STORAGE_TEST_ROOT' ) ) { throw new RuntimeException( 'Disposable fixture required.' ); }
require_once FOOGM_STORAGE_TEST_ROOT . '/migrate.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\StorageConversion;
use FooPlugins\FooGalleryMigrate\StorageMaintenanceGate;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use FooPlugins\FooGalleryMigrate\LegacyTableBackend;
use FooPlugins\FooGalleryMigrate\LegacyTableSnapshot;
use FooPlugins\FooGalleryMigrate\MigratorEngine;

class FgmRuntimeReceiptLoss extends LegacyTransactionConnection {
	public $fault;
	public $fired = false;
	public $after_commit = false;
	public $sql = array();
	private function lose() {
		// Test-only loss of a response after a REAL native commit/read. This does
		// not claim a server/network failure, SIGKILL or arbitrary schedule proof.
		$property = new ReflectionProperty( 'FooPlugins\\FooGalleryMigrate\\LegacyTransactionConnection', 'tainted' );
		$property->setValue( $this, true );
		$this->last_error = 'Fixture lost native result receipt.'; $this->fired = true;
	}
	public function query( $sql ) {
		$this->sql[] = $sql; $result = parent::query( $sql );
		if ( null !== $this->fault && 'COMMIT' === $sql && false !== $result ) {
			$this->after_commit = true;
			if ( 'commit-ack' === $this->fault ) { $this->lose(); return false; }
		}
		return $result;
	}
	public function get_results( $sql, $format ) {
		$this->sql[] = $sql; $result = parent::get_results( $sql, $format );
		if ( 'post-commit-read' === $this->fault && $this->after_commit && ! $this->fired ) { $this->lose(); return null; }
		return $result;
	}
}
function fgm_runtime_property( $object, $name ) {
	$property = new ReflectionProperty( $object, $name ); return $property->getValue( $object );
}

global $wpdb;
$original = $wpdb; $blog = (int) get_current_blog_id();
$observer_wpdb = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$observer_wpdb->prefix = $wpdb->prefix; $observer_wpdb->base_prefix = $wpdb->base_prefix;
$observer = new LegacyTransactionConnection( $observer_wpdb );
$schema = new LegacyStorageSchema( $observer_wpdb ); $tables = array_keys( $schema->definitions() );
$names = array( FOOGALLERY_MIGRATE_OPTION_DATA, StorageAuthority::CONTROL_OPTION, StorageAuthority::AUTHORITY_OPTION, StorageAuthority::MAINTENANCE_OPTION, StorageAuthority::STORAGE_BACKUP_OPTION );
$writer_pattern = $observer->esc_like( StorageMaintenanceGate::WRITER_PREFIX ) . '%';
foreach ( $tables as $table ) {
	if ( null !== $observer->get_var( $observer->prepare( 'SHOW TABLES LIKE %s', array( $observer->esc_like( $table ) ) ) ) || ! empty( $observer->last_error ) ) { throw new RuntimeException( 'Refusing existing tables.' ); }
}
foreach ( $names as $name ) {
	if ( null !== $observer->get_var( $observer->prepare( 'SELECT 1 FROM `' . $original->options . '` WHERE option_name = %s', array( $name ) ) ) || ! empty( $observer->last_error ) ) { throw new RuntimeException( 'Refusing existing evidence.' ); }
}
if ( null !== $observer->get_var( $observer->prepare( 'SELECT 1 FROM `' . $original->options . '` WHERE option_name LIKE %s LIMIT 1', array( $writer_pattern ) ) ) || ! empty( $observer->last_error ) ) { throw new RuntimeException( 'Refusing existing writers.' ); }
$checks = array(); $created = array(); $writers = array(); $capture = null;
$check = function ( $yes, $label ) use ( &$checks ) { if ( ! $yes ) { throw new RuntimeException( 'FAIL: ' . $label ); } $checks[] = $label; };
$reset = function () use ( $observer, $original, $names, $tables, $blog, &$writers ) {
	foreach ( $writers as $row ) { $observer->query( $observer->prepare( 'DELETE FROM `' . $original->options . '` WHERE option_name = %s AND BINARY option_value = BINARY %s', array( $row['option_name'], $row['option_value'] ) ) ); wp_cache_delete( $row['option_name'], 'options' ); }
	$writers = array();
	foreach ( $names as $name ) { $observer->query( $observer->prepare( 'DELETE FROM `' . $original->options . '` WHERE option_name = %s', array( $name ) ) ); wp_cache_delete( $name, 'options' ); }
	wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' );
	foreach ( array_reverse( $tables ) as $table ) { $observer->query( $observer->prepare( 'DELETE FROM `' . $table . '` WHERE blog_id = %d', array( $blog ) ) ); }
	unset( $GLOBALS['foogallery_migrate_engine_instance'] );
};
$raw_options = function () use ( $observer, $original, $names ) {
	return $observer->get_results( $observer->prepare( 'SELECT option_name, option_value, autoload FROM `' . $original->options . '` WHERE option_name IN (' . implode( ', ', array_fill( 0, count( $names ), '%s' ) ) . ') ORDER BY option_name', $names ), ARRAY_A );
};
$other = $blog + 1000;
try {
	foreach ( $schema->statements() as $index => $ddl ) { $created[] = $tables[$index]; dbDelta( $ddl ); }
	$check( $schema->verify(), 'native fixture schema verified' );
	( new LegacyTableSnapshot( $observer, $other ) )->replace_many( array( array( 'key' => 'checkpoint', 'order' => 0, 'kind' => 'scalar', 'value' => 'other-scope', 'expected_revision' => null ) ) );
	$other_raw = array();
	foreach ( $tables as $table ) { $other_raw[$table] = $observer->get_results( $observer->prepare( 'SELECT * FROM `' . $table . '` WHERE blog_id = %d ORDER BY id', array( $other ) ), ARRAY_A ); }
	foreach ( array( 'commit-ack', 'post-commit-read' ) as $fault ) {
		$reset();
		// A fresh fixture request; product code never resets an unusable adapter.
		$wpdb = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ); $wpdb->set_prefix( $original->base_prefix );
		if ( is_multisite() ) { $wpdb->set_blog_id( $blog ); }
		$pin = new FgmRuntimeReceiptLoss( $wpdb ); $gate = StorageMaintenanceGate::current();
		$property = new ReflectionProperty( $gate, 'connection' ); $property->setValue( $gate, $pin );
		$check( update_option( FOOGALLERY_MIGRATE_OPTION_DATA, array( 'checkpoint' => 'before' ), false ), $fault . ': compact source seeded' );
		$policy = array( 'max_raw_bytes' => 1048576, 'amplification' => 64, 'reserve_bytes' => 33554432 );
		$request = array( 'blog_id' => $blog, 'scope' => 'site', 'target' => 'table', 'confirmation' => 'switch-to-table', 'nonce' => wp_create_nonce( 'foogallery_migrate_storage_option_import_' . $blog ) );
		$converted = ( new StorageConversion( $policy ) )->switch_backend( $request );
		$check( ! is_wp_error( $converted ), $fault . ': actual coordinator converts verified source' );
		$engine = foogallery_migrate_migrator_instance();
		$check( true === $engine->set_migrator_setting( 'checkpoint', 'admitted' ), $fault . ': actual table writer admitted' );
		$repository = fgm_runtime_property( fgm_runtime_property( $engine, 'production_backend' ), 'repository' );
		$check( $pin === fgm_runtime_property( $repository, 'db' ), $fault . ': production backend and marker share identical adapter, not just mysqli handle' );
		$options = $raw_options();
		$writers = $observer->get_results( $observer->prepare( 'SELECT option_name, option_value, autoload FROM `' . $original->options . '` WHERE option_name LIKE %s ORDER BY option_name', array( $writer_pattern ) ), ARRAY_A );
		$check( 1 === count( $writers ), $fault . ': one persisted owned writer' );
		$queries = array(); $capture = function ( $sql ) use ( &$queries ) { $queries[] = $sql; return $sql; }; add_filter( 'query', $capture );
		$pin->fault = $fault;
		try { $engine->set_migrator_setting( 'checkpoint', 'committed-without-receipt' ); throw new LogicException( 'Uncertain writer reported success.' ); }
		catch ( RuntimeException $error ) { $check( $pin->fired && ! $pin->is_current(), $fault . ': lost receipt refuses actual writer and latches shared pin' ); }
		$check( array() === $queries, $fault . ': writer and authority SQL never use wpdb replay/query-filter path' );
		remove_filter( 'query', $capture ); $capture = null;
		$check( 'committed-without-receipt' === ( new LegacyTableBackend( $observer, $blog ) )->read( 'checkpoint' ), $fault . ': independent observer proves real commit despite reported refusal' );
		$check( $options === $raw_options(), $fault . ': control, sentinel and backup stay byte-identical' );
		$sql_count = count( $pin->sql );
		$check( is_wp_error( StorageAuthority::runtime_record( $gate ) ) && is_wp_error( StorageAuthority::option_mode_error( $gate ) ), $fault . ': no option fallback on healthy wpdb' );
		$check( false === $engine->storage_is_quiescent(), $fault . ': unusable writer cannot affirm complete drain' );
		try { $engine->set_migrator_setting( 'checkpoint', 'retry-must-not-write' ); throw new LogicException( 'Uncertain writer retried.' ); }
		catch ( RuntimeException $error ) { $check( true, $fault . ': cached writer retry refused' ); }
		$gate->shutdown_writer();
		$check( $writers === $observer->get_results( $observer->prepare( 'SELECT option_name, option_value, autoload FROM `' . $original->options . '` WHERE option_name LIKE %s ORDER BY option_name', array( $writer_pattern ) ), ARRAY_A ), $fault . ': shutdown retains exact uncertain writer marker' );
		$check( $sql_count === count( $pin->sql ), $fault . ': no rollback, release, re-acquisition or SQL after loss' );
		try { new MigratorEngine(); throw new LogicException( 'Fresh engine ignored lost pin.' ); }
		catch ( RuntimeException $error ) { $check( true, $fault . ': fresh engine cannot adopt authority via healthy wpdb' ); }
		// A different request can claim maintenance, but retained writer is NOT
		// drained. No stale-owner policy or fixture deletion is product recovery.
		$victim = $wpdb; $wpdb = $observer_wpdb;
		$waiter = StorageMaintenanceGate::current();
		$check( true === $waiter->acquire_maintenance(), $fault . ': independent request claims maintenance' );
		$check( is_wp_error( $waiter->maintenance_ready( function () { return true; } ) ), $fault . ': retained writer blocks independent conversion readiness' );
		$check( true === $waiter->release_maintenance(), $fault . ': waiter releases only its own marker' );
		$wpdb = $victim;
		foreach ( $tables as $table ) { $check( $other_raw[$table] === $observer->get_results( $observer->prepare( 'SELECT * FROM `' . $table . '` WHERE blog_id = %d ORDER BY id', array( $other ) ), ARRAY_A ), $fault . ': other SQL blog stays byte-identical: ' . $table ); }
	}
} finally {
	if ( null !== $capture ) { remove_filter( 'query', $capture ); }
	$reset(); $wpdb = $original;
	foreach ( array_reverse( $created ) as $table ) { $observer->query( 'DROP TABLE `' . $table . '`' ); }
}
$check( false === StorageAuthority::option_mode_error(), 'fixture restores virgin option authority' );
echo wp_json_encode( array( 'status' => 'pass', 'scope' => 'native writer integration with SIMULATED commit/read receipt loss; NOT hard-process-death, network-fault, full drain or takeover proof',
	'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'database' => $observer->get_var( 'SELECT VERSION()' ), 'blog_id' => $blog, 'multisite' => is_multisite(), 'checks' => $checks, 'check_count' => count( $checks ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
