<?php
/**
 * FooGallery Migrator Modula Plugin Class
 *
 * @package FooPlugins\FooGalleryMigrate
 */

namespace FooPlugins\FooGalleryMigrate\Plugins;

use FooPlugins\FooGalleryMigrate\Gallery;
use FooPlugins\FooGalleryMigrate\Image;
use FooPlugins\FooGalleryMigrate\Plugin;

define( 'FM_MODULA_TABLE_GALLERY', 'posts' );
define( 'FM_MODULA_POST_TYPE', 'modula-gallery' );

if ( ! class_exists( 'FooPlugins\FooGalleryMigrate\Plugins\Modula' ) ) {

    /**
     * Class Modula
     *
     * @package FooPlugins\FooGalleryMigrate
     */
    class Modula extends Plugin {

        /**
         * Name of the plugin.
         *
         * @return string
         */
        function name() {
            return 'Modula';
        }

        /**
         * Detects data from the gallery plugin.
         *
         * @return bool
         */
        function detect() {
            if ( class_exists( 'Modula' ) ) {
                // Modula plugin is activated. Get out early!
                return true;
            } else {
                // Do some checks even if the plugin is not activated.
                global $wpdb;

                if ( !$wpdb->query( 'SELECT count(*) FROM ' . $wpdb->prefix . 'posts WHERE `post_type` = "' . FM_MODULA_POST_TYPE .'"' ) ) {
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
                $modula_galleries = $this->get_galleries();

                if ( count( $modula_galleries ) != 0 ) {
                    foreach ( $modula_galleries as $modula_gallery ) {
                        $gallery = new Gallery( $this );
                        $gallery->ID = (int) $modula_gallery->ID;
                        $gallery->title = $modula_gallery->post_title;
                        $gallery->data = $modula_gallery;
                        $gallery->images = $this->find_images( $gallery->ID );
                        $gallery->image_count = count( $gallery->images );                       
                        $galleries[] = $gallery;    
                    }
                }
            
            }

            return $galleries;
        }

        /**
         * Find all images by gallery id
         * @param $gallery_id ID of the gallery
         * @return bool
         */
        private function find_images( $gallery_id ) {
            $images = array();

            $modula_images = get_post_meta( $gallery_id, 'modula-images', true );
            if ( is_array( $modula_images ) && !empty( $modula_images ) ) {
                foreach ( $modula_images as $modula_image ) {
                    $modula_image = ( object ) $modula_image;
                    $image = new Image();
                    $image_attributes = wp_get_attachment_image_src( $modula_image->id );
                    if ( is_array( $image_attributes ) && !empty ( $image_attributes ) ) {
                        //$image->attachment_id = $modula_image['id'];
                        $image->source_url = $image_attributes[0];
                    }
                    $image->caption = $modula_image->description;
                    $image->alt = $modula_image->alt;
                    $image->date = get_the_date( 'Y-m-d', $modula_image->id ) . ' ' . get_the_time( 'H:i:s', $modula_image->id );
                    $image->data = $modula_image;
                    $images[] = $image;
                    
                }                
            }
            return $images;
        }
            
        /**
         * Migrate gallery settings to foogalery.
         * @param $gallery Object of gallery
         * @return NULL
         */
        function migrate_settings( $gallery ) {
            //Migrate settings from the Modula gallery to the FooGallery.
            $width = 0;
            $height = 0;
            // Get modula gallery settings from post meta
            $modula_settings = get_post_meta( $gallery->ID, 'modula-settings', true );

            $gutter = $modula_settings['gutter'];
            $grid_image_size = $modula_settings['grid_image_size'];
            $lightbox = $modula_settings['lightbox'];
            $show_navigation = $modula_settings['show_navigation'];
            $hide_title = $modula_settings['hide_title'];
            $hide_description = $modula_settings['hide_description'];
            $cursor = $modula_settings['cursor'];

            if ( $grid_image_size == 'custom' ) {
                $width = $modula_settings['grid_image_dimensions']['width'];
                $height = $modula_settings['grid_image_dimensions']['height'];
            }

            //Set the FooGallery gallery template, to be closest to the Modula gallery layout.
            //$gallery_template_closest_to_modula_gallery_layout = 'default';
            //add_post_meta( $gallery->foogallery_id, FOOGALLERY_META_TEMPLATE, $gallery_template_closest_to_modula_gallery_layout, true );

            //Set the FooGallery gallery settings, based on the Modula gallery settings.
            $settings = array();

            if ( $width > 0 && $height > 0 ) {
                $settings['default_thumbnail_dimensions'] = array(
                    'width'  => $width,
                    'height' => $height
                );
            }

            if ( $lightbox == 'fancybox' ) {
                $settings['default_lightbox'] = 'foogallery';
            } else {
                $settings['default_lightbox'] = 'none';
            }

            if ( $show_navigation == '1' ) {
                $settings['default_lightbox_show_nav_buttons'] = 'yes';
            } else {
                $settings['default_lightbox_show_nav_buttons'] = 'no';
            }

            if ( $hide_title == '1' ) {
                $settings['default_caption_title_source'] = 'title';
            } else {
                $settings['default_caption_title_source'] = 'none';
            }

            if ( $hide_description == '1' ) {
                $settings['default_caption_desc_source'] = 'caption';
            } else {
                $settings['default_caption_desc_source'] = 'none';
            }

            if ( $cursor == 'zoom-in' ) {
                $settings['default_hover_effect_icon'] = 'fg-hover-zoom';
            } else {
                $settings['default_hover_effect_icon'] = '';
            }
            
            add_post_meta( $gallery->foogallery_id, FOOGALLERY_META_SETTINGS, $settings, true );
        }

        /**
         * Return all galleries object data.
         *
         * @return object Object of all galleries
         */
        private function get_galleries() {
            global $wpdb;
            $gallery_table = $wpdb->prefix . FM_MODULA_TABLE_GALLERY;
 
            return $wpdb->get_results( "select * from {$gallery_table} WHERE post_type = '" . FM_MODULA_POST_TYPE . "' AND post_status = 'publish'" );
        }
//
//        abstract function get_albums();
//
//        abstract function get_content();
    }
}