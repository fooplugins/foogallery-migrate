<?php
/**
 * Owned PHP 5.4 / WordPress 5.0 actual AIGPL source fixture.
 *
 * One seed batch per fresh WP-CLI process. This file does not convert storage
 * or run the migration engine. A collision is a refusal, not a repair.
 */
use FooPlugins\FooGalleryMigrate\StorageAdmin;
use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use FooPlugins\FooGalleryMigrate\Plugins\Aigpl;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'ABSPATH' ) ||
	! is_file( '/fixture-owner.json' ) ) {
	throw new RuntimeException( 'Owned floor WP-CLI fixture required.' );
}
ini_set( 'memory_limit', '256M' );
if ( ini_get( 'memory_limit' ) !== '256M' ) {
	throw new RuntimeException( 'The source fixture requires an explicit 256M request limit.' );
}
if ( ! isset( $args[0] ) || ! in_array( $args[0], array( 'seed', 'verify-source', 'status' ), true ) || count( $args ) !== 1 ) {
	throw new RuntimeException( 'Exactly one source-fixture step is required.' );
}
$step = $args[0];
$manifest = json_decode( file_get_contents( '/fixture-owner.json' ), true );
$repo = realpath( dirname( dirname( __DIR__ ) ) );
if ( ! is_array( $manifest ) || ! isset( $manifest['kind'], $manifest['fixture'], $manifest['site'],
	$manifest['repo'], $manifest['network'], $manifest['container'], $manifest['container_id'],
	$manifest['database'] ) || $manifest['kind'] !== 'foogallery-migrate-floor-v1' ||
	! preg_match( '~^/(?:private/)?tmp/foogallery-migrate-floor\.[A-Za-z0-9]+$~D', $manifest['fixture'] ) ||
	$manifest['site'] !== $manifest['fixture'] . '/site' ||
	realpath( ABSPATH ) !== '/site' || $repo !== '/repo' ||
	! is_string( $manifest['repo'] ) || $manifest['repo'] === '' ||
	realpath( ABSPATH . 'wp-content/plugins/foogallery-migrate' ) !== $repo ||
	! preg_match( '/^fgm-floor-[A-Za-z0-9]+$/D', $manifest['network'] ) ||
	$manifest['container'] !== $manifest['network'] . '-db' ||
	! preg_match( '/^[a-f0-9]{64}$/D', $manifest['container_id'] ) ||
	! preg_match( '/^fgm_floor_[a-z0-9]+$/D', $manifest['database'] ) ||
	DB_NAME !== $manifest['database'] || DB_HOST !== $manifest['container'] ||
	PHP_VERSION !== '5.4.45' || $GLOBALS['wp_version'] !== '5.0' || is_multisite() ) {
	throw new RuntimeException( 'Floor runtime or fixture ownership mismatch.' );
}
global $wpdb;
function floor_real_check( $okay, $message ) {
	if ( ! $okay ) { throw new RuntimeException( $message ); }
}
floor_real_check( $wpdb->get_var( 'SELECT DATABASE()' ) === $manifest['database'] && ! $wpdb->last_error,
	'Live SQL database differs from owned manifest.' );
floor_real_check( function_exists( 'foogallery_migrate_migrator_instance' ) &&
	function_exists( 'foogallery_default_gallery_template' ) && defined( 'FOOGALLERY_VERSION' ) &&
	get_current_user_id() > 0 && wp_get_current_user()->user_login === 'fgmflooradmin' &&
	current_user_can( 'manage_options' ), 'Actual plugins or owned administrator are unavailable.' );

$started = microtime( true );
$suffix = substr( $manifest['fixture'], strrpos( $manifest['fixture'], '.' ) + 1 );
$owner = 'fgm-floor-real-' . $suffix;
$owner_key = '_fgm_floor_real_fixture_owner';
$journal_key = 'fgm_floor_real_owned_journey_v1';
$identity = hash( 'sha256', $manifest['fixture'] . '|' . $manifest['database'] . '|' . $manifest['container_id'] );
$total = 10000;
$batch = 256;
$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=' );
$png_hash = hash( 'sha256', $png );
$upload = wp_upload_dir();
floor_real_check( empty( $upload['error'] ) && isset( $upload['basedir'], $upload['baseurl'] ) &&
	is_dir( $upload['basedir'] ), 'Owned uploads are unavailable.' );
