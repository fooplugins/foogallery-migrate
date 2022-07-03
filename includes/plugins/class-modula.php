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

//        abstract function get_galleries();
//
//        abstract function get_albums();
//
//        abstract function get_content();
    }
}