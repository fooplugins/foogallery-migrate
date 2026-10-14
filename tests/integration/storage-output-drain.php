<?php
/** Native real WordPress output-hook regression. Modern CLI only, disposable DB.
 * Synthetic source/FooGallery settings and calibration, actual production objects,
 * coordinator/backend and native post/meta/option writes. Not network/death proof.
 */
if ( ! defined( 'FOOGM_STORAGE_TEST_DISPOSABLE' ) || true !== FOOGM_STORAGE_TEST_DISPOSABLE || ! defined( 'FOOGM_STORAGE_TEST_ROOT' ) ) { throw new RuntimeException( 'Disposable fixture required.' ); }
$root = realpath( FOOGM_STORAGE_TEST_ROOT );
if ( ! $root || ! is_file( $root . '/migrate.php' ) ) { throw new RuntimeException( 'Invalid fixture root.' ); }
require $root . '/migrate.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
use FooPlugins\FooGalleryMigrate\MigratorEngine;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use FooPlugins\FooGalleryMigrate\StorageMaintenanceGate;
use FooPlugins\FooGalleryMigrate\StorageConversion;
use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use FooPlugins\FooGalleryMigrate\LegacyTableBackend;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;
use FooPlugins\FooGalleryMigrate\Objects\Gallery;
use FooPlugins\FooGalleryMigrate\Objects\Album;
use FooPlugins\FooGalleryMigrate\Objects\Image;
use FooPlugins\FooGalleryMigrate\Objects\Plugin;

foreach ( array( 'FOOGALLERY_CPT_GALLERY' => 'foogallery', 'FOOGALLERY_CPT_ALBUM' => 'fooalbum', 'FOOGALLERY_META_TEMPLATE' => '_foogallery_template', 'FOOGALLERY_META_SETTINGS' => '_foogallery_settings', 'FOOGALLERY_META_ATTACHMENTS' => '_foogallery_attachments', 'FOOGALLERY_ALBUM_META_TEMPLATE' => '_fooalbum_template', 'FOOGALLERY_ALBUM_META_GALLERIES' => '_fooalbum_galleries' ) as $name => $value ) { if ( ! defined( $name ) ) { define( $name, $value ); } }
if ( ! function_exists( 'foogallery_get_setting' ) ) { function foogallery_get_setting( $key ) { return ''; } }
class NativeOutputSource extends Plugin {
	public function name() { return 'NativeOutputFixture'; }
	public function detect() { return true; }
	public function find_galleries() { return array(); }
	public function find_albums() { return array(); }
	public function get_gallery_template( $gallery ) { return 'default'; }
	public function get_gallery_settings( $gallery, $settings ) { return $settings; }
}

