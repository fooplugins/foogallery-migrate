<?php
/**
 * FooGallery Migrator 10Web Plugin Class
 *
 * @package FooPlugins\FooGalleryMigrate
 */

namespace FooPlugins\FooGalleryMigrate\Plugins;

use FooPlugins\FooGalleryMigrate\Gallery;
use FooPlugins\FooGalleryMigrate\Image;
use FooPlugins\FooGalleryMigrate\Album;
use FooPlugins\FooGalleryMigrate\Plugin;
use stdClass;

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
                $photo_galleries = $this->get_galleries();               

                if ( count( $photo_galleries ) != 0 ) {
                    foreach ( $photo_galleries as $photo_gallery ) {                    
                        $gallery = new Gallery( $this );
                        $gallery->ID = $photo_gallery->id;
                        $gallery->title = $photo_gallery->name;
                        $gallery->foogallery_title = $photo_gallery->name;                        
                        $gallery->data = $photo_gallery;
                        $gallery->images = $this->find_images( $gallery->ID, '/wp-content/uploads/photo-gallery' );
                        $gallery->image_count = count( $gallery->images );
                        // To do fetch multiple data from other source and assign to setting member variable
                        $gallery->settings = "";
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
        private function get_galleries() {
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
                $image = new Image();
                $image->source_url = trailingslashit( site_url() ) . trailingslashit( $gallery_path ) . $photo_image->image_url;
                $image->caption = $photo_image->description;
                $image->alt = $photo_image->alt;
                $image->date = $photo_image->date;
                $image->data = $photo_image;

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

        /**
         * Return single album object data.
         * @param $id ID of the album
         * @return object Object of the album
         */
        private function get_photo_album( $id ) {
            global $wpdb;
            $album_table = $wpdb->prefix . self::FM_PHOTO_ALBUM_TABLE;

            return $wpdb->get_row( $wpdb->prepare( "select * from {$album_table} where id = %d", $id ) );
        }

        function find_albums() {
            $photo_albums = $this->get_photo_albums();

            $albums = array();

            if ( count( $photo_albums ) != 0 ) {
                foreach ( $photo_albums as $key => $photo_album ) {
                    $album = new Album( $this );
                    $album->ID = $photo_album->id;
                    $album->title = $photo_album->name;
                    $album->data = $photo_album;
                    $album->album_template = 'default';
                    // $album->fooalbum_id = photo_album->id;                        
                    $album->fooalbum_title = $photo_album->name;                        

                    $galleries = array();
                    $album_galleries = $this->get_galleries_by_album( $album->ID );

                    foreach( $album_galleries as $album_gallery ) {
                        $gallery = new Gallery( $this );
                        $gallery->ID = $album_gallery->id;
                        $gallery->title = $album_gallery->name;
                        $gallery->foogallery_title = $album_gallery->name;                        
                        $gallery->data = $album_gallery;
                        $gallery->images = $this->find_images( $gallery->ID, '/wp-content/uploads/photo-gallery' );
                        $gallery->image_count = count( $gallery->images );
                        $gallery->settings = "";
                        $galleries[] = $gallery;
                    }

                    $album->galleries = $galleries;
                    $album->gallery_count = count( $album->galleries );
                    $albums[] = $album;
                }
            }

            return $albums;                            
        }        
    }
}