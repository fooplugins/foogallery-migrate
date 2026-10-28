<?php
/** Real WordPress writer-barrier primitive checks; disposable fixtures only. */
if ( ! defined( 'ABSPATH' ) || ! defined( 'FOOGM_STORAGE_TEST_DISPOSABLE' ) || true !== FOOGM_STORAGE_TEST_DISPOSABLE ||
	! defined( 'FOOGM_STORAGE_TEST_ROOT' ) ) { throw new RuntimeException( 'Explicitly disposable fixture required.' ); }
require_once FOOGM_STORAGE_TEST_ROOT . '/migrate.php';
require_once __DIR__ . '/storage-pinned-fixture.php';

use FooPlugins\FooGalleryMigrate\StorageMaintenanceGate;
use FooPlugins\FooGalleryMigrate\StorageAuthority;

global $wpdb;
$prefix = $wpdb->esc_like( StorageMaintenanceGate::WRITER_PREFIX ) . '%';
if ( false !== StorageAuthority::option_mode_error() || null !== $wpdb->get_var( $wpdb->prepare(
	'SELECT 1 AS present FROM `' . $wpdb->options . '` WHERE option_name LIKE %s LIMIT 1', $prefix
) ) || ! empty( $wpdb->last_error ) ) { throw new RuntimeException( 'Refusing existing storage state.' ); }
$checks = array();
$check = function ( $condition, $label ) use ( &$checks ) {
	if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $label ); }
	$checks[] = $label;
};
$gate = StorageMaintenanceGate::current();
$blog_id = get_current_blog_id();
$table = $wpdb->options;
$owned = array();
$trap = function () { throw new RuntimeException( 'Unexpected legacy snapshot hydration.' ); };
$race = null;
try {
	$data = array( 'opaque' => array( '09' => false, 9 => null, 'bytes' => "snow 雪\x00" ), 'queued' => true );
	$check( add_option( FOOGALLERY_MIGRATE_OPTION_DATA, $data, '', 'no' ), 'seed owned option snapshot' );
	$owned[] = FOOGALLERY_MIGRATE_OPTION_DATA;
	$source = $wpdb->get_row( $wpdb->prepare( 'SELECT option_value, autoload FROM `' . $table . '` WHERE option_name = %s', FOOGALLERY_MIGRATE_OPTION_DATA ), ARRAY_A );
	add_filter( 'pre_option_' . FOOGALLERY_MIGRATE_OPTION_DATA, $trap );
	$check( $gate === StorageMaintenanceGate::current(), 'request singleton' );
	$check( true === $gate->begin_writer(), 'acquire writer' );
	$names = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM `' . $table . '` WHERE option_name LIKE %s', $prefix ) );
	$check( count( $names ) === 1, 'one writer row' );
	$marker = $names[0];
	$autoload = $wpdb->get_var( $wpdb->prepare( 'SELECT autoload FROM `' . $table . '` WHERE option_name = %s', $marker ) );
	$check( in_array( $autoload, array( 'no', 'off', 'auto-off' ), true ), 'writer not autoloaded' );
	$check( true === $gate->begin_writer(), 'nested writer shares marker' );
	$check( true === $gate->end_writer(), 'nested release' );
	$check( null !== $wpdb->get_var( $wpdb->prepare( 'SELECT 1 AS present FROM `' . $table . '` WHERE option_name = %s', $marker ) ), 'outer marker remains' );
	$check( true === $gate->acquire_maintenance(), 'gate can acquire while writer drains' );
	$calls = 0;
	$verdict = function () use ( &$calls ) { $calls++; return true; };
	$check( is_wp_error( $gate->maintenance_ready( $verdict ) ) && 0 === $calls, 'writer prevents engine verdict evaluation' );
	$check( true === $gate->assert_writer(), 'admitted writer can complete behind gate' );
	$check( true === $gate->renew_writer(), 'writer token-aware renewal' );
	$check( true === $gate->end_writer(), 'last writer release' );
	$check( false === get_option( $marker ), 'direct delete invalidates option cache' );
	wp_cache_set( StorageAuthority::MAINTENANCE_OPTION, false, 'options' );
	$check( is_wp_error( $gate->begin_writer() ), 'fresh SQL gate read ignores deliberately stale negative cache' );
	$check( true === $gate->maintenance_ready( $verdict ) && 2 === $calls, 'two post-gate strict affirmative opaque verdicts' );
	$check( is_wp_error( $gate->maintenance_ready( function () { return 1; } ) ), 'truthy verdict is not affirmative' );
	$check( true === $gate->renew_maintenance(), 'gate renewal including same-second no-op' );
	$check( true === $gate->release_maintenance(), 'owner release of gate' );
	$check( false === get_option( StorageAuthority::MAINTENANCE_OPTION ), 'gate deletion invalidates cached negative/alloptions' );
	foreach ( array( '', str_repeat( 'x', 513 ) ) as $invalid_gate ) {
		$check( add_option( StorageAuthority::MAINTENANCE_OPTION, $invalid_gate, '', 'no' ), 'seed invocation-owned empty or oversized gate' );
		$check( is_wp_error( $gate->begin_writer() ), 'existing empty or oversized gate never means absent' );
		$check( delete_option( StorageAuthority::MAINTENANCE_OPTION ), 'remove invocation-owned invalid gate' );
	}

	$collision_done = false;
	$collision = function ( $query ) use ( &$collision_done ) {
		if ( ! $collision_done && 0 === strpos( $query, 'INSERT IGNORE INTO' ) && false !== strpos( $query, StorageAuthority::MAINTENANCE_OPTION ) ) {
			$collision_done = true;
			if ( ! add_option( StorageAuthority::MAINTENANCE_OPTION, 'competing-gate-owner', '', 'no' ) ) { throw new RuntimeException( 'Could not seed competing gate.' ); }
		}
		return $query;
	};
	add_filter( 'query', $collision );
	$check( is_wp_error( $gate->acquire_maintenance() ) && $collision_done, 'insert-only gate claim refuses simultaneous competing owner' );
	remove_filter( 'query', $collision );
	$check( 'competing-gate-owner' === get_option( StorageAuthority::MAINTENANCE_OPTION ), 'insert collision does not overwrite competing owner' );
	$check( delete_option( StorageAuthority::MAINTENANCE_OPTION ), 'remove invocation-owned competing gate' );

	$check( true === $gate->acquire_maintenance(), 'acquire gate for ownership replacement check' );
	$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `' . $table . '` WHERE option_name = %s', StorageAuthority::MAINTENANCE_OPTION ) );
	$check( 1 === $wpdb->update( $table, array( 'option_value' => 'foreign-owner' ), array( 'option_name' => StorageAuthority::MAINTENANCE_OPTION ) ), 'fixture replaces raw owner' );
	$check( is_wp_error( $gate->release_maintenance() ) && is_wp_error( $gate->renew_maintenance() ), 'CAS cannot remove or renew foreign owner' );
	$check( 'foreign-owner' === $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `' . $table . '` WHERE option_name = %s', StorageAuthority::MAINTENANCE_OPTION ) ), 'foreign gate preserved' );
	$check( 1 === $wpdb->update( $table, array( 'option_value' => $raw ), array( 'option_name' => StorageAuthority::MAINTENANCE_OPTION ) ), 'restore invocation-owned gate only' );
	$check( true === $gate->release_maintenance(), 'original owner can release restored raw token' );

	$foreign = StorageMaintenanceGate::WRITER_PREFIX . str_repeat( 'z', 32 );
	$owned[] = $foreign;
	$check( add_option( $foreign, '{"expires":1}', '', 'no' ), 'seed expired fixture marker' );
	$check( true === $gate->acquire_maintenance(), 'gate for expired marker test' );
	$check( is_wp_error( $gate->maintenance_ready( $verdict ) ), 'expired marker requires explicit recovery' );
	$check( '{"expires":1}' === get_option( $foreign ), 'expired marker not pruned' );
	$check( delete_option( $foreign ), 'remove invocation-owned expired fixture' );
	$check( true === $gate->release_maintenance(), 'release after expired marker test' );

	$race_done = false;
	$race = function ( $query ) use ( &$race_done ) {
		if ( ! $race_done && 0 === strpos( $query, 'INSERT IGNORE INTO' ) && false !== strpos( $query, StorageMaintenanceGate::WRITER_PREFIX ) ) {
			$race_done = true;
			if ( ! add_option( StorageAuthority::MAINTENANCE_OPTION, 'race-owned-gate', '', 'no' ) ) { throw new RuntimeException( 'Could not seed racing gate.' ); }
		}
		return $query;
	};
	add_filter( 'query', $race );
	$check( is_wp_error( $gate->begin_writer() ) && $race_done, 'gate inserted between first check and marker prevents admission' );
	remove_filter( 'query', $race );
	$owned[] = StorageAuthority::MAINTENANCE_OPTION;
	$check( null === $wpdb->get_var( $wpdb->prepare( 'SELECT 1 AS present FROM `' . $table . '` WHERE option_name LIKE %s LIMIT 1', $prefix ) ), 'racing writer removed only its marker' );
	$check( 'race-owned-gate' === get_option( StorageAuthority::MAINTENANCE_OPTION ), 'racing gate retained' );
	$check( delete_option( StorageAuthority::MAINTENANCE_OPTION ), 'remove invocation-owned racing gate' );

	if ( is_multisite() ) {
		$check( true === $gate->begin_writer(), 'writer before blog switch' );
		$other = 1 === $blog_id ? 2 : 1;
		switch_to_blog( $other );
		$check( is_wp_error( $gate->assert_writer() ) && is_wp_error( $gate->end_writer() ), 'cached handle refuses cross-blog use' );
		$check( $gate !== StorageMaintenanceGate::current(), 'different blog has separate request handle' );
		restore_current_blog();
		$check( true === $gate->end_writer(), 'restored blog can release its own writer' );
		$check( true === $gate->begin_writer(), 'writer before shutdown blog switch' );
		switch_to_blog( $other );
		$gate->shutdown_writer();
		$check( get_current_blog_id() === $other, 'shutdown returns to original exit context' );
		restore_current_blog();
		$check( null === $wpdb->get_var( $wpdb->prepare( 'SELECT 1 AS present FROM `' . $table . '` WHERE option_name LIKE %s LIMIT 1', $prefix ) ), 'shutdown releases captured blog marker' );
	}
	$check( $source === $wpdb->get_row( $wpdb->prepare( 'SELECT option_value, autoload FROM `' . $table . '` WHERE option_name = %s', FOOGALLERY_MIGRATE_OPTION_DATA ), ARRAY_A ), 'source bytes/autoload unchanged and never hydrated' );
} finally {
	if ( null !== $race ) { remove_filter( 'query', $race ); }
	if ( get_current_blog_id() !== $blog_id ) { restore_current_blog(); }
	remove_filter( 'pre_option_' . FOOGALLERY_MIGRATE_OPTION_DATA, $trap );
	$gate->shutdown_writer();
	$gate->release_maintenance();
	foreach ( $owned as $name ) { delete_option( $name ); }
}
$check( false === StorageAuthority::option_mode_error(), 'virgin authority restored' );
$check( null === $wpdb->get_var( $wpdb->prepare( 'SELECT 1 AS present FROM `' . $table . '` WHERE option_name LIKE %s LIMIT 1', $prefix ) ) && empty( $wpdb->last_error ), 'no writer rows remain' );
echo wp_json_encode( array( 'status' => 'pass', 'scope' => 'writer-barrier primitive only; no production endpoint integration',
	'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'database' => $wpdb->get_var( 'SELECT VERSION()' ),
	'blog_id' => $blog_id, 'multisite' => is_multisite(), 'checks' => $checks, 'check_count' => count( $checks ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
