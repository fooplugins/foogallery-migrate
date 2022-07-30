<?php
/**
 * FooGallery Migrator Base Class
 *
 * @package FooPlugins\FooGalleryMigrate
 */

namespace FooPlugins\FooGalleryMigrate;

if ( ! class_exists( 'FooPlugins\FooGalleryMigrate\MigratorBase' ) ) {

    /**
     * Class Init
     *
     * @package FooPlugins\FooGalleryMigrate
     */
    class MigratorBase {

        protected const KEY_PLUGINS_DETECTED = 'plugins_detected';

        /**
         * Returns a setting for the migrator.
         *
         * @return mixed
         */
        protected function get_migrator_setting( $name, $default = false ) {
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
        protected function set_migrator_setting( $name, $value ) {
            $settings = get_option( FOOGALLERY_MIGRATE_OPTION_DATA );

            if ( !isset( $settings ) ) {
                $settings = array();
            }

            $settings[ $name ] = $value;

            update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $settings );
        }
    }
}