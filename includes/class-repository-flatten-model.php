<?php
/**
 * Prospective HOST-only repository-sink model. NOT wired into any codec/gate.
 * Native JSON only, no retained callback rows, SQL, wpdb or hook allocations.
 * See tests/tools/repository-flatten-model.php and docs/repository-flatten-model.md.
 */
namespace FooPlugins\FooGalleryMigrate;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class RepositoryFlattenModel {
	const PROFILE = 'php-8.5.11-darwin-arm64-nts-native-json-v1';
	const BINARY_SHA256 = '6ff616b1441ba498839c8ff3b51db9d6e2f037c175e30ebe81652a76e439c749';

	public function __construct( $profile, $custom_codec = null ) {
		$extensions = array();
		foreach ( get_loaded_extensions() as $name ) { $extensions[$name] = phpversion( $name ); }
		ksort( $extensions );
		if ( 'cli' !== PHP_SAPI || hash( 'sha256', json_encode( $extensions ) ) !== '1c30606fe6fefe1aa9a56184a8a6c35ea06743c27a52d17e8099a60e5223df1b' ||
			self::PROFILE !== $profile || null !== $custom_codec || PHP_VERSION !== '8.5.11' ||
			PHP_OS !== 'Darwin' || php_uname( 'm' ) !== 'arm64' || PHP_INT_SIZE !== 8 || PHP_ZTS || PHP_DEBUG ||
			'0' === getenv( 'USE_ZEND_ALLOC' ) || ini_get( 'opcache.enable_cli' ) || function_exists( 'wp_json_encode' ) ||
			hash_file( 'sha256', PHP_BINARY ) !== self::BINARY_SHA256 ) { self::fail(); }
	}

	private static function add( $a, $b ) { return StorageScaleAdmission::checked_add( $a, $b ); }
	private static function mul( $a, $b ) { return StorageScaleAdmission::checked_multiply( $a, $b ); }

	/** Old+new capacity, bucket/hash slots and rounding; NOT a source graph copy. */
	private static function arrays( $arrays, $entries ) {
		return self::add( self::mul( 768, $arrays ), self::mul( 160, $entries ) );
	}

	/** Reserve BEFORE describe_tree. Depth is a trusted finite domain ceiling. */
	public function descriptor_bytes( $max_depth ) {
		if ( ! is_int( $max_depth ) || $max_depth < 1 || $max_depth > 64 ) { self::fail(); }
		// Two recursive traversals can nest row and value frames; eight fixed
		// callees, each rounded above observed <=251 CV/temp zvals + 80 bytes.
		$frames = self::add( self::mul( 2, self::add( $max_depth, 1 ) ), 8 );
		return self::add( 4194304, self::mul( 4096, $frames ) );
	}

	/**
	 * Pure plan for one unchanged descriptor, not permission to emit. Descriptor
	 * scratch stays pending. Source graph is ALREADY in memory_get_usage(true).
	 * Maxima may come from different rows: summing is intentionally conservative.
	 */
	public function project( $d, $state_key ) {
		if ( ! is_array( $d ) || ! isset( $d['version'], $d['ownership'], $d['retained_output_rows'] ) ||
			1 !== $d['version'] || 'repository-sink' !== $d['ownership'] || 0 !== $d['retained_output_rows'] ||
			( ! is_string( $state_key ) && ! is_int( $state_key ) ) ) { self::fail(); }
		$names = array( 'records', 'logical_max_depth', 'logical_max_string_bytes', 'max_row_bytes',
			'max_row_tagged_arrays', 'max_row_tagged_nodes', 'max_row_tagged_entries', 'max_record_fields',
			'active_row_frames', 'max_ancestor_row_bytes' );
		foreach ( $names as $name ) { if ( ! isset( $d[$name] ) ) { self::fail(); } self::add( $d[$name], 0 ); }
		if ( $d['records'] < 1 || $d['records'] > 24576 || $d['logical_max_depth'] > 64 ||
			$d['active_row_frames'] < 1 || $d['active_row_frames'] > 65 || $d['max_row_bytes'] > 2097152 ||
			$d['logical_max_string_bytes'] > 1048576 || strlen( (string) $state_key ) > 1048576 ) { self::fail(); }
		$parts = array();
		// Source zvals/strings are shared. Only shells/field_order are new.
		$parts['field_order_values_mutable'] = self::arrays( 4, self::add( 5, self::mul( 2, $d['max_record_fields'] ) ) );
		$parts['tagged_containers'] = self::arrays( self::add( 2, $d['max_row_tagged_arrays'] ),
			self::add( self::mul( 2, $d['max_row_tagged_arrays'] ), $d['max_row_tagged_entries'] ) );
		// Integer strings/header rounding per node, base64 bodies <= row JSON.
		$parts['tagged_strings'] = self::add( self::mul( 64, $d['max_row_tagged_nodes'] ), self::mul( 2, $d['max_row_bytes'] ) );
		// Native JSON old/new smart buffers, final payload+mutation, projections
		// and typed-key/hash concatenation overlap. See measured coverage limits.
		$parts['native_json_projection_hash_buffers'] = self::add( 16384, self::mul( 8, $d['max_row_bytes'] ) );
		$parts['encoded_ancestors'] = self::add( self::mul( 2, $d['max_ancestor_row_bytes'] ),
			self::arrays( self::mul( 3, $d['active_row_frames'] ), self::mul( 60, $d['active_row_frames'] ) ) );
		// Digest streams the source recursively, but re-encodes its largest
		// scalar/key and concatenates the encoded value, length and domain.
		$scalar = max( 32, $d['logical_max_string_bytes'], strlen( (string) $state_key ) );
		$parts['digest_scalar_overlap'] = self::add( 16384, self::mul( 16, $scalar ) );
		$after = 0; foreach ( $parts as $bytes ) { $after = self::add( $after, $bytes ); }
		$descriptor = $this->descriptor_bytes( 64 );
		return array( 'profile' => self::PROFILE, 'prospective_only' => true, 'source_charge_bytes' => 0,
			'descriptor_bytes' => $descriptor, 'parts' => $parts, 'after_descriptor_bytes' => $after,
			'additional_allocation_bytes' => self::add( $descriptor, $after ) );
	}

	/**
	 * Inert bridge experiment: keep the original gate AND pending deltas.
	 * Trusted caller must supply its unchanged policy amplification. No credits.
	 * Production calls to admit_memory do NOT yet use this bridge.
	 */
	public function reserve_legacy( $scale, $raw_bytes, $amplification ) {
		if ( ! $scale instanceof StorageScaleAdmission || ! is_int( $amplification ) || $amplification < 1 ) { self::fail(); }
		$delta = self::mul( $raw_bytes, $amplification );
		$scale->admit_memory( $raw_bytes );
		return $scale->admit_additional( $delta );
	}
	private static function fail() { throw new \RuntimeException( 'Prospective repository flatten model refused: unsupported profile, codec or descriptor.' ); }
}
