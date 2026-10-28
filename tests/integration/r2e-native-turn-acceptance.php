<?php
/**
 * Fixture-only, guarded R2e turn fault/retry proof. Never run on a Local site.
 *
 * wp --path=/tmp/foogallery-migrate-r0.vE1HfS eval-file \
 *   tests/integration/r2e-native-turn-acceptance.php \
 *   /tmp/foogallery-migrate-r0.vE1HfS queue-gallery --user=fgmr0admin
 * Then: fault-gallery, retry-gallery, queue-album, fault-album, retry-album.
 * Every phase is a separate WP-CLI process. A pending marker requires manual
 * inspection; the script never takes over an uncertain trigger or retry.
 */
use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use FooPlugins\FooGalleryMigrate\LegacyTableBackend;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;
use FooPlugins\FooGalleryMigrate\MigratedHistoryRepository;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use FooPlugins\FooGalleryMigrate\StorageAdmin;
use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\Objects\Migratable;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! isset( $args ) || count( $args ) !== 2 ||
	! in_array( $args[1], array( 'queue-gallery', 'fault-gallery', 'retry-gallery',
		'queue-album', 'fault-album', 'retry-album' ), true ) ) {
	throw new RuntimeException( 'Exact owned site and one R2e phase required.' );
}
$r2e_phase = $args[1]; $r2e_site_arg = $args[0];
ini_set( 'memory_limit', '256M' );
if ( ini_get( 'memory_limit' ) !== '256M' ) { throw new RuntimeException( 'Effective request memory limit differs.' ); }
if ( realpath( $r2e_site_arg ) !== '/private/tmp/foogallery-migrate-r0.vE1HfS' ) {
	throw new RuntimeException( 'R2e proof is bound to the retained vE1HfS fixture.' );
}
// Reuse its accepted manifest/container/repository/admin/256M/source-file guard.
$args = array( $r2e_site_arg, 'verify-source' );
ob_start();
require __DIR__ . '/storage-release-many-roots.php';
$r2e_source_proof = trim( ob_get_clean() );
$args = array( $r2e_site_arg, $r2e_phase );
if ( ! is_string( $r2e_source_proof ) || '' === $r2e_source_proof ) { throw new RuntimeException( 'Source proof missing.' ); }
$r2e_source_hash = hash( 'sha256', $r2e_source_proof );
$r2e_status = StorageAdmin::status();
many_roots_check( $r2e_status['backend'] === 'table' && $r2e_status['phase'] === 'stable' &&
	$r2e_status['problem'] === '' && ini_get( 'memory_limit' ) === '256M', 'Stable table authority or actual 256M limit differs.' );
global $wpdb;
$r2e_identity = hash( 'sha256', $site . '|' . $manifest['database'] . '|' . $manifest['container_id'] );
$r2e_journal_key = 'fgm_r2e_native_turn_v1';
$r2e_marker_key = 'fgm_r2e_native_turn_pending_v1';
$r2e_trigger = 'fgm_r2e_vE1HfS_checkpoint';
$r2e_table = $wpdb->base_prefix . StorageAuthority::SHARED_STATE_TABLE_SUFFIX;
$r2e_schema = new \FooPlugins\FooGalleryMigrate\LegacyStorageSchema( $wpdb );
many_roots_check( isset( $r2e_schema->definitions()[$r2e_table] ) &&
	preg_match( '/^[A-Za-z0-9_]+$/D', $r2e_table ) &&
	$wpdb->dbh instanceof mysqli, 'Expected owned shared state table and mysqli handle required.' );
$r2e_handle = $wpdb->dbh; $r2e_thread = $r2e_handle->thread_id;

