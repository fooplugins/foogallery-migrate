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
	class Migrator extends MigratorBase {

        protected const KEY_GALLERIES = 'galleries';
        protected const KEY_CURRENT_MIGRATION_STATE = 'current_migration_state';
        protected const KEY_HAS_PREVIOUS_MIGRATION = 'previous_migration';

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
            $detected_plugins = array();

            foreach ( $this->plugins as $plugin ) {
                $detected_plugins[ $plugin->name() ] = $plugin->detect();
            }
            $this->set_migrator_setting( self::KEY_PLUGINS_DETECTED, $detected_plugins );

            $this->find_all();
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

        /**
         * Find all galleries and albums.
         *
         * @return void
         */
        private function find_all() {
            $galleries = array();

            foreach ( $this->plugins as $plugin ) {
                if ( $plugin->is_detected() ) {
                    $plugin_galleries = $plugin->find_galleries();

                    if ( is_array( $plugin_galleries ) ) {
                        $galleries = array_merge( $galleries, $plugin_galleries );
                    }
                }
            }

            $this->set_migrator_setting( self::KEY_GALLERIES, $galleries );
        }

        /**
         * Returns an array of all galleries that can be migrated.
         *
         * @return array<Gallery>
         */
        public function get_galleries() {
            return $this->get_migrator_setting( self::KEY_GALLERIES, array() );
        }

        /**
         * Mark a specific gallery for migration.
         *
         * @param $gallery_id_array
         * @return void
         */
        function queue_galleries_for_migration( $gallery_id_array ) {

            $galleries = $this->get_galleries();
            $queued_gallery_count = 0;

            foreach ( $galleries as $gallery ) {
                if ( array_key_exists( $gallery->unique_identifier(), $gallery_id_array ) ) {
                    // Only queue a gallery if it has not been migrated previously.
                    if ( !$gallery->migrated ) {
                        $queued_gallery_count++;
                        $gallery->part_of_migration = true;
                        $gallery->migration_status = $gallery::PROGRESS_QUEUED;
                        if ( 0 === $gallery->foogallery_id ) {
                            $gallery->foogallery_title = $gallery_id_array[$gallery->unique_identifier()]['title'];
                        }
                    }
                } else {
                    $gallery->part_of_migration = false;
                }
            }

            $this->calculate_migration_state();

            // Save the state of the galleries.
            $this->set_migrator_setting( self::KEY_GALLERIES, $galleries );

            $this->set_migrator_setting( self::KEY_HAS_PREVIOUS_MIGRATION, true );
        }

        /**
         * Calculates the state of the current migration.
         *
         * @return void
         */
        function calculate_migration_state() {
            $galleries = $this->get_galleries();
            $queued_count = 0;
            $completed_count = 0;
            $error_count = 0;

            foreach ( $galleries as $gallery ) {
                if ( $gallery->part_of_migration ) {
                    $queued_count++;

                    if ( $gallery->migrated ) {
                        $completed_count++;
                    }
                    if ( $gallery->migration_status === $gallery::PROGRESS_ERROR ) {
                        $error_count++;
                    }
                }
            }

            $progress = 0;
            if ( $queued_count > 0 ) {
                $progress = ( $completed_count + $error_count ) / $queued_count;
            }

            $this->set_migrator_setting( self::KEY_CURRENT_MIGRATION_STATE, array(
                'queued' => $queued_count,
                'completed' => $completed_count,
                'progress' => $progress
            ) );
        }

        /**
         * Resets all the previous migrations.
         *
         * @return void
         */
        function reset_migration() {
            delete_option( FOOGALLERY_MIGRATE_OPTION_DATA );
        }

        /**
         * Cancels the current migration.
         *
         * @return void
         */
        function cancel_migration() {
            $this->set_migrator_setting( self::KEY_CURRENT_MIGRATION_STATE, false );
        }

        /**
         * Returns the current gallery that is being migrated.
         *
         * @return int|string
         */
        function get_current_gallery_being_migrated() {
            $galleries = $this->get_galleries();

            foreach ( $galleries as $gallery ) {
                // Check if the gallery is queued for migration.
                if ( $gallery->migration_status === $gallery::PROGRESS_STARTED ) {
                    return $gallery->unique_identifier();
                }
            }
            return 0;
        }

        /**
         * Continue migrating the gallery.
         *
         * @return void
         */
        function migrate() {
            $galleries = $this->get_galleries();

            foreach ( $galleries as $gallery ) {
                // Check if the gallery is queued for migration, or has already started.
                if ( $gallery->migration_status === $gallery::PROGRESS_QUEUED || $gallery->migration_status === $gallery::PROGRESS_STARTED ) {
                    $gallery->migrate();
                    break;
                }
            }

            // Save the state of the galleries.
            $this->set_migrator_setting( self::KEY_GALLERIES, $galleries );
        }

        /**
         * Render the gallery migration form.
         *
         * @return void
         */
        function render_gallery_form() {
            $galleries = $this->get_galleries();

            if ( count( $galleries ) == 0 ) {
                _e( 'No galleries found!', 'foogallery-migrate' );
                return;
            }

            $migration_state = $this->get_migrator_setting( self::KEY_CURRENT_MIGRATION_STATE, false );
            if ( false === $migration_state ) {
                $has_migrations = false;
                $overall_progress = 0;
            } else {
                $has_migrations = true;
                $overall_progress = $migration_state['progress'];
            }
            $migrating = $has_migrations && defined( 'DOING_AJAX' ) && DOING_AJAX;
            $current_gallery_id = $this->get_current_gallery_being_migrated();
            $has_previous_migrations = $this->get_migrator_setting( self::KEY_HAS_PREVIOUS_MIGRATION, false );
            ?>
            <table class="wp-list-table widefat fixed striped table-view-list pages">
                <thead>
                    <tr>
                        <td id="cb" class="manage-column column-cb check-column">
                            <?php if ( ! $migrating ) { ?>
                                <label class="screen-reader-text" for="cb-select-all-1"><?php _e( 'Select All', 'foogallery-migrate' ); ?></label>
                                <input id="cb-select-all-1" type="checkbox" <?php echo $migrating ? 'disabled="disabled"' : ''; ?> checked="checked" />
                            <?php } ?>
                        </td>
                        <th scope="col" class="manage-column">
                            <span><?php _e( 'Gallery', 'foogallery-migrate' ); ?></span>
                        </th>
                        <th scope="col" class="manage-column">
                            <span><?php _e( 'Source', 'foogallery-migrate' ); ?></span>
                        </th>
                        <th scope="col" class="manage-column">
                            <span><?php printf( __( '%s Name', 'foogallery-migrate' ), foogallery_plugin_name() ); ?></span>
                        </th>
                        <th scope="col" class="manage-column">
                            <span><?php _e( 'Migration Progress', 'foogallery' ); ?></span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                <?php

                $url = add_query_arg( 'page', 'foogallery-migrate' );
                $page = 1;
                if ( defined( 'DOING_AJAX' ) ) {
                    if ( isset( $_POST['foogallery_migrate_paged'] ) ) {
                        $url = $_POST['foogallery_migrate_url'];
                        $page = $_POST['foogallery_migrate_paged'];
                    } else {
                        $url = wp_get_referer();
                        $parts = parse_url($url);
                        parse_str( $parts['query'], $query );
                        $page = $query['paged'];
                    }
                } else if ( isset( $_GET['paged'] ) ) {
                    $page = $_GET['paged'];
                }
                $url = add_query_arg( 'paged', $page, $url ) . '#galleries';
                $gallery_count = count( $galleries );
                $page_size = apply_filters( 'foogallery_migrate_page_size', 20);

                $pagination = new Pagination();
                $pagination->items( $gallery_count );
                $pagination->limit( $page_size ); // Limit entries per page
                $pagination->url = $url;
                $pagination->currentPage( $page );
                $pagination->calculate();

                for ($counter = $pagination->start; $counter <= $pagination->end; $counter++ ) {
                    if ( $counter >= $gallery_count ) {
                        break;
                    }
                    $gallery = $galleries[$counter];
                    $progress    = $gallery->progress;
                    $done        = $gallery->migrated;
                    $edit_link	 = '';
                    $foogallery = false;
                    if ( $gallery->foogallery_id > 0 ) {
                        $foogallery = \FooGallery::get_by_id( $gallery->foogallery_id );
                        if ( $foogallery ) {
                            $edit_link = '<a target="_blank" href="' . admin_url( 'post.php?post=' . $foogallery->ID . '&action=edit' ) . '">' . $foogallery->name . '</a>';
                        } else {
                            $done = false;
                        }
                    } ?>
                    <tr class="<?php echo ($counter % 2 === 0) ? 'alternate' : ''; ?>">
                        <?php if ( !$has_migrations && !$migrating && !$done ) { ?>
                            <th scope="row" class="column-cb check-column">
                                <input name="gallery-id[]" type="checkbox" checked="checked" value="<?php echo $gallery->unique_identifier(); ?>">
                            </th>
                        <?php } else if ( $migrating && $gallery->unique_identifier() === $current_gallery_id ) { ?>
                            <th>
                                <div class="dashicons dashicons-arrow-right"></div>
                            </th>
                        <?php } else { ?>
                            <th>
                            </th>
                        <?php } ?>
                        <td>
                            <?php echo $gallery->ID . '. '; ?>
                            <strong><?php echo $gallery->title; ?></strong>
                            <?php echo ' ' . sprintf( __( '(%s images)', 'foogallery' ), $gallery->image_count ); ?>
                        </td>
                        <td>
                            <?php echo $gallery->source; ?>
                        </td>
                        <td>
                            <?php if ( $foogallery ) {
                                echo $edit_link;
                            } else { ?>
                                <input name="foogallery-title-<?php echo $gallery->unique_identifier(); ?>" value="<?php echo $gallery->title; ?>">
                            <?php } ?>
                        </td>
                        <td class="foogallery-migrate-progress foogallery-migrate-progress-<?php echo $gallery->migration_status; ?>">
                            <?php echo $gallery->friendly_migration_message(); ?>
                        </td>
                    </tr>
                    <?php
                }
                ?>
                </tbody>
            </table>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php echo $pagination->render(); ?>
                </div>
            </div>

            <?php
            //hidden fields used for pagination
            echo '<input type="hidden" name="foogallery_migrate_paged" value="' . esc_attr( $page ) . '" />';
            echo '<input type="hidden" name="foogallery_migrate_url" value="' . esc_url( $url ) . '" />';

            echo '<input type="hidden" class="migrate_progress" value="' . $overall_progress . '" />';
            wp_nonce_field( 'foogallery_migrate', 'foogallery_migrate', false );
            wp_nonce_field( 'foogallery_migrate_reset', 'foogallery_migrate_reset', false );
            if ( $has_migrations ) { ?>
                <button name="foogallery_migrate_action" value="foogallery_migrate_continue"
                        class="button button-primary continue_migrate"><?php _e( 'Resume Migration', 'foogallery-migrate' ); ?></button>
                <button name="foogallery_migrate_action" value="foogallery_migrate_cancel"
                        class="button button-primary cancel_migrate"><?php _e( 'Stop Migration', 'foogallery-migrate' ); ?></button>
            <?php } else { ?>
                <button name="foogallery_migrate_action" value="foogallery_migrate_start"
                        class="button button-primary start_migrate"><?php _e( 'Start Gallery Migration', 'foogallery-migrate' ); ?></button>
            <?php }
            if ( $has_previous_migrations && !$migrating ) { ?>
                <input type="submit" name="foogallery_foogallery_reset" class="button reset_migrate" value="<?php _e( 'Reset Migration', 'foogallery' ); ?>">
            <?php }
            ?><div id="foogallery_migrate_gallery_spinner" style="width:20px">
                <span class="spinner"></span>
            </div>
            <?php if ( $migrating ) { ?>
                <div class="foogallery-migrate-progressbar">
                    <span style="width:<?php echo $overall_progress; ?>%"></span>
                </div>
                <?php echo intval( $overall_progress ); ?>%
                <div style="width:20px; display: inline-block;">
                    <span class="spinner shown"></span>
                </div>
            <?php }
        }
	}
}
