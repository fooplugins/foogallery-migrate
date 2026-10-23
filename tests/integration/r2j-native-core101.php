<?php
/**
 * One owned Core reusable-gallery 101-selection proof on empty mY4iGH only.
 * After independent review and a frozen source digest, run each phase in a
 * fresh WP-CLI process: seed, enable, recover (as needed), migrate, verify. No phase auto-resumes a
 * pending marker, and nothing here deletes source or destination posts/files.
 *
 * wp --path=/private/tmp/foogallery-migrate-r0.mY4iGH eval-file tests/integration/r2j-native-core101.php /private/tmp/foogallery-migrate-r0.mY4iGH PHASE REVIEWED_CANDIDATE_SHA256 --user=fgmr0admin
 */
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use FooPlugins\FooGalleryMigrate\StorageAdmin;
use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\StorageCli;
use FooPlugins\FooGalleryMigrate\StorageControl;
use FooPlugins\FooGalleryMigrate\StorageMaintenanceGate;
use FooPlugins\FooGalleryMigrate\Objects\Migratable;
use FooPlugins\FooGalleryMigrate\Plugins\WordPressCore;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! isset( $args ) || count( $args ) !== 3 ||
	$args[0] !== '/private/tmp/foogallery-migrate-r0.mY4iGH' ||
	! in_array( $args[1], array( 'seed', 'enable', 'recover', 'migrate', 'verify' ), true ) ||
	! is_string( $args[2] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $args[2] ) ) {
	throw new RuntimeException( 'Exact owned R2j site, phase and reviewed candidate SHA required.' );
}
$r2j_site = $args[0]; $r2j_phase = $args[1]; $r2j_reviewed_sha = $args[2];
if ( realpath( ABSPATH ) !== $r2j_site || is_multisite() ) { throw new RuntimeException( 'Loaded owned site differs.' ); }
$r2j_manifest_path = $r2j_site . '/.fgm-r0-owner.json';
$r2j_manifest = is_file( $r2j_manifest_path ) ? json_decode( file_get_contents( $r2j_manifest_path ), true ) : null;
$r2j_repo = realpath( dirname( dirname( __DIR__ ) ) );
if ( ! is_array( $r2j_manifest ) || ! isset( $r2j_manifest['kind'], $r2j_manifest['site'],
	$r2j_manifest['database'], $r2j_manifest['host'], $r2j_manifest['repo'],
	$r2j_manifest['container'], $r2j_manifest['container_id'] ) ||
	$r2j_manifest['kind'] !== 'foogallery-migrate-r0-v1' ||
	realpath( $r2j_manifest['site'] ) !== $r2j_site ||
	$r2j_manifest['database'] !== 'fgm_r0_my4igh' ||
	$r2j_manifest['container'] !== 'fgm-r0-mY4iGH' ||
	$r2j_manifest['container_id'] !== 'c790a47601f2fb569023e11c43c514428df1e158ab954e9f7c571da67d018a7e' ||
	realpath( $r2j_manifest['repo'] ) !== $r2j_repo ||
	realpath( $r2j_site . '/wp-content/plugins/foogallery-migrate' ) !== $r2j_repo ||
	! is_file( $r2j_site . '/wp-config.php' ) ) {
	throw new RuntimeException( 'Exact disposable manifest or source checkout differs.' );
}
$r2j_live = array(); $r2j_live_code = 0;
exec( 'docker inspect --format ' . escapeshellarg( '{{.Id}} {{index .Config.Labels "foogallery-migrate-r0"}} {{.State.Running}}' ) .
	' ' . escapeshellarg( $r2j_manifest['container'] ) . ' 2>/dev/null', $r2j_live, $r2j_live_code );
if ( $r2j_live_code !== 0 || count( $r2j_live ) !== 1 ||
	trim( $r2j_live[0] ) !== $r2j_manifest['container_id'] . ' owned true' ) {
	throw new RuntimeException( 'Exact live owned SQL container differs.' );
}
ini_set( 'memory_limit', '256M' );
global $wpdb, $wp_version;
function r2j_check( $okay, $message ) { if ( ! $okay ) { throw new RuntimeException( $message ); } }
r2j_check( ini_get( 'memory_limit' ) === '256M' && DB_NAME === $r2j_manifest['database'] &&
	DB_HOST === $r2j_manifest['host'] && $wpdb->get_var( 'SELECT DATABASE()' ) === $r2j_manifest['database'] &&
	! $wpdb->last_error && get_current_user_id() > 0 &&
	wp_get_current_user()->user_login === 'fgmr0admin' && current_user_can( 'manage_options' ) &&
	function_exists( 'foogallery_migrate_migrator_instance' ) &&
	function_exists( 'foogallery_default_gallery_template' ),
	'Loaded database, administrator, plugins or effective 256M differs.' );