function r2e_raw_option( $name ) {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT option_value FROM `' . $wpdb->options . '` WHERE option_name = %s', $name ), ARRAY_A );
	if ( ! is_array( $rows ) || $wpdb->last_error || count( $rows ) > 1 ) { throw new RuntimeException( 'R2e journal SQL read failed.' ); }
	return array( 'found' => 1 === count( $rows ),
		'value' => count( $rows ) ? maybe_unserialize( $rows[0]['option_value'] ) : null );
}
function r2e_save( $name, $value, $new ) {
	$ok = $new ? add_option( $name, $value, '', 'no' ) : update_option( $name, $value, false );
	$raw = r2e_raw_option( $name );
	if ( ! $ok || ! $raw['found'] || $raw['value'] !== $value || get_option( $name ) !== $value ) {
		throw new RuntimeException( 'R2e journal write/readback failed.' );
	}
}
function r2e_trigger_count( $name ) {
	global $wpdb;
	$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = %s', $name ) );
	if ( $wpdb->last_error || ! is_numeric( $count ) ) { throw new RuntimeException( 'Trigger inventory failed.' ); }
	return (int) $count;
}
function r2e_output_map() {
	global $wpdb;
	$sql = $wpdb->prepare( 'SELECT p.ID, p.post_type, m.meta_value AS source_key FROM `' . $wpdb->posts . '` p '
		. 'JOIN `' . $wpdb->postmeta . '` m ON m.post_id = p.ID WHERE m.meta_key = %s '
		. 'AND p.post_type IN (%s,%s,%s) ORDER BY p.ID, m.meta_id LIMIT 501',
		Migratable::META_SOURCE_KEY, FOOGALLERY_CPT_GALLERY, FOOGALLERY_CPT_ALBUM, 'attachment' );
	$rows = $wpdb->get_results( $sql, ARRAY_A );
	if ( ! is_array( $rows ) || $wpdb->last_error || count( $rows ) >= 501 ) { throw new RuntimeException( 'Owned output map is unbounded or unreadable.' ); }
	$result = array();
	foreach ( $rows as $row ) {
		$key = $row['post_type'] . ':' . $row['source_key'];
		if ( isset( $result[$key] ) ) { throw new RuntimeException( 'Duplicate migrated output identity.' ); }
		$result[$key] = (int) $row['ID'];
	}
	ksort( $result, SORT_STRING ); return $result;
}
function r2e_destination_inventory() {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT ID, post_type, post_status, post_title FROM `' . $wpdb->posts . '` '
		. 'WHERE post_type IN (%s,%s,%s) ORDER BY ID LIMIT 501',
		FOOGALLERY_CPT_GALLERY, FOOGALLERY_CPT_ALBUM, 'attachment' ), ARRAY_A );
	if ( ! is_array( $rows ) || $wpdb->last_error || count( $rows ) >= 501 ) { throw new RuntimeException( 'Destination inventory is unbounded or unreadable.' ); }
	$result = array();
	foreach ( $rows as $row ) {
		$id = (int) $row['ID'];
		if ( $id < 1 || isset( $result[$id] ) ) { throw new RuntimeException( 'Duplicate or invalid destination post ID.' ); }
		$result[$id] = array( 'type' => $row['post_type'], 'status' => $row['post_status'], 'title' => $row['post_title'] );
	}
	return $result;
}
function r2e_backup_identity() {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT option_value FROM `' . $wpdb->options . '` WHERE option_name = %s',
		StorageAuthority::STORAGE_BACKUP_OPTION ), ARRAY_A );
	if ( ! is_array( $rows ) || $wpdb->last_error || count( $rows ) !== 1 || ! is_string( $rows[0]['option_value'] ) ) {
		throw new RuntimeException( 'Retained option backup is absent or unreadable.' );
	}
	return array( 'bytes' => strlen( $rows[0]['option_value'] ), 'sha256' => hash( 'sha256', $rows[0]['option_value'] ) );
}
function r2e_candidate_hashes() {
	$root = realpath( dirname( dirname( __DIR__ ) ) );
	$files = array( 'migrate.php', 'includes/class-migrator-engine.php',
		'includes/class-legacy-table-backend.php', 'includes/class-legacy-table-repository.php',
		'includes/class-migratable-collection-repository.php', 'includes/migrators/class-migrator-base.php',
		'includes/objects/class-migratable.php', 'includes/objects/class-gallery.php',
		'includes/objects/class-album.php', 'includes/objects/class-image.php',
		'tests/integration/storage-release-many-roots.php', 'tests/integration/r2e-native-turn-acceptance.php' );
	$result = array();
	foreach ( $files as $file ) {
		$path = $root . '/' . $file;
		if ( ! is_file( $path ) || is_link( $path ) ) { throw new RuntimeException( 'Candidate source path changed.' ); }
		$result[$file] = hash_file( 'sha256', $path );
	}
	return $result;
}
function r2e_child_uids( $object, $kind ) {
	$object->ensure_children_loaded(); $children = $object->get_children();
	if ( ! is_array( $children ) || count( $children ) !== 1 ) { throw new RuntimeException( 'Expected one source child in owned fixture.' ); }
	$child = reset( $children );
	if ( ! is_object( $child ) || ! method_exists( $child, 'unique_identifier' ) ) { throw new RuntimeException( 'Invalid source child.' ); }
	if ( 'gallery' === $kind ) { return array( 'image' => $child->unique_identifier() ); }
	$child->ensure_children_loaded(); $images = $child->get_children();
	if ( ! is_array( $images ) || count( $images ) !== 1 ) { throw new RuntimeException( 'Expected one nested image in owned album.' ); }
	$image = reset( $images );
	return array( 'gallery' => $child->unique_identifier(), 'image' => $image->unique_identifier() );
}
function r2e_history_ids( $uids ) {
	$backend = r2e_backend();
	$store = new MigratedHistoryRepository( $backend, new MigratorSettings( $backend ) );
	$result = array();
	foreach ( $uids as $name => $uid ) {
		$record = $store->get( $uid );
		if ( ! is_object( $record ) || ! $record->migrated || ! is_int( $record->migrated_id ) || $record->migrated_id < 1 ) {
			throw new RuntimeException( 'Flushed history destination ID is missing for ' . $name . '.' );
		}
		$result[$name] = $record->migrated_id;
	}
	return $result;
}
function r2e_output_membership( $kind, $history_ids, $inventory ) {
	$root_id = $history_ids['root'];
	$type = 'gallery' === $kind ? FOOGALLERY_CPT_GALLERY : FOOGALLERY_CPT_ALBUM;
	if ( ! isset( $inventory[$root_id] ) || $inventory[$root_id]['type'] !== $type ) { throw new RuntimeException( 'Root history ID does not name the expected output post.' ); }
	if ( 'gallery' === $kind ) {
		$attachments = get_post_meta( $root_id, FOOGALLERY_META_ATTACHMENTS, true );
		if ( $attachments !== array( $history_ids['image'] ) ) { throw new RuntimeException( 'Gallery attachment order/identity differs.' ); }
	} else {
		$galleries = get_post_meta( $root_id, FOOGALLERY_ALBUM_META_GALLERIES, true );
		if ( $galleries !== array( $history_ids['gallery'] ) || ! isset( $inventory[$history_ids['gallery']] ) ||
			$inventory[$history_ids['gallery']]['type'] !== FOOGALLERY_CPT_GALLERY ) {
			throw new RuntimeException( 'Album gallery membership/order differs.' );
		}
		$attachments = get_post_meta( $history_ids['gallery'], FOOGALLERY_META_ATTACHMENTS, true );
		if ( $attachments !== array( $history_ids['image'] ) ) { throw new RuntimeException( 'Album child gallery attachment order differs.' ); }
	}
}
function r2e_backend() {
	global $wpdb;
	return new LegacyTableBackend( new LegacyTransactionConnection( $wpdb ), get_current_blog_id() );
}
function r2e_history_revision( $backend ) {
	$row = $backend->read( 'migrated-revision' );
	return $row['found'] ? $row['value'] : 0;
}
function r2e_snapshot( $backend, $bucket, $root_key = null ) {
	if ( null === $root_key ) {
		$context = $backend->migration_turn_context( $bucket );
		if ( ! $context['found'] || null === $context['root'] ) { throw new RuntimeException( 'Expected one runnable root.' ); }
		$root_key = $context['root']['key'];
	}
	$view = $backend->collection_view_context( $bucket );
	$roots = $backend->roots( $bucket, array( $root_key ) );
	if ( ! $view['found'] || count( $roots ) !== 1 || ! $roots[0]['found'] ) { throw new RuntimeException( 'Affected root is absent.' ); }
	$recheck = $backend->collection_view_context( $bucket );
	if ( $view['head'] !== $recheck['head'] ) { throw new RuntimeException( 'Affected root proof changed.' ); }
	$state = $backend->read( $bucket . '-state' );
	return array( 'root_key' => $root_key, 'head_revision' => $view['head']['revision'],
		'root_sha256' => hash( 'sha256', serialize( $roots[0]['value'] ) ),
		'state_sha256' => hash( 'sha256', serialize( $state ) ),
		'root_migrated' => ! empty( $roots[0]['value']['migrated'] ),
		'root_status' => $roots[0]['value']['migration_status'],
		'root_migrated_id' => isset( $roots[0]['value']['migrated_id'] ) ? (int) $roots[0]['value']['migrated_id'] : 0,
		'summary' => $state, 'history_revision' => r2e_history_revision( $backend ),
		'tagged_outputs' => r2e_output_map(), 'inventory' => r2e_destination_inventory() );
}
function r2e_same_checkpoint( $before, $after ) {
	return $before['root_key'] === $after['root_key'] && $before['head_revision'] === $after['head_revision'] &&
		$before['root_sha256'] === $after['root_sha256'] && $before['state_sha256'] === $after['state_sha256'];
}
function r2e_prior_posts_unchanged( $before, $after ) {
	foreach ( $before as $id => $post ) {
		if ( ! isset( $after[$id] ) || $after[$id] !== $post ) { return false; }
	}
	return true;
}
function r2e_report( $phase, $journal, $source_hash, $other = array() ) {
	echo wp_json_encode( array_merge( array( 'phase' => $phase, 'journal_phase' => $journal['phase'],
		'source_proof_sha256' => $source_hash, 'effective_memory' => ini_get( 'memory_limit' ),
		'backup' => $journal['backup'], 'candidate_sha256' => hash( 'sha256', serialize( $journal['candidate'] ) ),
		'peak_bytes' => memory_get_peak_usage( true ) ), $other ) ) . "\n";
}