global $wpdb;
$original = $wpdb; $blog = (int) get_current_blog_id();
$observer = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$observer->set_prefix( $original->base_prefix );
if ( is_multisite() ) { $observer->set_blog_id( $blog ); }
$observer->suppress_errors( true );
$schema = new LegacyStorageSchema( $observer ); $tables = array_keys( $schema->definitions() );
$names = array( FOOGALLERY_MIGRATE_OPTION_DATA, FOOGALLERY_MIGRATE_OPTION_SETTINGS, StorageAuthority::CONTROL_OPTION, StorageAuthority::AUTHORITY_OPTION, StorageAuthority::MAINTENANCE_OPTION, StorageAuthority::STORAGE_BACKUP_OPTION );
$pattern = $observer->esc_like( StorageMaintenanceGate::WRITER_PREFIX ) . '%';
$writers = function () use ( $observer, $pattern ) { return $observer->get_results( $observer->prepare( "SELECT option_name,option_value FROM `{$observer->options}` WHERE option_name LIKE %s ORDER BY option_name", $pattern ), ARRAY_A ); };
foreach ( $tables as $table ) { if ( null !== $observer->get_var( $observer->prepare( 'SHOW TABLES LIKE %s', $observer->esc_like( $table ) ) ) ) { throw new RuntimeException( 'Refusing preexisting tables.' ); } }
foreach ( $names as $name ) { if ( null !== $observer->get_var( $observer->prepare( "SELECT 1 FROM `{$observer->options}` WHERE option_name=%s", $name ) ) ) { throw new RuntimeException( 'Refusing preexisting state.' ); } }
if ( array() !== $writers() ) { throw new RuntimeException( 'Refusing preexisting writers.' ); }
$checks = array(); $posts = array(); $owned_markers = array(); $created = array(); $gate = null;
$assert = function ( $condition, $label ) use ( &$checks ) { if ( ! $condition ) { throw new RuntimeException( $label ); } $checks[] = $label; };
$clean = function () use ( $observer, &$created, &$owned_markers, $names, $blog, $assert ) {
	foreach ( $owned_markers as $row ) {
		$assert( 1 === $observer->query( $observer->prepare( "DELETE FROM `{$observer->options}` WHERE option_name=%s AND BINARY option_value=BINARY %s", $row['option_name'], $row['option_value'] ) ), 'exact fixture-owned marker cleanup, not product recovery' );
	}
	$owned_markers = array();
	foreach ( $created as $table ) { $observer->query( $observer->prepare( "DELETE FROM `$table` WHERE blog_id=%d", $blog ) ); }
	foreach ( $names as $name ) { $observer->query( $observer->prepare( "DELETE FROM `{$observer->options}` WHERE option_name=%s", $name ) ); }
	wp_cache_flush(); unset( $GLOBALS['foogallery_migrate_engine_instance'] );
};
$post_hook = $meta_hook = $option_hook = null; $source_id = 991;
try {
	$assert( current_user_can( 'manage_options' ), 'administrator fixture' );
	foreach ( array( 'option', 'table' ) as $mode ) {
		foreach ( array( 'gallery-healthy', 'album-healthy', 'gallery-post-loss', 'album-post-loss', 'gallery-meta-loss', 'gallery-error', 'album-blog-loss', 'option-loss' ) as $scenario ) {
			if ( ( 'table' === $mode && 'option-loss' === $scenario ) || ( ! is_multisite() && 'album-blog-loss' === $scenario ) ) { continue; }
			$clean();
			// A latched failed transport is NOT reset by restoring its handle. Each
			// independent scenario starts a fresh native wpdb/gate identity.
			$wpdb = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ); $wpdb->set_prefix( $original->base_prefix );
			if ( is_multisite() ) { $wpdb->set_blog_id( $blog ); }
			$gate = StorageMaintenanceGate::current();
			if ( 'table' === $mode ) {
				if ( ! $created ) { foreach ( $schema->statements() as $i => $ddl ) { $created[] = $tables[$i]; dbDelta( $ddl ); } }
				$assert( $schema->verify(), 'verified schema ' . $scenario );
				update_option( FOOGALLERY_MIGRATE_OPTION_DATA, array( 'fixture' => $scenario ), false );
				$assert( ( new MigratorSettings() )->set_migrator_setting( MigratorSettings::KEY_MIGRATED, array() ), 'seed normal compact history ' . $scenario );
				$assert( ( new MigratorSettings() )->set_migrator_setting( MigratorSettings::KEY_MIGRATED_REVISION, 0 ), 'seed normal history revision ' . $scenario );
				$request = array( 'blog_id' => $blog, 'scope' => 'site', 'target' => 'table', 'confirmation' => 'switch-to-table', 'nonce' => wp_create_nonce( 'foogallery_migrate_storage_option_import_' . $blog ) );
				$assert( ! is_wp_error( ( new StorageConversion( array( 'max_raw_bytes' => 1048576, 'amplification' => 64, 'reserve_bytes' => 33554432 ) ) )->switch_backend( $request ) ), 'actual coordinator conversion ' . $scenario );
				$authority = StorageAuthority::runtime_record( $gate );
				$assert( is_array( $authority ) && 'table' === $authority['backend'], 'verified authority ' . $scenario );
			}
			$engine = foogallery_migrate_migrator_instance(); $engine->assert_storage_writer();
			$pin = $gate->connection(); $replacement = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
			$lose = function () use ( $replacement ) { global $wpdb; $wpdb->dbh = $replacement->dbh; };
			$metadata_calls = 0; $post_calls = 0; $caught = null;
			$post_hook = function ( $id, $post, $update ) use ( $engine, $assert, $lose, $scenario, &$posts, &$post_calls, $blog ) {
				if ( $update || ! in_array( $post->post_type, array( FOOGALLERY_CPT_GALLERY, FOOGALLERY_CPT_ALBUM ), true ) ) { return; }
				$posts[] = $id; $post_calls++;
				$engine->begin_migrated_object_batch(); $assert( $engine->flush_migrated_object_batch(), 'nested flush ' . $scenario );
				$assert( ! $engine->storage_is_quiescent(), 'post hook still active ' . $scenario );
				if ( false !== strpos( $scenario, 'post-loss' ) ) { $lose(); }
				if ( 'gallery-error' === $scenario ) { throw new Error( 'native output error' ); }
				if ( 'album-blog-loss' === $scenario ) { switch_to_blog( 1 === $blog ? 2 : 1 ); }
			};
			$meta_hook = function ( $meta_id, $id, $key ) use ( $assert, $engine, $lose, $scenario, &$posts, &$metadata_calls ) {
				if ( ! in_array( $id, $posts, true ) ) { return; }
				$metadata_calls++; $assert( ! $engine->storage_is_quiescent(), 'metadata hook active ' . $scenario );
				if ( 'gallery-meta-loss' === $scenario ) { $lose(); }
			};
			$option_hook = function ( $name ) use ( $engine, $assert, $lose ) { if ( FOOGALLERY_MIGRATE_OPTION_DATA === $name ) { $assert( ! $engine->storage_is_quiescent(), 'option hook active' ); $lose(); } };
			add_action( 'wp_insert_post', $post_hook, 10, 3 ); add_action( 'added_post_meta', $meta_hook, 10, 3 ); add_action( 'updated_post_meta', $meta_hook, 10, 3 );
			$object = false;
			try {
				if ( 'option-loss' === $scenario ) { add_action( 'added_option', $option_hook, 10, 1 ); add_action( 'updated_option', $option_hook, 10, 1 ); $engine->set_migrator_setting( 'fixture', $scenario ); }
				else {
					$class = 0 === strpos( $scenario, 'album' ) ? Album::class : Gallery::class;
					$object = new $class( new NativeOutputSource() ); $object->ID = $source_id++; $object->title = 'Native output fixture';
					$child = new Image( $object->plugin ); $child->ID = 992; $child->source_url = 'https://example.invalid/already-migrated.png'; $child->migrated = true; $child->migrated_id = 993; $object->children = array( $child );
					$object->migrate();
				}
			} catch ( Throwable $error ) { $caught = $error; }
			remove_action( 'wp_insert_post', $post_hook, 10 ); remove_action( 'added_post_meta', $meta_hook, 10 ); remove_action( 'updated_post_meta', $meta_hook, 10 ); remove_action( 'added_option', $option_hook, 10 ); remove_action( 'updated_option', $option_hook, 10 );
			$post_hook = $meta_hook = $option_hook = null;
			$loss = false !== strpos( $scenario, 'loss' );
			if ( ! $loss && 'gallery-error' !== $scenario && null !== $caught ) { throw $caught; }
			if ( $loss ) {
				$assert( $caught instanceof RuntimeException, 'lost authority refused ' . $scenario );
				$assert( ! $engine->storage_is_quiescent(), 'lost authority not drained ' . $scenario );
				if ( $object ) { $assert( ! $object->migrated, 'no object completion ' . $scenario ); $assert( ( 'gallery-meta-loss' === $scenario ? 1 : 0 ) === $metadata_calls, 'no subsequent metadata ' . $scenario ); }
			} elseif ( 'gallery-error' === $scenario ) { $assert( $caught instanceof Error && 'native output error' === $caught->getMessage(), 'output Error propagated' ); $assert( $engine->storage_is_quiescent(), 'Error unwound depth' ); }
			else { $assert( null === $caught, 'healthy output returned ' . $scenario ); $assert( $engine->storage_is_quiescent(), 'healthy output drained ' . $scenario ); $assert( $object->migrated && $post_calls === 1 && $metadata_calls > 0, 'native output and completion ' . $scenario ); }
			if ( 'album-blog-loss' === $scenario ) { restore_current_blog(); }
			if ( $object ) {
				// Independent real transport observes persisted completion, not just
				// the request object; losses/errors must not append history.
				if ( 'table' === $mode ) { $history = ( new LegacyTableBackend( new LegacyTransactionConnection( $observer ), $blog ) )->read( MigratorSettings::KEY_MIGRATED ); $history = $history['value']; }
				else { $raw = $observer->get_var( $observer->prepare( "SELECT option_value FROM `{$observer->options}` WHERE option_name=%s", FOOGALLERY_MIGRATE_OPTION_DATA ) ); $state = null === $raw ? array() : unserialize( $raw, array( 'allowed_classes' => false ) ); $history = isset( $state[MigratorSettings::KEY_MIGRATED] ) ? $state[MigratorSettings::KEY_MIGRATED] : array(); }
				$items = isset( $history['items'] ) ? $history['items'] : array();
				if ( ! $loss && 'gallery-error' !== $scenario ) { $record = isset( $items[$object->unique_identifier()] ) ? $items[$object->unique_identifier()] : array(); $assert( count( $items ) === 1 && ! empty( $record['migrated'] ) && (int) $record['migrated_id'] === (int) $object->migrated_id, 'independent persisted completion ' . $scenario ); }
				else { $assert( array() === $items, 'independent no history advancement ' . $scenario ); }
			}
			$before = $writers(); $assert( 1 === count( $before ), 'one owned writer before shutdown ' . $scenario );
			$gate->shutdown_writer();
			if ( ! $pin->is_current() ) { $assert( $before === $writers(), 'uncertain marker retained ' . $scenario ); }
			$owned_markers = $writers();
			$clean();
		}
	}
} finally {
	if ( null !== $post_hook ) { remove_action( 'wp_insert_post', $post_hook, 10 ); remove_action( 'added_post_meta', $meta_hook, 10 ); remove_action( 'updated_post_meta', $meta_hook, 10 ); remove_action( 'added_option', $option_hook, 10 ); remove_action( 'updated_option', $option_hook, 10 ); }
	while ( get_current_blog_id() !== $blog && ms_is_switched() ) { restore_current_blog(); }
	if ( null !== $gate ) { $gate->shutdown_writer(); }
	$clean(); $wpdb = $original;
	foreach ( $posts as $id ) { wp_delete_post( $id, true ); }
	foreach ( array_reverse( $created ) as $table ) { $observer->query( "DROP TABLE `$table`" ); }
	wp_cache_flush(); unset( $GLOBALS['foogallery_migrate_engine_instance'] );
}
$assert( array() === $writers() && false === StorageAuthority::option_mode_error(), 'virgin option authority and no writer remnants' );
echo wp_json_encode( array( 'status' => 'pass', 'scope' => 'bounded real gallery/album/option output hooks; synthetic source/settings/policy; deliberate handle replacement, not network/PHP death or full drain', 'checks' => $checks, 'check_count' => count( $checks ), 'blog_id' => $blog, 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'database' => $observer->get_var( 'SELECT VERSION()' ) ) ) . PHP_EOL;
