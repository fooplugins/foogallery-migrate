<?php
/**
 * FooGallery Migrator Engine Class
 *
 * @package FooPlugins\FooGalleryMigrate
 */

namespace FooPlugins\FooGalleryMigrate;

use FooPlugins\FooGalleryMigrate\Objects\Plugin;

if ( !class_exists( 'FooPlugins\FooGalleryMigrate\MigratorEngine' ) ) {

	/**
	 * Class MigratorEngine
	 *
	 * @package FooPlugins\FooGalleryMigrate
	 */
	class MigratorEngine {

        protected const KEY_PLUGINS = 'plugins';
        protected const KEY_GALLERIES = 'galleries';
        protected const KEY_ALBUMS = 'albums';

        /**
         * Returns a setting for the migrator.
         *
         * @return mixed
         */
        public function get_migrator_setting( $name, $default = false ) {
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
        public function set_migrator_setting( $name, $value ) {
            $settings = get_option( FOOGALLERY_MIGRATE_OPTION_DATA );

            if ( !isset( $settings ) ) {
                $settings = array();
            }

            $settings[ $name ] = $value;

            update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $settings );
        }

        /**
         * Returns true if we have any saved migrator settings.
         *
         * @return bool
         */
        public function has_migrator_settings() {
            $settings = get_option( FOOGALLERY_MIGRATE_OPTION_DATA );

            return isset( $settings ) && is_array( $settings );
        }

        /**
         * Runs detection for all plugins.
         *
         * @return array<Plugin>
         */
        public function run_detection() {
            $plugins = foogallery_migrate_get_available_plugins();

            foreach ( $plugins as $plugin ) {
                $plugin->is_detected = $plugin->detect();
            }
            $this->set_migrator_setting( self::KEY_PLUGINS, $plugins );

            return $plugins;
        }

        /**
         * Returns an array of plugins.
         *
         * @return array<Plugin>
         */
        public function get_plugins() {
            $plugins = $this->get_migrator_setting( self::KEY_PLUGINS );
            if ( $plugins === false ) {
                $plugins = $this->run_detection();
            }
            return $plugins;
        }

        /**
         * Returns true if there are any detected plugins.
         *
         * @return bool
         */
        public function has_detected_plugins() {
            return count( $this->get_detected_plugins() ) > 0;
        }

        /**
         * Returns an array of plugins that are detected.
         *
         * @return array
         */
        public function get_detected_plugins() {
            $detected = array();
            foreach ( $this->get_plugins() as $plugin ) {
                if ( $plugin->is_detected ) {
                    $detected[] = $plugin->name();
                }
            }

            return $detected;
        }

        /**
         * Returns the Gallery Migrator
         *
         * @return Migrators\GalleryMigrator
         */
        public function get_gallery_migrator() {
            return new Migrators\GalleryMigrator( $this, self::KEY_GALLERIES );
        }

        /**
         * Returns the Album Migrator
         *
         * @return Migrators\AlbumMigrator
         */
        public function get_album_migrator() {
            return new Migrators\AlbumMigrator( $this, self::KEY_ALBUMS );
        }
	}
}
