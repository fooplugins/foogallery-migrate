<?php
/**
 * Owned vE1HfS only. Two fresh WP-CLI requests, after source QA:
 * wp --path=/private/tmp/foogallery-migrate-r0.vE1HfS eval-file tests/integration/r2g-native-target-acceptance.php /private/tmp/foogallery-migrate-r0.vE1HfS check --user=fgmr0admin
 * wp --path=/private/tmp/foogallery-migrate-r0.vE1HfS eval-file tests/integration/r2g-native-target-acceptance.php /private/tmp/foogallery-migrate-r0.vE1HfS retry --user=fgmr0admin
 * A pending marker is evidence, not permission to replay. No media is removed.
 */
use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use FooPlugins\FooGalleryMigrate\LegacyTableBackend;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;
use FooPlugins\FooGalleryMigrate\StorageAdmin;
use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\Objects\Migratable;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! isset( $args ) || count( $args ) !== 2 ||
	! in_array( $args[1], array( 'check', 'retry' ), true ) ||
	realpath( $args[0] ) !== '/private/tmp/foogallery-migrate-r0.vE1HfS' ) {
	throw new RuntimeException( 'Exact owned vE1HfS site and R2g phase required.' );
}
global $r2g_records_table, $verify_units, $r2g_source_hash, $r2g_gallery_id, $r2g_image_id;
$r2g_phase = $args[1]; $r2g_site = $args[0];
ini_set( 'memory_limit', '256M' );
if ( ini_get( 'memory_limit' ) !== '256M' ) { throw new RuntimeException( 'Effective 256M request limit required.' ); }
// The accepted source verifier checks manifest, live container/DB, source files,
// source adapter order, admin identity, repository and package/source identity.
$args = array( $r2g_site, 'verify-source' ); ob_start();
require __DIR__ . '/storage-release-many-roots.php';
$r2g_source_json = trim( ob_get_clean() ); $args = array( $r2g_site, $r2g_phase );
$r2g_source = json_decode( $r2g_source_json, true );
many_roots_check( is_array( $r2g_source ) && isset( $r2g_source['source'] ) &&
	$r2g_source['phase'] === 'ready' && $r2g_source['expected'] === 121 &&
	$r2g_source['memory_limit'] === '256M', 'Guarded full source proof is missing.' );
$r2g_source_hash = hash( 'sha256', serialize( $r2g_source['source'] ) );
$r2g_status = StorageAdmin::status();
many_roots_check( $r2g_status['backend'] === 'table' && $r2g_status['phase'] === 'stable' &&
	$r2g_status['problem'] === '' && ini_get( 'memory_limit' ) === '256M', 'Stable table authority differs.' );
global $wpdb;
$r2g_identity = hash( 'sha256', $site . '|' . $manifest['database'] . '|' . $manifest['container_id'] );
$r2g_uid = 'gallery_AIGPL_245'; $r2g_gallery_id = 247; $r2g_image_id = 244;
$r2g_journal_key = 'fgm_r2g_native_target_v1'; $r2g_marker_key = 'fgm_r2g_native_target_pending_v1';
$r2g_records_table = $wpdb->base_prefix . StorageAuthority::SHARED_RECORDS_TABLE_SUFFIX;
many_roots_check( preg_match( '/^[A-Za-z0-9_]+$/D', $r2g_records_table ) &&
	isset( ( new LegacyStorageSchema( $wpdb ) )->definitions()[$r2g_records_table] ),
	'Owned records table identity differs.' );

