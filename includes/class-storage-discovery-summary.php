<?php
/** Small, last-successful-gallery-discovery counts for storage advice. */
namespace FooPlugins\FooGalleryMigrate;
if ( ! defined( 'ABSPATH' ) ) { exit; }

class StorageDiscoverySummary {
	const OPTION = 'foogallery_migrate_discovery_summary';

	/** Count only objects already returned by discovery; never hydrate children. */
	public static function capture( $objects ) {
		$galleries = 0; $images = 0; $complete = true;
		foreach ( $objects as $object ) {
			++$galleries;
			if ( ! is_object( $object ) || ! method_exists( $object, 'get_children_count' ) ) { $complete = false; continue; }
			try { $count = $object->get_children_count(); }
			catch ( \Exception $error ) { $complete = false; continue; }
			catch ( \Throwable $error ) { $complete = false; continue; }
			if ( ! is_int( $count ) || $count < 0 ) { $complete = false; continue; }
			if ( $count > PHP_INT_MAX - $images ) { $images = PHP_INT_MAX; $complete = false; }
			else { $images += $count; }
		}
		return array( 'version' => 1, 'galleries' => $galleries, 'images' => $images,
			'images_complete' => $complete, 'refreshed_at' => time() );
	}

	/** An option update is small and non-autoloaded on both storage backends. */
	public static function publish( $summary ) {
		if ( null === self::validate( $summary ) ) { return false; }
		return update_option( self::OPTION, $summary, false );
	}

	public static function read() {
		return self::validate( get_option( self::OPTION, false ) );
	}

	public static function invalidate() { delete_option( self::OPTION ); }

	private static function validate( $summary ) {
		if ( ! is_array( $summary ) || count( $summary ) !== 5 || array_keys( $summary ) !== array( 'version', 'galleries', 'images', 'images_complete', 'refreshed_at' ) ||
			1 !== $summary['version'] || ! is_int( $summary['galleries'] ) || $summary['galleries'] < 0 ||
			! is_int( $summary['images'] ) || $summary['images'] < 0 || ! is_bool( $summary['images_complete'] ) ||
			! is_int( $summary['refreshed_at'] ) || $summary['refreshed_at'] < 1 || $summary['refreshed_at'] > time() + 300 ) { return null; }
		return $summary;
	}
}
