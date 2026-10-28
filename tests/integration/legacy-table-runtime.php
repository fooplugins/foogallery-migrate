<?php
/** Guarded real WP/MariaDB physical runtime contracts; no public backend selection. */
if ( ! defined( 'ABSPATH' ) || ! defined( 'FOOGM_STORAGE_TEST_DISPOSABLE' ) || true !== FOOGM_STORAGE_TEST_DISPOSABLE ) {
	throw new RuntimeException( 'Explicit disposable WordPress admission required.' );
}
$plugin_root = defined( 'FOOGM_STORAGE_TEST_ROOT' ) ? FOOGM_STORAGE_TEST_ROOT : dirname( dirname( __DIR__ ) );
require_once $plugin_root . '/migrate.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once dirname( __DIR__ ) . '/fixtures/legacy-runtime-contract-fixtures.php';

use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use FooPlugins\FooGalleryMigrate\LegacyTableBackend;
use FooPlugins\FooGalleryMigrate\LegacyTableRepository;
use FooPlugins\FooGalleryMigrate\TableSnapshot;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;
use FooPlugins\FooGalleryMigrate\StorageAuthority;

class FooGmRuntimeConnection extends LegacyTransactionConnection {
	public $queries = array();
	public $after_read;
	public $before_write;
	public $fail_commit = false;
	public $fail_start = false;
	public function get_results( $sql, $format ) {
		$this->queries[] = $sql; $rows = parent::get_results( $sql, $format );
		if ( is_callable( $this->after_read ) ) { call_user_func( $this->after_read, $sql ); } return $rows;
	}
	public function query( $sql ) {
		$this->queries[] = $sql;
		if ( is_callable( $this->before_write ) ) { call_user_func( $this->before_write, $sql ); }
		$result = parent::query( $sql );
		if ( 'START TRANSACTION' === $sql && $this->fail_start ) { return false; }
		return 'COMMIT' === $sql && $this->fail_commit ? false : $result;
	}
}

global $wpdb;
$schema = new LegacyStorageSchema( $wpdb ); $tables = array_keys( $schema->definitions() );
foreach ( $tables as $table ) {
	if ( null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) || ! empty( $wpdb->last_error ) ) { throw new RuntimeException( 'Refusing existing tables/unhealthy fixture DB.' ); }
}
$options = array(); $missing = new stdClass();
foreach ( array( FOOGALLERY_MIGRATE_OPTION_DATA, FOOGALLERY_MIGRATE_OPTION_SETTINGS, StorageAuthority::CONTROL_OPTION,
	StorageAuthority::AUTHORITY_OPTION, StorageAuthority::MAINTENANCE_OPTION, LegacyStorageSchema::VERSION_OPTION, LegacyStorageSchema::LOCK_OPTION ) as $key ) { $options[$key] = get_option( $key, $missing ); }
