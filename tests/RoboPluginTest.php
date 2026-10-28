<?php

namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\OptionMigratedStore;
use FooPlugins\FooGalleryMigrate\MigratorEngine;
use FooPlugins\FooGalleryMigrate\MigratorSettings;
use FooPlugins\FooGalleryMigrate\Objects\Gallery;
use FooPlugins\FooGalleryMigrate\Plugins\Robo;
use PHPUnit\Framework\TestCase;

class RoboPluginTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['foogallery_migrate_test_options'] = array( 'thumbnail_size_w' => 150, 'thumbnail_size_h' => 100 );
		$GLOBALS['foogallery_migrate_test_post_meta'] = array();
		$GLOBALS['foogallery_migrate_test_posts'] = array();
		$GLOBALS['foogallery_migrate_test_post_meta_updates'] = array();
		$GLOBALS['foogallery_migrate_test_plugins'] = array();
		$GLOBALS['foogallery_migrate_test_gallery_templates'] = array();
		$GLOBALS['foogallery_migrate_engine_instance'] = new MigratorEngine( new OptionMigratedStore( new MigratorSettings() ) );
		unset( $GLOBALS['wpdb'] );
		set_error_handler( function ( $level, $message, $file, $line ) {
			throw new \ErrorException( $message, 0, $level, $file, $line );
		} );
	}

	protected function tearDown(): void {
		restore_error_handler();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	private function gallery( $settings ): Gallery {
		$gallery = new Gallery( new Robo() );
		$gallery->ID = 47;
		$gallery->title = 'Robo source';
		$gallery->settings = $settings;
		return $gallery;
	}

	public static function missing_settings(): array {
		return array(
			'empty array' => array( array() ),
			'null' => array( null ),
			'legacy empty string' => array( '' ),
			'malformed string' => array( 'invalid' ),
			'false' => array( false ),
			'object' => array( new \stdClass() ),
			'malformed nested values' => array( array(
				'type' => array( 'grid' ), 'rsg_thumb_size_options' => 'invalid',
				'rsg_width_size' => 'invalid', 'rsg_border_options' => 'invalid',
				'rsg_radius' => array(), 'rsg_lightboxTitle' => array(),
				'rsg_horizontalSpaceBetweenBoxes' => array(),
			) ),
		);
	}

	/** @dataProvider missing_settings */
	public function test_missing_or_malformed_settings_have_warning_free_defaults( $source ): void {
		$gallery = $this->gallery( $source );
		$this->assertSame( 'default', $gallery->plugin->get_gallery_template( $gallery ) );
		$this->assertSame( array(
			'keep' => 'existing default',
			'default_thumbnail_dimensions' => array( 'width' => 150, 'height' => 100 ),
			'default_hover_effect_icon' => '', 'default_caption_title_source' => 'none',
			'default_lightbox' => '', 'default_alignment' => 'fg-left',
			'default_border_size' => 'fg-border-none', 'default_rounded_corners' => '',
			'default_spacing' => 'fg-gutter-0',
		), $gallery->plugin->get_gallery_settings( $gallery, array( 'keep' => 'existing default' ) ) );
		$this->assertSame( $source, $gallery->settings, 'Normalizing settings must not modify the source object.' );
	}

	public function test_gallery_without_a_settings_property_is_supported(): void {
		$gallery = $this->gallery( array() );
		unset( $gallery->settings );
		$this->assertSame( 'default', $gallery->plugin->get_gallery_template( $gallery ) );
		$this->assertSame( 'fg-left', $gallery->plugin->get_gallery_settings( $gallery, array() )['default_alignment'] );
	}

	public static function partial_dimensions(): array {
		return array(
			'width only' => array( array( 'rsg_thumb_size_options' => array( 'width' => '320' ) ), array( 'width' => '320', 'height' => 100 ) ),
			'height only' => array( array( 'rsg_thumb_size_options' => array( 'height' => '240' ) ), array( 'width' => 150, 'height' => '240' ) ),
			'width mode without width' => array( array( 'rsg_width_size' => array( 'widthType' => '1' ) ), array( 'width' => 150, 'height' => 100 ) ),
			'width without mode' => array( array( 'rsg_width_size' => array( 'width' => 320 ) ), array( 'width' => 150, 'height' => 100 ) ),
			'invalid dimensions' => array( array( 'rsg_thumb_size_options' => array( 'width' => array(), 'height' => 'bad' ) ), array( 'width' => 150, 'height' => 100 ) ),
			'invalid width in width mode' => array( array( 'rsg_width_size' => array( 'widthType' => 1, 'width' => array() ) ), array( 'width' => 150, 'height' => 100 ) ),
		);
	}

	/** @dataProvider partial_dimensions */
	public function test_partial_size_fields_fall_back_individually( $source, $expected ): void {
		$gallery = $this->gallery( $source );
		$this->assertSame( $expected, $gallery->plugin->get_gallery_settings( $gallery, array() )['default_thumbnail_dimensions'] );
	}

	public function test_live_robogrid_source_shape_keeps_the_existing_fallback_mapping(): void {
		$gallery = $this->gallery( array( 'type' => 'robogrid', 'rsg_galleryImages' => array( 40, 35, 11 ) ) );
		$settings = $gallery->plugin->get_gallery_settings( $gallery, array() );
		$this->assertSame( 'default', $gallery->plugin->get_gallery_template( $gallery ) );
		$this->assertSame( 'fg-left', $settings['default_alignment'] );
		$this->assertSame( 'fg-border-none', $settings['default_border_size'] );
		$this->assertSame( 'fg-gutter-0', $settings['default_spacing'] );
	}

	public static function layout_types(): array {
		return array(
			array( 'grid', 'masonry' ), array( 'masonry', 'masonry' ),
			array( 'mosaic', 'masonry' ), array( 'polaroid', 'masonry' ),
			array( 'slider', 'carousel' ), array( 'robogrid', 'default' ),
			array( 'unknown', 'default' ),
		);
	}

	/** @dataProvider layout_types */
	public function test_each_layout_type_accepts_missing_optional_fields( $type, $layout ): void {
		$gallery = $this->gallery( array( 'type' => $type ) );
		$this->assertSame( $layout, $gallery->plugin->get_gallery_template( $gallery ) );
		$settings = $gallery->plugin->get_gallery_settings( $gallery, array() );
		$this->assertSame( array( 'width' => 150, 'height' => 100 ), $settings[$layout . '_thumbnail_dimensions'] );
		if ( 'masonry' === $layout ) {
			$this->assertSame( 0, $settings['masonry_gutter_width'] );
			$this->assertSame( 'fg-left', $settings['masonry_alignment'] );
		}
	}

	public static function valid_settings(): array {
		return array(
			'grid' => array( 'grid', 'masonry', 'rsg_align' ),
			'masonry' => array( 'masonry', 'masonry', 'rsg_polaroidAlign' ),
			'mosaic' => array( 'mosaic', 'masonry', 'rsg_polaroidAlign' ),
			'polaroid' => array( 'polaroid', 'masonry', 'rsg_polaroidAlign' ),
			'default' => array( 'robogrid', 'default', 'rsg_align' ),
		);
	}

	/** @dataProvider valid_settings */
	public function test_valid_settings_keep_their_existing_mapping( $type, $layout, $alignment_key ): void {
		$source = array(
			'type' => $type, 'rsg_thumb_size_options' => array( 'width' => '320', 'height' => '240' ),
			'rsg_radius' => '3', 'rsg_border_options' => array( 'width' => '7' ),
			'rsg_lightboxTitle' => '1', 'rsg_horizontalSpaceBetweenBoxes' => '12',
			$alignment_key => 'center',
		);
		$gallery = $this->gallery( $source );
		$expected = array(
			$layout . '_thumbnail_dimensions' => array( 'width' => '320', 'height' => '240' ),
			$layout . '_hover_effect_icon' => '', $layout . '_caption_title_source' => 'none',
			$layout . '_lightbox' => 'foobox', $layout . '_alignment' => 'default' === $layout ? 'fg-left' : 'fg-center',
			$layout . '_border_size' => 'fg-border-thick', $layout . '_rounded_corners' => 'fg-round-medium',
		);
		if ( 'default' === $layout ) { $expected['default_spacing'] = 'fg-gutter-15'; }
		else { $expected['masonry_gutter_width'] = '12'; $expected['masonry_layout'] = 'fixed'; }
		$this->assertSame( $expected, $gallery->plugin->get_gallery_settings( $gallery, array() ) );
		$this->assertSame( $source, $gallery->settings );
	}

	public function test_width_mode_and_explicit_zero_dimensions_are_preserved(): void {
		$gallery = $this->gallery( array( 'rsg_width_size' => array( 'widthType' => '1', 'width' => '275' ) ) );
		$this->assertSame( array( 'width' => '275', 'height' => 100 ), $gallery->plugin->get_gallery_settings( $gallery, array() )['default_thumbnail_dimensions'] );
		$gallery->settings = array( 'rsg_thumb_size_options' => array( 'width' => 0, 'height' => 0 ) );
		$this->assertArrayNotHasKey( 'default_thumbnail_dimensions', $gallery->plugin->get_gallery_settings( $gallery, array() ) );
	}

	public static function slider_sources(): array {
		return array( array( 'title', 'title' ), array( 'caption', 'caption' ), array( 'description', 'desc' ), array( 'unknown', 'title' ), array( null, 'title' ) );
	}

	/** @dataProvider slider_sources */
	public function test_slider_caption_mapping_and_missing_source( $source, $expected ): void {
		$gallery = $this->gallery( array( 'type' => 'slider', 'rsg_content' => '1', 'rsg_content_source' => $source ) );
		$this->assertSame( array(
			'carousel_thumbnail_dimensions' => array( 'width' => 150, 'height' => 100 ),
			'carousel_caption_title_source' => $expected,
		), $gallery->plugin->get_gallery_settings( $gallery, array() ) );
	}

	public function test_layout_override_and_existing_default_settings_are_kept(): void {
		$GLOBALS['foogallery_migrate_test_gallery_templates'] = array( array( 'slug' => 'justified', 'name' => 'Justified' ) );
		$GLOBALS['foogallery_migrate_engine_instance']->save_settings( array( MigratorSettings::SETTING_OVERRIDE_GALLERY_LAYOUT => 'justified' ) );
		$gallery = $this->gallery( array( 'type' => 'grid' ) );
		$settings = $gallery->plugin->get_gallery_settings( $gallery, array( 'existing' => true ) );
		$this->assertTrue( $settings['existing'] );
		$this->assertSame( array( 'width' => 150, 'height' => 100 ), $settings['justified_thumbnail_dimensions'] );
		$this->assertArrayNotHasKey( 'masonry_thumbnail_dimensions', $settings );
	}

	public function test_gallery_creation_saves_defaults_without_changing_the_source_settings(): void {
		$source = array( 'type' => 'robogrid' );
		$gallery = $this->gallery( $source );
		$gallery->create_new_migrated_object();
		$this->assertGreaterThan( 0, $gallery->migrated_id );
		$this->assertSame( 'default', get_post_meta( $gallery->migrated_id, FOOGALLERY_META_TEMPLATE, true ) );
		$this->assertSame( 'fg-left', get_post_meta( $gallery->migrated_id, FOOGALLERY_META_SETTINGS, true )['default_alignment'] );
		$this->assertSame( $source, $gallery->settings );
	}

	public function test_discovery_accepts_missing_type_and_still_excludes_youtube(): void {
		$GLOBALS['foogallery_migrate_test_post_meta'] = array( 47 => array(), 48 => array( 'rsg_gallery_type' => 'youtube' ) );
		$GLOBALS['wpdb'] = new RoboSettingsWpdb();
		$galleries = ( new Robo() )->find_galleries();
		$this->assertCount( 1, $galleries );
		$this->assertSame( 47, $galleries[0]->ID );
		$this->assertSame( 'default', $galleries[0]->plugin->get_gallery_template( $galleries[0] ) );
	}
}

class RoboSettingsWpdb {
	public $posts = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public function prepare( $sql, ...$args ) { return vsprintf( str_replace( '%s', "'%s'", $sql ), $args ); }
	public function get_var( $sql ) { return '1'; }
	public function get_results( $sql ) {
		if ( preg_match( '/post_id = (\d+)/', $sql, $match ) ) {
			return array_map( function ( $key ) { return (object) array( 'meta_key' => $key ); }, array_keys( $GLOBALS['foogallery_migrate_test_post_meta'][(int)$match[1]] ) );
		}
		return array( (object) array( 'ID' => 47, 'post_title' => 'No type' ), (object) array( 'ID' => 48, 'post_title' => 'YouTube' ) );
	}
}