$r2j_identity = hash( 'sha256', $r2j_site . '|' . $r2j_manifest['database'] . '|' . $r2j_manifest['container_id'] );
$r2j_owner = 'fgm-r2j-mY4iGH'; $r2j_owner_meta = '_fgm_r2j_owner';
$r2j_journal_key = 'fgm_r2j_native_core101_v1';
$r2j_marker_key = 'fgm_r2j_native_core101_pending_v1';
$r2j_raw = function ( $name ) use ( $wpdb ) {
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT option_value FROM `' . $wpdb->options .
		'` WHERE option_name=%s LIMIT 2', $name ), ARRAY_A );
	r2j_check( is_array( $rows ) && ! $wpdb->last_error && count( $rows ) <= 1,
		'Exact option SQL read failed: ' . $name );
	return count( $rows ) ? $rows[0]['option_value'] : null;
};
$r2j_save = function ( $name, $value, $new ) use ( $r2j_raw ) {
	$before = $r2j_raw( $name );
	r2j_check( ( null === $before ) === $new, 'Journal or marker presence differs before write.' );
	$ok = $new ? add_option( $name, $value, '', 'no' ) : update_option( $name, $value, false );
	$after = $r2j_raw( $name );
	r2j_check( $ok && is_string( $after ) && $after === maybe_serialize( $value ) &&
		maybe_unserialize( $after ) === $value &&
		get_option( $name, null ) === $value, 'Journal or marker durable readback failed.' );
};
$r2j_clear_marker = function ( $expected ) use ( $r2j_raw, $r2j_marker_key ) {
	$raw = $r2j_raw( $r2j_marker_key );
	r2j_check( is_string( $raw ) && $raw === maybe_serialize( $expected ) &&
		get_option( $r2j_marker_key, null ) === $expected &&
		delete_option( $r2j_marker_key ) && null === $r2j_raw( $r2j_marker_key ) &&
		null === get_option( $r2j_marker_key, null ),
		'Exact pending marker raw, API identity, delete or readback differs.' );
};
$r2j_marker = $r2j_raw( $r2j_marker_key );
r2j_check( null === $r2j_marker, 'Pending R2j operation requires manual inspection; no replay.' );
$r2j_cleanup_raw = $r2j_raw( 'fgm_cleanup_owned_journey_v1' );
$r2j_cleanup = is_string( $r2j_cleanup_raw ) ? maybe_unserialize( $r2j_cleanup_raw ) : null;
r2j_check( is_array( $r2j_cleanup ) && isset( $r2j_cleanup['identity'], $r2j_cleanup['owner'],
	$r2j_cleanup['phase'], $r2j_cleanup['posts'], $r2j_cleanup['post_hashes'],
	$r2j_cleanup['media_hash'] ) && $r2j_cleanup['identity'] === $r2j_identity &&
	$r2j_cleanup['owner'] === 'fgm-cleanup-mY4iGH' && $r2j_cleanup['phase'] === 'removed' &&
	$r2j_cleanup['posts'] === array( 'page' => 4, 'foogallery' => 5, 'attachment' => 6 ) &&
	get_option( 'fgm_cleanup_owned_journey_v1' ) === $r2j_cleanup,
	'Accepted empty-fixture cleanup journal differs.' );
foreach ( $r2j_cleanup['posts'] as $kind => $id ) {
	$post = get_post( $id );
	r2j_check( $post && $post->post_type === $kind &&
		get_post_meta( $id, '_fgm_cleanup_owner', true ) === 'fgm-cleanup-mY4iGH' &&
		isset( $r2j_cleanup['post_hashes'][$kind] ) &&
		hash( 'sha256', $post->post_title . '|' . $post->post_content ) === $r2j_cleanup['post_hashes'][$kind],
		'Accepted cleanup journal protected post changed: ' . $kind );
}
$r2j_media = get_attached_file( 6 );
r2j_check( is_string( $r2j_media ) && is_file( $r2j_media ) && ! is_link( $r2j_media ) &&
	strpos( realpath( $r2j_media ), $r2j_site . '/wp-content/uploads/' ) === 0 &&
	hash_file( 'sha256', $r2j_media ) === $r2j_cleanup['media_hash'],
	'Protected prior attachment or media file differs.' );
$r2j_baseline = function () use ( $wpdb ) {
	$posts = $wpdb->get_results( 'SELECT * FROM `' . $wpdb->posts . '` ORDER BY ID LIMIT 7', ARRAY_A );
	r2j_check( is_array( $posts ) && ! $wpdb->last_error && count( $posts ) >= 6 &&
		array_map( 'intval', array_column( array_slice( $posts, 0, 6 ), 'ID' ) ) === array( 1, 2, 3, 4, 5, 6 ),
		'Protected six-post baseline differs.' );
	$meta = $wpdb->get_results( 'SELECT * FROM `' . $wpdb->postmeta .
		'` WHERE post_id IN (1,2,3,4,5,6) ORDER BY meta_id LIMIT 101', ARRAY_A );
	r2j_check( is_array( $meta ) && ! $wpdb->last_error && count( $meta ) < 101,
		'Protected metadata baseline is unavailable or oversized.' );
	return array( 'posts' => hash( 'sha256', serialize( array_slice( $posts, 0, 6 ) ) ),
		'meta' => hash( 'sha256', serialize( $meta ) ) );
};
$r2j_upload = wp_upload_dir( null, false );
$r2j_upload_root = $r2j_site . '/wp-content/uploads';
r2j_check( empty( $r2j_upload['error'] ) && isset( $r2j_upload['basedir'], $r2j_upload['baseurl'] ) &&
	$r2j_upload['basedir'] === $r2j_upload_root && ! is_link( $r2j_upload_root ) &&
	realpath( $r2j_upload_root ) === $r2j_upload_root,
	'Uploads must stay within the exact canonical owned root.' );
