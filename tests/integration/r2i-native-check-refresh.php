<?php
/**
 * R2i public check-all and forced-refresh proof on retained vE1HfS only.
 * Each step is a fresh WP-CLI process. Only exact operator-attest-check may
 * reconcile the retained failed test receipt; it never replays check-all.
 * Frozen source hashes are pinned below and must match before any write.
 *
 * wp --path=/private/tmp/foogallery-migrate-r0.vE1HfS eval-file tests/integration/r2i-native-check-refresh.php /private/tmp/foogallery-migrate-r0.vE1HfS inspect --user=fgmr0admin
 * wp --path=/private/tmp/foogallery-migrate-r0.vE1HfS eval-file tests/integration/r2i-native-check-refresh.php /private/tmp/foogallery-migrate-r0.vE1HfS check-all --user=fgmr0admin
 * wp --path=/private/tmp/foogallery-migrate-r0.vE1HfS eval-file tests/integration/r2i-native-check-refresh.php /private/tmp/foogallery-migrate-r0.vE1HfS refresh --user=fgmr0admin
 * wp --path=/private/tmp/foogallery-migrate-r0.vE1HfS eval-file tests/integration/r2i-native-check-refresh.php /private/tmp/foogallery-migrate-r0.vE1HfS verify --user=fgmr0admin
 */
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use FooPlugins\FooGalleryMigrate\LegacyTableBackend;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use FooPlugins\FooGalleryMigrate\StorageAdmin;
use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\StorageMaintenanceGate;
use FooPlugins\FooGalleryMigrate\Objects\Migratable;
use FooPlugins\FooGalleryMigrate\Plugins\Aigpl;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! isset( $args ) ||
	! in_array( count( $args ), array( 2, 3 ), true ) ||
	$args[0] !== '/private/tmp/foogallery-migrate-r0.vE1HfS' ||
	! in_array( $args[1], array( 'inspect', 'check-all', 'refresh', 'verify', 'attest-check' ), true ) ||
	( count( $args ) === 3 ) !== ( $args[1] === 'attest-check' ) ) {
	throw new RuntimeException( 'Exact canonical owned site and R2i phase required.' );
}
$r2i_site = $args[0]; $r2i_phase = $args[1];
$r2i_operator_hash = count( $args ) === 3 ? $args[2] : null;
ini_set( 'memory_limit', '256M' );
if ( ini_get( 'memory_limit' ) !== '256M' ) { throw new RuntimeException( 'Effective 256M request limit required.' ); }
// This accepted verifier binds manifest, live labelled Docker ID, DB, source
// symlink, administrator, all 121 original files, source order and page 246.
$args = array( $r2i_site, 'verify-source' ); ob_start();
require __DIR__ . '/storage-release-many-roots.php';
$r2i_source_document = json_decode( trim( ob_get_clean() ), true );
$args = array( $r2i_site, $r2i_phase );
many_roots_check( is_array( $r2i_source_document ) && isset( $r2i_source_document['source'] ) &&
	$r2i_source_document['phase'] === 'ready' && $r2i_source_document['expected'] === 121 &&
	$r2i_source_document['source']['page'] === 246 &&
	$r2i_source_document['memory_limit'] === '256M', 'Guarded 121-source proof differs.' );
$r2i_source_hash = hash( 'sha256', serialize( $r2i_source_document['source'] ) );
$r2i_status = StorageAdmin::status();
many_roots_check( $r2i_status['backend'] === 'table' && $r2i_status['phase'] === 'stable' &&
	$r2i_status['problem'] === '' && ini_get( 'memory_limit' ) === '256M', 'Stable table authority differs.' );
global $wpdb;
$r2i_identity = hash( 'sha256', $site . '|' . $manifest['database'] . '|' . $manifest['container_id'] );
$r2i_journal_key = 'fgm_r2i_native_check_refresh_v1';
$r2i_marker_key = 'fgm_r2i_native_check_refresh_pending_v1';
$r2i_option = function ( $name ) use ( $wpdb ) {
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT option_value FROM `' . $wpdb->options . '` WHERE option_name=%s', $name ), ARRAY_A );
	if ( ! is_array( $rows ) || $wpdb->last_error || count( $rows ) > 1 ) { throw new RuntimeException( 'Exact option SQL read failed.' ); }
	return array( 'found' => count( $rows ) === 1,
		'raw' => count( $rows ) ? $rows[0]['option_value'] : null,
		'value' => count( $rows ) ? maybe_unserialize( $rows[0]['option_value'] ) : null );
};
$r2i_save = function ( $name, $value, $new ) use ( $r2i_option ) {
	$before = $r2i_option( $name );
	if ( $new !== ! $before['found'] ) { throw new RuntimeException( 'Journal or marker presence differs before write.' ); }
	$written = $new ? add_option( $name, $value, '', 'no' ) : update_option( $name, $value, false );
	$after = $r2i_option( $name );
	if ( ( $new && ! $written ) || ! $after['found'] || $after['value'] !== $value || get_option( $name ) !== $value ) {
		throw new RuntimeException( 'Journal or marker durable readback failed.' );
	}
};
$r2i_delete_marker = function ( $expected ) use ( $r2i_option, $r2i_marker_key ) {
	$marker = $r2i_option( $r2i_marker_key );
	many_roots_check( $marker['found'] && $marker['value'] === $expected &&
		get_option( $r2i_marker_key ) === $expected && delete_option( $r2i_marker_key ) &&
		! $r2i_option( $r2i_marker_key )['found'], 'Pending marker could not be cleared exactly.' );
};
$r2i_prior_e = $r2i_option( 'fgm_r2e_native_turn_v1' );
$r2i_prior_g = $r2i_option( 'fgm_r2g_native_target_v1' );
many_roots_check( $r2i_prior_e['found'] && is_array( $r2i_prior_e['value'] ) &&
	$r2i_prior_e['value']['phase'] === 'album_done' &&
	$r2i_prior_e['value']['runs']['gallery']['uid'] === 'gallery_AIGPL_245' &&
	$r2i_prior_e['value']['runs']['gallery']['history_ids'] === array( 'root' => 247, 'image' => 244 ) &&
	$r2i_prior_e['value']['runs']['album']['history_ids'] === array( 'root' => 248, 'gallery' => 249, 'image' => 4 ) &&
	$r2i_prior_g['found'] && is_array( $r2i_prior_g['value'] ) &&
	$r2i_prior_g['value']['phase'] === 'done' &&
	! $r2i_option( 'fgm_r2e_native_turn_pending_v1' )['found'] &&
	! $r2i_option( 'fgm_r2g_native_target_pending_v1' )['found'],
	'Accepted R2e/R2g completed journals or exact destination IDs differ.' );
