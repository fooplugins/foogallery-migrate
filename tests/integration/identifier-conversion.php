<?php
/** Native SQL protocol check; not a browser/fresh-worker memory qualification. */
if ( ! defined( 'FOOGM_STORAGE_TEST_DISPOSABLE' ) || true !== FOOGM_STORAGE_TEST_DISPOSABLE ) { throw new RuntimeException( 'Owned disposable fixture required.' ); }
use FooPlugins\FooGalleryMigrate\StorageOperations;
use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use FooPlugins\FooGalleryMigrate\StorageControl;

global $wpdb;
$checks = 0; $turns = 0;
$check = function ( $ok, $label ) use ( &$checks ) { ++$checks; if ( ! $ok ) { throw new RuntimeException( $label ); } };
$blog = (int) get_current_blog_id();
$request = function ( $operation, $target ) use ( $blog ) {
	$purpose = 'recover' === $operation || 'discard' === $operation ? $operation : ( 'table' === $target ? 'option_import' : 'table_export' );
	return array( 'blog_id' => $blog, 'scope' => 'site', 'target' => $target,
		'confirmation' => 'recover' === $operation ? 'recover-storage' : ( 'discard' === $operation ? 'discard-copy' : 'switch-to-' . $target ),
		'nonce' => wp_create_nonce( 'foogallery_migrate_storage_' . $purpose . '_' . $blog ) );
};
$dispatch = function ( $operation, $target ) use ( $request, $check, &$turns ) {
	$result = ( new StorageOperations() )->dispatch( $operation, $request( $operation, $target ) ); ++$turns;
	$check( ! is_wp_error( $result ), 'Native ' . $operation . ' refused: ' . ( is_wp_error( $result ) ? $result->get_error_code() : '' ) );
	return $result;
};
$tables = array_keys( ( new LegacyStorageSchema( $wpdb ) )->definitions() );
foreach ( $tables as $table ) { $check( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ), 'Refuse existing tables.' ); }
$items = array();
for ( $i = 0; $i < 150; ++$i ) { $items['row-' . $i] = array( 'object_type' => 'future', 'opaque' => "雪\x00'" . $i ); }
$seed = array( 'migrated' => array( MigratorSettings::COMPACT_MARKER => MigratorSettings::COMPACT_VERSION, 'type' => 'migratable', 'items' => $items ), 'migrated-revision' => 1, 'queue' => false );
$existing = get_option( FOOGALLERY_MIGRATE_OPTION_DATA );
$check( false === $existing || serialize( $seed ) === serialize( $existing ), 'Refuse non-fixture state.' );
if ( false === $existing ) { add_option( FOOGALLERY_MIGRATE_OPTION_DATA, $seed, '', false ); }
// Provision through the real receiver, using the normal release policy throughout.
$provision = array( 'blog_id' => $blog, 'scope' => 'installation', 'target' => 'table', 'confirmation' => 'provision-shared-schema', 'nonce' => wp_create_nonce( 'foogallery_migrate_storage_provision_schema_' . $blog ) );
$check( ! is_wp_error( ( new StorageOperations() )->dispatch( 'provision', $provision ) ), 'Native provisioning failed.' );
$dispatch( 'switch', 'table' );
$dispatch( 'recover', 'table' );
$raw = get_option( StorageAuthority::CONTROL_OPTION ); $control = StorageControl::decode( $raw, $blog );
$check( 'copying' === $control['phase'] && $control['copy_cursor']['committed_rows'] > 0, 'Copy did not stage a real prefix.' );
$dispatch( 'discard', 'table' );
$check( $seed === get_option( FOOGALLERY_MIGRATE_OPTION_DATA ) && false === get_option( StorageAuthority::CONTROL_OPTION ), 'Discard changed source/retained control.' );
foreach ( $tables as $table ) { $check( '0' === (string) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $table . '`' ), 'Discard left owned rows.' ); }
foreach ( array( 'table', 'option' ) as $target ) {
	$result = $dispatch( 'switch', $target );
	for ( $turn = 0; 'stable' !== $result['phase'] && $turn < 60; ++$turn ) { $result = $dispatch( 'recover', $target ); }
	$check( 'stable' === $result['phase'] && $target === $result['backend'], 'Roundtrip did not reach stable authority.' );
}
$check( serialize( $seed ) === serialize( get_option( FOOGALLERY_MIGRATE_OPTION_DATA ) ), 'Roundtrip changed logical state.' );
$cleanup = array( 'blog_id' => $blog, 'scope' => 'site', 'target' => 'all', 'confirmation' => 'remove-all-internal-data', 'nonce' => wp_create_nonce( 'foogallery_migrate_storage_remove_all_' . $blog ) );
$result = ( new StorageOperations() )->dispatch( 'remove_all', $cleanup );
$check( ! is_wp_error( $result ) && ! empty( $result['complete'] ), 'Owned cleanup did not complete.' );
echo wp_json_encode( array( 'status' => 'pass', 'checks' => $checks, 'turns' => $turns, 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'scope' => 'native SQL provision/copy/discard/forward/reverse/cleanup; no fresh-worker memory claim' ) ) . "\n";
