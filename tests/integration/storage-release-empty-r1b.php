<?php
/** Owned real-SQL empty-option forward journey, including absent vs serialized []. */
if ( 'cli' !== PHP_SAPI || 4 !== $argc || ! in_array( $argv[2], array( 'absent', 'present' ), true ) ||
	! in_array( $argv[3], array( 'init', 'preflight', 'provision', 'switch', 'recover', 'status' ), true ) ) {
	throw new RuntimeException( 'Owned site, empty-source mode and explicit step are required.' );
}
$site = realpath( $argv[1] );
if ( false === $site || ! preg_match( '~^/(?:private/)?tmp/foogallery-migrate-r0\.[A-Za-z0-9]+$~D', $site ) ) { throw new RuntimeException( 'Not an owned temporary site.' ); }
if ( ! is_file( $site . '/.fgm-r0-owner.json' ) ) { throw new RuntimeException( 'Owned site manifest is missing.' ); }
$manifest = json_decode( file_get_contents( $site . '/.fgm-r0-owner.json' ), true );
if ( ! is_array( $manifest ) || ! isset( $manifest['kind'], $manifest['site'], $manifest['database'], $manifest['host'], $manifest['repo'], $manifest['container'], $manifest['container_id'] ) ||
	'foogallery-migrate-r0-v1' !== $manifest['kind'] || realpath( $manifest['site'] ) !== $site ||
	! preg_match( '/^fgm_r0_[a-z0-9]+$/D', $manifest['database'] ) ||
	! preg_match( '/^127\.0\.0\.1:[0-9]{2,5}$/D', $manifest['host'] ) ||
	! preg_match( '/^fgm-r0-[A-Za-z0-9]+$/D', $manifest['container'] ) ||
	! preg_match( '/^[a-f0-9]{64}$/D', $manifest['container_id'] ) ||
	realpath( dirname( __DIR__, 2 ) ) !== realpath( $manifest['repo'] ) ||
	realpath( $site . '/wp-content/plugins/foogallery-migrate' ) !== realpath( $manifest['repo'] ) ) { throw new RuntimeException( 'Owned site manifest mismatch.' ); }
$container_check = array(); $container_code = 0;
exec( 'docker inspect --format ' . escapeshellarg( '{{.Id}} {{index .Config.Labels "foogallery-migrate-r0"}}' ) . ' ' .
	escapeshellarg( $manifest['container'] ) . ' 2>/dev/null', $container_check, $container_code );
if ( 0 !== $container_code || count( $container_check ) !== 1 ||
	trim( $container_check[0] ) !== $manifest['container_id'] . ' owned' ) { throw new RuntimeException( 'Owned SQL container identity mismatch.' ); }
require $site . '/wp-load.php';
require_once dirname( __DIR__ ) . '/support/StorageReleaseJourney.php';
use FooPlugins\FooGalleryMigrate\Tests\StorageReleaseJourney as Journey;
use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\StorageControl;
use FooPlugins\FooGalleryMigrate\StorageAdmin;
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
global $wpdb;
Journey::check( ! is_multisite() && DB_NAME === $manifest['database'] && DB_HOST === $manifest['host'] &&
	$wpdb->get_var( 'SELECT DATABASE()' ) === $manifest['database'] && ! $wpdb->last_error, 'Owned SQL connection mismatch.' );