function r2g_option( $name ) {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT option_value FROM `' . $wpdb->options . '` WHERE option_name=%s', $name ), ARRAY_A );
	if ( ! is_array( $rows ) || $wpdb->last_error || count( $rows ) > 1 ) { throw new RuntimeException( 'Exact option SQL read failed.' ); }
	return array( 'found' => count( $rows ) === 1,
		'value' => count( $rows ) ? maybe_unserialize( $rows[0]['option_value'] ) : null,
		'raw' => count( $rows ) ? $rows[0]['option_value'] : null );
}
function r2g_save( $name, $value, $new ) {
	$before = r2g_option( $name );
	if ( $new !== ! $before['found'] ) { throw new RuntimeException( 'Journal/marker presence differs before write.' ); }
	$written = $new ? add_option( $name, $value, '', 'no' ) : update_option( $name, $value, false );
	$after = r2g_option( $name );
	if ( ( $new && ! $written ) || ! $after['found'] || $after['value'] !== $value ||
		get_option( $name ) !== $value ) { throw new RuntimeException( 'Journal/marker durable readback failed.' ); }
}
function r2g_backend() {
	global $wpdb;
	return new LegacyTableBackend( new LegacyTransactionConnection( $wpdb ), get_current_blog_id() );
}
function r2g_candidate() {
	$root = realpath( dirname( dirname( __DIR__ ) ) );
	$files = array( 'migrate.php', 'includes/class-migrator-engine.php',
		'includes/class-migratable-collection-repository.php', 'includes/class-legacy-table-backend.php',
		'includes/class-legacy-table-repository.php', 'tests/integration/storage-release-many-roots.php',
		'tests/integration/r2g-native-target-acceptance.php' );
	$result = array();
	foreach ( $files as $file ) {
		$path = $root . '/' . $file;
		if ( ! is_file( $path ) || is_link( $path ) ) { throw new RuntimeException( 'R2g candidate source path differs.' ); }
		$result[$file] = hash_file( 'sha256', $path );
	}
	return $result;
}
/** Exact raw row hashes; fixed metadata count/bytes before reading any payload. */
function r2g_rows( $bucket ) {
	global $wpdb, $r2g_records_table;
	$context = r2g_backend()->collection_view_context( $bucket );
	if ( ! $context['found'] || $context['total'] !== 121 ) { throw new RuntimeException( 'Owned collection root count differs.' ); }
	$head = $context['head'];
	$where = 'blog_id=%d AND state_id=%d AND generation=%d';
	$args = array( get_current_blog_id(), $head['id'], $head['active_generation'] );
	$size = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS rows_count, COALESCE(SUM(OCTET_LENGTH(payload) + OCTET_LENGTH(mutable_payload) + OCTET_LENGTH(key_value) '
		. '+ COALESCE(OCTET_LENGTH(legacy_identifier_value),0) + COALESCE(OCTET_LENGTH(source_plugin_value),0) + COALESCE(OCTET_LENGTH(source_id_value),0)),0) AS bytes_count '
		. 'FROM `' . $r2g_records_table . '` WHERE ' . $where, $args ), ARRAY_A );
	if ( ! is_array( $size ) || $wpdb->last_error || (int) $size['rows_count'] !== $head['record_count'] ||
		(int) $size['rows_count'] > 500 || (int) $size['bytes_count'] > 4194304 ) { throw new RuntimeException( 'Owned record metadata exceeds bounded proof.' ); }
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM `' . $r2g_records_table . '` WHERE ' . $where . ' ORDER BY id LIMIT 501', $args ), ARRAY_A );
	if ( ! is_array( $rows ) || $wpdb->last_error || count( $rows ) !== (int) $size['rows_count'] ) { throw new RuntimeException( 'Owned raw record read differs.' ); }
	$result = array();
	foreach ( $rows as $row ) {
		$key = bin2hex( $row['node_hash'] );
		if ( strlen( $key ) !== 64 || isset( $result[$key] ) ) { throw new RuntimeException( 'Duplicate/invalid record hash.' ); }
		if ( $bucket === 'galleries' && null !== $row['parent_node_hash'] ) { throw new RuntimeException( 'Gallery fixture has unexpected nested records.' ); }
		$result[$key] = hash( 'sha256', serialize( $row ) );
	}
	ksort( $result, SORT_STRING );
	return array( 'head' => $head, 'rows' => $result );
}
function r2g_posts() {
	global $wpdb;
	$rows = $wpdb->get_results( 'SELECT ID, post_type FROM `' . $wpdb->posts . '` ORDER BY ID LIMIT 501', ARRAY_A );
	if ( ! is_array( $rows ) || $wpdb->last_error || count( $rows ) >= 501 ) { throw new RuntimeException( 'Owned post inventory is unbounded.' ); }
	$result = array(); foreach ( $rows as $row ) { $result[(int) $row['ID']] = $row['post_type']; }
	return $result;
}
function r2g_history( $engine, $root_uid, $image_uid ) {
	$result = array();
	foreach ( array( 'root' => $root_uid, 'image' => $image_uid ) as $key => $uid ) {
		$object = $engine->get_migrated_object( $uid );
		if ( ! is_object( $object ) || ! isset( $object->migrated_id, $object->migration_status ) ) { throw new RuntimeException( 'Target history is absent.' ); }
		$result[$key] = array( 'id' => (int) $object->migrated_id, 'status' => $object->migration_status,
			'migrated' => (bool) $object->migrated, 'error' => $object->has_error() );
	}
	return $result;
}
function r2g_snapshot( $uid, $image_uid ) {
	$backend = r2g_backend(); $context = $backend->targeted_collection_context( 'galleries', $uid );
	if ( ! $context['found'] ) { throw new RuntimeException( 'Exact gallery root is absent.' ); }
	$codec = new LegacyStateCodec();
	$target_hash = $codec->node_hash( $codec->key_hash( 'galleries' ), null, $context['root']['key'] );
	$gallery = r2g_rows( 'galleries' ); $albums = r2g_rows( 'albums' );
	if ( ! isset( $gallery['rows'][$target_hash] ) ) { throw new RuntimeException( 'Target physical root is absent.' ); }
	$unrelated = $gallery['rows']; unset( $unrelated[$target_hash] );
	return array( 'root_key' => $context['root']['key'], 'root' => $context['root']['value'],
		'target_hash' => $target_hash, 'gallery_head' => $gallery['head'],
		'unrelated_rows' => $unrelated, 'album_head' => $albums['head'], 'album_rows' => $albums['rows'],
		'gallery_state' => $backend->read( 'galleries-state' ), 'album_state' => $backend->read( 'albums-state' ),
		'history' => r2g_history( foogallery_migrate_migrator_instance(), $uid, $image_uid ),
		'posts' => r2g_posts() );
}
function r2g_protected( $before, $after ) {
	global $verify_units, $r2g_source_hash, $r2g_gallery_id, $r2g_image_id;
	many_roots_check( $before['root_key'] === $after['root_key'] &&
		$before['target_hash'] === $after['target_hash'] &&
		$before['unrelated_rows'] === $after['unrelated_rows'] &&
		$before['album_head'] === $after['album_head'] && $before['album_rows'] === $after['album_rows'] &&
		$before['album_state'] === $after['album_state'] && $before['posts'] === $after['posts'] &&
		isset( $after['root']['migrated_id'] ) && (int) $after['root']['migrated_id'] === $r2g_gallery_id &&
		$after['history']['root']['id'] === $r2g_gallery_id && $after['history']['image']['id'] === $r2g_image_id &&
		get_post_meta( $r2g_gallery_id, FOOGALLERY_META_ATTACHMENTS, true ) === array( $r2g_image_id ) &&
		hash( 'sha256', serialize( $verify_units( true ) ) ) === $r2g_source_hash,
		'Unrelated roots, album, output IDs, or protected source changed.' );
}

