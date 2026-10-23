<?php
/** Owned disposable lifecycle journey. One explicit phase per fresh PHP process. */
if ( 'cli' !== PHP_SAPI || 3 !== $argc || ! in_array( $argv[2], array(
	'init', 'verify', 'seed-state', 'provision', 'switch', 'recover', 'edit', 'adopt-reversed', 'adopt-table', 'clear', 'seed-repeat-clear', 'repeat-clear', 'verify-clear',
	'remove-all', 'repeat-remove-all', 'fault-remove-all', 'verify-partial-remove-all', 'wrong-action-refuses', 'resume-remove-all',
	'prepare-uninstall', 'uninstall', 'verify-uninstalled', 'reverse', 'verify-reversed' ), true ) ) {
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
	! is_file( $site . '/wp-load.php' ) ) { throw new RuntimeException( 'Owned site manifest or source checkout mismatch.' ); }
$plugin_dir = $site . '/wp-content/plugins/foogallery-migrate';
if ( isset( $manifest['package'] ) ) {
	if ( is_link( $plugin_dir ) || realpath( $plugin_dir ) !== $plugin_dir ) { throw new RuntimeException( 'Package plugin directory is not regular.' ); }
	$identity_output = array(); $identity_code = 0;
	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/storage-release-package-identity.php' ) .
		' verify-tree ' . escapeshellarg( $plugin_dir ) . ' ' . escapeshellarg( $site . '/.fgm-r0-owner.json' ) . ' 2>/dev/null',
		$identity_output, $identity_code );
	if ( 0 !== $identity_code || count( $identity_output ) !== 1 ||
		trim( $identity_output[0] ) !== $manifest['package']['tree_sha256'] ) {
		throw new RuntimeException( 'Extracted package differs from pinned ZIP identity.' );
	}
} elseif ( realpath( $plugin_dir ) !== realpath( $manifest['repo'] ) ) { throw new RuntimeException( 'Plugin source symlink differs from manifest.' ); }
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
if ( in_array( $step, array( 'uninstall', 'verify-uninstalled' ), true ) ) { require_once $plugin_dir . '/includes/constants.php'; }
use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\StorageCli;
use FooPlugins\FooGalleryMigrate\StorageAdmin;
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use FooPlugins\FooGalleryMigrate\MigratedStore;
use FooPlugins\FooGalleryMigrate\StorageMaintenanceGate;
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
$save = function ( $state ) use ( $journal_key, $raw ) {
	update_option( $journal_key, $state, false );
	$bytes = $raw( $journal_key );
	cleanup_check( is_string( $bytes ) && maybe_unserialize( $bytes ) === $state &&
		get_option( $journal_key, null ) === $state, 'Owned journal durable readback failed.' );
};
$owned_posts = function () use ( $wpdb, $owner, $owner_meta ) {
	$rows = $wpdb->get_col( $wpdb->prepare( 'SELECT post_id FROM `' . $wpdb->postmeta . '` WHERE meta_key=%s AND meta_value=%s ORDER BY post_id', $owner_meta, $owner ) );
	cleanup_check( is_array( $rows ) && ! $wpdb->last_error, 'Protected post inventory failed.' );
	return array_map( 'intval', $rows );
};
$uploads = wp_upload_dir(); cleanup_check( empty( $uploads['error'] ) && is_dir( $uploads['basedir'] ), 'Owned uploads root unavailable.' );
$media_dir = $uploads['basedir'] . '/' . $owner; $media = $media_dir . '/protected.png';
$verify_protected = function ( $state ) use ( $owner, $owner_meta, $media, $owned_posts ) {
	if ( isset( $state['real'] ) ) { return; }
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
$verify_real = function ( $state ) use ( $uploads, $raw, $manifest, $wpdb ) {
	if ( ! isset( $state['real'] ) ) { return; }
	$real = $state['real'];
	$backend = isset( $state['adopted_backend'] ) ? $state['adopted_backend'] : 'option';
	$expected_total = 'table' === $backend ? 10000 : 1000;
	cleanup_check( is_array( $real ) && isset( $real['owner'], $real['identity'], $real['total'], $real['ids'],
		$real['ids_sha256'], $real['source'], $real['content'], $real['content_sha256'],
		$real['destination'], $real['uid'], $real['edit'], $real['completed'], $real['phase'] ) &&
		in_array( $backend, array( 'option', 'table' ), true ) &&
		$real['phase'] === ( 'table' === $backend ? 'edited' : 'reversed' ) &&
		$real['total'] === $expected_total && $real['completed'] === $expected_total &&
		is_array( $real['ids'] ) && count( $real['ids'] ) === $expected_total &&
		count( array_unique( $real['ids'] ) ) === $expected_total &&
		hash( 'sha256', implode( ',', $real['ids'] ) ) === $real['ids_sha256'],
		'Adopted actual migration journal dimensions differ.' );
	if ( isset( $state['real_journal_sha256'] ) ) {
		$actual = $raw( 'fgm_real_owned_journey_v1' );
		cleanup_check( is_string( $actual ) && hash( 'sha256', $actual ) === $state['real_journal_sha256'] &&
			maybe_unserialize( $actual ) === $real, 'Adopted real journal changed.' );
	}
	if ( isset( $state['adopted_package'] ) ) {
		cleanup_check( isset( $manifest['package'] ) && $state['adopted_package'] === $manifest['package'],
			'Adopted package identity changed.' );
	}
	foreach ( array( 'attachment' => $expected_total, 'aigpl_gallery' => 1, 'page' => 1 ) as $kind => $expected ) {
		$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM `' . $wpdb->posts . '` p INNER JOIN `' . $wpdb->postmeta . '` m ON m.post_id=p.ID WHERE m.meta_key=%s AND m.meta_value=%s AND p.post_type=%s',
			'_fgm_real_fixture_owner', $real['owner'], $kind ) );
		cleanup_check( ! $wpdb->last_error && (int) $count === $expected, 'Adopted real post count changed: ' . $kind );
	}
	$source = get_post( $real['source'] ); $content = get_post( $real['content'] );
	$destination = get_post( $real['destination'] );
	cleanup_check( $source && $source->post_type === 'aigpl_gallery' && $source->post_status === 'publish' &&
		$content && $content->post_type === 'page' && $content->post_status === 'publish' &&
		$destination && $destination->post_type === FOOGALLERY_CPT_GALLERY &&
		get_post_meta( $source->ID, '_fgm_real_fixture_owner', true ) === $real['owner'] &&
		get_post_meta( $content->ID, '_fgm_real_fixture_owner', true ) === $real['owner'] &&
		get_post_meta( $source->ID, '_aigpl_gallery_imgs', true ) === $real['ids'] &&
		hash( 'sha256', $content->post_content ) === $real['content_sha256'] &&
		get_post_meta( $destination->ID, FOOGALLERY_META_ATTACHMENTS, true ) === $real['ids'],
		'Adopted actual source/content/destination changed.' );
	$post_hash = hash( 'sha256', serialize( array( $source->post_title, $source->post_content, $source->post_status,
		$destination->post_title, $destination->post_content, $destination->post_status ) ) );
	if ( isset( $state['adopted_posts_sha256'] ) ) {
		cleanup_check( $post_hash === $state['adopted_posts_sha256'], 'Adopted source/destination post changed.' );
	}
	$matches = get_posts( array( 'post_type' => FOOGALLERY_CPT_GALLERY, 'post_status' => 'any',
		'posts_per_page' => 2, 'fields' => 'ids', 'meta_key' => '_foogallery_migrate_source_key',
		'meta_value' => $real['uid'] ) );
	cleanup_check( count( $matches ) === 1 && (int) $matches[0] === (int) $destination->ID,
		'Adopted migrated destination identity/uniqueness changed.' );
	$png_hash = hash( 'sha256', base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=' ) );
	$media_dir = $uploads['basedir'] . '/' . $real['owner'];
	cleanup_check( is_dir( $media_dir ) && ! is_link( $media_dir ), 'Adopted media directory changed.' );
	foreach ( $real['ids'] as $index => $id ) {
		$relative = $real['owner'] . '/' . $index . '.png';
		$file = $media_dir . '/' . $index . '.png';
		$post = get_post( $id ); $meta = wp_get_attachment_metadata( $id );
		cleanup_check( $post && $post->post_type === 'attachment' && $post->post_status === 'inherit' &&
			$post->post_mime_type === 'image/png' &&
			get_post_meta( $id, '_fgm_real_fixture_owner', true ) === $real['owner'] &&
			get_post_meta( $id, '_wp_attached_file', true ) === $relative &&
			is_array( $meta ) && isset( $meta['file'], $meta['width'], $meta['height'] ) &&
			$meta['file'] === $relative && (int) $meta['width'] === 1 && (int) $meta['height'] === 1 &&
			is_file( $file ) && ! is_link( $file ) && hash_file( 'sha256', $file ) === $png_hash,
			'Adopted attachment or file changed at index ' . $index . '.' );
	}
	$files = glob( $media_dir . '/*.png' );
	$output = do_shortcode( '[foogallery id="' . $destination->ID . '"]' );
	cleanup_check( is_array( $files ) && count( $files ) === $expected_total &&
		is_string( $output ) && false !== strpos( $output, 'foogallery' ),
		'Adopted media count or FooGallery output changed.' );
	return $post_hash;
};
$fixed_options = array( 'foogallery-migrate-data', 'foogallery-migrate-settings',
	'foogallery_migrate_storage_control', 'foogallery_migrate_storage_authority', 'foogallery_migrate_storage_maintenance',
	'foogallery_migrate_storage_cleanup', 'foogallery-migrate-data-storage-backup', 'foogallery_migrate_storage',
	'foogallery-migrate-store-schema-version', 'foogallery-migrate-store-migration-version',
	'foogallery-migrate-migrated-legacy-backup', 'foogallery_migrate_storage_schema_version', 'foogallery_migrate_storage_schema_lock' );
