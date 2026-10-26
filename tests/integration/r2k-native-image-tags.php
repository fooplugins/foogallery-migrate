<?php
/**
 * Test-only R2k.2 native tag journey. Run one phase per fresh WP-CLI request,
 * only after independent source/runner review on a newly created R0 source site:
 * wp --path=/private/tmp/foogallery-migrate-r0.SUFFIX --user=fgmr0admin eval-file tests/integration/r2k-native-image-tags.php /private/tmp/foogallery-migrate-r0.SUFFIX seed
 * Phases: seed, preflight, provision, switch, recover, status, start,
 * fault-continue, continue, verify.
 * No phase deletes posts, tables, queue evidence, or plugin files.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! isset( $args ) || count( $args ) !== 2 ||
	! in_array( $args[1], array( 'seed', 'preflight', 'provision', 'switch', 'recover', 'status', 'start', 'fault-continue', 'continue', 'verify' ), true ) ) {
	throw new RuntimeException( 'Exact owned WP-CLI tag phase required.' );
}
$r2k_site = realpath( $args[0] ); $r2k_phase = $args[1];
if ( ! $r2k_site || ! preg_match( '~^/(?:private/)?tmp/foogallery-migrate-r0\.[A-Za-z0-9]+$~D', $r2k_site ) ||
	realpath( ABSPATH ) !== $r2k_site ) { throw new RuntimeException( 'Wrong disposable WordPress site.' ); }
$r2k_manifest_file = $r2k_site . '/.fgm-r0-owner.json';
$r2k_manifest = is_file( $r2k_manifest_file ) && ! is_link( $r2k_manifest_file ) ?
	json_decode( file_get_contents( $r2k_manifest_file ), true ) : null;
$r2k_repo = realpath( dirname( dirname( __DIR__ ) ) );
if ( ! is_array( $r2k_manifest ) || ! isset( $r2k_manifest['kind'], $r2k_manifest['site'], $r2k_manifest['database'],
	$r2k_manifest['host'], $r2k_manifest['repo'], $r2k_manifest['container'], $r2k_manifest['container_id'] ) ||
	$r2k_manifest['kind'] !== 'foogallery-migrate-r0-v1' || realpath( $r2k_manifest['site'] ) !== $r2k_site ||
	! preg_match( '/^fgm_r0_[a-z0-9]+$/D', $r2k_manifest['database'] ) ||
	! preg_match( '/^127\.0\.0\.1:[0-9]{2,5}$/D', $r2k_manifest['host'] ) ||
	! preg_match( '/^fgm-r0-[A-Za-z0-9]+$/D', $r2k_manifest['container'] ) ||
	! preg_match( '/^[a-f0-9]{64}$/D', $r2k_manifest['container_id'] ) ||
	realpath( $r2k_manifest['repo'] ) !== $r2k_repo || isset( $r2k_manifest['package'] ) ||
	! is_link( $r2k_site . '/wp-content/plugins/foogallery-migrate' ) ||
	realpath( $r2k_site . '/wp-content/plugins/foogallery-migrate' ) !== $r2k_repo ) {
	throw new RuntimeException( 'Owned source fixture manifest or plugin link differs.' );
}
$r2k_live = array(); $r2k_code = 0;
exec( 'docker inspect --format ' . escapeshellarg( '{{.Id}} {{.State.Running}} {{index .Config.Labels "foogallery-migrate-r0"}}' ) .
	' ' . escapeshellarg( $r2k_manifest['container'] ) . ' 2>/dev/null', $r2k_live, $r2k_code );
if ( 0 !== $r2k_code || count( $r2k_live ) !== 1 ||
	trim( $r2k_live[0] ) !== $r2k_manifest['container_id'] . ' true owned' ) {
	throw new RuntimeException( 'Live owned SQL container differs.' );
}
ini_set( 'memory_limit', '256M' );
add_filter( 'admin_memory_limit', function () { return '256M'; } );
add_filter( 'image_memory_limit', function () { return '256M'; } );
global $wpdb;
function r2k_tag_check( $okay, $label ) { if ( ! $okay ) { throw new RuntimeException( $label ); } }
r2k_tag_check( ini_get( 'memory_limit' ) === '256M' && ! is_multisite() &&
	DB_NAME === $r2k_manifest['database'] && DB_HOST === $r2k_manifest['host'] &&
	$wpdb->get_var( 'SELECT DATABASE()' ) === $r2k_manifest['database'] && ! $wpdb->last_error &&
	get_current_user_id() > 0 && wp_get_current_user()->user_login === 'fgmr0admin' &&
	current_user_can( 'manage_options' ) && function_exists( 'foogallery_migrate_migrator_instance' ),
	'Loaded site, SQL, user, plugin, or effective memory differs.' );

use FooPlugins\FooGalleryMigrate\ImageTagQueueRepository;
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use FooPlugins\FooGalleryMigrate\LegacyTableBackend;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;
use FooPlugins\FooGalleryMigrate\MigratedHistoryRepository;
use FooPlugins\FooGalleryMigrate\MigratorEngine;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use FooPlugins\FooGalleryMigrate\StorageAdmin;
use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\StorageCli;
use FooPlugins\FooGalleryMigrate\Objects\Image;
use FooPlugins\FooGalleryMigrate\Plugins\Nextgen;

if ( ! defined( 'FOOGALLERY_ATTACHMENT_TAXONOMY_TAG' ) ) { define( 'FOOGALLERY_ATTACHMENT_TAXONOMY_TAG', 'foogallery_attachment_tag' ); }
register_taxonomy( 'ngg_tag', 'attachment' );
register_taxonomy( FOOGALLERY_ATTACHMENT_TAXONOMY_TAG, 'attachment' );
$r2k_owner = 'fgm-r2k-tags-' . substr( $r2k_site, strrpos( $r2k_site, '.' ) + 1 );
$r2k_marker = '_fgm_r2k_native_tags_owner'; $r2k_journal_key = 'fgm_r2k_native_tags_v1';
$r2k_identity = hash( 'sha256', $r2k_site . '|' . $r2k_manifest['database'] . '|' . $r2k_manifest['container_id'] );
$r2k_pins = array();
foreach ( array( 'includes/class-migrator-engine.php', 'includes/class-migrated-history-repository.php',
	'includes/class-legacy-table-repository.php', 'includes/class-image-tag-queue-repository.php',
	'includes/class-migrator-settings.php', 'includes/plugins/class-nextgen.php',
	'includes/objects/class-image.php', 'tests/integration/r2k-native-image-tags.php' ) as $file ) {
	$hash = hash_file( 'sha256', $r2k_repo . '/' . $file );
	r2k_tag_check( is_string( $hash ), 'Candidate source pin could not be read.' );
	$r2k_pins[$file] = $hash;
}
$r2k_picture = $wpdb->prefix . 'ngg_pictures'; $r2k_gallery = $wpdb->prefix . 'ngg_gallery';
r2k_tag_check( preg_match( '/^[A-Za-z0-9_]+$/D', $wpdb->prefix ) &&
	preg_match( '/^[A-Za-z0-9_]+$/D', $wpdb->base_prefix ), 'Owned SQL table prefix is unsafe.' );
$r2k_raw = function ( $name ) use ( $wpdb ) {
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_value FROM `{$wpdb->options}` WHERE option_name=%s LIMIT 2", $name ), ARRAY_A );
	r2k_tag_check( is_array( $rows ) && ! $wpdb->last_error && count( $rows ) <= 1, 'Raw owned option read failed or duplicated.' );
	return count( $rows ) ? $rows[0]['option_value'] : null;
};
$r2k_save = function ( $value ) use ( $r2k_journal_key, $r2k_raw ) {
	update_option( $r2k_journal_key, $value, false );
	$raw = $r2k_raw( $r2k_journal_key );
	r2k_tag_check( is_string( $raw ) && maybe_unserialize( $raw ) === $value && get_option( $r2k_journal_key, null ) === $value,
		'Owned tag journal durable readback failed.' );
};
$r2k_rows = function ( $ids ) use ( $wpdb ) {
	$result = array(); foreach ( array_chunk( $ids, 100 ) as $page ) {
		$placeholders = implode( ',', array_fill( 0, count( $page ), '%d' ) );
		$sql = "SELECT ID,post_type,post_status,post_title,post_content,guid FROM `{$wpdb->posts}` WHERE ID IN ($placeholders) ORDER BY ID";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $page ), ARRAY_A );
		r2k_tag_check( is_array( $rows ) && ! $wpdb->last_error && count( $rows ) === count( $page ), 'Owned post page changed.' );
		$result = array_merge( $result, $rows );
	} return $result;
};
$r2k_source_proof = function ( $journal ) use ( $wpdb, $r2k_marker, $r2k_owner, $r2k_rows, $r2k_picture, $r2k_gallery ) {
	r2k_tag_check( is_array( $journal['source_ids'] ) && count( $journal['source_ids'] ) === 154 &&
		is_array( $journal['target_ids'] ) && count( $journal['target_ids'] ) === 151,
		'Owned tag source dimensions differ.' );
	$ids = array_merge( $journal['source_ids'], $journal['target_ids'] );
	r2k_tag_check( count( array_unique( $ids ) ) === count( $ids ) &&
		$r2k_rows( $ids ) === $journal['post_rows'] &&
		$r2k_rows( array_column( $journal['baseline'], 'ID' ) ) === $journal['baseline'],
		'Owned or protected post identity/content changed.' );
	foreach ( $ids as $id ) { r2k_tag_check( get_post_meta( $id, $r2k_marker, true ) === $r2k_owner, 'Owned post marker changed.' ); }
	$owned = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$wpdb->postmeta}` WHERE meta_key=%s AND meta_value=%s", $r2k_marker, $r2k_owner ) );
	r2k_tag_check( ! $wpdb->last_error && (int) $owned === count( $ids ), 'Owned post count changed.' );
	$terms = array(); foreach ( $journal['source_ids'] as $id ) {
		$names = wp_get_object_terms( $id, 'ngg_tag', array( 'fields' => 'names' ) );
		r2k_tag_check( is_array( $names ) && ! is_wp_error( $names ), 'Owned source tag read failed.' );
		sort( $names, SORT_STRING ); $terms[] = array( $id, $names );
	}
	$pictures = $wpdb->get_results( "SELECT pid,filename,galleryid FROM `$r2k_picture` ORDER BY pid", ARRAY_A );
	$galleries = $wpdb->get_results( "SELECT gid,path FROM `$r2k_gallery` ORDER BY gid", ARRAY_A );
	r2k_tag_check( is_array( $pictures ) && is_array( $galleries ) && ! $wpdb->last_error &&
		hash( 'sha256', serialize( array( $terms, $pictures, $galleries ) ) ) === $journal['source_hash'],
		'Owned source terms or NextGEN adapter rows changed.' );
};
$r2k_journal_raw = $r2k_raw( $r2k_journal_key );
$r2k_journal = null === $r2k_journal_raw ? null : maybe_unserialize( $r2k_journal_raw );
if ( 'seed' === $r2k_phase ) {
	r2k_tag_check( null === $r2k_journal_raw && null === get_option( $r2k_journal_key, null ), 'Seed requires absent owned journal.' );
	$baseline_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$wpdb->posts}`" );
	r2k_tag_check( ! $wpdb->last_error && $baseline_count <= 5, 'Fresh source site has too many pre-existing posts.' );
	$baseline_ids = $wpdb->get_col( "SELECT ID FROM `{$wpdb->posts}` ORDER BY ID" );
	$baseline = $r2k_rows( array_map( 'intval', $baseline_ids ) );
	$next_post_id = $wpdb->get_var( $wpdb->prepare(
		'SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $wpdb->posts ) );
	r2k_tag_check( ! $wpdb->last_error && ! in_array( '9', array_map( 'strval', $baseline_ids ), true ) &&
		is_numeric( $next_post_id ) && (int) $next_post_id > 0 && (int) $next_post_id <= 9,
		'Fresh fixture cannot own numeric NextGEN source image 9 without touching protected posts.' );
	$owned = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$wpdb->postmeta}` WHERE meta_key=%s", $r2k_marker ) );
	r2k_tag_check( ! $wpdb->last_error && (int) $owned === 0, 'Owned marker collision.' );
	foreach ( array( $r2k_picture, $r2k_gallery ) as $table ) {
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		r2k_tag_check( ! $wpdb->last_error && null === $found, 'Test-only NextGEN source table collision.' );
	}
	foreach ( array( FOOGALLERY_MIGRATE_OPTION_DATA, 'foogallery-migrate-settings', 'foogallery_migrate_storage',
		'foogallery-migrate-migrated-legacy-backup', StorageAuthority::CONTROL_OPTION,
		StorageAuthority::AUTHORITY_OPTION, StorageAuthority::MAINTENANCE_OPTION,
		StorageAuthority::CLEANUP_OPTION, StorageAuthority::STORAGE_BACKUP_OPTION,
		LegacyStorageSchema::VERSION_OPTION, LegacyStorageSchema::LOCK_OPTION,
		'foogallery-migrate-store-schema-version', 'foogallery-migrate-store-migration-version' ) as $name ) {
		r2k_tag_check( null === $r2k_raw( $name ), 'Seed refuses existing plugin storage.' );
	}
	foreach ( array( 'foogallery_migrate_legacy_state', 'foogallery_migrate_legacy_records', 'foogallery_migrate_objects', 'foogallery_migrate_state' ) as $suffix ) {
		$table = $wpdb->base_prefix . $suffix;
		r2k_tag_check( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) && ! $wpdb->last_error,
			'Seed refuses plugin table remnant.' );
	}
	r2k_tag_check( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$wpdb->options}` WHERE option_name LIKE %s", $wpdb->esc_like( 'foogallery_migrate_storage_writer_' ) . '%' ) ) === 0 && ! $wpdb->last_error,
		'Seed refuses live writer marker.' );
	$r2k_journal = array( 'identity' => $r2k_identity, 'phase' => 'seeding', 'baseline' => $baseline,
		'source_pins' => $r2k_pins );
	r2k_tag_check( add_option( $r2k_journal_key, $r2k_journal, '', false ), 'Owned tag journal claim failed.' );
	$r2k_save( $r2k_journal );
	// These are test-only source adapter tables, not migration storage schema.
	r2k_tag_check( false !== $wpdb->query( "CREATE TABLE `$r2k_gallery` (gid BIGINT UNSIGNED NOT NULL PRIMARY KEY,path VARCHAR(255) NOT NULL) ENGINE=InnoDB" ) &&
		false !== $wpdb->query( "CREATE TABLE `$r2k_picture` (pid BIGINT UNSIGNED NOT NULL PRIMARY KEY,filename VARCHAR(255) NOT NULL,galleryid BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB" ),
		'Owned NextGEN adapter table creation failed.' );
	r2k_tag_check( 1 === $wpdb->insert( $r2k_gallery, array( 'gid' => 1, 'path' => 'r2k' ), array( '%d', '%s' ) ), 'Owned gallery source row failed.' );
	$create = function ( $label ) use ( $r2k_marker, $r2k_owner ) {
		$id = wp_insert_attachment( array( 'post_title' => $label, 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit', 'guid' => site_url( '/r2k/' . rawurlencode( $label ) . '.jpg' ) ) );
		r2k_tag_check( is_int( $id ) && $id > 0 && add_post_meta( $id, $r2k_marker, $r2k_owner, true ), 'Owned synthetic attachment or marker failed.' );
		return $id;
	};
	$sources = array(); for ( $i = 0; $i < 152; ++$i ) { $sources[] = $create( 'source-' . $i ); }
	$targets = array(); for ( $i = 0; $i < 151; ++$i ) { $targets[] = $create( 'target-' . $i ); }
	$extra_case = $create( 'adapter-case' ); $extra_lower = $create( 'adapter-lower' );
	r2k_tag_check( in_array( 9, $sources, true ), 'The fresh owned source posts must include numeric source 9.' );
	$r2k_source_ids = array_merge( $sources, array( $extra_case, $extra_lower ) );
	$usable = array_values( array_diff( $sources, array( 9 ) ) );
	$plugin = new Nextgen(); $plugin->is_detected = true; $history = array(); $expected = array(); $source_index = 0;
	for ( $i = 0; $i < 152; ++$i ) {
		$id = 101 === $i ? '009' : $usable[$source_index++];
		$target = $targets[ $i <= 100 ? $i : ( 101 === $i ? 0 : $i - 1 ) ];
		$url = 0 === $i || 102 === $i ? site_url( '/r2k/Case.jpg' ) : site_url( '/r2k/source-' . $i . '.jpg' );
		$tag = 101 === $i ? 'numeric-tag' : 'tag-' . $i;
		$r2k_result = wp_set_object_terms( absint( $id ), array( $tag ), 'ngg_tag', false );
		r2k_tag_check( ! is_wp_error( $r2k_result ), 'Owned NextGEN source tag failed.' );
		$image = new Image( $plugin ); $image->ID = $id; $image->migrated_id = $target;
		$image->migrated = 101 !== $i; $image->source_url = $url; $image->migration_status = 'completed';
		$history['r2k-' . $i] = $image;
		if ( ! isset( $expected[$target] ) ) { $expected[$target] = array(); }
		$expected[$target][] = $tag;
	}
	foreach ( array( array( 9, '9.jpg', 'numeric-tag' ), array( $extra_case, 'Case.jpg', 'fallback-case' ),
		array( $extra_lower, 'case.jpg', 'lower-unmatched' ) ) as $fallback ) {
		if ( 9 !== $fallback[0] ) { r2k_tag_check( ! is_wp_error( wp_set_object_terms( $fallback[0], array( $fallback[2] ), 'ngg_tag', false ) ), 'Owned fallback tag failed.' ); }
		r2k_tag_check( 1 === $wpdb->insert( $r2k_picture, array( 'pid' => $fallback[0], 'filename' => $fallback[1], 'galleryid' => 1 ), array( '%d', '%s', '%d' ) ), 'Owned fallback picture failed.' );
	}
	$expected[$targets[0]][] = 'fallback-case';
	r2k_tag_check( ! is_wp_error( wp_set_object_terms( $targets[0], array( 'Existing' ), FOOGALLERY_ATTACHMENT_TAXONOMY_TAG, false ) ), 'Destination preexisting tag failed.' );
	$engine = foogallery_migrate_migrator_instance();
	r2k_tag_check( $engine->set_migrator_setting( MigratorEngine::KEY_PLUGINS, array( $plugin ) ) &&
		$engine->set_migrator_setting( MigratorEngine::KEY_MIGRATED, $history ) &&
		$engine->set_migrator_setting( MigratorEngine::KEY_MIGRATED_REVISION, 1 ), 'Synthetic option history seed failed.' );
	$engine->save_settings( array( 'images_per_turn' => 150 ) );
	r2k_tag_check( 150 === (int) $engine->get_images_per_turn(), 'Ordinary images-per-turn setting did not save.' );
	$terms = array(); foreach ( $r2k_source_ids as $id ) { $names = wp_get_object_terms( $id, 'ngg_tag', array( 'fields' => 'names' ) ); sort( $names, SORT_STRING ); $terms[] = array( $id, $names ); }
	$pictures = $wpdb->get_results( "SELECT pid,filename,galleryid FROM `$r2k_picture` ORDER BY pid", ARRAY_A );
	$galleries = $wpdb->get_results( "SELECT gid,path FROM `$r2k_gallery` ORDER BY gid", ARRAY_A );
	$r2k_journal = array( 'identity' => $r2k_identity, 'phase' => 'seeded', 'baseline' => $baseline,
		'source_pins' => $r2k_pins,
		'source_ids' => $r2k_source_ids, 'target_ids' => $targets,
		'post_rows' => $r2k_rows( array_merge( $r2k_source_ids, $targets ) ),
		'source_hash' => hash( 'sha256', serialize( array( $terms, $pictures, $galleries ) ) ),
		'option_hash' => hash( 'sha256', $r2k_raw( FOOGALLERY_MIGRATE_OPTION_DATA ) ),
		'expected' => $expected, 'target_first' => $targets[0], 'target_last' => $targets[150] );
	$r2k_save( $r2k_journal );
	echo wp_json_encode( array( 'phase' => 'seeded', 'history' => count( $history ), 'targets' => count( $targets ),
		'option_hash' => $r2k_journal['option_hash'], 'peak' => memory_get_peak_usage( true ) ) ) . "\n";
	return;
}
r2k_tag_check( is_array( $r2k_journal ) && isset( $r2k_journal['identity'], $r2k_journal['phase'],
	$r2k_journal['source_hash'], $r2k_journal['option_hash'], $r2k_journal['expected'], $r2k_journal['source_pins'] ) &&
	$r2k_journal['identity'] === $r2k_identity &&
	$r2k_journal['source_pins'] === $r2k_pins &&
	in_array( $r2k_journal['phase'], array( 'seeded', 'start-pending', 'started', 'fault-pending', 'faulted', 'continue-pending', 'complete' ), true ) &&
	get_option( $r2k_journal_key, null ) === $r2k_journal,
	'Owned native tag journal is absent or in a failed/pending phase.' );
$r2k_source_proof( $r2k_journal );
$r2k_status = StorageAdmin::status();
$r2k_stable = $r2k_status['backend'] === 'table' && $r2k_status['phase'] === 'stable' &&
	$r2k_status['schema'] === 'verified' && $r2k_status['problem'] === '';
$r2k_current = $r2k_raw( FOOGALLERY_MIGRATE_OPTION_DATA );
$r2k_backup = $r2k_raw( StorageAuthority::STORAGE_BACKUP_OPTION );
if ( $r2k_stable ) {
	r2k_tag_check( null === $r2k_current && is_string( $r2k_backup ) &&
		hash( 'sha256', $r2k_backup ) === $r2k_journal['option_hash'],
		'Current option or retained original source changed.' );
} elseif ( 'stable' === $r2k_status['phase'] ) {
	r2k_tag_check( $r2k_status['backend'] === 'option' && is_string( $r2k_current ) &&
		hash( 'sha256', $r2k_current ) === $r2k_journal['option_hash'], 'Pre-conversion option changed.' );
} else {
	r2k_tag_check( $r2k_status['target'] === 'table' &&
		( is_string( $r2k_current ) || is_string( $r2k_backup ) ) &&
		( ! is_string( $r2k_current ) || hash( 'sha256', $r2k_current ) === $r2k_journal['option_hash'] ) &&
		( ! is_string( $r2k_backup ) || hash( 'sha256', $r2k_backup ) === $r2k_journal['option_hash'] ),
		'In-flight conversion lost or changed its pinned source.' );
}
if ( in_array( $r2k_phase, array( 'preflight', 'provision', 'switch', 'recover' ), true ) ) {
	r2k_tag_check( $r2k_journal['phase'] === 'seeded', 'Conversion cannot start after tag output.' );
	$confirm = 'recover' === $r2k_phase ? 'recover-storage' : ( 'provision' === $r2k_phase ? 'provision-shared-schema' : 'switch-to-table' );
	$result = StorageCli::execute( $r2k_phase, array( 'target' => 'table', 'confirm' => $confirm ) );
	r2k_tag_check( ! is_wp_error( $result ) && ini_get( 'memory_limit' ) === '256M', 'Public storage operation failed or raised memory ceiling.' );
	echo wp_json_encode( array( 'operation' => $r2k_phase, 'status' => StorageAdmin::status(), 'result' => $result,
		'peak' => memory_get_peak_usage( true ) ) ) . "\n"; return;
}
if ( 'status' === $r2k_phase ) {
	echo wp_json_encode( array( 'status' => $r2k_status, 'journal' => $r2k_journal['phase'], 'peak' => memory_get_peak_usage( true ) ) ) . "\n"; return;
}
r2k_tag_check( $r2k_stable, 'Stable verified table authority required for native tag output.' );
$r2k_backend = new LegacyTableBackend( new LegacyTransactionConnection( $wpdb ), get_current_blog_id() );
$r2k_queue = new ImageTagQueueRepository( $r2k_backend, new MigratorSettings( $r2k_backend ) );
$r2k_history = new MigratedHistoryRepository( $r2k_backend, new MigratorSettings( $r2k_backend ) );
if ( 'start' === $r2k_phase ) {
	r2k_tag_check( $r2k_journal['phase'] === 'seeded' && ! $r2k_queue->summary()['found'], 'Start requires untouched table queue.' );
	$pin = $r2k_history->tag_build_identity();
	$numeric = $r2k_history->tag_numeric_targets( array( array( 'type' => 'image', 'plugin' => 'NextGen', 'id' => '009' ) ), $pin );
	$url_started = microtime( true );
	$urls = $r2k_history->tag_source_urls( array( site_url( '/r2k/Case.jpg' ), site_url( '/r2k/case.jpg' ) ), $pin );
	$url_elapsed = round( microtime( true ) - $url_started, 3 );
	r2k_tag_check( $numeric === array( $r2k_journal['target_first'] ) && $urls === array( $r2k_journal['target_first'], 0 ),
		'Native numeric false-flag or first exact URL lookup differs.' );
	$r2k_journal['phase'] = 'start-pending'; $r2k_save( $r2k_journal );
	$started = microtime( true ); $result = foogallery_migrate_migrator_instance()->start_image_tag_sync();
	r2k_tag_check( ! is_wp_error( $result ) && $result['total'] === 151 && $result['processed'] === 150 &&
		$result['updated'] === 150 && $result['unmatched'] === 1 && ! $result['complete'] &&
		ini_get( 'memory_limit' ) === '256M', 'Public first 150-image tag turn failed.' );
	$summary = $r2k_queue->summary();
	r2k_tag_check( $summary['found'] && $summary['total'] === 151 && $summary['summary']['processed'] === 150,
		'Published indexed tag queue summary differs.' );
	$ordered = array(); $cursor = null; $pages = 0;
	do {
		$previous = $cursor; $page = $r2k_queue->nextBatch( 100, $cursor ); ++$pages;
		r2k_tag_check( is_array( $page ) && isset( $page['rows'] ) && is_array( $page['rows'] ) &&
			count( $page['rows'] ) > 0 && count( $page['rows'] ) <= 100 && $pages <= 3,
			'Bounded queue page is malformed or repeated.' );
		foreach ( $page['rows'] as $row ) { $ordered[] = $row['value']; }
		$cursor = $page['cursor'];
		r2k_tag_check( null === $cursor || $cursor !== $previous, 'Bounded queue cursor did not advance.' );
	} while ( null !== $cursor );
	r2k_tag_check( count( $ordered ) === 151 && array_column( $ordered, 'attachment_id' ) === $r2k_journal['target_ids'] &&
		$ordered[0]['tags'] === $r2k_journal['expected'][$r2k_journal['target_first']],
		'Indexed queue order, first metadata or cross-page tag merge differs.' );
	$r2k_journal['phase'] = 'started'; $r2k_journal['backup_hash'] = hash( 'sha256', $r2k_raw( StorageAuthority::STORAGE_BACKUP_OPTION ) );
	$r2k_save( $r2k_journal );
	echo wp_json_encode( array( 'phase' => 'started', 'result' => $result, 'numeric' => $numeric, 'urls' => $urls,
		'queue' => count( $ordered ), 'elapsed' => round( microtime( true ) - $started, 3 ),
		'url_lookup_elapsed' => $url_elapsed,
		'peak' => memory_get_peak_usage( true ) ) ) . "\n"; return;
}
if ( 'fault-continue' === $r2k_phase ) {
	r2k_tag_check( $r2k_journal['phase'] === 'started', 'Fault phase requires the first 150-item turn.' );
	$before = $r2k_queue->summary();
	r2k_tag_check( $before['found'] && $before['summary']['processed'] === 150 && $before['total'] === 151,
		'Pre-fault queue checkpoint differs.' );
	$last = $r2k_journal['target_last'];
	$prior = wp_get_object_terms( $last, FOOGALLERY_ATTACHMENT_TAXONOMY_TAG, array( 'fields' => 'names' ) );
	r2k_tag_check( is_array( $prior ) && ! is_wp_error( $prior ) && count( $prior ) === 0,
		'Final owned target already has migrated terms.' );
	$state_table = $wpdb->base_prefix . 'foogallery_migrate_legacy_state';
	$codec = new LegacyStateCodec(); $key_hash = strtoupper( $codec->key_hash( ImageTagQueueRepository::STATE_KEY ) );
	$heads = $wpdb->get_results( $wpdb->prepare( "SELECT id,revision FROM `$state_table` WHERE blog_id=%d AND HEX(state_key_hash)=%s LIMIT 2",
		get_current_blog_id(), $key_hash ), ARRAY_A );
	r2k_tag_check( is_array( $heads ) && ! $wpdb->last_error && count( $heads ) === 1 &&
		(int) $heads[0]['revision'] === $before['revision'], 'Exact queue head for owned checkpoint fault is absent.' );
	$head_id = (int) $heads[0]['id'];
	$trigger = 'fgm_r2k_tags_' . strtolower( substr( $r2k_site, strrpos( $r2k_site, '.' ) + 1 ) );
	$trigger_count = function () use ( $wpdb, $trigger ) {
		$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=%s', $trigger ) );
		r2k_tag_check( ! $wpdb->last_error && null !== $count, 'Owned fault trigger inventory failed.' );
		return (int) $count;
	};
	r2k_tag_check( 0 === $trigger_count() && $head_id > 0 && $wpdb->dbh instanceof mysqli,
		'Owned fault trigger collision or SQL handle mismatch.' );
	$handle = $wpdb->dbh; $thread = $handle->thread_id;
	$r2k_journal['phase'] = 'fault-pending'; $r2k_journal['fault_trigger'] = $trigger;
	$r2k_journal['fault_head'] = $head_id; $r2k_save( $r2k_journal );
	$ddl = 'CREATE TRIGGER `' . $trigger . '` BEFORE UPDATE ON `' . $state_table . '` FOR EACH ROW BEGIN '
		. 'IF OLD.id=' . $head_id . ' AND OLD.blog_id=' . (int) get_current_blog_id() .
		" AND HEX(OLD.state_key_hash)='" . $key_hash . "' AND CONNECTION_ID()=" . (int) $thread . ' THEN '
		. 'SET @fgm_r2k_tags_checkpoint_fault=COALESCE(@fgm_r2k_tags_checkpoint_fault,0)+1; '
		. "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='R2k owned tag checkpoint fault'; END IF; END";
	$created = false; $dropped = false; $fired = null; $failure = null; $result = null;
	try {
		r2k_tag_check( mysqli_query( $handle, 'SET @fgm_r2k_tags_checkpoint_fault=0' ) && mysqli_query( $handle, $ddl ),
			'Owned checkpoint fault trigger could not be created.' );
		$created = true;
		try { $result = foogallery_migrate_migrator_instance()->continue_image_tag_sync(); }
		catch ( Throwable $error ) { $failure = $error->getMessage(); }
		r2k_tag_check( $wpdb->dbh === $handle && $handle->thread_id === $thread,
			'Pinned SQL handle changed during checkpoint fault.' );
		$counter = mysqli_query( $handle, 'SELECT @fgm_r2k_tags_checkpoint_fault AS fired' );
		if ( $counter instanceof mysqli_result ) { $fired = (int) $counter->fetch_assoc()['fired']; $counter->free(); }
	} finally {
		if ( $created && $wpdb->dbh === $handle && $handle->thread_id === $thread ) {
			$dropped = (bool) mysqli_query( $handle, 'DROP TRIGGER `' . $trigger . '`' );
		}
	}
	r2k_tag_check( $created && $dropped && 0 === $trigger_count() && 1 === $fired && ! is_array( $result ),
		'Late owned checkpoint fault did not fire once or release its trigger.' );
	$after = $r2k_queue->summary();
	$terms = wp_get_object_terms( $last, FOOGALLERY_ATTACHMENT_TAXONOMY_TAG, array( 'fields' => 'names' ) );
	r2k_tag_check( $after === $before && is_array( $terms ) && ! is_wp_error( $terms ) &&
		$terms === $r2k_journal['expected'][$last],
		'Failed checkpoint did not retain exact prior queue while appending the final real term.' );
	$r2k_journal['phase'] = 'faulted'; $r2k_journal['fault_fired'] = $fired;
	$r2k_journal['fault_error'] = $failure; $r2k_save( $r2k_journal );
	echo wp_json_encode( array( 'phase' => 'faulted', 'trigger_fired' => $fired, 'checkpoint' => $after,
		'last_target_terms' => $terms, 'error' => $failure, 'peak' => memory_get_peak_usage( true ) ) ) . "\n"; return;
}
if ( 'continue' === $r2k_phase ) {
	r2k_tag_check( $r2k_journal['phase'] === 'faulted' && isset( $r2k_journal['fault_fired'] ) &&
		$r2k_journal['fault_fired'] === 1, 'Continue requires one proven failed checkpoint.' );
	$r2k_journal['phase'] = 'continue-pending'; $r2k_save( $r2k_journal );
	$started = microtime( true ); $result = foogallery_migrate_migrator_instance()->continue_image_tag_sync();
	r2k_tag_check( ! is_wp_error( $result ) && $result['total'] === 151 && $result['processed'] === 151 &&
		$result['updated'] === 151 && $result['complete'] && ini_get( 'memory_limit' ) === '256M',
		'Fresh-process final tag turn failed.' );
	$r2k_journal['phase'] = 'complete'; $r2k_save( $r2k_journal );
	echo wp_json_encode( array( 'phase' => 'complete', 'result' => $result,
		'elapsed' => round( microtime( true ) - $started, 3 ), 'peak' => memory_get_peak_usage( true ) ) ) . "\n"; return;
}
r2k_tag_check( $r2k_phase === 'verify' && $r2k_journal['phase'] === 'complete', 'Final proof requires completed owned tag journey.' );
$summary = $r2k_queue->summary();
r2k_tag_check( $summary['found'] && $summary['total'] === 151 && $summary['summary']['processed'] === 151 &&
	$summary['summary']['updated'] === 151 && hash( 'sha256', $r2k_raw( StorageAuthority::STORAGE_BACKUP_OPTION ) ) === $r2k_journal['backup_hash'],
	'Final durable queue or retained source differs.' );
foreach ( $r2k_journal['target_ids'] as $id ) {
	$names = wp_get_object_terms( $id, FOOGALLERY_ATTACHMENT_TAXONOMY_TAG, array( 'fields' => 'names' ) );
	r2k_tag_check( is_array( $names ) && ! is_wp_error( $names ), 'Destination terms unavailable.' );
	sort( $names, SORT_STRING ); $expected = $r2k_journal['expected'][$id];
	if ( $id === $r2k_journal['target_first'] ) { $expected[] = 'Existing'; }
	sort( $expected, SORT_STRING );
	r2k_tag_check( $names === array_values( array_unique( $expected ) ), 'Destination term append differs at attachment ' . $id );
}
$r2k_source_proof( $r2k_journal );
echo wp_json_encode( array( 'phase' => 'verified', 'source_hash' => $r2k_journal['source_hash'],
	'backup_hash' => $r2k_journal['backup_hash'], 'target_count' => count( $r2k_journal['target_ids'] ),
	'queue' => $summary, 'peak' => memory_get_peak_usage( true ) ) ) . "\n";
