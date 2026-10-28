<?php
/**
 * Fresh disposable queue SQL proof, after source QA approval only:
 * wp --path=SITE --skip-plugins --skip-themes eval-file tests/integration/r2k-image-tag-queue-owned.php SITE CONTAINER_ID --user=OWNED_ADMIN
 * SITE and CONTAINER_ID must be the new fixture's exact manifest identity.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! isset( $args ) || count( $args ) !== 2 ||
	! is_string( $args[0] ) || ! is_string( $args[1] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $args[1] ) ||
	realpath( $args[0] ) !== realpath( ABSPATH ) ) { throw new RuntimeException( 'Exact owned queue site and Docker ID required.' ); }
$site = realpath( $args[0] ); $repo = realpath( dirname( dirname( __DIR__ ) ) );
if ( ! preg_match( '#^/private/tmp/foogallery-migrate-r0\.[A-Za-z0-9]+$#D', $site ) ) {
	throw new RuntimeException( 'Queue SQL proof requires a fresh canonical R0 fixture path.' );
}
$suffix = substr( $site, strlen( '/private/tmp/foogallery-migrate-r0.' ) );
$manifest_path = $site . '/.fgm-r0-owner.json';
$manifest = is_file( $manifest_path ) && ! is_link( $manifest_path ) ? json_decode( file_get_contents( $manifest_path ), true ) : null;
if ( ! is_array( $manifest ) || ! isset( $manifest['kind'], $manifest['site'], $manifest['database'], $manifest['host'],
	$manifest['repo'], $manifest['container'], $manifest['container_id'] ) ||
	$manifest['kind'] !== 'foogallery-migrate-r0-v1' || realpath( $manifest['site'] ) !== $site ||
	$manifest['container_id'] !== $args[1] || realpath( $manifest['repo'] ) !== $repo ||
	realpath( $site . '/wp-content/plugins/foogallery-migrate' ) !== $repo ||
	$manifest['database'] !== 'fgm_r0_' . strtolower( $suffix ) ||
	$manifest['container'] !== 'fgm-r0-' . $suffix ||
	! preg_match( '/^127\.0\.0\.1:([0-9]{1,5})$/D', $manifest['host'], $host_match ) ||
	(int) $host_match[1] < 1 || (int) $host_match[1] > 65535 ) {
	throw new RuntimeException( 'Fresh queue fixture manifest/repository identity differs.' );
}
$live = array(); $code = 0;
exec( 'docker inspect --format ' . escapeshellarg( '{{.Id}} {{index .Config.Labels "foogallery-migrate-r0"}} {{.State.Running}}' ) .
	' ' . escapeshellarg( $manifest['container'] ) . ' 2>/dev/null', $live, $code );
if ( 0 !== $code || count( $live ) !== 1 || trim( $live[0] ) !== $args[1] . ' owned true' ) {
	throw new RuntimeException( 'Live owned queue container differs.' );
}
ini_set( 'memory_limit', '256M' );
global $wpdb;
if ( ini_get( 'memory_limit' ) !== '256M' || is_multisite() || DB_NAME !== $manifest['database'] ||
	DB_HOST !== $manifest['host'] || $wpdb->get_var( 'SELECT DATABASE()' ) !== $manifest['database'] ||
	$wpdb->last_error || get_current_user_id() < 1 || ! current_user_can( 'manage_options' ) ||
	(int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $wpdb->posts . '`' ) > 3 || $wpdb->last_error ) {
	throw new RuntimeException( 'Fresh queue site, DB, administrator, posts, or effective memory differs.' );
}
$fixed_options = array( 'foogallery-migrate-data', 'foogallery-migrate-settings',
	'foogallery_migrate_storage_control', 'foogallery_migrate_storage_authority', 'foogallery_migrate_storage_maintenance',
	'foogallery_migrate_storage_cleanup', 'foogallery-migrate-data-storage-backup',
	'foogallery_migrate_storage_schema_version', 'foogallery_migrate_storage_schema_lock' );
foreach ( $fixed_options as $name ) {
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT option_name FROM `' . $wpdb->options . '` WHERE option_name=%s LIMIT 2', $name ), ARRAY_A );
	if ( ! is_array( $rows ) || $wpdb->last_error || count( $rows ) ) { throw new RuntimeException( 'Owned option is not pristine: ' . $name ); }
}
$prefixes = array( 'foogallery_migrate_storage_writer_', '_transient_foogallery_migrate_content_result_',
	'_transient_timeout_foogallery_migrate_content_result_' );
foreach ( $prefixes as $prefix ) {
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT option_name FROM `' . $wpdb->options . '` WHERE option_name LIKE %s LIMIT 1',
		$wpdb->esc_like( $prefix ) . '%' ), ARRAY_A );
	if ( ! is_array( $rows ) || $wpdb->last_error || count( $rows ) ) { throw new RuntimeException( 'Owned writer or transient family is not pristine.' ); }
}
foreach ( array( $wpdb->base_prefix . 'foogallery_migrate_state', $wpdb->base_prefix . 'foogallery_migrate_records' ) as $table ) {
	$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	if ( $wpdb->last_error || null !== $found ) { throw new RuntimeException( 'Owned legacy or shared table is not pristine.' ); }
}
define( 'FOOGM_STORAGE_TEST_DISPOSABLE', true );
define( 'FOOGM_STORAGE_TEST_ROOT', $repo );
require __DIR__ . '/legacy-image-tag-queue.php';
