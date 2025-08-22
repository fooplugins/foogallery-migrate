<?php
/**
 * FooGallery Migrator Nextgen Plugin Class
 *
 * @package FooPlugins\FooGalleryMigrate
 */

namespace FooPlugins\FooGalleryMigrate\Plugins;

use FooPlugins\FooGalleryMigrate\Objects\Gallery;
use FooPlugins\FooGalleryMigrate\Objects\Image;
use FooPlugins\FooGalleryMigrate\Objects\Album;
use FooPlugins\FooGalleryMigrate\Objects\Plugin;

if ( ! class_exists( 'FooPlugins\FooGalleryMigrate\Plugins\Nextgen' ) ) {

    /**
     * Class Nextgen
     *
     * @package FooPlugins\FooGalleryMigrate
     */
    class Nextgen extends Plugin {

        const NEXTGEN_TABLE_GALLERY  = 'ngg_gallery';
        const NEXTGEN_TABLE_PICTURES = 'ngg_pictures';
        const NEXTGEN_TABLE_ALBUMS   = 'ngg_album';

        /**
         * Name of the plugin.
         *
         * @return string
         */
        function name() {
            return 'NextGen';
        }

        /**
         * Detects data from the gallery plugin.
         *
         * @return bool
         */
        function detect() {
            if (defined('NGG_PLUGIN_VERSION')) {
                // NextGen plugin is activated. Get out early!
                return true;
            } else {
                // Do some checks even if the plugin is not activated.
                global $wpdb;

                // Check if plugin's table ngg_gallery exists in database
                if ( !$wpdb->get_var( 'SHOW TABLES LIKE"%' . $wpdb->prefix . 'ngg_gallery%"' ) ) {
                   return false;
                }
                $galleries = $wpdb->get_results('SELECT * FROM ' . $wpdb->prefix . 'ngg_gallery');

                return count($galleries) > 0;
            }
        }

        /**
         * Find all galleries
         *
         * @return array
         */
        function find_galleries() {
            $nextgen_galleries = $this->get_nextgen_galleries();
            $galleries = array();

            if ( count( $nextgen_galleries ) != 0 ) {
                foreach ( $nextgen_galleries as $key => $nextgen_gallery ) {

                        $data = array(
                            'ID' => $nextgen_gallery->gid,
                            'title' => $nextgen_gallery->title,
                            'foogallery_title' => $nextgen_gallery->title,
                            'data' => $nextgen_gallery,
                            'children' => $this->find_images( $nextgen_gallery->gid, $nextgen_gallery->path ),
                            'settings' => ''
                        );
                        
                        $gallery = $this->get_gallery( $data );
                        
                    $galleries[] = $gallery;
                }
            }

            return $galleries;
        }

        /**
         * Find all images by gallery id
         * @param $gallery_id ID of the gallery
         * @param $gallery_path Image gallery path
         * @return array
         */
        private function find_images( $gallery_id, $gallery_path ) {
            $nextgen_images = $this->get_nextgen_gallery_images( $gallery_id );

            $images = array();
            foreach ( $nextgen_images as $nextgen_image ) {
                $source_url = trailingslashit( site_url() ) . trailingslashit( $gallery_path ) . $nextgen_image->filename;

                $data = array(
                    'source_url' => $source_url,
                    'slug' => $nextgen_image->filename,
                    'caption' => $nextgen_image->alttext,
                    'description' => $nextgen_image->description,
                    'alt' => '',
                    'date' => $nextgen_image->imagedate,
                    'data' => $nextgen_image
                );

                $image = $this->get_image( $data );

                if ( 0 == $nextgen_image->exclude ) {
                    $images[] = $image;
                }
            }
            return $images;
        }

        /**
         * Return all galleries object data.
         *
         * @return object Object of all galleries
         */
        private function get_nextgen_galleries() {
            global $wpdb;
            $gallery_table = $wpdb->prefix . self::NEXTGEN_TABLE_GALLERY;  
            return $wpdb->get_results( "select * from {$gallery_table}" );
        }

        /**
         * Return single gallery object data.
         * @param $id ID of the gallery
         * @return object Object of the gallery
         */
        private function get_nextgen_gallery_images( $id ) {
            global $wpdb;
            $picture_table = $wpdb->prefix . self::NEXTGEN_TABLE_PICTURES;

            return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$picture_table} WHERE galleryid = %d order by sortorder", $id ) );
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
         * Return all albums object data.
         *
         * @return object Object of all albums
         */
        private function get_nextgen_albums() {
            global $wpdb;
            $album_table = $wpdb->prefix . self::NEXTGEN_TABLE_ALBUMS;
            return $wpdb->get_results(" select * from {$album_table}");
        }


       /**
         * Return all galleris by album
         *
         * @return object Object of galleries by album 
         */
        private function get_galleries_by_album( $album_id ) {
            global $wpdb;
            $album_table = $wpdb->prefix . self::NEXTGEN_TABLE_ALBUMS;
            $gallery_table = $wpdb->prefix . self::NEXTGEN_TABLE_GALLERY;
            $get_galleries_data = $wpdb->get_row("SELECT sortorder FROM $album_table WHERE id = $album_id");
            if ( $get_galleries_data->sortorder !== '' ) {
                $galleries_id = base64_decode($get_galleries_data->sortorder);
                $galleries_id = str_replace("[", "", $galleries_id);
                $galleries_id = str_replace("]", "", $galleries_id);
                $galleries_id = str_replace('"', '', $galleries_id);

                if ( $galleries_id !== '' ) {
                    $get_galleries_data = $wpdb->get_results("SELECT * FROM {$gallery_table} WHERE gid IN ($galleries_id)");                    
                }
            } 

            return $get_galleries_data;
        }

        function find_albums() {
            $nextgen_albums = $this->get_nextgen_albums();
            $albums = array();

            if ( count( $nextgen_albums ) !== 0 ) {
                foreach ( $nextgen_albums as $key => $nextgen_album ) {

                    $data = array(
                        'ID' => $nextgen_album->id,
                        'title' => $nextgen_album->name,
                        'data' => $nextgen_album,
                        'fooalbum_title' => $nextgen_album->name,
                    );                    

                    $album = $this->get_album( $data );

                    $galleries = array();
                    $album_galleries = $this->get_galleries_by_album( $nextgen_album->id );

                    foreach( $album_galleries as $album_gallery ) {

                        $data = array(
                            'ID' => $album_gallery->gid,
                            'title' => $album_gallery->title,
                            'foogallery_title' => $album_gallery->title,
                            'data' => $album_gallery,
                            'children' => $this->find_images( $album_gallery->gid, $album_gallery->path ),
                            'settings' => ''
                        );

                        $gallery = $this->get_gallery( $data );

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