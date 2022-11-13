<?php
/**
 * FooGallery Migrator Gallery Class
 *
 * @package FooPlugins\FooGalleryMigrate
 */

namespace FooPlugins\FooGalleryMigrate;

if ( ! class_exists( 'FooPlugins\FooGalleryMigrate\Gallery' ) ) {

    /**
     * Class Init
     *
     * @package FooPlugins\FooGalleryMigrate
     */
    class Gallery extends \stdClass {

        const PROGRESS_NOT_STARTED = 'not_started';
        const PROGRESS_QUEUED = 'queued';
        const PROGRESS_STARTED = 'started';
        const PROGRESS_COMPLETED = 'completed';
        const PROGRESS_ERROR = 'error';

        function __construct( $plugin ) {
            $this->migrated = false;
            $this->plugin = $plugin;
            $this->ID = 0;
            $this->data = null;
            $this->title = '';
            $this->foogallery_id = 0;
            $this->foogallery_title = '';
            $this->migration_status = self::PROGRESS_NOT_STARTED;
            $this->image_count = 0;
            $this->migrated_image_count = 0;
            $this->images = array();
            $this->progress = 0;
            $this->part_of_migration = false;
        }

        /**
         * The unique identifier for the gallery.
         *
         * @return string
         */
        function unique_identifier() {
            return $this->plugin->name() . '_' . $this->ID;
        }

        /**
         * Migrate the gallery!
         *
         * @return void
         */
        function migrate() {
            if ( !$this->migrated ) {

                if ( $this->image_count === 0 ) {
                    $this->migration_status = self::PROGRESS_ERROR;
                } else {
                    $this->migration_status = self::PROGRESS_STARTED;

                    if ( $this->foogallery_id === 0 ) {
                        // Create an empty foogallery
                        $foogallery_args = array(
                            'post_title' => $this->foogallery_title,
                            'post_type' => FOOGALLERY_CPT_GALLERY,
                            'post_status' => 'publish',
                        );
                        $this->foogallery_id = wp_insert_post( $foogallery_args );

                        if ( is_wp_error( $this->foogallery_id ) ) {
                            $this->migration_status = self::PROGRESS_ERROR;
                        } else {

                            // Determine the best possible gallery template.
                            $gallery_template = $this->plugin->get_gallery_template( $this );

                            if ( empty( $gallery_template ) ) {
                                $gallery_template = foogallery_default_gallery_template();
                            }

                            // Set the gallery template.
                            add_post_meta( $this->foogallery_id, FOOGALLERY_META_TEMPLATE, $gallery_template, true );

                            $gallery_settings = array();
                            //set default settings if there are any
                            $default_gallery_id = foogallery_get_setting( 'default_gallery_settings' );
                            if ( !empty( $default_gallery_id ) ) {
                                $gallery_settings = get_post_meta( $default_gallery_id, FOOGALLERY_META_SETTINGS, true );
                            }

                            // Determine the best possible settings for the gallery.
                            $gallery_settings = $this->plugin->get_gallery_settings( $this, $gallery_settings );

                            // Set the gallery settings.
                            add_post_meta( $this->foogallery_id, FOOGALLERY_META_SETTINGS, $gallery_settings, true );
                        }



                        //set default settings if there are any
                        $default_gallery_id = foogallery_get_setting( 'default_gallery_settings' );
                        if ( $default_gallery_id ) {
                            $settings = get_post_meta( $default_gallery_id, FOOGALLERY_META_SETTINGS, true );
                            add_post_meta( $this->foogallery_id, FOOGALLERY_META_SETTINGS, $settings, true );
                        }

                        //migrate settings
                        $this->plugin->get_gallery_template( $this );
                    }

                    $this->migrate_next_image();

                    $attachments = $this->build_attachment_array();
                    update_post_meta( $this->foogallery_id, FOOGALLERY_META_ATTACHMENTS, $attachments );
                }
            }
        }

        /**
         * Migrate the next available image for the gallery.
         *
         * @return void
         */
        function migrate_next_image() {
            if ( $this->migration_status !== self::PROGRESS_ERROR && $this->migrated_image_count < $this->image_count ) {
                foreach ( $this->images as $image ) {
                    if ( !$image->migrated && intval( $image->attachment_id ) === 0 ) {
                        if ( $image->migrate() ) {
                            $this->migrated_image_count++;
                        }
                        break;
                    }
                }
            }
            $this->calculate_progress();
        }

        /**
         * Build up the attachment array for the gallery.
         *
         * @return array
         */
        function build_attachment_array() {
            $attachments = array();
            foreach ( $this->images as $image ) {
                if ( intval( $image->attachment_id ) > 0 ) {
                    $attachments[] = $image->attachment_id;
                }
            }
            return $attachments;
        }

        /**
         * Calculates the migration progress.
         *
         * @return void
         */
        function calculate_progress() {
            if ( $this->migrated || $this->image_count === 0 ) {
                // Nothing to migrate.
                $this->progress = 100;
                return;
            }

//            $this->migrated_image_count = 0;
//            foreach ( $this->images as $image ) {
//                if ( $image->migrated && !is_wp_error( $image->error ) ) {
//                    $this->migrated_image_count++;
//                }
//            }

            //update our percentage complete
            if ( $this->migrated_image_count > 0 && $this->image_count > 0 ) {
                $this->progress = $this->migrated_image_count / $this->image_count * 100;
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
                    return sprintf( __( 'Imported %d of %d image(s)', 'foogallery-migrate' ),
                        $this->migrated_image_count, $this->image_count );
                    break;
                case self::PROGRESS_COMPLETED:
                    return sprintf( __( 'Done! %d image(s) migrated', 'foogallery-migrate' ), $this->migrated_image_count );
                    break;
                case self::PROGRESS_ERROR:
                    if ( 0 === $this->import_count ) {
                        return __( 'No images to migrate!', 'foogallery-migrate' );
                    } else {
                        return __( 'Error while migrating!', 'foogallery-migrate' );
                    }
                    break;
            }

            return __( 'Unknown status!', 'foogallery-migrate' );
        }
    }
}