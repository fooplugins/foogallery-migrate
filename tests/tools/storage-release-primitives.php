<?php
/** Bounded fresh-process primitive measurements for release headroom, not customer qualification. */
if ( PHP_SAPI !== 'cli' || 2 !== $argc ) { exit( 2 ); }
$shape = $argv[1]; $n = 4096; $before = memory_get_usage( false ); $allocated = memory_get_usage( true );
$value = array();
for ( $i = 0; $i < $n; ++$i ) {
 switch ( $shape ) {
  case 'empty-arrays': $value[$i] = array(); break;
  case 'integers': $value[$i] = $i; break;
  case 'short-strings': $value['key-' . $i] = 'value-' . $i; break;
  case 'tag-pairs': $value[$i] = array( array( 'i', (string) $i ), array( 's', base64_encode( 'value-' . $i ) ) ); break;
  case 'bytes': $value[$i] = str_repeat( 'x', 1024 ); break;
  default: exit( 2 );
 }
}
$live = memory_get_usage( false ) - $before; $backing = memory_get_usage( true ) - $allocated;
$raw = serialize( $value ); $next_before = memory_get_usage( false ); $next = unserialize( $raw );
if ( serialize( $next ) !== $raw ) { throw new RuntimeException( 'Primitive roundtrip mismatch.' ); }
echo json_encode( array( 'php' => PHP_VERSION, 'shape' => $shape, 'count' => $n,
 'live_delta' => $live, 'backing_delta' => $backing, 'native_decode_delta' => memory_get_usage( false ) - $next_before,
 'raw_bytes' => strlen( $raw ), 'peak_allocated' => memory_get_peak_usage( true ), 'rusage' => getrusage() ) ) . "\n";
