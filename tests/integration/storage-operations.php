<?php
/** Disposable native receiver measurements; NO production calibration approval. */
if ( ! defined( 'ABSPATH' ) || ! defined( 'FOOGM_STORAGE_TEST_DISPOSABLE' ) || true !== FOOGM_STORAGE_TEST_DISPOSABLE ) { throw new RuntimeException( 'Disposable WordPress fixture required.' ); }
// A7 uses fresh workers and separate seeding, never this six-shape/reset-peak path.
if ( defined( 'FOOGM_STORAGE_TEST_MODE' ) ) {
	if ( 'storage-calibration-a7-gallery' !== FOOGM_STORAGE_TEST_MODE ) { throw new RuntimeException( 'Unknown measurement mode.' ); }
	require __DIR__ . '/storage-a7-worker.php';
	return;
}
// Intercept JSON termination without exiting the owned fixture before cleanup.
if ( ! defined( 'DOING_AJAX' ) ) { define( 'DOING_AJAX', true ); }
$root = defined( 'FOOGM_STORAGE_TEST_ROOT' ) ? FOOGM_STORAGE_TEST_ROOT : dirname( dirname( __DIR__ ) );
require_once $root . '/migrate.php';
// WP-CLI has already defined WP_ADMIN=false; execute the production admin
// initializer explicitly, as other native admin harnesses do. No HTTP claim.
if ( ! is_admin() ) { new \FooPlugins\FooGalleryMigrate\Init(); }
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\StorageCalibration;
use FooPlugins\FooGalleryMigrate\StorageOperations;
use FooPlugins\FooGalleryMigrate\StorageControl;
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use FooPlugins\FooGalleryMigrate\LegacyStateCodec;

