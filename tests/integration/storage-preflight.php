<?php
/**
 * Real WordPress, size-only admission checkpoint. Never use on a live site.
 * wp --skip-plugins --skip-themes --user=1 eval 'define("FOOGM_STORAGE_TEST_DISPOSABLE", true);
 * define("FOOGM_STORAGE_TEST_ROOT", "/absolute/copied/plugin"); require "/absolute/harness/storage-preflight.php";'
 * No conversion, provisioning, history recovery or legacy-floor acceptance.
 */
if ( ! defined( 'ABSPATH' ) || ! defined( 'FOOGM_STORAGE_TEST_DISPOSABLE' ) || true !== FOOGM_STORAGE_TEST_DISPOSABLE ||
	! defined( 'FOOGM_STORAGE_TEST_ROOT' ) ) {
	throw new RuntimeException( 'An explicitly disposable site and copied candidate root are required.' );
}
require_once FOOGM_STORAGE_TEST_ROOT . '/migrate.php';

use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\StoragePreflight;

// Refuse any pre-existing plugin state, including false/empty backup rows.
global $wpdb;
$options = array( FOOGALLERY_MIGRATE_OPTION_DATA, FOOGALLERY_MIGRATE_OPTION_SETTINGS,
	StorageAuthority::CONTROL_OPTION, StorageAuthority::AUTHORITY_OPTION, StorageAuthority::MAINTENANCE_OPTION,
	StorageAuthority::STORAGE_BACKUP_OPTION );
foreach ( $options as $name ) {
	if ( null !== $wpdb->get_var( $wpdb->prepare( 'SELECT 1 AS present FROM `' . $wpdb->options . '` WHERE option_name = %s LIMIT 1', $name ) ) || ! empty( $wpdb->last_error ) ) {
		throw new RuntimeException( 'Refusing existing plugin options or an unhealthy fixture.' );
	}
}
if ( false !== StorageAuthority::option_mode_error() || ! current_user_can( 'manage_options' ) ) {
	throw new RuntimeException( 'A virgin option-authority fixture and an existing administrator are required.' );
}
$checks = array();
$check = function ( $condition, $label ) use ( &$checks ) {
	if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $label ); }
	$checks[] = $label;
};
$owned = array();
$blocked_reads = 0;
$forbid_option = function () use ( &$blocked_reads ) {
	$blocked_reads++;
	throw new RuntimeException( 'Preflight attempted to load a protected option.' );
};
$user = get_current_user_id();
$filters = array();
$queries = array();
$capture = function ( $query ) use ( &$queries ) { $queries[] = $query; return $query; };

