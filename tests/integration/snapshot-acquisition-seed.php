<?php
/** ONE finite native pre-root/page/reconstruction test. Never a profile supplier.
 * Run in an invocation-owned disposable WP database, with an exact product root.
 */
if ( ! defined( 'ABSPATH' ) || ! defined( 'FOOGM_SEED_ROOT' ) || ! defined( 'FOOGM_SEED_OUT' ) ||
    ! defined( 'FOOGM_SEED_CELL' ) || ! defined( 'FOOGM_SEED_DB' ) || DB_NAME !== FOOGM_SEED_DB ||
    'cove_fgm_seed_20261019_34032_' !== DB_NAME || PHP_SAPI !== 'cli' ) { throw new RuntimeException( 'Owned native seed fixture required.' ); }
require_once FOOGM_SEED_ROOT . '/migrate.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once dirname( __DIR__ ) . '/support/SnapshotAcquisitionSeed.php';

use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use FooPlugins\FooGalleryMigrate\TableSnapshot;
use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;
use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use FooPlugins\FooGalleryMigrate\StorageScaleAdmission;
use FooPlugins\FooGalleryMigrate\StorageScalarObserver;
use FooPlugins\FooGalleryMigrate\SnapshotReadTraceStream;
use FooPlugins\FooGalleryMigrate\Tests\SnapshotAcquisitionSeed;

$set = function ( $object, $field, $value ) {
    $r = new ReflectionProperty( $object, $field ); if ( PHP_VERSION_ID < 80100 ) { $r->setAccessible( true ); } $r->setValue( is_object( $object ) ? $object : null, $value );
};
$get = function ( $object, $field ) {
    $r = new ReflectionProperty( $object, $field ); if ( PHP_VERSION_ID < 80100 ) { $r->setAccessible( true ); } return $r->getValue( is_object( $object ) ? $object : null );
};
$assert = function ( $yes, $label ) { if ( ! $yes ) { throw new RuntimeException( $label ); } };
global $wpdb, $wp_version, $wp_filter;
$connection = new LegacyTransactionConnection( $wpdb );
$schema = new LegacyStorageSchema( $wpdb ); $tables = array_keys( $schema->definitions() );
foreach ( $tables as $table ) {
    $assert( null === $connection->get_var( $connection->prepare( 'SHOW TABLES LIKE %s', $connection->esc_like( $table ) ) ), 'Refuse preexisting tables.' );
}
$extensions = array(); foreach ( get_loaded_extensions() as $name ) { $extensions[$name] = phpversion( $name ); } ksort( $extensions );
$hooks = array(); foreach ( $wp_filter as $name => $hook ) {
    $hooks[$name] = array();
    foreach ( $hook->callbacks as $priority => $callbacks ) { foreach ( $callbacks as $id => $callback ) { $hooks[$name][] = array( $priority, $id, $callback['accepted_args'] ); } }
} ksort( $hooks );
$runtime = array( 'php' => PHP_VERSION, 'sapi' => PHP_SAPI, 'binary' => PHP_BINARY, 'binary_sha256' => hash_file( 'sha256', PHP_BINARY ),
    'ini_loaded' => php_ini_loaded_file(), 'ini_scanned' => php_ini_scanned_files(), 'ini' => ini_get_all( null, false ),
    'extensions' => $extensions, 'hooks' => $hooks, 'wp' => $wp_version );
$classes = array(); foreach ( array( 'TableSnapshot', 'LegacySnapshotRowReader', 'LegacyRecordCodec', 'LegacyStateCodec', 'LegacyTransactionConnection', 'StorageScalarObserver', 'SnapshotReadTraceStream' ) as $name ) {
    $file = ( new ReflectionClass( 'FooPlugins\\FooGalleryMigrate\\' . $name ) )->getFileName();
    $assert( 0 === strpos( realpath( $file ), realpath( FOOGM_SEED_ROOT ) . '/' ), 'Wrong product source.' );
    $classes[$name] = array( 'path' => realpath( $file ), 'sha256' => hash_file( 'sha256', $file ) );
}
$receipt = array( 'format' => 'real-acquisition-seed-v1', 'status' => 'MEASURED_EVIDENCE_PENDING_RECEIVING', 'ok' => false,
    'method_approved' => false, 'profile_approved' => false, 'production_enabled' => false, 'certificate_selected' => false,
    'cell' => FOOGM_SEED_CELL, 'pid' => getmypid(), 'product_root' => realpath( FOOGM_SEED_ROOT ), 'classes' => $classes,
    'emitter' => array( 'path' => __FILE__, 'sha256' => hash_file( 'sha256', __FILE__ ) ), 'runtime' => $runtime,
    'database' => $connection->get_results( 'SELECT DATABASE() AS db, CONNECTION_ID() AS connection, VERSION() AS version, @@character_set_connection AS charset', ARRAY_A ),
    'corpus_limits' => array( 'encoded_bytes' => 1048576, 'row_bytes' => 65536, 'records' => 1024, 'depth' => 64 ),
    'collector_allowance' => StorageScalarObserver::ACQUISITION_ALLOWANCE, 'peaks_include_bootstrap' => true, 'peaks_reset_or_subtracted' => false );
