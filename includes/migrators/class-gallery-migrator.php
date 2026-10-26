<?php
/**
 * FooGallery GalleryMigrator Class
 *
 * @package FooPlugins\FooGalleryMigrate
 */

namespace FooPlugins\FooGalleryMigrate\Migrators;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

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
            $repository = $this->table_collection_repository();
            if ( null === $repository ) { $this->render_gallery_form_body( null ); return; }
            ob_start();
            try {
                $context = $this->render_gallery_form_body( $repository );
                if ( $repository->view_context( $this->type ) !== $context ) { throw new \RuntimeException( 'Galleries changed during form rendering.' ); }
                $html = ob_get_clean();
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Buffered render_gallery_form_body markup; source fields use esc_html/esc_attr/esc_url and debug markup uses wp_kses_post. Preserve form controls after the revision check.
                echo $html;
            } catch ( \Exception $error ) { ob_end_clean(); throw $error; }
            catch ( \Throwable $error ) { ob_end_clean(); throw $error; }
        }

        private function render_gallery_form_body( $repository ) {
            $context = null;
            if ( null === $repository ) { $galleries = $this->get_objects_to_migrate(); $gallery_count = count( $galleries ); }
            else {
                $context = $repository->view_context( $this->type );
                if ( ! $context['found'] ) { $this->get_objects_to_migrate(); $context = $repository->view_context( $this->type ); }
                if ( ! $context['found'] ) { throw new \RuntimeException( 'Gallery discovery did not publish a collection.' ); }
                $galleries = array(); $gallery_count = $context['total'];
            }
            wp_nonce_field( 'foogallery_migrate', 'foogallery_migrate', false );

            if ( $gallery_count === 0 ) {
                echo '<p>' . esc_html__( 'No galleries found!', 'foogallery-migrate' ) . '</p>';
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
                // Reuse the bounded current-root lookup; do not load its image
                // list or rely on the active gallery being on the visible page.
                $current_gallery = null;
                if ( null !== $repository ) {
                    $started = $repository->first_started( $this->type );
                    if ( $started['found'] ) { $current_gallery = $started['value']; }
                } else {
                    foreach ( $galleries as $candidate ) {
                        if ( Migratable::PROGRESS_STARTED === $candidate->migration_status ) {
                            $current_gallery = $candidate; break;
                        }
                    }
                }
                $current_gallery_id = null === $current_gallery ? 0 : $current_gallery->unique_identifier();
                $current_image_total = null === $current_gallery ? 0 : $current_gallery->get_children_count();
                $current_image_processed = null === $current_gallery ? 0 : min( $current_image_total, absint( $current_gallery->migrated_child_count ) );
                $show_refresh = !$has_migrations;
                ?>
                <table class="wp-list-table widefat fixed striped table-view-list pages">
                    <thead>
                        <tr>
                            <td id="cb" class="manage-column column-cb check-column">
                                <?php if ( ! $migrating ) { ?>
                                    <label class="screen-reader-text" for="foogallery-migrate-gallery-select-all"><?php esc_html_e( 'Select All', 'foogallery-migrate' ); ?></label>
                                    <input id="foogallery-migrate-gallery-select-all" class="foogallery-migrate-gallery-select-all" type="checkbox" <?php echo $migrating ? 'disabled="disabled"' : ''; ?> checked="checked" />
                                <?php } ?>
                            </td>
                            <th scope="col" class="manage-column">
                                <span><?php esc_html_e( 'Gallery', 'foogallery-migrate' ); ?></span>
                            </th>
                            <th scope="col" class="manage-column">
                                <span><?php esc_html_e( 'Source', 'foogallery-migrate' ); ?></span>
                            </th>
                            <th scope="col" class="manage-column">
                                <span><?php esc_html_e( 'Migration Data', 'foogallery-migrate' ); ?></span>
                            </th>
                            <th scope="col" class="manage-column">
                                <span><?php /* translators: %s: destination plugin name. */ printf( esc_html__( '%s Name', 'foogallery-migrate' ), esc_html( foogallery_plugin_name() ) ); ?></span>
                            </th>
                            <th scope="col" class="manage-column">
                                <span><?php esc_html_e( 'Migration Progress', 'foogallery-migrate' ); ?></span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php

                    $url = foogallery_migrate_admin_url( 'galleries' );
                    $page = 1;
                    if ( defined( 'DOING_AJAX' ) ) {
                        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Display pagination only; migration AJAX controllers verify capability and nonce before calling this renderer.
                        if ( isset( $_POST['foogallery_migrate_paged'] ) && is_scalar( $_POST['foogallery_migrate_paged'] ) ) {
                            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- A string pagination URL is sanitized below and escaped when rendered; this value cannot start a migration.
                            if ( isset( $_POST['foogallery_migrate_url'] ) && is_string( $_POST['foogallery_migrate_url'] ) ) {
                                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only pagination URL after the authenticated controller; sanitize it for URL use here.
                                $url = esc_url_raw( wp_unslash( $_POST['foogallery_migrate_url'] ) );
                            }
                            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only page number, normalized with absint and bounded below; mutations retain their controller nonce checks.
                            $page = absint( wp_unslash( $_POST['foogallery_migrate_paged'] ) );
                        } else {
                            $referer = wp_get_referer();
                            if ( is_string( $referer ) && '' !== $referer ) { $url = $referer; }
                            $parts = wp_parse_url( $url );
                            $query = array();
                            if ( isset( $parts['query'] ) ) { parse_str( $parts['query'], $query ); }
                            $page = isset( $query['gallery_paged'] ) ? absint( $query['gallery_paged'] ) : 1;
                        }
                    } else if ( array_key_exists( 'gallery_paged', $_GET ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page navigation, not a migration command.
                        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Normalize the read-only page number before choosing the visible rows.
                        $page = absint( wp_unslash( $_GET['gallery_paged'] ) );
                    }
                    $page = max( 1, $page );
                    $url = add_query_arg( 'gallery_paged', $page, $url );
                    $preflight_gallery_count = 0;
                    $preflight_image_count = 0;
                    $page_size = $this->migrator_engine->get_page_size();
                    $show_pagination = $page_size > 0;

                    if ( $show_pagination ) {
                        $pagination = new Pagination();
                        $pagination->items( $gallery_count );
                        $pagination->limit( $page_size ); // Limit entries per page
                        $pagination->parameterName( 'gallery_paged' );
                        $pagination->url = $url;
                        $pagination->currentPage( $page );
                        $pagination->calculate();
                        $start = $pagination->start;
                        $end = $pagination->end;
                    } else {
                        $start = 0;
                        $end = $gallery_count - 1;
                    }

                    $table_rows = array(); $table_end = $start;
                    for ($counter = $start; $counter <= $end; $counter++ ) {
                        if ( $counter >= $gallery_count ) {
                            break;
                        }
                        if ( null !== $repository ) {
                            if ( $counter >= $table_end ) {
                                $take = min( 100, $end - $counter + 1, $gallery_count - $counter );
                                $chunk = $repository->view_page( $this->type, $counter, $take );
                                if ( $chunk['head'] !== $context['head'] || count( $chunk['rows'] ) !== $take ) { throw new \RuntimeException( 'Gallery page changed during form rendering.' ); }
                                $table_rows = $chunk['rows']; $table_end = $counter + $take;
                            }
                            $gallery = $table_rows[$counter - ( $table_end - count( $table_rows ) )]['value'];
                        } else { $gallery = $galleries[$counter]; }
                        $progress    = $gallery->progress;
                        $done        = $gallery->migrated;
                        $edit_link   = '';
                        $foogallery = false;
                        if ( $gallery->migrated_id > 0 ) {
                            $foogallery = \FooGallery::get_by_id( $gallery->migrated_id );
                            if ( $foogallery ) {
                                $edit_link = '<a target="_blank" href="' . esc_url( admin_url( 'post.php?post=' . $foogallery->ID . '&action=edit' ) ) . '">' . esc_html( $foogallery->name ) . '</a>';
                            } else {
                                $done = false;
                            }
                        } 
                        $image_count = $gallery->get_children_count();
                        $can_select_gallery = !$has_migrations && !$migrating && !$done;

                        if ( $can_select_gallery ) {
                            $preflight_gallery_count++;
                            $preflight_image_count += $image_count;
                        }
						
						?>
                        <tr class="<?php echo esc_attr( ($counter % 2 === 0) ? 'alternate' : '' ); ?>">
                            <?php if ( $can_select_gallery ) { ?>
                                <th scope="row" class="column-cb check-column">
                                    <input class="foogallery-migrate-gallery-select" name="gallery-id[]" type="checkbox" checked="checked" value="<?php echo esc_attr( $gallery->unique_identifier() ); ?>" data-image-count="<?php echo esc_attr( $image_count ); ?>">
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
                                <?php if ( $this->migrator_engine->is_debug_enabled() && isset( $gallery->settings ) ) { ?>
                                    <br />
                                    <?php echo wp_kses_post( foogallery_migrate_array_to_table( $gallery->settings ) );
                                } ?>
                            </td>
                            <td>
                                <?php echo esc_html( $gallery->plugin->name() ); ?>
                            </td>
                            <td>
                                <?php esc_html_e( 'Layout : ', 'foogallery-migrate' ); ?>
                                <?php echo esc_html( $gallery->plugin->get_migration_gallery_template( $gallery ) ); ?>
                                <br />
                                <?php esc_html_e( 'Images : ', 'foogallery-migrate' ); ?>
                                <?php echo esc_html( $image_count ); ?>
                                <?php if ( $this->migrator_engine->is_debug_enabled() ) { ?>
                                <br />
                                <?php esc_html_e( 'Settings : ', 'foogallery-migrate' ); ?>
                                <?php echo wp_kses_post ( foogallery_migrate_array_to_table( $gallery->plugin->get_gallery_settings( $gallery, array() ) ) ); ?>
                                <?php  } ?>
                            </td>
                            <td>
                                <?php if ( $foogallery ) {
                                    echo wp_kses_post( $edit_link );
                                } else { ?>
                                    <input name="foogallery-title-<?php echo esc_attr( $gallery->unique_identifier() ); ?>" value="<?php echo esc_attr( $gallery->title ); ?>">
                                <?php } ?>
                            </td>
                            <td class="foogallery-migrate-progress foogallery-migrate-progress-<?php echo esc_attr( $gallery->migration_status ); ?>">
                                <?php echo esc_html( $gallery->friendly_migration_message() ); ?>
                                <?php if ( ! $done ) { ?>
									<br />
                                    <button type="button" class="button retry_migrate_gallery" data-gallery-id="<?php echo esc_attr( $gallery->unique_identifier() ); ?>">
                                        <?php esc_html_e( 'Migrate', 'foogallery-migrate' ); ?>
                                    </button>
                                <?php } elseif ( $gallery->migration_status === \FooPlugins\FooGalleryMigrate\Objects\Migratable::PROGRESS_COMPLETED ) { ?>
                                    <br />
                                    <button type="button" class="button check_migrate_gallery" data-gallery-id="<?php echo esc_attr( $gallery->unique_identifier() ); ?>">
                                        <?php esc_html_e( 'Check', 'foogallery-migrate' ); ?>
                                    </button>
                                <?php } ?>
								<?php if ( $gallery->has_children() ) {
									$error_rows = array();
									foreach ( $gallery->get_children() as $child ) {
										if ( ! is_object( $child ) ) {
											continue;
										}
										$has_error = false;
										if ( method_exists( $child, 'has_error' ) ) {
											$has_error = $child->has_error();
										}
										if ( $has_error ) {
											$source_url = isset( $child->source_url ) ? $child->source_url : '';
											$error_rows[] = array(
												'source_url' => $source_url,
												'message'    => $child->get_error_message(),
											);
										}
									}

									// Compact saved galleries retain error messages without their
									// image objects. Display these without loading the image list.
									if ( empty( $error_rows ) ) {
										foreach ( $gallery->get_children_errors() as $message ) {
											$error_rows[] = array( 'source_url' => '', 'message' => $message );
										}
									}
									$error_count = count( $error_rows );
									/* translators: %d: image error count. */
									$error_label = sprintf( _n( '%d error', '%d errors', $error_count, 'foogallery-migrate' ), $error_count );
									?>
									<?php if ( $error_count > 0 ) { ?>
										<br />
										<?php /* translators: %s: localized error count. */ echo esc_html( sprintf( __( 'Errors: %s', 'foogallery-migrate' ), $error_label ) ); ?>
										<details class="foogallery-migrate-error-details">
											<summary><?php esc_html_e( 'View error details', 'foogallery-migrate' ); ?></summary>
											<table class="widefat striped">
												<thead>
													<tr>
														<th><?php esc_html_e( 'Source URL', 'foogallery-migrate' ); ?></th>
														<th><?php esc_html_e( 'Error', 'foogallery-migrate' ); ?></th>
													</tr>
												</thead>
												<tbody>
												<?php foreach ( $error_rows as $error_row ) { ?>
													<tr>
														<td>
															<?php if ( ! empty( $error_row['source_url'] ) ) { ?>
																<a href="<?php echo esc_url( $error_row['source_url'] ); ?>" target="_blank" rel="noreferrer noopener"><?php echo esc_html( $error_row['source_url'] ); ?></a>
															<?php } else { ?>
																<?php esc_html_e( 'Unknown', 'foogallery-migrate' ); ?>
															<?php } ?>
														</td>
														<td><?php echo esc_html( $error_row['message'] ); ?></td>
													</tr>
												<?php } ?>
												</tbody>
											</table>
										</details>
									<?php } ?>
									<?php if ( $error_count > 0 || ( $done && Migratable::PROGRESS_ERROR === $gallery->migration_status ) ) { ?>
                                        <button type="button" class="button retry_migrate_gallery" data-gallery-id="<?php echo esc_attr( $gallery->unique_identifier() ); ?>">
                                            <?php esc_html_e( 'Retry Migration', 'foogallery-migrate' ); ?>
                                        </button>
									<?php } ?>
								<?php } ?>
                            </td>
                        </tr>
                        <?php
                    }
                    ?>
                    </tbody>
                </table>
                <p class="foogallery-migrate-table-total">
                    <?php /* translators: %d: gallery count. */ echo esc_html( sprintf( _n( 'Total galleries: %d', 'Total galleries: %d', $gallery_count, 'foogallery-migrate' ), $gallery_count ) ); ?>
                </p>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <?php if ( $show_pagination ) { echo wp_kses_post( $pagination->render( false ) ); } ?>
                    </div>
                </div>

                <?php
                //hidden fields used for pagination
                echo '<input type="hidden" name="foogallery_migrate_paged" value="' . esc_attr( $page ) . '" />';
                echo '<input type="hidden" name="foogallery_migrate_url" value="' . esc_url( $url ) . '" />';
                echo '<input type="hidden" class="migrate_progress" value="' . esc_attr( $overall_progress ) . '" />';
                echo '<input type="hidden" class="foogm-migration-summary" data-queued="' . esc_attr( false === $migration_state ? 0 : $migration_state['queued'] ) . '" data-completed="' . esc_attr( false === $migration_state ? 0 : $migration_state['completed'] ) . '" data-image-title="' . esc_attr( null === $current_gallery ? '' : $current_gallery->title ) . '" data-image-processed="' . esc_attr( $current_image_processed ) . '" data-image-total="' . esc_attr( $current_image_total ) . '" />';
                echo '<input type="hidden" name="foogallery_migrate_retry_gallery_id" value="" />';
                echo '<input type="hidden" name="foogallery_migrate_check_gallery_id" value="" />';

                if ( $has_migrations ) { ?>
                    <button name="foogallery_migrate_action" value="foogallery_migrate_continue"
                            class="button button-primary continue_migrate"><?php esc_html_e( 'Resume Migration', 'foogallery-migrate' ); ?></button>
                    <button name="foogallery_migrate_action" value="foogallery_migrate_cancel"
                            class="button cancel_migrate"><?php esc_html_e( 'Stop Migration', 'foogallery-migrate' ); ?></button>
                <?php } else { ?>
                    <p class="foogallery-migrate-preflight-report">
                        <strong><?php esc_html_e( 'Preflight:', 'foogallery-migrate' ); ?></strong>
                        <?php
                        printf(
                            /* translators: 1: selected gallery count, 2: selected image count. */
                            esc_html__( '%1$s galleries selected; %2$s images will be migrated.', 'foogallery-migrate' ),
                            '<span data-role="gallery-count">' . esc_html( number_format_i18n( $preflight_gallery_count ) ) . '</span>',
                            '<span data-role="image-count">' . esc_html( number_format_i18n( $preflight_image_count ) ) . '</span>'
                        );
                        ?>
                    </p>
                    <button name="foogallery_migrate_action" value="foogallery_migrate_start"
                            class="button button-primary start_migrate"><?php esc_html_e( 'Start Gallery Migration', 'foogallery-migrate' ); ?></button>
                <?php
                }
            }
            if ( $show_refresh ) { ?>
                <button name="foogallery_migrate_action" value="foogallery_refresh_gallery"
                        class="button refresh_gallery"><?php esc_html_e( 'Refresh Galleries', 'foogallery-migrate' ); ?></button>
            <?php }
            return $context;
        }
	}
}
