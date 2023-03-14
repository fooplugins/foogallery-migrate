<?php
/**
 * FooGallery Migrator Modula Plugin Class
 *
 * @package FooPlugins\FooGalleryMigrate
 */

namespace FooPlugins\FooGalleryMigrate\Plugins;

use FooPlugins\FooGalleryMigrate\Objects\Gallery;
use FooPlugins\FooGalleryMigrate\Objects\Image;
use FooPlugins\FooGalleryMigrate\Objects\Plugin;

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

                if ( $wpdb->get_var( 'SELECT count(*) FROM ' . $wpdb->prefix . 'posts WHERE `post_type` = "' . FM_MODULA_POST_TYPE .'"' ) ) {
                    return true;
                }
            }

            return false;
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
                $modula_galleries = $this->get_modula_galleries();

                if ( is_array( $modula_galleries ) && count( $modula_galleries ) != 0 ) {
                    foreach ( $modula_galleries as $modula_gallery ) {

                        $data = array(
                            'ID' => (int) $modula_gallery->ID,
                            'title' => $modula_gallery->post_title,
                            'foogallery_title' => $modula_gallery->post_title,
                            'data' => $modula_gallery,
                            'children' => $this->find_images( $modula_gallery->ID),
                            'settings' => get_post_meta( $modula_gallery->ID, 'modula-settings', true )
                        );
                        
                        $gallery = $this->get_gallery( $data );

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

                    $data = array(
                        'source_url' => wp_get_attachment_url( $modula_image->id ),
                        'caption' => $modula_image->description,
                        'alt' => $modula_image->alt,
                        'date' => get_the_date( 'Y-m-d', $modula_image->id ) . ' ' . get_the_time( 'H:i:s', $modula_image->id ),
                        'data' => $modula_image
                    );

                    $image = $this->get_image( $data );

                    $images[] = $image;
                    
                }                
            }
            return $images;
        }

        /**
         * Returns the gallery template.
         *
         * @param $gallery
         * @return string
         */
        function get_gallery_template( $gallery ) {
            switch ( $gallery->settings['type'] ) {
                case 'custom-grid':
                case 'grid':
                    return 'masonry';
                case 'creative-gallery':
                default:
                    return 'default';
            }
        }

        /**
         * Gets the settings for the gallery.
         *
         * @param $gallery
         * @param $settings
         * @return array
         */
        function get_gallery_settings( $gallery, $settings ) {
            $width = 0;
            $height = 0;

            $grid_image_size = $gallery->settings['grid_image_size'];
            $lightbox = $gallery->settings['lightbox'];
            $show_navigation = $gallery->settings['show_navigation'];
            $hide_title = $gallery->settings['hide_title'];
            $hide_description = $gallery->settings['hide_description'];
            $cursor = $gallery->settings['cursor'];
            $gutter = $gallery->settings['gutter'];
            $border_size = $gallery->settings['borderSize'];
            $border_radius = $gallery->settings['borderRadius'];

            if ( $grid_image_size === 'custom' ) {
                $width = $gallery->settings['grid_image_dimensions']['width'];
                $height = $gallery->settings['grid_image_dimensions']['height'];
            } else if ( $grid_image_size === 'thumbnail' ) {
                $width = get_option( 'thumbnail_size_w' );
                $height = get_option( 'thumbnail_size_h' );
            }

            $gallery_template = $this->get_gallery_template( $gallery );

            if ( $width > 0 && $height > 0 ) {
                $settings[ $gallery_template . '_thumbnail_dimensions'] = array(
                    'width'  => $width,
                    'height' => $height
                );
            }

            $settings[$gallery_template . '_lightbox'] = ($lightbox === 'fancybox') ? 'foobox' : '';
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
                    $settings[$gallery_template . '_border_size'] = 'fg-border-none';
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

                $grid_type = isset( $gallery->settings['grid_type'] ) ? $gallery->settings['grid_type'] : '';
                switch ( $grid_type ) {
                    case '':
                    case '1':
                    case 'automatic':
                        $settings[$gallery_template . '_layout'] =  'fixed';
                        break;
                    default:
                        $settings[$gallery_template . '_layout'] =  'col' . $grid_type;
                }
            }

            return $settings;
        }

        /**
         * Return all galleries object data.
         *
         * @return object Object of all galleries
         */
        private function get_modula_galleries() {
            global $wpdb;
            $gallery_table = $wpdb->prefix . FM_MODULA_TABLE_GALLERY;
 
            return $wpdb->get_results( "select * from {$gallery_table} WHERE post_type = '" . FM_MODULA_POST_TYPE . "' AND post_status = 'publish'" );
        }


        function find_albums() {
            return array();
        }             
    }
}