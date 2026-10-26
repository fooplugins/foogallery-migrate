<?php
/** Fresh authorized public recovery request for an invocation-owned Cove fixture.
 * Modern CLI only. No fault hooks, gate reflection or marker deletion here.
 */
if ( 'cli' !== PHP_SAPI || 2 !== $argc || ! is_file( $argv[1] . '/wp-load.php' ) || ! preg_match( '/^fgm-preflight-[a-z0-9-]+\.localhost$/D', basename( dirname( $argv[1] ) ) ) ) { exit( 2 ); }
$input = json_decode( stream_get_contents( STDIN, 4096 ), true );
if ( ! is_array( $input ) || array_keys( $input ) !== array( 'blog_id', 'plugin', 'target', 'identifier', 'migrated_id' ) || ! is_int( $input['blog_id'] ) || $input['blog_id'] < 1 || ! is_string( $input['plugin'] ) ||
	! in_array( $input['target'], array( 'option', 'table' ), true ) || ! is_string( $input['identifier'] ) || strlen( $input['identifier'] ) > 1024 || ! is_int( $input['migrated_id'] ) || $input['migrated_id'] < 1 ||
	! in_array( basename( $input['plugin'] ), array( 'foogallery-migrate-source', 'foogallery-migrate-package' ), true ) || realpath( dirname( $input['plugin'] ) ) !== realpath( $argv[1] . '/wp-content/plugins' ) || ! is_file( $input['plugin'] . '/migrate.php' ) ) { exit( 3 ); }
define( 'WP_USE_THEMES', false ); define( 'DISABLE_WP_CRON', true ); define( 'DOING_AJAX', true );
require $argv[1] . '/wp-load.php';
if ( 0 !== strpos( DB_NAME, 'cove_fgm_preflight_' ) || realpath( ABSPATH ) !== realpath( $argv[1] ) ) { exit( 4 ); }
if ( is_multisite() && get_current_blog_id() !== $input['blog_id'] ) { switch_to_blog( $input['blog_id'] ); }
if ( get_current_blog_id() !== $input['blog_id'] || ( is_multisite() && ! get_site( $input['blog_id'] ) ) ) { exit( 5 ); }
wp_set_current_user( 1 );
if ( ! current_user_can( 'manage_options' ) || ( is_multisite() && ! is_super_admin() ) ) { exit( 6 ); }
require_once $input['plugin'] . '/migrate.php';
if ( ! is_admin() ) { new \FooPlugins\FooGalleryMigrate\Init(); }
// Same explicitly synthetic small-fixture profile as the parent, not a shipped
// calibration and not evidence for literal runtime floors or production sizing.
$profile = function () { return array( 'version' => 1, 'status' => 'approved', 'fingerprint' => \FooPlugins\FooGalleryMigrate\StorageCalibration::fingerprint(), 'evidence_sha256' => str_repeat( 'a', 64 ), 'policy' => array( 'max_raw_bytes' => 1048576, 'amplification' => 64, 'reserve_bytes' => 33554432 ) ); };
add_filter( 'foogallery_migrate_storage_calibration', $profile );
$die = function () { return function () { throw new RuntimeException( 'owned-json-response' ); }; };
add_filter( 'wp_die_ajax_handler', $die ); $_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = array( 'action' => 'foogallery_migrate_storage', 'operation' => 'recover', 'blog_id' => (string) $input['blog_id'], 'scope' => 'site', 'target' => $input['target'], 'confirmation' => 'recover-storage', 'nonce' => wp_create_nonce( 'foogallery_migrate_storage_recover_' . $input['blog_id'] ) );
ob_start();
try { do_action( 'admin_post_foogallery_migrate_storage' ); throw new RuntimeException( 'Receiver did not emit JSON.' ); }
catch ( RuntimeException $error ) { if ( 'owned-json-response' !== $error->getMessage() ) { throw $error; } }
finally { $raw = ob_get_clean(); remove_filter( 'wp_die_ajax_handler', $die ); remove_filter( 'foogallery_migrate_storage_calibration', $profile ); }
$response = json_decode( $raw, true );
if ( ! is_array( $response ) || ! isset( $response['success'], $response['data'] ) || true !== $response['success'] ) { exit( 7 ); }
$engine = foogallery_migrate_migrator_instance(); $saved = $engine->get_migrated_object( $input['identifier'] );
$history = is_object( $saved ) && $saved->migrated && (int) $saved->migrated_id === $input['migrated_id'];
$post = get_post( $input['migrated_id'] );
if ( ! $post || 'foogallery' !== $post->post_type || ! is_array( get_post_meta( $input['migrated_id'], '_foogallery_attachments', true ) ) ) { exit( 8 ); }
$progress = (int) $engine->get_gallery_migrator()->get_state()['progress'];
\FooPlugins\FooGalleryMigrate\StorageMaintenanceGate::current()->shutdown_writer();
echo json_encode( array( 'pid' => getmypid(), 'response' => $response, 'history_retained' => $history, 'progress' => $progress ) ) . PHP_EOL;
