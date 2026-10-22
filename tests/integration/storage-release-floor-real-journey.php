<?php
/**
 * Owned PHP 5.4 / WordPress 5.0 actual AIGPL source fixture.
 *
 * One guarded source, public storage, or normal migration step per fresh WP-CLI
 * process. A seed collision is a refusal, not a repair.
 */
use FooPlugins\FooGalleryMigrate\StorageAdmin;
use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\StorageCli;
use FooPlugins\FooGalleryMigrate\StorageControl;
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use FooPlugins\FooGalleryMigrate\Plugins\Aigpl;
use FooPlugins\FooGalleryMigrate\Objects\Migratable;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'ABSPATH' ) ||
	! is_file( '/fixture-owner.json' ) ) {
	throw new RuntimeException( 'Owned floor WP-CLI fixture required.' );
}
ini_set( 'memory_limit', '256M' );
if ( ini_get( 'memory_limit' ) !== '256M' ) {
	throw new RuntimeException( 'The source fixture requires an explicit 256M request limit.' );
}
if ( ! isset( $args[0] ) || ! in_array( $args[0], array( 'seed', 'verify-source',
	'verify-destination', 'verify-reversed', 'status', 'preflight', 'provision', 'switch', 'recover',
	'turn', 'settings100', 'edit', 'reverse' ), true ) ||
	! ( count( $args ) === 1 ||
		( count( $args ) === 2 && $args[0] === 'seed' && $args[1] === '1000' ) ) ) {
	throw new RuntimeException( 'Exactly one owned floor journey step is required.' );
}
$step = $args[0];
$requested_total = count( $args ) === 2 ? 1000 : null;
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
$batch = 256;
$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=' );
$png_hash = hash( 'sha256', $png );
$upload = wp_upload_dir();
floor_real_check( empty( $upload['error'] ) && isset( $upload['basedir'], $upload['baseurl'] ) &&
	is_dir( $upload['basedir'] ), 'Owned uploads are unavailable.' );