$r2e_journal_row = r2e_raw_option( $r2e_journal_key );
$r2e_marker_row = r2e_raw_option( $r2e_marker_key );
$r2e_journal = $r2e_journal_row['found'] ? $r2e_journal_row['value'] : null;
$r2e_backup = r2e_backup_identity(); $r2e_candidate = r2e_candidate_hashes();
many_roots_check( $r2e_backup === array( 'bytes' => 142064,
	'sha256' => '71071fb837280ec98f9a370a450b607249b28f11312888965d82e11d11e0341d' ),
	'Retained vE1 option backup identity differs.' );
$r2e_source_document = json_decode( $r2e_source_proof, true );
many_roots_check( is_array( $r2e_source_document ) && isset( $r2e_source_document['source'] ) &&
	$r2e_source_document['source'] === $verify_units( true ), 'Source proof differs on repeat read.' );
if ( $r2e_marker_row['found'] || r2e_trigger_count( $r2e_trigger ) !== 0 ) {
	throw new RuntimeException( 'Pending fault marker or trigger requires manual inspection; no automatic takeover.' );
}
if ( 'queue-gallery' === $r2e_phase ) {
	many_roots_check( ! $r2e_journal_row['found'], 'Existing R2e journal forbids queue replay.' );
	$r2e_engine = foogallery_migrate_migrator_instance();
	$r2e_page = $r2e_engine->table_collection_repository()->view_page( 'galleries', 0, 1 );
	many_roots_check( count( $r2e_page['rows'] ) === 1 && ! $r2e_page['rows'][0]['value']->migrated &&
		$r2e_page['rows'][0]['value']->migration_status === Migratable::PROGRESS_NOT_STARTED,
		'First gallery is not an untouched migration candidate.' );
	$r2e_uid = $r2e_page['rows'][0]['value']->unique_identifier();
	$r2e_children = r2e_child_uids( $r2e_page['rows'][0]['value'], 'gallery' );
	many_roots_check( ! isset( r2e_output_map()[FOOGALLERY_CPT_GALLERY . ':' . $r2e_uid] ), 'Destination already exists.' );
	many_roots_check( $r2e_engine->get_gallery_migrator()->queue_objects_for_migration( array( $r2e_uid => array( 'title' => 'R2e gallery turn proof' ) ) ), 'Public gallery queue failed.' );
	$r2e_journal = array( 'identity' => $r2e_identity, 'source_sha256' => $r2e_source_hash,
		'backup' => $r2e_backup, 'candidate' => $r2e_candidate, 'phase' => 'gallery_queued',
		'runs' => array( 'gallery' => array( 'uid' => $r2e_uid, 'children' => $r2e_children ) ) );
	r2e_save( $r2e_journal_key, $r2e_journal, true );
	r2e_report( $r2e_phase, $r2e_journal, $r2e_source_hash, array( 'uid' => $r2e_uid ) );
	return;
}
many_roots_check( is_array( $r2e_journal ) && isset( $r2e_journal['identity'], $r2e_journal['source_sha256'],
	$r2e_journal['backup'], $r2e_journal['candidate'], $r2e_journal['phase'], $r2e_journal['runs'] ) &&
	$r2e_journal['identity'] === $r2e_identity && $r2e_journal['source_sha256'] === $r2e_source_hash &&
	$r2e_journal['backup'] === $r2e_backup && $r2e_journal['candidate'] === $r2e_candidate,
	'R2e journal, backup, or candidate source hashes differ.' );