$r2j_dir = $r2j_upload_root . '/' . $r2j_owner;
$r2j_file = $r2j_dir . '/one.png';
$r2j_png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=' );
$r2j_png_hash = hash( 'sha256', $r2j_png );
$r2j_shortcode = function ( $attachment ) { return '[gallery ids="' . $attachment . '" columns="2"]'; };
$r2j_revisions = function ( $state ) use ( $wpdb, $r2j_shortcode ) {
	$size = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS row_count, COALESCE(SUM(OCTET_LENGTH(post_title) + OCTET_LENGTH(post_content)),0) AS byte_count FROM `' .
		$wpdb->posts . '` WHERE post_type=%s', 'revision' ), ARRAY_A );
	r2j_check( is_array( $size ) && ! $wpdb->last_error &&
		(int) $size['row_count'] <= 204 && (int) $size['byte_count'] <= 2097152,
		'Bounded owned revision inventory exceeded.' );
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT ID, post_parent, post_status, post_title, post_content FROM `' .
		$wpdb->posts . '` WHERE post_type=%s ORDER BY ID LIMIT 205', 'revision' ), ARRAY_A );
	r2j_check( is_array( $rows ) && ! $wpdb->last_error && count( $rows ) === (int) $size['row_count'],
		'Owned revision SQL inventory differs.' );
	$allowed = array( (int) $state['page'] );
	if ( $state['phase'] === 'done' ) {
		r2j_check( isset( $state['destinations'] ) && count( $state['destinations'] ) === 101,
			'Completed revision parents require exact destinations.' );
		$allowed = array_merge( $allowed, $state['destinations'] );
	}
	$original = implode( "\n", array_fill( 0, 101, $r2j_shortcode( (int) $state['attachment'] ) ) );
	foreach ( $rows as $row ) {
		$parent_id = (int) $row['post_parent']; $parent = get_post( $parent_id );
		r2j_check( in_array( $parent_id, $allowed, true ) && $parent &&
			$row['post_status'] === 'inherit' &&
			( $parent_id !== (int) $state['page'] ||
				( $row['post_title'] === 'R2j owned 101 Core galleries' &&
					in_array( $row['post_content'], array( $original,
						isset( $state['replaced_sha256'] ) ? $parent->post_content : $original ), true ) ) ),
			'Revision escaped the owned source/output set or contains unexpected source bytes.' );
	}
	return array( 'count' => count( $rows ), 'sha256' => hash( 'sha256', serialize( $rows ) ) );
};
$r2j_owned_posts = function () use ( $wpdb, $r2j_owner_meta, $r2j_owner ) {
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT p.ID, p.post_type FROM `' . $wpdb->posts .
		'` p JOIN `' . $wpdb->postmeta . '` m ON m.post_id=p.ID WHERE m.meta_key=%s AND m.meta_value=%s ORDER BY p.ID LIMIT 4',
		$r2j_owner_meta, $r2j_owner ), ARRAY_A );
	r2j_check( is_array( $rows ) && ! $wpdb->last_error && count( $rows ) < 4,
		'Owned post inventory differs.' );
	return $rows;
};
$r2j_fixed_options = array( FOOGALLERY_MIGRATE_OPTION_DATA,
	StorageAuthority::CONTROL_OPTION, StorageAuthority::AUTHORITY_OPTION,
	StorageAuthority::MAINTENANCE_OPTION, StorageAuthority::CLEANUP_OPTION,
	StorageAuthority::STORAGE_BACKUP_OPTION, LegacyStorageSchema::VERSION_OPTION,
	LegacyStorageSchema::LOCK_OPTION, 'foogallery-migrate-settings',
	'foogallery-migrate-migrated-legacy-backup', 'foogallery_migrate_storage',
	'foogallery-migrate-store-schema-version', 'foogallery-migrate-store-migration-version' );