$r2i_prior_hashes = array( 'r2e' => hash( 'sha256', $r2i_prior_e['raw'] ),
	'r2g' => hash( 'sha256', $r2i_prior_g['raw'] ) );
$r2i_backup = $r2i_option( StorageAuthority::STORAGE_BACKUP_OPTION );
many_roots_check( $r2i_backup['found'] && is_string( $r2i_backup['raw'] ) &&
	strlen( $r2i_backup['raw'] ) === 142064 &&
	hash( 'sha256', $r2i_backup['raw'] ) === '71071fb837280ec98f9a370a450b607249b28f11312888965d82e11d11e0341d' &&
	! $r2i_option( FOOGALLERY_MIGRATE_OPTION_DATA )['found'], 'Retained backup or absent current option differs.' );
$r2i_control = $r2i_option( StorageAuthority::CONTROL_OPTION );
$r2i_authority = $r2i_option( StorageAuthority::AUTHORITY_OPTION );
many_roots_check( $r2i_control['found'] && $r2i_authority['found'], 'Stable control or table sentinel is missing.' );
foreach ( array( StorageAuthority::MAINTENANCE_OPTION, StorageAuthority::CLEANUP_OPTION,
	LegacyStorageSchema::LOCK_OPTION ) as $name ) {
	many_roots_check( ! $r2i_option( $name )['found'], 'Existing storage owner or cleanup marker requires inspection.' );
}
$r2i_writer_rows = function () use ( $wpdb ) {
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT option_id, option_name, option_value FROM `' . $wpdb->options .
		'` WHERE option_name LIKE %s ORDER BY option_id LIMIT 2',
		$wpdb->esc_like( StorageMaintenanceGate::WRITER_PREFIX ) . '%' ), ARRAY_A );
	many_roots_check( ! $wpdb->last_error && is_array( $rows ) && count( $rows ) <= 1,
		'Storage writer inventory differs.' );
	return $rows;
};
if ( $r2i_phase !== 'attest-check' ) {
	many_roots_check( count( $r2i_writer_rows() ) === 0, 'A storage writer is still present.' );
}
$r2i_assert_storage = function ( $writer_mode = 'none' ) use ( $r2i_option, $r2i_control,
	$r2i_authority, $r2i_backup, $r2i_writer_rows ) {
	$status = StorageAdmin::status();
	$writers = $r2i_writer_rows();
	$writer_ok = 'owned' === $writer_mode ? ( count( $writers ) === 1 &&
		true === StorageMaintenanceGate::current()->assert_writer() ) :
		( 'orphan' === $writer_mode ? count( $writers ) === 1 :
			( 'none' === $writer_mode && count( $writers ) === 0 ) );
	many_roots_check( $writer_ok &&
		$status['backend'] === 'table' && $status['phase'] === 'stable' && $status['problem'] === '' &&
		$r2i_option( StorageAuthority::CONTROL_OPTION )['raw'] === $r2i_control['raw'] &&
		$r2i_option( StorageAuthority::AUTHORITY_OPTION )['raw'] === $r2i_authority['raw'] &&
		$r2i_option( StorageAuthority::STORAGE_BACKUP_OPTION )['raw'] === $r2i_backup['raw'] &&
		! $r2i_option( StorageAuthority::MAINTENANCE_OPTION )['found'] &&
		! $r2i_option( StorageAuthority::CLEANUP_OPTION )['found'] &&
		! $r2i_option( FOOGALLERY_MIGRATE_OPTION_DATA )['found'],
		'Authority, sentinel, backup, or active owner changed.' );
};
$r2i_schema = new LegacyStorageSchema( $wpdb );
many_roots_check( $r2i_schema->verify(), 'Exact shared schema is not healthy.' );
$r2i_state_table = $wpdb->base_prefix . StorageAuthority::SHARED_STATE_TABLE_SUFFIX;
$r2i_records_table = $wpdb->base_prefix . StorageAuthority::SHARED_RECORDS_TABLE_SUFFIX;
many_roots_check( isset( $r2i_schema->definitions()[$r2i_state_table],
	$r2i_schema->definitions()[$r2i_records_table] ) &&
	preg_match( '/^[A-Za-z0-9_]+$/D', $r2i_state_table ) &&
	preg_match( '/^[A-Za-z0-9_]+$/D', $r2i_records_table ), 'Owned storage tables differ.' );

// Only these exact files are admitted; any changed candidate refuses before a
// journal is created or a public mutation begins.
$r2i_candidate_files = array( 'migrate.php', 'includes/class-migrator-engine.php',
	'includes/migrators/class-migrator-base.php', 'includes/class-migratable-collection-repository.php',
	'includes/class-legacy-table-backend.php', 'includes/class-legacy-table-repository.php',
	'includes/class-migrator-settings.php', 'includes/class-migrated-history-repository.php',
	'includes/class-migrated-store.php', 'includes/plugins/class-aigpl.php',
	'tests/integration/storage-release-many-roots.php', 'tests/integration/r2i-native-check-refresh.php' );
