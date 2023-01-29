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
            $this->progress = 0;
            $this->part_of_migration = false;
            $this->migrated_id = 0;
            $this->migrated_title = '';
            $this->children = array();
        }

        /**
         * Returns the type of object.
         *
         * @return string
         */
        abstract function type();

        /**
         * Returns true if the object has children.
         *
         * @return bool
         */
        function has_children() {
            return false;
        }

        /**
         * Returns the name of the children for the object.
         *
         * @return string
         */
        function children_name() {
            return '';
        }

        /**
         * Creates the new migrated object and returns true if successful.
         *
         * @return bool
         */
        function create_new_migrated_object() {
            return true;
        }

        /**
         * Migrates the next child. Returns true if successful.
         *
         * @return void
         */
        function migrate_next_child() {
            if ( !$this->has_children() ) { return; }
            if ( $this->migration_status !== self::PROGRESS_ERROR && $this->migrated_child_count < $this->get_children_count() ) {
                foreach ( $this->get_children() as $child ) {
                    if ( !$child->migrated ) {
                        $child->migrate();
                        if ( $child->migrated ) {
                            $this->migrated_child_count++;
                        }
                        break;
                    }
                }
            } 
        }

        /**
         * Returns an array of child migratable objects.
         *
         * @return array<Migratable>
         */
        function get_children() {
            return $this->children;
        }

        function get_children_count() {
            if ( $this->has_children() ) {
                $children = $this->get_children();
                if ( is_array( $children ) ) {
                    return count( $children );
                }
            }
            return 0;
        }

        /**
         * The unique identifier for the migratable object.
         *
         * @return string
         */
        function unique_identifier() {
            return $this->type() . '_' . $this->plugin->name() . '_' . $this->ID;
        }

        /**
         * Migrate the object!
         *
         * @return void
         */
        function migrate() {
            $migrated_object = foogallery_migrate_migrator_instance()->get_migrated_object( $this->unique_identifier() );
            if ( false !== $migrated_object ) {
                $this->migration_status = self::PROGRESS_COMPLETED;
                $this->migrated = true;
                $this->migrated_id = $migrated_object->migrated_id;
                $this->migrated_title = $migrated_object->migrated_title;
                if ( $this->has_children() ) {
                    $this->migrated_child_count = $migrated_object->migrated_child_count;
                }
            }

            if ( !$this->migrated ) {

                if ( $this->has_children() && $this->get_children_count() === 0 ) {
                    $this->migration_status = self::PROGRESS_NOTHING;
                } else {
                    $this->migration_status = self::PROGRESS_STARTED;

                    // First, make sure the object is migrated.
                    if ( $this->migrated_id === 0 ) {
                        $this->create_new_migrated_object();
                    }

                    // Then migrate the children.
                    if ( $this->has_children() ) {
                        $this->migrate_next_child();
                    }

                    // Always calculate the new progress, after an attempted migration.
                    $progress = $this->calculate_progress();

                    if ( $progress >= 100 ) {
                        $this->migration_status = self::PROGRESS_COMPLETED;
                        $this->migrated = true;
                        foogallery_migrate_migrator_instance()->add_migrated_object( $this );
                    }
                }
            }
        }

        /**
         * Calculates the migration progress.
         *
         * @return int
         */
        function calculate_progress() {
            if ( $this->migrated || $this->get_children_count() === 0 ) {
                // Nothing to migrate.
                $this->progress = 100;
            } else {
                // Make sure we have the latest migrated count.
                if ( $this->has_children() && $this->get_children_count() > 0 ) {
                    $this->migrated_child_count = 0;
                    foreach ( $this->get_children() as $child ) {
                        if ( $child->migrated ) {
                            $this->migrated_child_count++;
                        }
                    }

                    //update our percentage complete
                    if ($this->migrated_child_count > 0 && $this->get_children_count() > 0) {
                        $this->progress = $this->migrated_child_count / $this->get_children_count() * 100;
                    }
                }
            }
            return $this->progress;
        }

        function friendly_migration_message () {
            switch ( $this->migration_status ) {
                case self::PROGRESS_NOT_STARTED:
                    return __( 'Not migrated', 'foogallery-migrate' );
                case self::PROGRESS_QUEUED:
                    return __( 'Queued for migration', 'foogallery-migrate' );
                case self::PROGRESS_STARTED:
                    if ( $this->has_children() ) {
                        return sprintf(__('Migrated %d of %d %s', 'foogallery-migrate'),
                            $this->migrated_child_count, $this->get_children_count(), $this->children_name());
                    } else {
                        return __('Migrating...', 'foogallery-migrate');
                    }
                case self::PROGRESS_COMPLETED:
                    if ( $this->has_children() ) {
                        return sprintf(__('Done! %d %s migrated', 'foogallery-migrate'), $this->migrated_child_count, $this->children_name());
                    } else {
                        return __('Done!', 'foogallery-migrate');
                    }
                case self::PROGRESS_NOTHING:
                    if ( $this->has_children() ) {
                        return sprintf(__('No %s to migrate!', 'foogallery-migrate'), $this->children_name());
                    } else {
                        return __('Nothing to migrate!', 'foogallery-migrate');
                    }
                case self::PROGRESS_ERROR:
                    return __( 'Error while migrating!', 'foogallery-migrate' );
            }

            return __( 'Unknown status!', 'foogallery-migrate' );
        }

        /**
         * Build up an array of the ID's of the migrated children.
         *
         * @return array
         */
        function build_child_migrated_id_array() {
            $migrated_children = array();
            foreach ( $this->get_children() as $child ) {
                if ( intval( $child->migrated_id ) > 0 ) {
                    $migrated_children[] = $child->migrated_id;
                }
            }
            return $migrated_children;
        }
    }
}