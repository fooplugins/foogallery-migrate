<?php
/** Read-only source-size hints; never construct an engine or hydrate source objects. */
namespace FooPlugins\FooGalleryMigrate;
if ( ! defined( 'ABSPATH' ) ) { exit; }

class StorageSourceCounts {
	/** Partial source-record estimates, not an exact migration queue or memory forecast. */
	public static function measure() {
		$counts = array( 'galleries' => null, 'images' => null );
		if ( ! current_user_can( 'manage_options' ) || is_multisite() ) { return $counts; }
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! isset( $wpdb->prefix ) || ! preg_match( '/^[A-Za-z0-9_]+$/D', $wpdb->prefix ) ) { return $counts; }
		$p = $wpdb->prefix;
		$suppressed = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;
		try {
			// Serialized image lists remain unknown: do not read/decode post meta.
			self::add( $counts['galleries'], self::count_sql( $wpdb->prepare(
				"SELECT COUNT(*) FROM `{$p}posts` WHERE post_status='publish' AND post_type IN (%s,%s,%s)",
				'envira', 'modula-gallery', 'robo_gallery_table'
			) ) );
			self::add( $counts['galleries'], self::count_sql( $wpdb->prepare(
				"SELECT COUNT(*) FROM `{$p}posts` p WHERE p.post_type=%s AND p.post_status='publish' AND EXISTS (SELECT 1 FROM `{$p}postmeta` pm WHERE pm.post_id=p.ID AND pm.meta_key=%s AND pm.meta_value<>'')",
				'aigpl_gallery', '_aigpl_gallery_imgs'
			) ) );
			if ( self::table_exists( $p . 'ngg_gallery' ) ) {
				self::add( $counts['galleries'], self::count_sql( "SELECT COUNT(*) FROM `{$p}ngg_gallery`" ) );
				if ( self::table_exists( $p . 'ngg_pictures' ) ) {
					self::add( $counts['images'], self::count_sql( "SELECT COUNT(*) FROM `{$p}ngg_pictures` i INNER JOIN `{$p}ngg_gallery` g ON g.gid=i.galleryid WHERE (i.exclude=0 OR i.exclude IS NULL)" ) );
				}
			}
			if ( self::table_exists( $p . 'bwg_gallery' ) ) {
				self::add( $counts['galleries'], self::count_sql( "SELECT COUNT(*) FROM `{$p}bwg_gallery` WHERE published=1" ) );
				if ( self::table_exists( $p . 'bwg_image' ) ) {
					self::add( $counts['images'], self::count_sql( "SELECT COUNT(*) FROM `{$p}bwg_image` i INNER JOIN `{$p}bwg_gallery` g ON g.id=i.gallery_id WHERE g.published=1" ) );
				}
			}
			$albums = defined( 'WPPA_ALBUMS' ) ? WPPA_ALBUMS : $p . 'wppa_albums';
			$photos = defined( 'WPPA_PHOTOS' ) ? WPPA_PHOTOS : $p . 'wppa_photos';
			if ( self::table_exists( $albums ) && self::table_exists( $photos ) ) {
				// No filesystem validation: eligible-looking WPPA rows are estimates.
				$from = "FROM `{$photos}` i INNER JOIN `{$albums}` g ON g.id=i.album WHERE i.album>0 AND LOWER(TRIM(g.status))='publish' AND TRIM(g.capability)='' AND LOWER(TRIM(i.status)) IN ('publish','featured','gold','silver','bronze') AND LOWER(TRIM(i.ext)) IN ('jpg','jpeg','png','gif','webp')";
				self::add( $counts['galleries'], self::count_sql( 'SELECT COUNT(DISTINCT g.id) ' . $from ) );
				self::add( $counts['images'], self::count_sql( 'SELECT COUNT(*) ' . $from ) );
			}
		} catch ( \Exception $error ) { /* Advice must not block migration on an unavailable source. */ }
		catch ( \Throwable $error ) { /* Keep the declared PHP 5.4 syntax floor. */ }
		if ( null !== $suppressed ) { $wpdb->suppress_errors( $suppressed ); }
		return $counts;
	}

	private static function table_exists( $table ) {
		global $wpdb;
		if ( ! is_string( $table ) || ! preg_match( '/^[A-Za-z0-9_]+$/D', $table ) ) { return false; }
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) && ! $wpdb->last_error;
	}

	private static function count_sql( $sql ) {
		global $wpdb;
		$value = $wpdb->get_var( $sql );
		if ( $wpdb->last_error || ( ! is_int( $value ) && ! is_string( $value ) ) || ! preg_match( '/^[0-9]+$/D', (string) $value ) ) { return null; }
		return (int) $value;
	}

	private static function add( &$total, $value ) {
		if ( null === $value ) { return; }
		if ( null === $total ) { $total = 0; }
		$total = $value > PHP_INT_MAX - $total ? PHP_INT_MAX : $total + $value;
	}
}
