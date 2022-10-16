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

                //TODO : first check if the ngg_gallery tables exists

                $galleries = $wpdb->get_results('SELECT * FROM ' . $wpdb->prefix . 'ngg_gallery');

                return count($galleries) > 0;
            }
        }

        function find_galleries() {
            $nextgen_galleries = $this->get_nextgen_galleries();
            $galleries = array();

            if ( count( $nextgen_galleries ) != 0 ) {
                foreach ( $nextgen_galleries as $key => $nextgen_gallery ) {
                    $gallery = new Gallery();
                    $gallery->source = 'NextGen';
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

        private function find_images( $gallery_id, $gallery_path ) {
            $nextgen_images = $this->get_nextgen_gallery_images( $gallery_id );

            //TODO : sort the images based on the gallery sort order. If the gallery is "unsorted" then sort the images looking at $nextgen_image->sortorder
            $sorted_images = $nextgen_images;

            $images = array();
            foreach ( $sorted_images as $nextgen_image ) {
                $image = new Image();
                $image->source_url = trailingslashit( site_url() ) . trailingslashit( $gallery_path ) . $nextgen_image->filename;
                $image->caption = $nextgen_image->description;
                $image->alt = $nextgen_image->alttext;
                $image->date = $nextgen_image->imagedate;
                //TODO : check $nextgen_image->exclude;

                $image->data = $nextgen_image;
                $images[] = $image;
            }
            return $images;
        }

        private function get_nextgen_galleries() {
            global $wpdb;
            $gallery_table = $wpdb->prefix . self::NEXTGEN_TABLE_GALLERY;
            return $wpdb->get_results( "select * from {$gallery_table}" );
        }

        private function get_nextgen_albums() {
            global $wpdb;
            $album_table = $wpdb->prefix . self::NEXTGEN_TABLE_ALBUMS;
            return $wpdb->get_results(" select * from {$album_table}");
        }

        private function get_nextgen_album( $id ) {
            global $wpdb;
            $album_table = $wpdb->prefix . self::NEXTGEN_TABLE_ALBUMS;

            return $wpdb->get_row( $wpdb->prepare( "select * from {$album_table} where id = %d", $id ) );
        }

        private function get_nextgen_gallery( $id ) {
            global $wpdb;
            $gallery_table = $wpdb->prefix . self::NEXTGEN_TABLE_GALLERY;
            $picture_table = $wpdb->prefix . self::NEXTGEN_TABLE_PICTURES;

            return $wpdb->get_row( $wpdb->prepare( "select gid, name, title, galdesc, path, author,
(select count(*) from {$picture_table} where galleryid = gid) 'image_count'
from {$gallery_table}
where gid = %d", $id ) );
        }

        private function get_nextgen_gallery_images( $id ) {
            global $wpdb;
            $picture_table = $wpdb->prefix . self::NEXTGEN_TABLE_PICTURES;

            return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$picture_table} WHERE galleryid = %d order by sortorder", $id ) );
        }
    }
}