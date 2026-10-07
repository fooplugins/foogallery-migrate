<?php
/** Guarded, real-SQL collection dispatch and shared clear-deadline checkpoint. */
if ( ! defined( 'ABSPATH' ) || ! defined( 'FOOGM_STORAGE_TEST_DISPOSABLE' ) || true !== FOOGM_STORAGE_TEST_DISPOSABLE ) {
	throw new RuntimeException( 'Explicit disposable WordPress admission required.' );
}
$root = defined( 'FOOGM_STORAGE_TEST_ROOT' ) ? FOOGM_STORAGE_TEST_ROOT : dirname( dirname( __DIR__ ) );
require_once $root . '/migrate.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

use FooPlugins\FooGalleryMigrate\LegacyOptionBackend;
use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use FooPlugins\FooGalleryMigrate\LegacyTableBackend;
use FooPlugins\FooGalleryMigrate\LegacyTableRepository;
use FooPlugins\FooGalleryMigrate\LegacyTableSnapshot;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;
use FooPlugins\FooGalleryMigrate\MigratableCollectionRepository;
use FooPlugins\FooGalleryMigrate\MigratedHistoryRepository;
use FooPlugins\FooGalleryMigrate\MigratorEngine;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use FooPlugins\FooGalleryMigrate\ScalarStateRepository;
use FooPlugins\FooGalleryMigrate\Objects\Gallery;
use FooPlugins\FooGalleryMigrate\Objects\Album;
use FooPlugins\FooGalleryMigrate\Objects\Image;

/** Delay a real database operation, without synthesizing its result. */
class CollectionDeadlineConnection extends LegacyTransactionConnection {
	public $delay_match;
	public $delay_reads = false;
	private function delay( $sql ) {
		if ( $this->delay_match && false !== strpos( $sql, $this->delay_match ) ) {
			$this->delay_match = null; usleep( 1100000 );
		}
	}
	public function query( $sql ) { if ( ! $this->delay_reads ) { $this->delay( $sql ); } return parent::query( $sql ); }
	public function get_results( $sql, $format ) { if ( $this->delay_reads ) { $this->delay( $sql ); } return parent::get_results( $sql, $format ); }
}