$r2i_candidate = array();
foreach ( $r2i_candidate_files as $file ) {
	$path = $repo . '/' . $file;
	many_roots_check( is_file( $path ) && ! is_link( $path ), 'Candidate source path differs: ' . $file );
	$r2i_candidate[$file] = hash_file( 'sha256', $path );
}
// The runner's own digest binds subsequent fresh processes through the journal;
// its hard-coded hash would be self-referential. All other files pin exactly.
$expected_candidate = array(
	'migrate.php' => '63548de6d2f30d0fa58f55c730a3b9a1787ab54f98928116920497c4bb4e459a',
	'includes/class-migrator-engine.php' => '86211fe403773caf026b2a41a2ed850a6c028dad6a89ce30ad9d0644395c7af9',
	'includes/migrators/class-migrator-base.php' => '344cee5b52ff24cdb04d2f2b2487e2e845c12df17f47a782702888cbe5a4d6b3',
	'includes/class-migratable-collection-repository.php' => '1206c34278d9c43ebfbf82b8916ada3b52965a45ca73b073747f433de4a12711',
	'includes/class-legacy-table-backend.php' => 'ae63f94c6808fed69f2899424f57618d6ff25f8ac9c507590906d0f3af5e65d3',
	'includes/class-legacy-table-repository.php' => '47f940fdbaa3bb264546cead57ef82c3ef3826571a9c0ec73236bf6596bd732d',
	'includes/class-migrator-settings.php' => '51e3815d089b11feed855bb82706b737273c24a715ac1e6bfbdb387cb4d8b00e',
	'includes/class-migrated-history-repository.php' => 'e4d3197a48925fe2be20923825f57b7e7347374d559cb477759339e573a7f554',
	'includes/class-migrated-store.php' => '578468eb00b466c266f96bcbc269b046ad7e7c0a8c3e01be07e1d69208502cfc',
	'includes/plugins/class-aigpl.php' => '3dfdaa7a075c5dc6ec6d1c135e93435e21c83414422d68736e3e486a96430541',
	'tests/integration/storage-release-many-roots.php' => '069345eadb959913aef204e5402a6e41f74f8618ce4fc65054a201823afe5994',
);
$r2i_pinned = $r2i_candidate; unset( $r2i_pinned['tests/integration/r2i-native-check-refresh.php'] );
many_roots_check( $expected_candidate === $r2i_pinned, 'R2i frozen source hashes differ.' );
if ( $r2i_phase === 'attest-check' ) {
	many_roots_check( is_string( $r2i_operator_hash ) &&
		preg_match( '/^[a-f0-9]{64}$/D', $r2i_operator_hash ) &&
		$r2i_candidate['tests/integration/r2i-native-check-refresh.php'] === $r2i_operator_hash,
		'Explicit reviewed recovery runner SHA-256 required.' );
}

