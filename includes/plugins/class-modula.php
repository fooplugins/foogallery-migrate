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

                if ( is_array( $modula_galleries ) && count( $modula_galleries ) != 0 ) {
                    foreach ( $modula_galleries as $modula_gallery ) {
                        $gallery = new Gallery( $this );
                        $gallery->ID = (int) $modula_gallery->ID;
                        $gallery->title = $modula_gallery->post_title;
                        $gallery->data = $modula_gallery;
                        $gallery->images = $this->find_images( $gallery->ID );
                        $gallery->image_count = count( $gallery->images );
                        $gallery->settings = get_post_meta( $gallery->ID, 'modula-settings', true );
                        $galleries[] = $gallery;    
                    }
                }
            
            }

            return $galleries;
        }

        /**
         * Find all images by gallery id
         * @param $gallery_id int ID of the gallery
         * @return array
         */
        private function find_images( $gallery_id ) {
            $images = array();

            $modula_images = get_post_meta( $gallery_id, 'modula-images', true );
            if ( is_array( $modula_images ) && !empty( $modula_images ) ) {
                foreach ( $modula_images as $modula_image ) {
                    $modula_image = ( object ) $modula_image;
                    $image = new Image();
                    //$image->attachment_id = $modula_image->id;
                    $image_attributes = wp_get_attachment_image_src( $modula_image->id );
                    if ( is_array( $image_attributes ) && !empty ( $image_attributes ) ) {
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
         * Migrate gallery settings to FooGallery.
         * @param $gallery Object of gallery
         * @return NULL
         */
        function migrate_settings( $gallery ) {
            //Migrate settings from the Modula gallery to the FooGallery.
            $width = 0;
            $height = 0;
            // Get modula gallery settings from post meta
            $modula_settings = get_post_meta( $gallery->ID, 'modula-settings', true );

            $type = $modula_settings['type'];
            switch ( $type ) {
                case 'custom-grid':
                case 'grid':
                    $gallery_template = 'masonry';
                    break;
                case 'creative-gallery':
                default:
                    $gallery_template = 'default';
            }

            //Set the FooGallery gallery template, to be closest to the Modula gallery layout.
            add_post_meta( $gallery->foogallery_id, FOOGALLERY_META_TEMPLATE, $gallery_template, true );

            $grid_image_size = $modula_settings['grid_image_size'];
            $lightbox = $modula_settings['lightbox'];
            $show_navigation = $modula_settings['show_navigation'];
            $hide_title = $modula_settings['hide_title'];
            $hide_description = $modula_settings['hide_description'];
            $cursor = $modula_settings['cursor'];
            $gutter = $modula_settings['gutter'];
            $border_size = $modula_settings['borderSize'];
            $border_radius = $modula_settings['borderRadius'];

            if ( $grid_image_size === 'custom' ) {
                $width = $modula_settings['grid_image_dimensions']['width'];
                $height = $modula_settings['grid_image_dimensions']['height'];
            } else if ( $grid_image_size === 'thumbnail' ) {
                $width = get_option( 'thumbnail_size_w' );
                $height = get_option( 'thumbnail_size_h' );
            }

            //Set the FooGallery gallery settings, based on the Modula gallery settings.
            $settings = array();

            if ( $width > 0 && $height > 0 ) {
                $settings[ $gallery_template . '_thumbnail_dimensions'] = array(
                    'width'  => $width,
                    'height' => $height
                );
            }

            $settings[$gallery_template . '_lightbox'] =  ($lightbox === 'fancybox') ? 'foobox' : '';
            $settings[$gallery_template . '_lightbox_show_nav_buttons'] = ( $show_navigation === '1' ) ? 'yes' : 'no';
            $settings[$gallery_template . '_caption_title_source'] = ( $hide_title === '1' ) ? 'none' : 'title';
            $settings[$gallery_template . '_caption_desc_source'] = ( $hide_description === '1' ) ? 'none' : 'caption';
            $settings[$gallery_template . '_hover_effect_icon'] = ( $cursor === 'zoom-in' ) ? 'fg-hover-zoom' : '';

            switch ( $border_size ) {
                case '1':
                    $settings[$gallery_template . '_border_size'] = 'fg-border-thin';
                    break;
                case '2':
                case '3':
                case '4':
                case '5':
                    $settings[$gallery_template . '_border_size'] = 'fg-border-medium';
                    break;
                case '6':
                case '7':
                case '8':
                case '9':
                case '10':
                    $settings[$gallery_template . '_border_size'] = 'fg-border-thick';
                    break;
                default :
                    $settings[$gallery_template . '_spacing'] = 'fg-border-none';
            }

            switch ( $border_radius ) {
                case '1':
                    $settings[$gallery_template . '_rounded_corners'] = 'fg-round-small';
                    break;
                case '2':
                case '3':
                case '4':
                case '5':
                    $settings[$gallery_template . '_rounded_corners'] = 'fg-round-medium';
                    break;
                case '6':
                case '7':
                case '8':
                case '9':
                case '10':
                    $settings[$gallery_template . '_rounded_corners'] = 'fg-round-large';
                    break;
                default :
                    $settings[$gallery_template . '_rounded_corners'] = '';
            }

            //Set settings for specific gallery templates
            if ( $gallery_template === 'default' ) {
                switch ( $gutter ) {
                    case '1':
                    case '2':
                    case '3':
                    case '4':
                    case '5':
                        $settings[$gallery_template . '_spacing'] = 'fg-gutter-5';
                        break;
                    case '6':
                    case '7':
                    case '8':
                    case '9':
                    case '10':
                        $settings[$gallery_template . '_spacing'] = 'fg-gutter-10';
                        break;
                    default :
                        $settings[$gallery_template . '_spacing'] = 'fg-gutter-0';
                }

            } else if ( $gallery_template === 'masonry' ) {

                $settings[$gallery_template . '_gutter_width'] =  $gutter;

                $grid_type = isset( $modula_settings['grid_type'] ) ? $modula_settings['grid_type'] : '';
                switch ( $grid_type ) {
                    case '1':
                    case 'automatic':
                        $settings[$gallery_template . '_layout'] =  'fixed';
                        break;
                    default:
                        $settings[$gallery_template . '_layout'] =  'col' . $grid_type;
                }
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