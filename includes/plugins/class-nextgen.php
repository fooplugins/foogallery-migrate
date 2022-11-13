<?php
/**
 * FooGallery Migrator Nextgen Plugin Class
 *
 * @package FooPlugins\FooGalleryMigrate
 */

namespace FooPlugins\FooGalleryMigrate\Plugins;

use FooPlugins\FooGalleryMigrate\Gallery;
use FooPlugins\FooGalleryMigrate\Image;
use FooPlugins\FooGalleryMigrate\Plugin;

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
                    $gallery = new Gallery( $this );
                    $gallery->ID = $nextgen_gallery->gid;
                    $gallery->title = $nextgen_gallery->title;
                    $gallery->data = $nextgen_gallery;
                    $gallery->images = $this->find_images( $gallery->ID, $nextgen_gallery->path );
                    $gallery->image_count = count( $gallery->images );
                    $galleries[] = $gallery;
                }
            }

            return $galleries;
        }

        /**
         * Find all images by gallery id
         * @param $gallery_id ID of the gallery
         * @param $gallery_path Image gallery path
         * @return bool
         */
        private function find_images( $gallery_id, $gallery_path ) {
            $nextgen_images = $this->get_nextgen_gallery_images( $gallery_id );

            $images = array();
            foreach ( $nextgen_images as $nextgen_image ) {
                $image = new Image();
                $image->source_url = trailingslashit( site_url() ) . trailingslashit( $gallery_path ) . $nextgen_image->filename;
                $image->caption = $nextgen_image->description;
                $image->alt = $nextgen_image->alttext;
                $image->date = $nextgen_image->imagedate;
                $image->data = $nextgen_image;

                if ( '1' !== $nextgen_image->exclude ) {
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
         * Return single album object data.
         * @param $id ID of the album
         * @return object Object of the album
         */
        private function get_nextgen_album( $id ) {
            global $wpdb;
            $album_table = $wpdb->prefix . self::NEXTGEN_TABLE_ALBUMS;

            return $wpdb->get_row( $wpdb->prepare( "select * from {$album_table} where id = %d", $id ) );
        }
    }
}