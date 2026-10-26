<?php
/**
 * Separate positive persistence lane. Native wiring is disposable test-only;
 * the frozen corpus metadata is historical preparation, not calibration approval.
 */
namespace FooPlugins\FooGalleryMigrate\Tests;

require_once dirname( __DIR__ ) . '/support/StorageA7OptionsFixture.php';

class StorageA7OptionsHarness {
	/** Readers are invoked afresh for EVERY stage; a seed assertion alone is insufficient. */
	public static function run( $stage, $seed, $owner, $blog, $read_sql, $read_wp, $downstream ) {
		if ( ! in_array( $stage, array( 'forward', 'backup', 'reverse' ), true ) ) { throw new \RuntimeException( 'Unknown positive-storage stage.' ); }
		$manifest = StorageA7OptionsFixture::manifest(); $fixture = $manifest['fixture'];
		$exact = array( 'bytes' => $fixture['raw_bytes'], 'sha256' => $fixture['sha256'] );
		$required = array( 'format' => 'a7-options-seed-v1', 'corpus' => StorageA7OptionsFixture::NAME, 'stage' => 'seed',
			'owner' => $owner, 'blog_id' => $blog, 'status' => 'measurement_only', 'contract_decision' => 'pending',
			'method_approved' => false, 'profile_approved' => false, 'outcome' => 'SUCCESS', 'first_failure' => null,
			'fixture' => $fixture, 'persisted_sql' => $exact, 'wp_logical_sha256' => $fixture['logical_sha256'] );
		if ( ! is_string( $owner ) || ! preg_match( '/^[a-f0-9]{32}$/D', $owner ) || ! is_int( $blog ) || $blog < 1 || ! is_array( $seed ) ) {
			throw new \RuntimeException( 'Seed receipt not SUCCESS/bound/exact.' );
		}
		foreach ( $required as $key => $value ) {
			if ( ! array_key_exists( $key, $seed ) || $seed[$key] !== $value ) { throw new \RuntimeException( 'Seed receipt not SUCCESS/bound/exact.' ); }
		}
		// Reverse has no active legacy option after forward: verify its retained exact backup.
		$name = 'forward' !== $stage ? 'foogallery-migrate-data-storage-backup' : 'foogallery-migrate-data';
		if ( call_user_func( $read_sql, $name ) !== $exact ) { throw new \RuntimeException( 'Fresh persisted SQL binary digest mismatch.' ); }
		$value = call_user_func( $read_wp, $name );
		try { $logical = ( new \FooPlugins\FooGalleryMigrate\LegacyStateCodec() )->snapshot_digest( $value ); }
		catch ( \RuntimeException $error ) { throw new \RuntimeException( 'Fresh WordPress logical readback mismatch.' ); }
		if ( ! is_array( $value ) || $logical !== $fixture['logical_sha256'] ) { throw new \RuntimeException( 'Fresh WordPress logical readback mismatch.' ); }
		$result = call_user_func( $downstream );
		$expected_result = 'backup' === $stage ? $exact : array( 'blog_id' => $blog, 'backend' => 'reverse' === $stage ? 'option' : 'table',
			'epoch' => 'reverse' === $stage ? 2 : 1, 'phase' => 'stable' );
		if ( $result !== $expected_result ) { throw new \RuntimeException( 'Downstream result empty or mismatched.' ); }
		return $result;
	}

	/**
	 * Real WP adapter, not a connection opener. Caller supplies its already-confined WPDB.
	 * Host tests use an inert DB seam and bootstrap get_option; NOT real SQL/WP acceptance.
	 */
	public static function wordpress_readers( $wpdb ) {
		return array(
			function ( $name ) use ( $wpdb ) {
				$row = $wpdb->get_row( $wpdb->prepare( 'SELECT OCTET_LENGTH(option_value) AS bytes, SHA2(CAST(option_value AS BINARY),256) AS sha256 FROM `' . $wpdb->options . '` WHERE option_name=%s LIMIT 1', $name ), ARRAY_A );
				if ( ! empty( $wpdb->last_error ) ) { throw new \RuntimeException( 'SQL binary readback failed.' ); }
				if ( ! is_array( $row ) || ! isset( $row['bytes'], $row['sha256'] ) || ! preg_match( '/^[0-9]+$/D', (string) $row['bytes'] ) ||
					! is_string( $row['sha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $row['sha256'] ) ) { return false; }
				return array( 'bytes' => (int) $row['bytes'], 'sha256' => $row['sha256'] );
			},
			function ( $name ) {
				// Eliminate stale positive, negative and autoloaded option cache entries.
				\wp_cache_delete( $name, 'options' ); \wp_cache_delete( 'alloptions', 'options' ); \wp_cache_delete( 'notoptions', 'options' );
				return \get_option( $name, false );
			}
		);
	}
}
