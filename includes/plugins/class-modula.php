<?php
/**
 * FooGallery Migrator Modula Plugin Class
 *
 * @package FooPlugins\FooGalleryMigrate
 */

namespace FooPlugins\FooGalleryMigrate\Plugins;

use FooPlugins\FooGalleryMigrate\Plugin;

if ( ! class_exists( 'FooPlugins\FooGalleryMigrate\Plugins\Modula' ) ) {

    /**
     * Class Envira
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
            return false;
        }

        function find_galleries() {
            return array();
        }

        function migrate_settings( $gallery ) {
            //Migrate settings from the Modula gallery to the FooGallery.

            //Set the FooGallery gallery template, to be closest to the Modula gallery layout.
            //$gallery_template_closest_to_modula_gallery_layout = 'default';
            //add_post_meta( $gallery->foogallery_id, FOOGALLERY_META_TEMPLATE, $gallery_template_closest_to_modula_gallery_layout, true );

            //Set the FooGallery gallery settings, based on the Modula gallery settings.
            //$settings = array();
            //add_post_meta( $gallery->foogallery_id, FOOGALLERY_META_SETTINGS, $settings, true );
        }

//        abstract function get_galleries();
//
//        abstract function get_albums();
//
//        abstract function get_content();
    }
}