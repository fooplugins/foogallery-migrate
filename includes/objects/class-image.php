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

            // Get the contents of the picture
            $response = wp_remote_get( $this->source_url );
            if ( is_wp_error( $response ) ) {
                $this->error = $response;
                $this->migrated = true;
                return;
            }
            $contents = wp_remote_retrieve_body( $response );

            // Upload and get file data
            $upload    = wp_upload_bits( basename( $this->source_url ), null, $contents );
            $guid      = $upload['url'];
            $file      = $upload['file'];
            $file_type = wp_check_filetype( basename( $file ), null );

            // Create attachment
            $attachment = array(
                'ID'             => 0,
                'guid'           => $guid,
                'post_title'     => $this->slug,
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
                $this->error = $this->migrated_id;
                $this->migrated_id = 0;
                $this->migrated = true;
            }
            $attachment_data = wp_generate_attachment_metadata( $this->migrated_id, $file );
            wp_update_attachment_metadata( $this->migrated_id, $attachment_data );

            // Save alt text in the post meta
            update_post_meta( $this->migrated_id, '_wp_attachment_image_alt', $this->alt );
        }
    }
}