<?php
/**
 * FooGallery Migrator Album Class
 *
 * @package FooPlugins\FooGalleryMigrate
 */

namespace FooPlugins\FooGalleryMigrate;

if ( ! class_exists( 'FooPlugins\FooGalleryMigrate\Album' ) ) {

    /**
     * Class Init
     *
     * @package FooPlugins\FooGalleryMigrate
     */
    class Album extends \stdClass {

        const PROGRESS_NOT_STARTED = 'not_started';
        const PROGRESS_QUEUED = 'queued';
        const PROGRESS_STARTED = 'started';
        const PROGRESS_COMPLETED = 'completed';
        const PROGRESS_ERROR = 'error';
        const FOOGALLERY_ALBUM_GALLERIES = 'foogallery_album_galleries';
        const FOOGALLERY_ALBUM_TEMPLATE = 'foogallery_album_template';

        function __construct( $plugin ) {
            $this->migrated = false;
            $this->plugin = $plugin;
            $this->ID = 0;
            $this->data = null;
            $this->title = '';
            $this->fooalbum_id = 0;
            $this->fooalbum_title = '';
            $this->migration_status = self::PROGRESS_NOT_STARTED;
            $this->gallery_count = 0;
            $this->migrated_gallery_count = 0;
            $this->galleries = array();
            $this->progress = 0;
            $this->part_of_migration = false;
            $this->album_template = 'default';
        }

        /**
         * The unique identifier for the album.
         *
         * @return string
         */
        function unique_identifier() {
            return $this->plugin->name() . '_' . $this->ID;
        }

        /**
         * Migrate the album!
         *
         * @return void
         */
        function migrate() {
            if ( !$this->migrated ) {
            
                if ( $this->gallery_count == 0 ) {
                    $this->migration_status = self::PROGRESS_ERROR;
                } else {
                    $this->migration_status = self::PROGRESS_STARTED;

                    if($this->fooalbum_id === 0) {
                     
                        $fooalbum_args = array(
                            'post_title' => $this->title,
                            'post_type' => FOOGALLERY_CPT_ALBUM,
                            'post_status' => 'publish',
                        );
                        $album_id = wp_insert_post( $fooalbum_args );
                        $this->fooalbum_id = $album_id;
                    }

                    update_post_meta($album_id, self::FOOGALLERY_ALBUM_TEMPLATE, $this->album_template);

                    foreach($this->galleries as $gallery) {

                        if ( $this->migration_status !== self::PROGRESS_ERROR && $this->migrated_gallery_count < $this->gallery_count ) {

                            if ( !$gallery->migrated && intval( $gallery->foogallery_id ) === 0 ) {

                                if ( $gallery->foogallery_id === 0 ) {
                                    // Create an empty foogallery
                                    $foogallery_args = array(
                                        'post_title' => $gallery->foogallery_title,
                                        'post_type' => FOOGALLERY_CPT_GALLERY,
                                        'post_status' => 'publish',
                                    );
                                    $gallery->foogallery_id = wp_insert_post( $foogallery_args );

                                    if ( is_wp_error( $gallery->foogallery_id ) ) {
                                        $this->migration_status = self::PROGRESS_ERROR;
                                    } else {

                                        $added_galleries = get_post_meta( $album_id, self::FOOGALLERY_ALBUM_GALLERIES, true);
                                        if(empty($added_galleries)) {
                                            $added_galleries = array();
                                        }                    

                                        $additional_galleries = array( $gallery->foogallery_id );
                                        $merge_all_galleries = array_merge( $additional_galleries, $added_galleries );

                                        update_post_meta( $album_id, self::FOOGALLERY_ALBUM_GALLERIES, $merge_all_galleries );

                                        // Determine the best possible gallery template.
                                        $gallery_template = $this->plugin->get_gallery_template( $gallery );

                                        if ( empty( $gallery_template ) ) {
                                            $gallery_template = foogallery_default_gallery_template();
                                        }

                                        // Set the gallery template.
                                        add_post_meta( $gallery->foogallery_id, FOOGALLERY_META_TEMPLATE, $gallery_template, true );

                                        $gallery_settings = array();
                                        //set default settings if there are any
                                        $default_gallery_id = foogallery_get_setting( 'default_gallery_settings' );
                                        if ( !empty( $default_gallery_id ) ) {
                                            $gallery_settings = get_post_meta( $default_gallery_id, FOOGALLERY_META_SETTINGS, true );
                                        }

                                        // Determine the best possible settings for the gallery.
                                        $gallery_settings = $this->plugin->get_gallery_settings( $gallery, $gallery_settings );

                                        // Set the gallery settings.
                                        add_post_meta( $gallery->foogallery_id, FOOGALLERY_META_SETTINGS, $gallery_settings, true );
                                    }

                                    //set default settings if there are any
                                    $default_gallery_id = foogallery_get_setting( 'default_gallery_settings' );
                                    if ( $default_gallery_id ) {
                                        $settings = get_post_meta( $default_gallery_id, FOOGALLERY_META_SETTINGS, true );
                                        add_post_meta( $gallery->foogallery_id, FOOGALLERY_META_SETTINGS, $settings, true );
                                    }

                                    //migrate settings
                                    $this->plugin->get_gallery_template( $gallery );

                                    foreach($gallery->images as $image) {
                                        $image->migrate();
                                    }

                                    $attachments = $this->build_attachment_array($gallery);
                                    update_post_meta( $gallery->foogallery_id, FOOGALLERY_META_ATTACHMENTS, $attachments );  

                                    $this->migrated_gallery_count++;

                                }

                            } else {
                                $this->migrated_gallery_count++;
                            }                       

                            $added_galleries = get_post_meta( $album_id, self::FOOGALLERY_ALBUM_GALLERIES, true);
                            if(empty($added_galleries)) {
                                $added_galleries = array();
                            }                                

                            $additional_galleries = array( $gallery->foogallery_id );
                            $merge_all_galleries = array_merge( $additional_galleries, $added_galleries );

                            update_post_meta( $album_id, self::FOOGALLERY_ALBUM_GALLERIES, $merge_all_galleries );
 

                        }                        

                    }

                    $this->calculate_progress();
                }
            }
        }


        /**
         * Build up the attachment array for the gallery/album.
         * @param $gallery
         * @return array
         */
        function build_attachment_array($gallery) {
            $attachments = array();
            foreach ( $gallery->images as $image ) {
                if ( intval( $image->attachment_id ) > 0 ) {
                    $attachments[] = $image->attachment_id;
                }
            }
            return $attachments;
        }

        /**
         * Calculates the image count so we can show it in album migration.
         *
         * @return void
         */
        function get_image_count() {
            $image_count = 0;
            foreach($this->galleries as $gallery) {
                $image_count += $gallery->image_count;
            }
            return $image_count;
        }


        /**
         * returns album template.
         *
         * @return void
         */
        function get_album_template() {            
            return $this->album_template;
        }


        /**
         * Calculates the migration progress.
         *
         * @return void
         */
        function calculate_progress() {

            if ( $this->migrated || $this->gallery_count === 0 ) {
                // Nothing to migrate.               
                $this->progress = 100;
                return;
            }
                
            //update our percentage complete
            if ( $this->migrated_gallery_count > 0 && $this->gallery_count > 0 ) {
                $this->progress = $this->migrated_gallery_count / $this->gallery_count * 100;
            }

            if ( intval( $this->progress ) >= 100 ) {
                $this->migration_status = self::PROGRESS_COMPLETED;
                $this->migrated = true;
            }
        }

        function friendly_migration_message () {
            switch ( $this->migration_status ) {
                case self::PROGRESS_NOT_STARTED:
                    return __( 'Not migrated', 'foogallery-migrate' );
                    break;
                case self::PROGRESS_QUEUED:
                    return __( 'Queued for migration', 'foogallery-migrate' );
                    break;
                case self::PROGRESS_STARTED:
                    return sprintf( __( 'Imported %d of %d galleries', 'foogallery-migrate' ),
                        $this->migrated_gallery_count, $this->gallery_count );
                    break;
                case self::PROGRESS_COMPLETED:
                    return sprintf( __( 'Done! %d galleries migrated', 'foogallery-migrate' ), $this->migrated_gallery_count );
                    break;
                case self::PROGRESS_ERROR:
                    if ( 0 === $this->import_count ) {
                        return __( 'No galleries to migrate!', 'foogallery-migrate' );
                    } else {
                        return __( 'Error while migrating!', 'foogallery-migrate' );
                    }
                    break;
            }

            return __( 'Unknown status!', 'foogallery-migrate' );
        }
    }
}