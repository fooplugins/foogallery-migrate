<?php
/** Real WordPress/SQL presence regression; run only in an admitted disposable fixture. */
use FooPlugins\FooGalleryMigrate\MigratedStore;
use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\Plugins\Aigpl;
use FooPlugins\FooGalleryMigrate\Plugins\Envira;
use FooPlugins\FooGalleryMigrate\Plugins\Modula;
use FooPlugins\FooGalleryMigrate\Plugins\Robo;
use FooPlugins\FooGalleryMigrate\Plugins\Photo;
use FooPlugins\FooGalleryMigrate\Plugins\Nextgen;

if ( ! defined( 'ABSPATH' ) || ! defined( 'FOOGM_STORAGE_TEST_DISPOSABLE' ) || true !== FOOGM_STORAGE_TEST_DISPOSABLE ) {
	throw new RuntimeException( 'Explicit disposable WordPress admission required.' );
}
$root = FOOGM_STORAGE_TEST_ROOT;
require_once $root . '/migrate.php';
global $wpdb;
$handle = $wpdb->dbh;
if ( ! ( $handle instanceof mysqli ) || false !== StorageAuthority::option_mode_error() ||
	class_exists( 'Envira_Gallery_Lite' ) || class_exists( 'Modula' ) || class_exists( 'Robo_Gallery_Core' ) ||
	class_exists( 'BWG' ) || defined( 'NGG_PLUGIN_VERSION' ) ) {
	throw new RuntimeException( 'Refusing existing authority, non-SQL connection or active source plugins.' );
}
$definitions = array(
	$wpdb->base_prefix . StorageAuthority::SHARED_STATE_TABLE_SUFFIX => 'blog_id bigint NOT NULL',
	$wpdb->base_prefix . StorageAuthority::SHARED_RECORDS_TABLE_SUFFIX => 'blog_id bigint NOT NULL',
	$wpdb->prefix . MigratedStore::TABLE_SUFFIX => 'object_key varchar(191) NOT NULL, payload longtext NOT NULL',
	$wpdb->prefix . Nextgen::NEXTGEN_TABLE_GALLERY => 'gid bigint NOT NULL',
	$wpdb->prefix . Nextgen::NEXTGEN_TABLE_PICTURES => 'pid bigint NOT NULL',
	$wpdb->prefix . Photo::FM_PHOTO_TABLE_GALLERY => 'id bigint NOT NULL',
);
foreach ( $definitions as $table => $definition ) {
	if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) || null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) || $wpdb->last_error ) {
		throw new RuntimeException( 'Refusing existing or unsafe fixture table.' );
	}
}
$post_plugins = array( array( new Envira(), 'envira' ), array( new Modula(), 'modula-gallery' ), array( new Robo(), 'robo_gallery_table' ), array( new Aigpl(), Aigpl::POST_TYPE ) );
$types = array( Aigpl::POST_TYPE, 'envira', 'modula-gallery', 'robo_gallery_table' );
foreach ( $types as $type ) {
	if ( null !== $wpdb->get_var( $wpdb->prepare( 'SELECT ID FROM ' . $wpdb->posts . ' WHERE post_type = %s LIMIT 1', $type ) ) || $wpdb->last_error ) {
		throw new RuntimeException( 'Refusing existing source posts.' );
	}
}
$checks = array(); $failures = array(); $created = array(); $transaction = false;
$check = function ( $value, $label ) use ( &$checks, &$failures ) {
	$checks[] = $label;
	if ( ! $value ) { $failures[] = $label; }
};
$sql = function ( $query ) use ( $wpdb ) {
	if ( false === $wpdb->query( $query ) || $wpdb->last_error ) { throw new RuntimeException( 'Fixture SQL failed: ' . $wpdb->last_error ); }
};
$cleanup = function () use ( $wpdb, $handle, &$transaction, &$created ) {
	if ( $transaction ) {
		if ( ! mysqli_query( $handle, 'ROLLBACK' ) ) { throw new RuntimeException( 'Pinned rollback failed.' ); }
		$transaction = false;
	}
	foreach ( array_reverse( $created ) as $table ) {
		if ( false === $wpdb->query( 'DROP TABLE `' . $table . '`' ) || $wpdb->last_error ||
			null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
			throw new RuntimeException( 'Owned table cleanup failed.' );
		}
	}
	if ( $wpdb->dbh !== $handle || $wpdb->last_error ) { throw new RuntimeException( 'Pinned cleanup identity changed.' ); }
};
try {
	foreach ( $definitions as $table => $definition ) {
		$sql( 'CREATE TABLE `' . $table . '` (' . $definition . ') ENGINE=InnoDB' ); $created[] = $table;
	}
	$sql( 'START TRANSACTION' ); $transaction = true;
	// Shared tables contain only another site's rows: absence must remain site-scoped.
	foreach ( array( StorageAuthority::SHARED_STATE_TABLE_SUFFIX, StorageAuthority::SHARED_RECORDS_TABLE_SUFFIX ) as $suffix ) {
		$table = $wpdb->base_prefix . $suffix;
		$sql( 'INSERT INTO `' . $table . '` VALUES (999999)' );
	}
	// The PR15 object table itself correctly blocks option authority, even while empty.
	$object_table = $wpdb->prefix . MigratedStore::TABLE_SUFFIX;
	$sql( 'DROP TABLE `' . $object_table . '`' );
	$created = array_values( array_diff( $created, array( $object_table ) ) );
	// DDL committed the initial rows; all tables remain exclusively owned and are dropped below.
	$sql( 'START TRANSACTION' );
	$check( false === StorageAuthority::option_mode_error(), 'authority ignores only other-blog rows' );
	foreach ( array( StorageAuthority::SHARED_STATE_TABLE_SUFFIX, StorageAuthority::SHARED_RECORDS_TABLE_SUFFIX ) as $suffix ) {
		$table = $wpdb->base_prefix . $suffix;
		$sql( 'INSERT INTO `' . $table . '` VALUES (' . (int) get_current_blog_id() . ')' );
		$error = StorageAuthority::option_mode_error();
		$check( is_wp_error( $error ) && 'foogallery_migrate_storage_recovery_required' === $error->get_error_code(), $suffix . ' present rows refuse option authority' );
		$sql( 'DELETE FROM `' . $table . '` WHERE blog_id = ' . (int) get_current_blog_id() );
		$check( false === StorageAuthority::option_mode_error(), $suffix . ' absent current-blog rows allow options' );
	}
	// Build the owned presence-only store table before restarting the pinned transaction.
	$sql( 'CREATE TABLE `' . $object_table . '` (' . $definitions[$object_table] . ') ENGINE=InnoDB' ); $created[] = $object_table;
	$sql( 'START TRANSACTION' );
	$store = new MigratedStore( $wpdb );
	$check( false === $store->has( 'sql-existence-owned' ), 'store missing row is false' );
	$sql( "INSERT INTO `" . $object_table . "` VALUES ('sql-existence-owned', 'invalid payload proves existence shortcut')" );
	$check( true === $store->has( 'sql-existence-owned' ), 'store present row is true without hydration' );
	foreach ( $post_plugins as $entry ) {
		$plugin = $entry[0]; $type = $entry[1];
		$check( false === $plugin->detect(), $plugin->name() . ' absent source is false' );
		$sql( $wpdb->prepare( "INSERT INTO {$wpdb->posts} (post_type,post_status,post_title,post_content,post_excerpt,to_ping,pinged,post_content_filtered) VALUES (%s,'publish','owned SQL existence','','','','','')", $type ) );
		$id = (int) $wpdb->insert_id;
		if ( $plugin instanceof Aigpl ) {
			$check( false === $plugin->detect(), 'AIGPL no metadata remains false' );
			$sql( $wpdb->prepare( "INSERT INTO {$wpdb->postmeta} (post_id,meta_key,meta_value) VALUES (%d,%s,'')", $id, Aigpl::META_GALLERY_IMAGES ) );
			$check( false === $plugin->detect(), 'AIGPL empty metadata remains false' );
			$sql( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = '1' WHERE post_id = %d", $id ) );
		}
		$check( true === $plugin->detect(), $plugin->name() . ' present source is true' );
		if ( $plugin instanceof Aigpl ) {
			$sql( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_status = 'draft' WHERE ID = %d", $id ) );
			$check( false === $plugin->detect(), 'AIGPL unpublished source remains false' );
		}
	}
	foreach ( array( array( new Nextgen(), Nextgen::NEXTGEN_TABLE_GALLERY ), array( new Photo(), Photo::FM_PHOTO_TABLE_GALLERY ) ) as $entry ) {
		$table = $wpdb->prefix . $entry[1];
		$check( false === $entry[0]->detect(), $entry[0]->name() . ' empty table is false' );
		$sql( 'INSERT INTO `' . $table . '` VALUES (991)' );
		$check( true === $entry[0]->detect(), $entry[0]->name() . ' present table row is true' );
	}
	$nextgen = new Nextgen();
	$check( false === $nextgen->has_migratable_image_tags(), 'NextGEN absent tag join is false' );
	$sql( 'INSERT INTO `' . $wpdb->prefix . Nextgen::NEXTGEN_TABLE_PICTURES . '` VALUES (991)' );
	$sql( "INSERT INTO {$wpdb->term_taxonomy} (term_id,taxonomy,description,parent,count) VALUES (0,'ngg_tag','owned SQL existence',0,1)" );
	$taxonomy_id = (int) $wpdb->insert_id;
	$sql( "INSERT INTO {$wpdb->term_relationships} (object_id,term_taxonomy_id,term_order) VALUES (991," . $taxonomy_id . ',0)' );
	$check( true === $nextgen->has_migratable_image_tags(), 'NextGEN present tag join is true' );
	$sql( 'DELETE FROM ' . $wpdb->term_relationships . ' WHERE object_id = 991 AND term_taxonomy_id = ' . $taxonomy_id );
	$check( false === $nextgen->has_migratable_image_tags(), 'NextGEN removed relationship is false' );
} catch ( Exception $error ) {
	$cleanup(); throw $error;
}
$cleanup();
echo json_encode( array( 'ok' => empty( $failures ), 'check_count' => count( $checks ), 'checks' => $checks, 'failures' => $failures ) );
if ( $failures ) { throw new RuntimeException( 'SQL existence failures: ' . implode( '; ', $failures ) ); }