$r2j_schema = new LegacyStorageSchema( $wpdb );
$r2j_assert_pristine = function () use ( $r2j_raw, $r2j_fixed_options, $r2j_schema,
	$wpdb, $r2j_owned_posts, $r2j_dir ) {
	foreach ( $r2j_fixed_options as $name ) {
		r2j_check( null === $r2j_raw( $name ), 'Existing migration option/marker forbids R2j seed: ' . $name );
	}
	$tables = array_unique( array_merge( array_keys( $r2j_schema->definitions() ),
		array( $wpdb->prefix . 'foogallery_migrate_objects',
			$wpdb->prefix . 'foogallery_migrate_state' ) ) );
	foreach ( $tables as $table ) {
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		r2j_check( ! $wpdb->last_error && null === $found, 'Existing storage table forbids R2j seed.' );
	}
	$writer = $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM `' . $wpdb->options .
		'` WHERE option_name LIKE %s LIMIT 1', $wpdb->esc_like( StorageMaintenanceGate::WRITER_PREFIX ) . '%' ) );
	r2j_check( ! $wpdb->last_error && null === $writer && $r2j_owned_posts() === array() &&
		! file_exists( $r2j_dir ) && ! is_link( $r2j_dir ) &&
		StorageAdmin::status()['backend'] === 'option' && StorageAdmin::status()['phase'] === 'stable',
		'R2j requires clean empty option authority and no owned seed collision.' );
};
$r2j_candidate_files = array( 'migrate.php', 'includes/class-migrator-engine.php',
	'includes/migrators/class-migrator-base.php', 'includes/migrators/class-content-migrator.php',
	'includes/class-migratable-collection-repository.php', 'includes/class-content-scan-repository.php',
	'includes/class-legacy-table-backend.php', 'includes/class-legacy-table-repository.php',
	'includes/plugins/class-word-press-core.php', 'includes/class-storage-operations.php',
	'tests/integration/r2j-native-core101.php' );
$r2j_candidate = array();
foreach ( $r2j_candidate_files as $file ) {
	$path = $r2j_repo . '/' . $file;
	r2j_check( is_file( $path ) && ! is_link( $path ), 'Candidate source path differs: ' . $file );
	$r2j_candidate[$file] = hash_file( 'sha256', $path );
}
$r2j_candidate_sha = hash( 'sha256', serialize( $r2j_candidate ) );
r2j_check( $r2j_candidate_sha === $r2j_reviewed_sha, 'Unreviewed R2j candidate source changed.' );
$r2j_journal_raw = $r2j_raw( $r2j_journal_key );
$r2j_journal = is_string( $r2j_journal_raw ) ? maybe_unserialize( $r2j_journal_raw ) : null;
$r2j_source = function ( $state ) use ( $r2j_owned_posts, $r2j_file, $r2j_png_hash,
	$r2j_owner, $r2j_owner_meta, $r2j_shortcode, $r2j_upload_root, $r2j_baseline,
	$r2j_cleanup_raw, $r2j_raw, $r2j_media, $r2j_cleanup, $r2j_revisions, $wpdb ) {
	r2j_check( isset( $state['attachment'], $state['page'], $state['content_sha256'], $state['baseline'] ) &&
		$state['baseline'] === $r2j_baseline() &&
		$r2j_raw( 'fgm_cleanup_owned_journey_v1' ) === $r2j_cleanup_raw &&
		is_file( $r2j_media ) && hash_file( 'sha256', $r2j_media ) === $r2j_cleanup['media_hash'],
		'Protected six posts, metadata, cleanup journal or media changed.' );
	$attachment = (int) $state['attachment']; $page_id = (int) $state['page'];
	$owned = $r2j_owned_posts();
	r2j_check( count( $owned ) === 2 &&
		array_map( 'intval', array_column( $owned, 'ID' ) ) === array( $attachment, $page_id ) &&
		array_column( $owned, 'post_type' ) === array( 'attachment', 'page' ),
		'Exactly one owned attachment and page required.' );
	$page = get_post( $page_id ); $image = get_post( $attachment );
	$expected_content = implode( "\n", array_fill( 0, 101, $r2j_shortcode( $attachment ) ) );
	$metadata = wp_get_attachment_metadata( $attachment );
	r2j_check( $page && $image && $page->post_type === 'page' && $page->post_status === 'publish' &&
		$image->post_type === 'attachment' && $image->post_status === 'inherit' &&
		$image->post_mime_type === 'image/png' &&
		get_post_meta( $page_id, $r2j_owner_meta, true ) === $r2j_owner &&
		get_post_meta( $attachment, $r2j_owner_meta, true ) === $r2j_owner &&
		get_post_meta( $attachment, '_wp_attached_file', true ) === $r2j_owner . '/one.png' &&
		is_array( $metadata ) && isset( $metadata['file'], $metadata['width'], $metadata['height'] ) &&
		$metadata['file'] === $r2j_owner . '/one.png' &&
		(int) $metadata['width'] === 1 && (int) $metadata['height'] === 1 &&
		get_attached_file( $attachment ) === $r2j_file &&
		is_file( $r2j_file ) && ! is_link( $r2j_file ) &&
		realpath( $r2j_file ) === $r2j_file &&
		strpos( $r2j_file, $r2j_upload_root . '/' ) === 0 &&
		hash_file( 'sha256', $r2j_file ) === $r2j_png_hash &&
		$state['content_sha256'] === hash( 'sha256', $expected_content ),
		'Owned Core source attachment/file/page identity differs.' );
	$post_count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM `' . $wpdb->posts .
		'` WHERE post_type<>%s', 'revision' ) );
	r2j_check( ! $wpdb->last_error && (int) $post_count ===
		( 'done' === $state['phase'] ? 109 : 8 ),
		'Owned non-revision source/output post count differs.' );
	$revisions = $r2j_revisions( $state );
	$expected_revisions = 'done' === $state['phase'] ? 'revisions' : 'seed_revisions';
	r2j_check( isset( $state[$expected_revisions] ) && $state[$expected_revisions] === $revisions,
		'Exact bounded owned revision inventory differs.' );
	if ( in_array( $state['phase'], array( 'seeded', 'table' ), true ) ) {
		r2j_check( $page->post_content === $expected_content,
			'Unmigrated source page changed.' );
	}
	return $page;
};

