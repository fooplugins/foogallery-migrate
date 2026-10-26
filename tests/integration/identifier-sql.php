<?php
/** Native WP %i parity and actual admin/authority/WPPA/transaction call sites. */
if ( ! defined( 'FOOGM_STORAGE_TEST_DISPOSABLE' ) || true !== FOOGM_STORAGE_TEST_DISPOSABLE ) { throw new RuntimeException( 'Owned disposable fixture required.' ); }
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;
use FooPlugins\FooGalleryMigrate\TableSnapshot;
use FooPlugins\FooGalleryMigrate\StorageAdmin;
use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\Plugins\WpPhotoAlbumPlus;

global $wpdb;
$checks = 0; $created = array(); $source = dirname( __DIR__ ) . '/fixtures/identifier-statements.php';
$check = function ( $yes ) use ( &$checks ) { ++$checks; if ( ! $yes ) { throw new RuntimeException( 'Native identifier check failed: ' . $checks ); } };
$check( $wpdb->has_cap( 'identifier_placeholders' ) );
foreach ( require $source as $case ) {
	foreach ( array( 'wp_', 'wp_7_', 'Custom42_' ) as $prefix ) {
		$table = $prefix . $case[2];
		$old = str_replace( '{{table}}', $table, $case[1] );
		if ( count( $case[3] ) ) { $old = $wpdb->prepare( $old, $case[3] ); }
		$template = str_replace( array( '`{{table}}`', '{{table}}' ), '%i', $case[1] );
		$new = $wpdb->prepare( $template, array_merge( array( $table ), $case[3] ) );
		if ( false === strpos( $case[1], '`{{table}}`' ) ) { $new = str_replace( '`' . $table . '`', $table, $new ); }
		$check( $old === $new );
	}
}
$schema = new LegacyStorageSchema( $wpdb ); $tables = array_keys( $schema->definitions() );
$albums = $wpdb->prefix . 'wppa_albums'; $photos = $wpdb->prefix . 'wppa_photos';
foreach ( array_merge( $tables, array( $albums, $photos ) ) as $table ) {
	$check( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) && ! $wpdb->last_error );
}
try {
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	foreach ( $schema->statements() as $index => $ddl ) { $created[] = $tables[$index]; dbDelta( $ddl ); }
	$check( $schema->verify() );
	$check( add_option( LegacyStorageSchema::VERSION_OPTION, LegacyStorageSchema::VERSION, '', false ) );
	$check( false === StorageAuthority::option_mode_error() );
	$status = StorageAdmin::status();
	$check( 'verified' === $status['schema'] && '0' === (string) $status['records'] );
	$blog = (int) get_current_blog_id();
	$snapshot = new TableSnapshot( new LegacyTransactionConnection( $wpdb ), $blog );
	$changes = array( array( 'key' => 'identifier-fixture', 'order' => 0, 'kind' => 'map', 'value' => array( '09' => array( 'text' => "雪\x00'" ), 9 => false ), 'expected_revision' => null ) );
	$snapshot->replace_many( $changes );
	$check( $snapshot->read_bounded( 'identifier-fixture' ) === $changes[0]['value'] );
	$check( is_wp_error( StorageAuthority::option_mode_error() ) );
	// No source-image files are needed: compare raw queried rows before later filtering.
	$created[] = $albums;
	$check( false !== $wpdb->query( "CREATE TABLE `{$albums}` (id bigint PRIMARY KEY, name text, description text, a_order int, a_parent int, p_order_by int, suba_order_by int, timestamp bigint, status varchar(20), capability varchar(100)) ENGINE=InnoDB" ) );
	$created[] = $photos;
	$check( false !== $wpdb->query( "CREATE TABLE `{$photos}` (id bigint PRIMARY KEY, album bigint, ext varchar(10), name text, description text, p_order int, mean_rating int, rating_count int, owner text, timestamp bigint, status varchar(20), alt text, filename text, modified bigint, exifdtm text, videox int, videoy int, duration text, misc text) ENGINE=InnoDB" ) );
	foreach ( array( array( 3, 2, 'publish' ), array( 1, 1, 'publish' ), array( 2, 1, 'private' ) ) as $row ) {
		$check( false !== $wpdb->insert( $albums, array( 'id' => $row[0], 'name' => "O'Brien 雪", 'description' => '', 'a_order' => $row[1], 'a_parent' => 0, 'p_order_by' => 0, 'suba_order_by' => 0, 'timestamp' => 0, 'status' => $row[2], 'capability' => '' ) ) );
		$check( false !== $wpdb->insert( $photos, array( 'id' => $row[0], 'album' => $row[0], 'ext' => 'invalid', 'name' => 'No file needed', 'status' => $row[2] ) ) );
	}
	$expected = array();
	foreach ( require $source as $case ) {
		if ( 'plugins/class-wp-photo-album-plus.php' !== $case[0] ) { continue; }
		$expected[] = $wpdb->get_results( str_replace( '{{table}}', $wpdb->prefix . $case[2], $case[1] ) ); $check( ! $wpdb->last_error );
	}
	$captured = array();
	$trap = function ( $sql ) use ( &$captured, $albums, $photos ) {
		if ( false !== strpos( $sql, 'FROM `' . $albums . '`' ) && false !== strpos( $sql, 'ORDER BY a_order' ) ||
			false !== strpos( $sql, 'FROM `' . $photos . '`' ) && false !== strpos( $sql, 'ORDER BY id' ) ) { $captured[] = $sql; }
		return $sql;
	};
	$plugin = new WpPhotoAlbumPlus();
	$method = new ReflectionMethod( $plugin, 'prime_source_data' ); $method->setAccessible( true );
	add_filter( 'query', $trap );
	try { $method->invoke( $plugin ); } finally { remove_filter( 'query', $trap ); }
	$check( 2 === count( $captured ) );
	foreach ( $captured as $i => $sql ) { $check( serialize( $wpdb->get_results( $sql ) ) === serialize( $expected[$i] ) && ! $wpdb->last_error ); }
	$read = new ReflectionProperty( $plugin, 'album_rows' ); $read->setAccessible( true );
	$check( serialize( array_values( $read->getValue( $plugin ) ) ) === serialize( $expected[0] ) );
	$read = new ReflectionProperty( $plugin, 'photo_rows' ); $read->setAccessible( true );
	$check( array() === $read->getValue( $plugin ), 'Invalid extensions remain excluded after the SQL reads.' );
} finally {
	foreach ( array_reverse( $created ) as $table ) { $check( false !== $wpdb->query( 'DROP TABLE `' . $table . '`' ) ); }
	delete_option( LegacyStorageSchema::VERSION_OPTION );
}
echo wp_json_encode( array( 'status' => 'pass', 'checks' => $checks, 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'scope' => 'native preparation bytes, actual admin/authority/snapshot queries, raw WPPA row/order parity' ) ) . "\n";
