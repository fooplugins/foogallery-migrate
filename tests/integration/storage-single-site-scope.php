<?php
/** Narrowed release scope through real WP/SQL and the public receiver (PHP 5.4). */
// Documented WP-CLI fixture interface, restricted to this invocation's databases.
if ( defined( 'WP_CLI' ) && WP_CLI && isset( $args ) && array( 't_fc1db765' ) === $args &&
	defined( 'DB_NAME' ) && in_array( DB_NAME, array( 'cove_fgm_narrow_tfc1db765_', 'cove_fgm_narrow_multi_tfc1db765_' ), true ) ) {
	define( 'FOOGM_STORAGE_TEST_DISPOSABLE', true );
	define( 'FOOGM_STORAGE_TEST_EXPECTED_DATABASE', DB_NAME );
}
if ( ! defined( 'ABSPATH' ) || ! defined( 'FOOGM_STORAGE_TEST_DISPOSABLE' ) || true !== FOOGM_STORAGE_TEST_DISPOSABLE ||
	! defined( 'FOOGM_STORAGE_TEST_EXPECTED_DATABASE' ) ) { throw new RuntimeException( 'Explicit owned disposable database required.' ); }

use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\StorageAdmin;
use FooPlugins\FooGalleryMigrate\StorageCli;
use FooPlugins\FooGalleryMigrate\StorageOperations;
use FooPlugins\FooGalleryMigrate\MigratorEngine;

global $wpdb;
if ( FOOGM_STORAGE_TEST_EXPECTED_DATABASE !== $wpdb->get_var( 'SELECT DATABASE()' ) || $wpdb->last_error ) {
	throw new RuntimeException( 'Disposable database identity mismatch.' );
}
wp_set_current_user( 1 );
$checks = array();
$check = function ( $ok, $label ) use ( &$checks ) {
	if ( ! $ok ) { throw new RuntimeException( 'FAIL: ' . $label ); }
	$checks[] = $label;
};
$check( current_user_can( 'manage_options' ), 'real site administrator' );
$snapshot = function () use ( $wpdb ) {
	$rows = $wpdb->get_results( "SELECT option_name, HEX(option_value) AS bytes, autoload FROM `" . $wpdb->options . "` WHERE option_name LIKE 'foogallery%migrate%' ORDER BY option_name", ARRAY_A );
	if ( $wpdb->last_error ) { throw new RuntimeException( 'Snapshot read failed.' ); }
	$tables = $wpdb->get_col( 'SHOW TABLES' );
	if ( $wpdb->last_error ) { throw new RuntimeException( 'Table inventory failed.' ); }
	return serialize( array( $rows, $tables ) );
};
$before = $snapshot(); $status = StorageCli::execute( 'status', array() );
$check( is_array( $status ) && 'option' === $status['backend'], 'untouched installation defaults to options' );
$schema_ready = 'verified' === $status['schema'];
ob_start(); StorageAdmin::render(); $html = ob_get_clean();
$check( $before === $snapshot(), 'status and admin page are read-only including schema' );

if ( is_multisite() ) {
	$check( current_user_can( 'manage_network_options' ), 'even a real network administrator cannot enable tables' );
	$check( false === strpos( $html, '<form' ) && false !== strpos( $html, 'Multisite continues to use options' ), 'multisite UI offers explanation without table mutations' );
	foreach ( array( 'provision' => 'provision-shared-schema', 'preflight' => 'switch-to-table', 'switch' => 'switch-to-table',
		'recover' => 'recover-storage', 'replace' => 'replace-retained-with-table', 'discard' => 'discard-copy' ) as $operation => $confirmation ) {
		$result = StorageCli::execute( $operation, array( 'target' => 'table', 'confirm' => $confirmation ) );
		$check( is_wp_error( $result ) && 'foogallery_migrate_storage_single_site_required' === $result->get_error_code(), 'multisite CLI refuses ' . $operation );
		$purpose = 'provision' === $operation ? 'provision_schema' : ( 'recover' === $operation ? 'recover' : ( 'replace' === $operation ? 'option_reimport' : ( 'discard' === $operation ? 'discard' : 'option_import' ) ) );
		$request = array( 'blog_id' => get_current_blog_id(), 'scope' => 'provision' === $operation ? 'installation' : 'site', 'target' => 'table',
			'confirmation' => $confirmation, 'nonce' => wp_create_nonce( 'foogallery_migrate_storage_' . $purpose . '_' . get_current_blog_id() ) );
		$result = ( new StorageOperations() )->dispatch( $operation, $request );
		$check( is_wp_error( $result ) && 'foogallery_migrate_storage_single_site_required' === $result->get_error_code(), 'multisite receiver refuses ' . $operation );
		$check( $before === $snapshot(), 'denied ' . $operation . ' preserves exact storage bytes and schema' );
	}
} else {
	$check( $schema_ready === ( false === strpos( $html, 'provision-shared-schema' ) ), 'single-site UI offers schema provisioning only while not verified' );
	$result = StorageCli::execute( 'provision', array( 'target' => 'table', 'confirm' => 'provision-shared-schema' ) );
	$check( is_array( $result ) && 'verified' === $result['schema'] && 'option' === $result['backend'], 'public CLI provisions real InnoDB schema without selecting tables' );
	$status = StorageCli::execute( 'status', array() );
	$check( 'verified' === $status['schema'] && 'option' === $status['backend'] && 'stable' === $status['phase'], 'fresh status verifies owned schema and unchanged authority' );
	$before = $snapshot();
	$result = StorageCli::execute( 'switch', array( 'target' => 'table', 'confirm' => 'switch-to-table' ) );
	$check( is_wp_error( $result ), 'unqualified release policy refuses public conversion' );
	$check( $before === $snapshot(), 'capacity refusal preserves exact storage bytes and schema' );
}

// Real production engine, not an injected backend; this is option-path coverage,
// NOT a claim of migration output, table enablement or the large customer journey.
$engine = new MigratorEngine();
$engine->set_migrator_setting( 'narrowed-scope-probe', array( 'text' => '雪 native text', 'typed' => array( '09' => null, 9 => false ) ) );
$expected = array( 'text' => '雪 native text', 'typed' => array( '09' => null, 9 => false ) );
$check( $expected === $engine->get_migrator_setting( 'narrowed-scope-probe' ), 'production engine still writes and reads ordinary option state' );
$check( null === $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `' . $wpdb->options . '` WHERE option_name=%s', StorageAuthority::CONTROL_OPTION ) ) && ! $wpdb->last_error, 'ordinary engine writes never select table authority' );

echo json_encode( array( 'php' => PHP_VERSION, 'wordpress' => $GLOBALS['wp_version'], 'multisite' => is_multisite(),
	'checks' => count( $checks ), 'labels' => $checks, 'large_table_journey_accepted' => false ) ) . "\n";
