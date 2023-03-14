<?php
/**
 * FooGallery Migrator Envira Plugin Class
 *
 * @package FooPlugins\FooGalleryMigrate
 */

namespace FooPlugins\FooGalleryMigrate\Plugins;

use FooPlugins\FooGalleryMigrate\Objects\Gallery;
use FooPlugins\FooGalleryMigrate\Objects\Image;
use FooPlugins\FooGalleryMigrate\Objects\Plugin;

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
                $envira_galleries = $this->get_nextgen_galleries();

                if ( count( $envira_galleries ) != 0 ) {
                    foreach ( $envira_galleries as $envira_gallery ) {
                        $data = array(
                            'ID' => $envira_gallery->ID,
                            'title' => $envira_gallery->post_title,
                            'foogallery_title' => $envira_gallery->post_title,
                            'data' => $envira_gallery,
                            'children' => $this->find_images( $envira_gallery->ID ),
                            'settings' => get_post_meta( $envira_gallery->ID, '_eg_gallery_data', true )
                        );
                        
                        $gallery = $this->get_gallery($data);

                        $galleries[] = $gallery;
                    }
                }
            }

            return $galleries;
        }

        /**
         * Returns the gallery template.
         *
         * @param $gallery
         * @return string
         */
        function get_gallery_template( $gallery ) {
            switch ( $gallery->settings['config']['gallery_theme'] ) {
                case 'base':
                    return 'default';
            }
            return 'default';
        }

        /**
         * Gets the settings for the gallery.
         *
         * @param $gallery
         * @param $settings
         * @return array
         */
        function get_gallery_settings( $gallery, $settings ) {
            $width = $gallery->settings['config']['crop_width'];
            $height = $gallery->settings['config']['crop_height'];

            $gallery_template = $this->get_gallery_template( $gallery );

            if ( $width > 0 && $height > 0 ) {
                $settings[ $gallery_template . '_thumbnail_dimensions'] = array(
                    'width'  => $width,
                    'height' => $height
                );
            }

            return $settings;
        }

         /**
         * Return all galleries object data.
         *
         * @return object Object of all galleries
         */
        private function get_nextgen_galleries() {
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
                    $data = array(
                        'source_url' => wp_get_attachment_url( $envira_obj->id ),
                        'caption' => $envira_obj->caption,
                        'alt' => $envira_obj->alt,
                        'date' => get_the_date( 'Y-m-d', $envira_obj->id ) . ' ' . get_the_time( 'H:i:s', $envira_obj->id ),
                        'data' => $envira_obj
                    );

                    $image = $this->get_image($data);
                    $images[] = $image;
                }                
            }
            return $images;
        }


        function find_albums() {
            return array();
        }        
    }
}