$r2i_backend = new LegacyTableBackend( new LegacyTransactionConnection( $wpdb ), get_current_blog_id() );
$r2i_collection = ( new MigratorSettings( $r2i_backend ) )->table_collection_repository();
$r2i_history_view = function () use ( $r2i_backend ) {
	$read = $r2i_backend->history_read( 'export', array( 'limit' => 100, 'filters' => array(), 'cursor' => null ) );
	$page = $read['result'];
	if ( ! is_array( $page['rows'] ) || count( $page['rows'] ) > 10 || null !== $page['cursor'] ||
		! is_int( $read['context'][1]['root']['value'] ) ) { throw new RuntimeException( 'Bounded retained history differs.' ); }
	return array( 'revision' => $read['context'][1]['root']['value'], 'rows' => $page['rows'] );
};
$r2i_hash_rows = function ( $sql, $maximum, $label ) use ( $wpdb ) {
	$rows = $wpdb->get_results( $sql, ARRAY_A );
	if ( ! is_array( $rows ) || $wpdb->last_error || count( $rows ) >= $maximum + 1 ) {
		throw new RuntimeException( 'Bounded ' . $label . ' SQL inventory differs.' );
	}
	$encoded = serialize( $rows );
	if ( strlen( $encoded ) > 12582912 ) { throw new RuntimeException( 'Bounded ' . $label . ' bytes exceeded.' ); }
	return array( 'count' => count( $rows ), 'sha256' => hash( 'sha256', $encoded ), 'rows' => $rows );
};
$r2i_gallery_view = function () use ( $r2i_collection, $r2i_backend ) {
	$context = $r2i_collection->view_context( 'galleries' );
	if ( ! $context['found'] || $context['total'] !== 121 ) { throw new RuntimeException( '121 gallery roots required.' ); }
	$items = array();
	for ( $offset = 0; $offset < 121; $offset += 100 ) {
		$take = min( 100, 121 - $offset );
		$page = $r2i_collection->view_page( 'galleries', $offset, $take );
		$raw = $r2i_backend->collection_view_page( 'galleries', $offset, $take );
		if ( $page['head'] !== $context['head'] || $raw['head'] !== $context['head'] ||
			count( $page['rows'] ) !== $take || count( $raw['rows'] ) !== $take ) { throw new RuntimeException( 'Gallery page changed.' ); }
		foreach ( $page['rows'] as $index => $row ) {
			$gallery = $row['value'];
			if ( $raw['rows'][$index]['key'] !== $row['key'] || ! is_object( $gallery ) ||
				! method_exists( $gallery, 'unique_identifier' ) ||
				! isset( $gallery->ID, $gallery->migration_status, $gallery->migrated_id ) ) {
				throw new RuntimeException( 'Gallery source projection differs.' );
			}
			$items[] = array( 'key' => $row['key'], 'uid' => $gallery->unique_identifier(),
				'id' => (int) $gallery->ID, 'status' => $gallery->migration_status,
				'migrated' => (bool) $gallery->migrated, 'target' => (int) $gallery->migrated_id,
				'raw_sha256' => hash( 'sha256', serialize( $raw['rows'][$index] ) ) );
		}
	}
	if ( $r2i_collection->view_context( 'galleries' ) !== $context ) { throw new RuntimeException( 'Gallery view changed across pages.' ); }
	return array( 'head' => $context['head'], 'items' => $items );
};
$r2i_snapshot = function () use ( $wpdb, $r2i_hash_rows, $r2i_state_table, $r2i_records_table, $r2i_gallery_view, $r2i_history_view ) {
	$blog = get_current_blog_id();
	$state = $r2i_hash_rows( $wpdb->prepare( 'SELECT * FROM `' . $r2i_state_table . '` WHERE blog_id=%d ORDER BY id LIMIT 101', $blog ), 100, 'state heads' );
	$records = $r2i_hash_rows( $wpdb->prepare( 'SELECT * FROM `' . $r2i_records_table . '` WHERE blog_id=%d ORDER BY id LIMIT 1001', $blog ), 1000, 'state records' );
	$gallery_id = null; $history_ids = array(); $other_heads = array();
	$codec = new \FooPlugins\FooGalleryMigrate\LegacyStateCodec();
	foreach ( $state['rows'] as $row ) {
		if ( $row['state_key_value'] === $codec->key_value( 'galleries' ) ) {
			if ( null !== $gallery_id ) { throw new RuntimeException( 'Duplicate gallery state head.' ); }
			$gallery_id = (int) $row['id'];
		} elseif ( $row['state_key_value'] === $codec->key_value( 'migrated' ) ||
			$row['state_key_value'] === $codec->key_value( 'migrated-revision' ) ) {
			$history_ids[] = (int) $row['id'];
		} else { $other_heads[] = $row; }
	}
	if ( null === $gallery_id || count( $history_ids ) !== 2 ) { throw new RuntimeException( 'Gallery or history state head missing.' ); }
	$other_records = array(); $gallery_records = array();
	foreach ( $records['rows'] as $row ) {
		if ( (int) $row['state_id'] === $gallery_id ) { $gallery_records[] = $row; }
		elseif ( ! in_array( (int) $row['state_id'], $history_ids, true ) ) { $other_records[] = $row; }
	}
	$posts = $r2i_hash_rows( 'SELECT * FROM `' . $wpdb->posts . '` ORDER BY ID LIMIT 501', 500, 'posts' );
	$meta = $r2i_hash_rows( 'SELECT * FROM `' . $wpdb->postmeta . '` ORDER BY meta_id LIMIT 3001', 3000, 'post metadata' );
	if ( $posts['count'] !== 249 || $records['count'] < 371 ) { throw new RuntimeException( 'Protected post or record inventory differs.' ); }
	return array( 'protected' => array( 'other_heads' => hash( 'sha256', serialize( $other_heads ) ),
		'other_records' => hash( 'sha256', serialize( $other_records ) ),
		'posts' => $posts['sha256'], 'postmeta' => $meta['sha256'] ),
		'gallery_raw' => hash( 'sha256', serialize( $gallery_records ) ),
		'gallery' => $r2i_gallery_view(), 'history' => $r2i_history_view(),
		'record_count' => $records['count'], 'post_count' => $posts['count'] );
};
$r2i_assert_gallery_sources = function ( $view ) use ( &$journal ) {
	$expected = array_reverse( array_column( $journal['units'], 'gallery' ) );
	$actual = array_column( $view['items'], 'id' );
	many_roots_check( count( $view['items'] ) === 121 && $actual === $expected &&
		count( array_unique( array_column( $view['items'], 'uid' ) ) ) === 121,
		'All 121 gallery IDs, order or UIDs differ from owned source.' );
};
$r2i_unrelated_gallery_raw = function ( $view ) {
	$unrelated = array();
	foreach ( $view['items'] as $item ) {
		if ( in_array( $item['uid'], array( 'gallery_AIGPL_245', 'gallery_AIGPL_5' ), true ) ) { continue; }
		$unrelated[] = array( 'key' => $item['key'], 'uid' => $item['uid'], 'sha256' => $item['raw_sha256'] );
	}
	many_roots_check( count( $unrelated ) === 119, 'Exactly 119 unrelated gallery trees required.' );
	return $unrelated;
};
$r2i_journal = $r2i_option( $r2i_journal_key ); $r2i_marker = $r2i_option( $r2i_marker_key );
if ( $r2i_phase !== 'attest-check' ) {
	many_roots_check( ! $r2i_marker['found'], 'Pending R2i marker requires manual inspection; no replay.' );
}
$r2i_before = $r2i_snapshot(); $r2i_assert_gallery_sources( $r2i_before['gallery'] );
$r2i_eligible_children = function () use ( $r2i_collection, $r2i_backend, $r2i_before ) {
	$engine = foogallery_migrate_migrator_instance(); $eligible = array(); $child_count = 0;
	$settings = new MigratorSettings( $r2i_backend ); $history = array();
	foreach ( $r2i_before['history']['rows'] as $entry ) { $history[$entry['key']] = $entry['value']; }
	for ( $offset = 0; $offset < 121; $offset += 100 ) {
		$page = $r2i_collection->view_page( 'galleries', $offset, min( 100, 121 - $offset ) );
		many_roots_check( $page['head'] === $r2i_before['gallery']['head'], 'Inspection gallery head changed.' );
		foreach ( $page['rows'] as $row ) {
			$gallery = $row['value']; $gallery->ensure_children_loaded();
			$engine->apply_migrated_children( $gallery );
			foreach ( $gallery->get_children() as $child ) {
				++$child_count;
				if ( isset( $child->migration_status ) && $child->migration_status === Migratable::PROGRESS_ERROR ) { continue; }
				$target = isset( $child->migrated_id ) ? (int) $child->migrated_id : 0;
				if ( $target < 1 ) { continue; }
				$compact = $settings->compact_migrated_object( $child );
				$stored = isset( $history[$child->unique_identifier()] ) ? $history[$child->unique_identifier()] : array();
				$changed_fields = array();
				foreach ( array_unique( array_merge( array_keys( $compact ), array_keys( $stored ) ) ) as $field ) {
					if ( ! array_key_exists( $field, $compact ) || ! array_key_exists( $field, $stored ) ||
						$compact[$field] !== $stored[$field] ) { $changed_fields[] = $field; }
				}
				$eligible[] = array( 'parent' => $gallery->unique_identifier(), 'child' => $child->unique_identifier(),
					'target' => $target, 'status' => $child->migration_status,
					'file_exists' => is_file( get_attached_file( $target ) ),
					'url' => wp_get_attachment_url( $target ),
					'history_changed_fields' => $changed_fields,
					'parent_before' => isset( $stored['migration_parent_key'] ) ? $stored['migration_parent_key'] : null,
					'parent_after' => isset( $compact['migration_parent_key'] ) ? $compact['migration_parent_key'] : null );
			}
		}
	}
	many_roots_check( $r2i_collection->view_context( 'galleries' )['head'] === $r2i_before['gallery']['head'],
		'Read-only inspection changed gallery head.' );
	return array( 'children' => $child_count, 'eligible' => $eligible );
};
if ( 'inspect' === $r2i_phase ) {
	many_roots_check( ! $r2i_journal['found'], 'Read-only inspection requires no existing R2i journal.' );
	$inspection = $r2i_eligible_children();
	many_roots_check( $verify_units( true ) === $r2i_source_document['source'], 'Read-only inspection changed source.' );
	$r2i_assert_storage();
	$history_roots = array();
	foreach ( $r2i_before['history']['rows'] as $row ) {
		$history_roots[] = array( 'key' => $row['key'], 'fields' => array_keys( $row['value'] ),
			'status' => isset( $row['value']['migration_status'] ) ? $row['value']['migration_status'] : null,
			'target' => isset( $row['value']['migrated_id'] ) ? $row['value']['migrated_id'] : null );
	}
	$completed = array_values( array_filter( $r2i_before['gallery']['items'], function ( $item ) {
		return $item['migrated'] || $item['target'] > 0;
	} ) );
	$discovered = ( new Aigpl() )->find_galleries(); $discovery = array();
	foreach ( $discovered as $gallery ) {
		$discovery[] = array( 'id' => (int) $gallery->ID, 'uid' => $gallery->unique_identifier(),
			'status' => $gallery->migration_status, 'migrated' => (bool) $gallery->migrated,
			'target' => (int) $gallery->migrated_id );
	}
	many_roots_check( count( $discovery ) === 121 &&
		array_column( $discovery, 'id' ) === array_reverse( array_column( $journal['units'], 'gallery' ) ) &&
		$r2i_snapshot() === $r2i_before, 'Read-only source discovery changed or mismatched the owned source.' );
	$nondefault = array_values( array_filter( $discovery, function ( $gallery ) {
		return $gallery['migrated'] || $gallery['target'] > 0 || $gallery['status'] !== Migratable::PROGRESS_NOT_STARTED;
	} ) );
	echo wp_json_encode( array( 'phase' => 'inspected', 'roots' => 121, 'children' => $inspection['children'],
		'eligible' => $inspection['eligible'], 'history_revision' => $r2i_before['history']['revision'],
		'history_roots' => $history_roots, 'completed' => $completed,
		'discovered_nondefault' => $nondefault, 'discovered_default_count' => 121 - count( $nondefault ),
		'gallery_revision' => $r2i_before['gallery']['head']['revision'],
		'peak_bytes' => memory_get_peak_usage( true ), 'memory_limit' => ini_get( 'memory_limit' ) ) ) . "\n";
	return;
}
$r2i_prior = array( 'identity' => $r2i_identity, 'source' => $r2i_source_hash,
	'backup' => hash( 'sha256', $r2i_backup['raw'] ), 'prior' => $r2i_prior_hashes,
	'candidate' => $r2i_candidate );