if ( 'seed' === $r2j_phase ) {
	r2j_check( null === $r2j_journal_raw && (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $wpdb->posts . '`' ) === 6 &&
		! $wpdb->last_error, 'R2j seed requires exactly the accepted six-post fixture.' );
	$r2j_assert_pristine();
	$r2j_state = array( 'identity' => $r2j_identity, 'candidate' => $r2j_candidate,
		'phase' => 'seed_pending', 'baseline' => $r2j_baseline(),
		'cleanup_sha256' => hash( 'sha256', $r2j_cleanup_raw ) );
	$r2j_save( $r2j_journal_key, $r2j_state, true );
	$r2j_pending = array( 'identity' => $r2j_identity, 'phase' => 'seed_pending' );
	$r2j_save( $r2j_marker_key, $r2j_pending, true );
	r2j_check( ! file_exists( $r2j_dir ) && ! is_link( $r2j_dir ) && wp_mkdir_p( $r2j_dir ),
		'Owned media directory collision or creation failed.' );
	r2j_check( ! file_exists( $r2j_file ) && ! is_link( $r2j_file ) &&
		file_put_contents( $r2j_file, $r2j_png, LOCK_EX ) === strlen( $r2j_png ) &&
		hash_file( 'sha256', $r2j_file ) === $r2j_png_hash,
		'Owned one-pixel PNG collision or write failed.' );
	$attachment = wp_insert_attachment( array( 'post_title' => 'R2j owned Core image',
		'post_status' => 'inherit', 'post_mime_type' => 'image/png',
		'guid' => trailingslashit( $r2j_upload['baseurl'] ) . $r2j_owner . '/one.png',
		'meta_input' => array( $r2j_owner_meta => $r2j_owner ) ), $r2j_file, 0, true );
	r2j_check( ! is_wp_error( $attachment ) && is_int( $attachment ) && $attachment > 6 &&
		get_attached_file( $attachment ) === $r2j_file &&
		get_post_meta( $attachment, '_wp_attached_file', true ) === $r2j_owner . '/one.png',
		'Owned attachment insertion or native file binding failed.' );
	$relative = $r2j_owner . '/one.png';
	r2j_check( wp_update_attachment_metadata( $attachment,
		array( 'width' => 1, 'height' => 1, 'file' => $relative ) ),
		'Owned attachment metadata failed.' );
	$content = implode( "\n", array_fill( 0, 101, $r2j_shortcode( $attachment ) ) );
	$page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish',
		'post_title' => 'R2j owned 101 Core galleries', 'post_content' => $content,
		'meta_input' => array( $r2j_owner_meta => $r2j_owner ) ), true );
	r2j_check( ! is_wp_error( $page ) && is_int( $page ) && $page > $attachment,
		'Owned 101-occurrence source page insertion failed.' );
	$r2j_state['attachment'] = $attachment; $r2j_state['page'] = $page;
	$r2j_state['content_sha256'] = hash( 'sha256', $content );
	$r2j_state['phase'] = 'seeded';
	$r2j_state['seed_revisions'] = $r2j_revisions( $r2j_state );
	$r2j_save( $r2j_journal_key, $r2j_state, false );
	$r2j_source( $r2j_state );
	$r2j_clear_marker( $r2j_pending );
	echo wp_json_encode( array( 'phase' => 'seeded', 'attachment' => $attachment,
		'page' => $page, 'occurrences' => 101, 'candidate_sha256' => $r2j_candidate_sha,
		'memory_limit' => ini_get( 'memory_limit' ), 'peak_bytes' => memory_get_peak_usage( true ) ) ) . "\n";
	return;
}
r2j_check( is_array( $r2j_journal ) && isset( $r2j_journal['identity'], $r2j_journal['phase'],
	$r2j_journal['candidate'], $r2j_journal['cleanup_sha256'] ) &&
	$r2j_journal['identity'] === $r2j_identity &&
	$r2j_journal['candidate'] === $r2j_candidate &&
	$r2j_journal['cleanup_sha256'] === hash( 'sha256', $r2j_cleanup_raw ),
	'Fresh R2j journal, source candidate or prior cleanup differs.' );
