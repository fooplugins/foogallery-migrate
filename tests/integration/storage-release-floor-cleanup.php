<?php
/** Owned PHP 5.4 / WordPress 5.0 lifecycle journey: one step per fresh WP-CLI process. */
use FooPlugins\FooGalleryMigrate\StorageAdmin;
use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\StorageCli;
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'ABSPATH' ) ||
	! is_file( '/fixture-owner.json' ) || ! isset( $args[0] ) || count( $args ) !== 1 ||
	! in_array( $args[0], array( 'init', 'verify', 'seed-state', 'provision', 'switch', 'recover',
		'edit', 'clear', 'seed-repeat-clear', 'repeat-clear', 'verify-clear', 'remove-all',
		'repeat-remove-all', 'prepare-uninstall', 'uninstall', 'verify-uninstalled' ), true ) ) {
	throw new RuntimeException( 'Exactly one owned floor lifecycle step is required.' );
}
$step = $args[0];
ini_set( 'memory_limit', '256M' );
$manifest = json_decode( file_get_contents( '/fixture-owner.json' ), true );
$repo = realpath( dirname( dirname( __DIR__ ) ) );
if ( ! is_array( $manifest ) || ! isset( $manifest['kind'], $manifest['fixture'], $manifest['site'],
	$manifest['repo'], $manifest['network'], $manifest['container'], $manifest['container_id'],
	$manifest['database'] ) || $manifest['kind'] !== 'foogallery-migrate-floor-v1' ||
	! preg_match( '~^/(?:private/)?tmp/foogallery-migrate-floor\.[A-Za-z0-9]+$~D', $manifest['fixture'] ) ||
	$manifest['site'] !== $manifest['fixture'] . '/site' || realpath( ABSPATH ) !== '/site' ||
	$repo !== '/repo' || realpath( ABSPATH . 'wp-content/plugins/foogallery-migrate' ) !== $repo ||
	! preg_match( '/^fgm-floor-[A-Za-z0-9]+$/D', $manifest['network'] ) ||
	$manifest['container'] !== $manifest['network'] . '-db' ||
	! preg_match( '/^[a-f0-9]{64}$/D', $manifest['container_id'] ) ||
	! preg_match( '/^fgm_floor_[a-z0-9]+$/D', $manifest['database'] ) ||
	DB_NAME !== $manifest['database'] || DB_HOST !== $manifest['container'] ||
	PHP_VERSION !== '5.4.45' || $GLOBALS['wp_version'] !== '5.0' || is_multisite() ||
	ini_get( 'memory_limit' ) !== '256M' || WP_MAX_MEMORY_LIMIT !== '256M' ) {
	throw new RuntimeException( 'Floor runtime or fixture ownership mismatch.' );
}
global $wpdb;
function floor_cleanup_check( $okay, $message ) {
	if ( ! $okay ) { throw new RuntimeException( $message ); }
}
floor_cleanup_check( $wpdb->get_var( 'SELECT DATABASE()' ) === $manifest['database'] &&
	! $wpdb->last_error && get_current_user_id() > 0 &&
	wp_get_current_user()->user_login === 'fgmflooradmin' && current_user_can( 'manage_options' ),
	'Live SQL or owned administrator differs from fixture.' );
$inactive = in_array( $step, array( 'uninstall', 'verify-uninstalled' ), true );
floor_cleanup_check( $inactive || ( function_exists( 'foogallery_migrate_migrator_instance' ) &&
	function_exists( 'foogallery_default_gallery_template' ) && defined( 'FOOGALLERY_VERSION' ) ),
	'Actual plugins are unavailable.' );