if ( 'attest-check' === $r2i_phase ) {
	// One operator-supervised repair of the exact failed test receipt. The
	// check-all result is never replayed; a different owner/fixture refuses.
	$failed_candidate = $r2i_candidate;
	$failed_candidate['tests/integration/r2i-native-check-refresh.php'] =
		'416008c339a023129f527546e3c857d782a44ee252fd9a8991d1379d2360db6c';
	$failed_marker = array( 'identity' => $r2i_identity, 'phase' => 'check_pending' );
	many_roots_check( $r2i_journal['found'] && is_string( $r2i_journal['raw'] ) &&
		hash( 'sha256', $r2i_journal['raw'] ) === 'a3ed5b787fdbcabda08ce11042c0eb405f417b3bf77d020311aaa742c6941ff6' &&
		is_array( $r2i_journal['value'] ) && $r2i_journal['value']['phase'] === 'check_pending' &&
		$r2i_journal['value']['identity'] === $r2i_prior['identity'] &&
		$r2i_journal['value']['source'] === $r2i_prior['source'] &&
		$r2i_journal['value']['backup'] === $r2i_prior['backup'] &&
		$r2i_journal['value']['prior'] === $r2i_prior['prior'] &&
		$r2i_journal['value']['candidate'] === $failed_candidate &&
		$r2i_journal['value']['before'] === $r2i_before &&
		! isset( $r2i_journal['value']['checked'] ) &&
		$r2i_marker['found'] && $r2i_marker['value'] === $failed_marker &&
		hash( 'sha256', $r2i_marker['raw'] ) === '2367343e3969f5704923f9782424e96e3330ccd6bfe2cea9384d82eee0091851' &&
		$r2i_option( 'fgm_r2e_native_turn_v1' )['raw'] === $r2i_prior_e['raw'] &&
		$r2i_option( 'fgm_r2g_native_target_v1' )['raw'] === $r2i_prior_g['raw'] &&
		$verify_units( true ) === $r2i_source_document['source'],
		'Exact failed check-all receipt, source, or protected snapshot differs; no action.' );
	$r2i_assert_storage( 'orphan' );
	$writer_name = 'foogallery_migrate_storage_writer_XlPqKz2D1MIYBVpkhYYHif5UrUf6CQgJ';
	$writer_raw = '{"version":1,"blog_id":1,"token":"XlPqKz2D1MIYBVpkhYYHif5UrUf6CQgJ","expires":1792723315}';
	$writers = $r2i_writer_rows();
	many_roots_check( count( $writers ) === 1 && (int) $writers[0]['option_id'] === 185 &&
		$writers[0]['option_name'] === $writer_name && $writers[0]['option_value'] === $writer_raw &&
		hash( 'sha256', $writers[0]['option_value'] ) === '81a7e5d2512c7d7f6d03ea8346c9dd1d5927db7911aca240a055f4ebcbcb737d',
		'Exact failed request writer identity differs; no action.' );
	// The owned test fixture is operator-frozen: no HTTP or cron entrypoint.
	// Check again from its live, labelled DB container immediately before CAS:
	// zero InnoDB transactions and only this WP-CLI plus the root probe session.
	$quiescence_sql = 'SELECT COUNT(*) FROM information_schema.innodb_trx; ' .
		'SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE DB = DATABASE();';
	$inside = 'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" mariadb -uroot -D ' .
		escapeshellarg( $manifest['database'] ) . ' --batch --skip-column-names -e ' .
		escapeshellarg( $quiescence_sql );
	$quiescence = array(); $quiescence_code = 0;
	exec( 'docker exec ' . escapeshellarg( $manifest['container'] ) . ' sh -c ' .
		escapeshellarg( $inside ) . ' 2>/dev/null', $quiescence, $quiescence_code );
	many_roots_check( $quiescence_code === 0 && $quiescence === array( '0', '2' ),
		'Operator-frozen fixture DB is not quiescent; no action.' );
	$connection = new LegacyTransactionConnection( $wpdb );
	$writer_select = $connection->prepare( 'SELECT option_id, option_name, option_value FROM `' .
		$wpdb->options . '` WHERE option_name LIKE %s ORDER BY option_id LIMIT 2',
		array( $connection->esc_like( StorageMaintenanceGate::WRITER_PREFIX ) . '%' ) );
	$pinned_writers = $connection->get_results( $writer_select, ARRAY_A );
	many_roots_check( ! $connection->last_error && is_array( $pinned_writers ) &&
		count( $pinned_writers ) === 1 && $pinned_writers[0] === $writers[0],
		'Pinned-connection owner read differs; no action.' );
	$delete = $connection->prepare( 'DELETE FROM `' . $wpdb->options .
		'` WHERE option_id=%d AND option_name=%s AND BINARY option_value = BINARY %s LIMIT 1',
		array( 185, $writer_name, $writer_raw ) );
	many_roots_check( $connection->query( $delete ) === 1 && ! $connection->last_error,
		'Exact orphan writer CAS failed; retain test receipt.' );
	$pinned_after = $connection->get_results( $writer_select, ARRAY_A );
	many_roots_check( ! $connection->last_error && $pinned_after === array(),
		'Pinned-connection writer readback differs; retain test receipt.' );
	wp_cache_delete( $writer_name, 'options' );
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	many_roots_check( count( $r2i_writer_rows() ) === 0 &&
		$r2i_snapshot() === $r2i_before &&
		$r2i_option( $r2i_journal_key )['raw'] === $r2i_journal['raw'] &&
		$r2i_option( $r2i_marker_key )['raw'] === $r2i_marker['raw'],
		'CAS readback, source snapshot, or failed receipt changed; retain pending marker.' );
	$r2i_assert_storage();
	$attested = $r2i_journal['value'];
	$attested['phase'] = 'checked';
	$attested['checked'] = $r2i_before;
	$attested['failed_receipt'] = array( 'raw_journal' => $r2i_journal['raw'],
		'raw_marker' => $r2i_marker['raw'], 'failure' => 'same-request zero-writer observer assertion',
		'candidate' => $failed_candidate );
	$attested['candidate'] = $r2i_candidate;
	$r2i_save( $r2i_journal_key, $attested, false );
	$r2i_delete_marker( $failed_marker );
	many_roots_check( count( $r2i_writer_rows() ) === 0 && $r2i_snapshot() === $r2i_before &&
		$verify_units( true ) === $r2i_source_document['source'],
		'Attested check state or source changed.' );
	echo wp_json_encode( array( 'phase' => 'checked_attested',
		'failed_runner_sha256' => $failed_candidate['tests/integration/r2i-native-check-refresh.php'],
		'failed_journal_sha256' => hash( 'sha256', $r2i_journal['raw'] ),
		'gallery_revision' => $r2i_before['gallery']['head']['revision'],
		'memory_limit' => ini_get( 'memory_limit' ), 'peak_bytes' => memory_get_peak_usage( true ) ) ) . "\n";
	return;
}
if ( 'check-all' === $r2i_phase ) {
	many_roots_check( ! $r2i_journal['found'], 'Existing R2i journal forbids check-all replay.' );
	$inspection = $r2i_eligible_children();
	$urls = array( wp_get_attachment_url( 244 ), wp_get_attachment_url( 4 ) );
	$expected_eligible = array(
		array( 'parent' => 'gallery_AIGPL_245', 'child' => $urls[0], 'target' => 244,
			'status' => Migratable::PROGRESS_COMPLETED, 'file_exists' => true, 'url' => $urls[0],
			'history_changed_fields' => array( 'migration_parent_key' ),
			'parent_before' => 'gallery_AIGPL_245', 'parent_after' => null ),
		array( 'parent' => 'gallery_AIGPL_5', 'child' => $urls[1], 'target' => 4,
			'status' => Migratable::PROGRESS_COMPLETED, 'file_exists' => true, 'url' => $urls[1],
			'history_changed_fields' => array( 'migration_parent_key' ),
			'parent_before' => 'gallery_AIGPL_5', 'parent_after' => null ),
	);
	many_roots_check( $inspection['children'] === 121 && $inspection['eligible'] === $expected_eligible &&
		is_string( $urls[0] ) && '' !== $urls[0] && is_string( $urls[1] ) && '' !== $urls[1] &&
		$urls[0] !== $urls[1] && $r2i_before['history']['revision'] === 5 &&
		count( $r2i_before['history']['rows'] ) === 5, 'Exact eligible source children or prior history differs.' );
	$completed = array_values( array_filter( $r2i_before['gallery']['items'], function ( $item ) {
		return $item['migrated'] || $item['target'] > 0;
	} ) );
	many_roots_check( count( $completed ) === 1 && $completed[0]['uid'] === 'gallery_AIGPL_245' &&
		$completed[0]['target'] === 247 && $completed[0]['status'] === Migratable::PROGRESS_COMPLETED &&
		get_post_meta( 247, FOOGALLERY_META_ATTACHMENTS, true ) === array( 244 ) &&
		get_post_meta( 249, FOOGALLERY_META_ATTACHMENTS, true ) === array( 4 ),
		'Pre-check completed gallery/output differs.' );
	$r2i_record = array_merge( $r2i_prior, array( 'phase' => 'check_pending',
		'before' => $r2i_before, 'urls' => $urls ) );
	$r2i_save( $r2i_journal_key, $r2i_record, true );
	$r2i_pending = array( 'identity' => $r2i_identity, 'phase' => 'check_pending' );
	$r2i_save( $r2i_marker_key, $r2i_pending, true );
	$head_hits = array_fill_keys( $urls, 0 ); $unexpected_heads = 0;
	$filter = function ( $preempt, $request, $request_url ) use ( &$head_hits, &$unexpected_heads ) {
		if ( isset( $request['method'] ) && $request['method'] === 'HEAD' ) {
			if ( array_key_exists( $request_url, $head_hits ) ) {
				++$head_hits[$request_url];
				return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'headers' => array(), 'body' => '' );
			}
			++$unexpected_heads;
			return new \WP_Error( 'r2i_unowned_head', 'Unexpected outbound HEAD refused by owned fixture.' );
		}
		return $preempt;
	};
	add_filter( 'pre_http_request', $filter, 10, 3 );
	try { $result = foogallery_migrate_migrator_instance()->check_for_migration_errors(); }
	finally { remove_filter( 'pre_http_request', $filter, 10 ); }
	many_roots_check( ! is_wp_error( $result ) && $result === array( 'checked' => 2, 'errors' => 0 ) &&
		$head_hits === array_fill_keys( $urls, 1 ) && $unexpected_heads === 0 && ini_get( 'memory_limit' ) === '256M',
		'Public check-all did not inspect both migrated images without network.' );
	$r2i_after = $r2i_snapshot(); $r2i_assert_gallery_sources( $r2i_after['gallery'] );
	many_roots_check( $r2i_before['protected'] === $r2i_after['protected'] &&
		$r2i_after['history'] === $r2i_before['history'] &&
		$r2i_unrelated_gallery_raw( $r2i_before['gallery'] ) ===
			$r2i_unrelated_gallery_raw( $r2i_after['gallery'] ) &&
		array_column( $r2i_before['gallery']['items'], 'uid' ) === array_column( $r2i_after['gallery']['items'], 'uid' ) &&
		array_column( $r2i_before['gallery']['items'], 'status' ) === array_column( $r2i_after['gallery']['items'], 'status' ) &&
		array_column( $r2i_before['gallery']['items'], 'migrated' ) === array_column( $r2i_after['gallery']['items'], 'migrated' ) &&
		array_column( $r2i_before['gallery']['items'], 'target' ) === array_column( $r2i_after['gallery']['items'], 'target' ) &&
		$r2i_option( StorageAuthority::STORAGE_BACKUP_OPTION )['raw'] === $r2i_backup['raw'] &&
		$r2i_option( 'fgm_r2e_native_turn_v1' )['raw'] === $r2i_prior_e['raw'] &&
		$r2i_option( 'fgm_r2g_native_target_v1' )['raw'] === $r2i_prior_g['raw'] &&
		$verify_units( true ) === $r2i_source_document['source'],
		'Check-all changed protected state, source, output, or gallery status.' );
	$r2i_assert_storage( 'owned' );
	$r2i_record['checked'] = $r2i_after; $r2i_record['phase'] = 'checked';
	$r2i_save( $r2i_journal_key, $r2i_record, false );
	$r2i_delete_marker( $r2i_pending );
	echo wp_json_encode( array( 'phase' => 'checked', 'result' => $result,
		'gallery_revision' => $r2i_after['gallery']['head']['revision'],
		'source_sha256' => $r2i_source_hash, 'peak_bytes' => memory_get_peak_usage( true ),
		'memory_limit' => ini_get( 'memory_limit' ) ) ) . "\n";
	return;
}
many_roots_check( $r2i_journal['found'] && is_array( $r2i_journal['value'] ) &&
	isset( $r2i_journal['value']['phase'], $r2i_journal['value']['before'], $r2i_journal['value']['checked'] ) &&
	$r2i_journal['value']['identity'] === $r2i_prior['identity'] &&
	$r2i_journal['value']['source'] === $r2i_prior['source'] &&
	$r2i_journal['value']['backup'] === $r2i_prior['backup'] &&
	$r2i_journal['value']['prior'] === $r2i_prior['prior'] &&
	$r2i_journal['value']['candidate'] === $r2i_prior['candidate'],
	'Fresh R2i journal identity, prior journals, or candidate differs.' );
