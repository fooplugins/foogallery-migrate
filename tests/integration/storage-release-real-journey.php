<?php
/**
 * Owned, resumable actual AIGPL 10K fixture. One step per fresh CLI process.
 *
 * php storage-release-real-journey.php /tmp/foogallery-migrate-r0.XXXXXX seed|verify-source|status|preflight|provision|switch|recover|turn|children|settings100|edit
 * Seed and verify-source leave migration storage in its original option mode.
 * Turn/edit are for the later, explicitly table-backed R1c journey only.
 */
if ( 'cli' !== PHP_SAPI || 3 !== $argc || ! in_array( $argv[2], array( 'seed', 'verify-source', 'status', 'preflight', 'provision', 'switch', 'recover', 'turn', 'children', 'settings100', 'edit' ), true ) ) {
	throw new RuntimeException( 'An owned site path and one explicit step are required.' );
}
$site = realpath( $argv[1] );
if ( false === $site || ! preg_match( '~^/(?:private/)?tmp/foogallery-migrate-r0\.[A-Za-z0-9]+$~D', $site ) ) {
	throw new RuntimeException( 'Only a dedicated /tmp/foogallery-migrate-r0.* site is accepted.' );
}
$manifest_path = $site . '/.fgm-r0-owner.json';
$manifest = is_file( $manifest_path ) ? json_decode( file_get_contents( $manifest_path ), true ) : null;
if ( ! is_array( $manifest ) || ! isset( $manifest['kind'], $manifest['site'], $manifest['database'], $manifest['host'], $manifest['repo'], $manifest['container'], $manifest['container_id'] ) ||
	'foogallery-migrate-r0-v1' !== $manifest['kind'] || $site !== realpath( $manifest['site'] ) ||
	! preg_match( '/^fgm_r0_[a-z0-9]+$/D', $manifest['database'] ) ||
	! preg_match( '/^127\.0\.0\.1:[0-9]{2,5}$/D', $manifest['host'] ) ||
	! preg_match( '/^fgm-r0-[A-Za-z0-9]+$/D', $manifest['container'] ) ||
	! preg_match( '/^[a-f0-9]{64}$/D', $manifest['container_id'] ) ||
	realpath( dirname( dirname( __DIR__ ) ) ) !== realpath( $manifest['repo'] ) ||
	! is_file( $site . '/wp-load.php' ) || ! is_file( $site . '/wp-config.php' ) ||
	realpath( $site . '/wp-content/plugins/foogallery-migrate' ) !== realpath( $manifest['repo'] ) ) {
	throw new RuntimeException( 'Owned disposable site manifest or plugin source mismatch.' );
}
$container_check = array(); $container_code = 0;
exec( 'docker inspect --format ' . escapeshellarg( '{{.Id}} {{index .Config.Labels "foogallery-migrate-r0"}}' ) . ' ' .
	escapeshellarg( $manifest['container'] ) . ' 2>/dev/null', $container_check, $container_code );
if ( 0 !== $container_code || count( $container_check ) !== 1 ||
	trim( $container_check[0] ) !== $manifest['container_id'] . ' owned' ) {
	throw new RuntimeException( 'Owned SQL container identity mismatch.' );
}
define( 'WP_MAX_MEMORY_LIMIT', '256M' );
ini_set( 'memory_limit', '256M' );
require $site . '/wp-load.php';
use FooPlugins\FooGalleryMigrate\StorageAdmin;
use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\StorageCli;
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use FooPlugins\FooGalleryMigrate\LegacyTableBackend;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use FooPlugins\FooGalleryMigrate\Plugins\Aigpl;
use FooPlugins\FooGalleryMigrate\Objects\Migratable;
global $wpdb, $wp_version;
function real_check( $okay, $message ) {
	if ( ! $okay ) { throw new RuntimeException( $message ); }
}
real_check( ! is_multisite() && DB_NAME === $manifest['database'] && DB_HOST === $manifest['host'] &&
	$wpdb->get_var( 'SELECT DATABASE()' ) === $manifest['database'] && ! $wpdb->last_error,
	'Live SQL/site ownership differs from the manifest.' );
