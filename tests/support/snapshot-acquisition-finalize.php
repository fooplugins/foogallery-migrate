<?php
/** Shared emitter footer, also executed by the host fault regression. Requires the emitter's local scope. */
use FooPlugins\FooGalleryMigrate\StorageScalarObserver;

$failure = function ( $phase, $caught ) use ( &$error, &$receipt ) {
    $message = get_class( $caught ) . ': ' . $caught->getMessage();
    if ( null === $error ) { $error = $caught; $receipt['first_failure'] = $message; }
    else { $receipt['secondary_failures'][] = array( 'phase' => $phase, 'error' => $message ); }
    $receipt['ok'] = false;
};
$attempt = function ( $phase, $callback ) use ( $failure ) {
    try { $callback(); }
    catch ( Exception $caught ) { $failure( $phase, $caught ); }
    catch ( Throwable $caught ) { $failure( $phase, $caught ); }
};
if ( null !== $error ) {
    $receipt['first_failure'] = get_class( $error ) . ': ' . $error->getMessage();
    $receipt['ok'] = false;
}
$attempt( 'collector_detach', function () use ( $set ) { $set( StorageScalarObserver::class, 'acquisition_stream', null ); } );
if ( null !== $error && null !== $stream && ! isset( $receipt['trace_sha256'] ) ) {
    $attempt( 'trace_finish', function () use ( $stream, &$receipt ) { $receipt['trace_sha256'] = $stream->finish(); } );
    $attempt( 'trace_events', function () use ( $get, &$receipt ) { $receipt['events'] = $get( StorageScalarObserver::class, 'acquisition_sequence' ); } );
}
$receipt['resources'] = array( 'live' => memory_get_usage( false ), 'allocated' => memory_get_usage( true ),
    'peak_live_cumulative' => memory_get_peak_usage( false ), 'peak_allocated_cumulative' => memory_get_peak_usage( true ), 'rusage' => getrusage() );
$cleanup = array(); $drops = array();
foreach ( array_reverse( $created ) as $table ) {
    $drops[$table] = false; $cleanup[$table] = false;
    // A failed DROP must not suppress even that table's readback, or any later table's attempts.
    $attempt( 'drop:' . $table, function () use ( $connection, $table, &$drops ) {
        if ( false === $connection->query( 'DROP TABLE `' . $table . '`' ) || ! empty( $connection->last_error ) ) {
            throw new RuntimeException( 'Owned table drop failed: ' . $table );
        }
        $drops[$table] = true;
    } );
    $attempt( 'readback:' . $table, function () use ( $connection, $table, &$cleanup ) {
        $present = $connection->get_var( $connection->prepare( 'SHOW TABLES LIKE %s', $connection->esc_like( $table ) ) );
        if ( ! empty( $connection->last_error ) ) { throw new RuntimeException( 'Owned table readback failed: ' . $table ); }
        $cleanup[$table] = null === $present;
        if ( ! $cleanup[$table] ) { throw new RuntimeException( 'Owned table remains: ' . $table ); }
    } );
}
$receipt['cleanup_drop'] = $drops;
$receipt['cleanup_readback'] = $cleanup;
$receipt['ok'] = $receipt['ok'] && ! in_array( false, $drops, true ) && ! in_array( false, $cleanup, true );
$attempt( 'receipt_write', function () use ( &$receipt ) {
    $json = json_encode( $receipt, JSON_PRETTY_PRINT );
    if ( false === $json || file_put_contents( FOOGM_SEED_OUT . '/receipt.json', $json . "\n" ) !== strlen( $json ) + 1 ) {
        throw new RuntimeException( 'Seed receipt write failed.' );
    }
} );
echo json_encode( array( 'ok' => $receipt['ok'], 'cell' => FOOGM_SEED_CELL, 'pid' => getmypid(), 'failure' => isset( $receipt['first_failure'] ) ? $receipt['first_failure'] : null,
    'secondary_failures' => isset( $receipt['secondary_failures'] ) ? $receipt['secondary_failures'] : array() ) ) . "\n";
if ( ! $receipt['ok'] ) { exit( 2 ); }
