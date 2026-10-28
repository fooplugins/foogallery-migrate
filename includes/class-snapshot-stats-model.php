<?php
/** Internal discard-only codec workspace on the existing finite native host. */
namespace FooPlugins\FooGalleryMigrate;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class SnapshotStatsModel {
 /**
  * Same native encoder/allocator domain as GenericRetainingModel, NOT a new
  * approved runtime. Sampling is observation only and cannot qualify a host.
  * Deliberately separate: arbitrary flatten consumers keep their old guards.
  */
 public static function supports( $producer, $codec, $scale ) {
  if ( ! $scale instanceof StorageScaleAdmission || ! $scale->supports_snapshot_stats() ||
   get_class( $producer ) !== __NAMESPACE__ . '\\LegacyRecordCodec' || get_class( $codec ) !== __NAMESPACE__ . '\\LegacyStateCodec' ||
   $codec != new LegacyStateCodec( 1048576, 64, 10000, $scale ) ||
   'cli' !== PHP_SAPI || PHP_VERSION !== '8.5.11' || PHP_OS !== 'Darwin' || php_uname( 'm' ) !== 'arm64' ||
   PHP_INT_SIZE !== 8 || PHP_ZTS || PHP_DEBUG || '0' === getenv( 'USE_ZEND_ALLOC' ) ||
   '128M' !== ini_get( 'memory_limit' ) || ini_get( 'opcache.enable_cli' ) || hash_file( 'sha256', PHP_BINARY ) !== GenericRetainingModel::BINARY_SHA256 ) { return false; }
  $extensions = array(); foreach ( get_loaded_extensions() as $name ) { $extensions[$name] = phpversion( $name ); } ksort( $extensions );
  if ( hash( 'sha256', json_encode( $extensions ) ) !== '1c30606fe6fefe1aa9a56184a8a6c35ea06743c27a52d17e8099a60e5223df1b' ) { return false; }
  if ( ! function_exists( 'wp_json_encode' ) ) { return true; }
  $f = new \ReflectionFunction( 'wp_json_encode' ); $file = $f->getFileName();
  if ( ! $file || ! is_file( $file ) || filesize( $file ) > 65536 || $f->getEndLine() - $f->getStartLine() !== 2 ) { return false; }
  $scale->admit_snapshot_stats_workspace( 65536 );
  $lines = file( $file ); $body = implode( '', array_slice( $lines, $f->getStartLine() - 1, 3 ) );
  return hash( 'sha256', $body ) === '49d951ee7e37cdfd0db52c2bdd7df452ad1ab3560451453431e258baea6f1e28';
 }
 /**
  * Reuse ONLY the unchanged flatten PRIVATE workspace bound: descriptor arena,
  * row field-order/values/mutable copies, tagged arrays/strings, native JSON,
  * projection/hash, ALL encoded ancestor rows and scalar digest overlap.
  * The internal producer builds the same rows/digests, then destroys each row.
  * No future retained row list, consumer COW or external-output allocation is
  * made. Keep ancestor overcount even though the discard walk unsets its row.
  */
 public static function workspace( $descriptor, $state_key ) {
  $plan = GenericRetainingModel::flatten_plan( $descriptor, $state_key );
  return $plan['private'];
 }
}
