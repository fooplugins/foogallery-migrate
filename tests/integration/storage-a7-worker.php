<?php
/** Privileged disposable WP/SQL producer. Not shipped; never a production provider. */
use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\StorageCalibration;
use FooPlugins\FooGalleryMigrate\StorageOperations;
use FooPlugins\FooGalleryMigrate\StorageControl;
use FooPlugins\FooGalleryMigrate\StorageRetirement;
use FooPlugins\FooGalleryMigrate\StorageScalarObserver;
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use FooPlugins\FooGalleryMigrate\LegacyTableSnapshot;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;

if ( PHP_SAPI !== 'cli' || PHP_VERSION_ID < 70000 || empty( $GLOBALS['foogm_a7_bootstrap'] ) ||
	! defined( 'FOOGM_A7_STAGE' ) || ! defined( 'FOOGM_A7_CELL' ) || ! defined( 'FOOGM_A7_OWNER' ) ||
	! preg_match( '/^[a-f0-9]{32}$/D', FOOGM_A7_OWNER ) ) { throw new RuntimeException( 'Confined A7 bootstrap required.' ); }
$root = FOOGM_STORAGE_TEST_ROOT;
require_once $root . '/migrate.php';
if ( ! is_admin() ) { new \FooPlugins\FooGalleryMigrate\Init(); }
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
$manifest = json_decode( file_get_contents( FOOGM_A7_FIXTURES . '/manifest.json' ), true );

$positive = 'storage-a7-native-positive' === FOOGM_STORAGE_TEST_MODE;
if ( $positive ) {
	require_once __DIR__ . '/storage-a7-native-positive.php';
	if ( json_decode( file_get_contents( __DIR__ . '/storage-a7-options-manifest.json' ), true ) !== $manifest || FOOGM_A7_CELL !== \FooPlugins\FooGalleryMigrate\Tests\StorageA7OptionsFixture::NAME ) { throw new RuntimeException( 'Unfrozen positive corpus.' ); }
	$original = json_decode( file_get_contents( __DIR__ . '/storage-a7-manifest.json' ), true );
	$manifest['cells'] = array( FOOGM_A7_CELL => $manifest['fixture'] );
	$manifest['experimental_policy'] = $original['experimental_policy'];
}
if ( ! isset( $manifest['cells'][FOOGM_A7_CELL] ) ) { throw new RuntimeException( 'Unfrozen cell.' ); }
$expected = $manifest['cells'][FOOGM_A7_CELL];
global $wpdb;
$blog = (int) get_current_blog_id(); $schema = new LegacyStorageSchema( $wpdb ); $tables = array_keys( $schema->definitions() );
$owner_name = 'foogallery_migrate_a7_fixture_owner';
$names = array( FOOGALLERY_MIGRATE_OPTION_DATA, StorageAuthority::CONTROL_OPTION, StorageAuthority::AUTHORITY_OPTION, StorageAuthority::MAINTENANCE_OPTION, StorageAuthority::STORAGE_BACKUP_OPTION, $owner_name );
$check = function ( $ok, $label ) { if ( true !== $ok ) { throw new RuntimeException( $label ); } };
$sql_ok = function () use ( $wpdb, $check ) { $check( empty( $wpdb->last_error ), 'SQL failed; retain fixture' ); };
$raw = function ( $name ) use ( $wpdb, $sql_ok ) {
	$value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `' . $wpdb->options . '` WHERE option_name=%s LIMIT 1', $name ) ); $sql_ok(); return $value;
};
$physical = function () use ( $wpdb, $tables, $names, $raw, $sql_ok, $blog ) {
	$result = array( 'options' => array(), 'tables' => array() );
	foreach ( $names as $name ) {
		$v = $raw( $name ); $result['options'][$name] = null === $v ? null : array( 'bytes' => strlen( $v ), 'sha256' => hash( 'sha256', $v ) );
	}
	foreach ( $tables as $table ) {
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); $sql_ok();
		$result['tables'][$table] = null === $exists ? null : (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM `' . $table . '` WHERE blog_id=%d', $blog ) ); $sql_ok();
	}
	return $result;
};
$property = function ( $name, $set = false, $value = null ) {
	$p = new ReflectionProperty( 'FooPlugins\\FooGalleryMigrate\\StorageScalarObserver', $name ); if ( PHP_VERSION_ID < 80100 ) { $p->setAccessible( true ); }
	if ( $set ) { $p->setValue( null, $value ); } return $p->getValue();
};
$receipt = array( 'format' => 'a7-worker-v1', 'producer' => 'real-StorageOperations-WP-SQL', 'cell' => (string) FOOGM_A7_CELL, 'stage' => FOOGM_A7_STAGE,
	'owner' => FOOGM_A7_OWNER, 'blog_id' => $blog, 'status' => 'measurement_only', 'method_approved' => false, 'profile_approved' => false,
	'bootstrap' => $GLOBALS['foogm_a7_bootstrap'], 'runtime' => StorageCalibration::identity(), 'fingerprint' => StorageCalibration::fingerprint(),
	'fixture' => $expected, 'ok' => false, 'first_failure' => null, 'observations' => array() );
