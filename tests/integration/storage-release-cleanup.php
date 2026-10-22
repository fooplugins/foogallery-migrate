<?php
/** Owned disposable lifecycle journey. One explicit phase per fresh PHP process. */
if ( 'cli' !== PHP_SAPI || 3 !== $argc || ! in_array( $argv[2], array(
	'init', 'verify', 'seed-state', 'provision', 'switch', 'recover', 'edit', 'clear', 'seed-repeat-clear', 'repeat-clear', 'verify-clear',
	'remove-all', 'repeat-remove-all', 'prepare-uninstall', 'uninstall', 'verify-uninstalled', 'reverse', 'verify-reversed' ), true ) ) {
	throw new RuntimeException( 'Owned site and explicit cleanup phase required.' );
}
$site = realpath( $argv[1] ); $step = $argv[2];
if ( false === $site || ! preg_match( '~^/(?:private/)?tmp/foogallery-migrate-r0\.[A-Za-z0-9]+$~D', $site ) ) { throw new RuntimeException( 'Not an owned temporary site.' ); }
$manifest = is_file( $site . '/.fgm-r0-owner.json' ) ? json_decode( file_get_contents( $site . '/.fgm-r0-owner.json' ), true ) : null;
if ( ! is_array( $manifest ) || ! isset( $manifest['kind'], $manifest['site'], $manifest['database'], $manifest['host'], $manifest['repo'], $manifest['container'], $manifest['container_id'] ) ||
	'foogallery-migrate-r0-v1' !== $manifest['kind'] || realpath( $manifest['site'] ) !== $site ||
	! preg_match( '/^fgm_r0_[a-z0-9]+$/D', $manifest['database'] ) || ! preg_match( '/^127\.0\.0\.1:[0-9]{2,5}$/D', $manifest['host'] ) ||
	! preg_match( '/^fgm-r0-[A-Za-z0-9]+$/D', $manifest['container'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $manifest['container_id'] ) ||
	realpath( dirname( __DIR__, 2 ) ) !== realpath( $manifest['repo'] ) ||
	realpath( $site . '/wp-content/plugins/foogallery-migrate' ) !== realpath( $manifest['repo'] ) ||
	! is_file( $site . '/wp-load.php' ) ) { throw new RuntimeException( 'Owned site manifest or source checkout mismatch.' ); }
$container = array(); $code = 0;
exec( 'docker inspect --format ' . escapeshellarg( '{{.Id}} {{index .Config.Labels "foogallery-migrate-r0"}} {{.State.Running}}' ) . ' ' .
	escapeshellarg( $manifest['container'] ) . ' 2>/dev/null', $container, $code );
if ( 0 !== $code || count( $container ) !== 1 || trim( $container[0] ) !== $manifest['container_id'] . ' owned true' ) {
	throw new RuntimeException( 'Owned SQL container identity or liveness mismatch.' );
}
define( 'WP_MAX_MEMORY_LIMIT', '256M' ); ini_set( 'memory_limit', '256M' );
require $site . '/wp-load.php';
// When the fixture plugin is inactive, only its inert names/constants are
// loaded here for the runner's manifest checks. uninstall.php loads its own
// implementation; no ordinary plugin bootstrap or vendor autoloader runs.
if ( in_array( $step, array( 'uninstall', 'verify-uninstalled' ), true ) ) { require_once dirname( __DIR__, 2 ) . '/includes/constants.php'; }
use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\StorageCli;
use FooPlugins\FooGalleryMigrate\StorageAdmin;
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use FooPlugins\FooGalleryMigrate\MigratedStore;
global $wpdb, $wp_version;
function cleanup_check( $yes, $message ) { if ( ! $yes ) { throw new RuntimeException( $message ); } }
cleanup_check( ! is_multisite() && DB_NAME === $manifest['database'] && DB_HOST === $manifest['host'] &&
	$wpdb->get_var( 'SELECT DATABASE()' ) === $manifest['database'] && ! $wpdb->last_error &&
	'256M' === ini_get( 'memory_limit' ) && '256M' === WP_MAX_MEMORY_LIMIT, 'Owned SQL or runtime differs from manifest.' );
wp_set_current_user( 1 ); cleanup_check( current_user_can( 'manage_options' ), 'Owned administrator unavailable.' );
$suffix = substr( $site, strrpos( $site, '.' ) + 1 );
$owner = 'fgm-cleanup-' . $suffix; $owner_meta = '_fgm_cleanup_owner';
$journal_key = 'fgm_cleanup_owned_journey_v1';
$identity = hash( 'sha256', $site . '|' . $manifest['database'] . '|' . $manifest['container_id'] );
$journal = get_option( $journal_key, null );
$raw = function ( $name ) use ( $wpdb ) {
	$value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `' . $wpdb->options . '` WHERE option_name=%s', $name ) );
	cleanup_check( ! $wpdb->last_error, 'Owned option read failed.' ); return $value;
};
$exists_table = function ( $table ) use ( $wpdb ) {
	cleanup_check( preg_match( '/^[A-Za-z0-9_]+$/D', $table ), 'Unowned table name refused.' );
	$value = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	cleanup_check( ! $wpdb->last_error && ( null === $value || $table === $value ), 'Table identity read failed.' );
	return null !== $value;
};
$save = function ( $state ) use ( $journal_key ) {
	update_option( $journal_key, $state, false );
	cleanup_check( get_option( $journal_key, null ) === $state, 'Owned journal exact readback failed.' );
};
$owned_posts = function () use ( $wpdb, $owner, $owner_meta ) {
	$rows = $wpdb->get_col( $wpdb->prepare( 'SELECT post_id FROM `' . $wpdb->postmeta . '` WHERE meta_key=%s AND meta_value=%s ORDER BY post_id', $owner_meta, $owner ) );
	cleanup_check( is_array( $rows ) && ! $wpdb->last_error, 'Protected post inventory failed.' );
	return array_map( 'intval', $rows );
};
$uploads = wp_upload_dir(); cleanup_check( empty( $uploads['error'] ) && is_dir( $uploads['basedir'] ), 'Owned uploads root unavailable.' );
$media_dir = $uploads['basedir'] . '/' . $owner; $media = $media_dir . '/protected.png';
$verify_protected = function ( $state ) use ( $owner, $owner_meta, $media, $owned_posts ) {
	cleanup_check( isset( $state['posts'], $state['post_hashes'], $state['media_hash'] ) &&
		array_values( $state['posts'] ) === $owned_posts(), 'Owned protected post identity/count changed.' );
	foreach ( $state['posts'] as $kind => $id ) {
		$post = get_post( $id ); cleanup_check( $post && $owner === get_post_meta( $id, $owner_meta, true ) &&
			$kind === $post->post_type && hash( 'sha256', $post->post_title . '|' . $post->post_content ) === $state['post_hashes'][$kind],
			'Protected source/output/media post changed: ' . $kind );
	}
	cleanup_check( is_file( $media ) && ! is_link( $media ) && hash_file( 'sha256', $media ) === $state['media_hash'] &&
		get_attached_file( $state['posts']['attachment'] ) === $media, 'Protected attachment file changed.' );
};
$fixed_options = array( 'foogallery-migrate-data', 'foogallery-migrate-settings',
	'foogallery_migrate_storage_control', 'foogallery_migrate_storage_authority', 'foogallery_migrate_storage_maintenance',
	'foogallery_migrate_storage_cleanup', 'foogallery-migrate-data-storage-backup', 'foogallery_migrate_storage',
	'foogallery-migrate-store-schema-version', 'foogallery-migrate-store-migration-version',
	'foogallery-migrate-migrated-legacy-backup', 'foogallery_migrate_storage_schema_version', 'foogallery_migrate_storage_schema_lock' );
$fixed_tables = array( $wpdb->prefix . 'foogallery_migrate_objects', $wpdb->prefix . 'foogallery_migrate_state',
	$wpdb->base_prefix . 'foogallery_migrate_legacy_state', $wpdb->base_prefix . 'foogallery_migrate_legacy_records' );
$no_internal = function () use ( $fixed_options, $fixed_tables, $raw, $exists_table, $wpdb ) {
	foreach ( $fixed_options as $name ) { cleanup_check( null === $raw( $name ), 'Owned option remains: ' . $name ); }
	foreach ( $fixed_tables as $table ) { cleanup_check( ! $exists_table( $table ), 'Owned table remains: ' . $table ); }
	$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM `' . $wpdb->options . '` WHERE option_name LIKE %s OR option_name LIKE %s',
		$wpdb->esc_like( '_transient_foogallery_migrate_content_result_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_foogallery_migrate_content_result_' ) . '%' ) );
	cleanup_check( ! $wpdb->last_error && '0' === (string) $count, 'Owned content-result transient remains.' );
};
if ( 'init' === $step ) {
	cleanup_check( null === $journal && array() === $owned_posts(), 'Existing owned lifecycle journal/posts; refuse reseed.' );
	$no_internal();
	cleanup_check( ! file_exists( $media_dir ) && ! is_link( $media_dir ) && wp_mkdir_p( $media_dir ), 'Owned media directory collision.' );
	$state = array( 'identity' => $identity, 'owner' => $owner, 'phase' => 'seeding', 'posts' => array(), 'post_hashes' => array() );
	cleanup_check( add_option( $journal_key, $state, '', false ), 'Owned lifecycle journal insertion failed.' );
	$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=' );
	cleanup_check( ! file_exists( $media ) && ! is_link( $media ) && false !== file_put_contents( $media, $png, LOCK_EX ), 'Owned media file collision/write failed.' );
	$state['media_hash'] = hash( 'sha256', $png );
	$definitions = array( 'page' => array( 'Protected source page', 'Original source remains unchanged.' ),
		'foogallery' => array( 'Protected migrated output', 'Migrated gallery remains unchanged.' ),
		'attachment' => array( 'Protected attachment', '' ) );
	foreach ( $definitions as $kind => $content ) {
		$id = wp_insert_post( array( 'post_type' => $kind, 'post_status' => 'attachment' === $kind ? 'inherit' : 'publish',
			'post_mime_type' => 'attachment' === $kind ? 'image/png' : '', 'post_title' => $content[0],
			'post_content' => $content[1], 'meta_input' => array( $owner_meta => $owner ) ), true );
		cleanup_check( ! is_wp_error( $id ) && $id > 0, 'Owned protected post insertion failed.' );
		$state['posts'][$kind] = (int) $id;
		$state['post_hashes'][$kind] = hash( 'sha256', $content[0] . '|' . $content[1] );
		$save( $state );
	}
	cleanup_check( false !== update_attached_file( $state['posts']['attachment'], $media ), 'Owned attachment path failed.' );
	$state['phase'] = 'ready'; $save( $state ); $journal = $state;
}
cleanup_check( is_array( $journal ) && isset( $journal['identity'], $journal['owner'], $journal['phase'] ) &&
	$identity === $journal['identity'] && $owner === $journal['owner'] && 'seeding' !== $journal['phase'], 'Owned lifecycle journal missing, partial or mismatched.' );
