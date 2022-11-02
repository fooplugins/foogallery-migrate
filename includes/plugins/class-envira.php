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
use stdClass;

define( 'FM_ENVIRA_TABLE_GALLERY', 'posts' );
define( 'FM_ENVIRA_POST_TYPE', 'envira' );

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
            if ( class_exists( 'Envira_Gallery_Lite' ) ) {
                return true;
            } else {
                // Do some checks even if the plugin is not activated.
                global $wpdb;

                if ( !$wpdb->get_var( 'SELECT count(*) FROM ' . $wpdb->prefix . 'posts WHERE `post_type` = "envira"' ) ) {
                    return false;
                } else {
                    return true;
                }
            }
        }

        /**
         * Find all galleries
         *
         * @return array
         */
        function find_galleries() {
            $galleries = array();
            if ( $this->detect() ) {

                // Get galleries
                $envira_galleries = $this->get_galleries();               

                if ( count( $envira_galleries ) != 0 ) {
                    foreach ( $envira_galleries as $envira_gallery ) {
                        $gallery = new Gallery( $this );
                        $gallery->ID = $envira_gallery->ID;
                        $gallery->title = $envira_gallery->post_title;
                        $gallery->data = $envira_gallery;
                        $gallery->images = $this->find_images( $gallery->ID );
                        $gallery->image_count = count( $gallery->images );
                        $galleries[] = $gallery;
                    }
                }
            
            }

            return $galleries;
        }

        /**
         * Migrate gallery settings to foogalery.
         * @param $gallery Object of gallery
         * @return NULL
         */
        function migrate_settings( $gallery ) {
            //Migrate settings from the Envira gallery to the FooGallery.
            // Get envira gallery settings from post meta
            $eg_gallery_data = get_post_meta( $gallery->ID, '_eg_gallery_data', true );
            $width = $eg_gallery_data['config']['crop_width'];
            $height = $eg_gallery_data['config']['crop_height'];

            //Set the FooGallery gallery template, to be closest to the Envira gallery layout.
            //$gallery_template_closest_to_envira_gallery_layout = 'default';
            //add_post_meta( $gallery->foogallery_id, FOOGALLERY_META_TEMPLATE, $gallery_template_closest_to_envira_gallery_layout, true );

            //Set the FooGallery gallery settings, based on the envira gallery settings.
            $settings = array(
                'default_thumbnail_dimensions' => array(
                    'width'  => $width,
                    'height' => $height
                )
            );
            add_post_meta( $gallery->foogallery_id, FOOGALLERY_META_SETTINGS, $settings, true );
        }

         /**
         * Return all galleries object data.
         *
         * @return object Object of all galleries
         */
        private function get_galleries() {
            global $wpdb;
            $gallery_table = $wpdb->prefix . FM_ENVIRA_TABLE_GALLERY;
 
            return $wpdb->get_results( "select * from {$gallery_table} WHERE post_type = '" . FM_ENVIRA_POST_TYPE . "' AND post_status = 'publish'" );
        }

        /**
         * Find all images by gallery id
         * @param $gallery_id ID of the gallery
         * @return bool
         */
        private function find_images( $gallery_id ) {
            $images = array();

            $envira_images = get_post_meta( $gallery_id, '_eg_gallery_data', true );
            if ( is_array( $envira_images ) && !empty( $envira_images ) ) {
                foreach ( $envira_images['gallery'] as $attachment_id => $envira_image ) {
                    $envira_obj = ( object ) $envira_image;
                    $envira_obj->id = $attachment_id;                   
                    $image = new Image();
                    $image_attributes = wp_get_attachment_image_src( $envira_obj->id);
                    if ( is_array( $image_attributes ) && !empty ( $image_attributes ) ) {
                        //$image->attachment_id = $envira_image['id'];
                        $image->source_url = $image_attributes[0];
                    }
                    $image->caption = $envira_obj->caption;
                    $image->alt = $envira_obj->alt;
                    $image->date = get_the_date( 'Y-m-d', $envira_obj->id ) . ' ' . get_the_time( 'H:i:s', $envira_obj->id );
                    $image->data = $envira_obj;
                    $images[] = $image;
                }                
            }
            return $images;
        }
//
//        abstract function get_albums();
//
//        abstract function get_content();
    }
}