$r2g_backup = r2g_option( StorageAuthority::STORAGE_BACKUP_OPTION );
many_roots_check( $r2g_backup['found'] && is_string( $r2g_backup['raw'] ) &&
	strlen( $r2g_backup['raw'] ) === 142064 &&
	hash( 'sha256', $r2g_backup['raw'] ) === '71071fb837280ec98f9a370a450b607249b28f11312888965d82e11d11e0341d',
	'Retained option backup identity differs.' );
$r2g_backup_hash = hash( 'sha256', $r2g_backup['raw'] );
$r2g_prior = r2g_option( 'fgm_r2e_native_turn_v1' );
many_roots_check( $r2g_prior['found'] && is_array( $r2g_prior['value'] ) &&
	$r2g_prior['value']['phase'] === 'album_done' &&
	$r2g_prior['value']['runs']['gallery']['uid'] === $r2g_uid &&
	$r2g_prior['value']['runs']['gallery']['history_ids'] === array( 'root' => $r2g_gallery_id, 'image' => $r2g_image_id ) &&
	! r2g_option( 'fgm_r2e_native_turn_pending_v1' )['found'], 'Accepted R2e gallery/album journal differs.' );
$r2g_image_uid = $r2g_prior['value']['runs']['gallery']['children']['image'];
$r2g_candidate = r2g_candidate();
$r2g_expected = array( 'includes/class-migrator-engine.php' => '3cf269f8325ec188b6962b8c235c06c29bfd21e0dbde794d1d1cd3717b2e639e',
	'includes/class-migratable-collection-repository.php' => 'b1f8d10bd35188a6c814115e70aabc0ad34e7af5c1255e902e7e98de3f8ffb8e',
	'includes/class-legacy-table-backend.php' => '3e51230e2c522a5be56aabcc3cb6788e53ca586d1bb7fc60c6d70ac67b28a0b6',
	'includes/class-legacy-table-repository.php' => 'be10141b0c44f665a97c7c6c8383ff5a5059d2f1e835b5552d16c01ec995fb31' );