$r2e_kind = false !== strpos( $r2e_phase, 'gallery' ) ? 'gallery' : 'album';
$r2e_bucket = $r2e_kind === 'gallery' ? 'galleries' : 'albums';
$r2e_migrator_method = $r2e_kind === 'gallery' ? 'get_gallery_migrator' : 'get_album_migrator';

if ( 'queue-album' === $r2e_phase ) {
	many_roots_check( $r2e_journal['phase'] === 'gallery_done' && ! isset( $r2e_journal['runs']['album'] ), 'Gallery proof must finish first.' );
	$r2e_engine = foogallery_migrate_migrator_instance();
	$r2e_page = $r2e_engine->table_collection_repository()->view_page( 'albums', 0, 1 );
	many_roots_check( count( $r2e_page['rows'] ) === 1 && ! $r2e_page['rows'][0]['value']->migrated &&
		$r2e_page['rows'][0]['value']->migration_status === Migratable::PROGRESS_NOT_STARTED,
		'First album is not an untouched migration candidate.' );
	$r2e_uid = $r2e_page['rows'][0]['value']->unique_identifier();
	$r2e_children = r2e_child_uids( $r2e_page['rows'][0]['value'], 'album' );
	many_roots_check( ! isset( r2e_output_map()[FOOGALLERY_CPT_ALBUM . ':' . $r2e_uid] ), 'Album destination already exists.' );
	many_roots_check( $r2e_engine->get_album_migrator()->queue_objects_for_migration( array( $r2e_uid => array( 'title' => 'R2e album turn proof' ) ) ), 'Public album queue failed.' );
	$r2e_journal['runs']['album'] = array( 'uid' => $r2e_uid, 'children' => $r2e_children ); $r2e_journal['phase'] = 'album_queued';
	r2e_save( $r2e_journal_key, $r2e_journal, false );
	r2e_report( $r2e_phase, $r2e_journal, $r2e_source_hash, array( 'uid' => $r2e_uid ) );
	return;
}