$checks = array();
$check = function ( $condition, $label ) use ( &$checks ) { if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $label ); } $checks[] = $label; };
$refuses = function ( $callback, $label ) use ( $check ) { try { $callback(); } catch ( RuntimeException $error ) { $check( true, $label ); return; } $check( false, $label ); };
$change = function ( $key, $order, $kind, $value, $revision = null ) { return array( 'key' => $key, 'order' => $order, 'kind' => $kind, 'value' => $value, 'expected_revision' => $revision ); };
$patch = function ( $key, $revision, $patches ) { return array( 'key' => $key, 'expected_revision' => $revision, 'patches' => $patches ); };
$codec = new LegacyStateCodec(); $blog = (int) get_current_blog_id(); $created = array(); $switched = false;
try {
	foreach ( $schema->statements() as $i => $ddl ) { $created[] = $tables[$i]; dbDelta( $ddl ); }
	$check( $schema->verify(), 'exact unchanged shared schema verified' );
	$db = new FooGmRuntimeConnection( $wpdb ); $repo = new LegacyTableRepository( $db, $blog ); $snapshot = new TableSnapshot( $db, $blog );
	$check( array() === $db->queries, 'physical construction is inert' );
	$fixture = \FooPlugins\FooGalleryMigrate\Tests\Fixtures\legacy_runtime_contract_fixture();
	$refuses( function () use ( $snapshot, $change, $fixture ) { $snapshot->replace_many( array( $change( 'migrated', 0, 'map', $fixture['logical']['migrated'] ) ) ); }, 'shared historical objects refuse without narrowing' );
	$check( array() === $db->queries, 'historical refusal precedes SQL' );
	$record = array( 'object_type' => 'image', 'plugin_name' => 'Case', 'ID' => '009', 'migrated' => true, 'migrated_id' => '00071',
		'migration_status' => 'completed', 'part_of_migration' => true, 'error' => false, 'unknown' => "\xff\x00雪", 'zero' => -0.0,
		'children' => array( '09' => false, 9 => null ) );
	$numeric = $record; $numeric['ID'] = 9; $numeric['migrated_id'] = 72;
	$unmigrated = $record; $unmigrated['migrated'] = false;
	$zero_destination = $record; $zero_destination['migrated_id'] = 0;
	$history = array( 'unmigrated' => $unmigrated, 'zero' => $zero_destination, '09' => $record, 9 => $numeric, 'duplicate' => $record, 'false' => false, 'null' => null );
	$snapshot->replace_many( array( $change( 'migrated', 0, 'map', $history ), $change( 'checkpoint', 1, 'scalar', false ),
		$change( 'queue', 2, 'list', array( false, null ) ), $change( 'empty', 3, 'map', array() ) ) );
	$check( $codec->snapshot_digest( $snapshot->read_bounded( 'migrated' ) ) === $codec->snapshot_digest( $history ), 'compact nested typed/binary fixture round-trips canonically' );
	$inventory = array(); $order = 4;
	foreach ( $fixture['logical'] as $key => $value ) {
		if ( in_array( $key, array( 'migrated', 'galleries' ), true ) ) { continue; }
		$inventory[] = $change( $key, $order++, 'scalar', $value );
	}
	$wrapper = array( \FooPlugins\FooGalleryMigrate\MigratorSettings::COMPACT_MARKER => 1, 'type' => 'migratable', 'items' => array( $record ), 'unknown_header' => "\xff\x00" );
	$inventory[] = $change( 'galleries', $order++, 'scalar', $wrapper );
	$snapshot->replace_many( $inventory );
	foreach ( $inventory as $entry ) {
		$check( $repo->read_scalar( $entry['key'] )['value'] === $entry['value'], 'compact physical inventory bytes and raw wrapper preserved: ' . $entry['key'] );
	}
	$ids = $repo->identifiers( 'migrated', array( 9, '09', 'missing', 'false', 'null' ) );
	$check( array_column( $ids, 'key' ) === array( 9, '09', 'missing', 'false', 'null' ) && $ids[0]['value'] === $numeric && $ids[1]['value'] === $record && ! $ids[2]['found'] && false === $ids[3]['value'] && null === $ids[4]['value'], 'indexed getMany preserves request order, typed identifiers and presence' );
	$db->queries = array();
	$presence = $repo->identifier_presence( 'migrated', array( 'false', 'null', 'missing' ) );
	$check( array( array( 'found' => true, 'key' => 'false' ), array( 'found' => true, 'key' => 'null' ), array( 'found' => false, 'key' => 'missing' ) ) === $presence, 'indexed has distinguishes false/null/absence' );
	$check( ! count( array_filter( $db->queries, function ( $sql ) { return false !== strpos( $sql, '`mutable_payload`' ); } ) ), 'indexed has fetches no logical record payloads' );
	$summary = $repo->projection_summary( 'migrated' );
	$check( array( 'image' => 5 ) === $summary['object_type'] && array( 'completed' => 5 ) === $summary['migration_status'], 'SQL distinct log summary excludes null/false and nested children' );
	$identity = array( 'type' => 'image', 'plugin' => 'Case', 'id' => '009' );
	$exact = $repo->source_identities( 'migrated', array( $identity ) );
	$check( '09' === $exact[0]['key'], 'exact source lookup skips unmigrated/zero destination and picks first duplicate' );
	$check( '09' === $repo->source_identities( 'migrated', array( $identity ), true )[0]['key'], 'numeric image lookup preserves first legacy match' );
	$identity['id'] = 9;
	$check( 9 === $repo->source_identities( 'migrated', array( $identity ) )[0]['key'], 'exact cast source semantics retain leading-zero distinction' );
	$identity['plugin'] = 'case';
	$check( ! $repo->source_identities( 'migrated', array( $identity ) )[0]['found'], 'source plugin is binary case-sensitive' );
	$counts = $repo->aggregate( 'migrated' );
	$check( array( 'total' => 7, 'migrated' => 4, 'selected' => 5, 'errors' => 0 ) === $counts, 'SQL root summaries exclude nested children' );
	$page = $repo->filtered_page( 'migrated', 1, array( 'object_type' => 'image', 'is_migrated' => 1 ) );
	$check( 'zero' === $page['rows'][0]['key'], 'filtered log page preserves insertion order' );
	$next = $repo->filtered_page( 'migrated', 1, array( 'is_migrated' => 1, 'object_type' => 'image' ), $page['cursor'] );
	$check( '09' === $next['rows'][0]['key'], 'canonical reordered filters bind same cursor' );
	$refuses( function () use ( $repo, $page ) { $repo->filtered_page( 'migrated', 1, array( 'is_migrated' => 0 ), $page['cursor'] ); }, 'different filters cannot reuse cursor' );
	$refuses( function () use ( $db, $blog ) { ( new LegacyTableRepository( $db, $blog, 3 ) )->identifiers( 'migrated', array( '09', 9 ) ); }, 'aggregate indexed batch row budget refuses' );
	$refuses( function () use ( $db, $blog ) { ( new LegacyTableRepository( $db, $blog, 100, 500 ) )->source_identities( 'migrated', array( array( 'type' => 'image', 'plugin' => 'Case', 'id' => '009' ) ) ); }, 'source tree byte budget refuses before payload decode' );
	$state_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM `' . $tables[0] . '` WHERE blog_id = %d AND state_key_hash = UNHEX(%s)', $blog, $codec->key_hash( 'migrated' ) ) );
	$snapshot->replace_many( array( $change( 'page-budget', $order, 'list', array_fill( 0, 7, str_repeat( 'x', 500000 ) ) ) ) );
	$refuses( function () use ( $repo ) { $repo->filtered_page( 'page-budget', 100, array() ); }, 'filtered page aggregate encoded bytes respect four-MiB ceiling' );
	$raw_rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM `' . $tables[1] . '` WHERE blog_id = %d AND state_id = %d ORDER BY id', $blog, $state_id ), ARRAY_A );
	$repo->patch_lists_and_scalars( array( $patch( 'queue', 1, array( array( 'key' => 0, 'value' => 'changed' ), array( 'key' => 2, 'value' => false ) ) ), array( 'key' => 'checkpoint', 'expected_revision' => 1, 'value' => null ) ) );
	$check( $repo->page( 'queue', 10 )['rows'] === array( array( 'key' => 0, 'value' => 'changed' ), array( 'key' => 1, 'value' => null ), array( 'key' => 2, 'value' => false ) ) && null === $repo->scalar( 'checkpoint' ), 'list update/append and scalar checkpoint commit together' );
	$check( $raw_rows === $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM `' . $tables[1] . '` WHERE blog_id = %d AND state_id = %d ORDER BY id', $blog, $state_id ), ARRAY_A ), 'unrelated history physical IDs/bytes unchanged by queue patch' );
	$refuses( function () use ( $repo, $patch ) { $repo->patch_lists_and_scalars( array( $patch( 'queue', 2, array( array( 'key' => 4, 'value' => 'sparse' ) ) ), array( 'key' => 'checkpoint', 'expected_revision' => 2, 'value' => 'partial' ) ) ); }, 'sparse append rolls back companion state' );
	$check( null === $repo->scalar( 'checkpoint' ) && 3 === $repo->count_roots( 'queue' ), 'sparse rollback retains checkpoint and list' );
	$refuses( function () use ( $snapshot ) { $snapshot->read_bounded( 'queue' ); }, 'dirty snapshot cannot be implicitly exported as verified' );
	$verified = $snapshot->verify_digest( 'queue', 2 );
	$check( $verified['logical_digest'] === $codec->snapshot_digest( array( 'changed', null, false ) ) && 2 === $verified['digest_revision'], 'one-root maintenance publishes exact canonical list digest without revision bump' );
	$check( array( 'changed', null, false ) === $snapshot->read_bounded( 'queue' ), 'explicit maintenance enables bounded snapshot read' );
	$check( $snapshot->verify_digest( 'empty', 1 )['logical_digest'] === $codec->snapshot_digest( array() ), 'empty collection maintenance has exact canonical digest' );
	$repo->patch_many( array( $patch( 'migrated', 1, array( array( 'key' => 'null', 'delete' => true ), array( 'key' => 'added', 'value' => $record ) ) ) ) );
	$history2 = $history; unset( $history2['null'] ); $history2['added'] = $record;
	$check( $snapshot->verify_digest( 'migrated', 2 )['logical_digest'] === $codec->snapshot_digest( $history2 ), 'maintenance handles map order gaps, nested children and binary/negative-zero values' );
	$check( $repo->identifiers( 'migrated', array( 'added' ) )[0]['value'] === $record, 'affected root writes maintain identifier projection' );
	$refuses( function () use ( $repo, $page ) { $repo->filtered_page( 'migrated', 1, array( 'object_type' => 'image', 'is_migrated' => 1 ), $page['cursor'] ); }, 'logical mutation invalidates filtered cursor' );
	$export = $repo->export_page( 'migrated', 1 );
	$check( 3 === $export['record_count'] && count( $export['rows'] ) === 1, 'explicit export page includes verified subtree accounting' );
	$refuses( function () use ( $db, $blog ) { ( new TableSnapshot( $db, $blog, 1 ) )->verify_digest( 'migrated', 2 ); }, 'maintenance whole-operation row admission refuses' );
	$refuses( function () use ( $db, $blog ) { ( new TableSnapshot( $db, $blog, 100, 500 ) )->verify_digest( 'migrated', 2 ); }, 'maintenance total byte admission refuses' );
	$identifier_row = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM `' . $tables[1] . '` WHERE state_id = %d AND generation = 1 AND legacy_identifier_hash = UNHEX(%s)', $state_id, $codec->key_hash( '09' ) ) );
	$orphan = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM `' . $tables[1] . '` WHERE id = %d', $identifier_row ), ARRAY_A );
	unset( $orphan['id'] ); $orphan['node_hash'] = hash( 'sha256', 'orphan-fixture', true ); $orphan['parent_node_hash'] = hash( 'sha256', 'missing-parent-fixture', true );
	$orphan['row_kind'] = 'child'; $orphan['depth'] = 1; $orphan['legacy_identifier_hash'] = null; $orphan['legacy_identifier_value'] = null;
	$check( 1 === $wpdb->insert( $tables[1], $orphan ), 'untracked orphan corruption fixture inserted' ); $orphan_id = (int) $wpdb->insert_id;
	$refuses( function () use ( $snapshot ) { $snapshot->verify_digest( 'migrated', 2 ); }, 'digest maintenance refuses untracked orphan rows instead of certifying partial state' );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM `' . $tables[1] . '` WHERE id = %d', $orphan_id ) );
	$stored_id = $wpdb->get_var( $wpdb->prepare( 'SELECT legacy_identifier_value FROM `' . $tables[1] . '` WHERE id = %d', $identifier_row ) );
	$wpdb->query( $wpdb->prepare( 'UPDATE `' . $tables[1] . '` SET legacy_identifier_value = %s WHERE id = %d', $codec->key_value( 'collision' ), $identifier_row ) );
	$refuses( function () use ( $repo ) { $repo->identifiers( 'migrated', array( '09' ) ); }, 'identifier hash collision/raw-value mismatch refuses' );
	$wpdb->query( $wpdb->prepare( 'UPDATE `' . $tables[1] . '` SET legacy_identifier_value = %s WHERE id = %d', $stored_id, $identifier_row ) );
	$wpdb->query( $wpdb->prepare( 'UPDATE `' . $tables[1] . '` SET is_selected = 0 WHERE id = %d', $identifier_row ) );
	$refuses( function () use ( $repo, $identity ) { $repo->root( 'migrated', '09' ); }, 'canonical/projection mismatch refuses selected-state corruption' );
	$wpdb->query( $wpdb->prepare( 'UPDATE `' . $tables[1] . '` SET is_selected = 1 WHERE id = %d', $identifier_row ) );
	$wpdb->query( $wpdb->prepare( 'UPDATE `' . $tables[0] . '` SET snapshot_digest = UNHEX(%s) WHERE id = %d', str_repeat( '0', 64 ), $state_id ) );
	$refuses( function () use ( $snapshot ) { $snapshot->verify_digest( 'migrated', 2 ); }, 'current digest corruption refuses rather than laundering state' );
	$wpdb->query( $wpdb->prepare( 'UPDATE `' . $tables[0] . '` SET snapshot_digest = UNHEX(%s) WHERE id = %d', $codec->snapshot_digest( $history2 ), $state_id ) );
	$writer = new LegacyTableRepository( new LegacyTransactionConnection( $wpdb ), $blog ); $triggered = false;
	$db->after_read = function ( $sql ) use ( &$triggered, $writer, $patch ) {
		if ( ! $triggered && false !== strpos( $sql, 'legacy_identifier_hash = UNHEX' ) ) { $triggered = true; $writer->patch_many( array( $patch( 'migrated', 2, array( array( 'key' => 'concurrent', 'value' => false ) ) ) ) ); }
	};
	$refuses( function () use ( $repo ) { $repo->identifiers( 'migrated', array( '09', 9 ) ); }, 'independent concurrent writer refuses mixed identifier result' ); $db->after_read = null;
	$triggered = false;
	$db->after_read = function ( $sql ) use ( &$triggered, $writer, $patch ) {
		if ( ! $triggered && false !== strpos( $sql, 'AS encoded_bytes' ) ) { $triggered = true; $writer->patch_many( array( $patch( 'migrated', 3, array( array( 'key' => 'maintenance-race', 'value' => null ) ) ) ) ); }
	};
	$refuses( function () use ( $snapshot ) { $snapshot->verify_digest( 'migrated', 3 ); }, 'independent writer invalidates dirty maintenance without publishing digest' ); $db->after_read = null;
	$snapshot->verify_digest( 'migrated', 4 );
	$db->after_read = function ( $sql ) { if ( false !== strpos( $sql, 'AS encoded_bytes' ) ) { usleep( 1100000 ); } };
	$refuses( function () use ( $db, $blog ) { ( new LegacyTableRepository( $db, $blog, 10000, 16777216, 1 ) )->identifiers( 'migrated', array( '09' ) ); }, 'cooperative elapsed-time budget refuses slow bounded read' ); $db->after_read = null;
	$db->query( 'SET autocommit = 0' );
	$refuses( function () use ( $snapshot ) { $snapshot->verify_digest( 'migrated', 4 ); }, 'maintenance refuses disabled autocommit' ); $db->query( 'ROLLBACK' ); $db->query( 'SET autocommit = 1' );
	$db->query( 'START TRANSACTION' );
	$refuses( function () use ( $snapshot ) { $snapshot->verify_digest( 'migrated', 4 ); }, 'maintenance refuses ambient transaction without replacing it' ); $db->query( 'ROLLBACK' );
	$uncertain_start = new TableSnapshot( $db, $blog ); $db->fail_start = true;
	$refuses( function () use ( $uncertain_start ) { $uncertain_start->verify_digest( 'migrated', 4 ); }, 'started but unacknowledged maintenance transaction refuses' );
	$db->fail_start = false; $db->query( 'ROLLBACK' ); $before = count( $db->queries );
	$refuses( function () use ( $uncertain_start ) { $uncertain_start->verify_digest( 'migrated', 4 ); }, 'uncertain transaction start quarantines maintenance object' );
	$check( $before === count( $db->queries ), 'uncertain start refusal performs zero SQL' );
	$repo->patch_many( array( $patch( 'migrated', 4, array( array( 'key' => 'uncertain', 'value' => false ) ) ) ) );
	$tainted = new TableSnapshot( $db, $blog ); $db->fail_commit = true;
	$refuses( function () use ( $tainted ) { $tainted->verify_digest( 'migrated', 5 ); }, 'committed but unacknowledged maintenance reports uncertainty' ); $db->fail_commit = false; $before = count( $db->queries );
	$refuses( function () use ( $tainted ) { $tainted->verify_digest( 'migrated', 5 ); }, 'uncertain maintenance object refuses replay' );
	$check( $before === count( $db->queries ), 'tainted maintenance refusal performs zero SQL' );
	if ( is_multisite() ) {
		$sites = get_sites( array( 'number' => 10, 'fields' => 'ids' ) ); $other = null;
		foreach ( $sites as $id ) { if ( (int) $id !== $blog ) { $other = (int) $id; break; } }
		$check( null !== $other, 'actual multisite fixture includes another registered site' );
		switch_to_blog( $other ); $switched = true;
		$other_db = new LegacyTransactionConnection( $wpdb ); $other_snapshot = new TableSnapshot( $other_db, (int) get_current_blog_id() );
		$other_snapshot->replace_many( array( $change( 'migrated', 0, 'map', array( '09' => false ) ) ) );
		$other_repo = new LegacyTableRepository( $other_db, (int) get_current_blog_id() );
		$check( false === $other_repo->identifiers( 'migrated', array( '09' ) )[0]['value'] && $record === $repo->identifiers( 'migrated', array( '09' ) )[0]['value'], 'real switch_to_blog sites isolate identical identifiers in shared base-prefix tables' );
		$refuses( function () use ( $other_repo, $export ) { $other_repo->export_page( 'migrated', 1, $export['cursor'] ); }, 'actual cross-site export cursor refuses' );
		restore_current_blog(); $switched = false;
	}
} finally {
	if ( $switched ) { restore_current_blog(); }
	foreach ( array_reverse( $created ) as $table ) { if ( false === $wpdb->query( 'DROP TABLE `' . $table . '`' ) ) { throw new RuntimeException( 'Fixture cleanup failed.' ); } }
	foreach ( $options as $key => $value ) { $check( get_option( $key, $missing ) === $value, 'public option/authority remains unchanged: ' . $key ); }
	foreach ( $created as $table ) { $check( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ), 'fixture table cleanup read-back: ' . $table ); }
}
print wp_json_encode( array( 'pass' => true, 'checks' => $checks, 'count' => count( $checks ), 'blog' => $blog, 'actual_multisite' => is_multisite(), 'db' => $wpdb->get_var( 'SELECT VERSION()' ), 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION ), JSON_PRETTY_PRINT ) . "\n";
