<?php
/**
 * FooGallery Migrator Envira Plugin Class
 *
 * @package FooPlugins\FooGalleryMigrate
 */

namespace FooPlugins\FooGalleryMigrate\Plugins;

use FooPlugins\FooGalleryMigrate\Plugin;

if ( ! class_exists( 'FooPlugins\FooGalleryMigrate\Plugins\Envira' ) ) {

    /**
     * Class Envira
     *
     * @package FooPlugins\FooGalleryMigrate
     */
    class Envira extends Plugin {

        /**
         * Name of the plugin.
         *
         * @return string
         */
        function name() {
            return 'Envira';
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
            //Migrate settings from the Envira gallery to the FooGallery.

            //Set the FooGallery gallery template, to be closest to the Envira gallery layout.
            //$gallery_template_closest_to_envira_gallery_layout = 'default';
            //add_post_meta( $gallery->foogallery_id, FOOGALLERY_META_TEMPLATE, $gallery_template_closest_to_envira_gallery_layout, true );

            //Set the FooGallery gallery settings, based on the envira gallery settings.
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