$r2e_expected = $r2e_kind . ( 0 === strpos( $r2e_phase, 'fault-' ) ? '_queued' : '_faulted' );
many_roots_check( $r2e_journal['phase'] === $r2e_expected && isset( $r2e_journal['runs'][$r2e_kind]['uid'] ),
	'R2e phase or selected UID differs.' );
$r2e_uid = $r2e_journal['runs'][$r2e_kind]['uid'];
$r2e_uids = array_merge( array( 'root' => $r2e_uid ), $r2e_journal['runs'][$r2e_kind]['children'] );
$r2e_backend = r2e_backend();
$r2e_before = r2e_snapshot( $r2e_backend, $r2e_bucket );
many_roots_check( isset( $r2e_before['root_key'] ) && is_int( $r2e_before['head_revision'] ) &&
	$r2e_before['head_revision'] > 0, 'Runnable checkpoint identity missing.' );
$r2e_logical_turn = foogallery_migrate_migrator_instance()->table_collection_repository()->migration_turn_context( $r2e_bucket );
many_roots_check( $r2e_logical_turn['found'] && null !== $r2e_logical_turn['root'] &&
	$r2e_logical_turn['root']['key'] === $r2e_before['root_key'] &&
	$r2e_logical_turn['object']->unique_identifier() === $r2e_uid,
	'First runnable root is not the exact journal-owned selection.' );

