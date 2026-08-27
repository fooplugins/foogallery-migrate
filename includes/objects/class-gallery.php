<?php
/**
 * FooGallery Migrator Gallery Class
 *
 * @package FooPlugins\FooGalleryMigrate
 */

namespace FooPlugins\FooGalleryMigrate\Objects;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

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

        /**
         * Returns how many images can be migrated in one migration turn.
         *
         * @return int
         */
        function get_children_per_turn() {
            return foogallery_migrate_migrator_instance()->get_images_per_turn();
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
                $gallery_template = $this->plugin->get_migration_gallery_template( $this );

                if ( empty( $gallery_template ) ) {
                    $gallery_template = foogallery_default_gallery_template();
                }

                // Set the gallery template.
                add_post_meta( $this->migrated_id, FOOGALLERY_META_TEMPLATE, $gallery_template, true );

                $gallery_settings = $this->get_initial_gallery_settings();

                // Determine the best possible settings for the gallery.
                $gallery_settings = $this->plugin->get_gallery_settings( $this, $gallery_settings );

                // Set the gallery settings.
                add_post_meta( $this->migrated_id, FOOGALLERY_META_SETTINGS, $gallery_settings, true );

                $this->inherit_gallery_custom_css();
            }
        }

        /**
         * Gets the initial settings for a migrated gallery.
         *
         * @return array
         */
        function get_initial_gallery_settings() {
            $gallery_settings = array();
            $override_gallery_settings_id = foogallery_migrate_migrator_instance()->get_override_gallery_settings();

            if ( $override_gallery_settings_id > 0 ) {
                $gallery_settings = get_post_meta( $override_gallery_settings_id, FOOGALLERY_META_SETTINGS, true );
            } else {
                //set default settings if there are any
                $default_gallery_id = foogallery_get_setting( 'default_gallery_settings' );
                if ( ! empty( $default_gallery_id ) ) {
                    $gallery_settings = get_post_meta( $default_gallery_id, FOOGALLERY_META_SETTINGS, true );
                }
            }

            return is_array( $gallery_settings ) ? $gallery_settings : array();
        }

        /**
         * Inherit gallery custom CSS from the selected settings source.
         *
         * @return void
         */
        function inherit_gallery_custom_css() {
            if ( ! defined( 'FOOGALLERY_META_CUSTOM_CSS' ) ) {
                return;
            }

            $override_gallery_settings_id = foogallery_migrate_migrator_instance()->get_override_gallery_settings();
            if ( $override_gallery_settings_id < 1 ) {
                return;
            }

            $custom_css = get_post_meta( $override_gallery_settings_id, FOOGALLERY_META_CUSTOM_CSS, true );
            if ( is_scalar( $custom_css ) && '' !== trim( (string) $custom_css ) ) {
                update_post_meta( $this->migrated_id, FOOGALLERY_META_CUSTOM_CSS, $custom_css );
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
