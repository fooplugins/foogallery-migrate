<?php
/**
 * FooGallery Migrator Album Class
 *
 * @package FooPlugins\FooGalleryMigrate
 */

namespace FooPlugins\FooGalleryMigrate\Objects;

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
                $this->migrated_id = wp_insert_post( array(
                    'post_title' => $this->title,
                    'post_type' => FOOGALLERY_CPT_ALBUM,
                    'post_status' => 'publish',
                ) );

                if ( is_wp_error( $this->migrated_id ) ) {
                    $this->migration_status = self::PROGRESS_ERROR;
                } else {
                    update_post_meta( $this->migrated_id, FOOGALLERY_ALBUM_META_TEMPLATE, 'default' );
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