global $wpdb;
$schema = new LegacyStorageSchema( $wpdb ); $tables = array_keys( $schema->definitions() );
foreach ( $tables as $table ) {
	if ( null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) || ! empty( $wpdb->last_error ) ) { throw new RuntimeException( 'Refusing existing tables/unhealthy fixture DB.' ); }
}
$missing = new stdClass(); $saved = get_option( FOOGALLERY_MIGRATE_OPTION_DATA, $missing );
$checks = array(); $created = array(); $blog = (int) get_current_blog_id();
$check = function ( $ok, $label ) use ( &$checks ) { if ( ! $ok ) { throw new RuntimeException( 'FAIL: ' . $label ); } $checks[] = $label; };
$refuses = function ( $call, $label ) use ( $check ) { try { $call(); } catch ( RuntimeException $error ) { $check( true, $label . ': ' . $error->getMessage() ); return $error; } $check( false, $label ); };
$physical = function () use ( $wpdb, $tables ) {
	$result = array(); foreach ( $tables as $table ) { $rows = $wpdb->get_results( 'SELECT * FROM `' . $table . '` ORDER BY id', ARRAY_A ); if ( ! is_array( $rows ) || $wpdb->last_error ) { throw new RuntimeException( 'Physical readback failed.' ); } $result[$table] = $rows; } return serialize( $result );
};
try {
	foreach ( $schema->statements() as $i => $ddl ) { $created[] = $tables[$i]; dbDelta( $ddl ); }
	$check( $schema->verify(), 'schema v1 unchanged' );
	$db = new LegacyTransactionConnection( $wpdb ); $backend = new LegacyTableBackend( $db, $blog ); $snapshot = new LegacyTableSnapshot( $db, $blog );
	$settings = new MigratorSettings(); $plugin = new \FooPlugins\FooGalleryMigrate\Plugins\Aigpl();
	$image = new Image( $plugin ); $image->source_url = 'typed-child'; $image->data = array( 'opaque' => "\xff\x00" );
	$gallery = new Gallery( $plugin ); $gallery->ID = 101; $gallery->children = array( '09' => $image, 9 => clone $image );
	$other = new Gallery( $plugin ); $other->ID = 102;
	$objects = array( '09' => $gallery, 9 => $other );
	$album = new Album( $plugin ); $album->children = array( '09' => clone $gallery, 9 => clone $gallery );
	( new LegacyOptionBackend() )->replace_all( array() );
	$settings->set_migrator_settings( array( 'galleries' => $objects, 'galleries-state' => null, 'albums' => array( '09' => $album, 9 => new Album( $plugin ) ), 'albums-state' => null,
		'queue' => false, 'image_tag_sync_queue' => array(), 'migrated' => array( '09' => $image ) ) );
	$data = get_option( FOOGALLERY_MIGRATE_OPTION_DATA );
	$changes = array(); $order = 0; foreach ( $data as $key => $value ) { $changes[] = array( 'key' => $key, 'order' => $order++, 'kind' => in_array( $key, array( 'galleries', 'albums', 'migrated' ), true ) ? 'map' : 'scalar', 'value' => $value, 'expected_revision' => null ); }
	$snapshot->replace_many( $changes ); ( new LegacyOptionBackend() )->replace_all( $data );
	$option = new MigratableCollectionRepository( new LegacyOptionBackend(), $settings ); $table = new MigratableCollectionRepository( $backend, $settings );
	$check( serialize( $option->read_snapshot( 'galleries' ) ) === serialize( $table->read_snapshot( 'galleries' ) ), 'typed gallery/child snapshot parity' );
	$compare = function ( $label, $bucket = 'galleries' ) use ( $backend, $check ) { $raw = get_option( FOOGALLERY_MIGRATE_OPTION_DATA ); $check( serialize( $backend->collection_snapshot( $bucket ) ) === serialize( $raw[$bucket] ), $label . ': exact compact collection parity' ); $check( $backend->read( $bucket . '-state' )['value'] === $raw[$bucket . '-state'], $label . ': companion parity' ); };
	$gallery->migrated_id = 91; $locator = array( 'root' => '09', 'path' => array( 9 ) );
	$codec = new LegacyStateCodec(); $node = $codec->node_hash( $codec->key_hash( 'albums' ), null, 9 );
	$untouched = function () use ( $wpdb, $tables, $node ) { return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM `' . $tables[1] . '` WHERE node_hash = UNHEX(%s) ORDER BY id', $node ), ARRAY_A ); };
	$before_other = $untouched(); $check( $option->update_record( 'albums', $locator, $gallery ) && $table->update_record( 'albums', $locator, $gallery ), 'nested exact integer child update parity' ); $compare( 'nested update', 'albums' );
	$check( $before_other === $untouched(), 'unrelated typed root physical bytes untouched' );
	$before = $physical(); $noop_result = $table->update_record( 'albums', $locator, $gallery );
	$check( $noop_result && $before === $physical(), 'nested no-op preserves every physical row; changed=' . ( $before === $physical() ? 'no' : 'yes' ) );
	$check( false === $table->update_record( 'albums', array( 'root' => '09', 'path' => array( 'absent' ) ), $gallery ) && $before === $physical(), 'missing nested locator preserves physical bytes' );
	$objects = $option->read_snapshot( 'galleries' ); $objects[9]->title = 'changed second root';
	$check( $option->update_with_state( 'galleries', $objects, array( 'cursor' => 9 ) ) === $table->update_with_state( 'galleries', $objects, array( 'cursor' => 9 ) ), 'collection and checkpoint atomic update parity' ); $compare( 'checkpoint update' );
	$before = $physical(); $check( $table->update_with_state( 'galleries', $objects, array( 'cursor' => 9 ) ) && $before === $physical(), 'snapshot/checkpoint no-op preserves all physical bytes' );
	$refuses( function () use ( $db, $blog ) { ( new MigratableCollectionRepository( new LegacyTableBackend( $db, $blog, 2 ), new MigratorSettings() ) )->read_snapshot( 'galleries' ); }, 'snapshot aggregate row bound refuses' );
	$check( $before === $physical(), 'snapshot budget refusal leaves physical bytes unchanged' );
	$reordered = array( 9 => $objects[9], '09' => $objects['09'] );
	$check( $table->replace_snapshot( 'galleries', $reordered ), 'bounded reorder succeeds through staged replacement' );
	$check( array( 9, '09' ) === array_keys( $table->read_snapshot( 'galleries' ) ), 'staged reorder readback preserves typed identity and exact order' );
	$scalars = new ScalarStateRepository( $backend, $settings );
	$check( $scalars->read_many( array( 'queue', 'galleries-state', 'missing' ) ) === array( array( 'found' => true, 'key' => 'queue', 'value' => false ), array( 'found' => true, 'key' => 'galleries-state', 'value' => array( 'cursor' => 9 ) ), array( 'found' => false, 'key' => 'missing', 'value' => null ) ), 'bounded scalar read distinguishes stored false and missing' );
	$option_before = get_option( FOOGALLERY_MIGRATE_OPTION_DATA );
	$engine = new MigratorEngine( new MigratedHistoryRepository( $backend, $settings ) ); $objects[9]->title = 'engine checkpoint';
	$check( $engine->set_migrator_setting( 'galleries', $objects ), 'injected engine existing gallery setter writes tables' );
	$check( 'engine checkpoint' === $engine->get_migrator_setting( 'galleries' )[9]->title, 'injected engine reads its own table collections' );
	$check( $option_before === get_option( FOOGALLERY_MIGRATE_OPTION_DATA ), 'engine never writes option authority' );
	$before = $physical();
	$refuses( function () use ( $db, $blog ) { ( new MigratableCollectionRepository( new LegacyTableBackend( $db, $blog + 1000 ), new MigratorSettings() ) )->read_snapshot( 'galleries' ); }, 'wrong-blog collection refuses' ); $check( $before === $physical(), 'wrong-blog refusal leaves bytes unchanged' );
	// The same one-second caller deadline spans metadata and real staging/publication SQL.
	foreach ( array( array( 'root_payload_bytes', true ), array( 'SET root_payload', false ), array( 'SET active_generation', false ) ) as $fault ) {
		$delayed = new CollectionDeadlineConnection( $wpdb ); $delayed->delay_match = $fault[0]; $delayed->delay_reads = $fault[1];
		$bounded = new LegacyTableRepository( $delayed, $blog, 10000, 16777216, 1 ); $before = $physical();
		$error = $refuses( function () use ( $bounded ) { $bounded->history_mutate( 'clear', array() ); }, 'shared clear deadline: ' . $fault[0] );
		$check( false !== strpos( $error->getMessage(), 'time budget' ) && null === $delayed->delay_match, 'actual delayed operation exhausts caller time budget (' . $fault[0] . '): ' . $error->getMessage() );
		$check( $before === $physical(), 'timeout rollback preserves all physical heads/records/companions' );
	}
	$logical_revision = $backend->read( 'migrated-revision' )['value'];
	$check( $backend->history_mutate( 'clear', array() ), 'normal shared-deadline clear succeeds' );
	$check( array() === $snapshot->read_bounded( 'migrated' )['items'] && $logical_revision + 1 === $backend->read( 'migrated-revision' )['value'], 'clear and logical revision companion publication explicitly read back' );
	$check( $option_before === get_option( FOOGALLERY_MIGRATE_OPTION_DATA ), 'table clear/timeout paths retain option authority bytes' );
	echo wp_json_encode( array( 'ok' => true, 'blog_id' => $blog, 'check_count' => count( $checks ), 'checks' => $checks ) );
} finally {
	foreach ( array_reverse( $created ) as $table_name ) { $wpdb->query( 'DROP TABLE IF EXISTS `' . $table_name . '`' ); }
	if ( $saved === $missing ) { delete_option( FOOGALLERY_MIGRATE_OPTION_DATA ); } else { update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $saved ); }
}