$r2j_source( $r2j_journal );
$r2j_conversion = function () use ( $r2j_raw, $r2j_schema ) {
	$status = StorageAdmin::status();
	r2j_check( is_array( $status ) && isset( $status['backend'], $status['phase'],
		$status['target'], $status['problem'] ) && $status['problem'] === '' &&
		$r2j_schema->verify() && null === $r2j_raw( StorageAuthority::MAINTENANCE_OPTION ) &&
		null === $r2j_raw( StorageAuthority::CLEANUP_OPTION ) &&
		null === $r2j_raw( FOOGALLERY_MIGRATE_OPTION_DATA ),
		'Public conversion authority, owner, source or schema differs.' );
	$raw = $r2j_raw( StorageAuthority::CONTROL_OPTION );
	$control = StorageControl::decode( $raw, get_current_blog_id() );
	r2j_check( is_array( $control ) && $control['from'] === 'option' &&
		$control['to'] === 'table' && $control['phase'] === $status['phase'] &&
		$status['target'] === 'table' &&
		( ( $status['phase'] === 'stable' && $status['backend'] === 'table' ) ||
			( $status['phase'] !== 'stable' && $status['backend'] === $control['backend'] ) ),
		'Public conversion control or target differs.' );
	return array( 'status' => $status, 'control_sha256' => hash( 'sha256', $raw ) );
};
if ( 'enable' === $r2j_phase ) {
	r2j_check( $r2j_journal['phase'] === 'seeded' &&
		StorageAdmin::status()['backend'] === 'option' &&
		null === $r2j_raw( FOOGALLERY_MIGRATE_OPTION_DATA ),
		'Empty option authority required before public enablement.' );
	$r2j_journal['phase'] = 'enable_pending'; $r2j_save( $r2j_journal_key, $r2j_journal, false );
	$r2j_pending = array( 'identity' => $r2j_identity, 'phase' => 'enable_pending' );
	$r2j_save( $r2j_marker_key, $r2j_pending, true );
	$provision = StorageCli::execute( 'provision', array( 'target' => 'table', 'confirm' => 'provision-shared-schema' ) );
	r2j_check( ! is_wp_error( $provision ), 'Public table provision refused.' );
	$switch = StorageCli::execute( 'switch', array( 'target' => 'table', 'confirm' => 'switch-to-table' ) );
	r2j_check( ! is_wp_error( $switch ), 'Public empty-table switch refused.' );
	$conversion = $r2j_conversion();
	$r2j_journal['phase'] = $conversion['status']['phase'] === 'stable' ? 'table' : 'copying';
	$r2j_journal['control_sha256'] = $conversion['control_sha256'];
	$r2j_save( $r2j_journal_key, $r2j_journal, false );
	$r2j_source( $r2j_journal );
	$r2j_clear_marker( $r2j_pending );
	echo wp_json_encode( array( 'phase' => $r2j_journal['phase'], 'provision' => $provision,
		'switch' => $switch, 'memory_limit' => ini_get( 'memory_limit' ),
		'peak_bytes' => memory_get_peak_usage( true ) ) ) . "\n";
	return;
}
if ( 'recover' === $r2j_phase ) {
	r2j_check( $r2j_journal['phase'] === 'copying' &&
		isset( $r2j_journal['control_sha256'] ),
		'Only one recorded nonstable forward conversion may recover.' );
	$before = $r2j_conversion();
	r2j_check( $before['status']['phase'] !== 'stable' &&
		$before['control_sha256'] === $r2j_journal['control_sha256'],
		'Fresh recovery control changed outside this fixture journal.' );
	$r2j_journal['phase'] = 'recover_pending';
	$r2j_save( $r2j_journal_key, $r2j_journal, false );
	$r2j_pending = array( 'identity' => $r2j_identity, 'phase' => 'recover_pending' );
	$r2j_save( $r2j_marker_key, $r2j_pending, true );
	$recovered = StorageCli::execute( 'recover', array( 'target' => 'table', 'confirm' => 'recover-storage' ) );
	r2j_check( ! is_wp_error( $recovered ), 'Public forward recovery refused.' );
	$after = $r2j_conversion();
	r2j_check( $after['status']['phase'] === 'stable' ||
		$after['control_sha256'] !== $before['control_sha256'],
		'Fresh recovery made no recorded conversion progress; retain pending evidence.' );
	$r2j_journal['phase'] = $after['status']['phase'] === 'stable' ? 'table' : 'copying';
	$r2j_journal['control_sha256'] = $after['control_sha256'];
	$r2j_save( $r2j_journal_key, $r2j_journal, false );
	$r2j_source( $r2j_journal );
	$r2j_clear_marker( $r2j_pending );
	echo wp_json_encode( array( 'phase' => $r2j_journal['phase'], 'recover' => $recovered,
		'control_sha256' => $after['control_sha256'], 'memory_limit' => ini_get( 'memory_limit' ),
		'peak_bytes' => memory_get_peak_usage( true ) ) ) . "\n";
	return;
}
r2j_check( StorageAdmin::status()['backend'] === 'table' &&
	StorageAdmin::status()['phase'] === 'stable' && $r2j_schema->verify(),
	'Stable verified table authority required.' );
