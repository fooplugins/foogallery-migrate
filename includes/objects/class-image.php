<?php
/**
 * FooGallery Migrator Image Class
 *
 * @package FooPlugins\FooGalleryMigrate
 */

namespace FooPlugins\FooGalleryMigrate\Objects;

if ( ! class_exists( 'FooPlugins\FooGalleryMigrate\Objects\Image' ) ) {

    /**
     * Class Init
     *
     * @package FooPlugins\FooGalleryMigrate
     */
    class Image extends Migratable {

        function __construct( $plugin = null ) {
            $this->migrated = false;
            $this->plugin = $plugin;
            $this->ID = 0;
            $this->migrated_id = 0;
            $this->migrated_title = '';
            $this->title = '';
            $this->caption = '';
            $this->description = '';
            $this->slug = '';
            $this->alt = '';
            $this->date = '';
            $this->source_url = null;
            $this->url = '';
            $this->error = false;
            $this->data = null;
        }

        function type() {
            return 'image';
        }

        /**
         * The unique identifier for the image.
         *
         * @return string
         */
        function unique_identifier() {
            return $this->source_url;
        }

        /**
         * Checks if the image has already been uploaded to the media library.
         *
         * @return int
         */
        function check_image_already_uploaded() {
            return attachment_url_to_postid( $this->source_url );
        }

        protected function mark_error( $error ) {
            $this->error = $error;
            $this->migration_status = self::PROGRESS_ERROR;
            $this->migrated = true;
        }

        function create_new_migrated_object() {
            // Check if we can get out early!
            if ( $this->migrated && $this->migrated_id > 0 ) {
                return;
            }

            // Check if the file has already been uploaded to the media library.
            $existing_attachment_id = $this->check_image_already_uploaded();
            if ( $existing_attachment_id !== 0 ) {
                $this->migrated_id = $existing_attachment_id;
                if ( ! $this->apply_image_tags_to_attachment() ) {
                    return;
                }
                $this->migrated = true;
                return;
            }

			// Used for testing errors.
			// if ( wp_rand( 1, 100 ) <= 50 ) {
			// 	$this->mark_error( new \WP_Error( 'foogallery_migrate_forced_error', __( 'Forced migration error for testing.', 'foogallery-migrate' ) ) );
			// 	return;
			// }

			@set_time_limit(0);
			wp_raise_memory_limit( 'image' );

            // Use local file paths where possible to avoid HTTP and memory spikes.
            $file = '';
            $guid = '';
            $source_parts = wp_parse_url( $this->source_url );

            if ( $source_parts && ! empty( $source_parts['path'] ) ) {
                $source_path = $source_parts['path'];
                $source_host = isset( $source_parts['host'] ) ? $source_parts['host'] : '';
                $candidate_paths = array();

                $uploads = wp_get_upload_dir();
                $uploads_parts = wp_parse_url( $uploads['baseurl'] );
                $uploads_parts = $uploads_parts ? $uploads_parts : array();
                $uploads_host = isset( $uploads_parts['host'] ) ? $uploads_parts['host'] : '';
                $uploads_path = isset( $uploads_parts['path'] ) ? $uploads_parts['path'] : '';

                $site_parts = wp_parse_url( home_url( '/' ) );
                $site_parts = $site_parts ? $site_parts : array();
                $site_host = isset( $site_parts['host'] ) ? $site_parts['host'] : '';
                $site_path = isset( $site_parts['path'] ) ? untrailingslashit( $site_parts['path'] ) : '';

                if ( ( '' === $source_host || $source_host === $uploads_host ) && $uploads_path && 0 === strpos( $source_path, $uploads_path ) ) {
                    $relative = substr( $source_path, strlen( $uploads_path ) );
                    $candidate_paths[] = wp_normalize_path( trailingslashit( $uploads['basedir'] ) . ltrim( $relative, '/' ) );
                }

                if ( '' === $source_host || $source_host === $site_host ) {
                    if ( $site_path && '/' !== $site_path && 0 === strpos( $source_path, $site_path . '/' ) ) {
                        $relative = substr( $source_path, strlen( $site_path ) );
                        $candidate_paths[] = wp_normalize_path( trailingslashit( ABSPATH ) . ltrim( $relative, '/' ) );
                    } else {
                        $candidate_paths[] = wp_normalize_path( trailingslashit( ABSPATH ) . ltrim( $source_path, '/' ) );
                    }
                }

                foreach ( $candidate_paths as $candidate_path ) {
                    if ( is_readable( $candidate_path ) && ! is_dir( $candidate_path ) ) {
                        $file = $candidate_path;
                        $guid = $this->source_url;
                        break;
                    }
                }
            }

            if ( empty( $file ) ) {
                $imported_with_foogallery = $this->import_tagged_image_with_foogallery();
                if ( null !== $imported_with_foogallery ) {
                    return;
                }

                $this->include_file_helpers();

                $validated_url = wp_http_validate_url( $this->source_url );
                if ( ! $validated_url ) {
                    $this->mark_error( new \WP_Error( 'foogallery_migrate_invalid_source_url', __( 'Invalid source URL for migration.', 'foogallery-migrate' ) ) );
                    return;
                }

                $tmp = download_url( $validated_url, 60 );
                if ( is_wp_error( $tmp ) ) {
                    $this->mark_error( $tmp );
                    return;
                }

                $file_array = array(
                    'name'     => basename( $this->source_url ),
                    'tmp_name' => $tmp,
                );

                $sideload = wp_handle_sideload( $file_array, array( 'test_form' => false ) );
                if ( ! empty( $sideload['error'] ) ) {
                    @unlink( $tmp );
                    $this->mark_error( new \WP_Error( 'foogallery_migrate_sideload_failed', $sideload['error'] ) );
                    return;
                }

                $guid = $sideload['url'];
                $file = $sideload['file'];
            } else {
                $uploads = isset( $uploads ) ? $uploads : wp_get_upload_dir();
                $uploads_basedir = wp_normalize_path( trailingslashit( $uploads['basedir'] ) );
                if ( 0 !== strpos( wp_normalize_path( $file ), $uploads_basedir ) ) {
                    $this->include_file_helpers();

                    $tmp = wp_tempnam( $file );
                    if ( ! $tmp || ! @copy( $file, $tmp ) ) {
                        if ( $tmp ) {
                            @unlink( $tmp );
                        }
                        $this->mark_error( new \WP_Error( 'foogallery_migrate_local_copy_failed', __( 'Unable to copy local image for migration.', 'foogallery-migrate' ) ) );
                        return;
                    }

                    $file_array = array(
                        'name'     => basename( $file ),
                        'tmp_name' => $tmp,
                    );

                    $sideload = wp_handle_sideload( $file_array, array( 'test_form' => false ) );
                    if ( ! empty( $sideload['error'] ) ) {
                        @unlink( $tmp );
                        $this->mark_error( new \WP_Error( 'foogallery_migrate_sideload_failed', $sideload['error'] ) );
                        return;
                    }

                    $guid = $sideload['url'];
                    $file = $sideload['file'];
                }
            }

            $file_type = wp_check_filetype( basename( $file ), null );

            // Create attachment
            $attachment = array(
                'ID'             => 0,
                'guid'           => $guid,
                'post_title'     => $this->title,
                'post_excerpt'   => $this->caption,
                'post_content'   => $this->description,
                'post_date'      => $this->date,
                'post_mime_type' => $file_type['type'],
            );

            // Include image.php so we can call wp_generate_attachment_metadata()
            if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
                require_once(ABSPATH . 'wp-admin/includes/image.php');
            }

            // Insert the attachment
            $this->migrated_id = wp_insert_attachment( $attachment, $file, 0 );
            if ( is_wp_error( $this->migrated_id ) ) {
                $error = $this->migrated_id;
                $this->migrated_id = 0;
                $this->mark_error( $error );
                return;
            }
            $attachment_data = wp_generate_attachment_metadata( $this->migrated_id, $file );
            wp_update_attachment_metadata( $this->migrated_id, $attachment_data );

            // Save alt text in the post meta
            update_post_meta( $this->migrated_id, '_wp_attachment_image_alt', $this->alt );

            $attachment_path = get_attached_file( $this->migrated_id );
            if ( empty( $attachment_path ) || ! file_exists( $attachment_path ) ) {
                $this->mark_error( new \WP_Error( 'foogallery_migrate_missing_file', __( 'Attachment file is missing after migration.', 'foogallery-migrate' ) ) );
                return;
            }

            $this->apply_image_tags_to_attachment();
        }

        /**
         * Imports a tagged image through FooGallery's attachment import helper.
         *
         * FooGallery already supports assigning FOOGALLERY_ATTACHMENT_TAXONOMY_TAG terms
         * from a `tags` array during its import flow, so use that path when available.
         *
         * @return bool|null True/error handled when used, null when skipped.
         */
        private function import_tagged_image_with_foogallery() {
            $image_tags = $this->get_image_tags();
            if ( empty( $image_tags ) || ! function_exists( 'foogallery_import_attachment' ) || ! $this->can_assign_foogallery_image_tags() ) {
                return null;
            }

            $attachment_id = foogallery_import_attachment(
                array(
                    'url'         => $this->source_url,
                    'title'       => $this->title,
                    'caption'     => $this->caption,
                    'description' => $this->description,
                    'alt'         => $this->alt,
                    'tags'        => $image_tags,
                )
            );

            if ( is_wp_error( $attachment_id ) ) {
                $this->mark_error( $attachment_id );
                return false;
            }

            $attachment_id = absint( $attachment_id );
            if ( $attachment_id < 1 ) {
                $this->mark_error( new \WP_Error( 'foogallery_migrate_import_failed', __( 'Image import failed.', 'foogallery-migrate' ) ) );
                return false;
            }

            $this->migrated_id = $attachment_id;
            if ( ! empty( $this->date ) ) {
                wp_update_post(
                    array(
                        'ID'        => $this->migrated_id,
                        'post_date' => $this->date,
                    )
                );
            }

            if ( ! $this->apply_image_tags_to_attachment() ) {
                return false;
            }

            return true;
        }

        /**
         * Applies source image tag names to the migrated FooGallery attachment.
         *
         * @return bool True when term migration completed or was skipped.
         */
        private function apply_image_tags_to_attachment() {
            $image_tags = $this->get_image_tags();
            if ( $this->migrated_id < 1 || empty( $image_tags ) || ! $this->can_assign_foogallery_image_tags() ) {
                return true;
            }

            $result = wp_set_object_terms( $this->migrated_id, $image_tags, FOOGALLERY_ATTACHMENT_TAXONOMY_TAG, false );
            if ( is_wp_error( $result ) ) {
                $this->mark_error( $result );
                return false;
            }

            return true;
        }

        /**
         * Includes WordPress file helpers when the migration needs sideloading.
         *
         * @return void
         */
        private function include_file_helpers() {
            if ( ! function_exists( 'wp_handle_sideload' ) || ! function_exists( 'download_url' ) ) {
                require_once( ABSPATH . 'wp-admin/includes/file.php' );
            }
        }

        /**
         * Gets source image tag names from the owning source plugin.
         *
         * @return array Tag names.
         */
        private function get_image_tags() {
            if ( ! isset( $this->plugin ) || ! is_object( $this->plugin ) || ! method_exists( $this->plugin, 'get_image_tags' ) ) {
                return array();
            }

            $tags = $this->plugin->get_image_tags( $this );
            if ( ! is_array( $tags ) ) {
                return array();
            }

            $normalized_tags = array();
            foreach ( $tags as $tag ) {
                if ( ! is_scalar( $tag ) ) {
                    continue;
                }

                $tag = trim( (string) $tag );
                if ( '' !== $tag && ! in_array( $tag, $normalized_tags, true ) ) {
                    $normalized_tags[] = $tag;
                }
            }

            return $normalized_tags;
        }

        /**
         * Returns true when FooGallery media tags can be assigned.
         *
         * @return bool
         */
        private function can_assign_foogallery_image_tags() {
            return defined( 'FOOGALLERY_ATTACHMENT_TAXONOMY_TAG' ) &&
                function_exists( 'taxonomy_exists' ) &&
                taxonomy_exists( FOOGALLERY_ATTACHMENT_TAXONOMY_TAG ) &&
                function_exists( 'wp_set_object_terms' );
        }
    }
}
