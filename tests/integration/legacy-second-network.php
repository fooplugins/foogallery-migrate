<?php
/** Disposable topology preparation plus explicit base-prefix/scope readbacks only. */
if ( ! defined( 'ABSPATH' ) || ! defined( 'FOOGM_STORAGE_TEST_DISPOSABLE' ) || true !== FOOGM_STORAGE_TEST_DISPOSABLE || ! is_multisite() ) {
	throw new RuntimeException( 'Explicit disposable multisite admission required.' );
}
$root = defined( 'FOOGM_STORAGE_TEST_ROOT' ) ? FOOGM_STORAGE_TEST_ROOT : dirname( dirname( __DIR__ ) );
require_once $root . '/migrate.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use FooPlugins\FooGalleryMigrate\LegacyTableBackend;
use FooPlugins\FooGalleryMigrate\LegacyTableSnapshot;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
global $wpdb;
if ( array_map( 'intval', get_sites( array( 'fields' => 'ids', 'number' => 100 ) ) ) !== array( 1, 2 ) || count( get_networks() ) !== 1 ) { throw new RuntimeException( 'Wrong owned multisite topology.' ); }
$schema = new LegacyStorageSchema( $wpdb ); $tables = array_keys( $schema->definitions() );
foreach ( $tables as $table ) { if ( null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) || $wpdb->last_error ) { throw new RuntimeException( 'Refusing existing tables/unhealthy fixture.' ); } }
$checks = array(); $created = array(); $network = null; $site = 2; $original_site = get_site( $site ); $moved = false;
$original_row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM `' . $wpdb->blogs . '` WHERE blog_id = %d', $site ), ARRAY_A );
if ( ! is_array( $original_row ) || $wpdb->last_error ) { throw new RuntimeException( 'Original owned site readback failed.' ); }
$check = function ( $ok, $label ) use ( &$checks ) { if ( ! $ok ) { throw new RuntimeException( 'FAIL: ' . $label ); } $checks[] = $label; };
$refuses = function ( $call, $label ) use ( $check ) { try { $call(); } catch ( RuntimeException $error ) { $check( true, $label ); return; } $check( false, $label ); };
$physical = function () use ( $wpdb, $tables ) { $rows = array(); foreach ( $tables as $table ) { $found = $wpdb->get_results( 'SELECT * FROM `' . $table . '` ORDER BY id', ARRAY_A ); if ( ! is_array( $found ) || $wpdb->last_error ) { throw new RuntimeException( 'Physical readback failed.' ); } $rows[$table] = $found; } return serialize( $rows ); };
try {
	// Fixture data only. This is not plugin authority/schema/UI provisioning.
	if ( 1 !== $wpdb->insert( $wpdb->site, array( 'domain' => 'second-network.foogm-t04219c53.test', 'path' => '/' ), array( '%s', '%s' ) ) ) { throw new RuntimeException( 'Owned network fixture creation failed.' ); }
	$network = (int) $wpdb->insert_id;
	clean_network_cache( $network );
	// Reuse the already initialized owned site; no runtime/core configuration fix.
	$moved = true;
	$result = wp_update_site( $site, array( 'network_id' => $network, 'domain' => 'second-network.foogm-t04219c53.test', 'path' => '/' ) );
	if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_message() ); }
	$check( $network > 1 && (int) get_site( $site )->network_id === $network && (int) get_site( 1 )->network_id === 1 && count( get_networks() ) === 2, 'real first and second networks with registered positive blog scopes' );
	foreach ( $schema->statements() as $i => $ddl ) { $created[] = $tables[$i]; dbDelta( $ddl ); }
	$db = new LegacyTransactionConnection( $wpdb ); $backends = array(); $prefixes = array(); $settings = new MigratorSettings();
	foreach ( array( 1, $site ) as $blog ) {
		switch_to_blog( $blog );
		try {
			$prefixes[$blog] = $wpdb->prefix;
			$check( get_current_blog_id() === $blog && array_keys( ( new LegacyStorageSchema( $wpdb ) )->definitions() ) === $tables && ( new LegacyStorageSchema( $wpdb ) )->verify(), 'all actual blog contexts use the same two installation-wide base-prefix tables' );
			$backends[$blog] = new LegacyTableBackend( $db, $blog );
			$value = $settings->compact_state_value( 'galleries', array( '09' => array( 'tenant' => $blog, 'identity' => 'string-09', 'binary' => "\xff\x00" ), 9 => array( 'tenant' => $blog, 'identity' => 'integer-9' ) ) );
			( new LegacyTableSnapshot( $db, $blog ) )->replace_many( array( array( 'key' => 'galleries', 'kind' => 'map', 'order' => 0, 'value' => $value, 'expected_revision' => null ) ) );
			$rows = $backends[$blog]->roots( 'galleries', array( '09', 9 ) );
			$check( $rows[0]['found'] && $rows[1]['found'] && '09' === $rows[0]['key'] && 9 === $rows[1]['key'] && $rows[0]['value']['tenant'] === $blog && $rows[1]['value']['tenant'] === $blog && "\xff\x00" === $rows[0]['value']['binary'], 'typed identities and binary payloads explicitly read back in their own network/blog scope' );
		} finally { restore_current_blog(); }
	}
	$before = $physical();
	$cursor = $backends[$site]->export_page( 'galleries', 1 )['cursor'];
	$refuses( function () use ( $backends, $cursor ) { $backends[1]->export_page( 'galleries', 1, $cursor ); }, 'second-network cursor refuses wrong first-network blog' );
	$refuses( function () use ( $db ) { ( new LegacyTableBackend( $db, 1000 ) )->collection_snapshot( 'galleries' ); }, 'missing wrong-blog head fails closed' );
	$refuses( function () use ( $db ) { new LegacyTableBackend( $db, 0 ); }, 'zero/installation scope refuses' );
	$check( $before === $physical(), 'cross-network/wrong-blog refusals preserve every physical byte' );
	foreach ( $prefixes as $prefix ) { if ( $prefix === $wpdb->base_prefix ) { continue; } foreach ( $tables as $base_table ) { $local = $prefix . substr( $base_table, strlen( $wpdb->base_prefix ) ); $check( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $local ) ) ) && ! $wpdb->last_error, 'no per-blog duplicate storage table' ); } }
	$scopes = array_map( 'intval', $wpdb->get_col( 'SELECT DISTINCT blog_id FROM `' . $tables[0] . '` ORDER BY blog_id' ) );
	$check( $scopes === array( 1, $site ), 'SQL readback confirms exactly two positive scopes in different networks in the same state table' );
	echo wp_json_encode( array( 'ok' => true, 'networks' => array( 1, $network ), 'scopes' => $scopes, 'tables' => $tables, 'check_count' => count( $checks ), 'checks' => $checks ) );
} finally {
	foreach ( array_reverse( $created ) as $table ) { $wpdb->query( 'DROP TABLE IF EXISTS `' . $table . '`' ); }
	if ( $moved ) {
		$result = wp_update_site( $site, $original_site->to_array() );
		if ( is_wp_error( $result ) ) { throw new RuntimeException( 'Owned site topology restore failed.' ); }
	}
	if ( null !== $network ) { $wpdb->delete( $wpdb->sitemeta, array( 'site_id' => $network ), array( '%d' ) ); $wpdb->delete( $wpdb->site, array( 'id' => $network ), array( '%d' ) ); clean_network_cache( $network ); }
	foreach ( $created as $table ) { if ( null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) || $wpdb->last_error ) { throw new RuntimeException( 'Owned table cleanup readback failed.' ); } }
	$restored = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM `' . $wpdb->blogs . '` WHERE blog_id = %d', $site ), ARRAY_A );
	if ( $restored !== $original_row || $wpdb->last_error || count( get_networks() ) !== 1 || ( null !== $network && null !== get_network( $network ) ) ) { throw new RuntimeException( 'Owned topology cleanup byte/readback failed.' ); }
}