$fixed_tables = array( $wpdb->prefix . 'foogallery_migrate_objects', $wpdb->prefix . 'foogallery_migrate_state',
	$wpdb->base_prefix . 'foogallery_migrate_legacy_state', $wpdb->base_prefix . 'foogallery_migrate_legacy_records' );
$fault_source = function () use ( $plugin_dir ) {
	$files = array( 'migrate.php', 'includes/class-storage-cleanup.php', 'includes/class-storage-maintenance-gate.php',
		'includes/class-legacy-transaction-connection.php', 'includes/class-storage-cli.php',
		'includes/class-storage-operations.php', 'includes/class-storage-authority.php' );
	$hashes = array();
	foreach ( $files as $file ) {
		$hash = hash_file( 'sha256', $plugin_dir . '/' . $file );
		cleanup_check( is_string( $hash ), 'Scoped cleanup source file unavailable: ' . $file );
		$hashes[$file] = $hash;
	}
	return $hashes;
};
$fault_snapshot = function () use ( $fixed_options, $fixed_tables, $raw, $exists_table, $wpdb ) {
	$state = array( 'options' => array(), 'tables' => array() );
	foreach ( $fixed_options as $name ) {
		$value = $raw( $name ); $state['options'][$name] = null === $value ? null : hash( 'sha256', $value );
	}
	foreach ( $fixed_tables as $table ) {
		if ( ! $exists_table( $table ) ) { $state['tables'][$table] = null; continue; }
		$count = $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $table . '`' );
		cleanup_check( ! $wpdb->last_error && ctype_digit( (string) $count ), 'Owned table count unavailable.' );
		$state['tables'][$table] = (int) $count;
	}
	return $state;
};
$no_internal = function () use ( $fixed_options, $fixed_tables, $raw, $exists_table, $wpdb ) {
	foreach ( $fixed_options as $name ) { cleanup_check( null === $raw( $name ), 'Owned option remains: ' . $name ); }
	foreach ( $fixed_tables as $table ) { cleanup_check( ! $exists_table( $table ), 'Owned table remains: ' . $table ); }
	$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM `' . $wpdb->options . '` WHERE option_name LIKE %s OR option_name LIKE %s',
		$wpdb->esc_like( '_transient_foogallery_migrate_content_result_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_foogallery_migrate_content_result_' ) . '%' ) );
	cleanup_check( ! $wpdb->last_error && '0' === (string) $count, 'Owned content-result transient remains.' );
};
if ( 'adopt-reversed' === $step ) {
	cleanup_check( null === $journal && array() === $owned_posts() &&
		! file_exists( $media_dir ) && ! is_link( $media_dir ),
		'Adoption refuses an existing lifecycle journal or unrelated protected posts.' );
	$real = get_option( 'fgm_real_owned_journey_v1', null );
	$real_raw = $raw( 'fgm_real_owned_journey_v1' );
	$expected_owner = 'fgm-real-' . $suffix;
	cleanup_check( is_array( $real ) && is_string( $real_raw ) && maybe_unserialize( $real_raw ) === $real &&
		isset( $real['identity'], $real['owner'], $real['phase'], $real['edit'] ) &&
		$real['identity'] === $identity && $real['owner'] === $expected_owner && $real['phase'] === 'reversed',
		'Exact same-fixture reversed actual migration required.' );
	$status = StorageAdmin::status();
	$current = $raw( FOOGALLERY_MIGRATE_OPTION_DATA ); $backup = $raw( StorageAuthority::STORAGE_BACKUP_OPTION );
	$decoded = is_string( $current ) ? maybe_unserialize( $current ) : null;
	cleanup_check( $status['backend'] === 'option' && $status['phase'] === 'stable' && ! empty( $status['retained'] ) &&
		is_string( $current ) && is_string( $backup ) && $current !== $backup &&
		is_array( $decoded ) && array_key_exists( $expected_owner, $decoded ) &&
		$decoded[$expected_owner] === $real['edit'], 'Exact typed CURRENT option edit required before cleanup adoption.' );
	$journal = array( 'identity' => $identity, 'owner' => $owner, 'phase' => 'edited',
		'real' => $real, 'adopted_backend' => 'option', 'real_journal_sha256' => hash( 'sha256', $real_raw ),
		'adopted_current_sha256' => hash( 'sha256', $current ) );
	if ( isset( $manifest['package'] ) ) { $journal['adopted_package'] = $manifest['package']; }
	$journal['adopted_posts_sha256'] = $verify_real( $journal );
	cleanup_check( add_option( $journal_key, $journal, '', false ), 'Owned adoption journal insertion failed.' );
	$save( $journal );
}
if ( 'adopt-table' === $step ) {
	cleanup_check( null === $journal && array() === $owned_posts() &&
		! file_exists( $media_dir ) && ! is_link( $media_dir ),
		'Adoption refuses an existing lifecycle journal or unrelated protected posts.' );
	cleanup_check( isset( $manifest['package']['zip_sha256'], $manifest['package']['tree_sha256'] ) &&
		$manifest['package']['zip_sha256'] === '67489aa5f1849c9dc6e0c4e10911463338fbd2403797c2bcf565926409ed6379' &&
		$manifest['package']['tree_sha256'] === 'd18c9a460fa4a5ebeaaf87087d59b0250b7501dd4f0cc24c791fc670de05644e',
		'Exact reviewed 10K package required for table adoption.' );
	$real = get_option( 'fgm_real_owned_journey_v1', null );
	$real_raw = $raw( 'fgm_real_owned_journey_v1' );
	$expected_owner = 'fgm-real-' . $suffix;
	cleanup_check( is_array( $real ) && is_string( $real_raw ) && maybe_unserialize( $real_raw ) === $real &&
		isset( $real['identity'], $real['owner'], $real['phase'], $real['total'], $real['completed'], $real['edit'],
			$real['option_bytes'], $real['option_sha256'] ) &&
		$real['identity'] === $identity && $real['owner'] === $expected_owner &&
		$real['phase'] === 'edited' && $real['total'] === 10000 && $real['completed'] === 10000,
		'Exact same-fixture complete edited 10K migration required.' );
	$status = StorageAdmin::status();
	$backup = $raw( StorageAuthority::STORAGE_BACKUP_OPTION );
	cleanup_check( $status['backend'] === 'table' && $status['phase'] === 'stable' &&
		$status['schema'] === 'verified' && $status['problem'] === '' &&
		null !== $raw( StorageAuthority::CONTROL_OPTION ) &&
		null !== $raw( StorageAuthority::AUTHORITY_OPTION ) &&
		null === $raw( FOOGALLERY_MIGRATE_OPTION_DATA ) && is_string( $backup ) &&
		strlen( $backup ) === $real['option_bytes'] && hash( 'sha256', $backup ) === $real['option_sha256'] &&
		foogallery_migrate_migrator_instance()->get_migrator_setting( $expected_owner ) === $real['edit'],
		'Exact stable table, retained option prefix, and typed current edit required.' );
	$journal = array( 'identity' => $identity, 'owner' => $owner, 'phase' => 'edited',
		'real' => $real, 'adopted_backend' => 'table', 'real_journal_sha256' => hash( 'sha256', $real_raw ),
		'adopted_package' => $manifest['package'] );
	$journal['adopted_posts_sha256'] = $verify_real( $journal );
	cleanup_check( add_option( $journal_key, $journal, '', false ), 'Owned table adoption journal insertion failed.' );
	$save( $journal );
}
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
if ( isset( $journal['fault_source'] ) ) {
	cleanup_check( $journal['fault_source'] === $fault_source(), 'Pinned cleanup source changed during fault journey.' );
}
if ( isset( $journal['real'] ) ) {
	$real = $journal['real'];
	$real_raw = $raw( 'fgm_real_owned_journey_v1' );
	cleanup_check( is_array( $real ) && isset( $real['identity'], $real['owner'] ) &&
		$real['identity'] === $identity && $real['owner'] === 'fgm-real-' . $suffix &&
		( ! isset( $journal['real_journal_sha256'] ) ||
			( is_string( $real_raw ) && hash( 'sha256', $real_raw ) === $journal['real_journal_sha256'] &&
				maybe_unserialize( $real_raw ) === $real ) ) &&
		( ! isset( $journal['adopted_package'] ) ||
			( isset( $manifest['package'] ) && $manifest['package'] === $journal['adopted_package'] ) ),
		'Adopted source ownership, journal or package changed before lifecycle operation.' );
	if ( isset( $journal['adopted_posts_sha256'] ) ) {
		$source = get_post( $real['source'] ); $destination = get_post( $real['destination'] );
		cleanup_check( $source && $destination && hash( 'sha256', serialize( array(
			$source->post_title, $source->post_content, $source->post_status,
			$destination->post_title, $destination->post_content, $destination->post_status ) ) ) === $journal['adopted_posts_sha256'],
			'Adopted source or destination post changed before lifecycle operation.' );
	}
}
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
	$selected_backend = isset( $journal['adopted_backend'] ) ? $journal['adopted_backend'] :
		( isset( $journal['real'] ) ? 'option' : 'table' );
	$status = StorageAdmin::status();
	cleanup_check( in_array( $selected_backend, array( 'option', 'table' ), true ) &&
		$status['backend'] === $selected_backend && 'stable' === $status['phase'] &&
		'verified' === $status['schema'] && '' === $status['problem'],
		'Adopted authority changed before clear.' );
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
	$selected_backend = isset( $journal['adopted_backend'] ) ? $journal['adopted_backend'] :
		( isset( $journal['real'] ) ? 'option' : 'table' );
	$status = StorageAdmin::status();
	cleanup_check( in_array( $selected_backend, array( 'option', 'table' ), true ) &&
		$status['backend'] === $selected_backend && 'stable' === $status['phase'] &&
		'verified' === $status['schema'] && '' === $status['problem'],
		'Adopted authority changed before repeated clear.' );
	$result['receipt'] = $request( 'clear', 'state', 'clear-current-state' );
	cleanup_check( ! empty( $result['receipt']['complete'] ) && get_option( FOOGALLERY_MIGRATE_OPTION_SETTINGS, null ) === $journal['preferences'],
		'Repeated clear did not complete while preserving preferences: ' . json_encode( $result['receipt'] ) );
	$journal['phase'] = 'cleared'; $save( $journal );
}
if ( 'verify-clear' === $step ) {
	cleanup_check( 'cleared' === $journal['phase'], 'Completed table clear required.' );
	$selected_backend = isset( $journal['adopted_backend'] ) ? $journal['adopted_backend'] :
		( isset( $journal['real'] ) ? 'option' : 'table' );
	cleanup_check( in_array( $selected_backend, array( 'option', 'table' ), true ), 'Invalid adopted backend.' );
	$status = StorageAdmin::status(); cleanup_check( 'stable' === $status['phase'] && 'verified' === $status['schema'] &&
		$status['backend'] === $selected_backend, 'Selected authority/schema not preserved by clear.' );
	$current = $raw( FOOGALLERY_MIGRATE_OPTION_DATA );
	cleanup_check( null !== $raw( StorageAuthority::CONTROL_OPTION ) &&
		( 'option' === $selected_backend ? null === $raw( StorageAuthority::AUTHORITY_OPTION ) : null !== $raw( StorageAuthority::AUTHORITY_OPTION ) ) &&
		null !== $raw( LegacyStorageSchema::VERSION_OPTION ) &&
		( 'option' === $selected_backend ? ( is_string( $current ) && maybe_unserialize( $current ) === array() ) : null === $current ) &&
		null === $raw( StorageAuthority::STORAGE_BACKUP_OPTION ) && null === $raw( MigratedStore::LEGACY_BACKUP_OPTION ) &&
		null === $raw( StorageAuthority::CLEANUP_OPTION ), 'Clear mode, stale-copy or marker mismatch.' );
	foreach ( array_slice( $fixed_tables, 2 ) as $table ) {
		cleanup_check( $exists_table( $table ) && '0' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM `' . $table . '` WHERE blog_id=%d', 1 ) ) && ! $wpdb->last_error, 'Cleared shared table still has site state.' );
	}
	cleanup_check( ! foogallery_migrate_migrator_instance()->get_migrator_setting( 'cleanup_probe', false ) &&
		! foogallery_migrate_migrator_instance()->get_migrator_setting( 'cleanup_edit', false ) &&
		! foogallery_migrate_migrator_instance()->get_migrator_setting( 'cleanup_after_clear', false ), 'Fresh ordinary read found old state.' );
	if ( isset( $journal['preferences'] ) ) { cleanup_check( get_option( FOOGALLERY_MIGRATE_OPTION_SETTINGS, null ) === $journal['preferences'], 'User preferences changed across clear.' ); }
	$result['storage'] = $status;
}
$fault_fixture = $site === '/private/tmp/foogallery-migrate-r0.mY4iGH' &&
	$manifest['database'] === 'fgm_r0_my4igh' && $manifest['container'] === 'fgm-r0-mY4iGH' &&
	$manifest['container_id'] === 'c790a47601f2fb569023e11c43c514428df1e158ab954e9f7c571da67d018a7e';
$verify_partial_remove_all = function ( $state ) use ( $fault_fixture, $fault_source, $raw, $exists_table, $fixed_tables ) {
	cleanup_check( $fault_fixture && $state['phase'] === 'remove-all-failed' &&
		isset( $state['fault_source'], $state['fault_marker_sha256'], $state['fault_receipt'] ) &&
		$state['fault_source'] === $fault_source(), 'Exact owned partial-cleanup fixture/source required.' );
	$receipt = $state['fault_receipt'];
	$expected = array( \FooPlugins\FooGalleryMigrate\StorageCleanup::MARKER,
		LegacyStorageSchema::VERSION_OPTION, $fixed_tables[2] );
	$actual = isset( $receipt['residual'] ) ? $receipt['residual'] : null;
	cleanup_check( is_array( $actual ) && count( $actual ) === count( $expected ), 'Partial cleanup residual count changed.' );
	sort( $actual ); sort( $expected );
	cleanup_check( $actual === $expected && isset( $receipt['action'], $receipt['complete'], $receipt['failed'], $receipt['removed'] ) &&
		$receipt['action'] === 'remove_all' && false === $receipt['complete'] &&
		$receipt['failed'] === array( 'Owned cleanup SQL failed.' ) &&
		in_array( $fixed_tables[3], $receipt['removed'], true ) &&
		! $exists_table( $fixed_tables[3] ) && $exists_table( $fixed_tables[2] ) &&
		null === $raw( FOOGALLERY_MIGRATE_OPTION_DATA ) && null === $raw( FOOGALLERY_MIGRATE_OPTION_SETTINGS ) &&
		null === $raw( StorageAuthority::CONTROL_OPTION ) && null === $raw( StorageAuthority::AUTHORITY_OPTION ) &&
		null !== $raw( LegacyStorageSchema::VERSION_OPTION ),
		'Native first DROP, rejected second DROP, authority or exact residual proof failed.' );
	$marker = $raw( \FooPlugins\FooGalleryMigrate\StorageCleanup::MARKER );
	cleanup_check( is_string( $marker ) && hash( 'sha256', $marker ) === $state['fault_marker_sha256'] &&
		\FooPlugins\FooGalleryMigrate\StorageCleanup::pending_scope() === 'remove_all' &&
		is_wp_error( StorageAuthority::runtime_record() ), 'Durable marker did not block ordinary runtime.' );
};
if ( 'fault-remove-all' === $step ) {
	/** One test-only server-query refusal; all other SQL uses the real pinned mysqli handle. */
	class CleanupOneDropRejectConnection extends \FooPlugins\FooGalleryMigrate\LegacyTransactionConnection {
		private $target;
		public $rejections = 0;
		public function __construct( $wpdb, $target ) { parent::__construct( $wpdb ); $this->target = 'DROP TABLE `' . $target . '`'; }
		public function query( $sql ) {
			if ( $sql === $this->target && 0 === $this->rejections ) {
				$this->rejections++; $this->last_error = 'Owned one-shot DROP refusal'; return false;
			}
			return parent::query( $sql );
		}
	}
	cleanup_check( $fault_fixture && ! isset( $manifest['package'] ) && ! isset( $journal['real'] ) &&
		$journal['phase'] === 'state-seeded' && null === $raw( \FooPlugins\FooGalleryMigrate\StorageCleanup::MARKER ) &&
		null !== $raw( FOOGALLERY_MIGRATE_OPTION_DATA ) && null !== $raw( LegacyStorageSchema::VERSION_OPTION ) &&
		null === $raw( StorageAuthority::CONTROL_OPTION ) && null === $raw( StorageAuthority::AUTHORITY_OPTION ) &&
		$exists_table( $fixed_tables[2] ) && $exists_table( $fixed_tables[3] ) &&
		! $exists_table( $fixed_tables[0] ) && ! $exists_table( $fixed_tables[1] ),
		'Exact option-authoritative source fixture and two real shared tables required for one-shot DROP failure.' );
	$status = StorageAdmin::status();
	cleanup_check( $status['backend'] === 'option' && $status['phase'] === 'stable' &&
		$status['schema'] === 'verified' && $status['problem'] === '', 'Stable option authority and verified schema required before fault.' );
	$journal['fault_source'] = $fault_source(); $journal['phase'] = 'fault-armed'; $save( $journal );
	$gate = StorageMaintenanceGate::current(); $original = $gate->connection();
	$probe = new CleanupOneDropRejectConnection( $wpdb, $fixed_tables[2] );
	$property = new ReflectionProperty( 'FooPlugins\\FooGalleryMigrate\\StorageMaintenanceGate', 'connection' );
	$property->setAccessible( true );
	cleanup_check( $property->getValue( $gate ) === $original, 'Pinned connection changed before test fault.' );
	$property->setValue( $gate, $probe );
	cleanup_check( $gate->connection() === $probe, 'Test-only pinned connection was not installed.' );
	$receipt = StorageCli::execute( 'remove_all', array( 'target' => 'all', 'confirm' => 'remove-all-internal-data' ) );
	cleanup_check( 1 === $probe->rejections && is_array( $receipt ) && isset( $receipt['complete'] ) &&
		false === $receipt['complete'], 'One precise shared-state DROP must be rejected.' );
	$marker = $raw( \FooPlugins\FooGalleryMigrate\StorageCleanup::MARKER );
	cleanup_check( is_string( $marker ), 'Failed cleanup did not retain its durable marker.' );
	$journal['phase'] = 'remove-all-failed'; $journal['fault_marker_sha256'] = hash( 'sha256', $marker );
	$journal['fault_receipt'] = $receipt; $save( $journal );
	$verify_partial_remove_all( $journal );
	$result['receipt'] = $receipt; $result['rejected_drop'] = $fixed_tables[2];
}
if ( 'verify-partial-remove-all' === $step ) {
	$verify_partial_remove_all( $journal ); $result['receipt'] = $journal['fault_receipt'];
}
if ( 'wrong-action-refuses' === $step ) {
	$verify_partial_remove_all( $journal ); $before = $fault_snapshot();
	$denied = StorageCli::execute( 'clear', array( 'target' => 'state', 'confirm' => 'clear-current-state' ) );
	cleanup_check( is_array( $denied ) && isset( $denied['complete'], $denied['failed'] ) &&
		false === $denied['complete'] && $denied['failed'] === array( 'Cleanup marker requires assisted classification.' ) &&
		$before === $fault_snapshot(),
		'Wrong cleanup action did not refuse without changing owned SQL.' );
	$verify_partial_remove_all( $journal );
	$journal['wrong_action_refused'] = true; $save( $journal ); $result['wrong_action'] = $denied;
}
if ( 'resume-remove-all' === $step ) {
	$verify_partial_remove_all( $journal );
	cleanup_check( isset( $journal['wrong_action_refused'] ) && true === $journal['wrong_action_refused'],
		'Wrong-action refusal must be observed before fresh-request resume.' );
	$result['receipt'] = $request( 'remove_all', 'all', 'remove-all-internal-data' );
	cleanup_check( ! empty( $result['receipt']['complete'] ) && empty( $result['receipt']['failed'] ) &&
		empty( $result['receipt']['residual'] ), 'Same-scope cleanup did not complete.' );
	$no_internal(); $journal['phase'] = 'removed'; $save( $journal );
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
	require $plugin_dir . '/uninstall.php';
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
$verify_real( $journal );
$result['phase'] = $journal['phase'];
$result['protected_posts'] = isset( $journal['real'] ) ? array( 'actual_attachments' => $journal['real']['total'],
	'actual_source' => $journal['real']['source'], 'actual_destination' => $journal['real']['destination'] ) : $journal['posts'];
echo json_encode( $result, JSON_UNESCAPED_SLASHES ) . "\n";