$created = array(); $stream = null; $error = null;
try {
    foreach ( $schema->statements() as $i => $ddl ) { $created[] = $tables[$i]; dbDelta( $ddl ); }
    $assert( $schema->verify(), 'Exact native InnoDB schema.' );
    $value = SnapshotAcquisitionSeed::value( FOOGM_SEED_CELL ); $codec = new LegacyStateCodec();
    $receipt['input'] = array( 'serialized_bytes' => strlen( serialize( $value ) ), 'serialized_sha256' => hash( 'sha256', serialize( $value ) ), 'digest' => $codec->snapshot_digest( $value ) );
    $policy = array( 'max_raw_bytes' => 1048576, 'amplification' => 64, 'reserve_bytes' => 33554432 );
    $limits = array( 'version' => 1, 'max_tokens' => 262144, 'max_rows' => 1024, 'max_bytes' => 1048576, 'max_depth' => 64,
        'max_heads' => 100, 'max_physical_rows_per_table' => 1024, 'max_physical_rows' => 2048, 'max_physical_bytes' => 2097152,
        'memory_ceiling_bytes' => 268435456, 'max_seconds' => 10 );
    $scale = new StorageScaleAdmission( $limits, $policy, microtime( true ) + 10.0 );
    $scale->admit_additional( StorageScalarObserver::ACQUISITION_ALLOWANCE );
    $repo = new TableSnapshot( $connection, (int) get_current_blog_id(), 1024, 1048576, null, null, $scale );
    // Producer witnesses/plans only. NO SnapshotReadProfile construction/reflection.
    $set( $repo, 'snapshot_authentication_enabled', true ); $set( $repo, 'snapshot_events_enabled', true );
    $repo->replace_many( array( array( 'key' => 'galleries', 'order' => 0, 'kind' => 'map', 'value' => $value, 'expected_revision' => null ) ) );
    $bytes = 'OCTET_LENGTH(payload)+OCTET_LENGTH(mutable_payload)+OCTET_LENGTH(key_value)+COALESCE(OCTET_LENGTH(legacy_identifier_value),0)+COALESCE(OCTET_LENGTH(source_plugin_value),0)+COALESCE(OCTET_LENGTH(source_id_value),0)';
    $facts = $connection->get_results( 'SELECT COUNT(*) AS records, SUM(' . $bytes . ') AS encoded_bytes, SUM(payload_bytes) AS payload_bytes, MAX(' . $bytes . ') AS max_row_bytes, MAX(depth) AS max_depth FROM `' . $tables[1] . '`', ARRAY_A );
    $head = $connection->get_results( 'SELECT HEX(snapshot_digest) AS digest, record_count, payload_bytes, OCTET_LENGTH(root_payload) AS root_bytes, OCTET_LENGTH(state_key_value) AS key_bytes FROM `' . $tables[0] . '`', ARRAY_A );
    $receipt['sql'] = array( 'facts' => $facts, 'head' => $head );
    $assert( 1 === count( $facts ) && 1 === count( $head ), 'SQL fact cardinality.' );
    $receipt['total_encoded_bytes'] = (int) $facts[0]['encoded_bytes'] + (int) $head[0]['root_bytes'] + (int) $head[0]['key_bytes'];
    $assert( $receipt['total_encoded_bytes'] <= 1048576 && (int) $facts[0]['max_row_bytes'] <= 65536 && (int) $facts[0]['records'] <= 1024 && (int) $facts[0]['max_depth'] <= 64, 'Declared corpus limits.' );
    $assert( (int) $head[0]['record_count'] === (int) $facts[0]['records'] && (int) $head[0]['payload_bytes'] === (int) $facts[0]['payload_bytes'] + (int) $head[0]['root_bytes'] && strtolower( $head[0]['digest'] ) === $receipt['input']['digest'], 'Native SQL logical/count/byte parity.' );
    $assert( null === $get( $repo, 'snapshot_read_profile' ) && null === $get( $repo, 'snapshot_read_phase' ), 'No qualified authority.' );
    $plans = $get( $repo, 'snapshot_plans' ); $assert( 1 === count( $plans ), 'Real pre-root selector has producer plan.' ); unset( $plans );
    $receipt['policy'] = $policy; $receipt['limits'] = $limits;
    $stream = SnapshotReadTraceStream::writer( FOOGM_SEED_OUT . '/trace.ndjson', array( 'purpose' => 'real-seed-legacy-admission-only', 'cell' => FOOGM_SEED_CELL,
        'pid' => getmypid(), 'collector' => $classes['StorageScalarObserver'], 'input' => $receipt['input'], 'profile_approved' => false ) );
    $set( StorageScalarObserver::class, 'acquisition_stream', $stream );
    $returned = $repo->read_bounded( 'galleries' );
    $set( StorageScalarObserver::class, 'acquisition_stream', null );
    $receipt['trace_sha256'] = $stream->finish();
    $receipt['events'] = $get( StorageScalarObserver::class, 'acquisition_sequence' );
    $assert( serialize( $returned ) === serialize( $value ) && $codec->snapshot_digest( $returned ) === $receipt['input']['digest'], 'Real complete logical reconstruction parity.' );
    $assert( null === $get( $repo, 'snapshot_read_profile' ) && null === $get( $repo, 'snapshot_read_phase' ), 'Legacy admission retained through exit.' );
    $receipt['guard_debt_after'] = array( 'additional' => $get( $scale, 'additional_bytes' ), 'pending' => $get( $scale, 'retaining_pending' ), 'native_high_water' => $get( $scale, 'native_high_water' ) );
    $receipt['output_digest'] = $codec->snapshot_digest( $returned ); $receipt['ok'] = true;
} catch ( Exception $e ) { $error = $e; }
catch ( Throwable $e ) { $error = $e; }
require dirname( __DIR__ ) . '/support/snapshot-acquisition-finalize.php';