$media_dir = $upload['basedir'] . '/' . $owner;
$journal = get_option( $journal_key, null );
$save = function ( $value ) use ( $journal_key ) {
	floor_real_check( update_option( $journal_key, $value, false ) &&
		get_option( $journal_key, null ) === $value, 'Owned seed journal write/readback failed.' );
};
$owned_count = function ( $post_type ) use ( $wpdb, $owner_key, $owner ) {
	$count = $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM `{$wpdb->posts}` p INNER JOIN `{$wpdb->postmeta}` m ON m.post_id=p.ID WHERE p.post_type=%s AND m.meta_key=%s AND m.meta_value=%s",
		$post_type, $owner_key, $owner
	) );
	floor_real_check( ! $wpdb->last_error, 'Owned post count failed.' );
	return (int) $count;
};
$raw_option = function ( $name ) use ( $wpdb ) {
	$value = $wpdb->get_var( $wpdb->prepare(
		"SELECT option_value FROM `{$wpdb->options}` WHERE option_name=%s", $name
	) );
	floor_real_check( ! $wpdb->last_error, 'Raw storage option query failed.' );
	return $value;
};
$assert_pristine = function () use ( $raw_option, $wpdb ) {
	foreach ( array( FOOGALLERY_MIGRATE_OPTION_DATA, StorageAuthority::CONTROL_OPTION,
		StorageAuthority::AUTHORITY_OPTION, StorageAuthority::MAINTENANCE_OPTION,
		StorageAuthority::STORAGE_BACKUP_OPTION, LegacyStorageSchema::VERSION_OPTION,
		LegacyStorageSchema::LOCK_OPTION ) as $name ) {
		floor_real_check( null === $raw_option( $name ), 'Source fixture refuses existing migration storage or marker.' );
	}
	$schema = new LegacyStorageSchema( $wpdb );
	foreach ( array_keys( $schema->definitions() ) as $table ) {
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		floor_real_check( ! $wpdb->last_error && null === $found, 'Source fixture refuses existing owned storage table.' );
	}
	$storage = StorageAdmin::status();
	floor_real_check( $storage['backend'] === 'option' && $storage['phase'] === 'stable' &&
		$storage['problem'] === '', 'Source fixture requires clean original option authority.' );
};
$verify_source = function ( $state, $full ) use ( $total, $owner, $owner_key, $media_dir,
	$png_hash, $owned_count ) {
	floor_real_check( $state['phase'] === 'ready' && isset( $state['ids_sha256'], $state['source'],
		$state['content'], $state['content_sha256'] ) && count( $state['ids'] ) === $total &&
		count( array_unique( $state['ids'] ) ) === $total &&
		hash( 'sha256', implode( ',', $state['ids'] ) ) === $state['ids_sha256'],
		'Ready source or ordered attachment ID manifest differs.' );
	floor_real_check( is_dir( $media_dir ) && ! is_link( $media_dir ) &&
		$owned_count( 'attachment' ) === $total && $owned_count( Aigpl::POST_TYPE ) === 1 &&
		$owned_count( 'page' ) === 1, 'Owned post counts differ.' );
	$source = get_post( $state['source'] );
	$content = get_post( $state['content'] );
	$expected_content = 'Original AIGPL source: [aigpl-gallery id="' . $state['source'] . '"]';
	floor_real_check( $source && $source->post_type === Aigpl::POST_TYPE &&
		$source->post_status === 'publish' &&
		get_post_meta( $source->ID, $owner_key, true ) === $owner &&
		get_post_meta( $source->ID, Aigpl::META_GALLERY_IMAGES, true ) === $state['ids'],
		'Original AIGPL gallery or ordered image metadata differs.' );
	floor_real_check( $content && $content->post_type === 'page' &&
		$content->post_status === 'publish' &&
		get_post_meta( $content->ID, $owner_key, true ) === $owner &&
		$content->post_content === $expected_content &&
		hash( 'sha256', $content->post_content ) === $state['content_sha256'],
		'Protected shortcode content differs.' );
	if ( $full ) {
		foreach ( $state['ids'] as $index => $id ) {
			$relative = $owner . '/' . $index . '.png';
			$file = $media_dir . '/' . $index . '.png';
			$post = get_post( $id );
			$meta = wp_get_attachment_metadata( $id );
			floor_real_check( $post && $post->post_type === 'attachment' &&
				$post->post_status === 'inherit' && $post->post_mime_type === 'image/png' &&
				get_post_meta( $id, $owner_key, true ) === $owner &&
				get_post_meta( $id, '_wp_attached_file', true ) === $relative &&
				is_array( $meta ) && isset( $meta['file'], $meta['width'], $meta['height'] ) &&
				$meta['file'] === $relative && (int) $meta['width'] === 1 &&
				(int) $meta['height'] === 1 && is_file( $file ) && ! is_link( $file ) &&
				hash_file( 'sha256', $file ) === $png_hash,
				'Owned attachment metadata or file differs at index ' . $index . '.' );
		}
		$files = glob( $media_dir . '/*.png' );
		floor_real_check( is_array( $files ) && count( $files ) === $total,
			'Owned media file count differs.' );
	}
	return array( 'attachments' => $total, 'ordered_ids_sha256' => $state['ids_sha256'],
		'source' => (int) $state['source'], 'content' => (int) $state['content'],
		'content_sha256' => $state['content_sha256'], 'png_sha256' => $png_hash,
		'files_checked' => $full ? $total : 0 );
};