$r2j_destinations = function ( $state ) use ( $wpdb ) {
	r2j_check( isset( $state['source_ids'], $state['destinations'], $state['page'], $state['attachment'] ) &&
		count( $state['source_ids'] ) === 101 && count( array_unique( $state['source_ids'] ) ) === 101 &&
		count( $state['destinations'] ) === 101 && count( array_unique( $state['destinations'] ) ) === 101,
		'Pinned 101 source/destination dimensions differ.' );
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT p.ID, p.post_type, p.post_status, m.meta_value AS source_key FROM `' .
		$wpdb->posts . '` p JOIN `' . $wpdb->postmeta . '` m ON m.post_id=p.ID WHERE m.meta_key=%s AND p.post_type=%s ORDER BY p.ID LIMIT 102',
		Migratable::META_SOURCE_KEY, FOOGALLERY_CPT_GALLERY ), ARRAY_A );
	r2j_check( is_array( $rows ) && ! $wpdb->last_error && count( $rows ) === 101,
		'Exactly 101 reusable destination posts required.' );
	$map = array();
	foreach ( $rows as $row ) {
		r2j_check( $row['post_type'] === FOOGALLERY_CPT_GALLERY &&
			$row['post_status'] === 'publish' && ! isset( $map[$row['source_key']] ),
			'Destination type, status or source UID collision differs.' );
		$map[$row['source_key']] = (int) $row['ID'];
	}
	$ordered = array();
	foreach ( $state['source_ids'] as $source_id ) {
		$key = 'gallery_WordPress Core_' . $source_id;
		r2j_check( isset( $map[$key] ) &&
			get_post_meta( $map[$key], FOOGALLERY_META_ATTACHMENTS, true ) === array( (int) $state['attachment'] ),
			'One ordered Core source/output membership differs.' );
		$ordered[] = $map[$key];
	}
	r2j_check( $ordered === $state['destinations'], 'Destination IDs or source order differ.' );
	$page = get_post( (int) $state['page'] );
	$expected_replaced = array();
	foreach ( $ordered as $id ) { $expected_replaced[] = '[foogallery id="' . $id . '"]'; }
	r2j_check( $page && $page->post_type === 'page' && $page->post_status === 'publish' &&
		$page->post_content === implode( "\n", $expected_replaced ) &&
		hash( 'sha256', $page->post_content ) === $state['replaced_sha256'],
		'Exact 101 ordered stored replacements differ.' );
	return $ordered;
};
if ( 'migrate' === $r2j_phase ) {
	r2j_check( $r2j_journal['phase'] === 'table' &&
		(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM `' . $wpdb->posts .
			'` WHERE post_type<>%s', 'revision' ) ) === 8 && ! $wpdb->last_error,
		'Only the six baseline and two owned non-revision source posts may precede migration.' );
	$r2j_journal['phase'] = 'migration_pending'; $r2j_save( $r2j_journal_key, $r2j_journal, false );
	$r2j_pending = array( 'identity' => $r2j_identity, 'phase' => 'migration_pending' );
	$r2j_save( $r2j_marker_key, $r2j_pending, true );
	$engine = foogallery_migrate_migrator_instance();
	r2j_check( $engine->set_migrator_setting( WordPressCore::SETTING_MODE, WordPressCore::MODE_CREATE ),
		'Normal reusable Core mode could not be selected.' );
	$plugins = $engine->run_detection(); $core = null;
	foreach ( $plugins as $plugin ) { if ( $plugin->name() === 'WordPress Core' ) { $core = $plugin; } }
	r2j_check( $core && $core->is_detected && $core->get_migration_mode() === WordPressCore::MODE_CREATE,
		'Normal Core source detection/mode differs.' );
	$source_galleries = $core->find_galleries(); $source_ids = array();
	foreach ( $source_galleries as $gallery ) {
		r2j_check( isset( $gallery->ID ) && count( $gallery->get_children() ) === 1 &&
			(int) $gallery->get_children()[0]->migrated_id === (int) $r2j_journal['attachment'],
			'Core source gallery or image differs.' );
		$source_ids[] = (string) $gallery->ID;
	}
	r2j_check( count( $source_ids ) === 101 && count( array_unique( $source_ids ) ) === 101,
		'Identical source shortcodes must yield 101 distinct Core identities.' );
	$content = $engine->get_content_migrator();
	$progress = $content->scan_content_batch( true );
	for ( $batch = 0; empty( $progress['complete'] ) && $batch < 10; $batch++ ) {
		$progress = $content->scan_content_batch( false );
	}
	r2j_check( ! empty( $progress['complete'] ), 'Normal bounded content scan did not complete.' );
	$repository = $engine->content_scan_repository();
	r2j_check( $repository->is_table(), 'Content scan must use table authority.' );
	$items = array(); $revision = null;
	for ( $offset = 0; $offset < 101; $offset += 100 ) {
		$page = $repository->window( $offset, min( 100, 101 - $offset ) );
		r2j_check( $page['total'] === 101 && count( $page['items'] ) === min( 100, 101 - $offset ) &&
			( null === $revision || $page['revision'] === $revision ),
			'Pinned 101 content findings/window differ.' );
		$revision = $page['revision'];
		foreach ( $page['items'] as $key => $item ) { $items[$key] = $item; }
	}
	$expected_shortcode = $r2j_shortcode( $r2j_journal['attachment'] );
	$seen_ids = array(); $previous_offset = -1;
	foreach ( $items as $key => $item ) {
		r2j_check( is_int( $key ) && isset( $item['plugin_name'], $item['post_id'],
			$item['gallery_id'], $item['original_content'], $item['match_offset'] ) &&
			$item['plugin_name'] === 'WordPress Core' && (int) $item['post_id'] === (int) $r2j_journal['page'] &&
			$item['original_content'] === $expected_shortcode && (int) $item['match_offset'] > $previous_offset,
			'Core scan occurrence identity, source or order differs.' );
		$previous_offset = (int) $item['match_offset']; $seen_ids[] = (string) $item['gallery_id'];
	}
	r2j_check( $seen_ids === $source_ids && array_keys( $items ) === range( 0, 100 ),
		'Scanned 101 Core source identities differ from adapter order.' );
	$selection = array_merge( range( 0, 100 ), array( 0, 100 ) );
	$result = $content->migrate_and_replace_content( $selection );
	r2j_check( ! is_wp_error( $result ) && is_array( $result ) &&
		isset( $result['success'], $result['errors'] ) && $result['success'] === 101 &&
		$result['errors'] === array() &&
		StorageMaintenanceGate::current()->assert_writer() === true,
		'Public reusable-Core 101 action, duplicate selection or own writer failed.' );
	$output = get_post( (int) $r2j_journal['page'] ); $matches = array();
	r2j_check( $output && preg_match_all( '/\[foogallery\s+id="([0-9]+)"\]/', $output->post_content, $matches ) === 101,
		'Public migration did not replace all 101 source occurrences.' );
	$r2j_journal['source_ids'] = $source_ids;
	$r2j_journal['destinations'] = array_map( 'intval', $matches[1] );
	$r2j_journal['replaced_sha256'] = hash( 'sha256', $output->post_content );
	$r2j_destinations( $r2j_journal );
	$r2j_journal['phase'] = 'done';
	$r2j_journal['revisions'] = $r2j_revisions( $r2j_journal );
	$retry = $content->migrate_and_replace_content( $selection );
	r2j_check( is_array( $retry ) && isset( $retry['success'], $retry['errors'] ) &&
		$retry['success'] === 0 && hash( 'sha256', get_post( (int) $r2j_journal['page'] )->post_content ) ===
			$r2j_journal['replaced_sha256'] &&
		StorageMaintenanceGate::current()->assert_writer() === true,
		'Retry of replaced selection must not alter source or create output.' );
	$r2j_destinations( $r2j_journal );
	r2j_check( $r2j_journal['revisions'] === $r2j_revisions( $r2j_journal ),
		'Retry changed or added owned post revisions.' );
	$r2j_journal['result'] = $result;
	$r2j_journal['retry'] = $retry;
	$r2j_save( $r2j_journal_key, $r2j_journal, false );
	$r2j_clear_marker( $r2j_pending );
	echo wp_json_encode( array( 'phase' => 'done', 'selected' => 101, 'submitted' => count( $selection ),
		'success' => $result['success'], 'retry_success' => $retry['success'],
		'destination_sha256' => hash( 'sha256', implode( ',', $r2j_journal['destinations'] ) ),
		'memory_limit' => ini_get( 'memory_limit' ), 'peak_bytes' => memory_get_peak_usage( true ) ) ) . "\n";
	return;
}
r2j_check( $r2j_phase === 'verify' && $r2j_journal['phase'] === 'done' &&
	count( $r2j_owned_posts() ) === 2 &&
	$r2j_journal['result']['success'] === 101 && $r2j_journal['retry']['success'] === 0,
	'Fresh completed 101-result journal required.' );
$writer = $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM `' . $wpdb->options .
	'` WHERE option_name LIKE %s LIMIT 1', $wpdb->esc_like( StorageMaintenanceGate::WRITER_PREFIX ) . '%' ) );
r2j_check( ! $wpdb->last_error && null === $writer &&
	(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM `' . $wpdb->posts .
		'` WHERE post_type<>%s', 'revision' ) ) === 109 && ! $wpdb->last_error,
	'Fresh request has active writer or unexpected non-revision output inventory.' );
$ordered = $r2j_destinations( $r2j_journal );
r2j_check( $r2j_baseline() === $r2j_journal['baseline'] &&
	$r2j_raw( 'fgm_cleanup_owned_journey_v1' ) === $r2j_cleanup_raw &&
	is_file( $r2j_media ) && hash_file( 'sha256', $r2j_media ) === $r2j_cleanup['media_hash'] &&
	StorageAdmin::status()['backend'] === 'table' && StorageAdmin::status()['phase'] === 'stable',
	'Fresh protected baseline, media or stable authority differs.' );
echo wp_json_encode( array( 'phase' => 'verified', 'source_page' => $r2j_journal['page'],
	'attachment' => $r2j_journal['attachment'], 'destinations' => count( $ordered ),
	'destination_sha256' => hash( 'sha256', implode( ',', $ordered ) ),
	'content_sha256' => $r2j_journal['replaced_sha256'],
	'candidate_sha256' => $r2j_candidate_sha, 'memory_limit' => ini_get( 'memory_limit' ),
	'peak_bytes' => memory_get_peak_usage( true ) ) ) . "\n";
