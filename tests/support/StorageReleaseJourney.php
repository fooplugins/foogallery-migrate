<?php
/** Positive public barrier shared by the owned-floor journey and its host RED target. */
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\StorageCli;

class StorageReleaseJourney {
	const NATIVE_IMAGES = 20000;
	const REAL_IMAGES = 10000;
	const JOURNAL = 'foogm_repr_tfc1db765_journey_v1';
	const OWNER = 't_fc1db765-representation-lifetime-v1';
	const POST_OWNER = '_foogm_repr_tfc1db765_owner';

	/** No constructor profile, filter, reflection, capacity override or fake success. */
	public static function preflight() {
		return self::request( 'preflight', 'table' );
	}
	public static function needs_first_discovery( $journal ) {
		self::check( ! array_key_exists( 'discovery_pending', $journal ), 'Incomplete first discovery; preserve journal/output and require independent recovery review, not a forced retry.' );
		if ( isset( $journal['uid'] ) ) { return false; }
		self::check( isset( $journal['turns'] ) && 0 === $journal['turns'], 'Missing discovery checkpoint; preserve journal/output.' );
		return true;
	}

	/** Only the first turn, AFTER real sources exist; later reads retain progress. */
	public static function discover_first_turn( $engine ) {
		$engine->run_detection();
		return $engine->get_gallery_migrator()->get_objects_to_migrate( true );
	}

	/**
	 * Deliberately unavailable: this harness has no independently established
	 * exclusion of DB/filesystem/hook mutation. Neither a stable control record
	 * nor an owner meta flag proves that CURRENT options or deletion targets
	 * still belong to us. Do not accept an env flag or advisory lock as proof.
	 *
	 * A future separately reviewed teardown must bind exact CURRENT option rows,
	 * prevalidate the COMPLETE file/post deletion set, and durably checkpoint
	 * partial progress under verified exclusion, before it can replace refusal.
	 */
	public static function cleanup() {
		throw new \RuntimeException( 'Cleanup refused: independently verified DB/filesystem/hook quiescence and an exact restore/deletion manifest are unavailable. Preserve journal, CURRENT options, markers and files; no automatic teardown or retry.' );
	}

	public static function request( $operation, $target ) {
		$confirmation = 'recover' === $operation ? 'recover-storage' : ( 'provision' === $operation ? 'provision-shared-schema' : 'switch-to-' . $target );
		$result = StorageCli::execute( $operation, array( 'target' => $target, 'confirm' => $confirmation ) );
		if ( is_wp_error( $result ) ) { throw new \RuntimeException( 'Public ' . $operation . ' RED: ' . $result->get_error_code() ); }
		return $result;
	}
	public static function check( $yes, $message ) { if ( ! $yes ) { throw new \RuntimeException( $message ); } }
}