global $wpdb;
$blog = (int) get_current_blog_id(); $schema = new LegacyStorageSchema( $wpdb ); $tables = array_keys( $schema->definitions() );
$names = array( FOOGALLERY_MIGRATE_OPTION_DATA, StorageAuthority::CONTROL_OPTION, StorageAuthority::AUTHORITY_OPTION, StorageAuthority::MAINTENANCE_OPTION, StorageAuthority::STORAGE_BACKUP_OPTION );
foreach ( $tables as $table ) {
	if ( null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) || ! empty( $wpdb->last_error ) ) { throw new RuntimeException( 'Refusing existing table evidence.' ); }
}
foreach ( $names as $name ) {
	if ( null !== $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM `' . $wpdb->options . '` WHERE option_name = %s', $name ) ) ) { throw new RuntimeException( 'Refusing existing option evidence.' ); }
}
if ( ! function_exists( 'memory_reset_peak_usage' ) ) { throw new RuntimeException( 'Fresh peak measurement unavailable; this is not a floor test.' ); }
$checks = array(); $measurements = array(); $created = array();
$check = function ( $yes, $label ) use ( &$checks ) { if ( ! $yes ) { throw new RuntimeException( 'FAIL: ' . $label ); } $checks[] = $label; };
$request = function ( $target, $recover = false ) use ( $blog ) {
	$purpose = $recover ? 'recover' : ( 'table' === $target ? 'option_import' : 'table_export' );
	return array( 'blog_id' => (string) $blog, 'scope' => 'site', 'target' => $target, 'confirmation' => $recover ? 'recover-storage' : 'switch-to-' . $target,
		'nonce' => wp_create_nonce( 'foogallery_migrate_storage_' . $purpose . '_' . $blog ) );
};
$reset = function () use ( $wpdb, $tables, $names, $blog ) {
	foreach ( $names as $name ) { delete_option( $name ); }
	foreach ( array_reverse( $tables ) as $table ) { $wpdb->query( $wpdb->prepare( 'DELETE FROM `' . $table . '` WHERE blog_id = %d', $blog ) ); }
	unset( $GLOBALS['foogallery_migrate_engine_instance'] );
};
$old_post = $_POST; $old_method = isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : null;
$die_handler = function () { return function () { throw new RuntimeException( 'owned-json-response' ); }; };
$response = function ( $operation, $r, $method = 'POST' ) use ( $die_handler ) {
	$_POST = array_merge( $r, array( 'action' => 'foogallery_migrate_storage', 'operation' => $operation ) );
	$_SERVER['REQUEST_METHOD'] = $method;
	add_filter( 'wp_die_ajax_handler', $die_handler ); ob_start();
	try { do_action( 'admin_post_foogallery_migrate_storage' ); throw new RuntimeException( 'Handler returned without JSON.' ); }
	catch ( RuntimeException $error ) { if ( 'owned-json-response' !== $error->getMessage() ) { throw $error; } }
	finally { $raw = ob_get_clean(); remove_filter( 'wp_die_ajax_handler', $die_handler ); }
	$value = json_decode( $raw, true );
	if ( ! is_array( $value ) || ! isset( $value['success'], $value['data'] ) ) { throw new RuntimeException( 'Invalid native JSON.' ); }
	return $value;
};
// A trusted injection tests routing ONLY. This synthetic policy is not inferred
// from the measurements below and is never installed as server configuration.
$profile = array( 'version' => 1, 'status' => 'approved', 'fingerprint' => StorageCalibration::fingerprint(),
	'evidence_sha256' => str_repeat( 'a', 64 ), 'policy' => array( 'max_raw_bytes' => 1048576, 'amplification' => 64, 'reserve_bytes' => 33554432 ) );
$synthetic = function () use ( $profile ) { return $profile; };
try {
	$check( false !== has_action( 'admin_post_foogallery_migrate_storage', array( 'FooPlugins\\FooGalleryMigrate\\StorageOperations', 'handle' ) ), 'production authenticated receiver registered without provisioning' );
	$check( false === has_action( 'admin_post_nopriv_foogallery_migrate_storage' ), 'no unauthenticated storage receiver' );
	$queries = array(); $trap = function ( $sql ) use ( &$queries ) { $queries[] = $sql; return $sql; };
	add_filter( 'query', $trap );
	$r = $request( 'table' ); $r['nonce'] = 'wrong';
	$result = $response( 'switch', $r );
	$check( false === $result['success'] && 'foogallery_migrate_storage_invalid_nonce' === $result['data']['code'], 'native wrong nonce refuses receiver' );
	$result = $response( 'switch', $request( 'table' ), 'GET' );
	$check( false === $result['success'] && 'foogallery_migrate_storage_invalid_request' === $result['data']['code'], 'GET cannot request conversion' );
	foreach ( array( 'preflight', 'switch', 'recover' ) as $operation ) {
		$result = $response( $operation, $request( 'table', 'recover' === $operation ) );
		$check( false === $result['success'] && 'foogallery_migrate_storage_calibration_required' === $result['data']['code'], 'missing production calibration refuses ' . $operation );
	}
	$check( array() === $queries, 'all native uncalibrated denials occur before storage SQL' );
	remove_filter( 'query', $trap );
	$measurement_profile = $profile; $measurement_profile['status'] = 'measurement_only';
	$check( is_wp_error( ( new StorageOperations( $measurement_profile ) )->dispatch( 'switch', $request( 'table' ) ) ), 'measurements alone never admit conversion' );
	foreach ( $schema->statements() as $index => $ddl ) { $created[] = $tables[$index]; dbDelta( $ddl ); }
	$check( $schema->verify(), 'fixture explicitly prepares existing schema, not normal activation' );

	foreach ( array( 'empty', 'packed-256', 'packed-1024', 'maps-256', 'strings-8', 'depth-32' ) as $shape ) {
		$reset(); $initial = array();
		if ( 'packed-256' === $shape || 'packed-1024' === $shape ) { $initial['items'] = array_fill( 0, 'packed-256' === $shape ? 256 : 1024, null ); }
		else if ( 'maps-256' === $shape ) {
			$initial['items'] = array();
			for ( $i = 0; $i < 256; ++$i ) { $initial['items']['key-' . $i] = array( 'ID' => (string) $i, 'unknown' => array( false, null, -0.0, '009' ) ); }
		} else if ( 'strings-8' === $shape ) { $initial['items'] = array_fill( 0, 8, str_repeat( '雪', 8192 ) ); }
		else if ( 'depth-32' === $shape ) { $node = null; for ( $i = 0; $i < 32; ++$i ) { $node = array( 'child' => $node ); } $initial['nested'] = $node; unset( $node ); }
		$logical = ( new LegacyStateCodec() )->snapshot_digest( $initial );
		$check( update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $initial, false ), 'source fixture persists: ' . $shape );
		unset( $initial ); wp_cache_delete( FOOGALLERY_MIGRATE_OPTION_DATA, 'options' );
		$bytes = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT LENGTH(option_value) FROM `' . $wpdb->options . '` WHERE option_name = %s', FOOGALLERY_MIGRATE_OPTION_DATA ) );
		add_filter( 'foogallery_migrate_storage_calibration', $synthetic );
		$result = $response( 'preflight', $request( 'table' ) );
		$check( true === $result['success'] && false === $result['data']['conversion_ready'] && $bytes === $result['data']['raw_bytes'], 'preflight observes current raw size without claiming readiness: ' . $shape );
		foreach ( array( 'table', 'option' ) as $target ) {
			gc_collect_cycles(); memory_reset_peak_usage();
			$base = memory_get_usage( false ); $allocated = memory_get_usage( true ); $start = microtime( true );
			$result = $response( 'switch', $request( $target ) );
			$elapsed = microtime( true ) - $start;
			$peak = memory_get_peak_usage( false ); $peak_allocated = memory_get_peak_usage( true );
			$measurements[] = array( 'shape' => $shape, 'target' => $target, 'raw_bytes' => $bytes,
				'base_bytes' => $base, 'peak_bytes' => $peak, 'allocated_base_bytes' => $allocated, 'allocated_peak_bytes' => $peak_allocated,
				'peak_delta_bytes' => max( 0, $peak - $base ), 'seconds' => $elapsed, 'success' => $result['success'] );
			$check( true === $result['success'] && 'stable' === $result['data']['phase'] && $target === $result['data']['backend'], 'native receiver verified switch to ' . $target . ': ' . $shape );
			$check( array_keys( $result['data'] ) === array( 'blog_id', 'backend', 'epoch', 'phase' ), 'bounded response exposes no source/digests: ' . $target . '/' . $shape );
		}
		remove_filter( 'foogallery_migrate_storage_calibration', $synthetic );
		$check( $logical === ( new LegacyStateCodec() )->snapshot_digest( get_option( FOOGALLERY_MIGRATE_OPTION_DATA ) ), 'complete reverse logical parity: ' . $shape );
		// Replacement is a separate destructive confirmation, never ordinary switch.
		add_filter( 'foogallery_migrate_storage_calibration', $synthetic );
		$result = $response( 'switch', $request( 'table' ) );
		$check( false === $result['success'], 'ordinary repeat switch retains evidence: ' . $shape );
		$replacement = $request( 'table' ); $replacement['confirmation'] = 'replace-retained-with-table';
		$replacement['nonce'] = wp_create_nonce( 'foogallery_migrate_storage_option_reimport_' . $blog );
		$result = $response( 'replace', $replacement );
		$check( true === $result['success'] && 3 === $result['data']['epoch'] && 'table' === $result['data']['backend'], 'explicit native receiver replacement succeeds: ' . $shape );
		$result = $response( 'switch', $request( 'option' ) );
		$check( true === $result['success'] && 4 === $result['data']['epoch'], 'replacement remains reversible: ' . $shape );
		$check( $logical === ( new LegacyStateCodec() )->snapshot_digest( get_option( FOOGALLERY_MIGRATE_OPTION_DATA ) ), 'repeat receiver preserves complete logical state: ' . $shape );
		remove_filter( 'foogallery_migrate_storage_calibration', $synthetic );
	}
	$reset();
} finally {
	if ( isset( $trap ) ) { remove_filter( 'query', $trap ); }
	remove_filter( 'foogallery_migrate_storage_calibration', $synthetic );
	$_POST = $old_post;
	if ( null === $old_method ) { unset( $_SERVER['REQUEST_METHOD'] ); } else { $_SERVER['REQUEST_METHOD'] = $old_method; }
	foreach ( $names as $name ) { delete_option( $name ); }
	foreach ( array_reverse( $created ) as $table ) { $wpdb->query( 'DROP TABLE `' . $table . '`' ); }
	unset( $GLOBALS['foogallery_migrate_engine_instance'] );
}
foreach ( $names as $name ) { $check( null === $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM `' . $wpdb->options . '` WHERE option_name = %s', $name ) ), 'owned option removed: ' . $name ); }
echo wp_json_encode( array( 'status' => 'pass', 'check_count' => count( $checks ), 'checks' => $checks, 'blog_id' => $blog,
	'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'database' => $wpdb->get_var( 'SELECT VERSION()' ),
	'calibration_status' => 'measurement_only', 'fingerprint' => StorageCalibration::fingerprint(), 'measurements' => $measurements,
	'limits' => 'Six compact fixture shapes, synthetic trusted routing policy, CLI only. Not worst-case calibration, SAPI/floor/deadline or independent approval.' ), JSON_UNESCAPED_SLASHES ) . "\n";
