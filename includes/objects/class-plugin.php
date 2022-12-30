<?php
/**
 * FooGallery Migrator Plugin Base Class
 *
 * @package FooPlugins\FooGalleryMigrate
 */

namespace FooPlugins\FooGalleryMigrate\Objects;

if ( ! class_exists( 'FooPlugins\FooGalleryMigrate\Plugin\Objects' ) ) {

    /**
     * Class Plugin
     *
     * @package FooPlugins\FooGalleryMigrate
     */
    abstract class Plugin {

        public $is_detected = false;

        /**
         * The name of the Plugin.
         * @return string
         */
        abstract function name();

        /**
         * Detects data from the gallery plugin.
         * @return bool
         */
        abstract function detect();

        /**
         * Returns all galleries for the plugin.
         *
         * @return array<Gallery>
         */
        abstract function find_galleries();

        /**
         * Returns the closest possible gallery template
         *
         * @param $gallery Gallery
         * @return string
         */
        abstract function get_gallery_template( $gallery );

        /**
         * Returns the closest possible gallery settings
         *
         * @param $gallery Gallery
         * @param $default_settings array
         * @return array
         */
        abstract function get_gallery_settings( $gallery, $default_settings );

        /**
         * Migrates any settings for the gallery.
         *
         * @param $gallery Gallery
         * @return void
         */
        //abstract function migrate_settings( $gallery );
//
       abstract function find_albums();
//
//        abstract function get_content();

        function find_objects( $type ) {
            if ( 'albums' === $type ) {
                return $this->find_albums();
            }
            return $this->find_galleries();
        }
    }
}