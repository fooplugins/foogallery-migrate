<?php
/** Deterministic host-only I/O faults over the real stream and emitter footer. No SQL/WordPress fixture. */
require dirname( __DIR__ ) . '/bootstrap.php';

use FooPlugins\FooGalleryMigrate\SnapshotReadTraceStream;
use FooPlugins\FooGalleryMigrate\StorageScalarObserver;

define( 'FOOGM_SEED_OUT', $argv[1] );
define( 'FOOGM_SEED_CELL', 'UNIT_NOT_MEASURED' );
$mode = $argv[2];

class SeedFinalizerFaultStream {
    public $context;
    public static $writes = 0;
    public static $header_failure = false;
    public function stream_open( $path, $mode, $options, &$opened_path ) { return true; }
    public function stream_write( $data ) {
        ++self::$writes;
        if ( self::$header_failure || self::$writes > 1 ) {
            throw new RuntimeException( self::$header_failure || 2 === self::$writes ? 'initial trace write fault' : 'trace finalizer fault' );
        }
        return strlen( $data );
    }
    public function stream_flush() { return true; }
    public function stream_close() {}
}

class SeedFinalizerFaultConnection {
    public $last_error = '';
    public $calls = array();
    private $mode;
    public function __construct( $mode ) { $this->mode = $mode; }
    public function query( $sql ) {
        $this->calls[] = $sql; $this->last_error = '';
        if ( 'cleanup-faults' === $this->mode && false !== strpos( $sql, '`owned_c`' ) ) { throw new Error( 'owned_c drop fault' ); }
        return 'cleanup-faults' === $this->mode && false !== strpos( $sql, '`owned_b`' ) ? false : 1;
    }
    public function esc_like( $table ) { return $table; }
    public function prepare( $sql, $table ) { return str_replace( '%s', "'" . $table . "'", $sql ); }
    public function get_var( $sql ) {
        $this->calls[] = $sql; $this->last_error = '';
        if ( 'cleanup-faults' === $this->mode ) {
            if ( false !== strpos( $sql, "'owned_c'" ) ) { throw new RuntimeException( 'owned_c readback fault' ); }
            if ( false !== strpos( $sql, "'owned_b'" ) ) { $this->last_error = 'readback SQL fault'; return null; }
            return 'owned_a';
        }
        return null;
    }
}

$set = function ( $object, $field, $value ) {
    $r = new ReflectionProperty( $object, $field ); if ( PHP_VERSION_ID < 80100 ) { $r->setAccessible( true ); }
    $r->setValue( is_object( $object ) ? $object : null, $value );
};
$get = function ( $object, $field ) {
    $r = new ReflectionProperty( $object, $field ); if ( PHP_VERSION_ID < 80100 ) { $r->setAccessible( true ); }
    return $r->getValue( is_object( $object ) ? $object : null );
};
$assert = function ( $yes, $label ) { if ( ! $yes ) { throw new RuntimeException( $label ); } };
$connection = new SeedFinalizerFaultConnection( false !== strpos( $mode, 'cleanup-faults' ) ? 'cleanup-faults' : $mode );
$created = array( 'owned_a', 'owned_b', 'owned_c' );
$receipt = array( 'format' => 'UNIT_NOT_MEASURED', 'ok' => false ); $error = null; $stream = null;
register_shutdown_function( function () use ( $connection, $get ) {
    file_put_contents( FOOGM_SEED_OUT . '/calls.json', json_encode( array( 'sql' => $connection->calls, 'writes' => SeedFinalizerFaultStream::$writes,
        'collector_detached' => null === $get( StorageScalarObserver::class, 'acquisition_stream' ) ) ) );
} );
try {
    if ( 'trace-faults' === $mode || 'trace-cleanup-faults' === $mode || 'header-fault' === $mode ) {
        stream_wrapper_register( 'seedfault', 'SeedFinalizerFaultStream' );
        SeedFinalizerFaultStream::$header_failure = 'header-fault' === $mode;
        $stream = SnapshotReadTraceStream::writer( 'seedfault://trace', array( 'purpose' => 'UNIT_NOT_MEASURED' ) );
        $set( StorageScalarObserver::class, 'acquisition_stream', $stream );
        StorageScalarObserver::acquisition( 4, 0 );
    } else {
        $stream = SnapshotReadTraceStream::writer( FOOGM_SEED_OUT . '/trace.ndjson', array( 'purpose' => 'UNIT_NOT_MEASURED' ) );
        $stream->append( array( 'unit' => 1 ) );
        $receipt['trace_sha256'] = $stream->finish();
        if ( 'primary-after-finish' === $mode ) { throw new RuntimeException( 'post-trace primary fault' ); }
        $receipt['ok'] = true;
    }
} catch ( Exception $e ) { $error = $e; }
catch ( Throwable $e ) { $error = $e; }
require dirname( __DIR__ ) . '/support/snapshot-acquisition-finalize.php';