foreach ( $r2g_expected as $file => $hash ) { many_roots_check( $r2g_candidate[$file] === $hash, 'Unreviewed R2g production candidate differs.' ); }
$r2g_journal_row = r2g_option( $r2g_journal_key ); $r2g_marker_row = r2g_option( $r2g_marker_key );
many_roots_check( ! $r2g_marker_row['found'], 'Pending R2g operation requires manual inspection; no replay.' );
$r2g_engine = foogallery_migrate_migrator_instance();
$r2g_before = r2g_snapshot( $r2g_uid, $r2g_image_uid );
$r2g_gallery_post = get_post( $r2g_gallery_id ); $r2g_image_post = get_post( $r2g_image_id );
many_roots_check( $r2g_before['history']['root']['id'] === $r2g_gallery_id &&
	$r2g_before['history']['image']['id'] === $r2g_image_id &&
	$r2g_gallery_post && $r2g_gallery_post->post_type === FOOGALLERY_CPT_GALLERY &&
	$r2g_image_post && $r2g_image_post->post_type === 'attachment', 'Pinned target destination differs.' );

if ( $r2g_phase === 'check' ) {
	many_roots_check( ! $r2g_journal_row['found'] &&
		$r2g_before['history']['root']['status'] === Migratable::PROGRESS_COMPLETED &&
		$r2g_before['history']['image']['status'] === Migratable::PROGRESS_COMPLETED &&
		$r2g_before['root']['migration_status'] === Migratable::PROGRESS_COMPLETED &&
		$r2g_before['gallery_state'] === array( 'found' => true,
			'value' => array( 'queued' => 1, 'completed' => 1, 'progress' => 100 ) ),
		'R2g initial state is not completed and unclaimed.' );
	$url = wp_get_attachment_url( $r2g_image_id );
	many_roots_check( is_string( $url ) && '' !== $url && is_file( get_attached_file( $r2g_image_id ) ),
		'Exact target attachment URL or file is missing.' );
	$r2g_journal = array( 'identity' => $r2g_identity, 'phase' => 'check_pending',
		'source' => $r2g_source_hash, 'backup' => $r2g_backup_hash, 'candidate' => $r2g_candidate,
		'uid' => $r2g_uid, 'image_uid' => $r2g_image_uid, 'url' => $url, 'before' => $r2g_before );
	r2g_save( $r2g_journal_key, $r2g_journal, true );
	r2g_save( $r2g_marker_key, array( 'identity' => $r2g_identity, 'phase' => 'check_pending' ), true );
	$head_hits = 0;
	$filter = function ( $preempt, $request, $request_url ) use ( &$head_hits, $url ) {
		if ( $request_url === $url && isset( $request['method'] ) && $request['method'] === 'HEAD' ) {
			++$head_hits;
			return array( 'response' => array( 'code' => 404, 'message' => 'Not Found' ), 'headers' => array(), 'body' => '' );
		}
		return $preempt;
	};
	add_filter( 'pre_http_request', $filter, 10, 3 );
	try { $result = $r2g_engine->check_gallery_migration_errors( $r2g_uid ); }
	finally { remove_filter( 'pre_http_request', $filter, 10 ); }
	many_roots_check( ! is_wp_error( $result ) && $result === array( 'checked' => 1, 'errors' => 1 ) &&
		$head_hits === 1 && ini_get( 'memory_limit' ) === '256M',
		'Exact target HEAD 404 did not produce one checked error under 256M.' );
	$r2g_backup_after = r2g_option( StorageAuthority::STORAGE_BACKUP_OPTION );
	many_roots_check( $r2g_backup_after['found'] && is_string( $r2g_backup_after['raw'] ) &&
		$r2g_backup_after['raw'] === $r2g_backup['raw'] && strlen( $r2g_backup_after['raw'] ) === 142064 &&
		hash( 'sha256', $r2g_backup_after['raw'] ) === $r2g_backup_hash,
		'Retained option backup changed after targeted check.' );
	$after = r2g_snapshot( $r2g_uid, $r2g_image_uid );
	r2g_protected( $r2g_before, $after );
	many_roots_check( $after['root']['migration_status'] === Migratable::PROGRESS_ERROR &&
		$after['history']['root']['status'] === Migratable::PROGRESS_ERROR &&
		$after['history']['image']['status'] === Migratable::PROGRESS_ERROR &&
		$after['history']['root']['error'] && $after['history']['image']['error'] &&
		$after['gallery_state'] === $r2g_before['gallery_state'] &&
		$after['gallery_head']['revision'] > $r2g_before['gallery_head']['revision'],
		'Targeted check did not publish exact root/history error with legacy summary unchanged.' );
	$r2g_journal['checked'] = $after; $r2g_journal['phase'] = 'checked';
	r2g_save( $r2g_journal_key, $r2g_journal, false );
	$r2g_marker = r2g_option( $r2g_marker_key );
	many_roots_check( $r2g_marker['found'] &&
		$r2g_marker['value'] === array( 'identity' => $r2g_identity, 'phase' => 'check_pending' ) &&
		get_option( $r2g_marker_key ) === $r2g_marker['value'] &&
		delete_option( $r2g_marker_key ) && ! r2g_option( $r2g_marker_key )['found'],
		'R2g check marker did not clear.' );
	echo wp_json_encode( array( 'phase' => 'checked', 'result' => $result, 'head_hits' => $head_hits,
		'root_revision' => $after['gallery_head']['revision'], 'history' => $after['history'],
		'source_sha256' => $r2g_source_hash, 'backup_sha256' => $r2g_backup_hash,
		'peak_bytes' => memory_get_peak_usage( true ), 'memory_limit' => ini_get( 'memory_limit' ) ) ) . "\n";
	return;
}

