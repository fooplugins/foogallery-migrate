<?php
/** Finite HOST retaining producer model. Not a sink, WP profile or callback theorem. */
namespace FooPlugins\FooGalleryMigrate;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class GenericRetainingModel {
 const PROFILE = 'php-8.5.11-darwin-arm64-retaining-v2';
 const BINARY_SHA256 = '6ff616b1441ba498839c8ff3b51db9d6e2f037c175e30ebe81652a76e439c749';

 // Finite measured budgets only; actual headroom is still checked by $scale.
 public static function supports( $producer, $codec, $scale ) {
  if ( ! $scale instanceof StorageScaleAdmission || ! $scale->supports_retaining_phase() ||
   get_class( $producer ) !== __NAMESPACE__ . '\\LegacyRecordCodec' || get_class( $codec ) !== __NAMESPACE__ . '\\LegacyStateCodec' ||
   $codec != new LegacyStateCodec( 1048576, 64, 10000, $scale ) ||
   'cli' !== PHP_SAPI || PHP_VERSION !== '8.5.11' || PHP_OS !== 'Darwin' || php_uname( 'm' ) !== 'arm64' ||
   PHP_INT_SIZE !== 8 || PHP_ZTS || PHP_DEBUG || '0' === getenv( 'USE_ZEND_ALLOC' ) ||
   ! in_array( StoragePreflight::memory_bytes( ini_get( 'memory_limit' ) ), array( 134217728, 268435456 ), true ) ||
   ini_get( 'opcache.enable_cli' ) || hash_file( 'sha256', PHP_BINARY ) !== self::BINARY_SHA256 ) { return false; }
  $extensions = array(); foreach ( get_loaded_extensions() as $name ) { $extensions[$name] = phpversion( $name ); } ksort( $extensions );
  if ( hash( 'sha256', json_encode( $extensions ) ) !== '1c30606fe6fefe1aa9a56184a8a6c35ea06743c27a52d17e8099a60e5223df1b' ) { return false; }
  if ( ! function_exists( 'wp_json_encode' ) ) { return true; }
  // The exact measured native forwarding shim is supported, NOT WordPress's
  // encoder or arbitrary wrappers. No path/test-name trust or callback claims.
  $f = new \ReflectionFunction( 'wp_json_encode' ); $file = $f->getFileName();
  if ( ! $file || ! is_file( $file ) || filesize( $file ) > 65536 || $f->getEndLine() - $f->getStartLine() !== 2 ) { return false; }
  $scale->admit_memory( 65536 );
  $lines = file( $file ); $body = implode( '', array_slice( $lines, $f->getStartLine() - 1, 3 ) );
  return hash( 'sha256', $body ) === '49d951ee7e37cdfd0db52c2bdd7df452ad1ab3560451453431e258baea6f1e28';
 }
 private static function add( $a, $b ) { return StorageScaleAdmission::checked_add( $a, $b ); }
 private static function mul( $a, $b ) { return StorageScaleAdmission::checked_multiply( $a, $b ); }
 /** Native mixed array capacity; no assumed PHP array identity or sharing. */
 public static function container( $entries ) {
  self::add( $entries, 0 ); if ( 0 === $entries ) { return 0; }
  $capacity = 8; while ( $capacity < $entries ) { $capacity = self::mul( $capacity, 2 ); }
  return self::add( 128, self::mul( 40, $capacity ) );
 }
 private static function arrays( $a, $e ) { return self::add( self::mul( 768, $a ), self::mul( 160, $e ) ); }
 public static function descriptor_bytes() { return 4759552; } // 4MiB + (2*65+8)*4096, rounded up.
 public static function row_forecast( $bytes, $large_columns = 0 ) {
  return self::add( self::add( 2304, self::add( $bytes, self::add( $bytes, 1 ) >> 1 ) ), self::mul( 4096, $large_columns ) );
 }
 public static function row_large_columns( $row ) {
  $n = 0; foreach ( array( 'payload', 'mutable_payload', 'key_value', 'source_plugin_value', 'source_id_value' ) as $field ) {
   if ( is_string( $row[$field] ) && strlen( $row[$field] ) > 3072 ) { ++$n; }
  } return $n;
 }
 public static function flatten_plan( $d, $state_key ) {
  $parts = array();
  $parts['descriptor_frames_arena'] = self::descriptor_bytes();
  $parts['field_order_values_mutable'] = self::arrays( 4, self::add( 5, self::mul( 2, $d['max_record_fields'] ) ) );
  $parts['tagged_containers'] = self::arrays( self::add( 2, $d['max_row_tagged_arrays'] ), self::add( self::mul( 2, $d['max_row_tagged_arrays'] ), $d['max_row_tagged_entries'] ) );
  $parts['tagged_strings'] = self::add( self::mul( 64, $d['max_row_tagged_nodes'] ), self::mul( 2, $d['max_row_bytes'] ) );
  $parts['native_json_projection_hash'] = self::add( 16384, self::mul( 8, $d['max_row_bytes'] ) );
  $parts['encoded_ancestors'] = self::add( self::mul( 2, $d['max_ancestor_row_bytes'] ), self::arrays( self::mul( 3, $d['active_row_frames'] ), self::mul( 60, $d['active_row_frames'] ) ) );
  $parts['digest_scalar_overlap'] = self::add( 16384, self::mul( 16, max( 32, $d['logical_max_string_bytes'], strlen( (string) $state_key ) ) ) );
  $private = 0; foreach ( $parts as $n ) { $private = self::add( $private, $n ); }
  $future = self::add( self::mul( 2304, $d['records'] ), self::add( $d['encoded_bytes'], self::add( $d['encoded_bytes'], $d['encoded_odd_rows'] ) >> 1 ) );
  $future = self::add( $future, self::mul( 4096, $d['encoded_large_columns'] ) );
  return array( 'future' => $future,
   'private' => $private, 'external' => self::arrays( 1, $d['records'] ), 'events' => $d['records'], 'parts' => $parts );
 }
 /** Separate slot and complete row futures; workspace and consumer stay pending. */
 public static function staged_flatten_plan( $d, $state_key ) {
  $plan = self::flatten_plan( $d, $state_key );
  $next_row = self::row_forecast( $d['max_row_bytes'], 5 );
  $plan['rows_future'] = $plan['future'];
  $plan['rows_events'] = $plan['events'];
  $plan['future'] = self::container( $d['records'] );
  $plan['private'] = self::add( $plan['private'], $next_row );
  $plan['parts']['next_row'] = $next_row;
  $plan['events'] = 1;
  return $plan;
 }
 /** Prospective own-field allocation, not source bytes or a sharing credit. */
 private static function value_bytes( $v, $depth ) {
  if ( $depth > 64 ) { self::fail(); }
  if ( is_string( $v ) ) { return self::string_bytes( strlen( $v ) ); }
  if ( ! is_array( $v ) ) { return 0; }
  $n = self::container( count( $v ) );
  foreach ( $v as $k => $item ) { $n = self::add( $n, self::add( self::value_bytes( $k, $depth + 1 ), self::value_bytes( $item, $depth + 1 ) ) ); }
  return $n;
 }
 /**
  * Complete finite short-string allocation domain on the pinned host. Each
  * measured NATIVE CODEC size class (including base64 decoder capacity, not
  * substring capacity) has 8 bytes slack; empty/one-byte strings cost at least
  * 40. No interning or sharing credit.
  * Above 231, native base64 decoding can retain capacity for the ENCODED
  * input length, not merely n decoded bytes. Round that request (plus 32
  * bytes for header/NUL/alignment) up to a power of two: an upper allocator
  * class on the supported Zend MM runtime. Keep the old 64+2n bound as a
  * floor; neither raw-string charging nor the short calibration is reduced.
  */
 public static function string_bytes( $n ) {
  self::add( $n, 0 );
  foreach ( array( 3 => 40, 9 => 48, 15 => 56, 21 => 64, 27 => 72,
   39 => 88, 51 => 104, 63 => 120, 75 => 136, 99 => 168,
   123 => 200, 147 => 232, 171 => 264, 219 => 328, 231 => 392 ) as $end => $bytes ) {
   if ( $n <= $end ) { return $bytes; }
  }
  $floor = self::add( 64, self::mul( 2, $n ) );
  $request = self::add( 32, self::mul( 4, (int) ceil( $n / 3 ) ) );
  $class = 512; while ( $class < $request ) { $class = self::mul( $class, 2 ); }
  return max( $floor, $class );
 }
 /** Children storage/growth is phase-private, never prematurely realized. */
 public static function own_record_bytes( $record, $has_children, $key_bytes ) {
  $n = self::add( 64, self::mul( 2, $key_bytes ) );
  if ( ! is_array( $record ) ) { return self::add( $n, self::value_bytes( $record, 0 ) ); }
  $n = self::add( $n, self::container( count( $record ) ) );
  foreach ( $record as $field => $v ) {
   $n = self::add( $n, self::value_bytes( $field, 1 ) );
   if ( ! $has_children || 'children' !== $field ) { $n = self::add( $n, self::value_bytes( $v, 1 ) ); }
  }
  return $n;
 }
 public static function rebuild_descriptor_bytes( $d ) {
  // Native json_decode arrays/slots/string scratch plus untagging coexist.
  // The lexical descriptor has unwound. JSON returns before untagging; the
  // payload/mutable codecs and own-field walk also run sequentially. One
  // 65-frame stack (4096/frame) plus 2MiB allocator rounding, NOT the
  // descriptor's two walks + 4MiB arena. Tokens/bytes still cover concurrently
  // retained native JSON, untagged base/mutable and scalar/hash scratch. Finite
  // host calibration only; workspace overlaps index construction as well.
  $frames_arena = self::add( 2097152, self::mul( 65, 4096 ) );
  return self::add( $frames_arena, self::add( self::mul( 768, $d['max_row_native_tokens'] ), self::mul( 8, $d['max_row_bytes'] ) ) );
 }
 /** Fixed 65-frame certifier stack, scalar cursor/locals and resize scratch.
  * Each three-slot frame fits the existing 4096/frame envelope; row decoding
  * still uses the UNCHANGED row workspace in addition to this allocation. */
 public static function preorder_metadata_bytes() { return self::add( self::mul( 65, 4096 ), 8192 ); }
 /**
  * One row at a time under the already admitted descriptor-private workspace.
  * No entire decoded tree, positional index or user output is retained here.
  * describe_rows itself remains strictly lexical/non-hydrating. The producer
  * supplies preorder=true ONLY after certifying dense DFS positions/topology.
  */
 public static function rebuild_plan( $rows, $d, $codec, $scale, $preorder = false ) {
  $future = 0; $siblings = 0; $max_siblings = 0; $parents = 0;
  foreach ( $rows as $row ) {
   $scale->tick();
   $base = $codec->decode_record_payload( $row['payload'] ); $mutable = $codec->decode( $row['mutable_payload'] );
   if ( ! is_array( $base ) || ! isset( $base['kind'] ) || ! is_array( $mutable ) ) { self::fail(); }
   if ( 'array' === $base['kind'] ) {
    if ( ! isset( $base['field_order'], $base['values'] ) || ! array_key_exists( 'child_count', $base ) || ! is_array( $base['field_order'] ) || ! is_array( $base['values'] ) ) { self::fail(); }
    $record = array(); $has = null !== $base['child_count'];
    if ( $has ) {
     $count = $base['child_count'];
     if ( ! is_int( $count ) || $count < 0 || $count >= count( $rows ) ) { self::fail(); }
     if ( $count ) { ++$parents; $size = self::container( $count ); $siblings = self::add( $siblings, $size ); $max_siblings = max( $max_siblings, $size ); }
    }
    foreach ( $base['field_order'] as $field ) {
     if ( ! is_int( $field ) && ! is_string( $field ) ) { self::fail(); }
     if ( $has && 'children' === $field ) { $record[$field] = array(); }
     else if ( array_key_exists( $field, $mutable ) ) { $record[$field] = $mutable[$field]; }
     else if ( array_key_exists( $field, $base['values'] ) ) { $record[$field] = $base['values'][$field]; }
     else { self::fail(); }
    }
   } else if ( 'value' === $base['kind'] && array_key_exists( 'value', $base ) && ! is_array( $base['value'] ) ) { $record = $base['value']; $has = false; }
   else { self::fail(); }
   // Encoded key length is a conservative bound, also used by the event.
   $future = self::add( $future, self::own_record_bytes( $record, $has, strlen( $row['key_value'] ) ) );
   unset( $record, $base, $mutable );
  }
  $index = self::add( self::container( count( $rows ) ), self::add( self::container( $parents ), $siblings ) );
  // These are newly built private indexes, not caller arrays: one container
  // grows per assignment. Final capacities PLUS the largest resize overlap
  // cover every live bucket and old/new allocation, without charging every
  // independent bucket's resize simultaneously. Workspace still overlaps.
  $index_resize = max( self::container( count( $rows ) ), self::container( $parents ), $max_siblings );
  if ( $preorder ) {
   // Only adjacency is absent. Keep the complete node/visited map AND its
   // original largest-resize coefficient. Output capacities below do not fall.
   $index = self::container( count( $rows ) ); $index_resize = $index;
  }
  $parts = array( 'row_workspace' => self::rebuild_descriptor_bytes( $d ),
   'index_growth' => self::add( $index, $index_resize ), 'visited_growth' => 0,
   // All ancestor output capacities remain pending; one append can resize
   // one container at a time. No retained ancestor or child_rows credit.
   'output_children_growth' => self::add( $siblings, $max_siblings ), 'sibling_sort_cow' => 0 );
  $metadata = $preorder ? self::preorder_metadata_bytes() : 0;
  if ( $preorder ) { $parts['preorder_metadata'] = $metadata; }
  $private = 0; foreach ( $parts as $n ) { $private = self::add( $private, $n ); }
  $index_stage = self::add( $metadata, self::add( $parts['row_workspace'], $parts['index_growth'] ) );
  $after_indexes = self::add( self::add( $parts['visited_growth'], $parts['output_children_growth'] ), $parts['sibling_sort_cow'] );
  return array( 'future' => $future, 'private' => $private, 'index_stage' => $index_stage, 'after_indexes' => $after_indexes,
   'external' => 0, 'events' => count( $rows ), 'parts' => $parts );
 }
 private static function fail() { throw new \RuntimeException( 'Finite retaining model: unsupported row descriptor.' ); }
}