try {
	// WordPress's UTF-8 options column accepts NUL but not invalid UTF-8 bytes.
	$data = array( 'opaque' => array( '09' => false, 9 => null, 'binary' => "valid\x00bytes", 'unicode' => '雪' ), 'cursor' => 27 );
	$owned[] = FOOGALLERY_MIGRATE_OPTION_DATA;
	$check( add_option( FOOGALLERY_MIGRATE_OPTION_DATA, $data, '', 'no' ), 'seed invocation-owned legacy option' );
	$raw_before = $wpdb->get_row( $wpdb->prepare( 'SELECT option_value, autoload FROM `' . $wpdb->options . '` WHERE option_name = %s', FOOGALLERY_MIGRATE_OPTION_DATA ), ARRAY_A );
	$blog = get_current_blog_id();
	$request = array( 'blog_id' => (string) $blog, 'scope' => 'site', 'target' => 'table',
		'confirmation' => 'switch-to-table', 'nonce' => wp_create_nonce( 'foogallery_migrate_storage_option_import_' . $blog ) );
	// Synthetic fixture policy, NOT a production calibration.
	$policy = array( 'max_raw_bytes' => 1048576, 'amplification' => 32, 'reserve_bytes' => 16777216 );
	$preflight = new StoragePreflight( $wpdb, $policy );
	foreach ( array( FOOGALLERY_MIGRATE_OPTION_DATA, StorageAuthority::STORAGE_BACKUP_OPTION ) as $name ) {
		$hook = 'pre_option_' . $name;
		add_filter( $hook, $forbid_option );
		$filters[] = $hook;
	}
	add_filter( 'query', $capture );
	$result = $preflight->option_import( $request );
	remove_filter( 'query', $capture );
	$check( is_array( $result ) && true === $result['decode_admitted'] && false === $result['conversion_ready'], 'nonce-authorized read-only observation, not conversion permission' );
	$check( $result['raw_bytes'] === strlen( $raw_before['option_value'] ), 'SQL LENGTH reports raw bytes including binary and UTF-8' );
	$check( array( $wpdb->prepare( 'SELECT LENGTH(option_value) AS option_bytes FROM `' . $wpdb->options . '` WHERE option_name = %s LIMIT 1', FOOGALLERY_MIGRATE_OPTION_DATA ) ) === $queries, 'one fixed-current-site size-only SQL query' );
	$check( $blog === $result['blog_id'], 'scope comes from current WordPress blog' );
	$wrong = $request;
	$wrong['blog_id'] = (string) ( $blog + 1 );
	$check( is_wp_error( $preflight->option_import( $wrong ) ), 'reject cross-blog request' );
	$wrong = $request;
	$wrong['nonce'] = wp_create_nonce( 'foogallery_migrate' );
	$check( is_wp_error( $preflight->option_import( $wrong ) ), 'reject generic migration nonce' );
	$wrong = $request;
	$wrong['scope'] = 'network';
	$check( is_wp_error( $preflight->option_import( $wrong ) ), 'reject network provisioning through site import' );
	$wrong = $request;
	$wrong['confirmation'] = true;
	$check( is_wp_error( $preflight->option_import( $wrong ) ), 'require explicit typed confirmation' );
	wp_set_current_user( 0 );
	$check( 'foogallery_migrate_storage_forbidden' === $preflight->option_import( $request )->get_error_code(), 'native anonymous capability denial before storage access' );
	wp_set_current_user( $user );
	$check( 'foogallery_migrate_storage_decode_policy_required' === ( new StoragePreflight( $wpdb ) )->option_import( $request )->get_error_code(), 'no inferred production decode calibration' );
	$deny = $policy;
	$deny['max_raw_bytes'] = 1;
	$check( 'foogallery_migrate_storage_decode_refused' === ( new StoragePreflight( $wpdb, $deny ) )->option_import( $request )->get_error_code(), 'oversize option refused without hydration or staging' );
	foreach ( array( StorageAuthority::STORAGE_BACKUP_OPTION ) as $name ) {
		$owned[] = $name;
		// Direct SQL avoids the deliberate get_option trap during fixture setup.
		$check( false !== $wpdb->insert( $wpdb->options, array( 'option_name' => $name, 'option_value' => '', 'autoload' => 'no' ) ), 'seed empty backup-only row: ' . $name );
		$check( is_wp_error( StorageAuthority::option_mode_error() ), 'backup-only evidence requires recovery: ' . $name );
		$check( false !== $wpdb->delete( $wpdb->options, array( 'option_name' => $name ) ), 'remove invocation-owned backup row: ' . $name );
	}
	$check( 0 === $blocked_reads, 'no protected get_option calls or deserialization' );
	$raw_after = $wpdb->get_row( $wpdb->prepare( 'SELECT option_value, autoload FROM `' . $wpdb->options . '` WHERE option_name = %s', FOOGALLERY_MIGRATE_OPTION_DATA ), ARRAY_A );
	$check( $raw_before === $raw_after, 'legacy source bytes and autoload metadata unchanged' );
	$check( false === StorageAuthority::option_mode_error(), 'virgin authority restored after backup probes' );
} finally {
	remove_filter( 'query', $capture );
	foreach ( $filters as $hook ) { remove_filter( $hook, $forbid_option ); }
	wp_set_current_user( $user );
	foreach ( $owned as $name ) { delete_option( $name ); }
}
foreach ( $options as $name ) {
	$check( null === $wpdb->get_var( $wpdb->prepare( 'SELECT 1 AS present FROM `' . $wpdb->options . '` WHERE option_name = %s LIMIT 1', $name ) ) && empty( $wpdb->last_error ), 'no retained plugin option: ' . $name );
}
echo wp_json_encode( array( 'status' => 'pass', 'scope' => 'read-only authorization/raw-size checkpoint only',
	'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'database' => $wpdb->get_var( 'SELECT VERSION()' ),
	'blog_id' => get_current_blog_id(), 'multisite' => is_multisite(), 'checks' => $checks, 'check_count' => count( $checks ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
