<?php
/**
 * Owned, disposable modern-runtime start of the production storage journey.
 * Usage: php storage-release-r0.php /tmp/foogallery-migrate-r0.XXXXXX seed|preflight|status
 * Keep the site and SQL container after RED so the exact state can be inspected.
 */
if ( 'cli' !== PHP_SAPI || 3 !== $argc || ! in_array( $argv[2], array( 'seed', 'preflight', 'status' ), true ) ) {
	throw new RuntimeException( 'An owned site path and seed, preflight or status are required.' );
}
$site = realpath( $argv[1] );
if ( false === $site || ! preg_match( '~^/(?:private/)?tmp/foogallery-migrate-r0\.[A-Za-z0-9]+$~D', $site ) ) {
	throw new RuntimeException( 'The site must be a dedicated /tmp/foogallery-migrate-r0.* directory.' );
}
$manifest_path = $site . '/.fgm-r0-owner.json';
$manifest = is_file( $manifest_path ) ? json_decode( file_get_contents( $manifest_path ), true ) : null;
if ( ! is_array( $manifest ) || ! isset( $manifest['kind'], $manifest['site'], $manifest['database'], $manifest['host'], $manifest['repo'] ) ||
	'foogallery-migrate-r0-v1' !== $manifest['kind'] || $site !== realpath( $manifest['site'] ) ||
	! preg_match( '/^fgm_r0_[a-z0-9]+$/D', $manifest['database'] ) ||
	! preg_match( '/^127\.0\.0\.1:[0-9]{2,5}$/D', $manifest['host'] ) ||
	realpath( dirname( __DIR__, 2 ) ) !== realpath( $manifest['repo'] ) ) {
	throw new RuntimeException( 'Owned disposable site manifest is missing or mismatched.' );
}
if ( ! is_file( $site . '/wp-load.php' ) || ! is_file( $site . '/wp-config.php' ) ||
	realpath( $site . '/wp-content/plugins/foogallery-migrate' ) !== realpath( $manifest['repo'] ) ) {
	throw new RuntimeException( 'WordPress installation or plugin source does not match the owned manifest.' );
}
require $site . '/wp-load.php';
require_once dirname( __DIR__ ) . '/support/StorageA7OptionsFixture.php';
require_once dirname( __DIR__ ) . '/support/StorageReleaseJourney.php';

use FooPlugins\FooGalleryMigrate\Tests\StorageA7OptionsFixture as Native;
use FooPlugins\FooGalleryMigrate\Tests\StorageReleaseJourney as Journey;
use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\StorageCapacityPolicy;
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;

global $wpdb, $wp_version;
Journey::check( ! is_multisite() && DB_NAME === $manifest['database'] && DB_HOST === $manifest['host'] &&
	$wpdb->get_var( 'SELECT DATABASE()' ) === $manifest['database'] && ! $wpdb->last_error, 'SQL/site ownership mismatch.' );
Journey::check( class_exists( 'FooPlugins\\FooGalleryMigrate\\StorageCli' ) && function_exists( 'foogallery_migrate_migrator_instance' ) &&
	function_exists( 'foogallery_default_gallery_template' ), 'Production plugins are not active.' );
wp_set_current_user( 1 );
Journey::check( current_user_can( 'manage_options' ), 'Owned administrator is unavailable.' );
$raw = function ( $name ) use ( $wpdb ) {
	$value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `' . $wpdb->options . '` WHERE option_name=%s', $name ) );
	Journey::check( ! $wpdb->last_error, 'Option SQL read failed.' );
	return $value;
};
$journal_name = 'fgm_r0_owned_journey_v1';
$journal = get_option( $journal_name, null );
$seed_identity = array( 'owner' => hash( 'sha256', $manifest_path . '|' . $manifest['database'] ),
	'bytes' => Native::RAW_BYTES, 'sha256' => Native::RAW_SHA256 );
$storage_options = array( StorageAuthority::CONTROL_OPTION, StorageAuthority::AUTHORITY_OPTION,
	StorageAuthority::MAINTENANCE_OPTION, StorageAuthority::STORAGE_BACKUP_OPTION );
$step = $argv[2];
if ( 'seed' === $step ) {
	Journey::check( null === $journal && null === $raw( FOOGALLERY_MIGRATE_OPTION_DATA ), 'Existing journey or migration data; refusing reseed.' );
	foreach ( $storage_options as $name ) { Journey::check( null === $raw( $name ), 'Existing storage marker; refusing reseed.' ); }
	$value = Native::value();
	Journey::check( update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $value, false ), 'WordPress option seed failed.' );
	unset( $value );
	Journey::check( add_option( $journal_name, $seed_identity, '', false ), 'Owned journey marker could not be added.' );
}
Journey::check( $journal === $seed_identity || 'seed' === $step, 'Missing or mismatched owned seed journal.' );
$persisted = $raw( FOOGALLERY_MIGRATE_OPTION_DATA );
Journey::check( is_string( $persisted ) && strlen( $persisted ) === Native::RAW_BYTES &&
	hash( 'sha256', $persisted ) === Native::RAW_SHA256, 'Native-text SQL seed changed.' );
unset( $persisted );
$result = array( 'step' => $step, 'site' => $site, 'database' => $manifest['database'], 'db_host' => $manifest['host'],
	'php' => PHP_VERSION, 'wordpress' => $wp_version, 'mysql' => $wpdb->get_var( 'SELECT VERSION()' ),
	'implementation_digest' => StorageCapacityPolicy::implementation_digest(), 'fixture' => Native::NAME,
	'raw_bytes' => Native::RAW_BYTES, 'raw_sha256' => Native::RAW_SHA256 );
if ( 'preflight' === $step ) {
	foreach ( $storage_options as $name ) { Journey::check( null === $raw( $name ), 'Storage marker appeared before preflight.' ); }
	$tables = array_keys( ( new LegacyStorageSchema( $wpdb ) )->definitions() );
	foreach ( $tables as $table ) {
		Journey::check( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) &&
			! $wpdb->last_error, 'Storage table already exists before preflight.' );
	}
	try { $result['result'] = Journey::preflight(); }
	catch ( RuntimeException $error ) {
		foreach ( $storage_options as $name ) { Journey::check( null === $raw( $name ), 'Failed preflight changed a storage marker.' ); }
		foreach ( $tables as $table ) {
			Journey::check( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) &&
				! $wpdb->last_error, 'Failed preflight created a storage table.' );
		}
		$after = $raw( FOOGALLERY_MIGRATE_OPTION_DATA );
		Journey::check( is_string( $after ) && strlen( $after ) === Native::RAW_BYTES &&
			hash( 'sha256', $after ) === Native::RAW_SHA256 && get_option( $journal_name, null ) === $seed_identity,
			'Failed preflight changed the owned seed.' );
		$result['failure'] = $error->getMessage();
		$result['refusal_left_seed_and_storage_unchanged'] = true;
		echo json_encode( $result, JSON_UNESCAPED_SLASHES ) . "\n";
		exit( 1 );
	}
}
echo json_encode( $result, JSON_UNESCAPED_SLASHES ) . "\n";
