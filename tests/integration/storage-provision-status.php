<?php
/** Focused real WordPress/database provisioning; no conversion or calibration. */
if ( ! defined( 'ABSPATH' ) || ! getenv( 'FOOGM_PROVISION_OWNED_ROOT' ) || realpath( ABSPATH ) !== realpath( getenv( 'FOOGM_PROVISION_OWNED_ROOT' ) ) || DB_NAME !== getenv( 'FOOGM_PROVISION_OWNED_DATABASE' ) ) { throw new RuntimeException( 'Exact owned disposable root/database required.' ); }
use FooPlugins\FooGalleryMigrate\StorageAdmin;
use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\StorageOperations;
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
global $wpdb;
$schema = new LegacyStorageSchema( $wpdb ); $tables = array_keys( $schema->definitions() );
$main = is_multisite() ? get_main_site_id( get_main_network_id() ) : get_current_blog_id();
$options = $wpdb->get_blog_prefix( $main ) . 'options';
$metadata = array( LegacyStorageSchema::VERSION_OPTION, LegacyStorageSchema::LOCK_OPTION );
$check = function ( $ok, $label ) use ( &$checks ) { if ( ! $ok ) { throw new RuntimeException( $label ); } $checks[] = $label; };
$checks = array();
// Content/DDL equality, ignoring only InnoDB's non-transactional ID allocator.
$snapshot = function () use ( $wpdb ) {
 $result = array();
 foreach ( $wpdb->get_col( 'SHOW TABLES' ) as $table ) {
  if ( ! preg_match( '/^[a-zA-Z0-9_]+$/D', $table ) ) { throw new RuntimeException( 'Unexpected identifier.' ); }
  $ddl = $wpdb->get_row( 'SHOW CREATE TABLE `' . $table . '`', ARRAY_N );
  $rows = array(); foreach ( $wpdb->get_results( 'SELECT * FROM `' . $table . '`', ARRAY_A ) as $row ) { $rows[] = hash( 'sha256', serialize( $row ) ); }
  sort( $rows ); $result[$table] = array( preg_replace( '/ AUTO_INCREMENT=[0-9]+/', '', $ddl[1] ), $rows );
 }
 ksort( $result ); return $result;
};
if ( isset( $args[0] ) && 'snapshot' === $args[0] ) { echo wp_json_encode( $snapshot() ); return; }
foreach ( $tables as $table ) { if ( null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) { throw new RuntimeException( 'Refusing pre-existing fixture tables.' ); } }
foreach ( $metadata as $name ) { if ( null !== $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM `' . $options . '` WHERE option_name=%s', $name ) ) ) { throw new RuntimeException( 'Refusing pre-existing metadata.' ); } }
$baseline = $snapshot(); $request = array( 'blog_id' => (string) get_current_blog_id(), 'scope' => 'installation', 'target' => 'table', 'confirmation' => 'provision-shared-schema', 'nonce' => wp_create_nonce( 'foogallery_migrate_storage_provision_schema_' . get_current_blog_id() ) );
$receiver = new StorageOperations(); $original_user = get_current_user_id(); $error = null;
try {
 $status = StorageAdmin::status();
 $check( 'option' === $status['backend'] && 'stable' === $status['phase'] && $baseline === $snapshot(), 'default bootstrap/status leaves all database content and DDL unchanged' );
 foreach ( array( 'nonce' => 'wrong', 'confirmation' => '', 'scope' => 'site', 'blog_id' => (string) ( get_current_blog_id() + 1 ), 'extra' => 'forbidden' ) as $key => $value ) {
  $r = $request; $r[$key] = $value; $before = $snapshot();
  $check( is_wp_error( $receiver->dispatch( 'provision', $r ) ) && $before === $snapshot(), 'denied ' . $key . ' leaves database unchanged' );
 }
 $r = $request; $r['nonce'] = wp_create_nonce( 'foogallery_migrate_storage_option_import_' . get_current_blog_id() );
 $check( is_wp_error( $receiver->dispatch( 'provision', $r ) ) && $baseline === $snapshot(), 'conversion nonce cannot authorize provisioning' );
 wp_set_current_user( 0 );
 $check( is_wp_error( $receiver->dispatch( 'provision', $request ) ) && $baseline === $snapshot(), 'unauthorized user leaves database unchanged' ); wp_set_current_user( $original_user );
 if ( is_multisite() ) {
  $site_admin = get_user_by( 'login', 'provisionadmin' );
  $check( $site_admin && ! is_super_admin( $site_admin->ID ), 'fixture has a real non-network site administrator' );
  wp_set_current_user( $site_admin->ID ); $r = $request; $r['nonce'] = wp_create_nonce( 'foogallery_migrate_storage_provision_schema_' . get_current_blog_id() );
  $check( current_user_can( 'manage_options' ) && is_wp_error( $receiver->dispatch( 'provision', $r ) ) && $baseline === $snapshot(), 'site administrator without installation capability cannot provision shared schema' ); wp_set_current_user( $original_user );
 }
 $result = $receiver->dispatch( 'provision', $request );
 $check( ! is_wp_error( $result ), 'valid explicit provisioning succeeds without a capacity profile or conversion: ' . ( is_wp_error( $result ) ? $result->get_error_code() : '' ) );
 $check( true === $schema->verify(), 'only fixed shared InnoDB columns and indexes verified' );
 $status = StorageAdmin::status();
 $check( 'option' === $status['backend'] && 'stable' === $status['phase'] && 'verified' === $status['schema'] && '0' === (string) $status['records'] && ! $status['calibrated'], 'usable status reports verified schema and unchanged option authority without calibration' );
 $after = $snapshot(); $check( count( $after ) === count( $baseline ) + 2 && ! array_diff( array_keys( $after ), array_merge( array_keys( $baseline ), $tables ) ), 'only the two fixed shared tables were created' );
 foreach ( $baseline as $table => $value ) {
  if ( $table === $options ) { continue; }
  $check( $after[$table] === $value, 'unrelated/cross-blog table unchanged: ' . $table );
 }
 foreach ( array( StorageAuthority::CONTROL_OPTION, StorageAuthority::AUTHORITY_OPTION, StorageAuthority::MAINTENANCE_OPTION, StorageAuthority::STORAGE_BACKUP_OPTION ) as $name ) { $check( null === $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM `' . $wpdb->prefix . 'options` WHERE option_name=%s', $name ) ), 'no new authority/retained marker: ' . $name ); }
 if ( is_multisite() ) {
  $other = get_sites( array( 'site__not_in' => array( get_current_blog_id() ), 'number' => 1, 'fields' => 'ids' ) );
  $check( 1 === count( $other ), 'a second real blog is available for shared-row isolation' );
  foreach ( $schema->definitions() as $table => $definition ) {
   $row = array();
   foreach ( $definition['columns'] as $column => $spec ) {
    if ( ! empty( $spec[2] ) ) { continue; }
    $row[$column] = $spec[1] ? null : ( false !== strpos( $spec[0], 'int' ) ? 0 : ( 'datetime' === $spec[0] ? '2026-10-16 00:00:00' : ( 'binary(32)' === $spec[0] ? str_repeat( 'x', 32 ) : '{}' ) ) );
   }
   $row['blog_id'] = $other[0];
   $check( 1 === $wpdb->insert( $table, $row ), 'owned synthetic other-blog sentinel inserted: ' . $table );
  }
  $status = StorageAdmin::status(); $check( 'option' === $status['backend'] && '0' === (string) $status['records'], 'status excludes other-blog shared records and preserves current option authority' );
 }
 $before = $snapshot(); $check( ! is_wp_error( $receiver->dispatch( 'provision', $request ) ) && $before === $snapshot(), 'explicit re-provision is content/DDL-idempotent' );
 // Without installation ownership metadata, even structurally exact tables are foreign.
 $wpdb->delete( $options, array( 'option_name' => LegacyStorageSchema::VERSION_OPTION ) );
 $before = $snapshot(); $check( is_wp_error( $receiver->dispatch( 'provision', $request ) ) && $before === $snapshot(), 'unowned exact-shape tables are refused without adoption' );
 $status = StorageAdmin::status(); $check( 'verified' !== $status['schema'], 'status does not certify unowned exact-shape tables' );
 $wpdb->insert( $options, array( 'option_name' => LegacyStorageSchema::VERSION_OPTION, 'option_value' => '2', 'autoload' => 'no' ) );
 $before = $snapshot(); $check( is_wp_error( $receiver->dispatch( 'provision', $request ) ) && $before === $snapshot(), 'incompatible schema version is refused without modification' );
 $wpdb->delete( $options, array( 'option_name' => LegacyStorageSchema::VERSION_OPTION ) );
 $wpdb->insert( $options, array( 'option_name' => LegacyStorageSchema::VERSION_OPTION, 'option_value' => '1', 'autoload' => 'no' ) );
 $wpdb->query( 'ALTER TABLE `' . $tables[0] . '` MODIFY state_kind varchar(32) NOT NULL' );
 $before = $snapshot(); $check( is_wp_error( $receiver->dispatch( 'provision', $request ) ) && $before === $snapshot(), 'incompatible owned schema is refused without alteration' );
 $wpdb->query( 'DROP TABLE `' . $tables[1] . '`' );
 $before = $snapshot(); $check( is_wp_error( $receiver->dispatch( 'provision', $request ) ) && $before === $snapshot(), 'incompatible partial schema is refused before creating a missing table' );
} catch ( Throwable $failure ) { $error = $failure->getMessage(); }
finally {
 wp_set_current_user( $original_user );
 foreach ( array_reverse( $tables ) as $table ) { $wpdb->query( 'DROP TABLE IF EXISTS `' . $table . '`' ); }
 foreach ( $metadata as $name ) { $wpdb->delete( $options, array( 'option_name' => $name ) ); wp_cache_delete( $name, 'options' ); }
}
$clean = $baseline === $snapshot();
echo wp_json_encode( array( 'status' => null === $error && $clean ? 'pass' : 'fail', 'failure' => $error, 'checks' => $checks, 'check_count' => count( $checks ), 'exact_owned_cleanup' => $clean, 'root' => ABSPATH, 'database' => DB_NAME, 'prefix' => $wpdb->prefix, 'blog_id' => get_current_blog_id(), 'php' => PHP_VERSION, 'wordpress' => get_bloginfo( 'version' ), 'db_version' => $wpdb->get_var( 'SELECT VERSION()' ), 'calibration' => 'none; conversion not exercised' ) ) . "\n";
if ( null !== $error || ! $clean ) { WP_CLI::halt( 1 ); }
