<?php
/**
 * Owned, disposable modern-runtime start of the production storage journey.
 * Usage: php storage-release-r0.php /tmp/foogallery-migrate-r0.XXXXXX seed|preflight|preflight-low-memory|provision|switch|recover|verify-table|simulate-post-delete-interruption|status
 * Keep the site and SQL container after RED so the exact state can be inspected.
 */
if ( 'cli' !== PHP_SAPI || 3 !== $argc || ! in_array( $argv[2], array( 'seed', 'preflight', 'preflight-low-memory', 'provision', 'switch', 'recover', 'verify-table', 'simulate-post-delete-interruption', 'status' ), true ) ) {
	throw new RuntimeException( 'An owned site path and one explicit journey step are required.' );
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
if ( 'preflight-low-memory' === $argv[2] ) {
	define( 'WP_MAX_MEMORY_LIMIT', '128M' );
	ini_set( 'memory_limit', '128M' );
}
require $site . '/wp-load.php';
require_once dirname( __DIR__ ) . '/support/StorageA7OptionsFixture.php';
require_once dirname( __DIR__ ) . '/support/StorageReleaseJourney.php';

use FooPlugins\FooGalleryMigrate\Tests\StorageA7OptionsFixture as Native;
use FooPlugins\FooGalleryMigrate\Tests\StorageReleaseJourney as Journey;
use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\StorageCapacityPolicy;
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use FooPlugins\FooGalleryMigrate\StorageAdmin;
use FooPlugins\FooGalleryMigrate\StorageControl;
use FooPlugins\FooGalleryMigrate\StorageCopyCursor;
use FooPlugins\FooGalleryMigrate\StorageScalarObserver;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;
use FooPlugins\FooGalleryMigrate\TableSnapshot;

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
$questions = function () use ( $wpdb ) {
	$rows = $wpdb->dbh->query( "SHOW SESSION STATUS LIKE 'Questions'" );
	Journey::check( $rows instanceof mysqli_result, 'Owned session query counter unavailable.' );
	$row = $rows->fetch_assoc(); $rows->free();
	Journey::check( is_array( $row ) && isset( $row['Value'] ) && ctype_digit( $row['Value'] ), 'Invalid owned session query counter.' );
	return (int) $row['Value'];
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
if ( null === $persisted ) { $persisted = $raw( StorageAuthority::STORAGE_BACKUP_OPTION ); }
Journey::check( is_string( $persisted ) && strlen( $persisted ) === Native::RAW_BYTES &&
	hash( 'sha256', $persisted ) === Native::RAW_SHA256, 'Native-text SQL seed/backup changed.' );
unset( $persisted );
$result = array( 'step' => $step, 'site' => $site, 'database' => $manifest['database'], 'db_host' => $manifest['host'],
	'php' => PHP_VERSION, 'wordpress' => $wp_version, 'mysql' => $wpdb->get_var( 'SELECT VERSION()' ),
	'implementation_digest' => StorageCapacityPolicy::implementation_digest(), 'fixture' => Native::NAME,
	'raw_bytes' => Native::RAW_BYTES, 'raw_sha256' => Native::RAW_SHA256 );
if ( 'preflight' === $step || 'preflight-low-memory' === $step ) {
	if ( 'preflight-low-memory' === $step ) {
		ini_set( 'memory_limit', '128M' );
		Journey::check( '128M' === ini_get( 'memory_limit' ) && '128M' === WP_MAX_MEMORY_LIMIT,
			'Low-memory fixture could not set its WordPress/PHP limit.' );
	}
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
if ( in_array( $step, array( 'provision', 'switch', 'recover' ), true ) ) {
	$control = $raw( StorageAuthority::CONTROL_OPTION );
	if ( 'provision' === $step || 'switch' === $step ) {
		Journey::check( null === $control, 'An existing control forbids starting this step.' );
	} else {
		$record = StorageControl::decode( $control, get_current_blog_id() );
		Journey::check( is_array( $record ) && 'stable' !== $record['phase'] && 'option' === $record['from'] &&
			'table' === $record['to'], 'A forward copying/verification control is required.' );
	}
	if ( 'switch' === $step || 'recover' === $step ) {
		Journey::check( ( new LegacyStorageSchema( $wpdb ) )->verify(), 'Owned SQL schema is not verified.' );
	}
	$enabled = new ReflectionProperty( 'FooPlugins\\FooGalleryMigrate\\StorageScalarObserver', 'milestones_enabled' );
	$milestones = new ReflectionProperty( 'FooPlugins\\FooGalleryMigrate\\StorageScalarObserver', 'milestones' );
	$refusal = new ReflectionProperty( 'FooPlugins\\FooGalleryMigrate\\StorageScalarObserver', 'refusal' );
	$enabled->setValue( null, true ); $milestones->setValue( null, array() ); $refusal->setValue( null, null );
	$before_questions = $questions();
	try { $result['result'] = Journey::request( 'switch' === $step ? 'switch' : ( 'recover' === $step ? 'recover' : 'provision' ), 'table' ); }
	catch ( RuntimeException $error ) { $result['failure'] = $error->getMessage(); }
	$result['session_questions_delta'] = $questions() - $before_questions;
	$result['milestones'] = array();
	foreach ( $milestones->getValue() as $event ) {
		$result['milestones'][] = array( 'name' => $event['name'], 'elapsed_seconds' => round( $event['time'] - $milestones->getValue()[0]['time'], 3 ),
			'peak_allocated_bytes' => $event['peak_allocated_cumulative'] );
	}
	$result['refusal'] = $refusal->getValue();
	$result['status'] = StorageAdmin::status();
	$after_control = $raw( StorageAuthority::CONTROL_OPTION );
	if ( null !== $after_control ) {
		$after_record = StorageControl::decode( $after_control, get_current_blog_id() );
		if ( isset( $after_record['copy_cursor'] ) && is_array( $after_record['copy_cursor'] ) ) { $result['copy_cursor'] = $after_record['copy_cursor']; }
		if ( isset( $after_record['verify_cursor'] ) ) { $result['verify_cursor'] = $after_record['verify_cursor']; }
	}
	$source = $raw( FOOGALLERY_MIGRATE_OPTION_DATA );
	$backup = $raw( StorageAuthority::STORAGE_BACKUP_OPTION );
	Journey::check( ( is_string( $source ) && hash( 'sha256', $source ) === Native::RAW_SHA256 ) ||
		( is_string( $backup ) && hash( 'sha256', $backup ) === Native::RAW_SHA256 ), 'Original seed/backup changed after public step.' );
	echo json_encode( $result, JSON_UNESCAPED_SLASHES ) . "\n";
	exit( isset( $result['failure'] ) ? 1 : 0 );
}
if ( 'verify-table' === $step ) {
	$record = StorageControl::decode( $raw( StorageAuthority::CONTROL_OPTION ), get_current_blog_id() );
	Journey::check( is_array( $record ) && 'table' === $record['backend'] &&
		in_array( $record['phase'], array( 'flipped', 'stable' ), true ) &&
		( new LegacyStorageSchema( $wpdb ) )->verify(), 'Selected owned table authority is required.' );
	$expected = Native::value();
	$snapshot = new TableSnapshot( new LegacyTransactionConnection( $wpdb ), get_current_blog_id(), 24576, 16777216 );
	$actual = array( 'galleries' => $snapshot->read_bounded( 'galleries' ) );
	Journey::check( serialize( $actual ) === serialize( $expected ), 'Table snapshot differs from exact native fixture.' );
	Native::verify( $actual );
	$result['table_exact_native_bytes'] = strlen( serialize( $actual ) );
	$result['table_exact_native_sha256'] = hash( 'sha256', serialize( $actual ) );
	$result['status'] = StorageAdmin::status();
}
if ( 'simulate-post-delete-interruption' === $step ) {
	$control = $raw( StorageAuthority::CONTROL_OPTION );
	$record = StorageControl::decode( $control, get_current_blog_id() );
	Journey::check( 'flipped' === $record['phase'] && 'table' === $record['backend'] && 'option' === $record['from'] &&
		'table' === $record['to'] && isset( $record['verify_cursor'] ) &&
		StorageCopyCursor::validate_verify( $record['verify_cursor'] ) === $record['verify_cursor'], 'Completed owned flipped proof is required.' );
	$cursor = $record['verify_cursor'];
	Journey::check( 1 === $cursor['state_order'] && 20208 === $cursor['verified_rows'] &&
		0 === $cursor['next_root_ordinal'] && 0 === $cursor['next_node_ordinal'] &&
		is_string( $cursor['heads_digest'] ), 'Native fixture proof is not complete.' );
	$source = $raw( FOOGALLERY_MIGRATE_OPTION_DATA ); $backup = $raw( StorageAuthority::STORAGE_BACKUP_OPTION );
	Journey::check( is_string( $source ) && is_string( $backup ) && $source === $backup &&
		hash( 'sha256', $source ) === Native::RAW_SHA256 &&
		$record['source_digest'] === hash( 'sha256', 'present:' . Native::RAW_SHA256 ), 'Exact owned source/backup parity is required.' );
	$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM `' . $wpdb->options . '` WHERE option_name=%s AND BINARY option_value=BINARY %s',
		array( FOOGALLERY_MIGRATE_OPTION_DATA, $source ) ) );
	Journey::check( 1 === $deleted && ! $wpdb->last_error && null === $raw( FOOGALLERY_MIGRATE_OPTION_DATA ) &&
		$control === $raw( StorageAuthority::CONTROL_OPTION ), 'Post-delete interruption simulation failed.' );
	wp_cache_delete( FOOGALLERY_MIGRATE_OPTION_DATA, 'options' );
	$result['simulated_after_exact_source_delete_before_stable_cas'] = true;
	$result['status'] = StorageAdmin::status();
}
if ( 'status' === $step ) {
	$result['status'] = StorageAdmin::status();
	$control = $raw( StorageAuthority::CONTROL_OPTION );
	if ( null !== $control ) {
		$record = StorageControl::decode( $control, get_current_blog_id() );
		if ( isset( $record['copy_cursor'] ) && is_array( $record['copy_cursor'] ) ) { $result['copy_cursor'] = $record['copy_cursor']; }
		if ( isset( $record['verify_cursor'] ) ) { $result['verify_cursor'] = $record['verify_cursor']; }
	}
}
echo json_encode( $result, JSON_UNESCAPED_SLASHES ) . "\n";
