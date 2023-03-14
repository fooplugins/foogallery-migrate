<?php
/**
 * FooGallery GalleryMigrator Class
 *
 * @package FooPlugins\FooGalleryMigrate
 */

namespace FooPlugins\FooGalleryMigrate\Migrators;

use FooPlugins\FooGalleryMigrate\Objects\Gallery;
use FooPlugins\FooGalleryMigrate\Objects\Migratable;
use FooPlugins\FooGalleryMigrate\Pagination;

if ( ! class_exists( 'FooPlugins\FooGalleryMigrate\Migrators\GalleryMigrator' ) ) {

	/**
	 * Class Init
	 *
	 * @package FooPlugins\FooGalleryMigrate
	 */
	class GalleryMigrator extends MigratorBase {

        /**
         * Render the gallery migration form.
         *
         * @return void
         */
        function render_gallery_form() {
            $galleries = $this->get_objects_to_migrate();
            wp_nonce_field( 'foogallery_migrate', 'foogallery_migrate', false );

            if ( count( $galleries ) === 0 ) {
                echo '<p>' . __( 'No galleries found!', 'foogallery-migrate' ) . '</p>';
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
                $current_gallery_id = $this->get_current_object_being_migrated();
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
                                <span><?php _e( 'Gallery', 'foogallery-migrate' ); ?></span>
                            </th>
                            <th scope="col" class="manage-column">
                                <span><?php _e( 'Source', 'foogallery-migrate' ); ?></span>
                            </th>
                            <th scope="col" class="manage-column">
                                <span><?php _e( 'Migration Data', 'foogallery-migrate' ); ?></span>
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
                        if ( array_key_exists( 'foogallery_migrate_paged', $_POST ) ) {
                            $url = sanitize_url( $_POST['foogallery_migrate_url'] );
                            $page = sanitize_text_field( $_POST['foogallery_migrate_paged'] );
                        } else {
                            $url = wp_get_referer();
                            $parts = parse_url($url);
                            parse_str( $parts['query'], $query );
                            $page = $query['paged'];
                        }
                    } else if ( array_key_exists( 'paged', $_GET ) ) {
                        $page = sanitize_text_field( $_GET['paged'] );
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
                        if ( $gallery->migrated_id > 0 ) {
                            $foogallery = \FooGallery::get_by_id( $gallery->migrated_id );
                            if ( $foogallery ) {
                                $edit_link = '<a target="_blank" href="' . esc_url( admin_url( 'post.php?post=' . $foogallery->ID . '&action=edit' ) ) . '">' . esc_html( $foogallery->name ) . '</a>';
                            } else {
                                $done = false;
                            }
                        } ?>
                        <tr class="<?php echo esc_attr( ($counter % 2 === 0) ? 'alternate' : '' ); ?>">
                            <?php if ( !$has_migrations && !$migrating && !$done ) { ?>
                                <th scope="row" class="column-cb check-column">
                                    <input name="gallery-id[]" type="checkbox" checked="checked" value="<?php echo esc_attr( $gallery->unique_identifier() ); ?>">
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
                                <?php echo esc_html( $gallery->ID ) . '. '; ?>
                                <strong><?php echo esc_html( $gallery->title ); ?></strong>
                                <?php if ( foogallery_is_debug() && isset( $gallery->settings ) ) { ?>
                                    <br />
                                    <?php echo wp_kses_post( foogallery_migrate_array_to_table( $gallery->settings ) );
                                } ?>
                            </td>
                            <td>
                                <?php echo esc_html( $gallery->plugin->name() ); ?>
                            </td>
                            <td>
                                <?php _e( 'Template : ', 'foogallery-migrate' ); ?>
                                <?php echo esc_html( $gallery->plugin->get_gallery_template( $gallery ) ); ?>
                                <br />
                                <?php _e( 'Images : ', 'foogallery-migrate' ); ?>
                                <?php echo esc_html( $gallery->get_children_count() ); ?>
                                <?php if ( foogallery_is_debug() ) { ?>
                                <br />
                                <?php _e( 'Settings : ', 'foogallery-migrate' ); ?>
                                <?php echo wp_kses_post ( foogallery_migrate_array_to_table( $gallery->plugin->get_gallery_settings( $gallery, array() ) ) ); ?>
                                <?php  } ?>
                            </td>
                            <td>
                                <?php if ( $foogallery ) {
                                    echo $edit_link;
                                } else { ?>
                                    <input name="foogallery-title-<?php echo esc_attr( $gallery->unique_identifier() ); ?>" value="<?php echo esc_attr( $gallery->title ); ?>">
                                <?php } ?>
                            </td>
                            <td class="foogallery-migrate-progress foogallery-migrate-progress-<?php echo esc_attr( $gallery->migration_status ); ?>">
                                <?php echo esc_html( $gallery->friendly_migration_message() ); ?>
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
                echo '<input type="hidden" class="migrate_progress" value="' . esc_attr( $overall_progress ) . '" />';

                if ( $has_migrations ) { ?>
                    <button name="foogallery_migrate_action" value="foogallery_migrate_continue"
                            class="button button-primary continue_migrate"><?php _e( 'Resume Migration', 'foogallery-migrate' ); ?></button>
                    <button name="foogallery_migrate_action" value="foogallery_migrate_cancel"
                            class="button cancel_migrate"><?php _e( 'Stop Migration', 'foogallery-migrate' ); ?></button>
                <?php } else { ?>
                    <button name="foogallery_migrate_action" value="foogallery_migrate_start"
                            class="button button-primary start_migrate"><?php _e( 'Start Gallery Migration', 'foogallery-migrate' ); ?></button>
                <?php
                }
            }
            if ( $show_refresh ) { ?>
                <button name="foogallery_migrate_action" value="foogallery_refresh_gallery"
                        class="button refresh_gallery"><?php _e( 'Refresh Galleries', 'foogallery-migrate' ); ?></button>
            <?php }
            ?><div id="foogallery_migrate_gallery_spinner" style="width:20px">
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
