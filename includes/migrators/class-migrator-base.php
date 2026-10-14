<?php
/**
 * FooGallery Migrator Base Class
 *
 * @package FooPlugins\FooGalleryMigrate
 */

namespace FooPlugins\FooGalleryMigrate\Migrators;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

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

        // Request-global across cached migrators: an inner flush cannot drain
        // a still-running discovery, queue or migration turn. Not death proof.
        private static $storage_operation_depth = 0;

        public static function storage_is_quiescent() {
            return 0 === self::$storage_operation_depth;
        }

        private function storage_operation( $method, $arguments = array() ) {
            $this->migrator_engine->assert_storage_writer();
            self::$storage_operation_depth++;
            try {
                $result = call_user_func_array( array( $this, $method ), $arguments );
                $this->migrator_engine->assert_storage_writer();
            } catch ( \Exception $error ) {
                self::$storage_operation_depth--;
                throw $error;
            } catch ( \Throwable $error ) {
                self::$storage_operation_depth--;
                throw $error;
            }
            self::$storage_operation_depth--;
            return $result;
        }

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
         * @return bool Whether the setting was persisted.
         */
        public function set_setting( $name, $value ) {
            return $this->migrator_engine->set_migrator_setting( $name, $value );
        }

        /**
         * Returns an array of all galleries that can be migrated.
         *
         * @return array<Migratable>
         */
        public function get_objects_to_migrate( $force = false ) {
            return $this->storage_operation( 'get_objects_to_migrate_admitted', array( $force ) );
        }

        private function get_objects_to_migrate_admitted( $force ) {
            $objects = $this->get_setting( $this->type );

            if ( $objects === false || $force ) {
                $this->migrator_engine->assert_storage_writer();
                $objects = array();
                foreach ( $this->migrator_engine->get_plugins() as $plugin ) {
                    if ( $plugin->is_detected ) {
                        $this->migrator_engine->assert_storage_writer();
                        $plugin_objects = $plugin->find_objects( $this->type );
                        $this->migrator_engine->assert_storage_writer();

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
         * @return bool Whether the queue and its summary state were persisted.
         */
        function queue_objects_for_migration( $id_array ) {
            return $this->storage_operation( 'queue_objects_for_migration_admitted', array( $id_array ) );
        }

        private function queue_objects_for_migration_admitted( $id_array ) {
            $this->migrator_engine->assert_storage_writer();

            $objects = $this->get_objects_to_migrate();
            $queued_object_count = 0;

            foreach ( $objects as $object ) {
                if ( array_key_exists( $object->unique_identifier(), $id_array ) ) {
                    // Only queue an object if it has not been migrated previously.
					$must_migrate = !$object->migrated;

                    if ( $must_migrate ) {
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

            $state_saved = $this->calculate_migration_state( $objects );

            // Save the objects to migrate.
            $objects_saved = $this->set_setting( $this->type, $objects );

			return $state_saved && $objects_saved;
        }

        /**
         * Calculates the state of the current migration.
         *
         * @param $objects array<Migratable>
         * @return bool Whether the summary state was persisted.
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

            return $this->set_state( array(
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
            $this->migrator_engine->assert_storage_writer();
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
         * @return bool Whether the state was persisted.
         */
        protected function set_state( $value ) {
            return $this->set_setting( $this->type . '-state', $value );
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
         * @return bool Whether the migrated batch and option state were persisted.
         */
        function migrate() {
            return $this->storage_operation( 'migrate_admitted' );
        }

        private function migrate_admitted() {
            $this->migrator_engine->assert_storage_writer();
            $objects = $this->get_objects_to_migrate();

            $this->migrator_engine->begin_migrated_object_batch();

            foreach ( $objects as $object ) {

                // Check if the object is queued for migration, or has already started.
                if ( $object->migration_status === Migratable::PROGRESS_QUEUED || $object->migration_status === Migratable::PROGRESS_STARTED ) {
                    $object->migrate();
                    break;
                }
            }

            if ( ! $this->migrator_engine->flush_migrated_object_batch() ) {
                return false;
            }

            $state_saved = $this->calculate_migration_state( $objects );

            // Save the migration objects.
			$objects_saved = $this->set_setting( $this->type, $objects );

			return $state_saved && $objects_saved;
		}
    }
}