$r2i_record = $r2i_journal['value'];
if ( 'refresh' === $r2i_phase ) {
	many_roots_check( $r2i_record['phase'] === 'checked' && $r2i_before === $r2i_record['checked'],
		'Fresh refresh requires exact completed check-all state.' );
	$r2i_record['phase'] = 'refresh_pending'; $r2i_save( $r2i_journal_key, $r2i_record, false );
	$r2i_pending = array( 'identity' => $r2i_identity, 'phase' => 'refresh_pending' );
	$r2i_save( $r2i_marker_key, $r2i_pending, true );
	$started = microtime( true );
	$objects = foogallery_migrate_migrator_instance()->get_gallery_migrator()->get_objects_to_migrate( true );
	$elapsed = microtime( true ) - $started;
	$expected = array();
	foreach ( array_reverse( $journal['units'] ) as $unit ) {
		$id = $unit['gallery']; $target = 245 === $id ? 247 : ( 5 === $id ? 249 : 0 );
		$expected[] = array( 'id' => $id, 'uid' => 'gallery_AIGPL_' . $id,
			'status' => $target ? Migratable::PROGRESS_COMPLETED : Migratable::PROGRESS_NOT_STARTED,
			'migrated' => (bool) $target, 'target' => $target );
	}
	many_roots_check( is_array( $objects ) && count( $objects ) === 121,
		'Forced discovery did not return 121 objects.' );
	$found = array();
	foreach ( $objects as $object ) {
		$found[] = array( 'id' => (int) $object->ID, 'uid' => $object->unique_identifier(),
			'status' => $object->migration_status, 'migrated' => (bool) $object->migrated,
			'target' => (int) $object->migrated_id );
	}
	many_roots_check( $found === $expected && $elapsed <= 30 &&
		ini_get( 'memory_limit' ) === '256M', 'Forced refresh did not return ordered 121-source discovery.' );
	unset( $objects );
	$r2i_after = $r2i_snapshot(); $r2i_assert_gallery_sources( $r2i_after['gallery'] );
	$persisted = array();
	foreach ( $r2i_after['gallery']['items'] as $item ) {
		$persisted[] = array( 'id' => $item['id'], 'uid' => $item['uid'], 'status' => $item['status'],
			'migrated' => $item['migrated'], 'target' => $item['target'] );
	}
	many_roots_check( $r2i_before['protected'] === $r2i_after['protected'] &&
		$r2i_before['history'] === $r2i_after['history'] &&
		$persisted === $expected &&
		$r2i_option( StorageAuthority::STORAGE_BACKUP_OPTION )['raw'] === $r2i_backup['raw'] &&
		$r2i_option( 'fgm_r2e_native_turn_v1' )['raw'] === $r2i_prior_e['raw'] &&
		$r2i_option( 'fgm_r2g_native_target_v1' )['raw'] === $r2i_prior_g['raw'] &&
		$verify_units( true ) === $r2i_source_document['source'] &&
		$r2i_after['gallery']['head']['revision'] > $r2i_before['gallery']['head']['revision'],
		'Forced refresh changed protected state or did not publish one new gallery head.' );
	$r2i_assert_storage( 'owned' );
	$r2i_record['phase'] = 'done'; $r2i_record['refreshed'] = $r2i_after;
	$r2i_save( $r2i_journal_key, $r2i_record, false ); $r2i_delete_marker( $r2i_pending );
	echo wp_json_encode( array( 'phase' => 'done', 'discovered' => count( $found ),
		'elapsed_seconds' => $elapsed, 'gallery_revision' => $r2i_after['gallery']['head']['revision'],
		'source_sha256' => $r2i_source_hash, 'peak_bytes' => memory_get_peak_usage( true ),
		'memory_limit' => ini_get( 'memory_limit' ) ) ) . "\n";
	return;
}
many_roots_check( $r2i_record['phase'] === 'done' && isset( $r2i_record['refreshed'] ) &&
	$r2i_before === $r2i_record['refreshed'] &&
	$verify_units( true ) === $r2i_source_document['source'] &&
	$r2i_option( StorageAuthority::STORAGE_BACKUP_OPTION )['raw'] === $r2i_backup['raw'] &&
	$r2i_option( 'fgm_r2e_native_turn_v1' )['raw'] === $r2i_prior_e['raw'] &&
	$r2i_option( 'fgm_r2g_native_target_v1' )['raw'] === $r2i_prior_g['raw'],
	'Final R2i read-only source, checkpoint, journals or backup differs.' );
$r2i_assert_storage();
echo wp_json_encode( array( 'phase' => 'verified', 'gallery_revision' => $r2i_before['gallery']['head']['revision'],
	'source_sha256' => $r2i_source_hash, 'peak_bytes' => memory_get_peak_usage( true ),
	'memory_limit' => ini_get( 'memory_limit' ) ) ) . "\n";
