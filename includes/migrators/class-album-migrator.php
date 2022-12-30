<?php
/**
 * FooGallery AlbumMigrator Class
 *
 * @package FooPlugins\FooGalleryMigrate
 */

namespace FooPlugins\FooGalleryMigrate\Migrators;

if ( ! class_exists( 'FooPlugins\FooGalleryMigrate\Migrators\AlbumMigrator' ) ) {

	/**
	 * Class Init
	 *
	 * @package FooPlugins\FooGalleryMigrate
	 */
	class AlbumMigrator extends MigratorBase {

        protected const KEY_ALBUMS = 'albums';
        protected const KEY_CURRENT_ALBUM_MIGRATION_STATE = 'current_album_migration_state';
        protected const KEY_HAS_PREVIOUS_ALBUM_MIGRATION = 'previous_album_migration';

		/**
		 * Initialize the AlbumMigrator
		 */
		public function __construct() {

        }

        /**
         * Find all galleries and albums.
         *
         * @return void
         */
        private function find_all() {
            $albums = array();

            $exist_albums = $this->get_albums();

         
            if(!$exist_albums) {

                foreach ( $this->plugins as $plugin ) {
                    if ( $plugin->is_detected() ) {
                        $plugin_albums = $plugin->find_albums();

                        if ( is_array( $plugin_albums ) ) {
                            $albums = array_merge( $albums, $plugin_albums );
                        }
                    }
                }

                $this->set_migrator_setting( self::KEY_ALBUMS, $albums );                   
            }            
        }

        /**
         * Returns an array of all albums that can be migrated.
         *
         * @return array<Album>
         */
        public function get_albums() {
            return $this->get_migrator_setting( self::KEY_ALBUMS, array() );
        }

        /**
         * Mark a specific album for migration.
         *
         * @param $album_id_array
         * @return void
         */
        function queue_albums_for_migration( $album_id_array ) {

            $albums = $this->get_albums();
            $queued_album_count = 0;
            $updated_albums = array();

            foreach ( $albums as $album ) {
    
                if ( array_key_exists( $album->unique_identifier(), $album_id_array ) ) {
                    // Only queue a album if it has not been migrated previously.
                    if ( !$album->migrated ) {
                        $queued_album_count++;
                        $album->part_of_migration = true;
                        $album->migration_status = $album::PROGRESS_QUEUED;
                        // if ( 0 === $album->fooalbum_id ) {
                            $album->fooalbum_title = $album_id_array[$album->unique_identifier()]['title'];
                        // }
                        $updated_albums[] = $album;
                    }
                } else {
                    $album->part_of_migration = false;
                }
            }

            $this->set_migrator_setting( self::KEY_ALBUMS, $updated_albums );

            $this->calculate_migration_state($updated_albums);

            $this->set_migrator_setting( self::KEY_HAS_PREVIOUS_ALBUM_MIGRATION, true );

        }

        /**
         * Calculates the state of the current migration.
         *
         * @return void
         */
        function calculate_migration_state($updated_albums) {
            
            $queued_count = 0;
            $completed_count = 0;
            $error_count = 0;

            foreach ( $updated_albums as $album ) {
                if ( $album->part_of_migration ) {
                    $queued_count++;

                    if ( $album->migrated ) {
                        $completed_count++;
                    }

                    if ( $album->migration_status === $album::PROGRESS_ERROR ) {
                        $error_count++;
                    }
                }
            }

            $progress = 0;
            if ( $queued_count > 0 ) {
                $progress = ( $completed_count + $error_count ) / $queued_count * 100;
            }         

            $this->set_migrator_setting( self::KEY_CURRENT_ALBUM_MIGRATION_STATE, array(
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
            $this->set_migrator_setting( self::KEY_CURRENT_ALBUM_MIGRATION_STATE, false );
        }

        /**
         * Returns the current album that is being migrated.
         *
         * @return int|string
         */
        function get_current_album_being_migrated() {
            $albums = $this->get_albums();

            foreach ( $albums as $album ) {
                // Check if the album is queued for migration.
                if ( $album->migration_status === $album::PROGRESS_STARTED ) {
                    return $album->unique_identifier();
                }
            }
            return 0;
        }

        /**
         * Continue migrating the album.
         *
         * @return void
         */
        function migrate() {
            $albums = $this->get_albums();

            foreach ( $albums as $album ) {
                // Check if the album is queued for migration, or has already started.

                if ( $album->migration_status === $album::PROGRESS_QUEUED || $album->migration_status === $album::PROGRESS_STARTED ) {
                    $album->migrate();
                    break;
                }
            }

            $this->calculate_migration_state($albums);

            // Save the state of the albums.
            $this->set_migrator_setting( self::KEY_ALBUMS, $albums );
        }

        /**
         * Render the album migration form.
         *
         * @return void
         */
        function render_album_form() {
            $albums = $this->get_albums();  

            if ( count( $albums ) == 0 ) {
                _e( 'No albums found!', 'foogallery-migrate' );
                return;
            }

            $migration_state = $this->get_migrator_setting( self::KEY_CURRENT_ALBUM_MIGRATION_STATE, false );
            if ( false === $migration_state ) {
                $has_migrations = false;
                $overall_progress = 0;
            } else {
                $overall_progress = $migration_state['progress'];
                $has_migrations = $overall_progress < 100;
            }
            $migrating = $has_migrations && defined( 'DOING_AJAX' ) && DOING_AJAX;
            $current_album_id = $this->get_current_album_being_migrated();
            $has_previous_migrations = $this->get_migrator_setting( self::KEY_HAS_PREVIOUS_ALBUM_MIGRATION, false );
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
                            <span><?php _e( 'Album', 'foogallery-migrate' ); ?></span>
                        </th>
                        <th scope="col" class="manage-column">
                            <span><?php _e( 'Source', 'foogallery-migrate' ); ?></span>
                        </th>
                        <th scope="col" class="manage-column">
                            <span><?php _e( 'Migration Data', 'foogallery-migrate' ); ?></span>
                        </th>
                        <th scope="col" class="manage-column">
                            <span><?php printf( __( '%s Album Name', 'foogallery-migrate' ), foogallery_plugin_name() ); ?></span>
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
                    if ( array_key_exists( 'foogallery_album_migrate_paged', $_POST ) ) {
                        $url = sanitize_url( $_POST['foogallery_album_migrate_url'] );
                        $page = sanitize_text_field( $_POST['foogallery_album_migrate_paged'] );
                    } else {
                        $url = wp_get_referer();
                        $parts = parse_url($url);
                        parse_str( $parts['query'], $query );
                        $page = $query['paged'];
                    }
                } else if ( array_key_exists( 'paged', $_GET ) ) {
                    $page = sanitize_text_field( $_GET['paged'] );
                }
                $url = add_query_arg( 'paged', $page, $url ) . '#albums';
                $albums_count = count( $albums );
                $page_size = apply_filters( 'foogallery_migrate_page_size', 20);

                $pagination = new Pagination();
                $pagination->items( $albums_count );
                $pagination->limit( $page_size ); // Limit entries per page
                $pagination->url = $url;
                $pagination->currentPage( $page );
                $pagination->calculate();

                for ($counter = $pagination->start; $counter <= $pagination->end; $counter++ ) {
                    if ( $counter >= $albums_count ) {
                        break;
                    }
                    $album = $albums[$counter];
                    $progress    = $album->progress;
                    $done        = $album->migrated;
                    $edit_link	 = '';
                    $fooalbum = false;
                    if ( $album->fooalbum_id > 0 ) {
                        $fooalbum = \FooGalleryAlbum::get_by_id( $album->fooalbum_id );
                        if ( $fooalbum ) {
                            $edit_link = '<a target="_blank" href="' . esc_url( admin_url( 'post.php?post=' . $fooalbum->ID . '&action=edit' ) ) . '">' . esc_html( $fooalbum->name ) . '</a>';
                        } else {
                            $done = false;
                        }
                    } ?>
                    <tr class="<?php echo esc_attr( ($counter % 2 === 0) ? 'alternate' : '' ); ?>">
                        <?php if ( !$has_migrations && !$migrating && !$done ) { ?>
                            <th scope="row" class="column-cb check-column">
                                <input name="album-id[]" type="checkbox" checked="checked" value="<?php echo esc_attr( $album->unique_identifier() ); ?>">
                            </th>
                        <?php } else if ( $migrating && $album->unique_identifier() === $current_album_id ) { ?>
                            <th>
                                <div class="dashicons dashicons-arrow-right"></div>
                            </th>
                        <?php } else { ?>
                            <th>
                            </th>
                        <?php } ?>
                        <td>
                            <?php echo esc_html( $album->ID ) . '. '; ?>
                            <strong><?php echo esc_html( $album->title ); ?></strong>                           
                        </td>
                        <td>
                            <?php echo esc_html( $album->plugin->name() ); ?>
                        </td>
                        <td>
                            <?php _e( 'Template : ', 'foogallery-migrate' ); ?>
                            <?php echo $album->get_album_template(); ?>
                            <br />
                            <?php _e( 'Images : ', 'foogallery-migrate' ); ?>
                            <?php echo esc_html( $album->get_image_count() ); ?>
                            <?php if ( foogallery_is_debug() ) { ?>
                            <br />
                            <?php  } ?>
                        </td>
                        <td>
                            <?php
                             if ( $fooalbum ) {
                                echo $edit_link;
                            } else { 
                                ?>
                                <input name="foogallery-album-title-<?php echo esc_attr( $album->unique_identifier() ); ?>" value="<?php echo esc_attr( $album->title ); ?>">
                            <?php 
                                } 
                            ?>
                        </td>
                        <td class="foogallery-migrate-progress foogallery-migrate-progress-<?php echo esc_attr( $album->migration_status ); ?>">
                            <?php echo esc_html( $album->friendly_migration_message() ); ?>
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
            echo '<input type="hidden" name="foogallery_album_migrate_paged" value="' . esc_attr( $page ) . '" />';
            echo '<input type="hidden" name="foogallery_album_migrate_url" value="' . esc_url( $url ) . '" />';

            echo '<input type="hidden" class="album_migrate_progress" value="' . esc_attr( $overall_progress ) . '" />';
            wp_nonce_field( 'foogallery_album_migrate', 'foogallery_album_migrate', false );
            wp_nonce_field( 'foogallery_album_migrate_reset', 'foogallery_album_migrate_reset', false );
            if ( $has_migrations ) { ?>
                <button name="foogallery_migrate_action" value="foogallery_album_migrate_continue"
                        class="button button-primary continue_album_migrate"><?php _e( 'Resume Migration', 'foogallery-migrate' ); ?></button>
                <button name="foogallery_migrate_action" value="foogallery_album_migrate_cancel"
                        class="button button-primary cancel_album_migrate"><?php _e( 'Stop Migration', 'foogallery-migrate' ); ?></button>
            <?php } else { ?>
                <button name="foogallery_migrate_action" value="foogallery_album_migrate_start"
                        class="button button-primary start_album_migrate"><?php _e( 'Start Album Migration', 'foogallery-migrate' ); ?></button>
            <?php }
            if ( $has_previous_migrations && !$migrating ) { ?>
                <input type="submit" name="foogallery_foogallery_reset" class="button reset_album_migrate" value="<?php _e( 'Reset Migration', 'foogallery' ); ?>">
            <?php }
            ?><div id="foogallery_migrate_album_spinner" style="width:20px">
                <span class="spinner"></span>
            </div>
            <?php if ( $migrating ) { ?>
                <div class="foogallery-migrate-progressbar">
                    <span style="width:<?php echo esc_attr( $overall_progress ); ?>%"></span>
                </div>
                <?php echo esc_html( intval( $overall_progress ) ); ?>%
                <div style="width:20px; display: inline-block;">
                    <span class="spinner shown"></span>
                </div>
            <?php }
        }
	}
}
