<?php
/**
 * FooGallery Migrator Plugin Base Class
 *
 * @package FooPlugins\FooGalleryMigrate
 */

namespace FooPlugins\FooGalleryMigrate;

if ( ! class_exists( 'FooPlugins\FooGalleryMigrate\Plugin' ) ) {

    /**
     * Class Plugin
     *
     * @package FooPlugins\FooGalleryMigrate
     */
    abstract class Plugin extends MigratorBase {

        /**
         * The name of the Plugin.
         * @return string
         */
        abstract function name();

        /**
         * Returns true if the plugin has been detected before.
         *
         * @return bool
         */
        function is_detected() {
            $detected_plugins = $this->get_migrator_setting( self::KEY_PLUGINS_DETECTED, array() );
            return array_key_exists( $this->name(), $detected_plugins ) && $detected_plugins[ $this->name() ];
        }

//        protected function get_data() {
//            return $this->get_migrator_setting( $this->name(), array( 'galleries' => false, 'albums' => false, 'content' => false ) );
//        }
//
//        public function get_saved_galleries() {
//            return $this->get_data()['galleries'];
//        }
//
//        function find() {
//            $existing_galleries = $this->get_saved_galleries();
//            $found_galleries = $this->find_galleries();
//
//            //Merge the galleries together.
//            $data = $this->get_data();
//            $data['galleries'] = $found_galleries;
//            $this->set_migrator_setting( $this->name(), $data );
//        }

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
//
//        abstract function get_albums();
//
//        abstract function get_content();
    }
}