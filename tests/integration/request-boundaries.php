<?php
/** Real WordPress request/nonce checks on an owned disposable site only. */
if ( ! defined( 'FOOGM_STORAGE_TEST_DISPOSABLE' ) || true !== FOOGM_STORAGE_TEST_DISPOSABLE ) { throw new RuntimeException( 'Owned fixture required.' ); }

use FooPlugins\FooGalleryMigrate\Init;
use FooPlugins\FooGalleryMigrate\StorageMaintenanceGate;
use FooPlugins\FooGalleryMigrate\StorageOperations;

if ( ! defined( 'DOING_AJAX' ) ) { define( 'DOING_AJAX', true ); }
class RequestBoundaryStop extends RuntimeException {}
class RequestBoundaryEngine {
	public $saved; public $queued;
	public function save_settings( $settings ) { $this->saved = $settings; }
	public function get_gallery_migrator() { return $this; }
	public function get_album_migrator() { return $this; }
	public function queue_objects_for_migration( $items ) { $this->queued = $items; return true; }
	public function render_gallery_form() { throw new RequestBoundaryStop( 'render' ); }
	public function render_album_form() { throw new RequestBoundaryStop( 'render' ); }
}

$checks = 0; $writes = array(); $nonces = 0;
$check = function ( $ok, $label ) use ( &$checks ) { ++$checks; if ( ! $ok ) { throw new RuntimeException( 'Request boundary: ' . $label ); } };
$die = function ( $message = '', $title = '', $args = array() ) { throw new RequestBoundaryStop( 'die' ); };
foreach ( array( 'wp_die_handler', 'wp_die_ajax_handler', 'wp_die_json_handler' ) as $hook ) {
	add_filter( $hook, function () use ( $die ) { return $die; } );
}
add_action( 'check_admin_referer', function () use ( &$nonces ) { ++$nonces; } );
$query_log = function ( $sql ) use ( &$writes ) {
	if ( preg_match( '/^\s*(INSERT|UPDATE|DELETE|DROP|CREATE|ALTER|REPLACE|TRUNCATE)\b/i', $sql ) ) { $writes[] = $sql; }
	return $sql;
};
// Session counters see the pinned mysqli transport too; WordPress's query
// filter alone would miss commands that deliberately bypass wpdb::query().
$mutation_counters = function () {
	global $wpdb; $counts = array();
	$rows = $wpdb->get_results( "SHOW SESSION STATUS WHERE Variable_name IN ('Com_insert','Com_insert_select','Com_update','Com_update_multi','Com_delete','Com_delete_multi','Com_replace','Com_replace_select','Com_create_table','Com_drop_table','Com_alter_table','Com_truncate','Com_begin','Com_commit','Com_rollback')", ARRAY_A );
	foreach ( $rows as $row ) { $counts[$row['Variable_name']] = $row['Value']; }
	ksort( $counts ); return $counts;
};
$check( 15 === count( $mutation_counters() ), 'all mutation/transaction counters available' );
$run = function ( $callback ) {
	$level = ob_get_level(); ob_start(); $stop = null;
	try { call_user_func( $callback ); }
	catch ( RequestBoundaryStop $error ) { $stop = $error->getMessage(); }
	catch ( Exception $error ) { while ( ob_get_level() > $level ) { ob_end_clean(); } throw $error; }
	catch ( Throwable $error ) { while ( ob_get_level() > $level ) { ob_end_clean(); } throw $error; }
	return array( $stop, ob_get_clean() );
};
set_error_handler( function ( $severity, $message, $file, $line ) {
	if ( error_reporting() & $severity ) { throw new ErrorException( $message, 0, $severity, $file, $line ); }
	return false;
} );
$admin_id = get_current_user_id(); $blog = get_current_blog_id();
$engine = new RequestBoundaryEngine();
$init = ( new ReflectionClass( Init::class ) )->newInstanceWithoutConstructor();
$root = FOOGM_DIR;
$seed = array( 'request-boundary-source' => 'keep' );

