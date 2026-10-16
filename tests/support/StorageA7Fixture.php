<?php
/** Frozen finite A7 corpus. Pure factory: no WordPress, SQL or approval provider. */
namespace FooPlugins\FooGalleryMigrate\Tests;

class StorageA7Fixture {
	const MAX_RAW_BYTES = 8388608;
	const WORKER_SECONDS = 45;
	const RESPONSE_BYTES = 262144;

	public static function cells() { return array( 1000, 2000, 10000, 20000 ); }

	public static function value( $count ) {
		if ( ! in_array( $count, self::cells(), true ) ) { throw new \RuntimeException( 'Unknown frozen A7 cell.' ); }
		$images = array();
		for ( $i = 0; $i < $count; ++$i ) {
			$key = 20000 === $count && 0 === $i % 97 ? 'image-00' . $i : $i;
			$image = array( 'object_type' => 'image', 'ID' => '00' . $i, 'source_url' => 'https://example.invalid/a7/' . $i . '.jpg', 'migrated' => false );
			if ( 20000 === $count && 0 === $i % 97 ) {
				$image['unknown'] = array( '09' => null, 9 => false, 'binary' => "\xff\x00", 'unicode' => '雪🌍', 'float' => 0.5, 'zero' => -0.0 );
				$image['children'] = array( 'boundary' => array( 'object_type' => 'image', 'children' => array(), 'unknown' => true ) );
			}
			$images[$key] = $image;
		}
		return array( 'galleries' => array( '_foogallery_migrate_compact' => 1, 'type' => 'migratable',
			'items' => array( 'gallery-009' => array( 'object_type' => 'gallery', 'ID' => '009', 'title' => 'A7 雪', 'children' => $images, 'unknown' => array( null, false, "\x00\xff" ) ) ) ) );
	}

	/** Exactly one byte beyond the held-out byte ceiling, still canonical PHP serialization. */
	public static function over_bound_raw() {
		$raw = serialize( self::value( 20000 ) );
		$target = strlen( $raw ) + 1;
		// A scalar padding fixture isolates byte admission; it is not a smaller A7 cell.
		for ( $digits = 1; $digits <= 8; ++$digits ) {
			$length = $target - strlen( 'a:1:{s:7:"padding";s:' . str_repeat( '0', $digits ) . ':"";}' );
			if ( $length > 0 && strlen( (string) $length ) === $digits ) {
				$result = serialize( array( 'padding' => str_repeat( 'x', $length ) ) );
				if ( strlen( $result ) === $target ) { return $result; }
			}
		}
		throw new \RuntimeException( 'Cannot construct exact byte boundary.' );
	}

	public static function manifest() {
		$cells = array();
		foreach ( self::cells() as $count ) {
			$raw = serialize( self::value( $count ) );
			if ( strlen( $raw ) > self::MAX_RAW_BYTES ) { throw new \RuntimeException( 'Frozen raw-byte limit exceeded.' ); }
			$cells[(string) $count] = array( 'images' => $count, 'role' => 20000 === $count ? 'held_out' : 'training', 'raw_bytes' => strlen( $raw ), 'sha256' => hash( 'sha256', $raw ) );
		}
		$over = self::over_bound_raw();
		$cells['over-bound'] = array( 'images' => 0, 'role' => 'byte_refusal_only', 'raw_bytes' => strlen( $over ), 'sha256' => hash( 'sha256', $over ) );
		return array( 'format' => 'a7-compact-v1', 'domain' => 'native-cli-only', 'status' => 'measurement_only', 'method_approved' => false, 'profile_approved' => false,
			'limits' => array( 'raw_bytes' => self::MAX_RAW_BYTES, 'worker_seconds' => self::WORKER_SECONDS, 'response_bytes' => self::RESPONSE_BYTES,
				'fixture_depth' => 9, 'product_decoder_nodes' => 30000, 'product_snapshot_records' => 10000, 'product_snapshot_bytes' => 16777216, 'product_deadline_seconds' => 10 ),
			'experimental_policy' => array( 'max_raw_bytes' => $cells['20000']['raw_bytes'], 'amplification' => 64, 'reserve_bytes' => 33554432 ),
			'experimental_policy_reason' => 'Test-only finite held-out byte ceiling, existing synthetic factor64/reserve32MiB. NOT a measured or approved profile; no production bounds changed.',
			'cells' => $cells );
	}
}
