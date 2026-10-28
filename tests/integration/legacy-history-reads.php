<?php
/** Read-only common history dispatch on an explicitly admitted disposable WP DB. */
if ( ! defined( 'ABSPATH' ) || ! defined( 'FOOGM_STORAGE_TEST_DISPOSABLE' ) || true !== FOOGM_STORAGE_TEST_DISPOSABLE ) {
	throw new RuntimeException( 'Explicit disposable WordPress admission required.' );
}
$root = defined( 'FOOGM_STORAGE_TEST_ROOT' ) ? FOOGM_STORAGE_TEST_ROOT : dirname( dirname( __DIR__ ) );
require_once $root . '/migrate.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

use FooPlugins\FooGalleryMigrate\LegacyOptionBackend;
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use FooPlugins\FooGalleryMigrate\LegacyTableBackend;
use FooPlugins\FooGalleryMigrate\TableReader;
use FooPlugins\FooGalleryMigrate\TableSnapshot;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;
use FooPlugins\FooGalleryMigrate\MigratedHistoryRepository;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use FooPlugins\FooGalleryMigrate\StorageAuthority;

/** Observe real SQL and inject one concurrent write after candidate selection. */
class HistoryReadObservedConnection extends LegacyTransactionConnection {
	public $queries = array(); public $after_candidates;
	public function get_results( $sql, $format ) {
		$this->queries[] = $sql; $rows = parent::get_results( $sql, $format );
		if ( $this->after_candidates && false !== strpos( $sql, 'FORCE INDEX (`source_identity`)' ) && false !== strpos( $sql, 'LIMIT 101' ) ) {
			$action = $this->after_candidates; $this->after_candidates = null; $action();
		}
		return $rows;
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
$checks = array(); $created = array(); $writes = 0;
$check = function ( $ok, $label ) use ( &$checks ) { if ( ! $ok ) { throw new RuntimeException( 'FAIL: ' . $label ); } $checks[] = $label; };
$refuses = function ( $call, $label ) use ( $check ) { try { $call(); } catch ( RuntimeException $error ) { $check( true, $label ); return; } $check( false, $label ); };
$observe = function ( $name ) use ( &$writes ) { if ( FOOGALLERY_MIGRATE_OPTION_DATA === $name ) { $writes++; } };
$change = function ( $key, $order, $kind, $value ) { return array( 'key' => $key, 'order' => $order, 'kind' => $kind, 'value' => $value, 'expected_revision' => null ); };
$blog = (int) get_current_blog_id();
try {
	foreach ( $schema->statements() as $i => $ddl ) { $created[] = $tables[$i]; dbDelta( $ddl ); }
	$check( $schema->verify(), 'unchanged schema v1' );
	$db = new LegacyTransactionConnection( $wpdb ); $backend = new LegacyTableBackend( $db, $blog );
	$snapshot = new TableSnapshot( $db, $blog ); $reader = new TableReader( $db, $blog );
	$record = array( 'object_type' => 'image', 'plugin_name' => '', 'ID' => '009', 'migrated' => false, 'migrated_id' => 71,
		'migration_status' => 'completed', 'source_url' => 'https://example.test/Case.jpg', 'migration_parent_key' => 'Parent',
		'unknown' => '雪', 'settings' => array( '09' => false, 9 => null ), 'children' => array( '09' => false, 9 => null ) );
	$later = $record; $later['migrated_id'] = 72; $later['migrated'] = true;
	$history = array( 'before' => -0.0, 'items' => array( '09' => $record, 9 => null, 'stored-false' => false, 'later' => $later ),
		MigratorSettings::COMPACT_MARKER => 1, 'after' => '雪', 'type' => 'migratable' );
	$data = array( 'migrated' => $history, 'migrated-revision' => 5 );
	$snapshot->replace_many( array( $change( 'migrated', 0, 'map', $history ), $change( 'migrated-revision', 1, 'scalar', 5 ) ) );
	$check( ( new LegacyOptionBackend() )->replace_all( $data ), 'seed option parity fixture' );
	$settings = new MigratorSettings(); $option = new MigratedHistoryRepository( new LegacyOptionBackend(), $settings );
	$logical = new MigratedHistoryRepository( $backend, $settings );
	add_action( 'updated_option', $observe ); add_action( 'added_option', $observe );
	$metadata = $backend->metadata_many( array( 'migrated', 'migrated-revision' ) );
	$check( $metadata[0]['root']['root_count'] === 4 && $metadata[0]['root']['wrapper']['values']['after'] === '雪' && ! isset( $metadata[0]['root']['items'] ), 'bounded collection metadata excludes all item payloads' );
	$check( $metadata[1]['root']['value'] === 5 && $metadata[0]['head']['revision'] === 1, 'logical scalar revision differs from physical CAS revision' );
	foreach ( array( '09', 9, 'stored-false', 'absent' ) as $key ) {
		$check( $logical->has( $key ) === $option->has( $key ) && serialize( $logical->get( $key ) ) === serialize( $option->get( $key ) ), 'typed presence and hydration: ' . gettype( $key ) . ':' . $key );
	}
	$keys = array( 9, 'absent', '09', 'stored-false' );
	$check( serialize( $logical->get_many( $keys ) ) === serialize( $option->get_many( $keys ) ), 'requested order and opaque false/null tuples' );
	$refuses( function () use ( $logical ) { $logical->get_many( array( '09', '09' ) ); }, 'duplicate history keys refuse without normalization' );
	$check( $logical->count() === $option->count() && $logical->revision() === $option->revision(), 'count and logical revision parity' );
	$check( $logical->find_by_source_identity( 'image', '', '009' )->migrated_id === $option->find_by_source_identity( 'image', '', '009' )->migrated_id, 'first positive target wins even with migrated false' );
	$physical = $backend->source_identities( 'migrated', array( array( 'type' => 'image', 'plugin' => '', 'id' => '009' ) ) );
	$check( $physical[0]['key'] === 'later', 'existing physical migrated-flag lookup policy unchanged' );
	$identity_codec = new \FooPlugins\FooGalleryMigrate\LegacyStateCodec(); $identity_hash = $identity_codec->snapshot_digest( array( 'source-exact-v1', 'image', '', '009' ) );
	foreach ( array( 0, 1 ) as $flag ) {
		$explain = $db->get_results( $db->prepare( 'EXPLAIN SELECT id, item_order, HEX(node_hash) AS node_hash FROM `' . $tables[1] . '` FORCE INDEX (`source_identity`) WHERE blog_id = %d AND state_id = %d AND generation = %d AND source_identity_hash = UNHEX(%s) AND is_migrated = %d AND migrated_id > 0 AND parent_node_hash IS NULL ORDER BY item_order ASC, id ASC LIMIT 1', array( $blog, $metadata[0]['head']['id'], $metadata[0]['head']['active_generation'], $identity_hash, $flag ) ), ARRAY_A );
		$check( count( $explain ) === 1 && $explain[0]['key'] === 'source_identity' && false === stripos( $explain[0]['Extra'], 'filesort' ), 'native exact source selector uses ordering index without filesort: flag ' . $flag );
	}
	$check( false === $logical->find_by_source_identity( 'image', '', '9' ), 'exact source ID retains leading zeroes' );
	$page = $logical->iterate_page( 2 ); $next = $logical->iterate_page( 2, $page['cursor'] );
	$check( count( $page['rows'] ) === 2 && null !== $page['cursor'] && $page['revision'] === 5 && null === $next['cursor'] && count( $next['rows'] ) === 2, 'exact full terminal page uses null cursor without extra empty read' );
	$all = array_merge( $page['rows'], $next['rows'] );
	$check( array_column( $all, 'key' ) === array( '09', 9, 'stored-false', 'later' ), 'paged keys retain types and insertion order' );
	$filtered = $logical->page_log( 'image', 'completed', 2 );
	$check( count( $filtered['rows'] ) === 2 && null === $filtered['cursor'] && $filtered['revision'] === 5, 'filtered terminal page translated to logical contract' );
	$empty = $logical->page_log( 'gallery', '', 1 );
	$check( array() === $empty['rows'] && null === $empty['cursor'], 'empty filter yields terminal cursor' );
	$refuses( function () use ( $logical, $page ) { $logical->page_log( 'image', '', 2, $page['cursor'] ); }, 'cross-filter cursor refuses' );
	$refuses( function () use ( $db, $blog ) { ( new LegacyTableBackend( $db, $blog, 1 ) )->metadata_many( array( 'migrated', 'migrated-revision' ) ); }, 'shared multi-head row budget refuses' );
	$refuses( function () use ( $db, $blog ) { ( new LegacyTableBackend( $db, $blog, 10000, 16 ) )->metadata_many( array( 'migrated', 'migrated-revision' ) ); }, 'shared multi-head byte budget refuses before root retrieval' );
	$refuses( function () use ( $db, $blog ) { ( new MigratedHistoryRepository( new LegacyTableBackend( $db, $blog, 3 ), new MigratorSettings() ) )->get( 'absent' ); }, 'logical operation shares row budget across both metadata checks' );
	$state_codec = new \FooPlugins\FooGalleryMigrate\LegacyStateCodec();
	$one_context_bytes = $metadata[0]['head']['root_payload_bytes'] + $metadata[1]['head']['root_payload_bytes'] + strlen( $state_codec->key_value( 'migrated' ) ) + strlen( $state_codec->key_value( 'migrated-revision' ) );
	$refuses( function () use ( $db, $blog, $one_context_bytes ) { ( new MigratedHistoryRepository( new LegacyTableBackend( $db, $blog, 10000, $one_context_bytes + 100 ), new MigratorSettings() ) )->get( 'absent' ); }, 'logical operation shares encoded byte budget across metadata and lookup' );
	$refuses( function () use ( $backend ) { $backend->metadata_many( array( 'missing' ) ); }, 'missing metadata has no fallback' );
	$url_observed = new HistoryReadObservedConnection( $wpdb );
	$url_logical = new MigratedHistoryRepository( new LegacyTableBackend( $url_observed, $blog ), $settings );
	$url_heads = $backend->metadata_many( array( 'migrated', 'migrated-revision' ) );
	$url_option = serialize( get_option( FOOGALLERY_MIGRATE_OPTION_DATA ) );
	foreach ( array( '', null, false, true, 0, 1.5, array(), new stdClass() ) as $url ) {
		$check( false === $url_logical->find_by_source_url( 'image', $url ) && false === $option->find_by_source_url( 'image', $url ), 'empty/non-string source URL is a no-read false result: ' . gettype( $url ) );
	}
	$check( 71 === $option->find_by_source_url( 'image', 'https://example.test/Case.jpg' )->migrated_id && false === $option->find_by_source_url( 'image', 'https://example.test/case.jpg' ), 'option source URL keeps first-positive exact-case semantics' );
	$check( 71 === $url_logical->find_by_source_url( 'image', 'https://example.test/Case.jpg' )->migrated_id &&
		false === $url_logical->find_by_source_url( 'image', 'https://example.test/case.jpg' ), 'bounded source URL scan keeps first exact-case option result' );
	$check( false === $url_logical->find_by_source_url( 'image', ' ' ) && false === $url_logical->find_by_source_url( 'image', "\xff\x00" ), 'unknown source URLs remain negative matches' );
	$refuses( function () use ( $url_logical ) { $url_logical->find_by_source_url( 'image', str_repeat( 'u', 1048577 ) ); }, 'oversized URL request refuses before history page payload' );
	$pin = $url_logical->tag_build_identity();
	$numeric = $url_logical->tag_numeric_targets( array( array( 'type' => 'image', 'plugin' => '', 'id' => '009' ) ), $pin );
	$check( $numeric === array( 71 ), 'numeric image source finds first positive target despite migrated false' );
	$numeric_select = '';
	foreach ( $url_observed->queries as $query ) { if ( false !== strpos( $query, 'FORCE INDEX (`source_numeric`)' ) ) { $numeric_select = $query; break; } }
	$check( '' !== $numeric_select && false !== strpos( $numeric_select, 'source_numeric_identity_hash = UNHEX(' ) &&
		false === strpos( $numeric_select, 'AND is_migrated =' ), 'numeric lookup uses insertion-order index without migrated-flag filtering' );
	$check( count( $url_observed->queries ) > 0 && $url_heads === $backend->metadata_many( array( 'migrated', 'migrated-revision' ) ) &&
		$url_option === serialize( get_option( FOOGALLERY_MIGRATE_OPTION_DATA ) ) && 0 === $writes, 'bounded tag source reads preserve heads/options without fallback' );
	$check( false === $logical->find_by_source_identity( 'image', null, '009' ), 'null plugin does not match an empty string plugin' );
	$refuses( function () use ( $logical ) { $logical->summary(); }, 'per-type summary with ambiguous empty types remains unadmitted' );
	$refuses( function () use ( $logical ) { $logical->get_by_parent( 'gallery' ); }, 'unbounded parent scan refuses rather than option fallback' );
	$refuses( function () use ( $logical ) { $logical->read_snapshot(); }, 'no implicit whole-history materialization' );
	$check( 0 === $writes && serialize( get_option( FOOGALLERY_MIGRATE_OPTION_DATA ) ) === serialize( $data ), 'all logical reads/refusals leave options unchanged' );
	$backend->patch_many( array( array( 'key' => 'migrated-revision', 'expected_revision' => 1, 'value' => 6 ) ) );
	$refuses( function () use ( $logical, $page ) { $logical->iterate_page( 2, $page['cursor'] ); }, 'cursor also binds scalar revision head, not just history CAS' );
	$page = $logical->iterate_page( 2 );
	$backend->patch_many( array( array( 'key' => 'migrated', 'expected_revision' => 1, 'patches' => array( array( 'key' => 'later', 'value' => $record ) ) ),
		array( 'key' => 'migrated-revision', 'expected_revision' => 2, 'value' => 7 ) ) );
	$refuses( function () use ( $logical, $page ) { $logical->iterate_page( 2, $page['cursor'] ); }, 'stale cursor refuses after atomic physical patch' );
	$check( $logical->revision() === 7 && $logical->get( 'later' )->migrated_id === 71, 'dirty digest allows only verified bounded logical reads' );
	$refuses( function () use ( $snapshot ) { $snapshot->read_bounded( 'migrated' ); }, 'dirty full digest remains an explicit maintenance gate' );
	$refuses( function () use ( $db, $blog ) { ( new MigratedHistoryRepository( new LegacyTableBackend( $db, $blog + 1000 ), new MigratorSettings() ) )->get( '09' ); }, 'wrong-blog lookup refuses without options' );
	$check( $reader->head( 'migrated' )['revision'] === 2 && 0 === $writes, 'reads never advance physical/logical revision or option state' );
	$null_id = $record; $null_id['ID'] = null; $empty_id = $record; $empty_id['ID'] = '';
	$backend->patch_many( array( array( 'key' => 'migrated', 'expected_revision' => 2, 'patches' => array( array( 'key' => 'null-id', 'value' => $null_id ), array( 'key' => 'empty-id', 'value' => $empty_id ) ) ) ) );
	$check( $logical->find_by_source_identity( 'image', '', '' )->ID === '', 'null projection candidate is skipped for exact empty scalar ID' );
	$check( $logical->find_by_source_identity( 'image', '', false )->ID === '', 'false request preserves option string-cast empty-ID semantics' );
	$check( false === $logical->find_by_source_identity( 'image', '', null ), 'null request remains a no-read false result' );
	$seed = function ( $items ) use ( $snapshot, $reader, $observe ) {
		remove_action( 'updated_option', $observe ); remove_action( 'added_option', $observe );
		$history = array( MigratorSettings::COMPACT_MARKER => 1, 'type' => 'migratable', 'items' => $items );
		$snapshot->replace_many( array( array( 'key' => 'migrated', 'order' => 0, 'kind' => 'map', 'value' => $history, 'expected_revision' => $reader->head( 'migrated' )['revision'] ),
			array( 'key' => 'migrated-revision', 'order' => 1, 'kind' => 'scalar', 'value' => 5, 'expected_revision' => $reader->head( 'migrated-revision' )['revision'] ) ) );
		( new LegacyOptionBackend() )->replace_all( array( 'migrated' => $history, 'migrated-revision' => 5 ) );
		add_action( 'updated_option', $observe ); add_action( 'added_option', $observe );
	};
	$false_id = $empty_id; $false_id['ID'] = false; $false_id['migrated_id'] = 73;
	$no_id = $empty_id; unset( $no_id['ID'] );
	$nested = $record; $nested['children'] = array( 'nested-empty' => $empty_id );
	$zero = $empty_id; $zero['migrated_id'] = 0;
	$other_plugin = $empty_id; $other_plugin['plugin_name'] = 'Other';
	$seed( array( 'missing' => $no_id, 'null' => $null_id, 'zero' => $zero, 'nested' => $nested, 'other' => $other_plugin, 'first' => $false_id, 'later' => $empty_id ) );
	$check( serialize( $logical->find_by_source_identity( 'image', '', '' ) ) === serialize( $option->find_by_source_identity( 'image', '', '' ) ), 'empty-ID option parity includes false first winner, nested exclusion and positive target eligibility' );
	$check( false === $logical->find_by_source_identity( 'Image', '', '' ), 'empty-ID type remains case sensitive' );
	$check( false === $logical->find_by_source_identity( 'image', 'other', '' ), 'empty-ID plugin remains case sensitive' );
	$check( false === $logical->find_by_source_identity( 'image', ' ', '' ), 'empty-ID significant plugin spaces remain exact' );
	$check( false === $logical->find_by_source_identity( 'image', null, '' ), 'null plugin remains distinct from empty and named plugins for empty IDs' );
	$seed( array( 'missing' => $no_id, 'null' => $null_id, 'zero' => $zero, 'nested' => $nested ) );
	$check( false === $logical->find_by_source_identity( 'image', '', '' ) && false === $option->find_by_source_identity( 'image', '', '' ), 'null-only hash candidates return false without treating missing or child IDs as roots' );
	$items = array();
	for ( $i = 0; $i < 99; $i++ ) { $v = $null_id; $v['migrated'] = 0 === $i % 2; $items['null-' . $i] = $v; }
	$items['winner'] = $false_id;
	for ( $i = 0; $i < 1200; $i++ ) { $items['unrelated-' . $i] = $record; }
	$seed( $items );
	$check( serialize( $logical->find_by_source_identity( 'image', '', '' ) ) === serialize( $option->find_by_source_identity( 'image', '', '' ) ), '100 exact-hash candidates admitted despite 1200 unrelated roots' );
	$refuses( function () use ( $db, $blog ) { ( new MigratedHistoryRepository( new LegacyTableBackend( $db, $blog, 20 ), new MigratorSettings() ) )->find_by_source_identity( 'image', '', '' ); }, 'empty-ID candidate selection shares logical row budget' );
	$refuses( function () use ( $db, $blog ) { ( new MigratedHistoryRepository( new LegacyTableBackend( $db, $blog, 10000, 2048 ), new MigratorSettings() ) )->find_by_source_identity( 'image', '', '' ); }, 'empty-ID canonical trees share encoded byte budget' );
	$items['overflow'] = $null_id; $seed( $items );
	$refuses( function () use ( $logical ) { $logical->find_by_source_identity( 'image', '', '' ); }, '101 combined flag candidates refuse rather than return partial answer' );
	$seed( array( 'early-winner' => $empty_id ) + $items );
	$refuses( function () use ( $logical ) { $logical->find_by_source_identity( 'image', '', '' ); }, 'candidate overflow refuses even with early exact match' );
	$seed( array() );
	$check( false === $logical->find_by_source_identity( 'image', '', '' ), 'empty history has no empty-ID match' );
	$seed( array( 'null' => $null_id, 'winner' => $empty_id ) );
	$observed = new HistoryReadObservedConnection( $wpdb );
	$observed_backend = new LegacyTableBackend( $observed, $blog );
	$observed_logical = new MigratedHistoryRepository( $observed_backend, new MigratorSettings() );
	$before = $backend->metadata_many( array( 'migrated', 'migrated-revision' ) ); $option_before = serialize( get_option( FOOGALLERY_MIGRATE_OPTION_DATA ) );
	$check( '' === $observed_logical->find_by_source_identity( 'image', '', '' )->ID, 'observed empty-ID canonical match' );
	$selectors = 0;
	foreach ( $observed->queries as $sql ) {
		if ( false === strpos( $sql, 'FORCE INDEX (`source_identity`)' ) ) { continue; }
		$selectors++;
		$check( false !== strpos( $sql, 'LIMIT 101' ) && false === strpos( $sql, 'payload' ), 'empty-ID selector is bounded projection-only SQL' );
		$plan = $db->get_results( 'EXPLAIN ' . $sql, ARRAY_A );
		$check( count( $plan ) === 1 && $plan[0]['key'] === 'source_identity' && false === stripos( $plan[0]['Extra'], 'filesort' ), 'empty-ID selector uses exact identity ordering index without filesort' );
	}
	$check( 2 === $selectors && $before === $backend->metadata_many( array( 'migrated', 'migrated-revision' ) ) && $option_before === serialize( get_option( FOOGALLERY_MIGRATE_OPTION_DATA ) ), 'two fixed-flag selectors preserve both heads and exact option bytes' );
	$peer_wpdb = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ); $peer_wpdb->base_prefix = $wpdb->base_prefix;
	$peer = new LegacyTransactionConnection( $peer_wpdb );
	$check( $db->get_results( 'SELECT CONNECTION_ID() AS id', ARRAY_A )[0]['id'] !== $peer->get_results( 'SELECT CONNECTION_ID() AS id', ARRAY_A )[0]['id'], 'concurrency fixture uses two actual pinned connections' );
	$observed->after_candidates = function () use ( $peer, $blog, $reader ) {
		( new LegacyTableBackend( $peer, $blog ) )->patch_many( array( array( 'key' => 'migrated-revision', 'expected_revision' => $reader->head( 'migrated-revision' )['revision'], 'value' => 6 ) ) );
	};
	$refuses( function () use ( $observed_logical ) { $observed_logical->find_by_source_identity( 'image', '', '' ); }, 'empty-ID concurrent logical revision change refuses' );
	$observed->after_candidates = function () use ( $peer, $blog, $reader, $empty_id ) {
		$v = $empty_id; $v['ID'] = 'changed';
		( new LegacyTableBackend( $peer, $blog ) )->patch_many( array( array( 'key' => 'migrated', 'expected_revision' => $reader->head( 'migrated' )['revision'], 'patches' => array( array( 'key' => 'winner', 'value' => $v ) ) ) ) );
	};
	$refuses( function () use ( $observed_logical ) { $observed_logical->find_by_source_identity( 'image', '', '' ); }, 'empty-ID same-total concurrent identity update refuses' );
	$check( false === $logical->find_by_source_identity( 'image', '', '' ), 'verified dirty history remains readable without full rehash' );
	$check( 0 === $writes, 'empty-ID reads perform no observed option writes' );

	$pluginless = $record; $pluginless['plugin_name'] = null; $pluginless['children'] = array();
	$absent_plugin = $pluginless; unset( $absent_plugin['plugin_name'] ); $absent_plugin['migrated_id'] = 74;
	$pluginless_later = $pluginless; $pluginless_later['migrated'] = true; $pluginless_later['migrated_id'] = 75;
	$pluginless_zero = $pluginless; $pluginless_zero['migrated_id'] = 0;
	$pluginless_null_id = $pluginless; $pluginless_null_id['ID'] = null;
	$pluginless_missing_id = $pluginless; unset( $pluginless_missing_id['ID'] );
	$pluginless_other_type = $pluginless; $pluginless_other_type['object_type'] = 'gallery';
	$pluginless_nested = $record; $pluginless_nested['children'] = array( 'child' => $pluginless );
	$seed( array( 'zero' => $pluginless_zero, 'nested' => $pluginless_nested, 'empty-plugin' => $record, 'other-type' => $pluginless_other_type,
		'null-id' => $pluginless_null_id, 'missing-id' => $pluginless_missing_id, 'first' => $absent_plugin, 'later' => $pluginless_later ) );
	$check( serialize( $logical->find_by_source_identity( 'image', null, '009' ) ) === serialize( $option->find_by_source_identity( 'image', null, '009' ) ) && $logical->find_by_source_identity( 'image', null, '009' )->migrated_id === 74, 'null-plugin option parity: missing field matches null, first positive root wins across flags' );
	$check( false === $logical->find_by_source_identity( 'image', null, 9 ) && false === $logical->find_by_source_identity( 'Image', null, '009' ), 'null-plugin type and leading-zero source IDs remain exact' );
	$check( serialize( $logical->find_by_source_identity( 'gallery', null, '009' ) ) === serialize( $option->find_by_source_identity( 'gallery', null, '009' ) ), 'null-plugin candidates are filtered by canonical type' );
	$refuses( function () use ( $backend ) { $backend->source_identities( 'migrated', array( array( 'type' => 'image', 'plugin' => null, 'id' => '009' ) ) ); }, 'physical null-plugin lookup admission remains unchanged' );
	foreach ( array( '', false, 0, true, 1.5 ) as $id ) {
		$v = $pluginless; $v['ID'] = $id;
		$seed( array( 'null-id' => $pluginless_null_id, 'missing-id' => $pluginless_missing_id, 'winner' => $v ) );
		$check( serialize( $logical->find_by_source_identity( 'image', null, $id ) ) === serialize( $option->find_by_source_identity( 'image', null, $id ) ), 'null-plugin raw scalar source parity: ' . gettype( $id ) . ':' . (string) $id );
	}
	$seed( array( 'null-id' => $pluginless_null_id, 'missing-id' => $pluginless_missing_id ) );
	$check( false === $logical->find_by_source_identity( 'image', null, '' ), 'null-plugin null and missing source IDs are not scalar matches' );
	$items = array();
	for ( $i = 0; $i < 99; $i++ ) { $v = $pluginless_null_id; $v['migrated'] = 0 === $i % 2; $items['null-plugin-' . $i] = $v; }
	$items['winner'] = $absent_plugin;
	for ( $i = 0; $i < 1200; $i++ ) { $items['unrelated-' . $i] = $record; }
	$seed( $items );
	$check( serialize( $logical->find_by_source_identity( 'image', null, '009' ) ) === serialize( $option->find_by_source_identity( 'image', null, '009' ) ), '100 null-plugin bucket candidates admitted despite 1200 unrelated roots' );
	$refuses( function () use ( $db, $blog ) { ( new MigratedHistoryRepository( new LegacyTableBackend( $db, $blog, 20 ), new MigratorSettings() ) )->find_by_source_identity( 'image', null, '009' ); }, 'null-plugin candidates share logical row budget' );
	$refuses( function () use ( $db, $blog ) { ( new MigratedHistoryRepository( new LegacyTableBackend( $db, $blog, 10000, 2048 ), new MigratorSettings() ) )->find_by_source_identity( 'image', null, '009' ); }, 'null-plugin trees share logical encoded byte budget' );
	$items['overflow'] = $pluginless_other_type; $seed( $items );
	$refuses( function () use ( $logical ) { $logical->find_by_source_identity( 'image', null, '009' ); }, '101 null-plugin combined candidates refuse including other types before canonical filtering' );
	$seed( array( 'early-winner' => $pluginless ) + $items );
	$observed->queries = array();
	$refuses( function () use ( $observed_logical ) { $observed_logical->find_by_source_identity( 'image', null, '009' ); }, 'null-plugin overflow refuses even with early exact match' );
	$check( ! array_filter( $observed->queries, function ( $sql ) { return false !== strpos( $sql, 'SELECT' ) && false !== strpos( $sql, 'mutable_payload' ); } ), 'null-plugin overflow refuses before canonical tree payload retrieval' );
	$seed( array() );
	$check( false === $logical->find_by_source_identity( 'image', null, '009' ), 'empty history has no null-plugin match' );
	$seed( array( 'null-id' => $pluginless_null_id, 'winner' => $absent_plugin, 'later' => $pluginless_later ) );
	$observed->queries = array();
	$before = $backend->metadata_many( array( 'migrated', 'migrated-revision' ) ); $option_before = serialize( get_option( FOOGALLERY_MIGRATE_OPTION_DATA ) );
	$check( serialize( $observed_logical->find_by_source_identity( 'image', null, '009' ) ) === serialize( $option->find_by_source_identity( 'image', null, '009' ) ), 'observed null-plugin canonical option match' );
	$selectors = 0;
	foreach ( $observed->queries as $sql ) {
		if ( false === strpos( $sql, 'FORCE INDEX (`source_identity`)' ) ) { continue; }
		$selectors++;
		$check( false !== strpos( $sql, 'source_identity_hash IS NULL AND source_plugin_hash IS NULL' ) && false !== strpos( $sql, 'LIMIT 101' ) && false === strpos( $sql, 'payload' ), 'null-plugin selector is bounded projection-only NULL-bucket SQL' );
		$plan = $db->get_results( 'EXPLAIN ' . $sql, ARRAY_A );
		$check( count( $plan ) === 1 && $plan[0]['key'] === 'source_identity' && false === stripos( $plan[0]['Extra'], 'filesort' ), 'null-plugin selector uses source identity ordering index without filesort' );
	}
	$check( 2 === $selectors && $before === $backend->metadata_many( array( 'migrated', 'migrated-revision' ) ) && $option_before === serialize( get_option( FOOGALLERY_MIGRATE_OPTION_DATA ) ), 'null-plugin two fixed-flag selectors preserve both heads and exact option bytes' );
	$observed->queries = array();
	$check( false === $observed_logical->find_by_source_identity( 'image', null, null ) && array() === $observed->queries, 'null-plugin null source request returns false without SQL' );
	$observed->after_candidates = function () use ( $peer, $blog, $reader ) {
		( new LegacyTableBackend( $peer, $blog ) )->patch_many( array( array( 'key' => 'migrated-revision', 'expected_revision' => $reader->head( 'migrated-revision' )['revision'], 'value' => 6 ) ) );
	};
	$refuses( function () use ( $observed_logical ) { $observed_logical->find_by_source_identity( 'image', null, '009' ); }, 'null-plugin concurrent logical revision change refuses on two actual connections' );
	$observed->after_candidates = function () use ( $peer, $blog, $reader, $absent_plugin ) {
		$v = $absent_plugin; $v['plugin_name'] = '';
		( new LegacyTableBackend( $peer, $blog ) )->patch_many( array( array( 'key' => 'migrated', 'expected_revision' => $reader->head( 'migrated' )['revision'], 'patches' => array( array( 'key' => 'winner', 'value' => $v ) ) ) ) );
	};
	$refuses( function () use ( $observed_logical ) { $observed_logical->find_by_source_identity( 'image', null, '009' ); }, 'null-plugin same-total concurrent plugin update refuses' );
	$dirty_head = $reader->head( 'migrated' );
	$dirty_rows = $db->get_results( $db->prepare( 'SELECT digest_status FROM `' . $tables[0] . '` WHERE blog_id = %d AND id = %d', array( $blog, $dirty_head['id'] ) ), ARRAY_A );
	$check( $logical->find_by_source_identity( 'image', null, '009' )->migrated_id === 75 && count( $dirty_rows ) === 1 && 'dirty' === $dirty_rows[0]['digest_status'], 'null-plugin verified dirty history reads canonical next winner without rehash' );
	$check( 0 === $writes, 'null-plugin reads perform no observed option writes' );
} finally {
	remove_action( 'updated_option', $observe ); remove_action( 'added_option', $observe );
	if ( array_key_exists( FOOGALLERY_MIGRATE_OPTION_DATA, $options ) ) {
		if ( $options[FOOGALLERY_MIGRATE_OPTION_DATA] === $missing ) { delete_option( FOOGALLERY_MIGRATE_OPTION_DATA ); }
		else { update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $options[FOOGALLERY_MIGRATE_OPTION_DATA], false ); }
	}
	foreach ( array_reverse( $created ) as $table ) { if ( false === $wpdb->query( 'DROP TABLE `' . $table . '`' ) ) { throw new RuntimeException( 'Fixture cleanup failed.' ); } }
	foreach ( $options as $key => $before ) { $check( get_option( $key, $missing ) === $before, 'option cleanup read back: ' . $key ); }
	foreach ( $created as $table ) { $check( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) && empty( $wpdb->last_error ), 'table cleanup read back: ' . $table ); }
}
fwrite( STDOUT, wp_json_encode( array( 'ok' => true, 'blog_id' => $blog, 'actual_multisite' => is_multisite(), 'check_count' => count( $checks ), 'checks' => $checks ) ) . "\n" );
