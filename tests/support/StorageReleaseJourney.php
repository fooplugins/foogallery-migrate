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
	public static function request( $operation, $target ) {
		$confirmation = 'recover' === $operation ? 'recover-storage' : ( 'provision' === $operation ? 'provision-shared-schema' : 'switch-to-' . $target );
		$result = StorageCli::execute( $operation, array( 'target' => $target, 'confirm' => $confirmation ) );
		if ( is_wp_error( $result ) ) { throw new \RuntimeException( 'Public ' . $operation . ' RED: ' . $result->get_error_code() ); }
		return $result;
	}
	public static function check( $yes, $message ) { if ( ! $yes ) { throw new \RuntimeException( $message ); } }
}
