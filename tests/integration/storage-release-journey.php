<?php
/**
 * Positive, resumable companion to storage-release-policy.php (never replaces it).
 * One explicit step per fresh CLI process. The EXTERNAL aggregate ledger must
 * reserve each invocation/public request. No automatic recovery or test loop.
 * All errors leave journal/output/evidence in place; no failure-finally purge.
 */
if ( 'cli' !== PHP_SAPI || 4 !== $argc || ! in_array( $argv[1], array( '54', '56' ), true ) ||
	! in_array( $argv[2], array( 'preflight', 'provision', 'forward', 'seed-real', 'turn', 'edit', 'reverse', 'cleanup' ), true ) ||
	! preg_match( '/^[a-f0-9]{64}$/D', $argv[3] ) ) { throw new RuntimeException( 'Explicit floor, step and expected implementation digest required.' ); }
$tag = $argv[1]; $step = $argv[2]; $expected_digest = $argv[3];
$versions = array( '54' => array( '5.4.0', '5.0' ), '56' => array( '5.6.20', '6.0' ) );
if ( PHP_VERSION !== $versions[$tag][0] ) { throw new RuntimeException( 'Literal interpreter mismatch.' ); }
require '/srv/fgm-narrow-tfc1db765/wp' . $tag . '/wp-load.php';
require_once __DIR__ . '/../support/StorageReleaseJourney.php';
require_once __DIR__ . '/../support/StorageReleaseNativeFixture.php';
use FooPlugins\FooGalleryMigrate\Tests\StorageReleaseJourney as J;
use FooPlugins\FooGalleryMigrate\Tests\StorageReleaseNativeFixture as Native;
use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\StorageControl;
use FooPlugins\FooGalleryMigrate\StorageCapacityPolicy;
use FooPlugins\FooGalleryMigrate\StorageMaintenanceGate;
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use FooPlugins\FooGalleryMigrate\Plugins\Aigpl;
global $wpdb;
J::check( ! is_multisite() && $GLOBALS['wp_version'] === $versions[$tag][1] &&
	$wpdb->get_var( 'SELECT DATABASE()' ) === 'fgm_narrow_tfc1db765_' . $tag && ! $wpdb->last_error, 'Owned single-site floor mismatch.' );