if ( $inactive ) { require_once $repo . '/includes/constants.php'; }
$suffix = substr( $manifest['fixture'], strrpos( $manifest['fixture'], '.' ) + 1 );
$owner = 'fgm-floor-cleanup-' . $suffix;
$owner_meta = '_fgm_floor_cleanup_owner';
$journal_key = 'fgm_floor_cleanup_owned_journey_v1';
$identity = hash( 'sha256', $manifest['fixture'] . '|' . $manifest['database'] . '|' . $manifest['container_id'] );
$journal = get_option( $journal_key, null );
$raw = function ( $name ) use ( $wpdb ) {
	$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM `{$wpdb->options}` WHERE option_name=%s", $name ) );
	floor_cleanup_check( ! $wpdb->last_error, 'Raw owned option read failed.' );
	return $value;
};
$table_exists = function ( $table ) use ( $wpdb ) {
	floor_cleanup_check( preg_match( '/^[A-Za-z0-9_]+$/D', $table ), 'Unowned table name refused.' );
	$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	floor_cleanup_check( ! $wpdb->last_error && ( null === $found || $table === $found ), 'Table identity read failed.' );
	return null !== $found;
};
$save = function ( $state ) use ( $journal_key, $raw ) {
	update_option( $journal_key, $state, false );
	$bytes = $raw( $journal_key );
	floor_cleanup_check( is_string( $bytes ) && maybe_unserialize( $bytes ) === $state &&
		get_option( $journal_key, null ) === $state, 'Owned journal durable readback failed.' );
};
$owned_posts = function () use ( $wpdb, $owner_meta, $owner ) {
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM `{$wpdb->postmeta}` WHERE meta_key=%s AND meta_value=%s ORDER BY post_id", $owner_meta, $owner ) );
	floor_cleanup_check( is_array( $ids ) && ! $wpdb->last_error, 'Protected post inventory failed.' );
	return array_map( 'intval', $ids );
};
$uploads = wp_upload_dir();
floor_cleanup_check( empty( $uploads['error'] ) && is_dir( $uploads['basedir'] ), 'Owned uploads unavailable.' );
$media_dir = $uploads['basedir'] . '/' . $owner;
$media = $media_dir . '/protected.png';
$verify_protected = function ( $state ) use ( $owner, $owner_meta, $owned_posts, $media ) {
	floor_cleanup_check( isset( $state['posts'], $state['post_hashes'], $state['media_hash'] ) &&
		array_values( $state['posts'] ) === $owned_posts(), 'Protected post identities/count differ.' );
	foreach ( $state['posts'] as $kind => $id ) {
		$post = get_post( $id );
		floor_cleanup_check( $post && $post->post_type === $kind &&
			get_post_meta( $id, $owner_meta, true ) === $owner &&
			hash( 'sha256', $post->post_title . '|' . $post->post_content ) === $state['post_hashes'][$kind],
			'Protected post changed: ' . $kind );
	}
	floor_cleanup_check( is_file( $media ) && ! is_link( $media ) &&
		hash_file( 'sha256', $media ) === $state['media_hash'] &&
		get_attached_file( $state['posts']['attachment'] ) === $media,
		'Protected attachment file changed.' );
};
$fixed_options = array( 'foogallery-migrate-data', 'foogallery-migrate-settings',
	'foogallery_migrate_storage_control', 'foogallery_migrate_storage_authority',
	'foogallery_migrate_storage_maintenance', 'foogallery_migrate_storage_cleanup',
	'foogallery-migrate-data-storage-backup', 'foogallery_migrate_storage',
	'foogallery-migrate-store-schema-version', 'foogallery-migrate-store-migration-version',
	'foogallery-migrate-migrated-legacy-backup', 'foogallery_migrate_storage_schema_version',
	'foogallery_migrate_storage_schema_lock' );
$fixed_tables = array( $wpdb->prefix . 'foogallery_migrate_objects',
	$wpdb->prefix . 'foogallery_migrate_state',
	$wpdb->base_prefix . 'foogallery_migrate_legacy_state',
	$wpdb->base_prefix . 'foogallery_migrate_legacy_records' );
