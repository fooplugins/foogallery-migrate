<?php
/**
 * FooGallery Migrator Robo Plugin Class
 *
 * @package FooPlugins\FooGalleryMigrate
 */

namespace FooPlugins\FooGalleryMigrate\Plugins;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use FooPlugins\FooGalleryMigrate\Objects\Gallery;
use FooPlugins\FooGalleryMigrate\Objects\Image;
use FooPlugins\FooGalleryMigrate\Objects\Album;
use FooPlugins\FooGalleryMigrate\Objects\Plugin;

if( ! class_exists( 'FooPlugins\FooGalleryMigrate\Plugins\Robo' ) ) {

    /**
     * Class Robo
     *
     * @package FooPlugins\FooGalleryMigrate
     */    
    class Robo extends Plugin {

        /**
         * Name of the plugin.
         *
         * @return string
         */        
        function name() {
            return 'Robo';
        }

        /**
         * Detects data from the gallery plugin.
         *
         * @return bool
         */
        function detect() {
            if ( class_exists( 'Robo_Gallery_Core' ) ) {
                return true;
            } else {
                // Do some checks even if the plugin is not activated.
                global $wpdb;

                return (bool) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT 1 AS present FROM {$wpdb->posts} WHERE post_type = %s LIMIT 1",
                        'robo_gallery_table'
                    )
                );
            }
        }

        /**
         * Find all galleries
         *
         * @return array
         */
        function find_galleries() {
            global $wpdb;
            $galleries = array();
            if ( $this->detect() ) {

                // Get galleries
                $robo_galleries = $this->get_robo_galleries();

                if ( count( $robo_galleries ) > 0 ) {
                    foreach ( $robo_galleries as $robo_gallery ) {

                        $settings = array();
                        $get_all_meta = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $wpdb->postmeta WHERE post_id = %d", $robo_gallery->ID ) );
                        foreach ( $get_all_meta as $get_all_meta_data ) {
                            $current_meta_data = get_post_meta( $robo_gallery->ID, $get_all_meta_data->meta_key, true );
                            $new_key_for_setting = str_replace("-", "_", $get_all_meta_data->meta_key );
                            if ( $new_key_for_setting === 'rsg_gallery_type' ) {
                                $new_key_for_setting = 'type';
                            }
                            $settings[$new_key_for_setting] = $current_meta_data; 
                        }
                       
                        $data = array(
                            'ID' => $robo_gallery->ID,
                            'title' => $robo_gallery->post_title,
                            'foogallery_title' => $robo_gallery->post_title,
                            'data' => $robo_gallery,
                            'children' => $this->find_images( $robo_gallery->ID ),
                            'settings' => $settings
                        );
                        
                        $gallery = $this->get_gallery( $data );

                        //TODO : we should also import youtube galleries in future.
                        if ( ! isset( $settings['type'] ) || $settings['type'] !== 'youtube' ) {
                            $galleries[] = $gallery;
                        }                        
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
            $source = $this->get_source_settings( $gallery );
            switch ($source['type']) {
                case 'masonry':
                case 'grid':
                case 'mosaic':
                case 'polaroid':
                    return 'masonry';
                case 'slider':
                    return 'carousel';
                case 'youtube':
                default:
                    return 'default';
            }
        }        

        /** Missing legacy fields must not emit warnings or change source data. */
        private function get_source_settings( $gallery ) {
            $source = isset( $gallery->settings ) && is_array( $gallery->settings ) ? $gallery->settings : array();
            $defaults = array(
                'type' => '',
                'rsg_thumb_size_options' => array(),
                'rsg_width_size' => array(),
                'rsg_border_options' => array(),
                'rsg_zoomIcon' => array(),
                'rsg_showTitle' => array(),
                'rsg_radius' => 0,
                'rsg_lightboxTitle' => '',
                'rsg_align' => 'left',
                'rsg_polaroidAlign' => 'left',
                'rsg_horizontalSpaceBetweenBoxes' => 0,
                'rsg_content' => 0,
                'rsg_content_source' => 'title',
            );
            foreach ( $defaults as $key => $default ) {
                if ( ! isset( $source[$key] ) || ( is_array( $default ) ? ! is_array( $source[$key] ) : ! is_scalar( $source[$key] ) ) ) {
                    $source[$key] = $default;
                }
            }
            return $source;
        }

        /**
         * Gets the settings for the gallery.
         *
         * @param $gallery
         * @param $settings
         * @return array
         */
        function get_gallery_settings( $gallery, $settings ) {
            $source = $this->get_source_settings( $gallery );
            $width = get_option( 'thumbnail_size_w' );
            $height = get_option( 'thumbnail_size_h' );

            if( $source['type'] != 'slider' ) {

                if( ! empty( $source['rsg_thumb_size_options'] ) ) {

                    $size = $source['rsg_thumb_size_options'];
                    $width = isset( $size['width'] ) && is_numeric( $size['width'] ) ? $size['width'] : $width;
                    $height = isset( $size['height'] ) && is_numeric( $size['height'] ) ? $size['height'] : $height;

                } else if( isset( $source['rsg_width_size']['widthType'] ) && $source['rsg_width_size']['widthType'] == 1 ) {

                    $size = $source['rsg_width_size'];
                    $width = isset( $size['width'] ) && is_numeric( $size['width'] ) ? $size['width'] : $width;
                }
            }

            $border_radius = $source['rsg_radius'];
            $border = $source['rsg_border_options'];
            $border_size = isset( $border['width'] ) && is_numeric( $border['width'] ) ? $border['width'] : 0;
            $cursor = isset( $source['rsg_zoomIcon']['enabled'] ) && $source['rsg_zoomIcon']['enabled'];
            $hide_title = isset( $source['rsg_showTitle']['enabled'] ) && $source['rsg_showTitle']['enabled'];
            $lightbox = $source['rsg_lightboxTitle'];
            $align = 'left';

            if( $source['type'] == 'grid' ) {

                $align = $source['rsg_align'];

            } else if( $source['type'] == 'masonry' || $source['type'] == 'mosaic' || $source['type'] == 'polaroid' || $source['type'] == 'youtube' ) {

                $align = $source['rsg_polaroidAlign'];

            }

            $gutter = $source['rsg_horizontalSpaceBetweenBoxes'];

            $gallery_template = $this->get_migration_gallery_template( $gallery );

            if ( $width > 0 && $height > 0 ) {
                $settings[ $gallery_template . '_thumbnail_dimensions'] = array(
                    'width'  => $width,
                    'height' => $height
                );
            }

            if( $source['type'] == 'slider' ) {

                $content_type = $source['rsg_content'];

                if( $content_type == 1 ) {

                    $rsg_content_source = $source['rsg_content_source'];
                    
                    switch($rsg_content_source) {

                        case 'title':
                        $settings[$gallery_template . '_caption_title_source'] = 'title';    
                        break;
                        case 'caption':
                        $settings[$gallery_template . '_caption_title_source'] = 'caption';    
                        break;
                        case 'description':
                        $settings[$gallery_template . '_caption_title_source'] = 'desc';    
                        break;                        
                        default:
                        $settings[$gallery_template . '_caption_title_source'] = 'title';    

                    }
                    
                } 

            } else {

                $settings[$gallery_template . '_hover_effect_icon'] = ( $cursor === 1 ) ? 'fg-hover-zoom' : '';
                $settings[$gallery_template . '_caption_title_source'] = ( $hide_title === '1' ) ? 'title' : 'none';
                $settings[$gallery_template . '_lightbox'] = ($lightbox === '1') ? 'foobox' : '';

                switch($align) {
                    case 'left':
                    $settings[$gallery_template . '_alignment'] = 'fg-left';
                    break;
                    case 'center':
                    $settings[$gallery_template . '_alignment'] = 'fg-center';
                    break;
                    case 'right':
                    $settings[$gallery_template . '_alignment'] = 'fg-right';                                
                    break;
                    default :
                    $settings[$gallery_template . '_alignment'] = 'fg-left';                                
                }

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
                        case '11':
                        case '12':
                        case '13':
                        case '14':
                        case '15':
                            $settings[$gallery_template . '_spacing'] = 'fg-gutter-15';
                            break;
                        case '16':
                        case '17':
                        case '18':
                        case '19':
                        case '20':
                            $settings[$gallery_template . '_spacing'] = 'fg-gutter-20';
                            break;
                        case '21':
                        case '22':
                        case '23':
                        case '24':
                        case '25':
                            $settings[$gallery_template . '_spacing'] = 'fg-gutter-25';
                            break;                                                                        
                        default :
                            $settings[$gallery_template . '_spacing'] = 'fg-gutter-0';
                    }

                } else if ( $gallery_template === 'masonry' ) {
                    $settings[$gallery_template . '_gutter_width'] =  $gutter;
                    $settings[$gallery_template . '_layout'] =  'fixed';                                    
                }                      

            }

            return $settings;
        }

         /**
         * Return all galleries object data.
         *
         * @return object Object of all galleries
         */
        private function get_robo_galleries() {
            global $wpdb;
            return $wpdb->get_results( $wpdb->prepare( "select * from $wpdb->posts WHERE post_type = %s AND post_status = %s", 'robo_gallery_table', 'publish' ) );
        }

        /**
         * Find all images by gallery id
         * @param $gallery_id ID of the gallery
         * @return array
         */
        private function find_images( $gallery_id ) {
            $images = array();

            $robo_images = get_post_meta( $gallery_id, 'rsg_galleryImages', true );
            if ( is_array( $robo_images ) && !empty( $robo_images ) ) {
                foreach ( $robo_images as $attachment_id) {                

                    $data = array(
                        'source_url' => wp_get_attachment_url( $attachment_id ),
                        'caption' => "",
                        'alt' => "",
                        'date' => get_the_date( 'Y-m-d', $attachment_id ) . ' ' . get_the_time( 'H:i:s', $attachment_id ),
                        'data' => ""
                    );

                    $image = $this->get_image( $data );

                    $images[] = $image;
                }                
            }
            return $images;
        }


        function find_albums() {
            return array();
        }        
        /**
         * Returns shortcode regex patterns for Robo Gallery.
         *
         * @return array Array of regex patterns
         */
        function get_shortcode_patterns() {
            return array(
                '/\[robo-gallery\s+id=["\']?(\d+)["\']?\s*\]/i',
                '/\[robo_gallery\s+id=["\']?(\d+)["\']?\s*\]/i',
            );
        }

        /**
         * Returns Gutenberg block patterns for Robo Gallery.
         *
         * @return array Associative array of block names
         */
        function get_block_patterns() {
            return array(
                'robo-gallery/gallery' => array(),
                'robo/block-robo-gallery' => array(),
            );
        }   
    }
}
