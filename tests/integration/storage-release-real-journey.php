<?php
/**
 * Owned, resumable actual AIGPL fixture. One step per fresh CLI process.
 *
 * php storage-release-real-journey.php /tmp/foogallery-migrate-r0.XXXXXX seed|verify-source|verify-destination|verify-reversed|status|option-start|option-turn|preflight|provision|switch|recover|turn|children|settings100|edit|reverse
 * Append 1000 to the first seed call only for a new fitting fixture; the default remains 10K.
 * The guarded option prefix uses ordinary detection, queue and migration before
 * explicit table conversion. Turn/edit remain table-backed operations.
 */
$wp_cli_mode = defined( 'WP_CLI' ) && WP_CLI;
if ( $wp_cli_mode ) {
	if ( ! isset( $args ) || ! is_array( $args ) ) { throw new RuntimeException( 'WP-CLI fixture arguments are unavailable.' ); }
	$argv = array_merge( array( __FILE__ ), $args );
	$argc = count( $argv );
}
if ( 'cli' !== PHP_SAPI || ! ( 3 === $argc || ( 4 === $argc && isset( $argv[2], $argv[3] ) && 'seed' === $argv[2] && '1000' === $argv[3] ) ) ||
	! in_array( $argv[2], array( 'seed', 'verify-source', 'verify-destination', 'verify-reversed', 'status', 'option-start', 'option-turn', 'preflight', 'provision', 'switch', 'recover', 'turn', 'children', 'settings100', 'edit', 'reverse' ), true ) ) {
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
	! is_file( $site . '/wp-load.php' ) || ! is_file( $site . '/wp-config.php' ) ) {
	throw new RuntimeException( 'Owned disposable site manifest or plugin source mismatch.' );
}
$plugin_dir = $site . '/wp-content/plugins/foogallery-migrate';
if ( isset( $manifest['package'] ) ) {
	if ( is_link( $plugin_dir ) || realpath( $plugin_dir ) !== $plugin_dir ) {
		throw new RuntimeException( 'Extracted package must be an owned regular plugin directory.' );
	}
	$identity_output = array(); $identity_code = 0;
	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/storage-release-package-identity.php' ) .
		' verify-tree ' . escapeshellarg( $plugin_dir ) . ' ' . escapeshellarg( $manifest_path ) . ' 2>/dev/null',
		$identity_output, $identity_code );
	if ( 0 !== $identity_code || count( $identity_output ) !== 1 ||
		trim( $identity_output[0] ) !== $manifest['package']['tree_sha256'] ) {
		throw new RuntimeException( 'Extracted package differs from pinned ZIP identity.' );
	}
} elseif ( realpath( $plugin_dir ) !== realpath( $manifest['repo'] ) ) {
	throw new RuntimeException( 'Owned plugin source symlink differs from manifest.' );
}
$container_check = array(); $container_code = 0;
exec( 'docker inspect --format ' . escapeshellarg( '{{.Id}} {{index .Config.Labels "foogallery-migrate-r0"}}' ) . ' ' .
	escapeshellarg( $manifest['container'] ) . ' 2>/dev/null', $container_check, $container_code );
if ( 0 !== $container_code || count( $container_check ) !== 1 ||
	trim( $container_check[0] ) !== $manifest['container_id'] . ' owned' ) {
	throw new RuntimeException( 'Owned SQL container identity mismatch.' );
}
if ( $wp_cli_mode && realpath( ABSPATH ) !== $site ) {
	throw new RuntimeException( 'WP-CLI loaded a different site from the owned fixture.' );
}
if ( ! defined( 'WP_MAX_MEMORY_LIMIT' ) ) { define( 'WP_MAX_MEMORY_LIMIT', '256M' ); }
ini_set( 'memory_limit', '256M' );
if ( ! $wp_cli_mode ) { require $site . '/wp-load.php'; }
use FooPlugins\FooGalleryMigrate\StorageAdmin;
use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\StorageControl;
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
$requested_total = 4 === $argc ? 1000 : null;
$suffix = substr( $site, strrpos( $site, '.' ) + 1 );
$owner = 'fgm-real-' . $suffix;
$owner_key = '_fgm_real_fixture_owner';
$journal_key = 'fgm_real_owned_journey_v1';
$identity = hash( 'sha256', $site . '|' . $manifest['database'] . '|' . $manifest['container_id'] );
$batch = 256;
$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=' );
$png_hash = hash( 'sha256', $png );
$upload = wp_upload_dir();
real_check( empty( $upload['error'] ) && isset( $upload['basedir'], $upload['baseurl'] ) && is_dir( $upload['basedir'] ), 'Owned upload root is unavailable.' );
$media_dir = $upload['basedir'] . '/' . $owner;
$journal = get_option( $journal_key, null );
$total = null === $journal ? ( null === $requested_total ? 10000 : $requested_total ) :
	( is_array( $journal ) && isset( $journal['total'] ) ? $journal['total'] : 10000 );
