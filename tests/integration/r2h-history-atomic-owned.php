<?php
/**
 * R2h SQL proof, only after source review on the owned empty mY4iGH site:
 * wp --path=/private/tmp/foogallery-migrate-r0.mY4iGH --skip-plugins --skip-themes eval-file tests/integration/r2h-history-atomic-owned.php /private/tmp/foogallery-migrate-r0.mY4iGH --user=fgmr0admin
 * The included contract creates and removes only its own shared tables/options.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! isset( $args ) || count( $args ) !== 1 ||
	$args[0] !== '/private/tmp/foogallery-migrate-r0.mY4iGH' ||
	realpath( $args[0] ) !== realpath( ABSPATH ) ) {
	throw new RuntimeException( 'Exact owned R2h WP-CLI site required.' );
}
$r2h_site = realpath( $args[0] );
$r2h_owner = $r2h_site . '/.fgm-r0-owner.json';
$r2h_manifest = is_file( $r2h_owner ) ? json_decode( file_get_contents( $r2h_owner ), true ) : null;
$r2h_repo = realpath( dirname( dirname( __DIR__ ) ) );
if ( ! is_array( $r2h_manifest ) || ! isset( $r2h_manifest['kind'], $r2h_manifest['site'], $r2h_manifest['database'],
	$r2h_manifest['host'], $r2h_manifest['repo'], $r2h_manifest['container'], $r2h_manifest['container_id'] ) ||
	$r2h_manifest['kind'] !== 'foogallery-migrate-r0-v1' || realpath( $r2h_manifest['site'] ) !== $r2h_site ||
	$r2h_manifest['database'] !== 'fgm_r0_my4igh' || $r2h_manifest['container'] !== 'fgm-r0-mY4iGH' ||
	$r2h_manifest['container_id'] !== 'c790a47601f2fb569023e11c43c514428df1e158ab954e9f7c571da67d018a7e' ||
	realpath( $r2h_manifest['repo'] ) !== $r2h_repo ||
	realpath( $r2h_site . '/wp-content/plugins/foogallery-migrate' ) !== $r2h_repo ) {
	throw new RuntimeException( 'Owned R2h manifest, container, or repository differs.' );
}
$r2h_live = array(); $r2h_code = 0;
exec( 'docker inspect --format ' . escapeshellarg( '{{.Id}} {{index .Config.Labels "foogallery-migrate-r0"}}' ) .
	' ' . escapeshellarg( $r2h_manifest['container'] ) . ' 2>/dev/null', $r2h_live, $r2h_code );
if ( 0 !== $r2h_code || count( $r2h_live ) !== 1 ||
	trim( $r2h_live[0] ) !== $r2h_manifest['container_id'] . ' owned' ) {
	throw new RuntimeException( 'Live owned R2h SQL container differs.' );
}
ini_set( 'memory_limit', '256M' );
global $wpdb;
if ( ini_get( 'memory_limit' ) !== '256M' || is_multisite() ||
	DB_NAME !== $r2h_manifest['database'] || DB_HOST !== $r2h_manifest['host'] ||
	$wpdb->get_var( 'SELECT DATABASE()' ) !== $r2h_manifest['database'] || $wpdb->last_error ||
	get_current_user_id() < 1 || wp_get_current_user()->user_login !== 'fgmr0admin' ||
	! current_user_can( 'manage_options' ) ||
	(int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $wpdb->posts . '`' ) !== 6 || $wpdb->last_error ) {
	throw new RuntimeException( 'R2h loaded site, SQL, admin, protected posts, or memory differs.' );
}
$r2h_owned_options = array( 'foogallery-migrate-data', 'foogallery-migrate-settings',
	'foogallery-migrate-migrated-legacy-backup', 'foogallery-migrate-data-storage-backup',
	'foogallery_migrate_storage', 'foogallery_migrate_storage_control', 'foogallery_migrate_storage_authority',
	'foogallery_migrate_storage_maintenance', 'foogallery_migrate_storage_cleanup',
	'foogallery_migrate_storage_schema_version', 'foogallery_migrate_storage_schema_lock',
	'foogallery-migrate-store-schema-version', 'foogallery-migrate-store-migration-version',
	'fgm_r2e_native_turn_pending_v1', 'fgm_r2g_native_target_pending_v1' );
foreach ( $r2h_owned_options as $r2h_option ) {
	$r2h_found = $wpdb->get_results( $wpdb->prepare( 'SELECT option_name FROM `' . $wpdb->options . '` WHERE option_name=%s LIMIT 2', $r2h_option ), ARRAY_A );
	if ( ! is_array( $r2h_found ) || $wpdb->last_error || count( $r2h_found ) ) {
		throw new RuntimeException( 'Owned R2h state, option, or marker is not absent.' );
	}
}
foreach ( array( 'foogallery_migrate_legacy_state', 'foogallery_migrate_legacy_records',
	'foogallery_migrate_objects', 'foogallery_migrate_state' ) as $r2h_suffix ) {
	$r2h_table = $wpdb->base_prefix . $r2h_suffix;
	$r2h_found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $r2h_table ) ) );
	if ( $wpdb->last_error || null !== $r2h_found ) { throw new RuntimeException( 'Owned R2h SQL table is not absent.' ); }
}
$r2h_journal_rows = $wpdb->get_results( $wpdb->prepare( 'SELECT option_value FROM `' . $wpdb->options . '` WHERE option_name=%s LIMIT 2',
	'fgm_cleanup_owned_journey_v1' ), ARRAY_A );
if ( ! is_array( $r2h_journal_rows ) || $wpdb->last_error || count( $r2h_journal_rows ) !== 1 ) {
	throw new RuntimeException( 'Accepted cleanup journal is absent or ambiguous.' );
}
$r2h_journal_raw = $r2h_journal_rows[0]['option_value'];
$r2h_journal = maybe_unserialize( $r2h_journal_raw );
if ( ! is_array( $r2h_journal ) || ! isset( $r2h_journal['identity'], $r2h_journal['owner'],
	$r2h_journal['phase'], $r2h_journal['posts'], $r2h_journal['media_hash'] ) ||
	$r2h_journal['identity'] !== 'a23ab27adfb9ebf653d71c2f07e5deb6885ee9ec32255d9a176ef73bf07e7965' ||
	$r2h_journal['owner'] !== 'fgm-cleanup-mY4iGH' || $r2h_journal['phase'] !== 'removed' ||
	$r2h_journal['posts'] !== array( 'page' => 4, 'foogallery' => 5, 'attachment' => 6 ) ||
	get_option( 'fgm_cleanup_owned_journey_v1' ) !== $r2h_journal ) {
	throw new RuntimeException( 'Accepted cleanup journal identity differs.' );
}
$r2h_posts_sql = 'SELECT ID, post_type, post_status, post_title, post_content FROM `' . $wpdb->posts . '` ORDER BY ID ASC LIMIT 7';
$r2h_posts = $wpdb->get_results( $r2h_posts_sql, ARRAY_A );
$r2h_media = get_attached_file( 6 );
$r2h_media_real = is_string( $r2h_media ) ? realpath( $r2h_media ) : false;
if ( ! is_array( $r2h_posts ) || $wpdb->last_error || count( $r2h_posts ) !== 6 ||
	! is_string( $r2h_media_real ) || strpos( $r2h_media_real, $r2h_site . '/wp-content/uploads/' ) !== 0 ||
	! is_file( $r2h_media ) || hash_file( 'sha256', $r2h_media ) !== $r2h_journal['media_hash'] ) {
	throw new RuntimeException( 'Accepted protected posts or media differ before SQL proof.' );
}
$r2h_protected = function () use ( $wpdb, $r2h_posts_sql, $r2h_posts, $r2h_media, $r2h_journal_raw, $r2h_journal ) {
	$journal = $wpdb->get_results( $wpdb->prepare( 'SELECT option_value FROM `' . $wpdb->options . '` WHERE option_name=%s LIMIT 2',
		'fgm_cleanup_owned_journey_v1' ), ARRAY_A );
	$posts = $wpdb->get_results( $r2h_posts_sql, ARRAY_A );
	if ( ! is_array( $journal ) || count( $journal ) !== 1 || $journal[0]['option_value'] !== $r2h_journal_raw ||
		get_option( 'fgm_cleanup_owned_journey_v1' ) !== $r2h_journal || ! is_array( $posts ) ||
		$posts !== $r2h_posts || $wpdb->last_error || ! is_file( $r2h_media ) ||
		hash_file( 'sha256', $r2h_media ) !== $r2h_journal['media_hash'] ) {
		throw new RuntimeException( 'Protected cleanup journal, six posts, or media changed during R2h SQL proof.' );
	}
};
define( 'FOOGM_STORAGE_TEST_DISPOSABLE', true );
define( 'FOOGM_STORAGE_TEST_ROOT', $r2h_repo );
try { require __DIR__ . '/legacy-history-mutations.php'; }
catch ( Exception $error ) { $r2h_protected(); throw $error; }
catch ( Throwable $error ) { $r2h_protected(); throw $error; }
$r2h_protected();
