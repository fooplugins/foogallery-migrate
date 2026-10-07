<?php
/** Existing content/image-tag caller APIs against option and scoped table state. */
if ( ! defined( 'ABSPATH' ) || ! defined( 'FOOGM_STORAGE_TEST_DISPOSABLE' ) || true !== FOOGM_STORAGE_TEST_DISPOSABLE ) { throw new RuntimeException( 'Explicit disposable WordPress admission required.' ); }
$root = defined( 'FOOGM_STORAGE_TEST_ROOT' ) ? FOOGM_STORAGE_TEST_ROOT : dirname( dirname( __DIR__ ) );
require_once $root . '/migrate.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
use FooPlugins\FooGalleryMigrate\LegacyOptionBackend;
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use FooPlugins\FooGalleryMigrate\LegacyTableBackend;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use FooPlugins\FooGalleryMigrate\ContentScanRepository;
use FooPlugins\FooGalleryMigrate\ImageTagQueueRepository;
global $wpdb;
$schema = new LegacyStorageSchema( $wpdb ); $tables = array_keys( $schema->definitions() );
foreach ( $tables as $table ) { if ( null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) || $wpdb->last_error ) { throw new RuntimeException( 'Refusing existing tables.' ); } }
$sentinel = new stdClass(); $saved = get_option( FOOGALLERY_MIGRATE_OPTION_DATA, $sentinel ); $created = array(); $checks = array();
try {
	foreach ( $schema->statements() as $i => $ddl ) { $created[] = $tables[$i]; dbDelta( $ddl ); }
	$settings = new MigratorSettings(); $ob = new LegacyOptionBackend(); $ob->replace_all( array() ); $tb = new LegacyTableBackend( new LegacyTransactionConnection( $wpdb ), (int) get_current_blog_id() );
	$content_o = new ContentScanRepository( $ob, $settings ); $content_t = new ContentScanRepository( $tb, $settings );
	$queue_o = new ImageTagQueueRepository( $ob, $settings ); $queue_t = new ImageTagQueueRepository( $tb, $settings );
	$call = function ( $o, $t, $method, $args ) use ( &$checks ) {
		$a = call_user_func_array( array( $o, $method ), $args ); $b = call_user_func_array( array( $t, $method ), $args );
		if ( $a !== $b || $o->read_snapshot() !== $t->read_snapshot() || ( $o instanceof ContentScanRepository && $o->metadata() !== $t->metadata() ) ) { throw new RuntimeException( 'Partial state parity failed: ' . $method ); }
		$checks[] = $method;
	};
	$call( $content_o, $content_t, 'read_snapshot', array() );
	$call( $content_o, $content_t, 'update_with_scan_state', array( array( '09' => array( 'post_id' => 1, 'unknown' => 'retained opaque field' ), 9 => array( 'post_id' => 2 ) ), array( 'cursor' => 1 ) ) );
	$call( $content_o, $content_t, 'replace_post_matches', array( 1, array( 'new' => array( 'post_id' => 1 ) ), array( 'cursor' => 2 ) ) );
	$call( $content_o, $content_t, 'replace_post_matches', array( 1, array( 9 => array( 'post_id' => 1 ) ), array( 'cursor' => 3 ) ) );
	$call( $content_o, $content_t, 'update_match', array( 9, array( 'post_id' => 2, 'migrated' => true ) ) );
	$call( $content_o, $content_t, 'update_match', array( 'missing', array() ) );
	$call( $content_o, $content_t, 'remove_post', array( 1 ) );
	$call( $queue_o, $queue_t, 'update_with_sync_state', array( array( '09' => array( 'id' => 1 ), 9 => array( 'id' => 2 ) ), array( 'cursor' => 0, 'unknown' => 'retained opaque field' ) ) );
	$call( $queue_o, $queue_t, 'update_item', array( '09', array( 'id' => 1, 'done' => true ), array( 'cursor' => 1 ) ) );
	$call( $queue_o, $queue_t, 'update_item', array( 'missing', array(), array() ) );
	$call( $queue_o, $queue_t, 'append_error', array( 'error' ) );
	$call( $queue_o, $queue_t, 'clear', array() );
	$call( $content_o, $content_t, 'clear', array() );
	echo wp_json_encode( array( 'ok' => true, 'blog_id' => (int) get_current_blog_id(), 'check_count' => count( $checks ), 'checks' => $checks ) );
} finally {
	foreach ( array_reverse( $created ) as $table ) { $wpdb->query( 'DROP TABLE IF EXISTS `' . $table . '`' ); if ( null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) || $wpdb->last_error ) { throw new RuntimeException( 'Owned table cleanup failed.' ); } }
	if ( $saved === $sentinel ) { delete_option( FOOGALLERY_MIGRATE_OPTION_DATA ); } else { update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $saved ); }
	if ( get_option( FOOGALLERY_MIGRATE_OPTION_DATA, $sentinel ) !== $saved ) { throw new RuntimeException( 'Option restoration failed.' ); }
}
