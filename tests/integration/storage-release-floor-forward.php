<?php
/**
 * One public floor storage step per fresh, manifest-bound WP-CLI lifetime.
 * Usage: run-storage-release-floor.sh FIXTURE eval-file /repo/tests/integration/storage-release-floor-forward.php STEP [256M|384M] --user=1
 * STEP: status, provision, switch, recover, verify. No release/admission override.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'ABSPATH' ) || ! is_file( '/fixture-owner.json' ) ||
	! isset( $args ) || ! is_array( $args ) || ! in_array( count( $args ), array( 1, 2 ), true ) ||
	! in_array( $args[0], array( 'status', 'provision', 'switch', 'recover', 'verify' ), true ) ||
	( 2 === count( $args ) && ! in_array( $args[1], array( '256M', '384M' ), true ) ) ) {
	throw new RuntimeException( 'Owned floor WP-CLI fixture and one explicit step required.' );
}
$memory_limit = 2 === count( $args ) ? $args[1] : '256M';
$owner = json_decode( file_get_contents( '/fixture-owner.json' ), true );
if ( ! is_array( $owner ) || ! isset( $owner['kind'], $owner['database'], $owner['container'] ) ||
	'foogallery-migrate-floor-v1' !== $owner['kind'] || DB_NAME !== $owner['database'] ||
	DB_HOST !== $owner['container'] || PHP_VERSION !== '5.4.45' ||
	$GLOBALS['wp_version'] !== '5.0' || is_multisite() || ! current_user_can( 'manage_options' ) ||
	false === ini_set( 'memory_limit', $memory_limit ) || $memory_limit !== ini_get( 'memory_limit' ) ) {
	throw new RuntimeException( 'Floor runtime, site ownership, administrator or memory limit mismatch.' );
}

use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\StorageAdmin;
use FooPlugins\FooGalleryMigrate\StorageCli;
use FooPlugins\FooGalleryMigrate\StorageControl;
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;
use FooPlugins\FooGalleryMigrate\LegacyTableSnapshot;

global $wpdb;
function floor_forward_check( $okay, $message ) {
	if ( ! $okay ) { throw new RuntimeException( $message ); }
}
floor_forward_check( $wpdb->get_var( 'SELECT DATABASE()' ) === $owner['database'] && ! $wpdb->last_error,
	'Live SQL database ownership mismatch.' );
floor_forward_check( class_exists( 'FooPlugins\\FooGalleryMigrate\\StorageCli' ) &&
	function_exists( 'foogallery_migrate_migrator_instance' ) &&
	function_exists( 'foogallery_default_gallery_template' ), 'Real active production plugins required.' );

$step = $args[0];
$raw = function ( $name ) use ( $wpdb ) {
	$value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `' . $wpdb->options . '` WHERE option_name=%s', $name ) );
	floor_forward_check( ! $wpdb->last_error, 'Raw storage option read failed.' );
	return $value;
};
$native_bytes = 2998086;
$native_sha256 = 'b367943d00ec52ca5fe2a46fdf2b40bd8c15218a83eafc7e1be026becd6e2677';
$native = function () use ( $raw, $native_bytes, $native_sha256 ) {
	$source = $raw( FOOGALLERY_MIGRATE_OPTION_DATA );
	$backup = $raw( StorageAuthority::STORAGE_BACKUP_OPTION );
	foreach ( array( $source, $backup ) as $value ) {
		floor_forward_check( null === $value || ( is_string( $value ) && $native_bytes === strlen( $value ) &&
			$native_sha256 === hash( 'sha256', $value ) ), 'Native option or backup identity changed.' );
	}
	floor_forward_check( null !== $source || null !== $backup, 'Native seed and backup are both absent.' );
	return array( 'source_present' => null !== $source, 'backup_present' => null !== $backup,
		'raw_bytes' => $native_bytes, 'raw_sha256' => $native_sha256 );
};
$schema = new LegacyStorageSchema( $wpdb );
$tables = array_keys( $schema->definitions() );
$control = function () use ( $raw ) {
	$value = $raw( StorageAuthority::CONTROL_OPTION );
	return null === $value ? null : StorageControl::decode( $value, get_current_blog_id() );
};
$before_native = $native();
$before = $control();
$status = StorageAdmin::status();
floor_forward_check( '' === $status['problem'] && 'unavailable' !== $status['backend'], 'Storage health reports a problem.' );
$observer = null;
if ( 'switch' === $step || 'recover' === $step ) {
	// Diagnostic-only scalar milestones; no callback, policy or admission change.
	$observer = array();
	foreach ( array( 'milestones_enabled', 'milestones', 'refusal' ) as $name ) {
		$property = new ReflectionProperty( 'FooPlugins\\FooGalleryMigrate\\StorageScalarObserver', $name );
		$property->setAccessible( true );
		$observer[$name] = $property;
	}
	$observer['milestones_enabled']->setValue( null, true );
	$observer['milestones']->setValue( null, array() );
	$observer['refusal']->setValue( null, null );
}

if ( 'provision' === $step ) {
	floor_forward_check( null === $before && $before_native['source_present'] && ! $before_native['backup_present'] &&
		'option' === $status['backend'] && 'stable' === $status['phase'] &&
		null === $raw( StorageAuthority::AUTHORITY_OPTION ) &&
		null === $raw( StorageAuthority::MAINTENANCE_OPTION ), 'Provision requires untouched option authority.' );
	foreach ( $tables as $table ) {
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		floor_forward_check( null === $found && ! $wpdb->last_error, 'Shared storage table already exists.' );
	}
	$flags = array( 'target' => 'table', 'confirm' => 'provision-shared-schema' );
	$result = StorageCli::execute( 'provision', $flags );
} elseif ( 'switch' === $step ) {
	floor_forward_check( null === $before && $before_native['source_present'] &&
		'option' === $status['backend'] && 'stable' === $status['phase'] &&
		'verified' === $status['schema'] && null === $raw( StorageAuthority::MAINTENANCE_OPTION ),
		'Switch requires provisioned, stable option authority.' );
	$schema->verify();
	$flags = array( 'target' => 'table', 'confirm' => 'switch-to-table' );
	$result = StorageCli::execute( 'switch', $flags );
} elseif ( 'recover' === $step ) {
	floor_forward_check( is_array( $before ) && 'stable' !== $before['phase'] &&
		'option' === $before['from'] && 'table' === $before['to'] &&
		'verified' === $status['schema'], 'Recover requires the recorded forward conversion.' );
	$schema->verify();
	$flags = array( 'target' => 'table', 'confirm' => 'recover-storage' );
	$result = StorageCli::execute( 'recover', $flags );
} elseif ( 'verify' === $step ) {
	floor_forward_check( is_array( $before ) && 'stable' === $before['phase'] &&
		'table' === $before['backend'] && 'verified' === $status['schema'] &&
		! $before_native['source_present'] && $before_native['backup_present'] &&
		StorageControl::runtime_ready( $before, $raw( StorageAuthority::AUTHORITY_OPTION ) ),
		'Verify requires stable, selected table authority and retained native backup.' );
	$schema->verify();
	$heads = $wpdb->get_results( $wpdb->prepare( 'SELECT HEX(state_key_hash) AS key_hash, state_key_value, state_order, record_count FROM `' . $tables[0] . '` WHERE blog_id=%d LIMIT 2', get_current_blog_id() ), ARRAY_A );
	$head_error = $wpdb->last_error;
	$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM `' . $tables[1] . '` WHERE blog_id=%d', get_current_blog_id() ) );
	$codec = new LegacyStateCodec();
	floor_forward_check( ! $head_error && ! $wpdb->last_error && is_array( $heads ) && 1 === count( $heads ) &&
		strtolower( $heads[0]['key_hash'] ) === $codec->key_hash( 'galleries' ) &&
		$heads[0]['state_key_value'] === $codec->key_value( 'galleries' ) &&
		0 === (int) $heads[0]['state_order'] && 20208 === (int) $heads[0]['record_count'] &&
		20208 === (int) $count, 'Floor native20K table row counts differ.' );
	$snapshot = new LegacyTableSnapshot( new LegacyTransactionConnection( $wpdb ), get_current_blog_id(), 24576, 16777216 );
	$actual = array( 'galleries' => $snapshot->read_bounded( 'galleries' ) );
	$serialized = serialize( $actual );
	floor_forward_check( $native_bytes === strlen( $serialized ) && $native_sha256 === hash( 'sha256', $serialized ),
		'Exact native table readback differs from the original PHP 5.4 option.' );
	$result = array( 'table_record_count' => (int) $count,
		'exact_native_table_bytes' => strlen( $serialized ),
		'exact_native_table_sha256' => hash( 'sha256', $serialized ) );
} else {
	$result = array( 'read_only' => true );
}

$public_error = is_wp_error( $result );
if ( $public_error ) { $result = array( 'error_code' => $result->get_error_code(), 'error_message' => $result->get_error_message() ); }
$diagnostic = null;
if ( null !== $observer ) {
	$events = $observer['milestones']->getValue();
	$diagnostic = array( 'refusal' => $observer['refusal']->getValue(), 'milestones' => array() );
	$first = count( $events ) ? $events[0]['time'] : 0;
	foreach ( $events as $event ) {
		$diagnostic['milestones'][] = array( 'name' => $event['name'],
			'seconds_after_entry' => round( $event['time'] - $first, 3 ),
			'peak_allocated_bytes' => $event['peak_allocated_cumulative'] );
	}
	$observer['milestones_enabled']->setValue( null, false );
}
$after_native = $native();
$after = $control();
$after_status = StorageAdmin::status();
floor_forward_check( '' === $after_status['problem'] && 'unavailable' !== $after_status['backend'], 'Storage health changed to a problem.' );
if ( is_array( $after ) ) {
	floor_forward_check( $after_status['backend'] === $after['backend'] && $after_status['phase'] === $after['phase'],
		'Public status and authority control disagree.' );
}
if ( ! $public_error && 'provision' === $step ) {
	floor_forward_check( null === $after && 'verified' === $after_status['schema'] &&
		$after_native === $before_native, 'Provision changed native seed or authority.' );
	$schema->verify();
} elseif ( ! $public_error && ( 'switch' === $step || 'recover' === $step ) ) {
	floor_forward_check( is_array( $after ) && 'option' === $after['from'] && 'table' === $after['to'],
		'Public forward step lost its authority control.' );
	if ( 'stable' === $after['phase'] ) {
		floor_forward_check( 'table' === $after['backend'] && ! $after_native['source_present'] &&
			$after_native['backup_present'] && StorageControl::runtime_ready( $after, $raw( StorageAuthority::AUTHORITY_OPTION ) ),
			'Stable table selection or exact native backup is missing.' );
	} else {
		floor_forward_check( $after_native['source_present'], 'In-progress conversion lost original option.' );
	}
}
echo json_encode( array( 'step' => $step, 'php' => PHP_VERSION, 'wordpress' => $GLOBALS['wp_version'],
	'database' => DB_NAME, 'memory_limit' => ini_get( 'memory_limit' ), 'native' => $after_native,
	'result' => $result, 'status' => $after_status, 'diagnostic' => $diagnostic,
	'control' => is_array( $after ) ? array( 'version' => $after['version'], 'phase' => $after['phase'],
		'backend' => $after['backend'], 'from' => $after['from'], 'to' => $after['to'],
		'copy_cursor' => isset( $after['copy_cursor'] ) ? $after['copy_cursor'] : null,
		'verify_cursor' => isset( $after['verify_cursor'] ) ? $after['verify_cursor'] : null ) : null ) ) . "\n";
if ( $public_error ) { exit( 1 ); }
