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

		/** Table-only read seam; injected option doubles retain the old behavior. */
		protected function table_collection_repository() {
			return method_exists( $this->migrator_engine, 'table_collection_repository' ) ? $this->migrator_engine->table_collection_repository() : null;
		}

        private function get_objects_to_migrate_admitted( $force ) {
            // A forced source refresh replaces the old collection; reading its
            // potentially large table snapshot first serves no purpose.
            $objects = $force ? false : $this->get_setting( $this->type );

            if ( $objects === false || $force ) {
                $this->migrator_engine->assert_storage_writer();
				$table = $this->table_collection_repository();
				// Source callbacks run outside the storage deadline. Pin the exact
				// head (including absence) before they can interleave with a writer.
				$discovery_context = null !== $table ? $table->view_context( $this->type ) : null;
				if ( null !== $table && ! $force && $discovery_context['found'] ) {
					throw new \RuntimeException( 'Absent collection changed before lazy discovery.' );
				}
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
				if ( null !== $table ) {
					// Replacement of a discovered table collection has no need to
					// decode the discarded roots merely to calculate a diff.
					$table->replace_discovered( $this->type, $objects, $discovery_context );
				} else { $this->set_setting( $this->type, $objects ); }
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
			$table = $this->table_collection_repository();
			if ( null !== $table ) {
				// An absent collection still follows ordinary lazy discovery. Once
				// present, queue/cancel never hydrate unrelated collection roots.
				$context = $table->view_context( $this->type );
				if ( ! $context['found'] ) { $this->get_objects_to_migrate(); }
				return $table->queue_selected( $this->type, $id_array, array( $this, 'queue_transition' ), array( $this, 'queue_summary' ) );
			}

            $objects = $this->get_objects_to_migrate();
            foreach ( $objects as $object ) {
				$uid = $object->unique_identifier();
				$this->queue_transition( $object, array_key_exists( $uid, $id_array ),
					array_key_exists( $uid, $id_array ) ? $id_array[$uid] : null );
            }

            $state_saved = $this->calculate_migration_state( $objects );

            // Save the objects to migrate.
            $objects_saved = $this->set_setting( $this->type, $objects );

			return $state_saved && $objects_saved;
        }

		/** One logical queue transition, shared by option and bounded table callers. */
		public static function queue_transition( $object, $matched, $entry ) {
			if ( $matched ) {
				if ( ! $object->migrated ) {
					$object->part_of_migration = true;
					$object->migration_status = Migratable::PROGRESS_QUEUED;
					if ( 0 === $object->migrated_id ) {
						$object->migrated_title = $entry['title'];
					}
				}
			} else {
				$object->part_of_migration = false;
				if ( $object->migration_status === Migratable::PROGRESS_QUEUED ) {
					$object->migration_status = Migratable::PROGRESS_NOT_STARTED;
				}
			}
			return $object;
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

			return $this->set_state( self::queue_summary( $queued_count, $completed_count, $error_count ) );
        }

		/** Summary for the same selected-object predicate in either backend. */
		public static function queue_summary( $queued, $completed, $errors ) {
			return array( 'queued' => $queued, 'completed' => $completed,
				'progress' => $queued > 0 ? ( $completed + $errors ) / $queued * 100 : 0 );
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
			$repository = $this->table_collection_repository();
			if ( null !== $repository ) {
				$started = $repository->first_started( $this->type );
				return $started['found'] ? $started['value']->unique_identifier() : 0;
			}
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
			$table = $this->table_collection_repository();
			if ( null !== $table ) {
				$context = $table->migration_turn_context( $this->type );
				if ( ! $context['found'] ) {
					$this->get_objects_to_migrate();
					$context = $table->migration_turn_context( $this->type );
					if ( ! $context['found'] ) { throw new \RuntimeException( 'Discovered migration collection was not persisted.' ); }
				}
				$old = null === $context['root'] ? null : self::turn_contribution( $context['object'] );
				$this->migrator_engine->begin_migrated_object_batch();
				if ( null !== $context['root'] ) { $context['object']->migrate(); }
				if ( ! $this->migrator_engine->flush_migrated_object_batch() ) { return false; }
				$counts = $context['counts'];
				if ( null !== $old ) {
					$new = self::turn_contribution( $context['object'] );
					foreach ( $counts as $name => $count ) { $counts[$name] = $count - $old[$name] + $new[$name]; }
				}
				return $table->checkpoint_migration_turn( $this->type, $context,
					self::queue_summary( $counts['queued'], $counts['completed'], $counts['errors'] ) );
			}
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

		private static function turn_contribution( $object ) {
			$selected = (bool) $object->part_of_migration;
			return array( 'queued' => $selected ? 1 : 0,
				'completed' => $selected && $object->migrated ? 1 : 0,
				'errors' => $selected && Migratable::PROGRESS_ERROR === $object->migration_status ? 1 : 0 );
		}
    }
}
