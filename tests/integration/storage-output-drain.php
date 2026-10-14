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
if ( ! defined( 'DOING_AJAX' ) ) { define( 'DOING_AJAX', true ); }
if ( ! is_admin() ) { new \FooPlugins\FooGalleryMigrate\Init(); }
use FooPlugins\FooGalleryMigrate\MigratorEngine;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use FooPlugins\FooGalleryMigrate\StorageMaintenanceGate;
use FooPlugins\FooGalleryMigrate\StorageConversion;
use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use FooPlugins\FooGalleryMigrate\LegacyTableBackend;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;
use FooPlugins\FooGalleryMigrate\StorageCalibration;
use FooPlugins\FooGalleryMigrate\Objects\Gallery;
use FooPlugins\FooGalleryMigrate\Objects\Album;
use FooPlugins\FooGalleryMigrate\Objects\Image;
use FooPlugins\FooGalleryMigrate\Objects\Plugin;

foreach ( array( 'FOOGALLERY_CPT_GALLERY' => 'foogallery', 'FOOGALLERY_CPT_ALBUM' => 'fooalbum', 'FOOGALLERY_META_TEMPLATE' => '_foogallery_template', 'FOOGALLERY_META_SETTINGS' => '_foogallery_settings', 'FOOGALLERY_META_ATTACHMENTS' => '_foogallery_attachments', 'FOOGALLERY_ALBUM_META_TEMPLATE' => '_fooalbum_template', 'FOOGALLERY_ALBUM_META_GALLERIES' => '_fooalbum_galleries' ) as $name => $value ) { if ( ! defined( $name ) ) { define( $name, $value ); } }
if ( ! function_exists( 'foogallery_get_setting' ) ) { function foogallery_get_setting( $key ) { return ''; } }
if ( ! defined( 'FOOGALLERY_ATTACHMENT_TAXONOMY_TAG' ) ) { define( 'FOOGALLERY_ATTACHMENT_TAXONOMY_TAG', 'native_fixture_tag' ); }
register_taxonomy( FOOGALLERY_ATTACHMENT_TAXONOMY_TAG, 'attachment' );
class NativeOutputSource extends Plugin {
	public function name() { return 'NativeOutputFixture'; }
	public function detect() { return true; }
	public function find_galleries() { return array(); }
	public function find_albums() { return array(); }
	public function get_image_tags( $image ) { return array( 'native-output-fixture-tag' ); }
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
$post_hook = $meta_hook = $option_hook = $generation_hook = $term_hook = $attachment_hook = $discovery_hook = null; $source_id = 991;
$old_post = $_POST; $old_method = isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : null;
$old_fresh_site = get_option( 'fresh_site', false );
$synthetic = function () { return array( 'version' => 1, 'status' => 'approved', 'fingerprint' => StorageCalibration::fingerprint(), 'evidence_sha256' => str_repeat( 'a', 64 ), 'policy' => array( 'max_raw_bytes' => 1048576, 'amplification' => 64, 'reserve_bytes' => 33554432 ) ); };
$response = function ( $target, $blog ) {
	$die = function () { return function () { throw new RuntimeException( 'owned-json-response' ); }; };
	$purpose = 'table' === $target ? 'option_import' : 'table_export';
	$_POST = array( 'action' => 'foogallery_migrate_storage', 'operation' => 'switch', 'blog_id' => (string) $blog, 'scope' => 'site', 'target' => $target, 'confirmation' => 'switch-to-' . $target, 'nonce' => wp_create_nonce( 'foogallery_migrate_storage_' . $purpose . '_' . $blog ) );
	$_SERVER['REQUEST_METHOD'] = 'POST'; add_filter( 'wp_die_ajax_handler', $die ); ob_start();
	try { do_action( 'admin_post_foogallery_migrate_storage' ); throw new RuntimeException( 'Receiver returned without JSON.' ); }
	catch ( RuntimeException $error ) { if ( 'owned-json-response' !== $error->getMessage() ) { throw $error; } }
	finally { $raw = ob_get_clean(); remove_filter( 'wp_die_ajax_handler', $die ); }
	$value = json_decode( $raw, true ); if ( ! is_array( $value ) || ! isset( $value['success'], $value['data'] ) ) { throw new RuntimeException( 'Invalid receiver response.' ); } return $value;
};
try {
	$assert( current_user_can( 'manage_options' ), 'administrator fixture' );
	$source_attachment = wp_insert_attachment( array( 'post_title' => 'Owned base fixture image', 'post_mime_type' => 'image/gif', 'post_status' => 'inherit', 'guid' => home_url( '/wp-includes/images/blank.gif' ) ) ); $posts[] = $source_attachment;
	$source_content = '[gallery ids="' . $source_attachment . '" link="none"]';
	$source_post = wp_insert_post( array( 'post_title' => 'Owned base fixture source', 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => $source_content ) ); $posts[] = $source_post;
	$assert( 0 === (int) get_option( 'fresh_site' ), 'native source publish changes fresh_site before any engine writer' );
	foreach ( array( 'option', 'table' ) as $mode ) {
		foreach ( array( 'gallery-healthy', 'album-healthy', 'gallery-post-loss', 'album-post-loss', 'gallery-meta-loss', 'gallery-error', 'album-blog-loss', 'option-loss', 'media-healthy', 'media-post-loss', 'media-generation-loss', 'media-alt-loss', 'media-term-loss', 'media-error', 'media-reuse-healthy', 'base-healthy', 'base-discovery-loss', 'base-discovery-error', 'base-post-loss' ) as $scenario ) {
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
			$metadata_calls = 0; $post_calls = 0; $term_calls = 0; $generation_calls = 0; $discovery_calls = 0; $caught = null;
			$post_hook = function ( $id, $post, $update ) use ( $engine, $assert, $lose, $scenario, &$posts, &$post_calls, $blog ) {
				if ( $update || ! in_array( $post->post_type, array( FOOGALLERY_CPT_GALLERY, FOOGALLERY_CPT_ALBUM, 'attachment' ), true ) ) { return; }
				$posts[] = $id; $post_calls++;
				$engine->begin_migrated_object_batch(); $assert( $engine->flush_migrated_object_batch(), 'nested flush ' . $scenario );
				$assert( ! $engine->storage_is_quiescent(), 'post hook still active ' . $scenario );
				if ( false !== strpos( $scenario, 'post-loss' ) ) { $lose(); }
				if ( 'gallery-error' === $scenario ) { throw new Error( 'native output error' ); }
				if ( 'media-error' === $scenario ) { throw new Error( 'native media error' ); }
				if ( 'album-blog-loss' === $scenario ) { switch_to_blog( 1 === $blog ? 2 : 1 ); }
			};
			$meta_hook = function ( $meta_id, $id, $key ) use ( $assert, $engine, $lose, $scenario, &$posts, &$metadata_calls ) {
				if ( ! in_array( $id, $posts, true ) ) { return; }
				$metadata_calls++; $assert( ! $engine->storage_is_quiescent(), 'metadata hook active ' . $scenario );
				if ( 'gallery-meta-loss' === $scenario ) { $lose(); }
				if ( 'media-alt-loss' === $scenario && '_wp_attachment_image_alt' === $key ) { $lose(); }
			};
			$generation_hook = function ( $data ) use ( $engine, $assert, $lose, $scenario, &$generation_calls ) {
				$generation_calls++; $engine->begin_migrated_object_batch(); $assert( $engine->flush_migrated_object_batch(), 'nested metadata flush ' . $scenario );
				$assert( ! $engine->storage_is_quiescent(), 'media metadata callback active ' . $scenario );
				if ( 'media-generation-loss' === $scenario ) { $lose(); } return $data;
			};
			$term_hook = function ( $id, $terms, $tt_ids, $taxonomy ) use ( $engine, $assert, $lose, $scenario, &$term_calls ) {
				if ( FOOGALLERY_ATTACHMENT_TAXONOMY_TAG !== $taxonomy ) { return; }
				$term_calls++; $engine->begin_migrated_object_batch(); $assert( $engine->flush_migrated_object_batch(), 'nested terms flush ' . $scenario );
				$assert( ! $engine->storage_is_quiescent(), 'media term callback active ' . $scenario ); if ( 'media-term-loss' === $scenario ) { $lose(); }
			};
			$option_hook = function ( $name ) use ( $engine, $assert, $lose ) { if ( FOOGALLERY_MIGRATE_OPTION_DATA === $name ) { $assert( ! $engine->storage_is_quiescent(), 'option hook active' ); $lose(); } };
			add_action( 'wp_insert_post', $post_hook, 10, 3 ); add_action( 'added_post_meta', $meta_hook, 10, 3 ); add_action( 'updated_post_meta', $meta_hook, 10, 3 );
			// WordPress attachments emit add_attachment, not wp_insert_post.
			$attachment_hook = function ( $id ) use ( $post_hook ) { $post_hook( $id, get_post( $id ), false ); };
			add_action( 'add_attachment', $attachment_hook );
			add_filter( 'wp_generate_attachment_metadata', $generation_hook ); add_action( 'set_object_terms', $term_hook, 10, 4 );
			$object = false;
			try {
				if ( 'option-loss' === $scenario ) { add_action( 'added_option', $option_hook, 10, 1 ); add_action( 'updated_option', $option_hook, 10, 1 ); $engine->set_migrator_setting( 'fixture', $scenario ); }
				elseif ( 0 === strpos( $scenario, 'base-' ) ) {
					$source = new \FooPlugins\FooGalleryMigrate\Plugins\WordPressCore(); $source->is_detected = true;
					$assert( $engine->set_migrator_setting( MigratorEngine::KEY_PLUGINS, array( $source ) ), 'persist real core adapter ' . $scenario );
					$discovery_hook = function ( $sql ) use ( $engine, $assert, $lose, $scenario, &$discovery_calls ) {
						if ( false === strpos( $sql, "post_content LIKE '%[gallery%'" ) ) { return $sql; }
						$discovery_calls++; $engine->begin_migrated_object_batch(); $assert( $engine->flush_migrated_object_batch(), 'discovery nested flush ' . $scenario );
						$assert( ! $engine->storage_is_quiescent(), 'real base discovery remains active ' . $scenario );
						if ( 'base-discovery-loss' === $scenario ) { $lose(); }
						if ( 'base-discovery-error' === $scenario ) { throw new Error( 'native discovery error' ); } return $sql;
					};
					add_filter( 'query', $discovery_hook ); $migrator = $engine->get_gallery_migrator();
					$objects = $migrator->get_objects_to_migrate( true ); remove_filter( 'query', $discovery_hook ); $discovery_hook = null;
					$assert( 1 === count( $objects ) && 1 === $discovery_calls, 'actual shortcode discovery ' . $scenario ); $object = $objects[0];
					$assert( $engine->storage_is_quiescent(), 'discovery normal return drained ' . $scenario );
					$assert( $migrator->queue_objects_for_migration( array( $object->unique_identifier() => array( 'title' => $object->title ) ) ), 'real base queue call succeeded ' . $scenario );
					$assert( 1 === $migrator->get_state()['queued'], 'real base queue persisted ' . $scenario );
					$migrator->migrate(); $object = $migrator->get_objects_to_migrate()[0];
					$assert( 100 === (int) $migrator->get_state()['progress'], 'real base turn persisted completion ' . $scenario );
					$assert( array( $source_attachment ) === get_post_meta( $object->migrated_id, FOOGALLERY_META_ATTACHMENTS, true ), 'real attachment reused by base turn ' . $scenario );
				}
				elseif ( 0 === strpos( $scenario, 'media-' ) ) {
					$object = new Image( new NativeOutputSource() ); $object->ID = $source_id++; $object->title = 'Native attachment fixture'; $object->alt = 'Native alt text';
					$object->source_url = home_url( '/wp-includes/images/blank.gif' ) . '?fixture=' . $object->ID;
					$object->create_new_migrated_object();
					$object->migrate(); // Record completion after the direct creation boundary.
					if ( 'media-reuse-healthy' === $scenario ) {
						$reused = new Image( $object->plugin ); $reused->ID = $object->ID; $reused->source_url = $object->source_url; $reused->create_new_migrated_object();
						$assert( $reused->migrated && $reused->migrated_id === $object->migrated_id && 1 === $post_calls, 'source metadata reuses actual attachment without duplicate post' );
					}
				}
				else {
					$class = 0 === strpos( $scenario, 'album' ) ? Album::class : Gallery::class;
					$object = new $class( new NativeOutputSource() ); $object->ID = $source_id++; $object->title = 'Native output fixture';
					$child = new Image( $object->plugin ); $child->ID = 992; $child->source_url = 'https://example.invalid/already-migrated.png'; $child->migrated = true; $child->migrated_id = 993; $object->children = array( $child );
					$object->migrate();
				}
			} catch ( Throwable $error ) { $caught = $error; }
			remove_action( 'wp_insert_post', $post_hook, 10 ); remove_action( 'added_post_meta', $meta_hook, 10 ); remove_action( 'updated_post_meta', $meta_hook, 10 ); remove_action( 'added_option', $option_hook, 10 ); remove_action( 'updated_option', $option_hook, 10 );
			remove_filter( 'wp_generate_attachment_metadata', $generation_hook ); remove_action( 'set_object_terms', $term_hook, 10 );
			remove_action( 'add_attachment', $attachment_hook );
			if ( null !== $discovery_hook ) { remove_filter( 'query', $discovery_hook ); $discovery_hook = null; }
			$post_hook = $meta_hook = $option_hook = $generation_hook = $term_hook = $attachment_hook = null;
			$loss = false !== strpos( $scenario, 'loss' );
			if ( ! $loss && false === strpos( $scenario, '-error' ) && null !== $caught ) { throw $caught; }
			if ( $loss ) {
				$assert( $caught instanceof RuntimeException, 'lost authority refused ' . $scenario );
				$assert( ! $engine->storage_is_quiescent(), 'lost authority not drained ' . $scenario );
				if ( $object ) {
					$assert( ! $object->migrated, 'no object completion ' . $scenario );
					if ( 0 !== strpos( $scenario, 'media-' ) ) { $assert( ( 'gallery-meta-loss' === $scenario ? 1 : 0 ) === $metadata_calls, 'no subsequent metadata ' . $scenario ); }
					else {
						$assert( ( 'media-term-loss' === $scenario ? 1 : 0 ) === $term_calls, 'no subsequent terms ' . $scenario );
						$assert( ( 'media-post-loss' === $scenario ? 0 : 1 ) === $generation_calls, 'no subsequent generation ' . $scenario );
						$assert( 'media-term-loss' === $scenario || null === $observer->get_var( $observer->prepare( "SELECT meta_value FROM `{$observer->postmeta}` WHERE post_id=%d AND meta_key=%s", $object->migrated_id, Image::META_SOURCE_KEY ) ), 'no following source metadata ' . $scenario );
					}
				}
			} elseif ( 'gallery-error' === $scenario ) { $assert( $caught instanceof Error && 'native output error' === $caught->getMessage(), 'output Error propagated' ); $assert( $engine->storage_is_quiescent(), 'Error unwound depth' ); }
			elseif ( 'media-error' === $scenario ) { $assert( $caught instanceof Error && 'native media error' === $caught->getMessage(), 'media Error propagated' ); $assert( $engine->storage_is_quiescent(), 'media Error unwound depth' ); }
			elseif ( 'base-discovery-error' === $scenario ) { $assert( $caught instanceof Error && 'native discovery error' === $caught->getMessage(), 'discovery Error propagated' ); $assert( $engine->storage_is_quiescent(), 'base discovery Error unwound depth' ); }
			else { $assert( null === $caught, 'healthy output returned ' . $scenario ); $assert( $engine->storage_is_quiescent(), 'healthy output drained ' . $scenario ); $assert( $object->migrated && $post_calls === 1 && $metadata_calls > 0, 'native output and completion ' . $scenario ); }
			if ( 'album-blog-loss' === $scenario ) { restore_current_blog(); }
			if ( $object ) {
				// Independent real transport observes persisted completion, not just
				// the request object; losses/errors must not append history.
				if ( 'table' === $mode ) {
					$read = ( new LegacyTableBackend( new LegacyTransactionConnection( $observer ), $blog ) )->history_read( 'identifiers', array( $object->unique_identifier() ) );
					$history = array( 'items' => array() );
					foreach ( $read['result'] as $row ) { if ( $row['found'] ) { $history['items'][$object->unique_identifier()] = $row['value']; } }
					$assert( $read['context'][0]['root']['root_count'] === count( $history['items'] ), 'independent bounded history count ' . $scenario );
				}
				else { $raw = $observer->get_var( $observer->prepare( "SELECT option_value FROM `{$observer->options}` WHERE option_name=%s", FOOGALLERY_MIGRATE_OPTION_DATA ) ); $state = null === $raw ? array() : unserialize( $raw, array( 'allowed_classes' => false ) ); $history = isset( $state[MigratorSettings::KEY_MIGRATED] ) ? $state[MigratorSettings::KEY_MIGRATED] : array(); }
				$items = isset( $history['items'] ) ? $history['items'] : array();
				if ( ! $loss && 'gallery-error' !== $scenario && 'media-error' !== $scenario ) { $record = isset( $items[$object->unique_identifier()] ) ? $items[$object->unique_identifier()] : array(); $assert( count( $items ) === 1 && ! empty( $record['migrated'] ) && (int) $record['migrated_id'] === (int) $object->migrated_id, 'independent persisted completion ' . $scenario ); }
				else { $assert( array() === $items, 'independent no history advancement ' . $scenario ); }
			}
			if ( 'base-healthy' === $scenario ) {
				$engine->get_album_migrator()->get_objects_to_migrate( true );
				$checkpoint_values = array(
					'albums-state' => $engine->get_album_migrator()->get_state(),
					\FooPlugins\FooGalleryMigrate\ContentScanRepository::STATE_KEY => array( 'items' => array(), 'progress' => array( 'status' => 'complete' ) ),
					\FooPlugins\FooGalleryMigrate\ImageTagQueueRepository::STATE_KEY => array( 'items' => array(), 'errors' => array() ),
				);
				foreach ( $checkpoint_values as $key => $value ) { $assert( is_array( $value ), 'array checkpoint seed ' . $key ); $assert( $engine->set_migrator_setting( $key, $value ), 'seed real scalar-array checkpoint ' . $key ); }
				$assert( $engine->storage_is_quiescent(), 'checkpoint seed drained before selection' );
			}
			$before = $writers(); $assert( 1 === count( $before ), 'one owned writer before shutdown ' . $scenario );
			$gate->shutdown_writer();
			if ( ! $pin->is_current() ) { $assert( $before === $writers(), 'uncertain marker retained ' . $scenario ); }
			if ( 'base-healthy' === $scenario ) {
				$assert( array() === $writers(), 'base completion releases owned marker before selection' );
				// Explicit owned-fixture schema provisioning, never implicit DDL.
				if ( ! $created ) { foreach ( $schema->statements() as $i => $ddl ) { $created[] = $tables[$i]; dbDelta( $ddl ); } }
				$assert( $schema->verify(), 'explicit fixture schema before public selection' );
				unset( $GLOBALS['foogallery_migrate_engine_instance'] ); add_filter( 'foogallery_migrate_storage_calibration', $synthetic );
				$target = 'option' === $mode ? 'table' : 'option'; $result = $response( $target, $blog );
				$assert( true === $result['success'] && $target === $result['data']['backend'] && 'stable' === $result['data']['phase'], 'public receiver selects authoritative backend after real base output ' . $mode );
				remove_filter( 'foogallery_migrate_storage_calibration', $synthetic );
				$stale = null; try { $engine->get_gallery_migrator()->get_objects_to_migrate(); } catch ( RuntimeException $error ) { $stale = $error; }
				$assert( $stale instanceof RuntimeException, 'cached base engine refuses switched authority ' . $mode );
				$fresh = foogallery_migrate_migrator_instance(); $saved = $fresh->get_migrated_object( $object->unique_identifier() );
				$assert( is_object( $saved ) && $saved->migrated && $saved->migrated_id === $object->migrated_id, 'fresh engine reads authoritative completed history ' . $mode );
				$assert( 100 === (int) $fresh->get_gallery_migrator()->get_state()['progress'], 'selected backend retains base checkpoint ' . $mode );
				foreach ( $checkpoint_values as $key => $value ) {
					$assert( $value === $fresh->get_migrator_setting( $key ), 'selected backend retains scalar-array checkpoint ' . $key );
					$value['fixture_roundtrip'] = true;
					$assert( $fresh->set_migrator_setting( $key, $value ), 'selected backend updates scalar-array checkpoint ' . $key );
					$assert( $value === $fresh->get_migrator_setting( $key ), 'updated scalar-array checkpoint readback ' . $key );
				}
				StorageMaintenanceGate::current()->shutdown_writer();
				$assert( $source_content === get_post( $source_post )->post_content, 'selection never rewrites source content ' . $mode );
			}
			$owned_markers = $writers();
			$clean();
			// Keep the next base-post-loss case on a genuine insertion path.
			if ( 'base-healthy' === $scenario ) { wp_delete_post( $object->migrated_id, true ); }
		}
	}
} finally {
	if ( null !== $discovery_hook ) { remove_filter( 'query', $discovery_hook ); }
	remove_filter( 'foogallery_migrate_storage_calibration', $synthetic ); $_POST = $old_post;
	if ( null === $old_method ) { unset( $_SERVER['REQUEST_METHOD'] ); } else { $_SERVER['REQUEST_METHOD'] = $old_method; }
	if ( null !== $post_hook ) { remove_action( 'wp_insert_post', $post_hook, 10 ); remove_action( 'added_post_meta', $meta_hook, 10 ); remove_action( 'updated_post_meta', $meta_hook, 10 ); remove_action( 'added_option', $option_hook, 10 ); remove_action( 'updated_option', $option_hook, 10 ); }
	if ( null !== $generation_hook ) { remove_filter( 'wp_generate_attachment_metadata', $generation_hook ); remove_action( 'set_object_terms', $term_hook, 10 ); }
	if ( null !== $attachment_hook ) { remove_action( 'add_attachment', $attachment_hook ); }
	while ( get_current_blog_id() !== $blog && ms_is_switched() ) { restore_current_blog(); }
	if ( null !== $gate ) { $gate->shutdown_writer(); }
	$clean(); $wpdb = $original;
	foreach ( $posts as $id ) { if ( 'attachment' === get_post_type( $id ) ) { wp_delete_attachment( $id, true ); } else { wp_delete_post( $id, true ); } }
	// Native publication of our source page changes this preexisting option.
	// Restore only that demonstrated owned-fixture side effect, not DB drift.
	if ( false === $old_fresh_site ) { delete_option( 'fresh_site' ); } else { update_option( 'fresh_site', $old_fresh_site ); }
	$term = get_term_by( 'slug', 'native-output-fixture-tag', FOOGALLERY_ATTACHMENT_TAXONOMY_TAG ); if ( $term ) { wp_delete_term( $term->term_id, FOOGALLERY_ATTACHMENT_TAXONOMY_TAG ); }
	foreach ( array_reverse( $created ) as $table ) { $observer->query( "DROP TABLE `$table`" ); }
	wp_cache_flush(); unset( $GLOBALS['foogallery_migrate_engine_instance'] );
}
$assert( array() === $writers() && false === StorageAuthority::option_mode_error(), 'virgin option authority and no writer remnants' );
echo wp_json_encode( array( 'status' => 'pass', 'scope' => 'real base/core-adapter discovery, queue, output, checkpoint and public backend selection; direct gallery/album/media hooks; synthetic FooGallery settings/calibration; handle replacement, not network/PHP death/full recovery', 'checks' => $checks, 'check_count' => count( $checks ), 'blog_id' => $blog, 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'database' => $observer->get_var( 'SELECT VERSION()' ) ) ) . PHP_EOL;
