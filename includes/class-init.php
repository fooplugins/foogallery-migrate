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
            add_action( 'wp_ajax_foogallery_migrate_retry_gallery', array( $this, 'ajax_retry_gallery_migration' ) );
            add_action( 'wp_ajax_foogallery_migrate_check_gallery_errors', array( $this, 'ajax_check_gallery_errors' ) );
        

            // Ajax calls for importing albums
            add_action( 'wp_ajax_foogallery_album_migrate', array( $this, 'ajax_start_album_migration' ) );
            add_action( 'wp_ajax_foogallery_album_migrate_continue', array( $this, 'ajax_continue_album_migration' ) );
            add_action( 'wp_ajax_foogallery_album_migrate_cancel', array( $this, 'ajax_cancel_album_migration' ) );
            add_action( 'wp_ajax_foogallery_album_migrate_reset', array( $this, 'ajax_reset_album_migration' ) );  
            add_action( 'wp_ajax_foogallery_album_migrate_refresh', array( $this, 'ajax_refresh_album_migration' ) );

            // Ajax calls for content migration
            add_action( 'wp_ajax_foogallery_content_replace', array( $this, 'ajax_replace_content' ) );
            add_action( 'wp_ajax_foogallery_content_refresh', array( $this, 'ajax_refresh_content' ) );

            // Ajax calls for log updates
            add_action( 'wp_ajax_foogallery_migrate_update_status', array( $this, 'ajax_update_migrated_status' ) );
            add_action( 'wp_ajax_foogallery_migrate_delete_object', array( $this, 'ajax_delete_migrated_object' ) );

            add_action( 'admin_post_foogallery_migrate_save_settings', array( $this, 'save_settings' ) );
            add_action( 'admin_post_foogallery_migrate_detect_wordpress_core', array( $this, 'detect_wordpress_core_galleries' ) );
            add_action( 'admin_post_foogallery_migrate_content', array( $this, 'migrate_content_no_js' ) );
            add_action( 'admin_post_foogallery_migrate_refresh_content', array( $this, 'refresh_content_no_js' ) );
                      
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
                <h4><?php esc_html_e('FooGallery Migrate Error!', 'foogallery-custom-branding'); ?></h4>
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
            $content_migration_result = $this->consume_content_migration_result();
            require_once 'views/view-migrate.php';
        }

        /**
         * Consume the current administrator's no-JS content migration result.
         *
         * @return array|false
         */
        private function consume_content_migration_result() {
            $result_key = 'foogallery_migrate_content_result_' . get_current_user_id();
            $result = get_transient( $result_key );
            if ( is_array( $result ) ) {
                delete_transient( $result_key );
                return $result;
            }

            return false;
        }

        /**
         * Detect stored WordPress core galleries and route to content migration.
         *
         * Detection is read-only. Gallery creation and post updates remain behind
         * the existing nonce/capability protected content migration action.
         *
         * @return void
         */
        function detect_wordpress_core_galleries() {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'Unauthorized.', 'foogallery-migrate' ) );
            }

            check_admin_referer( 'foogallery_migrate_detect_wordpress_core' );

            $migrator = foogallery_migrate_migrator_instance();
            $migrator->run_detection();
            $migrator->get_gallery_migrator()->get_objects_to_migrate( true );
            $content_items = $migrator->get_content_migrator()->scan_content( true );
            $count = 0;
            foreach ( $content_items as $item ) {
                if ( is_array( $item ) && isset( $item['plugin_name'] ) && 'WordPress Core' === $item['plugin_name'] ) {
                    $count++;
                }
            }

            $redirect_url = add_query_arg(
                array(
                    'page'                       => 'foogallery-migrate',
                    'wordpress-gallery-count'    => $count,
                    'wordpress-gallery-detected' => $count > 0 ? '1' : '0',
                ),
                admin_url( 'edit.php?post_type=foogallery' )
            );

            wp_safe_redirect( $redirect_url . ( $count > 0 ? '#shortcodes' : '#sources' ) );
            exit;
        }

        /**
         * Migrate and replace selected content without JavaScript.
         *
         * @return void
         */
        function migrate_content_no_js() {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'Unauthorized.', 'foogallery-migrate' ) );
            }

            check_admin_referer( 'foogallery_content_migrate', 'foogallery_content_migrate' );

            $selected_items = array();
            if ( isset( $_POST['content-item'] ) ) {
                $selected_items = map_deep( wp_unslash( $_POST['content-item'] ), 'absint' );
            }

            $result = foogallery_migrate_migrator_instance()
                ->get_content_migrator()
                ->migrate_and_replace_content( $selected_items );

            set_transient(
                'foogallery_migrate_content_result_' . get_current_user_id(),
                $result,
                MINUTE_IN_SECONDS
            );

            $this->redirect_to_content_migration();
        }

        /**
         * Refresh the content scan without JavaScript.
         *
         * @return void
         */
        function refresh_content_no_js() {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'Unauthorized.', 'foogallery-migrate' ) );
            }

            check_admin_referer( 'foogallery_content_migrate', 'foogallery_content_migrate' );
            $migrator = foogallery_migrate_migrator_instance();
            $migrator->get_gallery_migrator()->get_objects_to_migrate( true );
            $migrator->get_content_migrator()->scan_content( true );
            $this->redirect_to_content_migration();
        }

        /**
         * Redirect back to the content migration tab.
         *
         * @return void
         */
        private function redirect_to_content_migration() {
            $redirect_url = add_query_arg(
                'page',
                'foogallery-migrate',
                admin_url( 'edit.php?post_type=foogallery' )
            );
            wp_safe_redirect( $redirect_url . '#shortcodes' );
            exit;
        }

        /**
         * Save FooGallery Migrate settings.
         *
         * @return void
         */
        function save_settings() {
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'Unauthorized.', 'foogallery-migrate' ) );
            }

            check_admin_referer( 'foogallery_migrate_settings', 'foogallery_migrate_settings' );

            $settings = array(
                'override_gallery_layout' => '',
                'page_size' => 20,
                'debug_enabled' => false,
            );

            if ( array_key_exists( 'override_gallery_layout', $_POST ) ) {
                $override_gallery_layout = wp_unslash( $_POST['override_gallery_layout'] );
                if ( is_scalar( $override_gallery_layout ) ) {
                    $settings['override_gallery_layout'] = sanitize_key( $override_gallery_layout );
                }
            }

            if ( array_key_exists( 'page_size', $_POST ) ) {
                $page_size = wp_unslash( $_POST['page_size'] );
                if ( is_scalar( $page_size ) ) {
                    $settings['page_size'] = absint( $page_size );
                }
            }

            $settings['debug_enabled'] = array_key_exists( 'debug_enabled', $_POST );

            $migrator = foogallery_migrate_migrator_instance();
            $migrator->save_settings( $settings );

            $redirect_url = add_query_arg(
                array(
                    'page' => 'foogallery-migrate',
                    'settings-updated' => 'true',
                ),
                admin_url( 'admin.php' )
            );

            wp_safe_redirect( $redirect_url . '#settings' );
            exit;
        }

        /**
         * Start the gallery migration!
         *
         * @return void
         */
        function ajax_start_migration() {
            if ( check_admin_referer( 'foogallery_migrate', 'foogallery_migrate' ) ) {
                if ( ! current_user_can( 'manage_options' ) ) {
                    wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'foogallery-migrate' ) ) );
                }

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
                if ( ! current_user_can( 'manage_options' ) ) {
                    wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'foogallery-migrate' ) ) );
                }

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

        function ajax_retry_gallery_migration() {
            if ( check_admin_referer( 'foogallery_migrate', 'foogallery_migrate' ) ) {
                if ( ! current_user_can( 'manage_options' ) ) {
                    wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'foogallery-migrate' ) ) );
                }
                $migrator = foogallery_migrate_migrator_instance();

                if ( array_key_exists( 'foogallery_migrate_retry_gallery_id', $_POST ) ) {
                    $gallery_id = sanitize_text_field( wp_unslash( $_POST['foogallery_migrate_retry_gallery_id'] ) );
                    $migrator->retry_gallery_migration( $gallery_id );
                }

                $migrator->get_gallery_migrator()->render_gallery_form();
                die();
            }
        }

        function ajax_check_gallery_errors() {
            if ( check_admin_referer( 'foogallery_migrate', 'foogallery_migrate' ) ) {
                if ( ! current_user_can( 'manage_options' ) ) {
                    wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'foogallery-migrate' ) ) );
                }

                $migrator = foogallery_migrate_migrator_instance();
                if ( array_key_exists( 'foogallery_migrate_check_gallery_id', $_POST ) ) {
                    $gallery_id = sanitize_text_field( wp_unslash( $_POST['foogallery_migrate_check_gallery_id'] ) );
                    $migrator->check_gallery_migration_errors( $gallery_id );
                }

                $migrator->get_gallery_migrator()->get_objects_to_migrate( true );
                $migrator->get_gallery_migrator()->render_gallery_form();
                die();
            }
        }

        function ajax_cancel_migration() {
            if ( check_admin_referer( 'foogallery_migrate', 'foogallery_migrate' ) ) {
                if ( ! current_user_can( 'manage_options' ) ) {
                    wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'foogallery-migrate' ) ) );
                }

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
                if ( ! current_user_can( 'manage_options' ) ) {
                    wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'foogallery-migrate' ) ) );
                }

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
                if ( ! current_user_can( 'manage_options' ) ) {
                    wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'foogallery-migrate' ) ) );
                }

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
                if ( ! current_user_can( 'manage_options' ) ) {
                    wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'foogallery-migrate' ) ) );
                }

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
                if ( ! current_user_can( 'manage_options' ) ) {
                    wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'foogallery-migrate' ) ) );
                }

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
                if ( ! current_user_can( 'manage_options' ) ) {
                    wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'foogallery-migrate' ) ) );
                }

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

        /**
         * Replace content shortcodes/blocks.
         *
         * @return void
         */
        function ajax_replace_content() {
            if ( check_admin_referer( 'foogallery_content_migrate', 'foogallery_content_migrate' ) ) {
                if ( ! current_user_can( 'manage_options' ) ) {
                    wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'foogallery-migrate' ) ) );
                }

                $migrator = foogallery_migrate_migrator_instance();
                $content_migrator = $migrator->get_content_migrator();

                $selected_items = array();
                if ( array_key_exists( 'content-item', $_POST ) ) {
                    $selected_items = map_deep( wp_unslash( $_POST['content-item'] ), 'sanitize_text_field' );
                }

                $result = $content_migrator->migrate_and_replace_content( $selected_items );

                // Show success/error messages
                if ( $result['success'] > 0 ) {
                    echo '<div class="notice notice-success"><p>';
                    printf(
                        /* translators: %d: migrated occurrence count. */
                        esc_html( _n( 'Successfully migrated and replaced %d gallery occurrence.', 'Successfully migrated and replaced %d gallery occurrences.', $result['success'], 'foogallery-migrate' ) ),
                        absint( $result['success'] )
                    );
                    echo '</p></div>';
                }

                if ( ! empty( $result['errors'] ) ) {
                    echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Errors:', 'foogallery-migrate' ) . '</strong></p><ul>';
                    foreach ( $result['errors'] as $error ) {
                        echo '<li>' . esc_html( $error ) . '</li>';
                    }
                    echo '</ul></div>';
                }

                $content_migrator->render_content_form();

                die();
            }
        }

        /**
         * Refresh content scan.
         *
         * @return void
         */
        function ajax_refresh_content() {
            if ( check_admin_referer( 'foogallery_content_migrate', 'foogallery_content_migrate' ) ) {
                if ( ! current_user_can( 'manage_options' ) ) {
                    wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'foogallery-migrate' ) ) );
                }

                $migrator = foogallery_migrate_migrator_instance();
                $content_migrator = $migrator->get_content_migrator();
                $migrator->get_gallery_migrator()->get_objects_to_migrate( true );
                $content_migrator->scan_content( true );
                $content_migrator->render_content_form();

                die();
            }
        }

        /**
         * Update migrated object status from log view.
         *
         * @return void
         */
        function ajax_update_migrated_status() {
            if ( ! check_admin_referer( 'foogallery_migrate_log', 'foogallery_migrate_log' ) ) {
                wp_send_json_error( array( 'message' => __( 'Invalid request.', 'foogallery-migrate' ) ) );
            }
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'foogallery-migrate' ) ) );
            }

            $object_id = '';
            if ( array_key_exists( 'object_id', $_POST ) ) {
                $object_id = sanitize_text_field( wp_unslash( $_POST['object_id'] ) );
            }

            $status = '';
            if ( array_key_exists( 'status', $_POST ) ) {
                $status = sanitize_text_field( wp_unslash( $_POST['status'] ) );
            }

            $allowed_statuses = array(
                \FooPlugins\FooGalleryMigrate\Objects\Migratable::PROGRESS_NOT_STARTED,
                \FooPlugins\FooGalleryMigrate\Objects\Migratable::PROGRESS_QUEUED,
                \FooPlugins\FooGalleryMigrate\Objects\Migratable::PROGRESS_STARTED,
                \FooPlugins\FooGalleryMigrate\Objects\Migratable::PROGRESS_COMPLETED,
                \FooPlugins\FooGalleryMigrate\Objects\Migratable::PROGRESS_NOTHING,
                \FooPlugins\FooGalleryMigrate\Objects\Migratable::PROGRESS_ERROR
            );

            if ( '' === $object_id || ! in_array( $status, $allowed_statuses, true ) ) {
                wp_send_json_error( array( 'message' => __( 'Invalid status update.', 'foogallery-migrate' ) ) );
            }

            $migrator = foogallery_migrate_migrator_instance();
            $result = $migrator->update_migrated_object_status( $object_id, $status );

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( array( 'message' => $result->get_error_message() ) );
            }

            $status_labels = array(
                \FooPlugins\FooGalleryMigrate\Objects\Migratable::PROGRESS_NOT_STARTED => __( 'Not migrated', 'foogallery-migrate' ),
                \FooPlugins\FooGalleryMigrate\Objects\Migratable::PROGRESS_QUEUED => __( 'Queued', 'foogallery-migrate' ),
                \FooPlugins\FooGalleryMigrate\Objects\Migratable::PROGRESS_STARTED => __( 'Started', 'foogallery-migrate' ),
                \FooPlugins\FooGalleryMigrate\Objects\Migratable::PROGRESS_COMPLETED => __( 'Completed', 'foogallery-migrate' ),
                \FooPlugins\FooGalleryMigrate\Objects\Migratable::PROGRESS_NOTHING => __( 'Nothing to migrate', 'foogallery-migrate' ),
                \FooPlugins\FooGalleryMigrate\Objects\Migratable::PROGRESS_ERROR => __( 'Error', 'foogallery-migrate' )
            );

            $status_label = $status_labels[ $status ] ?? $status;

            wp_send_json_success( array( 'status_label' => $status_label ) );
        }

        /**
         * Delete a migrated object from the log.
         *
         * @return void
         */
        function ajax_delete_migrated_object() {
            if ( ! check_admin_referer( 'foogallery_migrate_log', 'foogallery_migrate_log' ) ) {
                wp_send_json_error( array( 'message' => __( 'Invalid request.', 'foogallery-migrate' ) ) );
            }
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'foogallery-migrate' ) ) );
            }

            $object_id = '';
            if ( array_key_exists( 'object_id', $_POST ) ) {
                $object_id = sanitize_text_field( wp_unslash( $_POST['object_id'] ) );
            }

            if ( '' === $object_id ) {
                wp_send_json_error( array( 'message' => __( 'Invalid object.', 'foogallery-migrate' ) ) );
            }

            $migrator = foogallery_migrate_migrator_instance();
            $result = $migrator->delete_migrated_object( $object_id );

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( array( 'message' => $result->get_error_message() ) );
            }

            wp_send_json_success();
        }
    
	}
}