many_roots_check( $r2g_journal_row['found'] && is_array( $r2g_journal_row['value'] ) &&
	$r2g_journal_row['value']['phase'] === 'checked', 'R2g retry requires completed check journal.' );
$r2g_journal = $r2g_journal_row['value'];
many_roots_check( $r2g_journal['identity'] === $r2g_identity && $r2g_journal['source'] === $r2g_source_hash &&
	$r2g_journal['backup'] === $r2g_backup_hash && $r2g_journal['candidate'] === $r2g_candidate &&
	$r2g_journal['uid'] === $r2g_uid && $r2g_journal['image_uid'] === $r2g_image_uid &&
	$r2g_journal['checked'] === $r2g_before &&
	$r2g_before['history']['root']['status'] === Migratable::PROGRESS_ERROR &&
	$r2g_before['history']['image']['status'] === Migratable::PROGRESS_ERROR &&
	wp_get_attachment_url( $r2g_image_id ) === $r2g_journal['url'],
	'Fresh request target, source, checkpoint, or candidate differs.' );
$r2g_journal['phase'] = 'retry_pending'; r2g_save( $r2g_journal_key, $r2g_journal, false );
r2g_save( $r2g_marker_key, array( 'identity' => $r2g_identity, 'phase' => 'retry_pending' ), true );
$result = $r2g_engine->retry_gallery_migration( $r2g_uid );
many_roots_check( true === $result && ini_get( 'memory_limit' ) === '256M',
	'Public targeted retry failed or effective memory changed; pending marker retained.' );
