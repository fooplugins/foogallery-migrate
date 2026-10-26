<?php
/** Separate persistence-domain corpus. Never substitutes for the frozen binary gate. */
namespace FooPlugins\FooGalleryMigrate\Tests;

require_once __DIR__ . '/StorageA7Fixture.php';

class StorageA7OptionsFixture {
	const NAME = 'a7-options-utf8mb4-20k-v1';
	const RAW_BYTES = 2998293;
	const RAW_SHA256 = '60392c9d095f334fed1475ccd5cd1e3db5ed1142922627980f5d0bff64072297';
	const LOGICAL_SHA256 = '0b77be8fa38731750a46d6ba9afb96fd6d656738025b02a193acb44aa180ee6c';

	public static function value() {
		$value = StorageA7Fixture::value( 20000 );
		if ( hash( 'sha256', serialize( $value ) ) !== '6022f4ad5a1e8ef9a39a5b5284dc576c7eb75d02a0d69949a58e3967ca9dd4be' ) {
			throw new \RuntimeException( 'Original corpus identity mismatch.' );
		}
		$gallery =& $value['galleries']['items']['gallery-009']; $changes = 0;
		// Change only the factory's declared injected leaves, not arbitrary strings or keys.
		foreach ( $gallery['children'] as &$image ) {
			if ( isset( $image['unknown'] ) ) {
				if ( "\xff\x00" !== $image['unknown']['binary'] ) { throw new \RuntimeException( 'Unexpected injected leaf.' ); }
				$image['unknown']['binary'] = "x\x00"; ++$changes;
			}
		}
		unset( $image );
		if ( "\x00\xff" !== $gallery['unknown'][2] ) { throw new \RuntimeException( 'Unexpected injected gallery leaf.' ); }
		$gallery['unknown'][2] = "\x00x"; ++$changes;
		if ( 208 !== $changes ) { throw new \RuntimeException( 'Unexpected substitution count.' ); }
		self::verify( $value );
		return $value;
	}

	/** Reject drift; never regenerate or repair the pinned expectations. */
	public static function verify( $value ) {
		$raw = serialize( $value );
		if ( strlen( $raw ) !== self::RAW_BYTES || hash( 'sha256', $raw ) !== self::RAW_SHA256 ||
			( new \FooPlugins\FooGalleryMigrate\LegacyStateCodec() )->snapshot_digest( $value ) !== self::LOGICAL_SHA256 ) {
			throw new \RuntimeException( 'Positive corpus identity mismatch.' );
		}
	}

	public static function manifest() {
		return array( 'format' => self::NAME, 'domain' => 'host-only-preparation', 'status' => 'measurement_only',
			'contract_decision' => 'pending', 'native_execution_enabled' => false, 'method_approved' => false, 'profile_approved' => false,
			'original_gate' => 'OPEN/BLOCKED; unchanged bytes required; not replaced or waived',
			'original' => array( 'format' => 'a7-compact-v1', 'raw_bytes' => self::RAW_BYTES,
				'sha256' => '6022f4ad5a1e8ef9a39a5b5284dc576c7eb75d02a0d69949a58e3967ca9dd4be',
				'logical_sha256' => 'a3d122b4a3219a31ffefd28d1063f753caeb289eefc3597b38582ab1090ff695' ),
			'fixture' => array( 'raw_bytes' => self::RAW_BYTES, 'sha256' => self::RAW_SHA256, 'logical_sha256' => self::LOGICAL_SHA256 ),
			'delta' => array( 'bytes' => 208, 'string_leaves' => 208, 'from_hex' => 'ff', 'to_hex' => '78', 'equivalent_semantics' => false ),
			'shape' => array( 'galleries' => 1, 'immediate_images' => 20000, 'nested_images' => 207, 'object_records' => 20208,
				'tokens' => 204995, 'arrays' => 20834, 'depth_root_zero' => 8, 'aigpl_discovery' => false, 'settings_bucket' => false, 'top_level_20k' => false ),
			'limits' => array( 'worker_seconds' => StorageA7Fixture::WORKER_SECONDS, 'response_bytes' => StorageA7Fixture::RESPONSE_BYTES,
				'raw_bytes' => StorageA7Fixture::MAX_RAW_BYTES, 'default_20k_decoder' => 'refused; unchanged' ) );
	}
}
