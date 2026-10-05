<?php
/** Closed logical history mutations; requires an explicitly guarded disposable WP target. */
if ( ! defined( 'ABSPATH' ) || ! defined( 'FOOGM_STORAGE_TEST_DISPOSABLE' ) || true !== FOOGM_STORAGE_TEST_DISPOSABLE ) {
	throw new RuntimeException( 'Explicit disposable WordPress admission required.' );
}
$root = defined( 'FOOGM_STORAGE_TEST_ROOT' ) ? FOOGM_STORAGE_TEST_ROOT : dirname( dirname( __DIR__ ) );
require_once $root . '/migrate.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

use FooPlugins\FooGalleryMigrate\LegacyOptionBackend;
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use FooPlugins\FooGalleryMigrate\LegacyTableBackend;
use FooPlugins\FooGalleryMigrate\LegacyTableReader;
use FooPlugins\FooGalleryMigrate\LegacyTableSnapshot;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;
use FooPlugins\FooGalleryMigrate\MigratedHistoryRepository;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use FooPlugins\FooGalleryMigrate\Objects\Image;
use FooPlugins\FooGalleryMigrate\StorageAuthority;

/** Pinned DB fault injection, not a fake database or synthesized response. */
class HistoryMutationFaultConnection extends LegacyTransactionConnection {
	public $after_record_read; public $before_start; public $before_queue_lock; public $fail_revision = false;
	public function get_results( $sql, $format ) {
		$queue = ( new \FooPlugins\FooGalleryMigrate\LegacyStateCodec() )->key_hash( 'queue' );
		if ( $this->before_queue_lock && false !== strpos( $sql, 'FOR UPDATE' ) && false !== strpos( $sql, $queue ) ) {
			$action = $this->before_queue_lock; $this->before_queue_lock = null; $action();
		}
		$result = parent::get_results( $sql, $format );
		if ( $this->after_record_read && false !== strpos( $sql, ' AS encoded_bytes FROM ' ) && false !== strpos( $sql, ' AND id = ' ) ) {
			$action = $this->after_record_read; $this->after_record_read = null; $action();
		}
		return $result;
	}
	public function query( $sql ) {
		if ( $this->before_start && 'START TRANSACTION' === $sql ) { $action = $this->before_start; $this->before_start = null; $action(); }
		if ( $this->fail_revision && 0 === strpos( $sql, 'UPDATE `' ) && false !== strpos( $sql, "digest_status = 'current'" ) ) {
			$this->fail_revision = false; throw new RuntimeException( 'Injected scalar CAS failure.' );
		}
		return parent::query( $sql );
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
$refuses = function ( $call, $label ) use ( $check ) { try { $call(); } catch ( RuntimeException $error ) { $check( true, $label . ': ' . $error->getMessage() ); return; } $check( false, $label ); };
$observe = function ( $name ) use ( &$writes ) { if ( FOOGALLERY_MIGRATE_OPTION_DATA === $name ) { $writes++; } };
$blog = (int) get_current_blog_id();
try {
	foreach ( $schema->statements() as $i => $ddl ) { $created[] = $tables[$i]; dbDelta( $ddl ); }
	$check( $schema->verify(), 'unchanged schema v1' );
	$db = new LegacyTransactionConnection( $wpdb ); $backend = new LegacyTableBackend( $db, $blog );
	$snapshot = new LegacyTableSnapshot( $db, $blog ); $reader = new LegacyTableReader( $db, $blog );
	$record = array( 'object_type' => 'future', 'migration_status' => 'custom', 'migration_parent_key' => 'Parent', 'opaque' => array( '09' => false, 9 => null, 'bytes' => "雪\x00" ) );
	$history = array( 'before' => -0.0, 'items' => array( '09' => $record, 9 => null, 'false' => false, 'empty' => array() ), MigratorSettings::COMPACT_MARKER => 1, 'after' => '雪', 'type' => 'migratable' );
	$data = array( 'migrated' => $history, 'migrated-revision' => 5, 'queue' => null );
	$initial = array(); $order = 0;
	foreach ( $data as $key => $value ) { $initial[] = array( 'key' => $key, 'order' => $order++, 'kind' => 'migrated' === $key ? 'map' : 'scalar', 'value' => $value, 'expected_revision' => null ); }
	$snapshot->replace_many( $initial ); $check( ( new LegacyOptionBackend() )->replace_all( $data ), 'seed real option fixture succeeds' );
	$settings = new MigratorSettings(); $option = new MigratedHistoryRepository( new LegacyOptionBackend(), $settings ); $logical = new MigratedHistoryRepository( $backend, $settings );
	$heads = function () use ( $backend ) { return $backend->metadata_many( array( 'migrated', 'migrated-revision', 'queue' ) ); };
	$verify = function ( $label ) use ( $backend, $snapshot, $reader, $check ) {
		$snapshot->verify_digest( 'migrated', $reader->head( 'migrated' )['revision'] );
		$check( serialize( $snapshot->read_bounded( 'migrated' ) ) === serialize( get_option( FOOGALLERY_MIGRATE_OPTION_DATA )['migrated'] ), $label . ': exact raw wrapper/items/order parity' );
		$check( $backend->read( 'migrated-revision' )['value'] === get_option( FOOGALLERY_MIGRATE_OPTION_DATA )['migrated-revision'], $label . ': logical revision parity' );
		$check( $backend->read( 'queue' )['value'] === get_option( FOOGALLERY_MIGRATE_OPTION_DATA )['queue'], $label . ': atomic companion parity' );
	};
	$check( $option->set_status( '09', '' ) === $logical->set_status( '09', '' ), 'raw status mutation admitted' ); $verify( 'status preserves opaque fields' );
	$before = $heads(); $check( $logical->set_status( '09', '' ), 'status no-op returns true' ); $check( $heads() === $before, 'status no-op does not advance any head' );
	foreach ( array( 9, 'false', 'missing' ) as $key ) { $before = $heads(); $check( false === $logical->set_status( $key, 'error' ), 'invalid scalar/missing status returns false: ' . $key ); $check( $heads() === $before, 'invalid status preserves heads: ' . $key ); }
	$check( $option->set_status( 'empty', 'error' ) === $logical->set_status( 'empty', 'error' ), 'status on raw empty array admitted' ); $verify( 'raw empty status' );
	$image = new Image(); $image->source_url = 'https://example.test/New.jpg'; $image->migrated_id = 91;
	$records = array( array( 'object' => $image, 'parent_key' => 'Parent' ), $image );
	$check( 2 === $option->upsert_batch( $records, array( 'queue' => false ) ) && 2 === $logical->upsert_batch( $records, array( 'queue' => false ) ), 'duplicate sequential upserts counted and coalesced atomically' ); $verify( 'upsert' );
	$before = $heads(); $check( 1 === $logical->upsert( $image ), 'upsert exact no-op returns requested count' ); $check( $heads() === $before, 'upsert no-op preserves physical/logical revisions' );
	$check( 1 === $option->upsert( $image, array( 'queue' => 'changed' ) ) && 1 === $logical->upsert( $image, array( 'queue' => 'changed' ) ), 'no-op history plus changed companion succeeds' );
	$after = $heads(); $check( $after[0] === $before[0] && $after[1] === $before[1] && $after[2]['head']['revision'] === $before[2]['head']['revision'] + 1, 'companion-only write leaves both history heads unchanged' ); $verify( 'companion-only' );
	$check( $option->delete( 9 ) === $logical->delete( 9 ), 'delete stored null by exact integer identity' ); $verify( 'delete null' );
	$before = $heads(); $check( false === $logical->delete( 9 ) && $heads() === $before, 'missing delete no-op returns false' );
	$check( $option->delete( 'false' ) === $logical->delete( 'false' ), 'delete stored false' ); $verify( 'delete false' );
	$before = $heads();
	$refuses( function () use ( $logical ) { $logical->upsert_batch( array(), array( 'missing-companion' => 1 ) ); }, 'missing companion refuses without head creation' );
	$refuses( function () use ( $logical, $image ) { $logical->upsert( $image, array( 'queue' => new stdClass() ) ); }, 'object companion refuses losslessly' );
	$refuses( function () use ( $logical ) { $logical->set_status( '09', str_repeat( 'x', 33 ) ); }, 'unindexed oversized status refuses' );
	$refuses( function () use ( $db, $blog ) { ( new MigratedHistoryRepository( new LegacyTableBackend( $db, $blog, 3 ), new MigratorSettings() ) )->delete( '09' ); }, 'complete logical mutation shares metadata/old/new row budget' );
	$check( $before === $heads(), 'all preflight/budget refusals preserve every head' );
	$fault = new HistoryMutationFaultConnection( $wpdb ); $fault_backend = new LegacyTableBackend( $fault, $blog ); $fault_logical = new MigratedHistoryRepository( $fault_backend, $settings );
	$fault->fail_revision = true;
	$refuses( function () use ( $fault_logical, $image ) { $copy = clone $image; $copy->migrated_id = 92; $fault_logical->upsert( $copy, array( 'queue' => 'must-rollback' ) ); }, 'real transaction injected scalar CAS failure rolls back' );
	$check( $before === $heads() && $logical->get( $image->unique_identifier() )->migrated_id === 91, 'history/revision/companion rollback read back' );
	$fault->after_record_read = function () use ( $backend, $reader, $image ) { $old = $backend->roots( 'migrated', array( $image->unique_identifier() ) )[0]['value']; $old['migrated_id'] = 93;
		$backend->patch_many( array( array( 'key' => 'migrated', 'expected_revision' => $reader->head( 'migrated' )['revision'], 'patches' => array( array( 'key' => $image->unique_identifier(), 'value' => $old ) ) ) ) ); };
	$refuses( function () use ( $fault_logical, $image ) { $fault_logical->upsert( $image, array( 'queue' => 'stale' ) ); }, 'non-replaying stale read refuses before commit' );
	$check( $logical->get( $image->unique_identifier() )->migrated_id === 93 && 'changed' === $backend->read( 'queue' )['value'], 'interleaving writer retained and companion not committed' );
	// A second actual mysqli connection commits while the first owns some, but not all, dependency locks.
	$other_wpdb = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ); $other_wpdb->base_prefix = $wpdb->base_prefix;
	$other_backend = new LegacyTableBackend( new LegacyTransactionConnection( $other_wpdb ), $blog );
	$queue_revision = $reader->head( 'queue' )['revision'];
	$check( 'REPEATABLE-READ' === $wpdb->get_var( 'SELECT @@transaction_isolation' ), 'real repeatable-read isolation admitted' );
	// MariaDB 13 defaults to stricter snapshot-conflict checks than MySQL's REPEATABLE READ.
	if ( false !== strpos( $wpdb->get_var( 'SELECT VERSION()' ), 'MariaDB' ) ) {
		$check( false !== $db->query( 'SET SESSION innodb_snapshot_isolation = OFF' ), 'disposable MariaDB MySQL-style locking semantics admitted' );
	}
	$fault->before_queue_lock = function () use ( $other_backend, $queue_revision ) { $other_backend->patch_many( array( array( 'key' => 'queue', 'expected_revision' => $queue_revision, 'value' => 'interleaved-queue' ) ) ); };
	$refuses( function () use ( $fault_logical, $image ) { $copy = clone $image; $copy->migrated_id = 94; $fault_logical->upsert( $copy, array( 'queue' => 'changed' ) ); }, 'unchanged companion guard sees commit after earlier head lock' );
	$check( $logical->get( $image->unique_identifier() )->migrated_id === 93 && 'interleaved-queue' === $backend->read( 'queue' )['value'], 'repeatable-read stale dependency leaves only concurrent write' );
	$fault->before_start = function () use ( $other_backend, $reader ) { $other_backend->patch_many( array( array( 'key' => 'migrated-revision', 'expected_revision' => $reader->head( 'migrated-revision' )['revision'], 'value' => 50 ) ) ); };
	$refuses( function () use ( $fault_logical ) { $fault_logical->upsert_batch( array(), array( 'queue' => 'stale-before-start' ) ); }, 'companion-only publication CAS guards both unchanged history heads' );
	$check( 50 === $backend->read( 'migrated-revision' )['value'] && 'interleaved-queue' === $backend->read( 'queue' )['value'], 'pretransaction concurrent logical revision retained without companion write' );
	$other_wpdb->close();
	add_action( 'updated_option', $observe ); add_action( 'added_option', $observe ); $option_before = get_option( FOOGALLERY_MIGRATE_OPTION_DATA );
	$check( $logical->set_status( '09', 'error' ), 'explicit table status mutation without options' );
	$check( 0 === $writes && $option_before === get_option( FOOGALLERY_MIGRATE_OPTION_DATA ), 'table operations never read/write fallback options' );
	$refuses( function () use ( $db, $blog ) { ( new MigratedHistoryRepository( new LegacyTableBackend( $db, $blog + 1000 ), new MigratorSettings() ) )->delete( '09' ); }, 'wrong-blog mutation refuses' );
	$refuses( function () use ( $logical ) { $logical->clear(); }, 'whole-history clear not silently scanned' );
	$check( $logical->manages_revision(), 'shared repository owns logical revision' );
} finally {
	remove_action( 'updated_option', $observe ); remove_action( 'added_option', $observe );
	if ( $options[FOOGALLERY_MIGRATE_OPTION_DATA] === $missing ) { delete_option( FOOGALLERY_MIGRATE_OPTION_DATA ); } else { update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $options[FOOGALLERY_MIGRATE_OPTION_DATA], false ); }
	foreach ( array_reverse( $created ) as $table ) { if ( false === $wpdb->query( 'DROP TABLE `' . $table . '`' ) ) { throw new RuntimeException( 'Fixture cleanup failed.' ); } }
	foreach ( $options as $key => $before ) { $check( get_option( $key, $missing ) === $before, 'option cleanup read back: ' . $key ); }
	foreach ( $created as $table ) { $check( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) && empty( $wpdb->last_error ), 'table cleanup read back: ' . $table ); }
}
fwrite( STDOUT, wp_json_encode( array( 'ok' => true, 'blog_id' => $blog, 'actual_multisite' => is_multisite(), 'check_count' => count( $checks ), 'checks' => $checks ) ) . "\n" );
