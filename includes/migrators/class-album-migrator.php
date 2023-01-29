<?php
/**
 * FooGallery AlbumMigrator Class
 *
 * @package FooPlugins\FooGalleryMigrate
 */

namespace FooPlugins\FooGalleryMigrate\Migrators;
use FooPlugins\FooGalleryMigrate\Objects\Album;
use FooPlugins\FooGalleryMigrate\Objects\Migratable;
use FooPlugins\FooGalleryMigrate\Pagination;

if ( ! class_exists( 'FooPlugins\FooGalleryMigrate\Migrators\AlbumMigrator' ) ) {

	/**
	 * Class Init
	 *
	 * @package FooPlugins\FooGalleryMigrate
     *     
     *
	 */
	class AlbumMigrator extends MigratorBase {
        /**
         * Render the album migration form.
         *
         * @return void
         */
        function render_album_form() {
            $albums = $this->get_objects_to_migrate();
            wp_nonce_field( 'foogallery_album_migrate', 'foogallery_album_migrate', false );

            if ( count( $albums ) === 0 ) {
                echo '<p>' . __( 'No albums found!', 'foogallery-migrate' ) . '</p>';
                $show_refresh = true;
                $migrating = false;
            } else {

                $migration_state = $this->get_state();

                if ( false === $migration_state ) {
                    $has_migrations = false;
                    $overall_progress = 0;
                } else {
                    $overall_progress = $migration_state['progress'];
                    if ( $migration_state['queued'] > 0 ) {
                        $has_migrations = $overall_progress < 100;
                    } else {
                        $has_migrations = false; // There is nothing queued.
                    }
                }
                $migrating = $has_migrations && defined( 'DOING_AJAX' ) && DOING_AJAX;
                $current_album_id = $this->get_current_object_being_migrated();
                $show_refresh = !$has_migrations;
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
                        if ( $album->migrated_id > 0 ) {
                            $fooalbum = \FooGalleryAlbum::get_by_id( $album->migrated_id );
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
                                <?php _e( 'Galleries : ', 'foogallery-migrate' ); ?>
                                <?php echo esc_html( $album->get_children_count() ); ?>
                                <br />
                                <?php _e( 'Images : ', 'foogallery-migrate' ); ?>
                                <?php echo esc_html( $album->get_total_images() ); ?>
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

                if ( $has_migrations ) { ?>
                    <button name="foogallery_migrate_action" value="foogallery_album_migrate_continue"
                            class="button button-primary continue_album_migrate"><?php _e( 'Resume Migration', 'foogallery-migrate' ); ?></button>
                    <button name="foogallery_migrate_action" value="foogallery_album_migrate_cancel"
                            class="button cancel_album_migrate"><?php _e( 'Stop Migration', 'foogallery-migrate' ); ?></button>
                <?php } else { ?>
                    <button name="foogallery_migrate_action" value="foogallery_album_migrate_start"
                            class="button button-primary start_album_migrate"><?php _e( 'Start Album Migration', 'foogallery-migrate' ); ?></button>
                <?php
                }
            }
            if ( $show_refresh ) { ?>
                <button name="foogallery_migrate_action" value="foogallery_refresh_albums"
                        class="button refresh_albums"><?php _e( 'Refresh Albums', 'foogallery-migrate' ); ?></button>
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