if ( 0 === strpos( $r2e_phase, 'fault-' ) ) {
	$r2e_head = $r2e_backend->migration_turn_context( $r2e_bucket )['head'];
	$r2e_codec = new LegacyStateCodec(); $r2e_key_hash = $r2e_codec->key_hash( $r2e_bucket );
	$r2e_mark = array( 'identity' => $r2e_identity, 'bucket' => $r2e_bucket,
		'trigger' => $r2e_trigger, 'head_id' => $r2e_head['id'], 'phase' => 'fault_pending' );
	$r2e_journal['runs'][$r2e_kind]['before'] = $r2e_before;
	$r2e_journal['phase'] = $r2e_kind . '_fault_pending';
	r2e_save( $r2e_journal_key, $r2e_journal, false );
	r2e_save( $r2e_marker_key, $r2e_mark, true );
	// DDL is confined to this exact owned table and head. Session variable survives
	// the checkpoint transaction rollback, proving this trigger actually fired.
	$ddl = 'CREATE TRIGGER `' . $r2e_trigger . '` BEFORE UPDATE ON `' . $r2e_table . '` FOR EACH ROW BEGIN '
		. 'IF OLD.id = ' . (int) $r2e_head['id'] . ' AND OLD.blog_id = ' . (int) get_current_blog_id() .
		' AND HEX(OLD.state_key_hash) = ' . "'" . strtoupper( $r2e_key_hash ) . "'" . ' THEN '
		. 'SET @fgm_r2e_checkpoint_fault = COALESCE(@fgm_r2e_checkpoint_fault, 0) + 1; '
		. "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'R2e owned checkpoint fault'; END IF; END";
	$r2e_created = false; $r2e_fired = null; $r2e_error = null; $r2e_result = null; $r2e_dropped = false;
	try {
		if ( ! mysqli_query( $r2e_handle, 'SET @fgm_r2e_checkpoint_fault = 0' ) ||
			! mysqli_query( $r2e_handle, $ddl ) ) { throw new RuntimeException( 'Owned fault trigger creation failed.' ); }
		$r2e_created = true;
		try { $r2e_result = foogallery_migrate_migrator_instance()->$r2e_migrator_method()->migrate(); }
		catch ( Throwable $error ) { $r2e_error = $error->getMessage(); }
		if ( $wpdb->dbh !== $r2e_handle || $r2e_handle->thread_id !== $r2e_thread ) { throw new RuntimeException( 'Pinned SQL handle changed; retain fault evidence.' ); }
		$counter = mysqli_query( $r2e_handle, 'SELECT @fgm_r2e_checkpoint_fault AS fired' );
		if ( $counter instanceof mysqli_result ) { $r2e_fired = (int) $counter->fetch_assoc()['fired']; $counter->free(); }
	} finally {
		if ( $r2e_created && $wpdb->dbh === $r2e_handle && $r2e_handle->thread_id === $r2e_thread ) {
			$r2e_dropped = (bool) mysqli_query( $r2e_handle, 'DROP TRIGGER `' . $r2e_trigger . '`' );
		}
	}
	many_roots_check( $r2e_created && $r2e_dropped && 0 === r2e_trigger_count( $r2e_trigger ) &&
		$r2e_fired === 1 && true !== $r2e_result, 'Checkpoint fault did not fire once and cleanly release its trigger.' );
	$r2e_after = r2e_snapshot( r2e_backend(), $r2e_bucket, $r2e_before['root_key'] );
	many_roots_check( r2e_same_checkpoint( $r2e_before, $r2e_after ) &&
		$r2e_after['history_revision'] > $r2e_before['history_revision'] &&
		$r2e_after['inventory'] !== $r2e_before['inventory'] &&
		r2e_prior_posts_unchanged( $r2e_before['inventory'], $r2e_after['inventory'] ),
		'Real output/history or rollback proof failed.' );
	$r2e_history_ids = r2e_history_ids( $r2e_uids );
	r2e_output_membership( $r2e_kind, $r2e_history_ids, $r2e_after['inventory'] );
	many_roots_check( ! $r2e_after['root_migrated'] && $r2e_after['root_status'] === Migratable::PROGRESS_QUEUED &&
		$r2e_after['root_migrated_id'] === 0 && $r2e_source_document['source'] === $verify_units( true ),
		'Failed checkpoint changed root state or protected source.' );
	$r2e_journal['runs'][$r2e_kind]['faulted'] = $r2e_after;
	$r2e_journal['runs'][$r2e_kind]['history_ids'] = $r2e_history_ids;
	$r2e_journal['runs'][$r2e_kind]['fault_error'] = $r2e_error;
	$r2e_journal['phase'] = $r2e_kind . '_faulted';
	r2e_save( $r2e_journal_key, $r2e_journal, false );
	$r2e_removed_marker = r2e_raw_option( $r2e_marker_key );
	many_roots_check( $r2e_removed_marker['found'] && $r2e_removed_marker['value'] === $r2e_mark &&
		delete_option( $r2e_marker_key ) &&
		! r2e_raw_option( $r2e_marker_key )['found'],
		'Owned pending marker did not clear after fully observed rollback.' );
	r2e_report( $r2e_phase, $r2e_journal, $r2e_source_hash,
		array( 'trigger_fired' => $r2e_fired, 'history_before' => $r2e_before['history_revision'],
			'history_after' => $r2e_after['history_revision'], 'history_ids' => $r2e_history_ids,
			'new_destination_ids' => array_values( array_diff( array_keys( $r2e_after['inventory'] ), array_keys( $r2e_before['inventory'] ) ) ),
			'error' => $r2e_error ) );
	return;
}