$no_internal = function () use ( $fixed_options, $fixed_tables, $raw, $table_exists, $wpdb ) {
	foreach ( $fixed_options as $name ) {
		floor_cleanup_check( null === $raw( $name ), 'Internal option remains: ' . $name );
	}
	foreach ( $fixed_tables as $table ) {
		floor_cleanup_check( ! $table_exists( $table ), 'Internal table remains: ' . $table );
	}
	$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$wpdb->options}` WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_foogallery_migrate_content_result_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_foogallery_migrate_content_result_' ) . '%' ) );
	floor_cleanup_check( ! $wpdb->last_error && (string) $count === '0', 'Content-result transient remains.' );
};
if ( $step === 'init' ) {
	floor_cleanup_check( null === $journal && $owned_posts() === array() &&
		! file_exists( $media_dir ) && ! is_link( $media_dir ), 'Owned lifecycle fixture collision.' );
	$no_internal();
	floor_cleanup_check( wp_mkdir_p( $media_dir ), 'Owned media directory could not be created.' );
	$journal = array( 'identity' => $identity, 'owner' => $owner, 'phase' => 'seeding',
		'posts' => array(), 'post_hashes' => array() );
	floor_cleanup_check( add_option( $journal_key, $journal, '', false ), 'Owned lifecycle journal insertion failed.' );
	$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=' );
	floor_cleanup_check( ! file_exists( $media ) && ! is_link( $media ) &&
		false !== file_put_contents( $media, $png, LOCK_EX ), 'Owned media collision or write failure.' );
	$journal['media_hash'] = hash( 'sha256', $png );
	$definitions = array( 'page' => array( 'Protected source page', 'Original source remains unchanged.' ),
		'foogallery' => array( 'Protected migrated output', 'Migrated gallery remains unchanged.' ),
		'attachment' => array( 'Protected attachment', '' ) );
	foreach ( $definitions as $kind => $content ) {
		$id = wp_insert_post( array( 'post_type' => $kind,
			'post_status' => $kind === 'attachment' ? 'inherit' : 'publish',
			'post_mime_type' => $kind === 'attachment' ? 'image/png' : '',
			'post_title' => $content[0], 'post_content' => $content[1],
			'meta_input' => array( $owner_meta => $owner ) ), true );
		floor_cleanup_check( ! is_wp_error( $id ) && $id > 0, 'Protected post insertion failed.' );
		$journal['posts'][$kind] = (int) $id;
		$journal['post_hashes'][$kind] = hash( 'sha256', $content[0] . '|' . $content[1] );
		$save( $journal );
	}
	floor_cleanup_check( false !== update_attached_file( $journal['posts']['attachment'], $media ),
		'Protected attachment path failed.' );
	$journal['phase'] = 'ready'; $save( $journal );
}
floor_cleanup_check( is_array( $journal ) && isset( $journal['identity'], $journal['owner'],
	$journal['phase'] ) && $journal['identity'] === $identity && $journal['owner'] === $owner &&
	$journal['phase'] !== 'seeding', 'Owned lifecycle journal missing, partial, or mismatched.' );
$verify_protected( $journal );
$result = array( 'step' => $step, 'fixture' => $manifest['fixture'],
	'database' => $manifest['database'], 'container_id' => $manifest['container_id'],
	'php' => PHP_VERSION, 'wordpress' => $GLOBALS['wp_version'],
	'memory_limit' => ini_get( 'memory_limit' ), 'phase' => $journal['phase'] );
$request = function ( $operation, $target, $confirm ) {
	$r = StorageCli::execute( $operation, array( 'target' => $target, 'confirm' => $confirm ) );
	floor_cleanup_check( ! is_wp_error( $r ), 'Public ' . $operation . ' refused: ' .
		( is_wp_error( $r ) ? $r->get_error_code() : '' ) );
	return $r;
};
if ( $step === 'verify' ) { $result['storage'] = StorageAdmin::status(); }
if ( $step === 'seed-state' ) {
	floor_cleanup_check( $journal['phase'] === 'ready' && null === $raw( FOOGALLERY_MIGRATE_OPTION_DATA ),
		'Fresh option state required.' );
	$engine = foogallery_migrate_migrator_instance();
	foreach ( array( 'cleanup_probe' => array( 'source' => $journal['posts']['page'], 'version' => 1 ),
		'queue' => array( 'pending' => $journal['posts']['attachment'] ),
		'content_scan' => array( 'source' => $journal['posts']['page'] ),
		'image_tags' => array( 'protected' ) ) as $name => $value ) {
		floor_cleanup_check( $engine->set_migrator_setting( $name, $value ), 'Original option state seed failed.' );
	}
	floor_cleanup_check( null !== $raw( FOOGALLERY_MIGRATE_OPTION_DATA ), 'Original option state absent.' );
	$journal['phase'] = 'state-seeded'; $save( $journal );
}
if ( $step === 'provision' || $step === 'switch' || $step === 'recover' ) {
	floor_cleanup_check( $journal['phase'] === 'state-seeded', 'Table enablement phase mismatch.' );
	$result['receipt'] = $request( $step, 'table', $step === 'recover' ? 'recover-storage' :
		( $step === 'provision' ? 'provision-shared-schema' : 'switch-to-table' ) );
	$result['storage'] = StorageAdmin::status();
	if ( $result['storage']['backend'] === 'table' && $result['storage']['phase'] === 'stable' ) {
		$journal['phase'] = 'table'; $save( $journal );
	}
}
if ( $step === 'edit' ) {
	$status = StorageAdmin::status();
	floor_cleanup_check( $journal['phase'] === 'table' && $status['backend'] === 'table' &&
		$status['phase'] === 'stable' && $status['schema'] === 'verified', 'Stable table required for edit.' );
	$edit = array( 'current' => true, '09' => null, 9 => false, 'bytes' => "x\0" );
	floor_cleanup_check( foogallery_migrate_migrator_instance()->set_migrator_setting( 'cleanup_edit', $edit ) &&
		foogallery_migrate_migrator_instance()->get_migrator_setting( 'cleanup_edit' ) === $edit,
		'Typed current table edit failed.' );
	$journal['edit'] = $edit; $journal['phase'] = 'edited'; $save( $journal );
}
if ( $step === 'clear' || $step === 'repeat-clear' ) {
	floor_cleanup_check( $journal['phase'] === ( $step === 'clear' ? 'edited' : 'repeat-ready' ),
		'Owned clear phase mismatch.' );
	$result['receipt'] = $request( 'clear', 'state', 'clear-current-state' );
	floor_cleanup_check( ! empty( $result['receipt']['complete'] ), 'Public clear incomplete.' );
	$journal['phase'] = 'cleared'; $save( $journal );
}
if ( $step === 'seed-repeat-clear' ) {
	floor_cleanup_check( $journal['phase'] === 'cleared', 'Cleared table required for second state.' );
	$preferences = array( 'images_per_turn' => 7, 'debug_enabled' => true );
	update_option( FOOGALLERY_MIGRATE_OPTION_SETTINGS, $preferences, false );
	floor_cleanup_check( get_option( FOOGALLERY_MIGRATE_OPTION_SETTINGS, null ) === $preferences &&
		foogallery_migrate_migrator_instance()->set_migrator_setting( 'cleanup_after_clear', 'second current state' ),
		'Second state or preferences did not persist.' );
	$journal['preferences'] = $preferences; $journal['phase'] = 'repeat-ready'; $save( $journal );
}
if ( $step === 'verify-clear' ) {
	floor_cleanup_check( $journal['phase'] === 'cleared', 'Completed clear required for proof.' );
	$status = StorageAdmin::status();
	floor_cleanup_check( $status['backend'] === 'table' && $status['phase'] === 'stable' &&
		$status['schema'] === 'verified' && null !== $raw( StorageAuthority::CONTROL_OPTION ) &&
		null !== $raw( StorageAuthority::AUTHORITY_OPTION ) &&
		null !== $raw( LegacyStorageSchema::VERSION_OPTION ) &&
		null === $raw( FOOGALLERY_MIGRATE_OPTION_DATA ) &&
		null === $raw( StorageAuthority::STORAGE_BACKUP_OPTION ),
		'Clear changed authority/schema or left option state.' );
	foreach ( array_slice( $fixed_tables, 2 ) as $table ) {
		$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM `' . $table . '` WHERE blog_id=%d', 1 ) );
		floor_cleanup_check( $table_exists( $table ) && ! $wpdb->last_error && (string) $count === '0',
			'Clear left owned site table rows.' );
	}
	floor_cleanup_check( ! foogallery_migrate_migrator_instance()->get_migrator_setting( 'cleanup_probe', false ) &&
		! foogallery_migrate_migrator_instance()->get_migrator_setting( 'cleanup_edit', false ) &&
		! foogallery_migrate_migrator_instance()->get_migrator_setting( 'cleanup_after_clear', false ),
		'Fresh normal table read found prior state.' );
	if ( isset( $journal['preferences'] ) ) {
		floor_cleanup_check( get_option( FOOGALLERY_MIGRATE_OPTION_SETTINGS, null ) === $journal['preferences'],
			'Clear changed user preferences.' );
	}
	$result['storage'] = $status;
}
if ( $step === 'remove-all' || $step === 'repeat-remove-all' ) {
	floor_cleanup_check( in_array( $journal['phase'], array( 'cleared', 'removed' ), true ),
		'Cleared or removed state required.' );
	$result['receipt'] = $request( 'remove_all', 'all', 'remove-all-internal-data' );
	floor_cleanup_check( ! empty( $result['receipt']['complete'] ), 'Public remove-all incomplete.' );
	$no_internal(); $journal['phase'] = 'removed'; $save( $journal );
}
if ( $step === 'prepare-uninstall' ) {
	floor_cleanup_check( $journal['phase'] === 'removed', 'Clean removed state required for option uninstall fixture.' );
	$no_internal();
	floor_cleanup_check( foogallery_migrate_migrator_instance()->set_migrator_setting( 'cleanup_probe', 'uninstall fixture' ) &&
		update_option( StorageAuthority::STORAGE_BACKUP_OPTION, 'owned stale copy', false ),
		'Populated option/stale-copy uninstall state failed.' );
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	floor_cleanup_check( is_plugin_active( 'foogallery-migrate/migrate.php' ), 'Fixture plugin inactive too early.' );
	deactivate_plugins( 'foogallery-migrate/migrate.php', true );
	floor_cleanup_check( ! is_plugin_active( 'foogallery-migrate/migrate.php' ), 'Fixture plugin deactivation failed.' );
	$journal['phase'] = 'uninstall-ready'; $save( $journal );
}
if ( $step === 'uninstall' ) {
	floor_cleanup_check( $journal['phase'] === 'uninstall-ready' &&
		! function_exists( 'foogallery_migrate_migrator_instance' ) &&
		! class_exists( 'FooPlugins\\FooGalleryMigrate\\MigratorEngine', false ),
		'Standalone uninstall requires inactive plugin and absent engine.' );
	$before = get_included_files();
	define( 'WP_UNINSTALL_PLUGIN', 'foogallery-migrate/migrate.php' );
	require $repo . '/uninstall.php';
	$added = array_diff( get_included_files(), $before );
	foreach ( $added as $path ) {
		floor_cleanup_check( false === strpos( $path, '/vendor/' ) &&
			false === strpos( $path, 'class-migrator-engine.php' ), 'Uninstall loaded vendor or admin engine.' );
	}
	$no_internal(); $journal['phase'] = 'uninstalled'; $save( $journal );
	$result['direct_includes'] = count( $added );
}
if ( $step === 'verify-uninstalled' ) {
	floor_cleanup_check( $journal['phase'] === 'uninstalled' &&
		! function_exists( 'foogallery_migrate_migrator_instance' ) &&
		! class_exists( 'FooPlugins\\FooGalleryMigrate\\MigratorEngine', false ),
		'Standalone post-uninstall proof requires inactive plugin and engine.' );
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	floor_cleanup_check( ! is_plugin_active( 'foogallery-migrate/migrate.php' ), 'Fixture plugin unexpectedly active.' );
	$no_internal();
}
$verify_protected( $journal );
$result['phase'] = $journal['phase'];
$result['protected_posts'] = $journal['posts'];
$result['media_sha256'] = $journal['media_hash'];
echo json_encode( $result, JSON_UNESCAPED_SLASHES ) . "\n";
