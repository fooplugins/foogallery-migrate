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

        /**
         * The unique identifier for the album.
         *
         * @return string
         */
        function unique_identifier() {
            return 'album_' . parent::unique_identifier();
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

        /**
         * Migrate the album!
         *
         * TODO : there is a lot of duplicated logic that already exists in the gallery glass.
         *
         * @return void
         */
        // function migrate() {
        //     if ( !$this->migrated ) {
            
        //         if ( $this->gallery_count == 0 ) {
        //             $this->migration_status = self::PROGRESS_ERROR;
        //         } else {
        //             $this->migration_status = self::PROGRESS_STARTED;

        //             if($this->fooalbum_id === 0) {
                     
        //                 $fooalbum_args = array(
        //                     'post_title' => $this->title,
        //                     'post_type' => FOOGALLERY_CPT_ALBUM,
        //                     'post_status' => 'publish',
        //                 );
        //                 $album_id = wp_insert_post( $fooalbum_args );
        //                 $this->fooalbum_id = $album_id;
        //             }

        //             update_post_meta($album_id, self::FOOGALLERY_ALBUM_TEMPLATE, $this->album_template);

        //             foreach($this->galleries as $gallery) {

        //                 if ( $this->migration_status !== self::PROGRESS_ERROR && $this->migrated_gallery_count < $this->gallery_count ) {

        //                     if ( !$gallery->migrated && intval( $gallery->foogallery_id ) === 0 ) {

        //                         if ( $gallery->foogallery_id === 0 ) {
        //                             // Create an empty foogallery
        //                             $foogallery_args = array(
        //                                 'post_title' => $gallery->foogallery_title,
        //                                 'post_type' => FOOGALLERY_CPT_GALLERY,
        //                                 'post_status' => 'publish',
        //                             );
        //                             $gallery->foogallery_id = wp_insert_post( $foogallery_args );

        //                             if ( is_wp_error( $gallery->foogallery_id ) ) {
        //                                 $this->migration_status = self::PROGRESS_ERROR;
        //                             } else {

        //                                 $added_galleries = get_post_meta( $album_id, self::FOOGALLERY_ALBUM_GALLERIES, true);
        //                                 if(empty($added_galleries)) {
        //                                     $added_galleries = array();
        //                                 }                    

        //                                 $additional_galleries = array( $gallery->foogallery_id );
        //                                 $merge_all_galleries = array_merge( $additional_galleries, $added_galleries );

        //                                 update_post_meta( $album_id, self::FOOGALLERY_ALBUM_GALLERIES, $merge_all_galleries );

        //                                 // Determine the best possible gallery template.
        //                                 $gallery_template = $this->plugin->get_gallery_template( $gallery );

        //                                 if ( empty( $gallery_template ) ) {
        //                                     $gallery_template = foogallery_default_gallery_template();
        //                                 }

        //                                 // Set the gallery template.
        //                                 add_post_meta( $gallery->foogallery_id, FOOGALLERY_META_TEMPLATE, $gallery_template, true );

        //                                 $gallery_settings = array();
        //                                 //set default settings if there are any
        //                                 $default_gallery_id = foogallery_get_setting( 'default_gallery_settings' );
        //                                 if ( !empty( $default_gallery_id ) ) {
        //                                     $gallery_settings = get_post_meta( $default_gallery_id, FOOGALLERY_META_SETTINGS, true );
        //                                 }

        //                                 // Determine the best possible settings for the gallery.
        //                                 $gallery_settings = $this->plugin->get_gallery_settings( $gallery, $gallery_settings );

        //                                 // Set the gallery settings.
        //                                 add_post_meta( $gallery->foogallery_id, FOOGALLERY_META_SETTINGS, $gallery_settings, true );
        //                             }

        //                             //set default settings if there are any
        //                             $default_gallery_id = foogallery_get_setting( 'default_gallery_settings' );
        //                             if ( $default_gallery_id ) {
        //                                 $settings = get_post_meta( $default_gallery_id, FOOGALLERY_META_SETTINGS, true );
        //                                 add_post_meta( $gallery->foogallery_id, FOOGALLERY_META_SETTINGS, $settings, true );
        //                             }

        //                             //migrate settings
        //                             $this->plugin->get_gallery_template( $gallery );

        //                             foreach($gallery->images as $image) {
        //                                 $image->migrate();
        //                             }

        //                             $attachments = $this->build_attachment_array($gallery);
        //                             update_post_meta( $gallery->foogallery_id, FOOGALLERY_META_ATTACHMENTS, $attachments );  

        //                             $this->migrated_gallery_count++;

        //                         }

        //                     } else {
        //                         $this->migrated_gallery_count++;
        //                     }                       

        //                     $added_galleries = get_post_meta( $album_id, self::FOOGALLERY_ALBUM_GALLERIES, true);
        //                     if(empty($added_galleries)) {
        //                         $added_galleries = array();
        //                     }                                

        //                     $additional_galleries = array( $gallery->foogallery_id );
        //                     $merge_all_galleries = array_merge( $additional_galleries, $added_galleries );

        //                     update_post_meta( $album_id, self::FOOGALLERY_ALBUM_GALLERIES, $merge_all_galleries );
 

        //                 }                        

        //             }

        //             $this->calculate_progress();
        //         }
        //     }
        // }

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