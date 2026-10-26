<?php
/** SQL-only logical log summary, on an explicitly admitted disposable WP DB. */
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

/** Records actual queries; fault callbacks run over a second pinned connection. */
class HistorySummaryObservedConnection extends LegacyTransactionConnection {
	public $queries = array(); public $after_group;
	public function get_results( $sql, $format ) {
		$this->queries[] = $sql; $rows = parent::get_results( $sql, $format );
		if ( $this->after_group && false !== strpos( $sql, 'GROUP BY' ) ) {
			$action = $this->after_group; $this->after_group = null; $action();
		}
		return $rows;
	}
}

global $wpdb;
$schema = new LegacyStorageSchema( $wpdb ); $tables = array_keys( $schema->definitions() );
foreach ( $tables as $table ) {
	if ( null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) || ! empty( $wpdb->last_error ) ) { throw new RuntimeException( 'Refusing existing tables/unhealthy fixture DB.' ); }
}
$missing = new stdClass(); $options = array();
foreach ( array( FOOGALLERY_MIGRATE_OPTION_DATA, FOOGALLERY_MIGRATE_OPTION_SETTINGS, StorageAuthority::CONTROL_OPTION, StorageAuthority::AUTHORITY_OPTION, StorageAuthority::MAINTENANCE_OPTION, LegacyStorageSchema::VERSION_OPTION, LegacyStorageSchema::LOCK_OPTION ) as $key ) { $options[$key] = get_option( $key, $missing ); }
$checks = array(); $created = array(); $writes = 0;
$check = function ( $ok, $label ) use ( &$checks ) { if ( ! $ok ) { throw new RuntimeException( 'FAIL: ' . $label ); } $checks[] = $label; };
$refuses = function ( $call, $message, $label ) use ( $check ) {
	try { $call(); } catch ( RuntimeException $error ) { $check( false !== strpos( $error->getMessage(), $message ), $label . ': ' . $error->getMessage() ); return; }
	$check( false, $label );
};
$observe = function ( $name ) use ( &$writes ) { if ( FOOGALLERY_MIGRATE_OPTION_DATA === $name ) { $writes++; } };
$blog = (int) get_current_blog_id();
try {
	foreach ( $schema->statements() as $i => $ddl ) { $created[] = $tables[$i]; dbDelta( $ddl ); }
	$check( $schema->verify(), 'unchanged schema v1' );
	$db = new LegacyTransactionConnection( $wpdb ); $snapshot = new TableSnapshot( $db, $blog ); $reader = new TableReader( $db, $blog );
	$observed = new HistorySummaryObservedConnection( $wpdb ); $backend = new LegacyTableBackend( $observed, $blog );
	$logical = new MigratedHistoryRepository( $backend, new MigratorSettings() );
	$option = new MigratedHistoryRepository( new LegacyOptionBackend(), new MigratorSettings() );
	$seed = function ( $items ) use ( $snapshot, $reader, $check, &$writes ) {
		$head = $reader->head( 'migrated' ); $revision = false === $head ? null : $head['revision'];
		$history = array( 'before' => -0.0, 'items' => $items, MigratorSettings::COMPACT_MARKER => 1, 'after' => '雪', 'type' => 'migratable' );
		$changes = array( array( 'key' => 'migrated', 'order' => 0, 'kind' => 'map', 'value' => $history, 'expected_revision' => $revision ) );
		if ( null === $revision ) { $changes[] = array( 'key' => 'migrated-revision', 'order' => 1, 'kind' => 'scalar', 'value' => 5, 'expected_revision' => null ); }
		$snapshot->replace_many( $changes );
		$check( ( new LegacyOptionBackend() )->replace_all( array( 'migrated' => $history, 'migrated-revision' => 5 ) ), 'real option fixture seed succeeds' ); $writes = 0;
	};
	$record = function ( $type, $status ) { return array( 'object_type' => $type, 'migration_status' => $status ); };
	$items = array( '09' => $record( 'unknown', 'pending' ), 9 => null, 'opaque' => false,
		'case' => $record( 'Image', 'Error' ), 'lower' => $record( 'image', 'error' ),
		'trailing' => $record( 'image ', 'error ' ), 'blank' => $record( ' ', ' ' ),
		'again' => $record( 'unknown', 'completed' ), 'empty-type' => $record( '', '' ),
		'no-type' => array( 'migration_status' => 'error' ) );
	$items['lower']['error'] = false; $items['again']['error'] = 'not a status error';
	$items['09']['children'] = array( $record( 'child-only', 'error' ) );
	$seed( $items ); add_action( 'updated_option', $observe ); add_action( 'added_option', $observe );
	$before = $backend->metadata_many( array( 'migrated', 'migrated-revision' ) );
	$option_before = serialize( get_option( FOOGALLERY_MIGRATE_OPTION_DATA ) );
	$observed->queries = array(); $summary = $logical->log_summary(); $summary_queries = $observed->queries;
	$check( serialize( $summary ) === serialize( $option->log_summary() ), 'exact option totals/aliases and first occurrence order; opaque and nested children excluded' );
	$check( $summary['total'] === 6 && $summary['error'] === 1 && $summary['completed'] === 1, 'status errors are distinct from error payload and migrated flags' );
	$check( array_keys( $summary['types'] ) === array( 'unknown', 'Image', 'image', 'image ', ' ' ), 'binary exact type groups preserve case/trailing spaces/space-only values' );
	$check( array_keys( $summary['statuses'] ) === array( 'pending', 'Error', 'error', 'error ', ' ', 'completed' ), 'binary exact status groups and order' );
	$groups = 0;
	foreach ( $summary_queries as $sql ) {
		if ( false !== strpos( $sql, $tables[1] ) ) {
			$check( false === strpos( $sql, 'payload' ) && false === strpos( $sql, 'SELECT node_hash' ) && false !== strpos( $sql, 'GROUP BY' ) && false !== strpos( $sql, 'LIMIT 101' ), 'summary record query is a bounded SQL-only projection aggregate' ); $groups++;
		}
	}
	$check( 2 === $groups, 'fixed two aggregate queries independent of root count' );
	$check( $before === $backend->metadata_many( array( 'migrated', 'migrated-revision' ) ) && 0 === $writes && $option_before === serialize( get_option( FOOGALLERY_MIGRATE_OPTION_DATA ) ), 'summary preserves both heads and every option byte' );
	foreach ( array( array( 'object_type' => 'image' ), array( 'object_type' => 'image', 'migration_status' => null ), $record( 'image', '' ) ) as $ambiguous ) {
		$seed( array( 'ambiguous' => $ambiguous ) );
		$refuses( function () use ( $logical ) { $logical->log_summary(); }, 'Ambiguous empty', 'missing/null/empty status refuses rather than invented logical totals' );
		$check( 0 === $writes, 'ambiguous summary refusal never writes options' );
	}
	$seed( array( false, null, array(), $record( '', '' ) ) );
	$check( $logical->log_summary() === $option->log_summary() && 0 === $logical->log_summary()['total'], 'all opaque/empty-type roots yield valid zero summary' );
	$seed( array() ); $check( $logical->log_summary() === $option->log_summary(), 'empty history returns all aliases and zero counters' );
	$many = array(); for ( $i = 0; $i < 1200; $i++ ) { $many[$i] = $record( 'image', $i % 2 ? 'error' : 'completed' ); }
	$seed( $many ); $observed->queries = array();
	$check( $logical->log_summary() === $option->log_summary() && $logical->log_summary()['total'] === 1200, '1200-root SQL-only summary option parity within 100 distinct groups' );
	$heads = $backend->metadata_many( array( 'migrated', 'migrated-revision' ) );
	$refuses( function () use ( $observed, $blog ) { ( new MigratedHistoryRepository( new LegacyTableBackend( $observed, $blog, 4 ), new MigratorSettings() ) )->log_summary(); }, 'budget', 'summary shares row budget across aggregates and both metadata checks' );
	$codec = new \FooPlugins\FooGalleryMigrate\LegacyStateCodec();
	$one_context = $heads[0]['head']['root_payload_bytes'] + $heads[1]['head']['root_payload_bytes'] + strlen( $codec->key_value( 'migrated' ) ) + strlen( $codec->key_value( 'migrated-revision' ) );
	$refuses( function () use ( $observed, $blog, $one_context ) { ( new MigratedHistoryRepository( new LegacyTableBackend( $observed, $blog, 10000, $one_context + 100 ), new MigratorSettings() ) )->log_summary(); }, 'budget', 'summary shares byte budget across aggregates and both metadata checks' );
	$refuses( function () use ( $observed, $blog ) { ( new MigratedHistoryRepository( new LegacyTableBackend( $observed, $blog + 1000 ), new MigratorSettings() ) )->log_summary(); }, 'Missing', 'wrong blog has no history/option fallback' );
	$refuses( function () use ( $backend ) { $backend->history_read( 'log_summary', array( 'extra' => true ) ); }, 'Malformed', 'summary accepts no arbitrary filters or callback' );
	foreach ( array( 'type', 'status' ) as $dimension ) {
		$many = array(); for ( $i = 0; $i < 101; $i++ ) { $many[$i] = $record( 'type' === $dimension ? 'type-' . $i : 'image', 'status' === $dimension ? 'status-' . $i : 'completed' ); }
		$seed( $many ); $refuses( function () use ( $logical ) { $logical->log_summary(); }, 'distinct-group', '101 distinct ' . $dimension . ' groups refuse without payload fallback' );
	}
	$seed( $items );
	$observed->after_group = function () use ( $db, $blog, $reader ) {
		( new LegacyTableBackend( $db, $blog ) )->patch_many( array( array( 'key' => 'migrated-revision', 'expected_revision' => $reader->head( 'migrated-revision' )['revision'], 'value' => 6 ) ) );
	};
	$refuses( function () use ( $logical ) { $logical->log_summary(); }, 'changed', 'concurrent scalar revision change refuses mixed logical summary' );
	$observed->after_group = function () use ( $db, $blog, $reader, $record ) {
		( new LegacyTableBackend( $db, $blog ) )->patch_many( array( array( 'key' => 'migrated', 'expected_revision' => $reader->head( 'migrated' )['revision'], 'patches' => array( array( 'key' => 'lower', 'value' => $record( 'image', 'completed' ) ) ) ) ) );
	};
	$refuses( function () use ( $logical ) { $logical->log_summary(); }, 'changed', 'concurrent history head change refuses mixed type/status counts' );
	$digest_status = $wpdb->get_var( $wpdb->prepare( 'SELECT digest_status FROM `' . $tables[0] . '` WHERE blog_id = %d AND id = %d', array( $blog, $reader->head( 'migrated' )['id'] ) ) );
	$check( empty( $wpdb->last_error ) && 'dirty' === $digest_status && $logical->log_summary()['total'] === 6 && $logical->log_summary()['completed'] === 2 && 0 === $writes, 'verified dirty-digest projections stay readable without whole-history rehash' );
	// Per-type summary includes explicit empty types, unlike log_summary. V1
	// collapses those with absent/null/opaque roots: refuse the whole domain.
	$typed = array( 'unknown' => $record( 'unknown', 'pending' ), 'image' => $record( 'image', 'error' ),
		'case' => $record( 'Image', 'Error' ), 'space' => $record( 'image ', 'error ' ),
		'again' => $record( 'unknown', 'completed' ), 'blank' => $record( ' ', 'error' ),
		'missing-status' => array( 'object_type' => 'image' ), 'null-status' => $record( 'image', null ),
		'empty-status' => $record( 'image', '' ), 'numeric' => $record( '9', 'error' ), 'numeric-string' => $record( '09', 'completed' ) );
	$typed['image']['error'] = false; $typed['again']['error'] = 'not a status error';
	$typed['unknown']['children'] = array( $record( 'child-only', 'error' ) );
	$seed( $typed ); $before = $backend->metadata_many( array( 'migrated', 'migrated-revision' ) );
	$option_before = serialize( get_option( FOOGALLERY_MIGRATE_OPTION_DATA ) );
	$observed->queries = array(); $per_type = $logical->summary(); $type_queries = $observed->queries;
	$check( serialize( $per_type ) === serialize( $option->summary() ), 'exact per-type count/error/order parity including missing/null/empty statuses and nested exclusion' );
	$check( array_keys( $per_type ) === array( 'unknown', 'image', 'Image', 'image ', ' ', 9, '09' ) && $per_type['image'] === array( 'count' => 4, 'errors' => 1 ), 'exact binary type groups and PHP numeric keys; errors are exact logical status' );
	$groups = 0;
	foreach ( $type_queries as $sql ) {
		if ( false !== strpos( $sql, $tables[1] ) ) {
			$check( false === strpos( $sql, 'payload' ) && false !== strpos( $sql, 'GROUP BY' ) && false !== strpos( $sql, 'LIMIT 101' ), 'per-type record query is one bounded projection aggregate' ); $groups++;
		}
	}
	$check( 1 === $groups, 'per-type summary uses one aggregate independent of root count' );
	$check( $before === $backend->metadata_many( array( 'migrated', 'migrated-revision' ) ) && 0 === $writes && $option_before === serialize( get_option( FOOGALLERY_MIGRATE_OPTION_DATA ) ), 'per-type summary never changes heads or options' );
	foreach ( array( false, null, array(), array( 'object_type' => null ), $record( '', 'error' ) ) as $ambiguous ) {
		$seed( array( 'valid' => $record( 'image', 'error' ), 'ambiguous' => $ambiguous ) );
		$refuses( function () use ( $logical ) { $logical->summary(); }, 'Ambiguous empty type', 'per-type missing/null/opaque/explicit-empty type refuses without fallback' );
		$check( 0 === $writes, 'per-type admission refusal never writes options' );
	}
	$seed( array() ); $check( $logical->summary() === array() && $logical->summary() === $option->summary(), 'empty per-type summary parity' );
	$many = array(); for ( $i = 0; $i < 1200; $i++ ) { $many[$i] = $record( 'image', $i % 2 ? 'error' : 'completed' ); }
	$seed( $many ); $check( $logical->summary() === $option->summary() && $logical->summary()['image'] === array( 'count' => 1200, 'errors' => 600 ), '1200-root per-type summary parity without payload reads' );
	$refuses( function () use ( $observed, $blog ) { ( new MigratedHistoryRepository( new LegacyTableBackend( $observed, $blog, 4 ), new MigratorSettings() ) )->summary(); }, 'budget', 'per-type aggregate and metadata share row budget' );
	$refuses( function () use ( $observed, $blog, $one_context ) { ( new MigratedHistoryRepository( new LegacyTableBackend( $observed, $blog, 10000, $one_context + 100 ), new MigratorSettings() ) )->summary(); }, 'budget', 'per-type aggregate and metadata share byte budget' );
	$refuses( function () use ( $observed, $blog ) { ( new MigratedHistoryRepository( new LegacyTableBackend( $observed, $blog + 1000 ), new MigratorSettings() ) )->summary(); }, 'Missing', 'per-type wrong-blog refusal' );
	$refuses( function () use ( $backend ) { $backend->history_read( 'summary', array( 'extra' => true ) ); }, 'Malformed', 'per-type summary admits no filters/callback' );
	$many = array(); for ( $i = 0; $i < 100; $i++ ) { $many[$i] = $record( 'type-' . $i, 'completed' ); }
	$seed( $many ); $check( $logical->summary() === $option->summary() && count( $logical->summary() ) === 100, '100 distinct types are admitted' );
	$many[100] = $record( 'type-100', 'error' ); $seed( $many );
	$refuses( function () use ( $logical ) { $logical->summary(); }, 'distinct-group', '101 per-type groups refuse' );
	$seed( $typed );
	$observed->after_group = function () use ( $db, $blog, $reader ) {
		( new LegacyTableBackend( $db, $blog ) )->patch_many( array( array( 'key' => 'migrated-revision', 'expected_revision' => $reader->head( 'migrated-revision' )['revision'], 'value' => 7 ) ) );
	};
	$refuses( function () use ( $logical ) { $logical->summary(); }, 'changed', 'per-type concurrent logical revision refuses' );
	$observed->after_group = function () use ( $db, $blog, $reader, $record ) {
		( new LegacyTableBackend( $db, $blog ) )->patch_many( array( array( 'key' => 'migrated', 'expected_revision' => $reader->head( 'migrated' )['revision'], 'patches' => array( array( 'key' => 'image', 'value' => $record( 'image', 'completed' ) ) ) ) ) );
	};
	$refuses( function () use ( $logical ) { $logical->summary(); }, 'changed', 'per-type concurrent same-total status update refuses' );
	$check( $logical->summary()['image'] === array( 'count' => 4, 'errors' => 0 ) && 0 === $writes, 'per-type verified dirty projections remain readable without whole-history rehash' );
	$heads = $backend->metadata_many( array( 'migrated', 'migrated-revision' ) );
	$snapshot->replace_many( array( array( 'key' => 'migrated', 'order' => 0, 'kind' => 'map', 'value' => array( 'header-root' => false ), 'expected_revision' => $heads[0]['head']['revision'] ) ) );
	$refuses( function () use ( $logical ) { $logical->summary(); }, 'wrapper', 'per-type old header-root refusal' );
	$refuses( function () use ( $logical ) { $logical->log_summary(); }, 'wrapper', 'old header-root layout is not silently translated' );
} finally {
	remove_action( 'updated_option', $observe ); remove_action( 'added_option', $observe );
	if ( $options[FOOGALLERY_MIGRATE_OPTION_DATA] === $missing ) { delete_option( FOOGALLERY_MIGRATE_OPTION_DATA ); }
	else { update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $options[FOOGALLERY_MIGRATE_OPTION_DATA], false ); }
	foreach ( array_reverse( $created ) as $table ) { if ( false === $wpdb->query( 'DROP TABLE `' . $table . '`' ) ) { throw new RuntimeException( 'Fixture cleanup failed.' ); } }
	foreach ( $options as $key => $before ) { $check( get_option( $key, $missing ) === $before, 'option cleanup read back: ' . $key ); }
	foreach ( $created as $table ) { $check( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) && empty( $wpdb->last_error ), 'table cleanup read back: ' . $table ); }
}
fwrite( STDOUT, wp_json_encode( array( 'ok' => true, 'blog_id' => $blog, 'actual_multisite' => is_multisite(), 'check_count' => count( $checks ), 'checks' => $checks ) ) . "\n" );
