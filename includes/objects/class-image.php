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

        function __construct() {
            $this->migrated = false;
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
            require_once( ABSPATH . 'wp-admin/includes/file.php' );

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
            require_once(ABSPATH . 'wp-admin/includes/image.php');

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
        }
    }
}