$start = microtime( true );
$pins_before = $positive ? foogm_a7_product_pins( $root ) : null;
try {
	$check( current_user_can( 'manage_options' ), 'Authorized administrator required' );
	if ( $positive ) {
		$receipt['pcre_before'] = ini_get( 'pcre.backtrack_limit' );
		$check( false !== ini_set( 'pcre.backtrack_limit', '16777216' ), 'Cannot set bounded worker-local PCRE budget' );
	}
	if ( 'seed' === FOOGM_A7_STAGE ) {
		foreach ( $names as $name ) { $check( null === $raw( $name ), 'Refuse existing option evidence' ); }
		foreach ( $tables as $table ) {
			$check( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ), 'Refuse existing table evidence' ); $sql_ok();
		}
		$check( add_option( $owner_name, FOOGM_A7_OWNER, '', false ), 'Cannot acquire new fixture owner' );
		foreach ( $schema->statements() as $ddl ) { dbDelta( $ddl ); $sql_ok(); }
		$check( $schema->verify(), 'Exact shared schema mismatch' );
		$fixture_raw = file_get_contents( FOOGM_A7_FIXTURES . '/' . FOOGM_A7_CELL . '.serialized' );
		$check( strlen( $fixture_raw ) === $expected['raw_bytes'] && hash( 'sha256', $fixture_raw ) === $expected['sha256'], 'Frozen fixture byte mismatch' );
		$check( 1 === $wpdb->query( $wpdb->prepare( 'INSERT INTO `' . $wpdb->options . '` (option_name,option_value,autoload) VALUES (%s,%s,%s)', FOOGALLERY_MIGRATE_OPTION_DATA, $fixture_raw, 'no' ) ), 'Fixture raw insert failed' );
		$check( $raw( FOOGALLERY_MIGRATE_OPTION_DATA ) === $fixture_raw, 'Fixture SQL readback mismatch' );
		$receipt['observations']['seed'] = $physical();
		if ( $positive ) { $receipt['positive_seed'] = foogm_a7_positive_seed( $wpdb, $blog, $expected ); }
	} else {
		$check( FOOGM_A7_OWNER === $raw( $owner_name ), 'Exact fixture owner mismatch; no cleanup permission' );
		$receipt['before'] = $physical();
		if ( 'reverse' === FOOGM_A7_STAGE ) { $receipt['retained_table_before'] = ( new StorageRetirement( new LegacyTransactionConnection( $wpdb ), $wpdb->prefix, $blog, $manifest['experimental_policy'], microtime( true ) + 10 ) )->capture(); }
		if ( 'cleanup' === FOOGM_A7_STAGE ) {
			// Entire shared tables are owned because seed refused ALL existing tables.
			foreach ( array_reverse( $tables ) as $table ) { $wpdb->query( 'DROP TABLE IF EXISTS `' . $table . '`' ); $sql_ok(); }
			foreach ( $names as $name ) { delete_option( $name ); $check( null === $raw( $name ), 'Owned option cleanup incomplete' ); }
			$after = $physical();
			$check( count( array_filter( $after['tables'], function ( $v ) { return null !== $v; } ) ) === 0, 'Owned table cleanup incomplete' );
			$receipt['observations']['cleanup_completed'] = true; $receipt['after'] = $after;
		} elseif ( $positive && 'backup' === FOOGM_A7_STAGE ) {
			$control = StorageControl::decode( $raw( StorageAuthority::CONTROL_OPTION ), $blog );
			$check( StorageControl::runtime_ready( $control, $raw( StorageAuthority::AUTHORITY_OPTION ) ), 'Backup authority inconsistent' );
			$check( 'table' === $control['backend'] && 1 === $control['epoch'] && 'stable' === $control['phase'], 'Backup authority not stable table epoch1' );
			$receipt['backup_authority'] = $control;
			$receipt['result'] = foogm_a7_positive_run( $wpdb, $blog, function () use ( $wpdb ) {
				list( $sql ) = \FooPlugins\FooGalleryMigrate\Tests\StorageA7OptionsHarness::wordpress_readers( $wpdb );
				return $sql( StorageAuthority::STORAGE_BACKUP_OPTION );
			}, $receipt );
			$receipt['after'] = $physical();
			$check( $receipt['before'] === $receipt['after'], 'Backup inspection mutated storage' );
		} elseif ( in_array( FOOGM_A7_STAGE, array( 'preflight', 'forward', 'reverse', 'refusal' ), true ) ) {
			$target = 'reverse' === FOOGM_A7_STAGE ? 'option' : 'table';
			$purpose = 'table' === $target ? 'option_import' : 'table_export';
			$request = array( 'blog_id' => (string) $blog, 'scope' => 'site', 'target' => $target, 'confirmation' => 'switch-to-' . $target,
				'nonce' => wp_create_nonce( 'foogallery_migrate_storage_' . $purpose . '_' . $blog ) );
			// Deliberately approved-SHAPED TEST injection, required to traverse the real
			// policy guard; its status is not exported as an approved measured profile.
			$profile = array( 'version' => 1, 'status' => 'approved', 'fingerprint' => StorageCalibration::fingerprint(),
				'evidence_sha256' => hash_file( 'sha256', FOOGM_A7_FIXTURES . '/manifest.json' ), 'policy' => $manifest['experimental_policy'] );
			$filter = function () use ( $profile ) { return $profile; };
			add_filter( 'foogallery_migrate_storage_calibration', $filter );
			$property( 'milestones', true, array() ); $property( 'milestones_valid', true, true ); $property( 'milestones_enabled', true, true );
			$operation = 'preflight' === FOOGM_A7_STAGE ? 'preflight' : 'switch';
			$dispatch = function () use ( $operation, $request, &$receipt ) {
				$result = ( new StorageOperations() )->dispatch( $operation, $request );
				if ( is_wp_error( $result ) ) { $receipt['dispatch_failure'] = array( 'code' => $result->get_error_code(), 'message' => $result->get_error_message() ); }
				return $result;
			};
			$result = $positive ? foogm_a7_positive_run( $wpdb, $blog, $dispatch, $receipt ) : $dispatch();
			$property( 'milestones_enabled', true, false ); remove_filter( 'foogallery_migrate_storage_calibration', $filter );
			$receipt['milestones'] = $property( 'milestones' ); $receipt['milestones_valid'] = $property( 'milestones_valid' );
			$receipt['response_bytes'] = strlen( json_encode( is_wp_error( $result ) ? array( 'code' => $result->get_error_code() ) : $result ) );
			$receipt['result'] = is_wp_error( $result ) ? array( 'error' => $result->get_error_code() ) : $result;
			$receipt['after'] = $physical();
			$control_raw = $raw( StorageAuthority::CONTROL_OPTION ); $sentinel_raw = $raw( StorageAuthority::AUTHORITY_OPTION );
			$control = null === $control_raw ? null : StorageControl::decode( $control_raw, $blog );
			$receipt['authority'] = array( 'control' => $control, 'sentinel' => null === $sentinel_raw ? null : json_decode( $sentinel_raw, true ) );
			$check( $receipt['milestones_valid'] && $receipt['response_bytes'] <= 1024, 'Missing/bounded scalar measurement failure' );
			if ( 'refusal' === FOOGM_A7_STAGE ) {
				$check( is_wp_error( $result ) && 'foogallery_migrate_storage_decode_refused' === $result->get_error_code(), 'Expected exact byte-bound refusal' );
				$check( $receipt['before'] === $receipt['after'] && null === $control && null === $sentinel_raw, 'Refusal changed source/authority' );
			} else {
				$check( ! is_wp_error( $result ), 'receiver:' . ( is_wp_error( $result ) ? $result->get_error_code() : 'unknown' ) );
				if ( 'preflight' === FOOGM_A7_STAGE ) {
					$check( true === $result['decode_admitted'] && false === $result['conversion_ready'] && $expected['raw_bytes'] === $result['raw_bytes'], 'Preflight raw/admission mismatch' );
					$check( $receipt['before'] === $receipt['after'], 'Preflight mutated storage' );
				} else {
					$check( array_keys( $result ) === array( 'blog_id', 'backend', 'epoch', 'phase' ) && 'stable' === $result['phase'] && $target === $result['backend'], 'Receiver did not reach bounded stable destination' );
					$check( StorageControl::runtime_ready( $control, $sentinel_raw ), 'Authority/control inconsistent' );
					$backup = $raw( StorageAuthority::STORAGE_BACKUP_OPTION );
					$check( is_string( $backup ) && hash( 'sha256', $backup ) === $expected['sha256'], 'Retained backup byte mismatch' );
					if ( 'table' === $target ) {
						$check( null === $raw( FOOGALLERY_MIGRATE_OPTION_DATA ), 'Forward retained active option' );
						$value = ( new LegacyTableSnapshot( new LegacyTransactionConnection( $wpdb ), $blog ) )->read_bounded( 'galleries' );
						$logical = ( new LegacyStateCodec() )->snapshot_digest( array( 'galleries' => $value ) );
					} else {
						$receipt['retained_table_after'] = ( new StorageRetirement( new LegacyTransactionConnection( $wpdb ), $wpdb->prefix, $blog, $manifest['experimental_policy'], microtime( true ) + 10 ) )->capture();
						$check( $receipt['retained_table_before'] === $receipt['retained_table_after'], 'Retained table source bytes changed' );
						$reverse_raw = $raw( FOOGALLERY_MIGRATE_OPTION_DATA );
						$check( is_string( $reverse_raw ) && hash( 'sha256', $reverse_raw ) === $expected['sha256'], 'Reverse exact serialized bytes changed' );
						$logical = ( new LegacyStateCodec() )->snapshot_digest( unserialize( $reverse_raw, array( 'allowed_classes' => false ) ) );
					}
					$check( $logical === $control['logical_digest'] && $logical === $expected['logical_sha256'], 'Complete logical parity mismatch' );
					$receipt['observations']['logical_sha256'] = $logical;
				}
			}
		} else { throw new RuntimeException( 'Unknown A7 stage' ); }
	}
	if ( $positive && 'reverse' === FOOGM_A7_STAGE ) { $receipt['reverse_readback'] = foogm_a7_positive_seed( $wpdb, $blog, $expected ); }
	$receipt['ok'] = true;
} catch ( Throwable $error ) {
	$receipt['first_failure'] = $error->getMessage();
	$receipt['sql_error'] = $wpdb->last_error;
	$receipt['after_failure'] = $physical();
}
$property( 'milestones_enabled', true, false );
if ( ! isset( $receipt['milestones'] ) ) { $receipt['milestones'] = $property( 'milestones' ); $receipt['milestones_valid'] = $property( 'milestones_valid' ); }
$receipt['seconds'] = microtime( true ) - $start;
$receipt['resources'] = array( 'live' => memory_get_usage( false ), 'allocated' => memory_get_usage( true ), 'peak_live_cumulative' => memory_get_peak_usage( false ),
	'peak_allocated_cumulative' => memory_get_peak_usage( true ), 'rusage' => getrusage(), 'rss_unit' => 'Darwin' === PHP_OS ? 'bytes' : 'KiB', 'peaks_include_bootstrap' => true, 'peaks_reset_or_subtracted' => false, 'allocator_sample' => 'after product response encoding and parity checks; before evidence receipt JSON output' );
$receipt['product_root'] = realpath( $root );
if ( $positive ) {
	$receipt['product_pins_before'] = $pins_before; $receipt['product_pins_after'] = foogm_a7_product_pins( $root );
	$receipt['product_classes'] = foogm_a7_product_classes( $root );
	if ( $receipt['product_pins_before'] !== $receipt['product_pins_after'] ) { $receipt['ok'] = false; $receipt['first_failure'] = 'Product files changed during worker'; }
}
$encoded = json_encode( $receipt, JSON_UNESCAPED_SLASHES );
if ( ! is_string( $encoded ) || strlen( $encoded ) > 262144 ) { throw new RuntimeException( 'A7 producer receipt encoding/size failure' ); }
echo $encoded . "\n";
