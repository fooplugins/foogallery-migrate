<?php
/** Existing missing/reorder/list-removal callers against the approved schema. */
if ( ! defined( 'ABSPATH' ) || ! defined( 'FOOGM_STORAGE_TEST_DISPOSABLE' ) || true !== FOOGM_STORAGE_TEST_DISPOSABLE ) { throw new RuntimeException( 'Explicit disposable WordPress admission required.' ); }
$root = defined( 'FOOGM_STORAGE_TEST_ROOT' ) ? FOOGM_STORAGE_TEST_ROOT : dirname( dirname( __DIR__ ) );
require_once $root . '/migrate.php'; require_once ABSPATH . 'wp-admin/includes/upgrade.php';
use FooPlugins\FooGalleryMigrate\LegacyOptionBackend;
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use FooPlugins\FooGalleryMigrate\LegacyTableBackend;
use FooPlugins\FooGalleryMigrate\TableSnapshot;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;
use FooPlugins\FooGalleryMigrate\MigratableCollectionRepository;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use FooPlugins\FooGalleryMigrate\Objects\Gallery;
global $wpdb;
$schema = new LegacyStorageSchema( $wpdb ); $tables = array_keys( $schema->definitions() );
foreach ( $tables as $table ) { if ( null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) || $wpdb->last_error ) { throw new RuntimeException( 'Refusing existing tables.' ); } }
$sentinel = new stdClass(); $saved = get_option( FOOGALLERY_MIGRATE_OPTION_DATA, $sentinel ); $created = array(); $checks = array();
$physical = function () use ( $wpdb, $tables ) { $all = array(); foreach ( $tables as $table ) { $rows = $wpdb->get_results( 'SELECT * FROM `' . $table . '` ORDER BY id', ARRAY_A ); if ( ! is_array( $rows ) || $wpdb->last_error ) { throw new RuntimeException( 'Physical readback failed.' ); } $all[$table] = $rows; } return serialize( $all ); };
try {
	foreach ( $schema->statements() as $i => $ddl ) { $created[] = $tables[$i]; dbDelta( $ddl ); }
	$settings = new MigratorSettings(); $ob = new LegacyOptionBackend(); $ob->replace_all( array( 'queue' => false ) ); $db = new LegacyTransactionConnection( $wpdb ); $blog = (int) get_current_blog_id(); $tb = new LegacyTableBackend( $db, $blog );
	( new TableSnapshot( $db, $blog ) )->replace_many( array( array( 'key' => 'queue', 'kind' => 'scalar', 'order' => 0, 'value' => false, 'expected_revision' => null ) ) );
	$o = new MigratableCollectionRepository( $ob, $settings ); $t = new MigratableCollectionRepository( $tb, $settings );
	$call = function ( $method, $args ) use ( $o, $t, &$checks ) {
		$a = call_user_func_array( array( $o, $method ), $args ); $b = call_user_func_array( array( $t, $method ), $args );
		if ( serialize( $a ) !== serialize( $b ) || serialize( $o->read_snapshot( $args[0] ) ) !== serialize( $t->read_snapshot( $args[0] ) ) ) { throw new RuntimeException( 'Collection parity failed: ' . $method ); } $checks[] = $method;
	};
	$call( 'read_snapshot', array( 'galleries' ) );
	$gallery = new Gallery( new \FooPlugins\FooGalleryMigrate\Plugins\Aigpl() ); $gallery->ID = 11; $gallery->unknown = "\xff\x00";
	$call( 'update_record', array( 'galleries', array( 'root' => 'absent', 'path' => array() ), $gallery ) );
	$call( 'update_with_state', array( 'galleries', array( '09' => $gallery, 9 => clone $gallery ), 'queued' ) );
	$call( 'update_with_state', array( 'galleries', array( 9 => clone $gallery, '09' => $gallery ), 'reordered' ) );
	if ( $tb->read( 'galleries-state' )['value'] !== 'reordered' ) { throw new RuntimeException( 'Missing companion/reorder checkpoint failed.' ); }
	$before = $physical(); $call( 'update_with_state', array( 'galleries', array( 9 => clone $gallery, '09' => $gallery ), 'reordered' ) );
	if ( $before !== $physical() ) { throw new RuntimeException( 'Collection no-op changed physical bytes.' ); }
	$values = array( clone $gallery, clone $gallery, clone $gallery ); $raw = $settings->compact_state_value( 'albums', $values );
	( new TableSnapshot( $db, $blog ) )->replace_many( array( array( 'key' => 'albums', 'kind' => 'list', 'order' => 3, 'value' => $raw, 'expected_revision' => null ) ) );
	$settings->set_migrator_setting( 'albums', $values );
	$call( 'update_with_state', array( 'albums', array( $values[2], $values[0] ), 'removed' ) );
	if ( $tb->read( 'albums-state' )['value'] !== 'removed' ) { throw new RuntimeException( 'List removal checkpoint failed.' ); }
	$call( 'replace_snapshot', array( 'albums', array() ) );
	echo wp_json_encode( array( 'ok' => true, 'blog_id' => $blog, 'check_count' => count( $checks ), 'checks' => $checks ) );
} finally {
	foreach ( array_reverse( $created ) as $table ) { $wpdb->query( 'DROP TABLE IF EXISTS `' . $table . '`' ); if ( null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) || $wpdb->last_error ) { throw new RuntimeException( 'Owned table cleanup failed.' ); } }
	if ( $saved === $sentinel ) { delete_option( FOOGALLERY_MIGRATE_OPTION_DATA ); } else { update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $saved ); }
	if ( get_option( FOOGALLERY_MIGRATE_OPTION_DATA, $sentinel ) !== $saved ) { throw new RuntimeException( 'Option restoration failed.' ); }
}
