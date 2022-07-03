<?php
/**
 * FooGallery Migrate Init Class
 * Runs at the startup of the plugin
 * Assumes after all checks have been made, and all is good to go!
 *
 * @package FooPlugins\FooGalleryMigrate
 */

namespace FooPlugins\FooGalleryMigrate;

if ( ! class_exists( 'FooPlugins\FooGalleryMigrate\Init' ) ) {

	/**
	 * Class Init
	 *
	 * @package FooPlugins\FooGalleryMigrate
	 */
	class Init {

		/**
		 * Initialize the plugin
		 */
		public function __construct() {
            add_action( 'foogallery_admin_menu_after', array( $this, 'add_menu' ) );
		}

        /**
         * Add an admin menu
         *
         * @return void
         */
        function add_menu() {
            foogallery_add_submenu_page(
                __( 'Migrate!', 'foogallery' ),
                'manage_options',
                'foogallery-migrate',
                array( $this, 'render_view' )
            );
        }

        /**
         * Render the contents of the page for the menu.
         *
         * @return void
         */
        function render_view() {
            require_once 'view-migrate.php';
        }
	}
}
