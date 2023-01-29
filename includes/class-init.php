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
            add_action( 'admin_notices', array($this, 'foogallery_check') );

            add_action( 'foogallery_admin_menu_after', array( $this, 'add_menu' ) );

            // Ajax calls for importing galleries
            add_action( 'wp_ajax_foogallery_migrate', array( $this, 'ajax_start_migration' ) );
            add_action( 'wp_ajax_foogallery_migrate_continue', array( $this, 'ajax_continue_migration' ) );
            add_action( 'wp_ajax_foogallery_migrate_cancel', array( $this, 'ajax_cancel_migration' ) );
            add_action( 'wp_ajax_foogallery_migrate_reset', array( $this, 'ajax_reset_migration' ) );
            add_action( 'wp_ajax_foogallery_migrate_refresh', array( $this, 'ajax_refresh_migration' ) );
        

            // Ajax calls for importing albums
            add_action( 'wp_ajax_foogallery_album_migrate', array( $this, 'ajax_start_album_migration' ) );
            add_action( 'wp_ajax_foogallery_album_migrate_continue', array( $this, 'ajax_continue_album_migration' ) );
            add_action( 'wp_ajax_foogallery_album_migrate_cancel', array( $this, 'ajax_cancel_album_migration' ) );
            add_action( 'wp_ajax_foogallery_album_migrate_reset', array( $this, 'ajax_reset_album_migration' ) );  
            add_action( 'wp_ajax_foogallery_album_migrate_refresh', array( $this, 'ajax_refresh_album_migration' ) );  
                      
		}

        /***
         * Show an admin message if FooGallery is not installed.
         *
         * @return void
         */
        function foogallery_check() {
            if ( !class_exists( 'FooGallery_Plugin' ) ) {

                $url = admin_url( 'plugin-install.php?tab=search&s=foogallery&plugin-search-input=Search+Plugins' );

                $link = sprintf( ' <a href="%s">%s</a>', $url, __( 'install FooGallery!', 'foogallery-migrate' ) );

                $message = __( 'The FooGallery plugin is required for the FooGallery Migrate plugin to work. Activate it now if you have it installed, or ', 'foogallery-migrate' ) . $link;

                ?>
                <div class="error">
                <h4><?php _e('FooGallery Migrate Error!', 'foogallery-custom-branding'); ?></h4>
                <p><?php echo wp_kses_post( $message ); ?></p>
                </div><?php
            }
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
            require_once 'views/view-migrate.php';
        }

        /**
         * Start the gallery migration!
         *
         * @return void
         */
        function ajax_start_migration() {
            if ( check_admin_referer( 'foogallery_migrate', 'foogallery_migrate' ) ) {

                $migrator = foogallery_migrate_migrator_instance();

                if ( array_key_exists( 'gallery-id', $_POST ) ) {

                    $gallery_ids = map_deep( wp_unslash( $_POST['gallery-id'] ), 'sanitize_text_field' );

                    $migrations = array();

                    foreach ( $gallery_ids as $gallery_id ) {
                        $migrations[$gallery_id] = array(
                            'id' => $gallery_id,
                            'migrated' => false,
                            'current' => false,
                        );
                        if ( array_key_exists( 'foogallery-title-' . $gallery_id, $_POST ) ) {
                            $migrations[$gallery_id]['title'] = sanitize_text_field( wp_unslash( $_POST[ 'foogallery-title-' . $gallery_id ] ) );
                        }
                    }

                    // Queue the galleries for migration.
                    $migrator->get_gallery_migrator()->queue_objects_for_migration( $migrations );
                }

                $migrator->get_gallery_migrator()->render_gallery_form();

                die();
            }
        }

        function ajax_continue_migration() {
            if ( check_admin_referer( 'foogallery_migrate', 'foogallery_migrate' ) ) {

                if ( array_key_exists( 'action', $_REQUEST ) ) {
                    $action = sanitize_text_field( wp_unslash( $_REQUEST['action'] ) );

                    if ('foogallery_migrate_continue' === $action) {
                        $migrator = foogallery_migrate_migrator_instance();
                        $migrator->get_gallery_migrator()->migrate();
                        $migrator->get_gallery_migrator()->render_gallery_form();
                    }
                }

                die();
            }
        }

        function ajax_cancel_migration() {
            if ( check_admin_referer( 'foogallery_migrate', 'foogallery_migrate' ) ) {

                if ( array_key_exists( 'action', $_REQUEST ) ) {
                    $action = sanitize_text_field(wp_unslash($_REQUEST['action']));

                    if ('foogallery_migrate_cancel' === $action) {
                        $migrator = foogallery_migrate_migrator_instance();
                        $migrator->get_gallery_migrator()->cancel_migration();
                        $migrator->get_gallery_migrator()->render_gallery_form();
                    }
                }
            }
            die();
        }

        function ajax_refresh_migration() {
            if ( check_admin_referer( 'foogallery_migrate', 'foogallery_migrate' ) ) {

                if ( array_key_exists( 'action', $_REQUEST ) ) {
                    $action = sanitize_text_field(wp_unslash($_REQUEST['action']));

                    if ('foogallery_migrate_refresh' === $action) {
                        $migrator = foogallery_migrate_migrator_instance();
                        $migrator->get_gallery_migrator()->get_objects_to_migrate(true);
                        $migrator->get_gallery_migrator()->render_gallery_form();
                    }
                }
            }
            die();
        }                

        /**
         * Start the album migration!
         *
         * @return void
         */
        function ajax_start_album_migration() {
            if ( check_admin_referer( 'foogallery_album_migrate', 'foogallery_album_migrate' ) ) {

                $migrator = foogallery_migrate_migrator_instance();

                if ( array_key_exists( 'album-id', $_POST ) ) {

                    $album_ids = map_deep( wp_unslash( $_POST['album-id'] ), 'sanitize_text_field' );

                    $migrations = array();

                    foreach ( $album_ids as $album_id ) {
                        $migrations[$album_id] = array(
                            'id' => $album_id,
                            'migrated' => false,
                            'current' => false,
                        );
                        if ( array_key_exists( 'foogallery-album-title-' . $album_id, $_POST ) ) {
                            $migrations[$album_id]['title'] = sanitize_text_field( wp_unslash( $_POST[ 'foogallery-album-title-' . $album_id ] ) );
                        }
                    }

                    // Queue the albums for migration.
                    $migrator->get_album_migrator()->queue_objects_for_migration( $migrations );
                }

                $migrator->get_album_migrator()->render_album_form();

                die();
            }
        }

        function ajax_continue_album_migration() {
            if ( check_admin_referer( 'foogallery_album_migrate', 'foogallery_album_migrate' ) ) {

                if ( array_key_exists( 'action', $_REQUEST ) ) {
                    $action = sanitize_text_field( wp_unslash( $_REQUEST['action'] ) );

                    if ('foogallery_album_migrate_continue' === $action) {
                        $migrator = foogallery_migrate_migrator_instance();
                        $migrator->get_album_migrator()->migrate();
                        $migrator->get_album_migrator()->render_album_form();
                    }
                }

                die();
            }
        }

        function ajax_cancel_album_migration() {
            if ( check_admin_referer( 'foogallery_album_migrate', 'foogallery_album_migrate' ) ) {

                if ( array_key_exists( 'action', $_REQUEST ) ) {
                    $action = sanitize_text_field(wp_unslash($_REQUEST['action']));

                    if ('foogallery_album_migrate_cancel' === $action) {
                        $migrator = foogallery_migrate_migrator_instance();
                        $migrator->get_album_migrator()->cancel_migration();
                        $migrator->get_album_migrator()->render_album_form();
                    }
                }
            }
            die();
        }

        function ajax_refresh_album_migration() {
            if ( check_admin_referer( 'foogallery_album_migrate', 'foogallery_album_migrate' ) ) {

                if ( array_key_exists( 'action', $_REQUEST ) ) {
                    $action = sanitize_text_field(wp_unslash($_REQUEST['action']));

                    if ('foogallery_album_migrate_refresh' === $action) {
                        $migrator = foogallery_migrate_migrator_instance();
                        $migrator->get_album_migrator()->get_objects_to_migrate(true);
                        $migrator->get_album_migrator()->render_album_form();
                    }
                }
            }
            die();
        }        
    
	}
}
