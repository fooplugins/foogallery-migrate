<?php
/**
 * FooGallery Migrator Gallery Class
 *
 * @package FooPlugins\FooGalleryMigrate
 */

namespace FooPlugins\FooGalleryMigrate\Objects;

if ( ! class_exists( 'FooPlugins\FooGalleryMigrate\Objects\Gallery' ) ) {

    /**
     * Class Init
     *
     * @package FooPlugins\FooGalleryMigrate
     */
    class Gallery extends Migratable {

        function type() {
            return 'gallery';
        }

        function has_children() {
            return true;
        }

        function children_name() {
            return 'images';
        }

        function create_new_migrated_object() {
            // Create an empty foogallery
            $foogallery_args = array(
                'post_title' => $this->title,
                'post_type' => FOOGALLERY_CPT_GALLERY,
                'post_status' => 'publish',
            );
            $this->migrated_id = wp_insert_post( $foogallery_args );

            if ( is_wp_error( $this->migrated_id ) ) {
                $this->migration_status = self::PROGRESS_ERROR;
            } else {

                // Determine the best possible gallery template.
                $gallery_template = $this->plugin->get_gallery_template( $this );

                if ( empty( $gallery_template ) ) {
                    $gallery_template = foogallery_default_gallery_template();
                }

                // Set the gallery template.
                add_post_meta( $this->migrated_id, FOOGALLERY_META_TEMPLATE, $gallery_template, true );

                $gallery_settings = array();
                //set default settings if there are any
                $default_gallery_id = foogallery_get_setting( 'default_gallery_settings' );
                if ( !empty( $default_gallery_id ) ) {
                    $gallery_settings = get_post_meta( $default_gallery_id, FOOGALLERY_META_SETTINGS, true );
                }

                // Determine the best possible settings for the gallery.
                $gallery_settings = $this->plugin->get_gallery_settings( $this, $gallery_settings );

                // Set the gallery settings.
                add_post_meta( $this->migrated_id, FOOGALLERY_META_SETTINGS, $gallery_settings, true );
            }
        }

        /**
         * Migrate the next attachment.
         *
         * @return void
         */
        function migrate_next_child() {
            parent::migrate_next_child();

            $attachments = $this->build_child_migrated_id_array();
            update_post_meta( $this->migrated_id, FOOGALLERY_META_ATTACHMENTS, $attachments );
        }
    }
}