$media_dir = $upload['basedir'] . '/' . $owner;
$journal = get_option( $journal_key, null );
if ( $journal === null ) {
	$total = $requested_total === null ? 10000 : $requested_total;
} else {
	$total = is_array( $journal ) && isset( $journal['total'] ) ? $journal['total'] : 10000;
	floor_real_check( is_int( $total ) && in_array( $total, array( 1000, 10000 ), true ) &&
		$requested_total === null, 'Existing owned journal fixes size; requested size change refused.' );
}
$save = function ( $value ) use ( $journal_key, $wpdb ) {
	// WordPress returns false for an unchanged value on a later recovery turn.
	// Accept that case only after durable raw SQL and Options API readback agree.
	update_option( $journal_key, $value, false );
	$raw = $wpdb->get_var( $wpdb->prepare(
		"SELECT option_value FROM `{$wpdb->options}` WHERE option_name=%s", $journal_key
	) );
	floor_real_check( ! $wpdb->last_error && is_string( $raw ) &&
		maybe_unserialize( $raw ) === $value && get_option( $journal_key, null ) === $value,
		'Owned seed journal write/readback failed.' );
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
	floor_real_check( in_array( $state['phase'], array( 'ready', 'migrating', 'complete',
		'edited', 'reversing', 'reversed' ), true ) &&
		isset( $state['ids_sha256'], $state['source'],
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
$verify_destination = function ( $state ) use ( $total ) {
	floor_real_check( in_array( $state['phase'], array( 'complete', 'edited', 'reversing',
		'reversed' ), true ) && isset( $state['uid'], $state['destination'], $state['completed'] ) &&
		(int) $state['completed'] === $total, 'Completed owned migration required for destination proof.' );
	$destination = get_post( $state['destination'] );
	floor_real_check( $destination && $destination->post_type === FOOGALLERY_CPT_GALLERY &&
		get_post_meta( $destination->ID, FOOGALLERY_META_ATTACHMENTS, true ) === $state['ids'],
		'Actual destination gallery or exact attachment order differs.' );
	$matches = get_posts( array( 'post_type' => FOOGALLERY_CPT_GALLERY,
		'post_status' => 'any', 'posts_per_page' => 2, 'fields' => 'ids',
		'meta_key' => Migratable::META_SOURCE_KEY, 'meta_value' => $state['uid'] ) );
	floor_real_check( count( $matches ) === 1 && (int) $matches[0] === (int) $destination->ID,
		'Actual destination source identity or uniqueness differs.' );
	$output = do_shortcode( '[foogallery id="' . $destination->ID . '"]' );
	floor_real_check( is_string( $output ) && strpos( $output, 'foogallery' ) !== false,
		'Actual FooGallery output failed.' );
	return array( 'post' => (int) $destination->ID, 'attachments' => $total,
		'ordered_ids_sha256' => hash( 'sha256', implode( ',', $state['ids'] ) ),
		'output_bytes' => strlen( $output ) );
};

if ( $step === 'seed' || $step === 'preflight' || $step === 'provision' ) {
	$assert_pristine();
}
if ( $step === 'seed' && $journal === null ) {
	floor_real_check( $owned_count( 'attachment' ) === 0 &&
		$owned_count( Aigpl::POST_TYPE ) === 0 && $owned_count( 'page' ) === 0 &&
		! file_exists( $media_dir ), 'Owned source exists without its journal.' );
	$journal = array( 'identity' => $identity, 'owner' => $owner,
		'total' => $total, 'phase' => 'seeding', 'ids' => array() );
	floor_real_check( add_option( $journal_key, $journal, '', false ),
		'Owned source journal could not be created.' );
}
floor_real_check( is_array( $journal ) && isset( $journal['identity'], $journal['owner'],
	$journal['phase'], $journal['ids'] ) && $journal['identity'] === $identity &&
	$journal['owner'] === $owner && is_array( $journal['ids'] ) &&
	count( $journal['ids'] ) <= $total &&
	( ! isset( $journal['total'] ) || $journal['total'] === $total ),
	'Owned source journal missing or mismatched.' );

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
	'fixture_total' => $total, 'phase' => $journal['phase'],
	'seeded' => count( $journal['ids'] ),
	'turns' => isset( $journal['turns'] ) ? (int) $journal['turns'] : 0,
	'completed' => isset( $journal['completed'] ) ? (int) $journal['completed'] : 0,
	'destination' => isset( $journal['destination'] ) ? (int) $journal['destination'] : null,
	'elapsed_seconds' => round( microtime( true ) - $started, 3 ),
	'peak_allocated_bytes' => memory_get_peak_usage( true ) );
if ( $step === 'verify-source' ) {
	$result['source'] = $verify_source( $journal, true );
	$result['elapsed_seconds'] = round( microtime( true ) - $started, 3 );
	$result['peak_allocated_bytes'] = memory_get_peak_usage( true );
} elseif ( $step === 'verify-destination' ) {
	$result['source'] = $verify_source( $journal, false );
	$result['destination_proof'] = $verify_destination( $journal );
} elseif ( $step === 'verify-reversed' ) {
	$storage = StorageAdmin::status();
	floor_real_check( $journal['phase'] === 'reversed' && isset( $journal['edit'] ) &&
		$storage['backend'] === 'option' && $storage['phase'] === 'stable' &&
		$storage['schema'] === 'verified' && $storage['problem'] === '' &&
		! empty( $storage['retained'] ),
		'Stable retained current-option reversal required for proof.' );
	$result['source'] = $verify_source( $journal, true );
	$result['destination_proof'] = $verify_destination( $journal );
	$current = $raw_option( FOOGALLERY_MIGRATE_OPTION_DATA );
	$backup = $raw_option( StorageAuthority::STORAGE_BACKUP_OPTION );
	floor_real_check( is_string( $current ) && is_string( $backup ) &&
		$current !== $backup, 'CURRENT option must differ from retained old backup.' );
	$decoded = maybe_unserialize( $current );
	floor_real_check( is_array( $decoded ) && array_key_exists( $owner, $decoded ) &&
		$decoded[$owner] === $journal['edit'],
		'Raw CURRENT option lost the exact typed table edit.' );
	unset( $decoded );
	$control_raw = $raw_option( StorageAuthority::CONTROL_OPTION );
	$control = is_string( $control_raw ) ?
		StorageControl::decode( $control_raw, get_current_blog_id() ) : null;
	floor_real_check( is_array( $control ) && $control['backend'] === 'option' &&
		$control['phase'] === 'stable' && StorageControl::runtime_ready( $control,
			$raw_option( StorageAuthority::AUTHORITY_OPTION ) ),
		'Stable current-option authority differs.' );
	$result['current_option_bytes'] = strlen( $current );
	$result['current_option_sha256'] = hash( 'sha256', $current );
	$result['edit_sha256'] = hash( 'sha256', serialize( $journal['edit'] ) );
} elseif ( $step === 'status' && $journal['phase'] !== 'seeding' ) {
	$result['source'] = $verify_source( $journal, false );
}
if ( in_array( $step, array( 'preflight', 'provision', 'switch', 'recover' ), true ) ) {
	floor_real_check( $journal['phase'] === 'ready' && ! isset( $journal['uid'] ) &&
		! isset( $journal['turns'] ) && ! isset( $journal['completed'] ),
		'Public storage enablement requires unstarted actual source.' );
	$result['source'] = $verify_source( $journal, false );
	$before = StorageAdmin::status();
	$control_raw = $raw_option( StorageAuthority::CONTROL_OPTION );
	$control = $control_raw === null ? null : StorageControl::decode( $control_raw, get_current_blog_id() );
	if ( $step === 'switch' ) {
		floor_real_check( $control === null && $before['backend'] === 'option' &&
			$before['phase'] === 'stable' && $before['schema'] === 'verified' &&
			$raw_option( FOOGALLERY_MIGRATE_OPTION_DATA ) === null &&
			$raw_option( StorageAuthority::STORAGE_BACKUP_OPTION ) === null &&
			$raw_option( StorageAuthority::MAINTENANCE_OPTION ) === null,
			'Switch requires provisioned empty option authority.' );
	} elseif ( $step === 'recover' ) {
		floor_real_check( is_array( $control ) && $control['phase'] !== 'stable' &&
			$control['from'] === 'option' && $control['to'] === 'table' &&
			$before['schema'] === 'verified',
			'Recover requires recorded in-progress forward conversion.' );
	} else {
		floor_real_check( $control === null && $before['backend'] === 'option' &&
			$before['phase'] === 'stable' && $before['problem'] === '',
			'Preflight/provision requires untouched option authority.' );
	}
	if ( $step === 'switch' || $step === 'recover' ) {
		( new LegacyStorageSchema( $wpdb ) )->verify();
	}
	$confirm = $step === 'recover' ? 'recover-storage' :
		( $step === 'provision' ? 'provision-shared-schema' : 'switch-to-table' );
	$operation_started = microtime( true );
	$operation = StorageCli::execute( $step, array( 'target' => 'table', 'confirm' => $confirm ) );
	$result['operation_seconds'] = round( microtime( true ) - $operation_started, 3 );
	$result['storage'] = StorageAdmin::status();
	$result['source_after'] = $verify_source( $journal, false );
	$control_raw = $raw_option( StorageAuthority::CONTROL_OPTION );
	$control = $control_raw === null ? null : StorageControl::decode( $control_raw, get_current_blog_id() );
	$result['control'] = is_array( $control ) ? array( 'phase' => $control['phase'],
		'backend' => $control['backend'], 'from' => $control['from'], 'to' => $control['to'] ) : null;
	if ( is_wp_error( $operation ) ) {
		$result['failure'] = array( 'code' => $operation->get_error_code(),
			'message' => $operation->get_error_message() );
	} else {
		$result['storage_result'] = $operation;
		floor_real_check( $result['storage']['problem'] === '' &&
			( $step !== 'provision' || ( $result['storage']['backend'] === 'option' &&
				$result['storage']['phase'] === 'stable' && $result['storage']['schema'] === 'verified' &&
				$control === null ) ) &&
			( ( $step !== 'switch' && $step !== 'recover' ) ||
				( is_array( $control ) && $control['from'] === 'option' && $control['to'] === 'table' ) ),
			'Public storage result or control differs from expected forward path.' );
	}
}
if ( $step === 'turn' || $step === 'settings100' ) {
	$storage = StorageAdmin::status();
	$control_raw = $raw_option( StorageAuthority::CONTROL_OPTION );
	$control = $control_raw === null ? null : StorageControl::decode( $control_raw, get_current_blog_id() );
	floor_real_check( $storage['backend'] === 'table' && $storage['phase'] === 'stable' &&
		$storage['schema'] === 'verified' && $storage['problem'] === '' &&
		is_array( $control ) && $control['backend'] === 'table' &&
		$control['phase'] === 'stable' &&
		StorageControl::runtime_ready( $control, $raw_option( StorageAuthority::AUTHORITY_OPTION ) ) &&
		$raw_option( FOOGALLERY_MIGRATE_OPTION_DATA ) === null,
		'Normal migration requires stable verified table authority.' );
	$result['source'] = $verify_source( $journal, false );
	$engine = foogallery_migrate_migrator_instance();
	$limit = $engine->get_images_per_turn();
	floor_real_check( is_numeric( $limit ) && (int) $limit > 0,
		'Normal images-per-turn setting is invalid.' );
	$result['images_per_turn'] = (int) $limit;
	$operation_started = microtime( true );
	if ( $step === 'settings100' ) {
		floor_real_check( $journal['phase'] === 'migrating' &&
			isset( $journal['turns'], $journal['completed'] ) &&
			(int) $journal['turns'] === 5 && (int) $journal['completed'] === 25 &&
			! isset( $journal['images_per_turn'] ) && (int) $limit === 5,
			'Exactly five successful default-five turns required before setting 100.' );
		$settings = new MigratorSettings();
		$settings->save_settings( array( MigratorSettings::SETTING_IMAGES_PER_TURN => 100 ) );
		floor_real_check( $settings->get_images_per_turn() === 100 &&
			$engine->get_images_per_turn() === 100,
			'Normal images-per-turn setting did not persist.' );
		$journal['images_per_turn'] = 100;
		$save( $journal );
		$result['images_per_turn'] = 100;
	} else {
		floor_real_check( $journal['phase'] === 'ready' || $journal['phase'] === 'migrating',
			'No pending normal migration turn.' );
		$turns = isset( $journal['turns'] ) ? (int) $journal['turns'] : 0;
		$completed_before = isset( $journal['completed'] ) ? (int) $journal['completed'] : 0;
		floor_real_check( ( $turns < 5 && ! isset( $journal['images_per_turn'] ) && (int) $limit === 5 ) ||
			( $turns >= 5 && isset( $journal['images_per_turn'] ) &&
				(int) $journal['images_per_turn'] === 100 && (int) $limit === 100 ),
			'Owned turn sequence or ordinary images-per-turn setting changed.' );
		$migrator = $engine->get_gallery_migrator();
		if ( $journal['phase'] === 'ready' ) {
			$engine->run_detection();
			$objects = $migrator->get_objects_to_migrate( true );
			floor_real_check( count( $objects ) === 1 &&
				(int) $objects[0]->children_count === $total,
				'Actual AIGPL detection did not find one owned-size gallery.' );
			$journal['uid'] = $objects[0]->unique_identifier();
			floor_real_check( $migrator->queue_objects_for_migration(
				array( $journal['uid'] => array( 'title' => $owner ) ) ),
				'Normal gallery queue refused.' );
			$journal['phase'] = 'migrating';
			$journal['turns'] = 0;
			$journal['completed'] = 0;
			$save( $journal );
		}
		floor_real_check( $migrator->migrate(), 'Normal migration turn failed.' );
		$objects = $migrator->get_objects_to_migrate();
		floor_real_check( count( $objects ) === 1 &&
			$journal['uid'] === $objects[0]->unique_identifier(),
			'Actual migration object changed.' );
		$object = $objects[0];
		$completed = (int) $object->migrated_child_count;
		floor_real_check( $completed > $completed_before &&
			$completed <= min( $total, $completed_before + (int) $limit ) &&
			(int) $engine->get_images_per_turn() === (int) $limit,
			'Normal turn progress or images-per-turn changed.' );
		$journal['turns']++;
		$journal['completed'] = $completed;
		$journal['destination'] = (int) $object->migrated_id;
		$result['migration_status'] = $object->migration_status;
		if ( $object->migrated ) {
			floor_real_check( $completed === $total &&
				$journal['ids'] === get_post_meta( $object->migrated_id,
					FOOGALLERY_META_ATTACHMENTS, true ),
				'Destination attachment IDs/order differ.' );
			$matches = get_posts( array( 'post_type' => FOOGALLERY_CPT_GALLERY,
				'post_status' => 'any', 'posts_per_page' => 2, 'fields' => 'ids',
				'meta_key' => Migratable::META_SOURCE_KEY,
				'meta_value' => $journal['uid'] ) );
			floor_real_check( count( $matches ) === 1 &&
				(int) $matches[0] === (int) $object->migrated_id,
				'Actual destination ownership or uniqueness differs.' );
			floor_real_check( strpos( do_shortcode( '[foogallery id="' .
				$object->migrated_id . '"]' ), 'foogallery' ) !== false,
				'Actual FooGallery output failed.' );
			$journal['phase'] = 'complete';
		}
		$save( $journal );
		$result['phase'] = $journal['phase'];
		$result['turns'] = $journal['turns'];
		$result['completed'] = $journal['completed'];
		$result['destination'] = $journal['destination'];
		$result['source_after'] = $verify_source( $journal, false );
	}
	$result['operation_seconds'] = round( microtime( true ) - $operation_started, 3 );
}
if ( $step === 'edit' ) {
	$storage = StorageAdmin::status();
	floor_real_check( $journal['phase'] === 'complete' && isset( $journal['completed'] ) &&
		(int) $journal['completed'] === $total && $storage['backend'] === 'table' &&
		$storage['phase'] === 'stable' && $storage['schema'] === 'verified' &&
		$storage['problem'] === '', 'Completed stable table migration required for edit.' );
	$result['source'] = $verify_source( $journal, false );
	$result['destination_proof'] = $verify_destination( $journal );
	$engine = foogallery_migrate_migrator_instance();
	$edit = array( 'current' => true, '09' => null, 9 => false, 'bytes' => "x\0" );
	$operation_started = microtime( true );
	floor_real_check( $engine->set_migrator_setting( $owner, $edit ) &&
		$engine->get_migrator_setting( $owner ) === $edit,
		'Normal table edit/readback failed.' );
	$journal['edit'] = $edit;
	$journal['phase'] = 'edited';
	$save( $journal );
	$result['phase'] = $journal['phase'];
	$result['edit_sha256'] = hash( 'sha256', serialize( $edit ) );
	$result['operation_seconds'] = round( microtime( true ) - $operation_started, 3 );
}
if ( $step === 'reverse' ) {
	floor_real_check( in_array( $journal['phase'], array( 'edited', 'reversing' ), true ) &&
		isset( $journal['edit'], $journal['uid'], $journal['destination'], $journal['completed'] ) &&
		(int) $journal['completed'] === $total,
		'Only completed, edited owned migration may reverse.' );
	$result['source'] = $verify_source( $journal, false );
	$result['destination_proof'] = $verify_destination( $journal );
	$before = StorageAdmin::status();
	floor_real_check( $before['schema'] === 'verified' && $before['problem'] === '' &&
		( ( $before['phase'] === 'stable' &&
			in_array( $before['backend'], array( 'table', 'option' ), true ) ) ||
			( $before['phase'] !== 'stable' && $before['target'] === 'option' &&
				in_array( $before['backend'], array( 'table', 'option' ), true ) ) ),
		'Owned verified table-to-option transition required.' );
	if ( $before['phase'] === 'stable' ) {
		floor_real_check( $journal['edit'] ===
			foogallery_migrate_migrator_instance()->get_migrator_setting( $owner ),
			'Current edited setting differs before reversal.' );
	}
	$tables = array_keys( ( new LegacyStorageSchema( $wpdb ) )->definitions() );
	$heads = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS n, COALESCE(SUM(payload_bytes),0) AS bytes FROM `' .
		$tables[0] . '` WHERE blog_id=%d', get_current_blog_id() ), ARRAY_A );
	$rows = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS n, COALESCE(SUM(payload_bytes),0) AS bytes, COALESCE(MAX(payload_bytes),0) AS largest FROM `' .
		$tables[1] . '` WHERE blog_id=%d', get_current_blog_id() ), ARRAY_A );
	floor_real_check( ! $wpdb->last_error && is_array( $heads ) && is_array( $rows ),
		'Current table dimensions unavailable.' );
	$result['table_dimensions'] = array( 'heads' => (int) $heads['n'],
		'head_payload_bytes' => (int) $heads['bytes'], 'rows' => (int) $rows['n'],
		'row_payload_bytes' => (int) $rows['bytes'],
		'largest_row_bytes' => (int) $rows['largest'] );
	$operation = $before['phase'] === 'stable' ?
		( $before['backend'] === 'table' ? 'switch' : null ) : 'recover';
	$operation_started = microtime( true );
	$storage_result = $operation === null ? null : StorageCli::execute( $operation,
		array( 'target' => 'option', 'confirm' => $operation === 'switch' ?
			'switch-to-option' : 'recover-storage' ) );
	$result['operation_seconds'] = round( microtime( true ) - $operation_started, 3 );
	$result['storage'] = StorageAdmin::status();
	if ( is_wp_error( $storage_result ) ) {
		$result['failure'] = array( 'code' => $storage_result->get_error_code(),
			'message' => $storage_result->get_error_message() );
	} else {
		if ( $storage_result !== null ) { $result['storage_result'] = $storage_result; }
		if ( $result['storage']['phase'] === 'stable' &&
			$result['storage']['backend'] === 'option' ) {
			$current = $raw_option( FOOGALLERY_MIGRATE_OPTION_DATA );
			$backup = $raw_option( StorageAuthority::STORAGE_BACKUP_OPTION );
			floor_real_check( is_string( $current ) && is_string( $backup ) &&
				$current !== $backup,
				'Reversal did not publish CURRENT option bytes distinct from old backup.' );
			$decoded = maybe_unserialize( $current );
			floor_real_check( is_array( $decoded ) && array_key_exists( $owner, $decoded ) &&
				$decoded[$owner] === $journal['edit'],
				'Raw CURRENT option bytes lost the exact typed table edit.' );
			unset( $decoded );
			$control_raw = $raw_option( StorageAuthority::CONTROL_OPTION );
			$control = is_string( $control_raw ) ?
				StorageControl::decode( $control_raw, get_current_blog_id() ) : null;
			floor_real_check( is_array( $control ) && $control['backend'] === 'option' &&
				$control['phase'] === 'stable' &&
				StorageControl::runtime_ready( $control,
					$raw_option( StorageAuthority::AUTHORITY_OPTION ) ),
				'Current option authority is not stable after reversal.' );
			$result['current_option_bytes'] = strlen( $current );
			$result['current_option_sha256'] = hash( 'sha256', $current );
			$journal['phase'] = 'reversed';
			$save( $journal );
		} else {
			floor_real_check( $result['storage']['target'] === 'option' &&
				$result['storage']['phase'] !== 'stable' &&
				in_array( $result['storage']['backend'], array( 'table', 'option' ), true ),
				'Reverse transition changed authority unexpectedly.' );
			$journal['phase'] = 'reversing';
			$save( $journal );
		}
		$result['phase'] = $journal['phase'];
		$result['source_after'] = $verify_source( $journal, false );
		$result['destination_after'] = $verify_destination( $journal );
	}
}
$result['elapsed_seconds'] = round( microtime( true ) - $started, 3 );
$result['peak_allocated_bytes'] = memory_get_peak_usage( true );
$result['storage'] = StorageAdmin::status();
echo json_encode( $result, JSON_UNESCAPED_SLASHES ) . "\n";
exit( isset( $result['failure'] ) ? 1 : 0 );