$verify_protected( $journal );
$result = array( 'step' => $step, 'site' => $site, 'database' => $manifest['database'],
	'wordpress' => $wp_version, 'php' => PHP_VERSION, 'phase' => $journal['phase'] );
$request = function ( $operation, $target, $confirm ) {
	$r = StorageCli::execute( $operation, array( 'target' => $target, 'confirm' => $confirm ) );
	cleanup_check( ! is_wp_error( $r ), 'Public ' . $operation . ' refused: ' . ( is_wp_error( $r ) ? $r->get_error_code() : '' ) );
	return $r;
};
if ( 'verify' === $step ) { $result['storage'] = StorageAdmin::status(); }
if ( 'seed-state' === $step ) {
	cleanup_check( 'ready' === $journal['phase'] && null === $raw( FOOGALLERY_MIGRATE_OPTION_DATA ), 'Fresh option state required for operational seed.' );
	$engine = foogallery_migrate_migrator_instance();
	foreach ( array( 'cleanup_probe' => array( 'version' => 1, 'source' => $journal['posts']['page'] ),
		'queue' => array( 'pending' => $journal['posts']['attachment'] ),
		'content_scan' => array( 'source' => $journal['posts']['page'] ),
		'image_tags' => array( 'protected' ) ) as $name => $value ) {
		cleanup_check( $engine->set_migrator_setting( $name, $value ), 'Operational source option seed failed.' );
	}
	cleanup_check( null !== $raw( FOOGALLERY_MIGRATE_OPTION_DATA ), 'Operational source option did not persist.' );
	$journal['phase'] = 'state-seeded'; $save( $journal );
}
if ( 'provision' === $step ) { cleanup_check( 'state-seeded' === $journal['phase'], 'Unexpected provision phase.' ); $result['storage'] = $request( 'provision', 'table', 'provision-shared-schema' ); }
if ( 'switch' === $step || 'recover' === $step ) {
	cleanup_check( 'state-seeded' === $journal['phase'], 'Unexpected table-switch phase.' );
	$result['storage'] = $request( $step, 'table', 'recover' === $step ? 'recover-storage' : 'switch-to-table' );
	$status = StorageAdmin::status(); $result['status'] = $status;
	if ( 'table' === $status['backend'] && 'stable' === $status['phase'] ) { $journal['phase'] = 'table'; $save( $journal ); }
}
if ( 'edit' === $step ) {
	cleanup_check( 'table' === $journal['phase'] && 'table' === StorageAdmin::status()['backend'], 'Stable selected table required for edit.' );
	cleanup_check( foogallery_migrate_migrator_instance()->set_migrator_setting( 'cleanup_edit', 'current table state after switch' ), 'Current table state edit failed.' );
	$journal['phase'] = 'edited'; $save( $journal );
}
if ( 'reverse' === $step ) {
	cleanup_check( in_array( $journal['phase'], array( 'edited', 'reversing' ), true ), 'Owned edited table or recorded reverse required.' );
	$status = StorageAdmin::status();
	cleanup_check( 'verified' === $status['schema'] && '' === $status['problem'] &&
		( 'table' === $status['backend'] || 'option' === $status['backend'] ) &&
		( 'stable' === $status['phase'] || 'option' === $status['target'] ), 'Verified owned reverse transition required.' );
	if ( 'stable' !== $status['phase'] ) { $result['storage'] = $request( 'recover', 'option', 'recover-storage' ); }
	elseif ( 'table' === $status['backend'] ) { $result['storage'] = $request( 'switch', 'option', 'switch-to-option' ); }
	$status = StorageAdmin::status();
	if ( 'stable' === $status['phase'] && 'option' === $status['backend'] ) {
		$current = $raw( FOOGALLERY_MIGRATE_OPTION_DATA ); $backup = $raw( StorageAuthority::STORAGE_BACKUP_OPTION );
		$decoded = is_string( $current ) ? maybe_unserialize( $current ) : null;
		cleanup_check( is_string( $backup ) && is_array( $decoded ) && $current !== $backup &&
			isset( $decoded['cleanup_edit'] ) && 'current table state after switch' === $decoded['cleanup_edit'], 'CURRENT option lost exact table edit.' );
		$journal['phase'] = 'reversed'; $journal['current_hash'] = hash( 'sha256', $current ); $save( $journal );
	} else { $journal['phase'] = 'reversing'; $save( $journal ); }
	$result['status'] = $status;
}
if ( 'verify-reversed' === $step ) {
	cleanup_check( 'reversed' === $journal['phase'] && isset( $journal['current_hash'] ), 'Completed owned reverse required.' );
	$status = StorageAdmin::status(); $current = $raw( FOOGALLERY_MIGRATE_OPTION_DATA );
	$decoded = is_string( $current ) ? maybe_unserialize( $current ) : null;
	cleanup_check( 'stable' === $status['phase'] && 'option' === $status['backend'] && ! empty( $status['retained'] ) &&
		is_array( $decoded ) && isset( $decoded['cleanup_edit'] ) && 'current table state after switch' === $decoded['cleanup_edit'] &&
		hash( 'sha256', $current ) === $journal['current_hash'], 'Stable CURRENT option reverse proof failed.' );
	$result['storage'] = $status; $result['current_option_bytes'] = strlen( $current );
}
if ( 'clear' === $step ) {
	cleanup_check( 'edited' === $journal['phase'], 'Edited table state required for clear.' );
	$result['receipt'] = $request( 'clear', 'state', 'clear-current-state' );
	cleanup_check( ! empty( $result['receipt']['complete'] ), 'Public clear incomplete; retain marker and journal.' );
	$journal['phase'] = 'cleared'; $save( $journal );
}
if ( 'seed-repeat-clear' === $step ) {
	cleanup_check( 'cleared' === $journal['phase'], 'Stable cleared table required for preference proof.' );
	$preferences = array( 'images_per_turn' => 7, 'debug_enabled' => true );
	update_option( FOOGALLERY_MIGRATE_OPTION_SETTINGS, $preferences, false );
	cleanup_check( get_option( FOOGALLERY_MIGRATE_OPTION_SETTINGS, null ) === $preferences, 'Owned preference seed failed.' );
	$current = foogallery_migrate_migrator_instance()->get_migrator_setting( 'cleanup_after_clear', false );
	if ( false === $current ) { cleanup_check( foogallery_migrate_migrator_instance()->set_migrator_setting( 'cleanup_after_clear', 'second current state' ), 'Second table-state seed failed.' ); }
	else { cleanup_check( 'second current state' === $current, 'Existing second table state differs.' ); }
	$journal['phase'] = 'repeat-ready'; $journal['preferences'] = $preferences; $save( $journal );
}
if ( 'repeat-clear' === $step ) {
	cleanup_check( 'repeat-ready' === $journal['phase'] && isset( $journal['preferences'] ), 'Second table state must be seeded in a prior request.' );
	$result['receipt'] = $request( 'clear', 'state', 'clear-current-state' );
	cleanup_check( ! empty( $result['receipt']['complete'] ) && get_option( FOOGALLERY_MIGRATE_OPTION_SETTINGS, null ) === $journal['preferences'],
		'Repeated clear did not complete while preserving preferences: ' . json_encode( $result['receipt'] ) );
	$journal['phase'] = 'cleared'; $save( $journal );
}
if ( 'verify-clear' === $step ) {
	cleanup_check( 'cleared' === $journal['phase'], 'Completed table clear required.' );
	$status = StorageAdmin::status(); cleanup_check( 'table' === $status['backend'] && 'stable' === $status['phase'] && 'verified' === $status['schema'], 'Table authority/schema not preserved by clear.' );
	cleanup_check( null !== $raw( StorageAuthority::CONTROL_OPTION ) && null !== $raw( StorageAuthority::AUTHORITY_OPTION ) &&
		null !== $raw( LegacyStorageSchema::VERSION_OPTION ) && null === $raw( FOOGALLERY_MIGRATE_OPTION_DATA ) &&
		null === $raw( StorageAuthority::STORAGE_BACKUP_OPTION ) && null === $raw( MigratedStore::LEGACY_BACKUP_OPTION ) &&
		null === $raw( StorageAuthority::CLEANUP_OPTION ), 'Clear mode, stale-copy or marker mismatch.' );
	foreach ( array_slice( $fixed_tables, 2 ) as $table ) {
		cleanup_check( $exists_table( $table ) && '0' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM `' . $table . '` WHERE blog_id=%d', 1 ) ) && ! $wpdb->last_error, 'Cleared shared table still has site state.' );
	}
	cleanup_check( ! foogallery_migrate_migrator_instance()->get_migrator_setting( 'cleanup_probe', false ) &&
		! foogallery_migrate_migrator_instance()->get_migrator_setting( 'cleanup_edit', false ) &&
		! foogallery_migrate_migrator_instance()->get_migrator_setting( 'cleanup_after_clear', false ), 'Fresh ordinary table read found old state.' );
	if ( isset( $journal['preferences'] ) ) { cleanup_check( get_option( FOOGALLERY_MIGRATE_OPTION_SETTINGS, null ) === $journal['preferences'], 'User preferences changed across clear.' ); }
	$result['storage'] = $status;
}
if ( 'remove-all' === $step || 'repeat-remove-all' === $step ) {
	cleanup_check( in_array( $journal['phase'], array( 'cleared', 'removed' ), true ), 'Cleared state required for remove-all.' );
	$result['receipt'] = $request( 'remove_all', 'all', 'remove-all-internal-data' );
	cleanup_check( ! empty( $result['receipt']['complete'] ), 'Public remove-all incomplete; retain marker and journal.' );
	$no_internal(); $journal['phase'] = 'removed'; $save( $journal );
}
if ( 'prepare-uninstall' === $step ) {
	cleanup_check( in_array( $journal['phase'], array( 'removed', 'edited' ), true ), 'Owned removed or edited-table state required before standalone uninstall.' );
	if ( 'removed' === $journal['phase'] ) {
		$no_internal();
		cleanup_check( foogallery_migrate_migrator_instance()->set_migrator_setting( 'cleanup_probe', 'uninstall fixture' ), 'Uninstall option-state seed failed.' );
		cleanup_check( update_option( StorageAuthority::STORAGE_BACKUP_OPTION, 'owned stale copy', false ), 'Uninstall stale-copy seed failed.' );
	} else {
		$status = StorageAdmin::status();
		cleanup_check( 'table' === $status['backend'] && 'stable' === $status['phase'] && 'verified' === $status['schema'], 'Edited table authority/schema required for direct uninstall.' );
		foreach ( array_slice( $fixed_tables, 2 ) as $table ) {
			cleanup_check( $exists_table( $table ) && (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM `' . $table . '` WHERE blog_id=%d', 1 ) ) > 0 && ! $wpdb->last_error,
				'Owned shared table must contain real edited data before direct uninstall.' );
		}
		cleanup_check( 'current table state after switch' === foogallery_migrate_migrator_instance()->get_migrator_setting( 'cleanup_edit', false ), 'Edited current table state missing before uninstall.' );
	}
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	cleanup_check( is_plugin_active( 'foogallery-migrate/migrate.php' ), 'Fixture plugin is not active before deactivation.' );
	deactivate_plugins( 'foogallery-migrate/migrate.php', true );
	cleanup_check( ! is_plugin_active( 'foogallery-migrate/migrate.php' ), 'Fixture plugin deactivation failed.' );
	$journal['phase'] = 'uninstall-ready'; $save( $journal );
}
if ( 'uninstall' === $step ) {
	cleanup_check( 'uninstall-ready' === $journal['phase'] && ! function_exists( 'foogallery_migrate_migrator_instance' ) &&
		! class_exists( 'FooPlugins\\FooGalleryMigrate\\MigratorEngine', false ), 'Standalone uninstall requires no active plugin or engine bootstrap.' );
	$before = get_included_files(); define( 'WP_UNINSTALL_PLUGIN', 'foogallery-migrate/migrate.php' );
	require dirname( __DIR__, 2 ) . '/uninstall.php';
	$after = array_diff( get_included_files(), $before );
	foreach ( $after as $path ) { cleanup_check( false === strpos( $path, '/vendor/' ) && false === strpos( $path, 'class-migrator-engine.php' ), 'Uninstall loaded vendor/admin engine.' ); }
	$no_internal(); $journal['phase'] = 'uninstalled'; $save( $journal );
	$result['direct_includes'] = count( $after );
}
if ( 'verify-uninstalled' === $step ) {
	cleanup_check( 'uninstalled' === $journal['phase'] && ! function_exists( 'foogallery_migrate_migrator_instance' ) &&
		! class_exists( 'FooPlugins\\FooGalleryMigrate\\MigratorEngine', false ), 'Standalone post-uninstall verification requires inactive plugin and engine.' );
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	cleanup_check( ! is_plugin_active( 'foogallery-migrate/migrate.php' ), 'Fixture plugin unexpectedly active after uninstall.' );
	$no_internal();
}
$verify_protected( $journal );
$result['phase'] = $journal['phase']; $result['protected_posts'] = $journal['posts'];
echo json_encode( $result, JSON_UNESCAPED_SLASHES ) . "\n";