$r2g_backup_after = r2g_option( StorageAuthority::STORAGE_BACKUP_OPTION );
many_roots_check( $r2g_backup_after['found'] && is_string( $r2g_backup_after['raw'] ) &&
	$r2g_backup_after['raw'] === $r2g_backup['raw'] && strlen( $r2g_backup_after['raw'] ) === 142064 &&
	hash( 'sha256', $r2g_backup_after['raw'] ) === $r2g_backup_hash,
	'Retained option backup changed after targeted retry.' );
$after = r2g_snapshot( $r2g_uid, $r2g_image_uid );
r2g_protected( $r2g_before, $after );
many_roots_check( $after['root']['migration_status'] === Migratable::PROGRESS_COMPLETED &&
	! empty( $after['root']['migrated'] ) && (int) $after['root']['migrated_id'] === $r2g_gallery_id &&
	$after['history']['root']['status'] === Migratable::PROGRESS_COMPLETED &&
	$after['history']['image']['status'] === Migratable::PROGRESS_COMPLETED &&
	! $after['history']['root']['error'] && ! $after['history']['image']['error'] &&
	$after['gallery_head']['revision'] > $r2g_before['gallery_head']['revision'] &&
	$after['gallery_state'] === array( 'found' => true,
		'value' => array( 'queued' => 1, 'completed' => 1, 'progress' => 100 ) ),
	'Fresh retry did not complete with original destination and summary.' );
$r2g_journal['retried'] = $after; $r2g_journal['phase'] = 'done';
r2g_save( $r2g_journal_key, $r2g_journal, false );
$r2g_marker = r2g_option( $r2g_marker_key );
many_roots_check( $r2g_marker['found'] &&
	$r2g_marker['value'] === array( 'identity' => $r2g_identity, 'phase' => 'retry_pending' ) &&
	get_option( $r2g_marker_key ) === $r2g_marker['value'] &&
	delete_option( $r2g_marker_key ) && ! r2g_option( $r2g_marker_key )['found'],
	'R2g retry marker did not clear.' );
echo wp_json_encode( array( 'phase' => 'done', 'root_revision' => $after['gallery_head']['revision'],
	'history' => $after['history'], 'post_count' => count( $after['posts'] ),
	'source_sha256' => $r2g_source_hash, 'backup_sha256' => $r2g_backup_hash,
	'peak_bytes' => memory_get_peak_usage( true ), 'memory_limit' => ini_get( 'memory_limit' ) ) ) . "\n";
