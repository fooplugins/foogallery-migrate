<?php
/**
 * FooGallery Migrator Base Class
 *
 * @package FooPlugins\FooGalleryMigrate
 */

namespace FooPlugins\FooGalleryMigrate\Migrators;

use FooPlugins\FooGalleryMigrate\MigratorEngine;
use FooPlugins\FooGalleryMigrate\Objects\Gallery;
use FooPlugins\FooGalleryMigrate\Objects\Migratable;
use stdClass;

if ( !class_exists( 'FooPlugins\FooGalleryMigrate\Migrators\MigratorBase' ) ) {

    /**
     * Class MigratorBase
     *
     * @package FooPlugins\FooGalleryMigrate
     */
    abstract class MigratorBase {

        /**
         * @var string
         */
        protected $type;

        /**
         * @var MigratorEngine
         */
        protected $migrator_engine;

        /**
         * Initialize the GalleryMigrator
         * @param $migrator_engine MigratorEngine
         */
        public function __construct( $migrator_engine, $type ) {
            $this->migrator_engine = $migrator_engine;
            $this->type = $type;
        }

        /**
         * Gets a migrator setting.
         *
         * @param $name
         * @param $default
         * @return false|mixed
         */
        public function get_setting( $name, $default = false ) {
            return $this->migrator_engine->get_migrator_setting( $name, $default );
        }

        /***
         * Sets a migrator setting.
         *
         * @param $name
         * @param $value
         * @return void
         */
        public function set_setting( $name, $value ) {
            $this->migrator_engine->set_migrator_setting( $name, $value );
        }

        /**
         * Returns an array of all galleries that can be migrated.
         *
         * @return array<Migratable>
         */
        public function get_objects_to_migrate( $force = false ) {
            $objects = $this->get_setting( $this->type );

            if ( $objects === false || $force ) {
                $objects = array();
                foreach ( $this->migrator_engine->get_plugins() as $plugin ) {
                    if ( $plugin->is_detected ) {
                        $plugin_objects = $plugin->find_objects( $this->type );

                        if ( is_array( $plugin_objects ) ) {
                            $objects = array_merge( $objects, $plugin_objects );
                        }
                    }
                }
                $this->set_setting( $this->type, $objects );
            }

            return $objects;
        }

        /**
         * Mark a specific gallery for migration.
         *
         * @param $id_array
         * @return void
         */
        function queue_objects_for_migration( $id_array ) {

            $objects = $this->get_objects_to_migrate();
            $queued_object_count = 0;

            foreach ( $objects as $object ) {
                if ( array_key_exists( $object->unique_identifier(), $id_array ) ) {
                    // Only queue an object if it has not been migrated previously.
                    if ( !$object->migrated ) {
                        $queued_object_count++;
                        $object->part_of_migration = true;
                        $object->migration_status = Migratable::PROGRESS_QUEUED;
                        if ( 0 === $object->migrated_id ) {
                            $object->migrated_title = $id_array[$object->unique_identifier()]['title'];
                        }
                    }
                } else {
                    $object->part_of_migration = false;
                    if ( $object->migration_status === Migratable::PROGRESS_QUEUED ) {
                        $object->migration_status = Migratable::PROGRESS_NOT_STARTED;
                    }
                }
            }

            $this->calculate_migration_state( $objects );

            // Save the objects to migrate.
            $this->set_setting( $this->type, $objects );
        }

        /**
         * Calculates the state of the current migration.
         *
         * @param $objects array<Migratable>
         * @return void
         */
        function calculate_migration_state( $objects ) {
            $queued_count = 0;
            $completed_count = 0;
            $error_count = 0;

            foreach ( $objects as $object ) {
                if ( $object->part_of_migration ) {
                    $queued_count++;

                    if ( $object->migrated ) {
                        $completed_count++;
                    }
                    if ( $object->migration_status === Migratable::PROGRESS_ERROR ) {
                        $error_count++;
                    }
                }
            }

            $progress = 0;
            if ( $queued_count > 0 ) {
                $progress = ( $completed_count + $error_count ) / $queued_count * 100;
            }

            $this->set_state( array(
                'queued' => $queued_count,
                'completed' => $completed_count,
                'progress' => $progress
            ) );
        }

        /**
         * Cancels the current gallery migration.
         *
         * @return void
         */
        function cancel_migration() {
            $this->queue_objects_for_migration( array() );
        }

        /**
         * Returns the current object that is being migrated.
         *
         * @return int|string
         */
        function get_current_object_being_migrated() {
            $objects = $this->get_objects_to_migrate();

            foreach ( $objects as $object ) {
                // Check if the object is queued for migration.
                if ( $object->migration_status === Migratable::PROGRESS_STARTED ) {
                    return $object->unique_identifier();
                }
            }
            return 0;
        }

        /**
         * Sets the migration state.
         *
         * @param $value
         * @return void
         */
        protected function set_state( $value ) {
            $this->set_setting( $this->type . '-state', $value );
        }

        /**
         * Gets the migration state.
         *
         * @return false|mixed
         */
        function get_state() {
            return $this->get_setting( $this->type . '-state', false );
        }

        /**
         * Continue the migration!
         *
         * @return void
         */
        function migrate() {
            $objects = $this->get_objects_to_migrate();

            foreach ( $objects as $object ) {

                // Check if the object is queued for migration, or has already started.
                if ( $object->migration_status === Migratable::PROGRESS_QUEUED || $object->migration_status === Migratable::PROGRESS_STARTED ) {
                    $object->migrate();
                    break;
                }
            }

            $this->calculate_migration_state( $objects );

            // Save the migration objects.
            $this->set_setting( $this->type, $objects );
        }
    }
}