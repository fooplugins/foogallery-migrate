<?php
/**
 * FooGallery Migrator Envira Plugin Class
 *
 * @package FooPlugins\FooGalleryMigrate
 */

namespace FooPlugins\FooGalleryMigrate\Plugins;

use FooPlugins\FooGalleryMigrate\Gallery;
use FooPlugins\FooGalleryMigrate\Image;
use FooPlugins\FooGalleryMigrate\Plugin;

if ( ! class_exists( 'FooPlugins\FooGalleryMigrate\Plugins\Envira' ) ) {

    /**
     * Class Envira
     *
     * @package FooPlugins\FooGalleryMigrate
     */
    class Envira extends Plugin {

        /**
         * Name of the plugin.
         *
         * @return string
         */
        function name() {
            return 'Envira';
        }

        /**
         * Detects data from the gallery plugin.
         *
         * @return bool
         */
        function detect() {
            return class_exists( 'Envira_Gallery_Lite' );
        }

        function find_galleries() {
            // Get galleries
            $instance = \Envira_Gallery_Lite::get_instance();
            $envira_galleries = $instance->get_galleries( false, true, '' );
            $galleries = array();

            if ( count( $envira_galleries ) != 0 ) {
                foreach ( $envira_galleries as $envira_gallery ) {
                    $gallery = new Gallery( $this );
                    $gallery->ID = $envira_gallery['id'];
                    $gallery->title = $envira_gallery['config']['title'];
                    $gallery->data = $envira_gallery;
                    $gallery->images = array();
                    if ( is_array( $envira_gallery['gallery'] ) ) {
                        foreach ( $envira_gallery['gallery'] as $envira_image ) {
                            $image = new Image();
                            $image->data = $envira_image;
                            $image->source_url = $envira_image['src'];
                            $image->caption = $envira_image['caption'];
                            $image->alt = $envira_image['alt'];
                            $gallery->images[] = $image;
                        }
                    }
                    $gallery->image_count = count( $gallery->images );
                    $galleries[] = $gallery;
                }
            }

            return $galleries;
        }

        function migrate_settings( $gallery ) {
            //Migrate settings from the Envira gallery to the FooGallery.

            //Set the FooGallery gallery template, to be closest to the Envira gallery layout.
            //$gallery_template_closest_to_envira_gallery_layout = 'default';
            //add_post_meta( $gallery->foogallery_id, FOOGALLERY_META_TEMPLATE, $gallery_template_closest_to_envira_gallery_layout, true );

            //Set the FooGallery gallery settings, based on the envira gallery settings.
            //$settings = array();
            //add_post_meta( $gallery->foogallery_id, FOOGALLERY_META_SETTINGS, $settings, true );
        }

//        abstract function get_galleries();
//
//        abstract function get_albums();
//
//        abstract function get_content();
    }
}