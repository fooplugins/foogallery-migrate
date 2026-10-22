<?php
/** Bounded release-policy probe; only the retained task-owned Linux fixtures. PHP 5.4. */
if ( 'cli' !== PHP_SAPI || 3 !== $argc || ! in_array( $argv[1], array( '54', '56' ), true ) ||
	! in_array( $argv[2], array( 'facts', 'measure', 'seed', 'preflight', 'switch', 'recover', 'status' ), true ) ) {
	throw new RuntimeException( 'Explicit owned fixture and operation required.' );
}
$tag = $argv[1]; $operation = $argv[2];
$versions = array( '54' => array( '5.4.0', '5.0' ), '56' => array( '5.6.20', '6.0' ) );
if ( PHP_VERSION !== $versions[$tag][0] ) { throw new RuntimeException( 'Literal runtime mismatch.' ); }
require '/srv/fgm-narrow-tfc1db765/wp' . $tag . '/wp-load.php';
use FooPlugins\FooGalleryMigrate\StorageCapacityPolicy;
use FooPlugins\FooGalleryMigrate\StorageCli;
use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\StorageScalarObserver;
use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
global $wpdb;
$expected_database = 'fgm_narrow_tfc1db765_' . $tag;
if ( $expected_database !== $wpdb->get_var( 'SELECT DATABASE()' ) || $wpdb->last_error || is_multisite() || $GLOBALS['wp_version'] !== $versions[$tag][1] ) {
	throw new RuntimeException( 'Owned single-site database required.' );
}
wp_set_current_user( 1 );
if ( ! current_user_can( 'manage_options' ) ) { throw new RuntimeException( 'Real administrator required.' ); }
require_once __DIR__ . '/../support/StorageReleaseNativeFixture.php';
$receipt = array( 'operation' => $operation, 'php' => PHP_VERSION, 'wordpress' => $GLOBALS['wp_version'],
	'source_digest' => StorageCapacityPolicy::implementation_digest(), 'feature_accepted' => false );
if ( 'facts' === $operation ) {
	$receipt['facts'] = StorageCapacityPolicy::facts();
} elseif ( 'measure' === $operation || 'seed' === $operation ) {
	$value = \FooPlugins\FooGalleryMigrate\Tests\StorageReleaseNativeFixture::value();
	$raw = serialize( $value );
	$receipt['fixture'] = array( 'name' => 'a7-options-native-php5-20k-v1', 'native_text_not_binary_equivalence' => true,
		'bytes' => strlen( $raw ), 'sha256' => hash( 'sha256', $raw ), 'immediate_images' => 20000 );
	if ( 'seed' === $operation ) {
		if ( null !== $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `' . $wpdb->options . '` WHERE option_name=%s', StorageAuthority::CONTROL_OPTION ) ) || $wpdb->last_error ) {
			throw new RuntimeException( 'Do not reseed a conversion or table authority.' );
		}
		update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $value, false );
		$stored = $wpdb->get_row( $wpdb->prepare( 'SELECT LENGTH(option_value) AS bytes, SHA2(option_value,256) AS digest FROM `' . $wpdb->options . '` WHERE option_name=%s', FOOGALLERY_MIGRATE_OPTION_DATA ), ARRAY_A );
		if ( $wpdb->last_error || (string) strlen( $raw ) !== (string) $stored['bytes'] || hash( 'sha256', $raw ) !== $stored['digest'] || serialize( get_option( FOOGALLERY_MIGRATE_OPTION_DATA ) ) !== $raw ) {
			throw new RuntimeException( 'Exact native-text SQL and WordPress seed mismatch.' );
		}
		$receipt['seed_verified'] = true;
	} else {
		// Observations only, not an injected production profile or allocator proof.
		unset( $value ); $before = memory_get_usage( false ); $allocated = memory_get_usage( true );
		$start = microtime( true ); $decoded = unserialize( $raw );
		$receipt['native_decode'] = array( 'live_delta' => memory_get_usage( false ) - $before,
			'allocated_delta' => memory_get_usage( true ) - $allocated, 'seconds' => microtime( true ) - $start );
		$start = microtime( true ); $roundtrip = serialize( $decoded );
		if ( $roundtrip !== $raw ) { throw new RuntimeException( 'Native roundtrip mismatch.' ); }
		$receipt['native_serialize'] = array( 'live_delta_with_graph' => memory_get_usage( false ) - $before,
			'seconds' => microtime( true ) - $start );
		$receipt['logical_sha256'] = ( new LegacyStateCodec() )->snapshot_digest( $decoded );
		$receipt['process_peak_allocated'] = memory_get_peak_usage( true );
		$receipt['process_peak_live'] = memory_get_peak_usage( false );
		$receipt['rusage'] = getrusage();
	}
} else {
	$raw_before = $wpdb->get_var( $wpdb->prepare( 'SELECT SHA2(option_value,256) FROM `' . $wpdb->options . '` WHERE option_name=%s', FOOGALLERY_MIGRATE_OPTION_DATA ) );
	$control_sql = $wpdb->prepare( 'SELECT option_value FROM `' . $wpdb->options . '` WHERE option_name=%s', StorageAuthority::CONTROL_OPTION );
	$control_before = $wpdb->get_var( $control_sql );
	if ( $wpdb->last_error || null !== $control_before || $raw_before !== \FooPlugins\FooGalleryMigrate\Tests\StorageReleaseNativeFixture::RAW_SHA256 ) {
		throw new RuntimeException( 'Exact native corpus with untouched options authority required.' );
	}
	// Existing scalar-only acquisition seam; never changes admission/control flow.
	$armed = new ReflectionProperty( 'FooPlugins\\FooGalleryMigrate\\StorageScalarObserver', 'milestones_enabled' );
	$armed->setAccessible( true ); $armed->setValue( null, true );
	$start = microtime( true );
	$result = StorageCli::execute( $operation, 'status' === $operation ? array() : array( 'target' => 'table', 'confirm' => 'recover' === $operation ? 'recover-storage' : 'switch-to-table' ) );
	$receipt['seconds'] = microtime( true ) - $start;
	$receipt['result'] = is_wp_error( $result ) ? array( 'error' => $result->get_error_code(), 'message' => $result->get_error_message() ) : $result;
	$receipt['raw_preserved'] = $raw_before === $wpdb->get_var( $wpdb->prepare( 'SELECT SHA2(option_value,256) FROM `' . $wpdb->options . '` WHERE option_name=%s', FOOGALLERY_MIGRATE_OPTION_DATA ) );
	if ( $wpdb->last_error ) { throw new RuntimeException( 'Raw preservation observation failed.' ); }
	$receipt['control_preserved'] = $control_before === $wpdb->get_var( $control_sql );
	if ( $wpdb->last_error || ( is_wp_error( $result ) && ( ! $receipt['raw_preserved'] || ! $receipt['control_preserved'] ) ) ) {
		throw new RuntimeException( 'Refusal changed options or the authority control record.' );
	}
	foreach ( array( 'milestones', 'refusal' ) as $property ) {
		$p = new ReflectionProperty( 'FooPlugins\\FooGalleryMigrate\\StorageScalarObserver', $property ); $p->setAccessible( true );
		$receipt[$property] = $p->getValue();
	}
	$receipt['process_peak_allocated'] = memory_get_peak_usage( true );
	$receipt['rusage'] = getrusage();
	echo json_encode( $receipt ) . "\n";
	if ( is_wp_error( $result ) ) { exit( 1 ); }
	exit( 0 );
}
echo json_encode( $receipt ) . "\n";
