<?php
/**
 * FooGallery Migrator Album Class
 *
 * @package FooPlugins\FooGalleryMigrate
 */

namespace FooPlugins\FooGalleryMigrate\Objects;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'FooPlugins\FooGalleryMigrate\Objects\Album' ) ) {

    /**
     * Class Init
     *
     * @package FooPlugins\FooGalleryMigrate
     *
     */
    class Album extends Migratable {

        function type() {
            return 'album';
        }

        function has_children() {
            return true;
        }

        function children_name() {
            return 'galleries';
        }

        function friendly_migration_message () {
            if ( self::PROGRESS_STARTED === $this->migration_status ) {
                return sprintf( __('Migrated %d of %d %s (%d of %d images) ', 'foogallery-migrate'),
                    $this->migrated_child_count, $this->get_children_count(), $this->children_name(), $this->get_total_migrated_images(), $this->get_total_images() );
            }
            return parent::friendly_migration_message();
        }

        function get_total_images() {
            $image_count = 0;
            foreach ( $this->get_children() as $child ) {
                $image_count += $child->get_children_count();
            }
            return $image_count;
        }

        function get_total_migrated_images() {
            $image_count = 0;
            foreach ( $this->get_children() as $child ) {
                $image_count += $child->migrated_child_count;
            }
            return $image_count;
        }

        function create_new_migrated_object() {
            // Create an album
            
            if ( $this->migrated_id === 0 ) {
                $album_title = $this->title;
                if ( isset( $this->migrated_title ) && '' !== trim( (string) $this->migrated_title ) ) {
                    $album_title = $this->migrated_title;
                }
                $override_album_settings_id = foogallery_migrate_migrator_instance()->get_override_album_settings();

                $this->migrated_id = wp_insert_post( array(
                    'post_title' => $album_title,
                    'post_type' => FOOGALLERY_CPT_ALBUM,
                    'post_status' => 'publish',
                ) );

                if ( is_wp_error( $this->migrated_id ) ) {
                    $this->migration_status = self::PROGRESS_ERROR;
                } else {
                    $album_template = 'default';
                    if ( $override_album_settings_id > 0 ) {
                        $source_album_template = get_post_meta( $override_album_settings_id, FOOGALLERY_ALBUM_META_TEMPLATE, true );
                        if ( is_scalar( $source_album_template ) && '' !== trim( (string) $source_album_template ) ) {
                            $album_template = $source_album_template;
                        }
                    }

                    update_post_meta( $this->migrated_id, FOOGALLERY_ALBUM_META_TEMPLATE, $album_template );

                    if ( $override_album_settings_id > 0 ) {
                        $this->inherit_album_settings_from( $override_album_settings_id );
                    }
                }
            }            
        }

        /**
         * Inherit album display settings from an existing FooGallery album.
         *
         * @param int $source_album_id Source album post ID.
         * @return void
         */
        function inherit_album_settings_from( $source_album_id ) {
            if ( defined( 'FOOGALLERY_META_SETTINGS_OLD' ) ) {
                $settings = get_post_meta( $source_album_id, FOOGALLERY_META_SETTINGS_OLD, true );
                if ( ! empty( $settings ) ) {
                    update_post_meta( $this->migrated_id, FOOGALLERY_META_SETTINGS_OLD, $settings );
                }
            }

            if ( defined( 'FOOGALLERY_ALBUM_META_SORT' ) ) {
                $sort = get_post_meta( $source_album_id, FOOGALLERY_ALBUM_META_SORT, true );
                if ( is_scalar( $sort ) && '' !== trim( (string) $sort ) ) {
                    update_post_meta( $this->migrated_id, FOOGALLERY_ALBUM_META_SORT, $sort );
                }
            }

            if ( defined( 'FOOGALLERY_META_CUSTOM_CSS' ) ) {
                $custom_css = get_post_meta( $source_album_id, FOOGALLERY_META_CUSTOM_CSS, true );
                if ( is_scalar( $custom_css ) && '' !== trim( (string) $custom_css ) ) {
                    update_post_meta( $this->migrated_id, FOOGALLERY_META_CUSTOM_CSS, $custom_css );
                }
            }
        }

        /**
         * Migrate the next gallery.
         *
         * @return void
         */
        function migrate_next_child() {
            parent::migrate_next_child();        
            $galleries = $this->build_child_migrated_id_array();
            update_post_meta( $this->migrated_id, FOOGALLERY_ALBUM_META_GALLERIES, $galleries );
        }
    }
}
