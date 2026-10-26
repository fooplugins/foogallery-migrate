<?php
/** Real SQL scalar missing/set/delete parity, with exact fixture restoration. */
if ( ! defined( 'ABSPATH' ) || ! defined( 'FOOGM_STORAGE_TEST_DISPOSABLE' ) || true !== FOOGM_STORAGE_TEST_DISPOSABLE ) { throw new RuntimeException( 'Explicit disposable WordPress admission required.' ); }
$root = defined( 'FOOGM_STORAGE_TEST_ROOT' ) ? FOOGM_STORAGE_TEST_ROOT : dirname( dirname( __DIR__ ) );
require_once $root . '/migrate.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
use FooPlugins\FooGalleryMigrate\LegacyOptionBackend;
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use FooPlugins\FooGalleryMigrate\LegacyTableBackend;
use FooPlugins\FooGalleryMigrate\TableSnapshot;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use FooPlugins\FooGalleryMigrate\ScalarStateRepository;
global $wpdb;
$schema = new LegacyStorageSchema( $wpdb ); $tables = array_keys( $schema->definitions() );
foreach ( $tables as $table ) { if ( null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) || $wpdb->last_error ) { throw new RuntimeException( 'Refusing existing tables.' ); } }
$sentinel = new stdClass(); $saved = get_option( FOOGALLERY_MIGRATE_OPTION_DATA, $sentinel ); $created = array(); $checks = array();
$physical = function () use ( $wpdb, $tables ) { $rows = array(); foreach ( $tables as $table ) { $read = $wpdb->get_results( 'SELECT * FROM `' . $table . '` ORDER BY id', ARRAY_A ); if ( ! is_array( $read ) || $wpdb->last_error ) { throw new RuntimeException( 'Physical readback failed.' ); } $rows[$table] = $read; } return serialize( $rows ); };
try {
	foreach ( $schema->statements() as $i => $ddl ) { $created[] = $tables[$i]; dbDelta( $ddl ); }
	$db = new LegacyTransactionConnection( $wpdb ); $settings = new MigratorSettings(); $option_backend = new LegacyOptionBackend(); $blog = (int) get_current_blog_id();
	$option_backend->replace_all( array( 'queue' => false ) );
	( new TableSnapshot( $db, $blog ) )->replace_many( array( array( 'key' => 'queue', 'kind' => 'scalar', 'order' => 0, 'value' => false, 'expected_revision' => null ) ) );
	$option = new ScalarStateRepository( $option_backend, $settings ); $table = new ScalarStateRepository( new LegacyTableBackend( $db, $blog ), $settings );
	foreach ( array( array( 'set', array( 'queue', null ) ), array( 'set', array( 'queue', false ) ), array( 'set', array( 'queue', false ) ), array( 'set', array( 'future', null ) ), array( 'delete', array( 'missing' ) ), array( 'delete', array( 'queue' ) ), array( 'mutate_many', array( array( 'future' => false, '09' => null, 9 => false ) ) ), array( 'delete', array( '09' ) ) ) as $index => $operation ) {
		$before = $physical(); $expected = call_user_func_array( array( $option, $operation[0] ), $operation[1] ); $actual = call_user_func_array( array( $table, $operation[0] ), $operation[1] );
		if ( $expected !== $actual || $option->read_many( array( 'queue', 'future', 'missing', '09', 9 ) ) !== $table->read_many( array( 'queue', 'future', 'missing', '09', 9 ) ) ) { throw new RuntimeException( 'Scalar parity failed at operation ' . $index ); }
		if ( in_array( $index, array( 2, 4 ), true ) && $physical() !== $before ) { throw new RuntimeException( 'Scalar no-op changed physical bytes.' ); }
		$checks[] = 'scalar operation ' . $index . ': ' . $operation[0];
	}
	echo wp_json_encode( array( 'ok' => true, 'blog_id' => $blog, 'check_count' => count( $checks ), 'checks' => $checks ) );
} finally {
	foreach ( array_reverse( $created ) as $table ) { $wpdb->query( 'DROP TABLE IF EXISTS `' . $table . '`' ); if ( null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) || $wpdb->last_error ) { throw new RuntimeException( 'Owned scalar table cleanup failed.' ); } }
	if ( $saved === $sentinel ) { delete_option( FOOGALLERY_MIGRATE_OPTION_DATA ); } else { update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $saved ); }
	if ( get_option( FOOGALLERY_MIGRATE_OPTION_DATA, $sentinel ) !== $saved ) { throw new RuntimeException( 'Owned option restoration failed.' ); }
}
