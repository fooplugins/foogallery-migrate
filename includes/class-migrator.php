<?php
/**
 * FooGallery Migrator Class
 *
 * @package FooPlugins\FooGalleryMigrate
 */

namespace FooPlugins\FooGalleryMigrate;

if ( ! class_exists( 'FooPlugins\FooGalleryMigrate\Migrator' ) ) {

	/**
	 * Class Init
	 *
	 * @package FooPlugins\FooGalleryMigrate
	 */
	class Migrator {


        /**
         * Internal list of all plugins.
         *
         * @var array<Plugin>
         */
        public $plugins = array();

		/**
		 * Initialize the Migrator
		 */
		public function __construct() {
            $this->plugins[] = new Plugins\Envira();
            $this->plugins[] = new Plugins\Modula();
            $this->plugins[] = new Plugins\Nextgen();

            $settings = get_option( FOOGALLERY_MIGRATE_OPTION_DATA );

            if ( !isset( $settings ) || false === $settings ) {
                // We have never tried to detect anything, so try to detect plugins.
                $this->run_detection();
            }
        }

        /**
         * Runs detection for all plugins.
         *
         * @return void
         */
        public function run_detection() {
            foreach ( $this->plugins as $plugin ) {
                $plugin->set_detection( $plugin->detect() );
            }
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
         * Returns an array of plugin names that are detected.
         *
         * @return array
         */
        public function get_detected_plugins() {
            $detected = array();
            foreach ( $this->plugins as $plugin ) {
                if ( $plugin->is_detected() ) {
                    $detected[] = $plugin->name();
                }
            }

            return $detected;
        }
	}
}
