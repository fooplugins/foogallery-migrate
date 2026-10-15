<?php
/** Actual activated admin page + Chromium + authenticated HTTP public receiver. */
if ( ! defined( 'ABSPATH' ) || ! defined( 'FOOGM_STORAGE_TEST_DISPOSABLE' ) || true !== FOOGM_STORAGE_TEST_DISPOSABLE || ! defined( 'FOOGM_BROWSER_MODULE' ) || ! defined( 'FOOGM_BROWSER_EVIDENCE' ) ) { throw new RuntimeException( 'Owned browser runner required.' ); }
$root = FOOGM_STORAGE_TEST_ROOT; require_once $root . '/migrate.php';
use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
global $wpdb;
$blog = get_current_blog_id(); $schema = new LegacyStorageSchema( $wpdb ); $tables = array_keys( $schema->definitions() );
foreach ( $tables as $table ) { if ( null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) || $wpdb->last_error ) { throw new RuntimeException( 'Refusing pre-existing table.' ); } }
$main = is_multisite() ? get_main_site_id( get_main_network_id() ) : $blog; $main_options = $wpdb->get_blog_prefix( $main ) . 'options';
foreach ( array( LegacyStorageSchema::VERSION_OPTION, LegacyStorageSchema::LOCK_OPTION ) as $name ) { if ( null !== $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM `' . $main_options . '` WHERE option_name=%s', $name ) ) ) { throw new RuntimeException( 'Refusing existing shared metadata.' ); } }
$names = array( FOOGALLERY_MIGRATE_OPTION_DATA, FOOGALLERY_MIGRATE_OPTION_SETTINGS, StorageAuthority::CONTROL_OPTION, StorageAuthority::AUTHORITY_OPTION, StorageAuthority::MAINTENANCE_OPTION, StorageAuthority::STORAGE_BACKUP_OPTION, 'active_plugins' );
$missing = new stdClass(); $saved = array(); foreach ( $names as $name ) { $saved[$name] = get_option( $name, $missing ); }
$network_active = is_multisite() ? get_site_option( 'active_sitewide_plugins', null ) : null;
$old_sessions = get_user_meta( 1, 'session_tokens', true ); $had_sessions = metadata_exists( 'user', 1, 'session_tokens' );
$profile_path = WPMU_PLUGIN_DIR . '/fgm-owned-browser-' . wp_generate_password( 12, false, false ) . '.php';
if ( file_exists( $profile_path ) ) { throw new RuntimeException( 'Refusing existing profile path.' ); }
$private = tempnam( getenv( 'TMPDIR' ), 'fgm-browser-' ); if ( false === $private ) { throw new RuntimeException( 'Private config unavailable.' ); } chmod( $private, 0600 );
try {
	update_option( FOOGALLERY_MIGRATE_OPTION_DATA, array( 'native_browser' => 'initial-owned-option' ), false );
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	$activated = activate_plugin( plugin_basename( FOOGM_FILE ), '', is_multisite() ); if ( is_wp_error( $activated ) ) { throw new RuntimeException( $activated->get_error_message() ); }
	$token = WP_Session_Tokens::get_instance( 1 )->create( time() + 600 ); $host = parse_url( home_url( '/' ), PHP_URL_HOST );
	$cookies = array(); foreach ( array( SECURE_AUTH_COOKIE => 'secure_auth', LOGGED_IN_COOKIE => 'logged_in' ) as $name => $scheme ) { $cookies[] = array( 'name' => $name, 'value' => wp_generate_auth_cookie( 1, time() + 600, $scheme, $token ), 'domain' => $host, 'path' => '/', 'secure' => true, 'httpOnly' => true, 'sameSite' => 'Lax' ); }
	$profile_code = '<?php /* INVOCATION-OWNED SYNTHETIC TEST PROFILE. NEVER SHIP OR APPROVE AS PRODUCTION. */ add_filter("foogallery_migrate_storage_calibration", function () { return array("version"=>1,"status"=>"approved","fingerprint"=>\\FooPlugins\\FooGalleryMigrate\\StorageCalibration::fingerprint(),"evidence_sha256"=>str_repeat("b",64),"policy"=>array("max_raw_bytes"=>65536,"amplification"=>64,"reserve_bytes"=>33554432)); });';
	if ( ! is_dir( WPMU_PLUGIN_DIR ) && ! wp_mkdir_p( WPMU_PLUGIN_DIR ) ) { throw new RuntimeException( 'Owned MU fixture directory unavailable.' ); }
	$config = array( 'playwright' => FOOGM_BROWSER_MODULE, 'cookies' => $cookies, 'url' => admin_url( 'options-general.php?page=foogallery-migrate-storage' ), 'baseurl' => home_url( '/' ), 'profile_path' => $profile_path, 'profile_code' => $profile_code, 'cove' => '/opt/homebrew/bin/cove', 'site' => explode( '.', $host )[0], 'screenshots' => FOOGM_BROWSER_EVIDENCE );
	if ( false === file_put_contents( $private, wp_json_encode( $config ) ) ) { throw new RuntimeException( 'Private browser configuration failed.' ); }
	$pipes = array(); $process = proc_open( array( '/usr/bin/env', 'node', __DIR__ . '/../tools/storage-admin-browser.cjs', $private ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
	if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Browser process unavailable.' ); }
	$output = stream_get_contents( $pipes[1] ); $error = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] ); $exit = proc_close( $process );
	if ( 0 !== $exit ) { throw new RuntimeException( 'Browser failed: ' . $error ); }
	$result = json_decode( trim( $output ), true ); if ( ! is_array( $result ) || 'pass' !== $result['status'] || count( $result['checks'] ) !== $result['check_count'] ) { throw new RuntimeException( 'Invalid browser result.' ); }
	$result['blog_id'] = $blog; $result['wordpress'] = get_bloginfo( 'version' ); $result['php'] = PHP_VERSION; $result['database'] = $wpdb->get_var( 'SELECT VERSION()' );
} finally {
	if ( file_exists( $private ) ) { unlink( $private ); } if ( file_exists( $profile_path ) ) { unlink( $profile_path ); }
	foreach ( array_reverse( $tables ) as $table ) { $wpdb->query( 'DROP TABLE IF EXISTS `' . $table . '`' ); }
	foreach ( $saved as $name => $value ) { if ( $missing === $value ) { delete_option( $name ); } else { update_option( $name, $value ); } }
	foreach ( array( LegacyStorageSchema::VERSION_OPTION, LegacyStorageSchema::LOCK_OPTION ) as $name ) { $wpdb->query( $wpdb->prepare( 'DELETE FROM `' . $main_options . '` WHERE option_name=%s', $name ) ); }
	if ( $had_sessions ) { update_user_meta( 1, 'session_tokens', $old_sessions ); } else { delete_user_meta( 1, 'session_tokens' ); }
	if ( is_multisite() ) { if ( null !== $network_active ) { update_site_option( 'active_sitewide_plugins', $network_active ); } else { delete_site_option( 'active_sitewide_plugins' ); } }
}
echo wp_json_encode( $result ) . "\n";