// Include the actual two cleanup handlers, including the query-only nonce case
// that check_admin_referer alone would accept. All denied cases must be silent
// at PHP's warning boundary and leave both storage and SQL writes untouched.
foreach ( array( 'view-migrate.php', 'view-migrate-tab-sources.php' ) as $view ) {
	foreach ( array( 'missing', 'query-only', 'null', 'array', 'integer', 'empty', 'invalid', 'wrong-site', 'unauthorized', 'valid' ) as $case ) {
		unset( $GLOBALS['foogallery_migrate_engine_instance'] );
		wp_set_current_user( 'unauthorized' === $case ? 0 : $admin_id );
		update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $seed, false );
		$nonce = wp_create_nonce( 'foogallery_migrate_storage_clear_' . $blog );
		$_GET = array( 'tab' => 'sources' ); $_POST = array( 'clear_migration_history' => '1' );
		if ( 'query-only' === $case ) { $_GET['foogallery_migrate_clear_nonce'] = $nonce; }
		elseif ( ! in_array( $case, array( 'missing', 'query-only' ), true ) ) {
			$values = array( 'null' => null, 'array' => array( $nonce ), 'integer' => 123, 'empty' => '', 'invalid' => 'invalid',
				'wrong-site' => wp_create_nonce( 'foogallery_migrate_storage_clear_' . ( $blog + 1 ) ), 'unauthorized' => $nonce, 'valid' => $nonce );
			$_POST['foogallery_migrate_clear_nonce'] = $values[$case];
		}
		$_REQUEST = array_merge( $_GET, $_POST ); $writes = array(); $nonces = 0;
		$before = $mutation_counters(); add_filter( 'query', $query_log );
		$result = $run( function () use ( $root, $view ) { require $root . '/includes/views/' . $view; } );
		remove_filter( 'query', $query_log );
		$check( ( 'valid' === $case ? null : 'die' ) === $result[0], $view . '/' . $case . ' terminates at the expected boundary' );
		$state = get_option( FOOGALLERY_MIGRATE_OPTION_DATA );
		$check( 'valid' === $case ? ( is_array( $state ) && ! array_key_exists( 'request-boundary-source', $state ) ) : $state === $seed, $view . '/' . $case . ' preserves or clears source state' );
		if ( 'valid' !== $case ) { $check( ! count( $writes ) && $before === $mutation_counters(), $view . '/' . $case . ' has no SQL mutation/transaction' ); }
		if ( in_array( $case, array( 'missing', 'query-only', 'null', 'array', 'integer' ), true ) ) {
			$check( 0 === $nonces, $view . '/' . $case . ' rejects POST shape before nonce handling' );
		}
		StorageMaintenanceGate::current()->shutdown_writer();
	}
}

// Every mutation controller that can reach the annotated title helper or form
// renderers must reject a bad nonce or a user lacking manage_options first.
$controllers = array(
	'save_settings' => 'foogallery_migrate_settings',
	'ajax_start_migration' => 'foogallery_migrate', 'ajax_continue_migration' => 'foogallery_migrate',
	'ajax_retry_gallery_migration' => 'foogallery_migrate', 'ajax_check_gallery_errors' => 'foogallery_migrate',
	'ajax_cancel_migration' => 'foogallery_migrate', 'ajax_refresh_migration' => 'foogallery_migrate',
	'ajax_start_album_migration' => 'foogallery_album_migrate', 'ajax_continue_album_migration' => 'foogallery_album_migrate',
	'ajax_cancel_album_migration' => 'foogallery_album_migrate', 'ajax_refresh_album_migration' => 'foogallery_album_migrate',
	'ajax_replace_content' => 'foogallery_content_migrate', 'ajax_refresh_content' => 'foogallery_content_migrate',
	'ajax_refresh_content_status' => 'foogallery_content_migrate', 'migrate_content_no_js' => 'foogallery_content_migrate',
	'refresh_content_no_js' => 'foogallery_content_migrate', 'refresh_content_status_no_js' => 'foogallery_content_migrate',
	'ajax_sync_image_tags' => 'foogallery_migrate_image_tags',
	'ajax_update_migrated_status' => 'foogallery_migrate_log', 'ajax_delete_migrated_object' => 'foogallery_migrate_log',
);
$GLOBALS['foogallery_migrate_engine_instance'] = $engine;
foreach ( $controllers as $method => $action ) {
	foreach ( array( 'bad-nonce', 'missing-nonce', 'unauthorized' ) as $case ) {
		wp_set_current_user( 'unauthorized' === $case ? 0 : $admin_id );
		$_POST = array( $action => 'unauthorized' === $case ? wp_create_nonce( $action ) : 'invalid' );
		if ( 'missing-nonce' === $case ) { $_POST = array(); }
		$_GET = array(); $_REQUEST = $_POST; $writes = array(); $engine->saved = null; $engine->queued = null;
		$before = $mutation_counters(); add_filter( 'query', $query_log ); $result = $run( array( $init, $method ) ); remove_filter( 'query', $query_log );
		$check( 'die' === $result[0], $method . '/' . $case . ' refuses' );
		$check( ! count( $writes ) && $before === $mutation_counters(), $method . '/' . $case . ' performs no SQL mutation/transaction' );
		$check( null === $engine->saved && null === $engine->queued, $method . '/' . $case . ' reaches no settings or queue sink' );
	}
}

