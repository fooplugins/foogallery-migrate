<?php
/** Fresh-process native codec measurements. No policy, receiver, SQL or approval. */
if ( PHP_SAPI !== 'cli' || 2 !== $argc || ! in_array( $argv[1], array( 'flat-fields', 'nested', 'strings', 'invalid-json' ), true ) ) { exit( 2 ); }
$root = dirname( dirname( __DIR__ ) ); define( 'ABSPATH', $root . '/' );
require $root . '/includes/class-legacy-state-codec.php';
require $root . '/includes/class-storage-scalar-observer.php';
$shape = $argv[1]; $value = array();
if ( 'flat-fields' === $shape ) { for ( $i = 0; $i < 4990; ++$i ) { $value['key-' . $i] = $i; } }
elseif ( 'nested' === $shape ) { $value = null; for ( $i = 0; $i < 63; ++$i ) { $value = array( $value ); } }
elseif ( 'strings' === $shape ) { $value = array( str_repeat( "\xff\0/", 100000 ) ); }
$codec = new \FooPlugins\FooGalleryMigrate\LegacyStateCodec();
$before = memory_get_usage( false ); $start = microtime( true ); $error = null;
try {
 $json = 'invalid-json' === $shape ? '{' . str_repeat( '"version":1,', 4000 ) . '"value":["n"]}' : $codec->encode( $value );
 $encoded_live = memory_get_usage( false ); $decoded = $codec->decode( $json );
 if ( serialize( $decoded ) !== serialize( $value ) ) { throw new RuntimeException( 'Roundtrip differs.' ); }
} catch ( RuntimeException $e ) { $error = $e->getMessage(); }
if ( ( 'invalid-json' === $shape ) !== ( null !== $error ) ) { throw new RuntimeException( 'Unexpected codec outcome: ' . $error ); }
echo json_encode( array( 'php' => PHP_VERSION, 'shape' => $shape, 'measurement_only' => true, 'error' => $error,
 'json_bytes' => isset( $json ) ? strlen( $json ) : null, 'before_live' => $before,
 'encoded_live' => isset( $encoded_live ) ? $encoded_live : null, 'final_live' => memory_get_usage( false ),
 'peak_live' => memory_get_peak_usage( false ), 'peak_allocated' => memory_get_peak_usage( true ),
 'seconds' => microtime( true ) - $start, 'rusage' => getrusage() ) ) . "\n";