J::check( StorageCapacityPolicy::implementation_digest() === $expected_digest, 'Installed implementation is not this test checkpoint.' );
$loaded = new ReflectionClass( 'FooPlugins\\FooGalleryMigrate\\StorageConversion' );
J::check( $loaded->getFileName() === '/srv/fgm-narrow-tfc1db765/wp' . $tag . '/wp-content/plugins/foogallery-migrate/includes/class-storage-conversion.php', 'Loaded plugin path mismatch.' );
wp_set_current_user( 1 ); J::check( current_user_can( 'manage_options' ), 'Real administrator required.' );
$blog = get_current_blog_id(); $tables = array_keys( ( new LegacyStorageSchema( $wpdb ) )->definitions() );
$raw = function ( $name ) use ( $wpdb ) {
	$v = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `' . $wpdb->options . '` WHERE option_name=%s', $name ) );
	J::check( ! $wpdb->last_error, 'Option observation failed.' ); return $v;
};
$control = function () use ( $raw, $blog ) { return StorageControl::decode( $raw( StorageAuthority::CONTROL_OPTION ), $blog ); };
$save = function ( $journal ) { update_option( J::JOURNAL, $journal, false ); J::check( get_option( J::JOURNAL ) === $journal, 'Journal readback failed.' ); };
$journal = get_option( J::JOURNAL, null ); $result = null;
if ( 'preflight' === $step ) {
	J::check( null === $journal && null === $raw( StorageAuthority::CONTROL_OPTION ) && null === $raw( StorageAuthority::AUTHORITY_OPTION ) &&
		null === $raw( StorageAuthority::MAINTENANCE_OPTION ) && null === $raw( StorageAuthority::STORAGE_BACKUP_OPTION ), 'Do not replace an owner or conversion.' );
	$initial = $raw( FOOGALLERY_MIGRATE_OPTION_DATA );
	J::check( is_string( $initial ) && strlen( $initial ) === Native::RAW_BYTES && hash( 'sha256', $initial ) === Native::RAW_SHA256, 'Exact original native-text20K seed required; no reseeding.' ); unset( $initial );
	// This is the intentional first RED barrier with today's empty policy.
	// A refusal must not write even this test's journal.
	$result = J::preflight();
	foreach ( $tables as $table ) {
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		J::check( ! $wpdb->last_error && ( null === $found || $table === $found ), 'Ambiguous initial schema.' );
		if ( null !== $found ) {
			( new LegacyStorageSchema( $wpdb ) )->verify( $table );
			J::check( '0' === (string) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $table . '`' ) && ! $wpdb->last_error, 'Initially empty verified tables required; no shared-data overwrite.' );
		}
	}
	J::check( ! $wpdb->get_var( $wpdb->prepare( 'SELECT ID FROM `' . $wpdb->posts . '` WHERE post_type=%s LIMIT 1', Aigpl::POST_TYPE ) ) && ! $wpdb->last_error, 'Unrelated AIGPL source exists.' );
	$save( array( 'owner' => J::OWNER, 'digest' => $expected_digest, 'phase' => 'provision', 'ids' => array(), 'turns' => 0,
		'saved_settings' => get_option( FOOGALLERY_MIGRATE_OPTION_SETTINGS, null ) ) );
} else {
	J::check( is_array( $journal ) && isset( $journal['owner'], $journal['digest'], $journal['phase'] ) &&
		J::OWNER === $journal['owner'] && $expected_digest === $journal['digest'], 'Missing/mismatched journey owner; preserve all state.' );
	if ( 'provision' === $step ) {
		J::check( 'provision' === $journal['phase'] && null === $raw( StorageAuthority::CONTROL_OPTION ) && null === $raw( StorageAuthority::MAINTENANCE_OPTION ), 'Do not provision over another owner.' );
		$result = J::request( 'provision', 'table' ); ( new LegacyStorageSchema( $wpdb ) )->verify();
		foreach ( $tables as $table ) { J::check( '0' === (string) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $table . '`' ) && ! $wpdb->last_error, 'Unexpected provisioned data.' ); }
		$journal['phase'] = 'forward'; $save( $journal );
	} elseif ( 'forward' === $step || 'reverse' === $step ) {
		$target = 'forward' === $step ? 'table' : 'option'; J::check( $journal['phase'] === $step, 'Wrong transition step.' );
		$c = $control(); $operation = is_array( $c ) && 'stable' !== $c['phase'] ? 'recover' : 'switch';
		if ( 'reverse' === $step ) { J::check( ! is_array( $c ) || 'table' === $c['backend'] || 'stable' !== $c['phase'], 'Do not repeat a completed reversal.' ); }
		$result = J::request( $operation, $target );
		if ( 'stable' === $result['phase'] ) {
			J::check( $target === $result['backend'], 'Wrong stable authority.' );
			if ( 'table' === $target ) {
				J::check( null === $raw( FOOGALLERY_MIGRATE_OPTION_DATA ) && hash( 'sha256', $raw( StorageAuthority::STORAGE_BACKUP_OPTION ) ) === Native::RAW_SHA256, 'Forward exact-byte cleanup/backup mismatch.' );
				$head = $wpdb->get_row( $wpdb->prepare( 'SELECT record_count FROM `' . $tables[0] . '` WHERE blog_id=%d', $blog ), ARRAY_A );
				J::check( is_array( $head ) && (int) $head['record_count'] >= J::NATIVE_IMAGES && ! $wpdb->last_error, 'Native20K conversion was not performed.' );
				$journal['phase'] = 'seed-real';
			} else {
				$engine = foogallery_migrate_migrator_instance();
				J::check( $journal['edit'] === $engine->get_migrator_setting( J::OWNER ), 'Reversal lost CURRENT table edit.' );
				J::check( $raw( FOOGALLERY_MIGRATE_OPTION_DATA ) !== $raw( StorageAuthority::STORAGE_BACKUP_OPTION ), 'Reversal revived the stale native backup.' );
				$journal['stable_control'] = $raw( StorageAuthority::CONTROL_OPTION ); $journal['phase'] = 'cleanup';
			}
			$save( $journal );
		}
	} elseif ( 'seed-real' === $step ) {
		J::check( 'seed-real' === $journal['phase'] && 'table' === $control()['backend'], 'Table-backed real fixture required.' );
		J::check( function_exists( 'foogallery_default_gallery_template' ) && defined( 'FOOGALLERY_VERSION' ), 'Actual FooGallery required; no API substitute.' );
		register_post_type( Aigpl::POST_TYPE, array( 'public' => false ) );
		$engine = foogallery_migrate_migrator_instance();
		if ( ! count( $journal['ids'] ) ) {
			// Distinguish conversion of synthetic20K from an ACTUAL original10K
			// AIGPL migration. Replace synthetic detection via normal table writers.
			J::check( $engine->set_migrator_setting( MigratorSettings::KEY_GALLERIES, array() ), 'Normal table detection reset refused.' );
		}
		$upload = wp_upload_dir(); $dir = $upload['basedir'] . '/' . J::OWNER;
		if ( ! isset( $journal['media_dir'] ) ) { J::check( ! file_exists( $dir ) && wp_mkdir_p( $dir ), 'Owned media directory collision.' ); $journal['media_dir'] = $dir; $save( $journal ); }
		J::check( $journal['media_dir'] === $dir && is_dir( $dir ) && ! is_link( $dir ), 'Media ownership changed.' );
		// Bounded fixture seeding only; never a loop of hidden public requests.
		$end = min( J::REAL_IMAGES, count( $journal['ids'] ) + 256 );
		for ( $i = count( $journal['ids'] ); $i < $end; ++$i ) {
			$file = $dir . '/' . $i . '.png'; J::check( ! file_exists( $file ), 'Unexpected owned output; stop rather than overwrite.' );
			J::check( false !== file_put_contents( $file, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=' ) ), 'PNG write failed.' );
			$relative = substr( $file, strlen( $upload['basedir'] ) + 1 );
			$id = wp_insert_post( array( 'post_type' => 'attachment', 'post_mime_type' => 'image/png', 'post_status' => 'inherit', 'post_title' => J::OWNER . ' ' . $i,
				'guid' => $upload['baseurl'] . '/' . $relative, 'meta_input' => array( J::POST_OWNER => J::OWNER ) ), true );
			J::check( ! is_wp_error( $id ) && $id > 0, 'Owned attachment creation failed.' );
			update_post_meta( $id, '_wp_attached_file', $relative ); update_post_meta( $id, '_wp_attachment_metadata', array( 'width' => 1, 'height' => 1, 'file' => $relative, 'sizes' => array() ) );
			$journal['ids'][] = $id; $save( $journal );
		}
		if ( J::REAL_IMAGES === count( $journal['ids'] ) ) {
			$id = wp_insert_post( array( 'post_type' => Aigpl::POST_TYPE, 'post_status' => 'publish', 'post_title' => J::OWNER, 'meta_input' => array( J::POST_OWNER => J::OWNER ) ), true );
			J::check( ! is_wp_error( $id ) && $id > 0, 'Owned AIGPL source creation failed.' ); update_post_meta( $id, Aigpl::META_GALLERY_IMAGES, $journal['ids'] );
			$journal['source'] = $id; $journal['phase'] = 'turn'; $save( $journal );
		}
	} elseif ( 'turn' === $step ) {
		J::check( 'turn' === $journal['phase'] && count( $journal['ids'] ) === J::REAL_IMAGES, 'Original10K source required.' );
		$engine = foogallery_migrate_migrator_instance();
		if ( 0 === $journal['turns'] ) {
			$engine->run_detection(); $migrator = $engine->get_gallery_migrator(); $objects = $migrator->get_objects_to_migrate();
			J::check( 1 === count( $objects ) && J::REAL_IMAGES === $objects[0]->children_count, 'Original-size real discovery mismatch.' );
			$journal['uid'] = $objects[0]->unique_identifier();
			J::check( $migrator->queue_objects_for_migration( array( $journal['uid'] => array( 'title' => J::OWNER ) ) ), 'Queue refused.' ); $save( $journal );
		} else { $migrator = $engine->get_gallery_migrator(); }
		// One ordinary migration turn, unchanged images_per_turn. Fresh process
		// on re-entry. A slice's64-request ceiling never grants an automatic retry.
		J::check( $migrator->migrate(), 'Real migration turn failed.' ); ++$journal['turns'];
		$objects = $migrator->get_objects_to_migrate(); J::check( 1 === count( $objects ), 'Real gallery disappeared.' ); $object = $objects[0];
		$journal['destination'] = $object->migrated_id; $journal['completed'] = $object->migrated_child_count;
		if ( $object->migrated ) {
			J::check( J::REAL_IMAGES === $object->migrated_child_count && $journal['ids'] === get_post_meta( $object->migrated_id, FOOGALLERY_META_ATTACHMENTS, true ), 'Actual destination images/order mismatch.' );
			J::check( 1 === count( get_posts( array( 'post_type' => FOOGALLERY_CPT_GALLERY, 'post_status' => 'any', 'fields' => 'ids', 'meta_key' => \FooPlugins\FooGalleryMigrate\Objects\Migratable::META_SOURCE_KEY, 'meta_value' => $journal['uid'] ) ) ), 'Duplicate real destination.' );
			J::check( false !== strpos( do_shortcode( '[foogallery id="' . $object->migrated_id . '"]' ), 'foogallery' ), 'Actual FooGallery rendering failed.' );
			$journal['phase'] = 'edit';
		}
		$save( $journal );
	} elseif ( 'edit' === $step ) {
		J::check( 'edit' === $journal['phase'] && J::REAL_IMAGES === $journal['completed'] && 'table' === $control()['backend'], 'Completed actual migration on tables required.' );
		$journal['edit'] = array( 'current' => true, '09' => null, 9 => false, 'bytes' => "x\0" ); $engine = foogallery_migrate_migrator_instance();
		J::check( $engine->set_migrator_setting( J::OWNER, $journal['edit'] ) && $journal['edit'] === $engine->get_migrator_setting( J::OWNER ), 'Normal table edit/readback failed.' );
		StorageMaintenanceGate::current()->shutdown_writer(); $journal['phase'] = 'reverse'; $save( $journal );
	} elseif ( 'cleanup' === $step ) {
		J::check( 'cleanup' === $journal['phase'] && $journal['stable_control'] === $raw( StorageAuthority::CONTROL_OPTION ) && 'option' === $control()['backend'] && 'stable' === $control()['phase'], 'Not the exact completed owned reversal.' );
		J::check( null === $raw( StorageAuthority::MAINTENANCE_OPTION ) && null === $raw( StorageAuthority::AUTHORITY_OPTION ), 'A writer/authority remains; no assisted takeover.' );
		J::check( $journal['ids'] === get_post_meta( $journal['destination'], FOOGALLERY_META_ATTACHMENTS, true ) &&
			$journal['uid'] === get_post_meta( $journal['destination'], \FooPlugins\FooGalleryMigrate\Objects\Migratable::META_SOURCE_KEY, true ) &&
			get_post_meta( $journal['source'], J::POST_OWNER, true ) === J::OWNER, 'Fixture/output ownership changed.' );
		J::check( hash( 'sha256', $raw( StorageAuthority::STORAGE_BACKUP_OPTION ) ) === Native::RAW_SHA256, 'Retained backup changed; no cleanup.' );
		// Fixture teardown, NOT a claim of product uninstall/retirement acceptance.
		// No shared-table DDL. Initially every table was verified completely empty.
		$db = new LegacyTransactionConnection( $wpdb );
		J::check( false !== $db->query( 'START TRANSACTION' ), 'Cleanup transaction refused.' );
		try {
			$locked = $db->get_var( $db->prepare( 'SELECT option_value FROM `' . $wpdb->options . '` WHERE option_name=%s FOR UPDATE', array( StorageAuthority::CONTROL_OPTION ) ) );
			J::check( ! $db->last_error && $locked === $journal['stable_control'], 'Owned stable control changed before cleanup.' );
			foreach ( $tables as $table ) { J::check( false !== $db->query( $db->prepare( 'DELETE FROM `' . $table . '` WHERE blog_id=%d AND EXISTS (SELECT 1 FROM `' . $wpdb->options . '` WHERE option_name=%s AND BINARY option_value=BINARY %s)', array( $blog, StorageAuthority::CONTROL_OPTION, $journal['stable_control'] ) ) ), 'Owned row cleanup failed; preserve journal.' ); }
			foreach ( array( StorageAuthority::STORAGE_BACKUP_OPTION, StorageAuthority::CONTROL_OPTION ) as $name ) {
				$value = $db->get_var( $db->prepare( 'SELECT option_value FROM `' . $wpdb->options . '` WHERE option_name=%s FOR UPDATE', array( $name ) ) );
				J::check( ! $db->last_error && is_string( $value ) && ( StorageAuthority::CONTROL_OPTION === $name ? $value === $journal['stable_control'] : hash( 'sha256', $value ) === Native::RAW_SHA256 ), 'Owned marker changed; rollback cleanup.' );
				J::check( 1 === $db->query( $db->prepare( 'DELETE FROM `' . $wpdb->options . '` WHERE option_name=%s AND BINARY option_value=BINARY %s', array( $name, $value ) ) ), 'Exact owned marker cleanup failed.' );
			}
			J::check( false !== $db->query( 'COMMIT' ), 'Cleanup commit failed.' );
		} catch ( Exception $error ) { $db->query( 'ROLLBACK' ); throw $error; }
		catch ( Throwable $error ) { $db->query( 'ROLLBACK' ); throw $error; }
		foreach ( $journal['ids'] as $id ) { J::check( J::OWNER === get_post_meta( $id, J::POST_OWNER, true ) && wp_delete_attachment( $id, true ), 'Owned attachment cleanup failed.' ); }
		J::check( wp_delete_post( $journal['source'], true ) && wp_delete_post( $journal['destination'], true ), 'Owned source/destination cleanup failed.' );
		J::check( is_dir( $journal['media_dir'] ) && ! is_link( $journal['media_dir'] ) && rmdir( $journal['media_dir'] ), 'Owned media directory is not empty.' );
		update_option( FOOGALLERY_MIGRATE_OPTION_DATA, Native::value(), false );
		if ( null === $journal['saved_settings'] ) { delete_option( FOOGALLERY_MIGRATE_OPTION_SETTINGS ); } else { update_option( FOOGALLERY_MIGRATE_OPTION_SETTINGS, $journal['saved_settings'], false ); }
		J::check( hash( 'sha256', $raw( FOOGALLERY_MIGRATE_OPTION_DATA ) ) === Native::RAW_SHA256, 'Exact original fixture restoration failed.' );
		foreach ( $tables as $table ) { J::check( '0' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM `' . $table . '` WHERE blog_id=%d', $blog ) ) && ! $wpdb->last_error, 'Owned rows remain.' ); }
		delete_option( J::JOURNAL ); J::check( null === get_option( J::JOURNAL, null ), 'Journal cleanup failed.' );
	}
}
$after = get_option( J::JOURNAL, null );
echo json_encode( array( 'step' => $step, 'php' => PHP_VERSION, 'implementation_digest' => $expected_digest, 'result' => $result,
	'native_conversion_images' => J::NATIVE_IMAGES, 'actual_aigpl_images' => J::REAL_IMAGES,
	'progress' => is_array( $after ) ? array( 'phase' => $after['phase'], 'fixture_attachments' => count( $after['ids'] ), 'turns' => $after['turns'] ) : null,
	'scope' => 'fresh CLI step; NOT web-SAPI or feature acceptance; other original workloads remain open' ) ) . "\n";