// The public storage receiver verifies each delegated operation's purpose.
$operations = array(
	'preflight' => array( 'site', 'table', 'switch-to-table' ), 'switch' => array( 'site', 'table', 'switch-to-table' ),
	'recover' => array( 'site', 'table', 'recover-storage' ), 'replace' => array( 'site', 'table', 'replace-retained-with-table' ),
	'discard' => array( 'site', 'table', 'discard-copy' ), 'provision' => array( 'installation', 'table', 'provision-shared-schema' ),
	'clear' => array( 'site', 'state', 'clear-current-state' ), 'remove_all' => array( 'site', 'all', 'remove-all-internal-data' ),
);
foreach ( $operations as $operation => $fields ) {
	foreach ( array( 'bad-nonce', 'missing-nonce', 'unauthorized' ) as $case ) {
		wp_set_current_user( 'unauthorized' === $case ? 0 : $admin_id ); $_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = array( 'action' => 'foogallery_migrate_storage', 'operation' => $operation, 'blog_id' => $blog,
			'scope' => $fields[0], 'target' => $fields[1], 'confirmation' => $fields[2], 'nonce' => 'invalid' );
		if ( 'missing-nonce' === $case ) { $_POST['nonce'] = ''; }
		$_REQUEST = $_POST; $writes = array();
		$before = $mutation_counters(); add_filter( 'query', $query_log ); $result = $run( array( StorageOperations::class, 'handle' ) ); remove_filter( 'query', $query_log );
		$data = json_decode( $result[1], true );
		$code = 'foogallery_migrate_storage_' . ( 'unauthorized' === $case ? 'forbidden' : 'invalid_nonce' );
		$check( 'die' === $result[0] && false === $data['success'] && $code === $data['data']['code'], $operation . '/' . $case . ' verifies delegated authorization' );
		$check( ! count( $writes ) && $before === $mutation_counters(), $operation . '/' . $case . ' performs no SQL mutation/transaction' );
	}
}

// Successful authenticated title processing: real unslash/sanitization, literal
// and PHP-normalized field names, and safe rejection of array-shaped titles.
wp_set_current_user( $admin_id );
foreach ( array( 'gallery', 'album' ) as $kind ) {
	foreach ( array( 'literal', 'normalized', 'array' ) as $shape ) {
		$id = 'Source Plugin.7'; $prefix = 'album' === $kind ? 'foogallery-album-title-' : 'foogallery-title-';
		$action = 'album' === $kind ? 'foogallery_album_migrate' : 'foogallery_migrate';
		$field = $prefix . $id; if ( 'normalized' === $shape ) { $field = str_replace( array( ' ', '.' ), '_', $field ); }
		$title = "O'Brien \\ Path <em>title</em>";
		$_POST = array( $action => wp_create_nonce( $action ), $kind . '-id' => array( $id ), $field => 'array' === $shape ? array( 'bad' ) : wp_slash( $title ) );
		$_REQUEST = $_POST; $engine->queued = null;
		$result = $run( array( $init, 'album' === $kind ? 'ajax_start_album_migration' : 'ajax_start_migration' ) );
		$check( 'render' === $result[0], $kind . '/' . $shape . ' reaches rendering after authorization' );
		$check( ( 'array' === $shape ? '' : "O'Brien \\ Path title" ) === $engine->queued[$id]['title'], $kind . '/' . $shape . ' title is unslashed exactly once and sanitized' );
		StorageMaintenanceGate::current()->shutdown_writer();
	}
}

$redirect = function () { throw new RequestBoundaryStop( 'redirect' ); };
add_filter( 'wp_redirect', $redirect );
foreach ( array( 'scalars', 'arrays' ) as $shape ) {
	$_POST = array( 'foogallery_migrate_settings' => wp_create_nonce( 'foogallery_migrate_settings' ),
		'override_gallery_layout' => 'MASONRY! foo', 'override_gallery_settings' => '-23', 'override_album_settings' => '42', 'page_size' => '-50', 'images_per_turn' => '9' );
	if ( 'arrays' === $shape ) { foreach ( array( 'override_gallery_layout', 'override_gallery_settings', 'override_album_settings', 'page_size', 'images_per_turn' ) as $key ) { $_POST[$key] = array( 'bad' ); } }
	$_REQUEST = $_POST; $engine->saved = null; $result = $run( array( $init, 'save_settings' ) );
	$check( 'redirect' === $result[0], 'settings/' . $shape . ' completes' );
	$expected = 'arrays' === $shape ? array( '', 0, 0, 20, 5, false ) : array( 'masonryfoo', 23, 42, 50, 9, false );
	$check( array_values( $engine->saved ) === $expected, 'settings/' . $shape . ' normalizes values or keeps defaults' );
	StorageMaintenanceGate::current()->shutdown_writer();
}
remove_filter( 'wp_redirect', $redirect ); restore_error_handler();
echo wp_json_encode( array( 'status' => 'pass', 'checks' => $checks, 'controllers' => count( $controllers ), 'storage_operations' => count( $operations ),
	'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION,
	'scope' => 'real WordPress nonce/capability/sanitization and owned SQL; synthetic destination engine, no gallery/media migration' ) ) . "\n";
