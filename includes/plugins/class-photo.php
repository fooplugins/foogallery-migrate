<?php
/**
 * FooGallery Migrator 10Web Plugin Class
 *
 * @package FooPlugins\FooGalleryMigrate
 */

namespace FooPlugins\FooGalleryMigrate\Plugins;

use FooPlugins\FooGalleryMigrate\Objects\Gallery;
use FooPlugins\FooGalleryMigrate\Objects\Image;
use FooPlugins\FooGalleryMigrate\Objects\Album;
use FooPlugins\FooGalleryMigrate\Objects\Plugin;

define( 'FM_PHOTO_TABLE_GALLERY', 'bwg_gallery' );
define( 'FM_PHOTO_POST_TYPE', 'bwg_gallery' );
define( 'FM_PHOTO_IMAGE_TABLE', 'bwg_image' );
define( 'FM_PHOTO_ALBUM_TABLE', 'bwg_album' );

if( ! class_exists( 'FooPlugins\FooGalleryMigrate\Plugins\Photo' ) ) {

    /**
     * Class Photo
     *
     * @package FooPlugins\FooGalleryMigrate
     */    
    class Photo extends Plugin {

        const FM_PHOTO_TABLE_GALLERY  = 'bwg_gallery';
        const FM_PHOTO_IMAGE_TABLE = 'bwg_image';
        const FM_PHOTO_ALBUM_TABLE   = 'bwg_album';
        const FM_PHOTO_ALBUM_GALLERY_TABLE = 'bwg_album_gallery';

        /**
         * Name of the plugin.
         *
         * @return string
         */        
        function name() {
            return '10Web';
        }

        /**
         * Detects data from the gallery plugin.
         *
         * @return bool
         */
        function detect() {
            if ( class_exists( 'BWG' ) ) {
                return true;
            } else {
                // Do some checks even if the plugin is not activated.
                global $wpdb;

                // Check if plugin's table exists in database
                if ( !$wpdb->get_var( 'SHOW TABLES LIKE"%' . $wpdb->prefix . self::FM_PHOTO_TABLE_GALLERY . '%"' ) ) {
                    return false;
                }
                $galleries = $wpdb->get_results('SELECT * FROM ' . $wpdb->prefix . self::FM_PHOTO_TABLE_GALLERY );

                return count($galleries) > 0;
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
                $photo_galleries = $this->get_photo_galleries();

                if ( count( $photo_galleries ) != 0 ) {
                    foreach ( $photo_galleries as $photo_gallery ) {

                        $data = array(
                            'ID' => $photo_gallery->id,
                            'title' => $photo_gallery->name,
                            'foogallery_title' => $photo_gallery->name,
                            'data' => $photo_gallery,
                            'children' => $this->find_images( $photo_gallery->id, '/wp-content/uploads/photo-gallery' ),
                            'settings' => ''
                        );
                        
                        $gallery = $this->get_gallery( $data );

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
            return $settings;
        }

         /**
         * Return all galleries object data.
         *
         * @return object Object of all galleries
         */
        private function get_photo_galleries() {
            global $wpdb;
            $gallery_table = $wpdb->prefix . self::FM_PHOTO_TABLE_GALLERY;
            return $wpdb->get_results( "select * from {$gallery_table} WHERE published = 1" );
        }

        /**
         * Return single gallery object data.
         * @param $id ID of the gallery
         * @return object Object of the gallery
         */
        private function get_photo_gallery_images( $id ) {
            global $wpdb;
            $picture_table = $wpdb->prefix . self::FM_PHOTO_IMAGE_TABLE;

            return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$picture_table} WHERE gallery_id = %d order by id", $id ) );
        }        

        /**
         * Find all images by gallery id
         * @param $gallery_id ID of the gallery
         * @param $gallery_path Image gallery path
         * @return bool
         */
        private function find_images( $gallery_id, $gallery_path ) {            
            $photo_images = $this->get_photo_gallery_images( $gallery_id );            

            $images = array();
            foreach ( $photo_images as $photo_image ) {

                $source_url = trailingslashit( site_url() ) . trailingslashit( $gallery_path ) . $photo_image->image_url;

                $data = array(
                    'source_url' => $source_url,
                    'caption' => $photo_image->description,
                    'alt' => $photo_image->alt,
                    'date' => $photo_image->date,
                    'data' => $photo_image
                );

                $image = $this->get_image($data);                

                if ( '1' == $photo_image->published ) {
                    $images[] = $image;
                }
            }
            return $images;
        }        

        /**
         * Return all albums object data.
         *
         * @return object Object of all albums
         */
        private function get_photo_albums() {
            global $wpdb;
            $album_table = $wpdb->prefix . self::FM_PHOTO_ALBUM_TABLE;
            return $wpdb->get_results(" select * from {$album_table}");
        }


        private function get_galleries_by_album( $album_id ) {
            global $wpdb;
            $album_gallery_table = $wpdb->prefix . self::FM_PHOTO_ALBUM_GALLERY_TABLE;
            $galleries_by_album = $wpdb->get_results( "select * from {$album_gallery_table} WHERE album_id = $album_id" );

            $galleries_id = array();
            foreach( $galleries_by_album as $gallery_by_album ) {
                $galleries_id[] = $gallery_by_album->alb_gal_id;
            }

            $galleries_id = implode(",", $galleries_id);
            $gallery_table = $wpdb->prefix . self::FM_PHOTO_TABLE_GALLERY;
            return $wpdb->get_results( "select * from {$gallery_table} WHERE published = 1 AND id IN($galleries_id)" );                        
        }

        function find_albums() {
            $photo_albums = $this->get_photo_albums();            
            $albums = array();

            if ( count( $photo_albums ) != 0 ) {
                foreach ( $photo_albums as $key => $photo_album ) {

                    $data = array(
                        'ID' => $photo_album->id,
                        'title' => $photo_album->name,
                        'data' => $photo_album,
                        'fooalbum_title' => $photo_album->name,
                    );                    

                    $album = $this->get_album($data);                    

                    $galleries = array();
                    $album_galleries = $this->get_galleries_by_album( $album->ID );

                    foreach( $album_galleries as $album_gallery ) {

                        $data = array(
                            'ID' => $album_gallery->id,
                            'title' => $album_gallery->name,
                            'foogallery_title' => $album_gallery->name,
                            'data' => $album_gallery,
                            'children' => $this->find_images( $album_gallery->id, '/wp-content/uploads/photo-gallery' ),
                            'settings' => ''
                        );
                        
                        $gallery = $this->get_gallery($data);

                        $galleries[] = $gallery;
                    }

                    $album->children = $galleries;
                    $albums[] = $album;
                }
            }

            return $albums;                            
        }        
    }
}