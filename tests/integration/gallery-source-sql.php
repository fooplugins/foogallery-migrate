<?php
/** Exact SQL and real result parity on the manifest-bound disposable R0 fixture. */
if ( ! defined( 'ABSPATH' ) || ! defined( 'FOOGM_STORAGE_TEST_DISPOSABLE' ) || true !== FOOGM_STORAGE_TEST_DISPOSABLE ) {
	throw new RuntimeException( 'Explicit disposable WordPress fixture required.' );
}
$manifest = json_decode( file_get_contents( ABSPATH . '.fgm-r0-owner.json' ), true );
if ( ! is_array( $manifest ) || 'foogallery-migrate-r0-v1' !== $manifest['kind'] ||
	realpath( ABSPATH ) !== realpath( $manifest['site'] ) || DB_NAME !== $manifest['database'] || DB_HOST !== $manifest['host'] ||
	! preg_match( '#^/(private/)?tmp/foogallery-migrate-r0\.[A-Za-z0-9]+$#D', realpath( ABSPATH ) ) ) {
	throw new RuntimeException( 'Disposable fixture identity differs.' );
}
require_once dirname( dirname( __DIR__ ) ) . '/migrate.php';

global $wpdb;
$checks = 0; $created = array();
$check = function ( $yes ) use ( &$checks ) {
	++$checks; if ( ! $yes ) { throw new RuntimeException( 'Source SQL parity failed: ' . $checks ); }
};
$cases = array(
	array( 'FooPlugins\\FooGalleryMigrate\\Plugins\\Envira', 'get_nextgen_galleries', 'envira' ),
	array( 'FooPlugins\\FooGalleryMigrate\\Plugins\\Modula', 'get_modula_galleries', 'modula-gallery' ),
	array( 'FooPlugins\\FooGalleryMigrate\\Plugins\\Robo', 'get_robo_galleries', 'robo_gallery_table' ),
);
$compare = function ( $case ) use ( $wpdb, $check ) {
	// The literal statement at 592c2fb, before the placeholder cleanup.
	$old_sql = "select * from {$wpdb->posts} WHERE post_type = '{$case[2]}' AND post_status = 'publish'";
	$before = $wpdb->get_results( $old_sql ); $check( ! $wpdb->last_error && is_array( $before ) );
	$method = new ReflectionMethod( $case[0], $case[1] ); $method->setAccessible( true );
	$after = $method->invoke( new $case[0]() );
	$check( ! $wpdb->last_error );
	$check( $old_sql === $wpdb->last_query );
	$check( serialize( $before ) === serialize( $after ) );
	return $after;
};
try {
	// Require clean source tables rather than accidentally accepting existing data.
	foreach ( $cases as $case ) { $check( array() === $compare( $case ) ); }
	foreach ( $cases as $case ) {
		$published = array();
		foreach ( array( 'publish', 'publish', 'draft', 'private', 'trash', 'inherit' ) as $i => $status ) {
			$id = wp_insert_post( array( 'post_type' => $case[2], 'post_status' => $status,
				'post_title' => "SQL 雪 O'Brien %_ " . $i, 'post_content' => "Keep \\ bytes & <markup>" ), true );
			$check( ! is_wp_error( $id ) && $id > 0 ); $created[] = $id;
			if ( 'publish' === $status ) { $published[] = (string) $id; }
		}
		$rows = $compare( $case );
		$ids = array(); foreach ( $rows as $row ) { $ids[] = (string) $row->ID; }
		sort( $ids ); sort( $published ); $check( $ids === $published );
	}
} finally {
	foreach ( $created as $id ) { wp_delete_post( $id, true ); $check( null === get_post( $id ) ); }
}
echo wp_json_encode( array( 'status' => 'pass', 'checks' => $checks, 'scope' => 'native WP prepare, exact statement bytes and full row/order parity', 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION ) ) . "\n";