$r2e_faulted = $r2e_journal['runs'][$r2e_kind]['faulted'];
many_roots_check( r2e_same_checkpoint( $r2e_journal['runs'][$r2e_kind]['before'], $r2e_before ) &&
	$r2e_faulted === $r2e_before, 'Fresh request does not see exact failed-turn checkpoint/history/output.' );
$r2e_history = foogallery_migrate_migrator_instance()->get_migrated_object( $r2e_uid );
many_roots_check( is_object( $r2e_history ) && $r2e_history->migrated && $r2e_history->migrated_id > 0,
	'Fresh request cannot see the flushed migrated history.' );
$r2e_history_ids = r2e_history_ids( $r2e_uids );
many_roots_check( $r2e_history_ids === $r2e_journal['runs'][$r2e_kind]['history_ids'],
	'Fresh request history destination identities changed.' );
r2e_output_membership( $r2e_kind, $r2e_history_ids, $r2e_before['inventory'] );
$r2e_result = foogallery_migrate_migrator_instance()->$r2e_migrator_method()->migrate();
$r2e_after = r2e_snapshot( r2e_backend(), $r2e_bucket, $r2e_before['root_key'] );
many_roots_check( true === $r2e_result && $r2e_after['head_revision'] > $r2e_before['head_revision'] &&
	$r2e_after['inventory'] === $r2e_before['inventory'] &&
	$r2e_after['tagged_outputs'] === $r2e_before['tagged_outputs'] &&
	$r2e_after['history_revision'] >= $r2e_before['history_revision'],
	'Fresh retry did not checkpoint while reusing exact history/output identities.' );
$r2e_expected_summary = array( 'queued' => 1, 'completed' => 1, 'progress' => 100 );
many_roots_check( $r2e_after['root_migrated'] && $r2e_after['root_status'] === Migratable::PROGRESS_COMPLETED &&
	$r2e_after['root_migrated_id'] === $r2e_history_ids['root'] && $r2e_after['summary']['found'] &&
	$r2e_after['summary']['value'] === $r2e_expected_summary &&
	$r2e_history_ids === r2e_history_ids( $r2e_uids ),
	'Fresh retry root, summary, or history destination ID differs.' );
r2e_output_membership( $r2e_kind, $r2e_history_ids, $r2e_after['inventory'] );
many_roots_check( $r2e_source_document['source'] === $verify_units( true ), 'Protected source changed after retry.' );
$r2e_journal['runs'][$r2e_kind]['retried'] = $r2e_after;
$r2e_journal['phase'] = $r2e_kind . '_done';
r2e_save( $r2e_journal_key, $r2e_journal, false );
r2e_report( $r2e_phase, $r2e_journal, $r2e_source_hash,
	array( 'uid' => $r2e_uid, 'history_ids' => $r2e_history_ids,
		'inventory_count' => count( $r2e_after['inventory'] ),
		'history_revision' => $r2e_after['history_revision'],
		'final_source_verified' => 'album' === $r2e_kind ) );
