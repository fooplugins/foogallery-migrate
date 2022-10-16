<?php
/**
 * FooGallery Migrator Image Class
 *
 * @package FooPlugins\FooGalleryMigrate
 */

namespace FooPlugins\FooGalleryMigrate;

if ( ! class_exists( 'FooPlugins\FooGalleryMigrate\Image' ) ) {

    /**
     * Class Init
     *
     * @package FooPlugins\FooGalleryMigrate
     */
    class Image extends \stdClass {

        function __construct() {
            $this->migrated = false;
            $this->attachment_id = 0;
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

        /**
         * Checks if the image has already been uploaded to the media library.
         * TODO : this will not find images that are uploaded in different months.
         *   Eg if the image was originally migrated in 2022/06 and then again in 2022/07 it would not
         *   be found and a duplicate would be uploaded, as the base upload folder would have changed.
         *
         * @return int
         */
        function check_image_already_uploaded() {
            $upload = wp_upload_dir();

            $image_url = trailingslashit( $upload['url'] ) . basename( $this->source_url );

            return attachment_url_to_postid( $image_url );
        }

        /**
         * Migrate the image by uploading to the media library.
         *
         * @return bool
         */
        function migrate() {

            // Check if we can get out early!
            if ( $this->migrated && $this->attachment_id > 0 ) {
                return true;
            }

            // Check if the file has already been uploaded to the media library.
            $existing_attachment_id = $this->check_image_already_uploaded();
            if ( $existing_attachment_id !== 0 ) {
                $this->attachment_id = $existing_attachment_id;
                $this->migrated = true;
                return true;
            }

            // Get the contents of the picture
            $response = wp_remote_get( $this->source_url );
            if ( is_wp_error( $response ) ) {
                $this->error = $response;
                $this->migrated = true;
                return false;
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
                'post_excerpt'   => $this->description,
                'post_content'   => $this->description,
                'post_date'      => $this->date,
                'post_mime_type' => $file_type['type'],
            );

            // Include image.php so we can call wp_generate_attachment_metadata()
            require_once( ABSPATH . 'wp-admin/includes/image.php' );

            // Insert the attachment
            $this->attachment_id = wp_insert_attachment( $attachment, $file, 0 );
            if ( is_wp_error( $this->attachment_id ) ) {
                $this->error = $this->attachment_id;
                $this->migrated = true;
                return false;
            }
            $attachment_data = wp_generate_attachment_metadata( $this->attachment_id, $file );
            wp_update_attachment_metadata( $this->attachment_id, $attachment_data );

            // Save alt text in the post meta
            update_post_meta( $this->attachment_id, '_wp_attachment_image_alt', $this->alt );

            return true;
        }
    }
}