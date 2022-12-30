<?php
/**
 * FooGallery Migratable Class
 *
 * @package FooPlugins\FooGalleryMigrate
 */

namespace FooPlugins\FooGalleryMigrate\Objects;

if ( ! class_exists( 'FooPlugins\FooGalleryMigrate\Objects\Migratable' ) ) {

    /**
     * Class Init
     *
     * @package FooPlugins\FooGalleryMigrate
     */
    abstract class Migratable extends \stdClass {

        const PROGRESS_NOT_STARTED = 'not_started';
        const PROGRESS_QUEUED = 'queued';
        const PROGRESS_STARTED = 'started';
        const PROGRESS_COMPLETED = 'completed';
        const PROGRESS_ERROR = 'error';
        const PROGRESS_NOTHING = 'nothing';

        /**
         * @param $plugin Plugin
         */
        function __construct( $plugin ) {
            $this->migrated = false;
            $this->plugin = $plugin;
            $this->ID = 0;
            $this->data = null;
            $this->title = '';
            $this->migration_status = self::PROGRESS_NOT_STARTED;
            $this->migrated_child_count = 0;
            $this->images = array();
            $this->progress = 0;
            $this->part_of_migration = false;

            $this->migrated_id = 0;
            $this->migrated_title = '';
        }

        abstract function has_children();

        abstract function children_name();

        abstract function create_new_migrated_object();

        abstract function migrate_next_child();

        abstract function get_children();

        function get_children_count() {
            return count( $this->get_children() );
        }

        /**
         * The unique identifier for the migratable object.
         *
         * @return string
         */
        function unique_identifier() {
            return $this->plugin->name() . '_' . $this->ID;
        }

        /**
         * Migrate the object!
         *
         * @return void
         */
        function migrate() {
            if ( !$this->migrated ) {

                if ( $this->get_children_count() === 0 ) {
                    $this->migration_status = self::PROGRESS_NOTHING;
                } else {
                    $this->migration_status = self::PROGRESS_STARTED;

                    if ( $this->migrated_id === 0 ) {
                        $this->create_new_migrated_object();
                    }

                    $this->migrate_next_child();

                    $attachments = $this->build_attachment_array();
                    update_post_meta( $this->foogallery_id, FOOGALLERY_META_ATTACHMENTS, $attachments );
                }
            }
        }

        /**
         * Calculates the migration progress.
         *
         * @return void
         */
        function calculate_progress() {
            if ( $this->migrated || $this->get_children_count() === 0 ) {
                // Nothing to migrate.
                $this->progress = 100;
                return;
            }

            //update our percentage complete
            if ( $this->migrated_child_count > 0 && $this->get_children_count() > 0 ) {
                $this->progress = $this->migrated_child_count / $this->get_children_count() * 100;
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
                case self::PROGRESS_QUEUED:
                    return __( 'Queued for migration', 'foogallery-migrate' );
                case self::PROGRESS_STARTED:
                    return sprintf( __( 'Migrated %d of %d %s', 'foogallery-migrate' ),
                        $this->migrated_child_count, $this->get_children_count(), $this->children_name() );
                case self::PROGRESS_COMPLETED:
                    return sprintf( __( 'Done! %d %s migrated', 'foogallery-migrate' ), $this->migrated_child_count, $this->children_name() );
                case self::PROGRESS_NOTHING:
                    return sprintf( __( 'No %s to migrate!', 'foogallery-migrate' ), $this->children_name() );
                case self::PROGRESS_ERROR:
                    return __( 'Error while migrating!', 'foogallery-migrate' );
            }

            return __( 'Unknown status!', 'foogallery-migrate' );
        }
    }
}