<?php
/**
 * FooGallery Migrator Robo Plugin Class
 *
 * @package FooPlugins\FooGalleryMigrate
 */

namespace FooPlugins\FooGalleryMigrate\Plugins;

use FooPlugins\FooGalleryMigrate\Objects\Gallery;
use FooPlugins\FooGalleryMigrate\Objects\Image;
use FooPlugins\FooGalleryMigrate\Objects\Album;
use FooPlugins\FooGalleryMigrate\Objects\Plugin;

define( 'ROBO_TABLE_GALLERY', 'posts' );
define( 'ROBO_POST_TYPE', 'robo_gallery_table' );

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

                if ( !$wpdb->get_var( 'SELECT count(*) FROM ' . $wpdb->prefix . 'posts WHERE `post_type` = "robo_gallery_table"' ) ) {
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
            global $wpdb;
            $galleries = array();
            if ( $this->detect() ) {

                // Get galleries
                $robo_galleries = $this->get_galleries();   
                $meta_table = $wpdb->prefix . "postmeta";            

                if ( count( $robo_galleries ) != 0 ) {
                    foreach ( $robo_galleries as $robo_gallery ) {
                        $gallery = new Gallery( $this );
                        $gallery->ID = $robo_gallery->ID;
                        $gallery->title = $robo_gallery->post_title;
                        $gallery->data = $robo_gallery;
                        $gallery->children = $this->find_images( $gallery->ID );
                        // To do fetch multiple data from other source and assign to setting member variable
                        $get_all_meta = $wpdb->get_results( "SELECT * FROM $meta_table WHERE post_id = $gallery->ID" );
                        foreach( $get_all_meta as $get_all_meta_data ) {
                            $current_meta_data = get_post_meta($gallery->ID, $get_all_meta_data->meta_key, true);
                            $new_key_for_setting = str_replace("-", "_", $get_all_meta_data->meta_key);
                            if( $new_key_for_setting == 'rsg_gallery_type' ) {
                                $new_key_for_setting = 'type';
                            }
                            $gallery->settings[$new_key_for_setting] = $current_meta_data; 
                        }
                        if( $gallery->settings['type'] != 'youtube' ) {
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
            switch ( $gallery->settings['type'] ) {
                case 'masonry':
                case 'grid':
                case 'mosaic':
                case 'polaroid':                
                    return 'masonry';
                case 'slider':
                case 'youtube':
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

            if( $gallery->settings['type'] == 'slider' ) {
                
                    $width = get_option( 'thumbnail_size_w' );
                    $height = get_option( 'thumbnail_size_h' );

            } else {

                if( ! empty( $gallery->settings['rsg_thumb_size_options'] ) ) {

                    $width = $gallery->settings['rsg_thumb_size_options']['width'];
                    $height = $gallery->settings['rsg_thumb_size_options']['height'];                

                } else if( $gallery->settings['rsg_width_size']['widthType'] == 1 ) {

                    $width = $gallery->settings['rsg_width_size']['width'];
                    $height = get_option( 'thumbnail_size_h' ); 

                } else {

                    $width = get_option( 'thumbnail_size_w' ); 
                    $height = get_option( 'thumbnail_size_h' ); 

                }

            }

            $border_radius = $gallery->settings['rsg_radius'];
            $border_size = $gallery->settings['rsg_border_options']['width'];
            $cursor = $gallery->settings['rsg_zoomIcon']['enabled'];
            $hide_title = $gallery->settings['rsg_showTitle']['enabled'];
            $lightbox = $gallery->settings['rsg_lightboxTitle'];

            if( $gallery->settings['type'] == 'grid' ) {

                $align = $gallery->settings['rsg_align'];

            } else if( $gallery->settings['type'] == 'masonry' || $gallery->settings['type'] == 'mosaic' || $gallery->settings['type'] == 'polaroid' || $gallery->settings['type'] == 'youtube' ) {

                $align = $gallery->settings['rsg_polaroidAlign'];

            }


            
            $gutter = $gallery->settings['rsg_horizontalSpaceBetweenBoxes'];

            $gallery_template = $this->get_gallery_template( $gallery );            

            if ( $width > 0 && $height > 0 ) {
                $settings[ $gallery_template . '_thumbnail_dimensions'] = array(
                    'width'  => $width,
                    'height' => $height
                );
            }

            if( $gallery->settings['type'] == 'slider' ) {

                $content_type = $gallery->settings['rsg_content'];

                if( $content_type == 1 ) {

                    $rsg_content_source = $gallery->settings['rsg_content_source'];                    
                    
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
        private function get_galleries() {
            global $wpdb;
            $gallery_table = $wpdb->prefix . ROBO_TABLE_GALLERY;
 
            return $wpdb->get_results( "select * from {$gallery_table} WHERE post_type = '" . ROBO_POST_TYPE . "' AND post_status = 'publish'" );
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
                    $image = new Image();
                    $image_attributes = wp_get_attachment_image_src( $attachment_id );
                    if ( is_array( $image_attributes ) && !empty( $image_attributes ) ) {
                        // $image->migrated_id = $attachment_id;
                        $image->source_url = $image_attributes[0];
                    }
                    $image->caption = "";
                    $image->alt = "";
                    $image->date = get_the_date( 'Y-m-d', $attachment_id ) . ' ' . get_the_time( 'H:i:s', $attachment_id );
                    $images[] = $image;
                }                
            }
            return $images;
        }


        function find_albums() {
            return array();
        }        
//
//        abstract function get_albums();
//
//        abstract function get_content();
    }
}