Journey::check( class_exists( 'FooPlugins\\FooGalleryMigrate\\StorageCli' ) && function_exists( 'foogallery_migrate_migrator_instance' ), 'Production plugins inactive.' );
wp_set_current_user( 1 ); Journey::check( current_user_can( 'manage_options' ), 'Owned admin unavailable.' );
$mode = $argv[2]; $step = $argv[3]; $journal_name = 'fgm_r1b_owned_empty_journey_v1';
$raw = function ( $name ) use ( $wpdb ) {
	$value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `' . $wpdb->options . '` WHERE option_name=%s', $name ) );
	Journey::check( ! $wpdb->last_error, 'Owned option read failed.' ); return $value;
};
$expected = array( 'owner' => hash( 'sha256', $site . '|' . $manifest['database'] . '|' . $manifest['container_id'] ), 'mode' => $mode );
$old_expected = array( 'owner' => hash( 'sha256', $site . '|' . $manifest['database'] ), 'mode' => $mode );
if ( 'init' === $step ) {
	Journey::check( null === get_option( $journal_name, null ) && null === $raw( FOOGALLERY_MIGRATE_OPTION_DATA ), 'Existing source/journal; refusing empty reseed.' );
	foreach ( array( StorageAuthority::CONTROL_OPTION, StorageAuthority::AUTHORITY_OPTION, StorageAuthority::MAINTENANCE_OPTION, StorageAuthority::STORAGE_BACKUP_OPTION, LegacyStorageSchema::VERSION_OPTION, LegacyStorageSchema::LOCK_OPTION ) as $name ) {
		Journey::check( null === $raw( $name ), 'Storage evidence already present.' );
	}
	foreach ( array_keys( ( new LegacyStorageSchema( $wpdb ) )->definitions() ) as $table ) {
		Journey::check( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) &&
			! $wpdb->last_error, 'Storage table/remnant already exists before init.' );
	}
	if ( 'present' === $mode ) {
		Journey::check( update_option( FOOGALLERY_MIGRATE_OPTION_DATA, array(), false ) &&
			'a:0:{}' === $raw( FOOGALLERY_MIGRATE_OPTION_DATA ), 'Exact serialized empty source seed failed.' );
	}
	Journey::check( add_option( $journal_name, $expected, '', false ), 'Owned empty journey marker failed.' );
}
$journal = get_option( $journal_name, null );
Journey::check( $journal === $expected || ( 'status' === $step && $journal === $old_expected ), 'Owned empty journey identity mismatch.' );
$result = array( 'step' => $step, 'mode' => $mode, 'site' => $site, 'database' => $manifest['database'], 'host' => $manifest['host'] );
try {
	if ( 'preflight' === $step ) { $result['result'] = Journey::preflight(); }
	if ( in_array( $step, array( 'provision', 'switch', 'recover' ), true ) ) {
		$result['result'] = Journey::request( $step, 'table' );
	}
} catch ( RuntimeException $error ) { $result['failure'] = $error->getMessage(); }
$result['status'] = StorageAdmin::status();
$result['source_raw'] = $raw( FOOGALLERY_MIGRATE_OPTION_DATA );
$result['backup_raw'] = $raw( StorageAuthority::STORAGE_BACKUP_OPTION );
$control = $raw( StorageAuthority::CONTROL_OPTION );
if ( null !== $control ) {
	$record = StorageControl::decode( $control, get_current_blog_id() );
	$result['control_phase'] = $record['phase'];
	if ( isset( $record['verify_cursor'] ) ) { $result['verify_cursor'] = $record['verify_cursor']; }
}
if ( 'stable' === $result['status']['phase'] && 'table' === $result['status']['backend'] ) {
	Journey::check( null === $result['source_raw'] && 'a:0:{}' === $result['backup_raw'] &&
		( new LegacyStorageSchema( $wpdb ) )->verify(), 'Stable empty authority/backup/schema mismatch.' );
	$state_table = $wpdb->base_prefix . StorageAuthority::SHARED_STATE_TABLE_SUFFIX;
	$record_table = $wpdb->base_prefix . StorageAuthority::SHARED_RECORDS_TABLE_SUFFIX;
	Journey::check( '0' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM `' . $state_table . '` WHERE blog_id=%d', get_current_blog_id() ) ) &&
		'0' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM `' . $record_table . '` WHERE blog_id=%d', get_current_blog_id() ) ), 'Empty table authority has rows.' );
}
echo json_encode( $result, JSON_UNESCAPED_SLASHES ) . "\n";
exit( isset( $result['failure'] ) ? 1 : 0 );