real_check( is_int( $total ) && in_array( $total, array( 1000, 10000 ), true ) &&
	( null === $journal || null === $requested_total ),
	'Existing owned journal fixes size; requested size change refused.' );
$save = function ( $value ) use ( $journal_key, $wpdb ) {
	// An exact no-op may return false; durable raw SQL and Options API must agree.
	update_option( $journal_key, $value, false );
	$raw = $wpdb->get_var( $wpdb->prepare(
		"SELECT option_value FROM `{$wpdb->options}` WHERE option_name=%s", $journal_key
	) );
	real_check( ! $wpdb->last_error && is_string( $raw ) &&
		maybe_unserialize( $raw ) === $value && get_option( $journal_key, null ) === $value,
		'Owned journal durable readback failed.' );
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
		StorageAuthority::STORAGE_BACKUP_OPTION, LegacyStorageSchema::VERSION_OPTION,
		LegacyStorageSchema::LOCK_OPTION ) as $name ) {
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
	real_check( in_array( $state['phase'], array( 'ready', 'option-starting', 'migrating', 'complete', 'edited', 'reversing', 'reversed' ), true ), 'The actual source is not complete.' );
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
$verify_destination = function ( $state ) use ( $total ) {
	real_check( in_array( $state['phase'], array( 'complete', 'edited', 'reversing', 'reversed' ), true ) &&
		$total === (int) $state['completed'] && isset( $state['uid'], $state['destination'] ),
		'Completed owned migration required for destination proof.' );
	$destination = get_post( $state['destination'] );
	real_check( $destination && FOOGALLERY_CPT_GALLERY === $destination->post_type &&
		$state['ids'] === get_post_meta( $destination->ID, FOOGALLERY_META_ATTACHMENTS, true ),
		'Destination gallery or exact attachment order differs.' );
	$matches = get_posts( array( 'post_type' => FOOGALLERY_CPT_GALLERY, 'post_status' => 'any',
		'posts_per_page' => 2, 'fields' => 'ids', 'meta_key' => Migratable::META_SOURCE_KEY,
		'meta_value' => $state['uid'] ) );
	real_check( 1 === count( $matches ) && (int) $matches[0] === (int) $destination->ID,
		'Actual destination ownership/uniqueness differs.' );
	$output = do_shortcode( '[foogallery id="' . $destination->ID . '"]' );
	real_check( is_string( $output ) && false !== strpos( $output, 'foogallery' ),
		'Actual FooGallery output failed.' );
	return array( 'post' => (int) $destination->ID, 'attachments' => $total,
		'ordered_ids_sha256' => hash( 'sha256', implode( ',', $state['ids'] ) ),
		'output_bytes' => strlen( $output ) );
};
$option_measure = function ( $state, $allow_backup ) use ( $raw_option ) {
	$current = $raw_option( FOOGALLERY_MIGRATE_OPTION_DATA );
	$backup = $allow_backup ? $raw_option( StorageAuthority::STORAGE_BACKUP_OPTION ) : null;
	$raw = is_string( $current ) ? $current : $backup;
	real_check( is_string( $raw ), 'Pinned option state is absent from both current and retained storage.' );
	$measurement = array( 'bytes' => strlen( $raw ), 'sha256' => hash( 'sha256', $raw ) );
	if ( isset( $state['option_sha256'] ) ) {
		real_check( $measurement['bytes'] === $state['option_bytes'] &&
			$measurement['sha256'] === $state['option_sha256'], 'Pinned option bytes changed outside the owned journey.' );
	}
	return $measurement;
};
$verify_progress = function ( $state, $engine ) use ( $total ) {
	real_check( isset( $state['uid'], $state['completed'], $state['destination'] ) &&
		is_string( $state['uid'] ) && '' !== $state['uid'] && is_int( $state['completed'] ) &&
		$state['completed'] >= 0 && $state['completed'] < $total,
		'Owned unfinished migration identity or progress is invalid.' );
	$objects = $engine->get_gallery_migrator()->get_objects_to_migrate();
	real_check( 1 === count( $objects ) && $state['uid'] === $objects[0]->unique_identifier() &&
		$total === (int) $objects[0]->children_count && ! empty( $objects[0]->part_of_migration ) &&
		in_array( $objects[0]->migration_status, array( Migratable::PROGRESS_QUEUED, Migratable::PROGRESS_STARTED ), true ) &&
		( ! isset( $state['option_status'] ) || $state['option_status'] === $objects[0]->migration_status ) &&
		$state['completed'] === (int) $objects[0]->migrated_child_count &&
		(int) $state['destination'] === (int) $objects[0]->migrated_id,
		'Queued gallery identity, status, destination or progress changed.' );
	if ( $state['completed'] > 0 ) {
		$destination = get_post( $state['destination'] );
		$ids = get_post_meta( $state['destination'], FOOGALLERY_META_ATTACHMENTS, true );
		real_check( $destination && FOOGALLERY_CPT_GALLERY === $destination->post_type &&
			is_array( $ids ) && array_slice( $state['ids'], 0, $state['completed'] ) === $ids &&
			$state['uid'] === get_post_meta( $destination->ID, Migratable::META_SOURCE_KEY, true ),
			'Owned destination prefix, attachment order or source identity changed.' );
		$matches = get_posts( array( 'post_type' => FOOGALLERY_CPT_GALLERY, 'post_status' => 'any',
			'posts_per_page' => 2, 'fields' => 'ids', 'meta_key' => Migratable::META_SOURCE_KEY,
			'meta_value' => $state['uid'] ) );
		real_check( 1 === count( $matches ) && (int) $matches[0] === (int) $state['destination'],
			'Owned destination is not unique.' );
	} else {
		real_check( 0 === (int) $state['destination'], 'Unstarted gallery unexpectedly has a destination.' );
	}
	return array( 'uid' => $state['uid'], 'completed' => $state['completed'],
		'destination' => (int) $state['destination'], 'status' => $objects[0]->migration_status );
};

if ( 'seed' === $step && null === $journal ) {
	real_check( 0 === $owned_count( 'attachment' ) && 0 === $owned_count( Aigpl::POST_TYPE ) &&
		0 === $owned_count( 'page' ) && ! file_exists( $media_dir ), 'Owned fixture already exists without its journal.' );
	// Seeding must not replace an existing migration or change storage authority.
	$assert_pristine_storage();
	$journal = array( 'identity' => $identity, 'owner' => $owner, 'total' => $total, 'phase' => 'seeding',
		'ids' => array(), 'turns' => 0, 'completed' => 0 );
	real_check( add_option( $journal_key, $journal, '', false ), 'Owned fixture journal could not be created.' );
}
real_check( is_array( $journal ) && isset( $journal['identity'], $journal['owner'], $journal['phase'], $journal['ids'] ) &&
	$identity === $journal['identity'] && $owner === $journal['owner'] && is_array( $journal['ids'] ) &&
	count( $journal['ids'] ) <= $total, 'Owned fixture journal is missing or mismatched.' );
if ( isset( $journal['option_prefix'] ) ) {
	real_check( true === $journal['option_prefix'] && 10000 === $total &&
		isset( $journal['uid'], $journal['destination'], $journal['option_status'], $journal['option_bytes'], $journal['option_sha256'] ) &&
		in_array( $journal['option_status'], array( Migratable::PROGRESS_QUEUED, Migratable::PROGRESS_STARTED ), true ) &&
		is_int( $journal['option_bytes'] ) && $journal['option_bytes'] > 0 &&
		is_string( $journal['option_sha256'] ) && preg_match( '/^[a-f0-9]{64}$/D', $journal['option_sha256'] ) &&
		in_array( $journal['phase'], array( 'migrating', 'complete', 'edited', 'reversing', 'reversed' ), true ),
		'Owned option-prefix journal is incomplete or incompatible.' );
}

if ( 'seed' === $step ) {
	real_check( 'seeding' === $journal['phase'], 'Fixture is already complete; refusing reseed.' );
	$assert_pristine_storage();
	real_check( ! is_link( $media_dir ) && ( is_dir( $media_dir ) || wp_mkdir_p( $media_dir ) ), 'Owned media directory is unavailable.' );
	$end = min( $total, count( $journal['ids'] ) + $batch );
	for ( $index = count( $journal['ids'] ); $index < $end; ++$index ) {
		$file = $media_dir . '/' . $index . '.png';
		real_check( ! file_exists( $file ) && ! is_link( $file ) && false !== file_put_contents( $file, $png, LOCK_EX ), 'Owned media collision or write failure.' );
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
	'memory_limit' => ini_get( 'memory_limit' ), 'fixture_total' => $total,
	'phase' => $journal['phase'], 'seeded' => count( $journal['ids'] ),
	'turns' => $journal['turns'], 'completed' => $journal['completed'] );
if ( 'verify-source' === $step ) {
	$result['source'] = $verify_source( $journal, true );
}
if ( 'verify-destination' === $step ) {
	$result['source'] = $verify_source( $journal, false );
	$result['destination_proof'] = $verify_destination( $journal );
}
if ( 'verify-reversed' === $step ) {
	$storage = StorageAdmin::status();
	real_check( 'reversed' === $journal['phase'] && isset( $journal['edit'] ) &&
		'option' === $storage['backend'] && 'stable' === $storage['phase'] &&
		'verified' === $storage['schema'] && '' === $storage['problem'] && ! empty( $storage['retained'] ),
		'Stable retained current-option reversal required for proof.' );
	$result['source'] = $verify_source( $journal, true );
	$result['destination_proof'] = $verify_destination( $journal );
	$current = $raw_option( FOOGALLERY_MIGRATE_OPTION_DATA );
	$backup = $raw_option( StorageAuthority::STORAGE_BACKUP_OPTION );
	real_check( is_string( $current ) && is_string( $backup ) && $current !== $backup,
		'CURRENT option must differ from retained old backup.' );
	$decoded = maybe_unserialize( $current );
	real_check( is_array( $decoded ) && array_key_exists( $owner, $decoded ) &&
		$decoded[$owner] === $journal['edit'], 'Raw CURRENT option lost the exact typed table edit.' );
	unset( $decoded );
	$control_raw = $raw_option( StorageAuthority::CONTROL_OPTION );
	$control = is_string( $control_raw ) ? StorageControl::decode( $control_raw, get_current_blog_id() ) : null;
	real_check( is_array( $control ) && 'option' === $control['backend'] &&
		'stable' === $control['phase'] && StorageControl::runtime_ready( $control,
			$raw_option( StorageAuthority::AUTHORITY_OPTION ) ),
		'Stable current-option authority differs.' );
	$result['current_option_bytes'] = strlen( $current );
	$result['current_option_sha256'] = hash( 'sha256', $current );
	$result['edit_sha256'] = hash( 'sha256', serialize( $journal['edit'] ) );
}
if ( 'status' === $step ) {
	$result['storage'] = StorageAdmin::status();
	if ( 'seeding' !== $journal['phase'] ) { $result['source'] = $verify_source( $journal, false ); }
	if ( isset( $journal['option_prefix'] ) ) {
		$result['pinned_option'] = $option_measure( $journal, true );
		if ( 'migrating' === $journal['phase'] && 'stable' === $result['storage']['phase'] ) {
			$result['progress'] = $verify_progress( $journal, foogallery_migrate_migrator_instance() );
		}
	}
}
if ( 'option-start' === $step ) {
	real_check( 10000 === $total && 'ready' === $journal['phase'] && 0 === $journal['turns'] &&
		0 === $journal['completed'] && ! isset( $journal['option_prefix'] ) &&
		null === $raw_option( FOOGALLERY_MIGRATE_OPTION_DATA ) &&
		null === $raw_option( StorageAuthority::CONTROL_OPTION ),
		'Only the untouched owned 10K option fixture may start the option prefix.' );
	$storage = StorageAdmin::status();
	real_check( 'option' === $storage['backend'] && 'stable' === $storage['phase'] &&
		'' === $storage['problem'], 'Original option authority is not healthy.' );
	$result['source'] = $verify_source( $journal, false );
	$engine = foogallery_migrate_migrator_instance();
	real_check( 5 === (int) $engine->get_images_per_turn(), 'Owned option prefix must start with the ordinary default five.' );
	// A crash after any production write must not silently re-detect or re-queue.
	$journal['phase'] = 'option-starting'; $save( $journal );
	$before_questions = $questions(); $started = microtime( true );
	$engine->run_detection();
	$migrator = $engine->get_gallery_migrator();
	$objects = $migrator->get_objects_to_migrate( true );
	real_check( 1 === count( $objects ) && (int) $journal['source'] === (int) $objects[0]->ID &&
		$total === (int) $objects[0]->children_count, 'Actual AIGPL discovery changed.' );
	$uid = $objects[0]->unique_identifier();
	real_check( $migrator->queue_objects_for_migration( array( $uid => array( 'title' => $owner ) ) ),
		'Actual option-backed gallery queue refused.' );
	$journal['uid'] = $uid; $journal['destination'] = 0;
	$queued = $migrator->get_objects_to_migrate();
	real_check( 1 === count( $queued ) && $uid === $queued[0]->unique_identifier() &&
		Migratable::PROGRESS_QUEUED === $queued[0]->migration_status &&
		! empty( $queued[0]->part_of_migration ) && 0 === (int) $queued[0]->migrated_child_count,
		'Ordinary option queue was not durably retained.' );
	$measure = $option_measure( array(), false );
	$journal['option_prefix'] = true;
	$journal['option_status'] = $queued[0]->migration_status;
	$journal['option_bytes'] = $measure['bytes'];
	$journal['option_sha256'] = $measure['sha256'];
	$journal['phase'] = 'migrating'; $save( $journal );
	$result['option'] = $measure;
	$result['progress'] = $verify_progress( $journal, $engine );
	$result['elapsed_seconds'] = round( microtime( true ) - $started, 3 );
	$result['session_questions_delta'] = $questions() - $before_questions;
	$result['peak_allocated_bytes'] = memory_get_peak_usage( true );
	$result['phase'] = $journal['phase'];
}
if ( 'option-turn' === $step ) {
	real_check( 10000 === $total && 'migrating' === $journal['phase'] &&
		isset( $journal['option_prefix'] ) && true === $journal['option_prefix'] &&
		$journal['option_bytes'] < 1048576 && $journal['completed'] < $total,
		'Option prefix is absent, complete or already at the 1 MiB inspection point.' );
	$storage = StorageAdmin::status();
	real_check( 'option' === $storage['backend'] && 'stable' === $storage['phase'] &&
		'' === $storage['problem'], 'Option turn requires stable original option authority.' );
	$result['source'] = $verify_source( $journal, false );
	$option_measure( $journal, false );
	$before_questions = $questions(); $started = microtime( true );
	$engine = foogallery_migrate_migrator_instance();
	$verify_progress( $journal, $engine );
	$limit = $engine->get_images_per_turn();
	real_check( is_numeric( $limit ) && (int) $limit > 0 &&
		$journal['completed'] + (int) $limit < $total &&
		( $journal['turns'] < 5 ? 5 === (int) $limit :
			( isset( $journal['images_per_turn'] ) ? 100 === (int) $limit : 5 === (int) $limit ) ),
		'Normal option turn size changed or would finish the source before conversion.' );
	$migrator = $engine->get_gallery_migrator();
	$before = $journal['completed'];
	real_check( $migrator->migrate(), 'Normal option-backed migration turn failed.' );
	$objects = $migrator->get_objects_to_migrate();
	real_check( 1 === count( $objects ) && $journal['uid'] === $objects[0]->unique_identifier() &&
		! $objects[0]->migrated && $objects[0]->migrated_child_count > $before &&
		$objects[0]->migrated_child_count <= $before + (int) $limit &&
		(int) $objects[0]->migrated_id > 0,
		'Option turn did not preserve the unfinished queued gallery.' );
	$journal['turns']++;
	$journal['completed'] = (int) $objects[0]->migrated_child_count;
	$journal['destination'] = (int) $objects[0]->migrated_id;
	$journal['option_status'] = $objects[0]->migration_status;
	$measure = $option_measure( array(), false );
	real_check( $measure['sha256'] !== $journal['option_sha256'],
		'Option turn advanced output without a durable migration-option write.' );
	$journal['option_bytes'] = $measure['bytes'];
	$journal['option_sha256'] = $measure['sha256'];
	$save( $journal );
	$result['option'] = $measure;
	$result['progress'] = $verify_progress( $journal, $engine );
	$result['images_per_turn'] = (int) $limit;
	$result['turns'] = $journal['turns'];
	$result['completed'] = $journal['completed'];
	$result['destination'] = $journal['destination'];
	$result['inspection_ready'] = $measure['bytes'] >= 1048576;
	$result['elapsed_seconds'] = round( microtime( true ) - $started, 3 );
	$result['session_questions_delta'] = $questions() - $before_questions;
	$result['peak_allocated_bytes'] = memory_get_peak_usage( true );
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
	$option_prefix = 'option' === $storage['backend'] && 10000 === $total &&
		isset( $journal['option_prefix'] ) && true === $journal['option_prefix'];
	real_check( in_array( $storage['backend'], array( 'option', 'table' ), true ) &&
		'stable' === $storage['phase'] && ( $option_prefix || 'verified' === $storage['schema'] ) &&
		'' === $storage['problem'] && 'migrating' === $journal['phase'] && $journal['turns'] >= 5 &&
		$journal['completed'] >= 25 && ! isset( $journal['images_per_turn'] ),
		'Only the owned, default-turn-validated migration may change this setting once.' );
	if ( $option_prefix ) {
		$option_measure( $journal, false );
		$verify_progress( $journal, foogallery_migrate_migrator_instance() );
	}
	$settings = new MigratorSettings();
	real_check( 5 === $settings->get_images_per_turn(), 'Existing normal setting is not the default five.' );
	$settings->save_settings( array( MigratorSettings::SETTING_IMAGES_PER_TURN => 100 ) );
	real_check( 100 === $settings->get_images_per_turn() &&
		100 === foogallery_migrate_migrator_instance()->get_images_per_turn(), 'Normal setting did not persist as 100.' );
	$journal['images_per_turn'] = 100; $save( $journal );
	$result['images_per_turn'] = 100;
}
if ( in_array( $step, array( 'preflight', 'provision', 'switch', 'recover' ), true ) ) {
	$option_prefix = 'migrating' === $journal['phase'] && 10000 === $total &&
		isset( $journal['option_prefix'] ) && true === $journal['option_prefix'] &&
		$journal['option_bytes'] >= 1048576 && $journal['completed'] > 0 && $journal['completed'] < $total;
	real_check( ( 'ready' === $journal['phase'] && 0 === $journal['turns'] && 0 === $journal['completed'] ) ||
		$option_prefix, 'Storage enablement requires an untouched fixture or pinned large unfinished option prefix.' );
	$result['source'] = $verify_source( $journal, false );
	if ( $option_prefix ) {
		$before_storage = StorageAdmin::status();
		real_check( ( 'option' === $before_storage['backend'] && 'stable' === $before_storage['phase'] ) ||
			( 'recover' === $step && 'stable' !== $before_storage['phase'] &&
				in_array( $before_storage['backend'], array( 'option', 'table' ), true ) &&
				'table' === $before_storage['target'] ),
			'Pinned option conversion authority is not recoverable.' );
		real_check(
			'' === $before_storage['problem'], 'Pinned option conversion authority is not healthy.' );
		$result['option_before'] = $option_measure( $journal, 'recover' === $step );
		// The pinned raw SQL hash already commits the verified queue/progress.
		// Reading through the engine here would admit a request-long option
		// writer and correctly block the maintenance gate below. A separate
		// fresh status request proves the queue before and after conversion.
	}
	$before_questions = $questions(); $started = microtime( true );
	$confirm = 'recover' === $step ? 'recover-storage' :
		( 'provision' === $step ? 'provision-shared-schema' : 'switch-to-table' );
	$storage_result = StorageCli::execute( $step, array( 'target' => 'table', 'confirm' => $confirm ) );
	$result['elapsed_seconds'] = round( microtime( true ) - $started, 3 );
	$result['session_questions_delta'] = $questions() - $before_questions;
	$result['peak_allocated_bytes'] = memory_get_peak_usage( true );
	$result['storage'] = StorageAdmin::status();
	if ( $option_prefix && 'table' === $result['storage']['backend'] && 'stable' === $result['storage']['phase'] ) {
		$result['retained_option'] = $option_measure( $journal, true );
		// An engine loaded before the switch may still cache option authority in
		// this request. Status and the first table turn prove progress afresh.
		$result['fresh_table_proof_required'] = true;
	}
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
		if ( 'migrating' === $journal['phase'] && isset( $journal['option_prefix'] ) ) {
			$result['retained_option'] = $option_measure( $journal, true );
			$result['progress_before'] = $verify_progress( $journal, $engine );
		}
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
if ( 'reverse' === $step ) {
	real_check( in_array( $journal['phase'], array( 'edited', 'reversing' ), true ) &&
		$total === (int) $journal['completed'] && isset( $journal['edit'], $journal['destination'], $journal['uid'] ),
		'Only completed, edited owned table migration may reverse.' );
	$result['source'] = $verify_source( $journal, false );
	$storage = StorageAdmin::status();
	real_check( 'verified' === $storage['schema'] && '' === $storage['problem'] &&
		( ( 'stable' === $storage['phase'] && in_array( $storage['backend'], array( 'table', 'option' ), true ) ) ||
			( in_array( $storage['backend'], array( 'table', 'option' ), true ) && 'option' === $storage['target'] ) ),
		'Owned verified table-to-option transition required.' );
	if ( 'stable' === $storage['phase'] ) {
		real_check( $journal['edit'] === foogallery_migrate_migrator_instance()->get_migrator_setting( $owner ),
			'Current edited setting differs before reversal.' );
	}
	$tables = array_keys( ( new LegacyStorageSchema( $wpdb ) )->definitions() );
	$heads = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS n, COALESCE(SUM(payload_bytes),0) AS bytes FROM `' . $tables[0] . '` WHERE blog_id=%d', get_current_blog_id() ), ARRAY_A );
	$rows = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS n, COALESCE(SUM(payload_bytes),0) AS bytes, COALESCE(MAX(payload_bytes),0) AS largest FROM `' . $tables[1] . '` WHERE blog_id=%d', get_current_blog_id() ), ARRAY_A );
	real_check( ! $wpdb->last_error && is_array( $heads ) && is_array( $rows ), 'Current table dimensions unavailable.' );
	$result['table_dimensions'] = array( 'heads' => (int) $heads['n'], 'head_payload_bytes' => (int) $heads['bytes'],
		'rows' => (int) $rows['n'], 'row_payload_bytes' => (int) $rows['bytes'], 'largest_row_bytes' => (int) $rows['largest'] );
	$operation = 'stable' === $storage['phase'] ? ( 'table' === $storage['backend'] ? 'switch' : null ) : 'recover';
	$before_questions = $questions(); $started = microtime( true );
	$storage_result = null === $operation ? null : StorageCli::execute( $operation, array( 'target' => 'option',
		'confirm' => 'switch' === $operation ? 'switch-to-option' : 'recover-storage' ) );
	$result['elapsed_seconds'] = round( microtime( true ) - $started, 3 );
	$result['session_questions_delta'] = $questions() - $before_questions;
	$result['peak_allocated_bytes'] = memory_get_peak_usage( true );
	$result['storage'] = StorageAdmin::status();
	if ( is_wp_error( $storage_result ) ) {
		$result['failure'] = array( 'code' => $storage_result->get_error_code(), 'message' => $storage_result->get_error_message() );
	} else {
		if ( null !== $storage_result ) { $result['storage_result'] = $storage_result; }
		if ( 'stable' === $result['storage']['phase'] && 'option' === $result['storage']['backend'] ) {
			$current = $raw_option( FOOGALLERY_MIGRATE_OPTION_DATA );
			$backup = $raw_option( StorageAuthority::STORAGE_BACKUP_OPTION );
			real_check( is_string( $current ) && is_string( $backup ) && $current !== $backup,
				'Reversal did not publish CURRENT option data distinct from the original backup.' );
			$decoded = maybe_unserialize( $current );
			real_check( is_array( $decoded ) && array_key_exists( $owner, $decoded ) &&
				$journal['edit'] === $decoded[$owner], 'CURRENT option bytes lost the exact table edit.' );
			unset( $decoded );
			$result['current_option_bytes'] = strlen( $current );
			$result['current_option_sha256'] = hash( 'sha256', $current );
			$journal['phase'] = 'reversed'; $save( $journal );
		} else {
			real_check( in_array( $result['storage']['backend'], array( 'table', 'option' ), true ) && 'option' === $result['storage']['target'] &&
				'stable' !== $result['storage']['phase'], 'Reverse transition changed authority unexpectedly.' );
			$journal['phase'] = 'reversing'; $save( $journal );
		}
		$result['phase'] = $journal['phase'];
	}
}
echo json_encode( $result, JSON_UNESCAPED_SLASHES ) . "\n";
exit( isset( $result['failure'] ) ? 1 : 0 );