$assert_pristine();
if ( $step === 'seed' && $journal === null ) {
	floor_real_check( $owned_count( 'attachment' ) === 0 &&
		$owned_count( Aigpl::POST_TYPE ) === 0 && $owned_count( 'page' ) === 0 &&
		! file_exists( $media_dir ), 'Owned source exists without its journal.' );
	$journal = array( 'identity' => $identity, 'owner' => $owner,
		'phase' => 'seeding', 'ids' => array() );
	floor_real_check( add_option( $journal_key, $journal, '', false ),
		'Owned source journal could not be created.' );
}
floor_real_check( is_array( $journal ) && isset( $journal['identity'], $journal['owner'],
	$journal['phase'], $journal['ids'] ) && $journal['identity'] === $identity &&
	$journal['owner'] === $owner && is_array( $journal['ids'] ) &&
	count( $journal['ids'] ) <= $total, 'Owned source journal missing or mismatched.' );

if ( $step === 'seed' ) {
	floor_real_check( $journal['phase'] === 'seeding', 'Ready source refuses reseed.' );
	floor_real_check( ! is_link( $media_dir ) &&
		( is_dir( $media_dir ) || wp_mkdir_p( $media_dir ) ), 'Owned media directory unavailable.' );
	$existing_files = glob( $media_dir . '/*.png' );
	floor_real_check( is_array( $existing_files ) &&
		count( $existing_files ) === count( $journal['ids'] ) &&
		$owned_count( 'attachment' ) === count( $journal['ids'] ),
		'Owned seed journal is behind existing posts or files.' );
	$end = min( $total, count( $journal['ids'] ) + $batch );
	for ( $index = count( $journal['ids'] ); $index < $end; ++$index ) {
		$file = $media_dir . '/' . $index . '.png';
		floor_real_check( ! file_exists( $file ) && ! is_link( $file ) &&
			file_put_contents( $file, $png, LOCK_EX ) === strlen( $png ),
			'Owned media collision or write failed.' );
		$relative = $owner . '/' . $index . '.png';
		$id = wp_insert_post( array( 'post_type' => 'attachment', 'post_status' => 'inherit',
			'post_mime_type' => 'image/png', 'post_title' => $owner . ' ' . $index,
			'guid' => $upload['baseurl'] . '/' . $relative,
			'meta_input' => array( $owner_key => $owner ) ), true );
		floor_real_check( ! is_wp_error( $id ) && $id > 0,
			'Owned attachment insertion failed.' );
		floor_real_check( update_post_meta( $id, '_wp_attached_file', $relative ) !== false &&
			update_post_meta( $id, '_wp_attachment_metadata', array( 'width' => 1,
				'height' => 1, 'file' => $relative, 'sizes' => array() ) ) !== false,
			'Owned attachment metadata failed.' );
		$journal['ids'][] = (int) $id;
		$save( $journal );
	}
	if ( count( $journal['ids'] ) === $total ) {
		floor_real_check( $owned_count( Aigpl::POST_TYPE ) === 0 &&
			$owned_count( 'page' ) === 0, 'Owned source/content collision.' );
		register_post_type( Aigpl::POST_TYPE, array( 'public' => false ) );
		$source = wp_insert_post( array( 'post_type' => Aigpl::POST_TYPE,
			'post_status' => 'publish', 'post_title' => $owner,
			'meta_input' => array( $owner_key => $owner ) ), true );
		floor_real_check( ! is_wp_error( $source ) && $source > 0,
			'Owned AIGPL source insertion failed.' );
		floor_real_check( update_post_meta( $source, Aigpl::META_GALLERY_IMAGES,
			$journal['ids'] ) !== false, 'Original AIGPL metadata failed.' );
		$content = 'Original AIGPL source: [aigpl-gallery id="' . $source . '"]';
		$page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish',
			'post_title' => $owner . ' content', 'post_content' => $content,
			'meta_input' => array( $owner_key => $owner ) ), true );
		floor_real_check( ! is_wp_error( $page ) && $page > 0,
			'Owned shortcode content insertion failed.' );
		$journal['source'] = (int) $source;
		$journal['content'] = (int) $page;
		$journal['content_sha256'] = hash( 'sha256', $content );
		$journal['ids_sha256'] = hash( 'sha256', implode( ',', $journal['ids'] ) );
		$journal['phase'] = 'ready';
		$save( $journal );
	}
}
$result = array( 'step' => $step, 'fixture' => $manifest['fixture'],
	'database' => $manifest['database'], 'container_id' => $manifest['container_id'],
	'php' => PHP_VERSION, 'wordpress' => $GLOBALS['wp_version'], 'owner' => $owner,
	'memory_limit' => ini_get( 'memory_limit' ),
	'phase' => $journal['phase'], 'seeded' => count( $journal['ids'] ),
	'elapsed_seconds' => round( microtime( true ) - $started, 3 ),
	'peak_allocated_bytes' => memory_get_peak_usage( true ) );
if ( $step === 'verify-source' ) {
	$result['source'] = $verify_source( $journal, true );
	$result['elapsed_seconds'] = round( microtime( true ) - $started, 3 );
	$result['peak_allocated_bytes'] = memory_get_peak_usage( true );
} elseif ( $step === 'status' && $journal['phase'] === 'ready' ) {
	$result['source'] = $verify_source( $journal, false );
}
$result['storage'] = StorageAdmin::status();
echo json_encode( $result, JSON_UNESCAPED_SLASHES ) . "\n";