real_check( '256M' === ini_get( 'memory_limit' ) && '256M' === WP_MAX_MEMORY_LIMIT,
	'Owned request must use the 256M memory limit.' );
real_check( function_exists( 'foogallery_migrate_migrator_instance' ) &&
	function_exists( 'foogallery_default_gallery_template' ) && defined( 'FOOGALLERY_VERSION' ),
	'Actual FooGallery and FooGallery Migrate must be active.' );
wp_set_current_user( 1 );
real_check( current_user_can( 'manage_options' ), 'Owned administrator is unavailable.' );

$step = $argv[2];
$suffix = substr( $site, strrpos( $site, '.' ) + 1 );
$owner = 'fgm-real-' . $suffix;
$owner_key = '_fgm_real_fixture_owner';
$journal_key = 'fgm_real_owned_journey_v1';
$identity = hash( 'sha256', $site . '|' . $manifest['database'] . '|' . $manifest['container_id'] );
$total = 10000;
$batch = 256;
$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=' );
$png_hash = hash( 'sha256', $png );
$upload = wp_upload_dir();
real_check( empty( $upload['error'] ) && isset( $upload['basedir'], $upload['baseurl'] ) && is_dir( $upload['basedir'] ), 'Owned upload root is unavailable.' );
$media_dir = $upload['basedir'] . '/' . $owner;
$journal = get_option( $journal_key, null );
$save = function ( $value ) use ( $journal_key ) {
	real_check( update_option( $journal_key, $value, false ), 'Owned journal write failed.' );
	real_check( get_option( $journal_key, null ) === $value, 'Owned journal readback failed.' );
};
$owned_count = function ( $post_type ) use ( $wpdb, $owner_key, $owner ) {
	$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$wpdb->posts}` p INNER JOIN `{$wpdb->postmeta}` m ON m.post_id=p.ID WHERE p.post_type=%s AND m.meta_key=%s AND m.meta_value=%s", $post_type, $owner_key, $owner ) );
	real_check( ! $wpdb->last_error, 'Owned post count query failed.' );
	return (int) $count;
};
$raw_option = function ( $name ) use ( $wpdb ) {
	$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM `{$wpdb->options}` WHERE option_name=%s", $name ) );
	real_check( ! $wpdb->last_error, 'Raw migration storage option query failed.' );
	return $value;
};
$questions = function () use ( $wpdb ) {
	$rows = $wpdb->dbh->query( "SHOW SESSION STATUS LIKE 'Questions'" );
	real_check( $rows instanceof mysqli_result, 'Owned session query counter unavailable.' );
	$row = $rows->fetch_assoc(); $rows->free();
	real_check( is_array( $row ) && isset( $row['Value'] ) && ctype_digit( $row['Value'] ), 'Invalid owned query counter.' );
	return (int) $row['Value'];
};
$assert_pristine_storage = function () use ( $raw_option, $wpdb ) {
	foreach ( array( FOOGALLERY_MIGRATE_OPTION_DATA, StorageAuthority::CONTROL_OPTION,
		StorageAuthority::AUTHORITY_OPTION, StorageAuthority::MAINTENANCE_OPTION,
		StorageAuthority::STORAGE_BACKUP_OPTION, LegacyStorageSchema::VERSION_OPTION ) as $name ) {
		real_check( null === $raw_option( $name ), 'Source seeding refuses existing migration storage or schema marker.' );
	}
	$schema = new LegacyStorageSchema( $wpdb );
	foreach ( array_keys( $schema->definitions() ) as $table ) {
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		real_check( ! $wpdb->last_error && null === $found, 'Source seeding refuses existing migrated-storage tables.' );
	}
	$storage = StorageAdmin::status();
	real_check( 'option' === $storage['backend'] && 'stable' === $storage['phase'] && '' === $storage['problem'],
		'Source seeding requires clean original option authority.' );
};
$verify_source = function ( $state, $full ) use ( $total, $owner, $owner_key, $media_dir, $upload, $png_hash, $owned_count ) {
	real_check( 'ready' === $state['phase'] || 'migrating' === $state['phase'] || 'complete' === $state['phase'] || 'edited' === $state['phase'], 'The actual source is not complete.' );
	real_check( $total === count( $state['ids'] ) && $total === count( array_unique( $state['ids'] ) ) &&
		isset( $state['ids_sha256'], $state['source'], $state['content'], $state['content_sha256'] ) &&
		hash( 'sha256', implode( ',', $state['ids'] ) ) === $state['ids_sha256'], 'Ordered attachment ID manifest differs.' );
	real_check( is_dir( $media_dir ) && ! is_link( $media_dir ) &&
		$total === $owned_count( 'attachment' ) && 1 === $owned_count( Aigpl::POST_TYPE ) &&
		1 === $owned_count( 'page' ), 'Owned attachment/source/content post counts differ.' );
	$source = get_post( $state['source'] );
	$content = get_post( $state['content'] );
	real_check( $source && Aigpl::POST_TYPE === $source->post_type && 'publish' === $source->post_status &&
		$owner === get_post_meta( $source->ID, $owner_key, true ) &&
		$state['ids'] === get_post_meta( $source->ID, Aigpl::META_GALLERY_IMAGES, true ), 'Original AIGPL metadata differs.' );
	real_check( $content && 'page' === $content->post_type && 'publish' === $content->post_status &&
		$owner === get_post_meta( $content->ID, $owner_key, true ) &&
		hash( 'sha256', $content->post_content ) === $state['content_sha256'], 'Original shortcode content differs.' );
	if ( $full ) {
		foreach ( $state['ids'] as $index => $id ) {
			$relative = $owner . '/' . $index . '.png';
			$file = $media_dir . '/' . $index . '.png';
			$post = get_post( $id );
			$meta = wp_get_attachment_metadata( $id );
			real_check( $post && 'attachment' === $post->post_type && 'inherit' === $post->post_status &&
				'image/png' === $post->post_mime_type && $owner === get_post_meta( $id, $owner_key, true ) &&
				$relative === get_post_meta( $id, '_wp_attached_file', true ) && is_array( $meta ) &&
				isset( $meta['file'], $meta['width'], $meta['height'] ) && $relative === $meta['file'] &&
				1 === (int) $meta['width'] && 1 === (int) $meta['height'] &&
				is_file( $file ) && ! is_link( $file ) && $png_hash === hash_file( 'sha256', $file ),
				'Owned attachment post, metadata, or file differs at index ' . $index . '.' );
		}
		$files = glob( $media_dir . '/*.png' );
		real_check( is_array( $files ) && $total === count( $files ), 'Owned media file count differs.' );
	}
	return array( 'attachments' => $total, 'ordered_ids_sha256' => $state['ids_sha256'],
		'source' => (int) $state['source'], 'content' => (int) $state['content'],
		'content_sha256' => $state['content_sha256'], 'png_sha256' => $png_hash,
		'files_checked' => $full ? $total : 0 );
};

if ( 'seed' === $step && null === $journal ) {
	real_check( 0 === $owned_count( 'attachment' ) && 0 === $owned_count( Aigpl::POST_TYPE ) &&
		0 === $owned_count( 'page' ) && ! file_exists( $media_dir ), 'Owned fixture already exists without its journal.' );
	// Seeding must not replace an existing migration or change storage authority.
	$assert_pristine_storage();
	$journal = array( 'identity' => $identity, 'owner' => $owner, 'phase' => 'seeding',
		'ids' => array(), 'turns' => 0, 'completed' => 0 );
	real_check( add_option( $journal_key, $journal, '', false ), 'Owned fixture journal could not be created.' );
}
real_check( is_array( $journal ) && isset( $journal['identity'], $journal['owner'], $journal['phase'], $journal['ids'] ) &&
	$identity === $journal['identity'] && $owner === $journal['owner'] && is_array( $journal['ids'] ) &&
	count( $journal['ids'] ) <= $total, 'Owned fixture journal is missing or mismatched.' );

if ( 'seed' === $step ) {
	real_check( 'seeding' === $journal['phase'], 'Fixture is already complete; refusing reseed.' );
	$assert_pristine_storage();
	real_check( ! is_link( $media_dir ) && ( is_dir( $media_dir ) || wp_mkdir_p( $media_dir ) ), 'Owned media directory is unavailable.' );
	$end = min( $total, count( $journal['ids'] ) + $batch );
	for ( $index = count( $journal['ids'] ); $index < $end; ++$index ) {
		$file = $media_dir . '/' . $index . '.png';
		real_check( ! file_exists( $file ) && false !== file_put_contents( $file, $png, LOCK_EX ), 'Owned media collision or write failure.' );
		$relative = $owner . '/' . $index . '.png';
		$id = wp_insert_post( array( 'post_type' => 'attachment', 'post_status' => 'inherit',
			'post_mime_type' => 'image/png', 'post_title' => $owner . ' ' . $index,
			'guid' => $upload['baseurl'] . '/' . $relative,
			'meta_input' => array( $owner_key => $owner ) ), true );
		real_check( ! is_wp_error( $id ) && $id > 0, 'Owned attachment insertion failed.' );
		real_check( false !== update_post_meta( $id, '_wp_attached_file', $relative ) &&
			false !== update_post_meta( $id, '_wp_attachment_metadata', array( 'width' => 1, 'height' => 1,
				'file' => $relative, 'sizes' => array() ) ), 'Owned attachment metadata failed.' );
		$journal['ids'][] = (int) $id;
		$save( $journal );
	}
	if ( $total === count( $journal['ids'] ) ) {
		real_check( 0 === $owned_count( Aigpl::POST_TYPE ) && 0 === $owned_count( 'page' ), 'Existing owned source/content collision.' );
		register_post_type( Aigpl::POST_TYPE, array( 'public' => false ) );
		$source = wp_insert_post( array( 'post_type' => Aigpl::POST_TYPE, 'post_status' => 'publish',
			'post_title' => $owner, 'meta_input' => array( $owner_key => $owner ) ), true );
		real_check( ! is_wp_error( $source ) && $source > 0, 'Owned AIGPL source insertion failed.' );
		real_check( false !== update_post_meta( $source, Aigpl::META_GALLERY_IMAGES, $journal['ids'] ), 'Original AIGPL image metadata failed.' );
		$content = 'Original AIGPL source: [aigpl-gallery id="' . $source . '"]';
		$page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish',
			'post_title' => $owner . ' content', 'post_content' => $content,
			'meta_input' => array( $owner_key => $owner ) ), true );
		real_check( ! is_wp_error( $page ) && $page > 0, 'Owned source content insertion failed.' );
		$journal['source'] = (int) $source;
		$journal['content'] = (int) $page;
		$journal['content_sha256'] = hash( 'sha256', $content );
		$journal['ids_sha256'] = hash( 'sha256', implode( ',', $journal['ids'] ) );
		$journal['phase'] = 'ready';
		$save( $journal );
	}
}
$result = array( 'step' => $step, 'site' => $site, 'database' => $manifest['database'],
	'php' => PHP_VERSION, 'wordpress' => $wp_version, 'owner' => $owner,
	'phase' => $journal['phase'], 'seeded' => count( $journal['ids'] ),
	'turns' => $journal['turns'], 'completed' => $journal['completed'] );
if ( 'verify-source' === $step ) {
	$result['source'] = $verify_source( $journal, true );
}
if ( 'status' === $step ) {
	$result['storage'] = StorageAdmin::status();
	if ( 'seeding' !== $journal['phase'] ) { $result['source'] = $verify_source( $journal, false ); }
}
if ( 'children' === $step ) {
	$storage = StorageAdmin::status();
	real_check( 'table' === $storage['backend'] && 'stable' === $storage['phase'] && 'verified' === $storage['schema'] &&
		'' === $storage['problem'] && 'migrating' === $journal['phase'] && $journal['completed'] > 0,
		'Owned populated stable table history required for child proof.' );
	$result['source'] = $verify_source( $journal, false );
	$keys = array(); $number = min( 100, (int) $journal['completed'] );
	for ( $i = 0; $i < $number; ++$i ) {
		$key = wp_get_attachment_url( $journal['ids'][$i] );
		real_check( is_string( $key ) && '' !== $key, 'Owned image URL unavailable.' ); $keys[] = $key;
	}
	$keys = array_reverse( $keys );
	$backend = new LegacyTableBackend( new LegacyTransactionConnection( $wpdb ), (int) get_current_blog_id() );
	$before_questions = $questions(); $started = microtime( true );
	$read = $backend->history_read( 'children', array( 'parent' => $journal['uid'], 'keys' => array_merge( $keys, array( 'missing-owned-child' ) ) ) );
	$result['elapsed_seconds'] = round( microtime( true ) - $started, 3 );
	$result['session_questions_delta'] = $questions() - $before_questions;
	$result['peak_allocated_bytes'] = memory_get_peak_usage( true );
	real_check( $keys === array_column( $read['result'], 'key' ), 'Exact child identity or caller order differs.' );
	foreach ( $read['result'] as $row ) {
		real_check( is_array( $row['value'] ) && isset( $row['value']['migration_parent_key'] ) &&
			$journal['uid'] === $row['value']['migration_parent_key'], 'Actual child parent association differs.' );
	}
	$other = $backend->history_read( 'children', array( 'parent' => 'unrelated-owned-parent', 'keys' => $keys ) );
	real_check( array() === $other['result'], 'Unrelated parent acquired actual children.' );
	$result['matched_children'] = count( $read['result'] );
}
if ( 'settings100' === $step ) {
	$storage = StorageAdmin::status();
	real_check( 'table' === $storage['backend'] && 'stable' === $storage['phase'] && 'verified' === $storage['schema'] &&
		'' === $storage['problem'] && 'migrating' === $journal['phase'] && $journal['turns'] >= 5 &&
		$journal['completed'] >= 25 && ! isset( $journal['images_per_turn'] ),
		'Only the owned, default-turn-validated migration may change this setting once.' );
	$settings = new MigratorSettings();
	real_check( 5 === $settings->get_images_per_turn(), 'Existing normal setting is not the default five.' );
	$settings->save_settings( array( MigratorSettings::SETTING_IMAGES_PER_TURN => 100 ) );
	real_check( 100 === $settings->get_images_per_turn() &&
		100 === foogallery_migrate_migrator_instance()->get_images_per_turn(), 'Normal setting did not persist as 100.' );
	$journal['images_per_turn'] = 100; $save( $journal );
	$result['images_per_turn'] = 100;
}
if ( in_array( $step, array( 'preflight', 'provision', 'switch', 'recover' ), true ) ) {
	real_check( 'ready' === $journal['phase'] && 0 === $journal['turns'] && 0 === $journal['completed'],
		'Storage enablement requires the unchanged, unstarted actual fixture.' );
	$result['source'] = $verify_source( $journal, false );
	$before_questions = $questions(); $started = microtime( true );
	$confirm = 'recover' === $step ? 'recover-storage' :
		( 'provision' === $step ? 'provision-shared-schema' : 'switch-to-table' );
	$storage_result = StorageCli::execute( $step, array( 'target' => 'table', 'confirm' => $confirm ) );
	$result['elapsed_seconds'] = round( microtime( true ) - $started, 3 );
	$result['session_questions_delta'] = $questions() - $before_questions;
	$result['peak_allocated_bytes'] = memory_get_peak_usage( true );
	$result['storage'] = StorageAdmin::status();
	if ( is_wp_error( $storage_result ) ) {
		$result['failure'] = array( 'code' => $storage_result->get_error_code(), 'message' => $storage_result->get_error_message() );
	} else { $result['storage_result'] = $storage_result; }
}
if ( 'turn' === $step || 'edit' === $step ) {
	$storage = StorageAdmin::status();
	real_check( 'table' === $storage['backend'] && 'stable' === $storage['phase'] &&
		'verified' === $storage['schema'] && '' === $storage['problem'], 'Stable verified table authority is required.' );
	$result['source'] = $verify_source( $journal, false );
	$before_questions = $questions(); $started = microtime( true );
	$engine = foogallery_migrate_migrator_instance();
	$limit = $engine->get_images_per_turn();
	real_check( is_numeric( $limit ) && (int) $limit > 0, 'Normal images-per-turn setting is invalid.' );
	$result['images_per_turn'] = (int) $limit;
	if ( 'turn' === $step ) {
		real_check( 'ready' === $journal['phase'] || 'migrating' === $journal['phase'], 'No pending migration turn.' );
		$migrator = $engine->get_gallery_migrator();
		if ( 'ready' === $journal['phase'] ) {
			$engine->run_detection();
			$objects = $migrator->get_objects_to_migrate( true );
			real_check( 1 === count( $objects ) && $total === (int) $objects[0]->children_count,
				'Actual AIGPL detection did not find exactly one 10K gallery.' );
			$journal['uid'] = $objects[0]->unique_identifier();
			real_check( $migrator->queue_objects_for_migration( array( $journal['uid'] => array( 'title' => $owner ) ) ), 'Actual gallery queue refused.' );
			$journal['phase'] = 'migrating';
			$save( $journal );
		}
		$before = (int) $journal['completed'];
		real_check( $migrator->migrate(), 'Normal migration turn failed.' );
		$objects = $migrator->get_objects_to_migrate();
		real_check( 1 === count( $objects ) && $journal['uid'] === $objects[0]->unique_identifier(), 'Actual migration object changed.' );
		$object = $objects[0];
		$completed = (int) $object->migrated_child_count;
		real_check( $completed >= $before && $completed <= min( $total, $before + (int) $limit ) &&
			(int) $engine->get_images_per_turn() === (int) $limit, 'Migration progress or images-per-turn changed.' );
		$journal['turns']++;
		$journal['completed'] = $completed;
		$journal['destination'] = (int) $object->migrated_id;
		$result['migration_status'] = $object->migration_status;
		if ( $object->migrated ) {
			real_check( $total === $completed && $journal['ids'] === get_post_meta( $object->migrated_id, FOOGALLERY_META_ATTACHMENTS, true ), 'Destination attachment IDs/order differ.' );
			$matches = get_posts( array( 'post_type' => FOOGALLERY_CPT_GALLERY, 'post_status' => 'any',
				'posts_per_page' => 2, 'fields' => 'ids', 'meta_key' => Migratable::META_SOURCE_KEY,
				'meta_value' => $journal['uid'] ) );
			real_check( 1 === count( $matches ) && (int) $matches[0] === (int) $object->migrated_id, 'Actual destination ownership/uniqueness differs.' );
			real_check( false !== strpos( do_shortcode( '[foogallery id="' . $object->migrated_id . '"]' ), 'foogallery' ), 'Actual FooGallery output failed.' );
			$journal['phase'] = 'complete';
		}
		$save( $journal );
		$result['phase'] = $journal['phase'];
		$result['turns'] = $journal['turns'];
		$result['completed'] = $journal['completed'];
		$result['destination'] = $journal['destination'];
	} else {
		real_check( 'complete' === $journal['phase'] && $total === (int) $journal['completed'], 'Completed actual migration required for edit.' );
		$journal['edit'] = array( 'current' => true, '09' => null, 9 => false, 'bytes' => "x\0" );
		real_check( $engine->set_migrator_setting( $owner, $journal['edit'] ) &&
			$journal['edit'] === $engine->get_migrator_setting( $owner ), 'Normal table edit/readback failed.' );
		$journal['phase'] = 'edited';
		$save( $journal );
		$result['phase'] = $journal['phase'];
	}
	$result['elapsed_seconds'] = round( microtime( true ) - $started, 3 );
	$result['session_questions_delta'] = $questions() - $before_questions;
	$result['peak_allocated_bytes'] = memory_get_peak_usage( true );
}
echo json_encode( $result, JSON_UNESCAPED_SLASHES ) . "\n";
exit( isset( $result['failure'] ) ? 1 : 0 );
