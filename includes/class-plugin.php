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
    abstract class Plugin {

        const KEY_PLUGINS_DETECTED = 'plugins_detected';

        /**
         * The name of the Plugin.
         * @return string
         */
        abstract function name();

        /**
         * Returns a setting for the migrator.
         *
         * @return mixed
         */
        private function get_migrator_setting( $name, $default = false ) {
            $settings = get_option( FOOGALLERY_MIGRATE_OPTION_DATA );

            if ( isset( $settings ) && is_array( $settings ) && array_key_exists( $name, $settings ) ) {
                return $settings[ $name ];
            }

            return $default;
        }

        /**
         * Sets a migrator setting.
         *
         * @param $name
         * @param $value
         * @return void
         */
        private function set_migrator_setting( $name, $value ) {
            $settings = get_option( FOOGALLERY_MIGRATE_OPTION_DATA );

            if ( !isset( $settings ) ) {
                $settings = array();
            }

            $settings[ $name ] = $value;

            update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $settings );
        }

        /**
         * Returns true if the plugin has been detected before.
         *
         * @return bool
         */
        function is_detected() {
            $detected_plugins = $this->get_migrator_setting( self::KEY_PLUGINS_DETECTED, array() );
            return array_key_exists( $this->name(), $detected_plugins ) && $detected_plugins[ $this->name() ];
        }

        /**
         * Stores that the plugin is detected.
         *
         * @return void
         */
        function set_detection( $is_detected ) {
            if ( $this->is_detected() !== $is_detected ) {
                $detected_plugins = $this->get_migrator_setting( self::KEY_PLUGINS_DETECTED, array() );
                $detected_plugins[ $this->name() ] = $is_detected;

                $this->set_migrator_setting( self::KEY_PLUGINS_DETECTED, $detected_plugins );
            }
        }

        /**
         * Detects data from the gallery plugin.
         * @return bool
         */
        abstract function detect();

//        abstract function get_galleries();
//
//        abstract function get_albums();
//
//        abstract function get_content();
    }
}