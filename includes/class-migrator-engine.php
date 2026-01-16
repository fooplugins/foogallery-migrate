<?php
/**
 * FooGallery Migrator Engine Class
 *
 * @package FooPlugins\FooGalleryMigrate
 */

namespace FooPlugins\FooGalleryMigrate;

use FooPlugins\FooGalleryMigrate\Objects\Migratable;
use FooPlugins\FooGalleryMigrate\Objects\Plugin;

if ( !class_exists( 'FooPlugins\FooGalleryMigrate\MigratorEngine' ) ) {

	/**
	 * Class MigratorEngine
	 *
	 * @package FooPlugins\FooGalleryMigrate
	 */
	class MigratorEngine {

        protected const KEY_PLUGINS = 'plugins';
        protected const KEY_GALLERIES = 'galleries';
        protected const KEY_ALBUMS = 'albums';
        protected const KEY_CONTENT = 'block-shortcode';
        protected const KEY_MIGRATED = 'migrated';

        /**
         * Returns a setting for the migrator.
         *
         * @return mixed
         */
        public function get_migrator_setting( $name, $default = false ) {
            $settings = get_option( FOOGALLERY_MIGRATE_OPTION_DATA );

            if ( isset( $settings ) && is_array( $settings ) && array_key_exists( $name, $settings ) ) {
                return $settings[ $name ];
            }

            return $default;
        }

        /**
         * Sets a migrator setting.
         *
         * @param $name
         * @param $value
         * @return void
         */
        public function set_migrator_setting( $name, $value ) {
            $settings = get_option( FOOGALLERY_MIGRATE_OPTION_DATA );

            if ( !isset( $settings ) ) {
                $settings = array();
            }

            $settings[ $name ] = $value;

            update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $settings, false );
        }

        /**
         * Clear a migrator setting.
         *
         * @param $name
         * @param $value
         * @return void
         */
        public function clear_migrator_setting() {
            $settings = array();
            update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $settings, false );
        }

        /**
         * Returns true if we have any saved migrator settings.
         *
         * @return bool
         */
        public function has_migrator_settings() {
            $settings = get_option( FOOGALLERY_MIGRATE_OPTION_DATA );

            return isset( $settings ) && is_array( $settings );
        }

        /**
         * Runs detection for all plugins.
         *
         * @return array<Plugin>
         */
        public function run_detection() {
            $plugins = foogallery_migrate_get_available_plugins();

            foreach ( $plugins as $plugin ) {
                $plugin->is_detected = $plugin->detect();
            }
            $this->set_migrator_setting( self::KEY_PLUGINS, $plugins );

            return $plugins;
        }

        /**
         * Returns an array of plugins.
         *
         * @return array<Plugin>
         */
        public function get_plugins() {
            $plugins = $this->get_migrator_setting( self::KEY_PLUGINS );
            if ( $plugins === false ) {
                $plugins = $this->run_detection();
            }
            return $plugins;
        }

        /**
         * Returns true if there are any detected plugins.
         *
         * @return bool
         */
        public function has_detected_plugins() {
            return count( $this->get_detected_plugins() ) > 0;
        }

        /**
         * Returns an array of plugins that are detected.
         *
         * @return array
         */
        public function get_detected_plugins() {
            $detected = array();
            foreach ( $this->get_plugins() as $plugin ) {
                if ( $plugin->is_detected ) {
                    $detected[] = $plugin->name();
                }
            }

            return $detected;
        }

        /**
         * Returns the Gallery Migrator
         *
         * @return Migrators\GalleryMigrator
         */
        public function get_gallery_migrator() {
            return new Migrators\GalleryMigrator( $this, self::KEY_GALLERIES );
        }

        /**
         * Returns the Album Migrator
         *
         * @return Migrators\AlbumMigrator
         */
        public function get_album_migrator() {
            return new Migrators\AlbumMigrator( $this, self::KEY_ALBUMS );
        }

        /**
         * Returns the Content Migrator
         *
         * @return Migrators\ContentMigrator
         */
        public function get_content_migrator() {
            return new Migrators\ContentMigrator( $this, self::KEY_CONTENT );
        }

        /**
         * Store a migrated object, so that it does not get migrated twice.
         *
         * @param $object Migratable
         * @return void
         */
        public function add_migrated_object( $object ) {
            $objects = $this->get_migrated_objects();
            if ( !array_key_exists( $object->unique_identifier(), $objects ) ) {
                $objects[$object->unique_identifier()] = $object;
                $this->set_migrator_setting(self::KEY_MIGRATED, $objects);
            }
        }

        /**
         * Check if an object has been migrated previously.
         *
         * @param $unique_identifier
         * @return bool
         */
        public function has_object_been_migrated( $unique_identifier ) {
            return array_key_exists( $unique_identifier, $this->get_migrated_objects() );
        }

        /**
         * Get all previously migrated objects.
         *
         * @return array<Migratable>
         */
        public function get_migrated_objects() {
            $objects = $this->get_migrator_setting( self::KEY_MIGRATED );
            if ( $objects === false ) {
                $objects = array();
            }
            return $objects;
        }

        /**
         * Update a migrated object's status.
         *
         * @param string $unique_identifier
         * @param string $status
         * @return Migratable|\WP_Error
         */
        public function update_migrated_object_status( $unique_identifier, $status ) {
            $objects = $this->get_migrated_objects();
            if ( ! array_key_exists( $unique_identifier, $objects ) ) {
                return new \WP_Error( 'foogallery_migrate_missing_object', __( 'Migrated object not found.', 'foogallery-migrate' ) );
            }

            $object = $objects[ $unique_identifier ];
            if ( ! is_object( $object ) ) {
                return new \WP_Error( 'foogallery_migrate_invalid_object', __( 'Invalid migrated object.', 'foogallery-migrate' ) );
            }

            $object->migration_status = $status;
            $objects[ $unique_identifier ] = $object;
            $this->set_migrator_setting( self::KEY_MIGRATED, $objects );

            return $object;
        }

        /**
         * Delete a migrated object.
         *
         * @param string $unique_identifier
         * @return bool|\WP_Error
         */
        public function delete_migrated_object( $unique_identifier ) {
            $objects = $this->get_migrated_objects();
            if ( ! array_key_exists( $unique_identifier, $objects ) ) {
                return new \WP_Error( 'foogallery_migrate_missing_object', __( 'Migrated object not found.', 'foogallery-migrate' ) );
            }

            unset( $objects[ $unique_identifier ] );
            $this->set_migrator_setting( self::KEY_MIGRATED, $objects );

            return true;
        }

        /**
         * Get a previously migrated object.
         *
         * @return Migratable|bool
         */
        public function get_migrated_object( $unique_identifier ) {
            if ( $this->has_object_been_migrated( $unique_identifier ) ) {
                return $this->get_migrated_objects()[$unique_identifier];
            }
            return false;
        }

        /**
         * Returns true if any objects have been migrated.
         *
         * @return bool
         */
        public function has_migrated_objects() {
            return count ( $this->get_migrated_objects() ) > 0;
        }

        /**
         * Returns a summary of migrated objects.
         *
         * @return array
         */
        public function get_migrated_objects_summary() {
			$summary = array();
			
            foreach( $this->get_migrated_objects() as $object ) {
                if ( !array_key_exists( $object->type(), $summary ) ) {
                    $summary[$object->type()] = array(
						'count' => 0,
						'errors' => 0,
					);
                }

                $summary[$object->type()]['count']++;
				if ( Migratable::PROGRESS_ERROR === $object->migration_status  ) {
					$summary[$object->type()]['errors']++;
				}
            }
            return $summary;
        }

        /**
         * Reset a gallery migration and re-queue it for processing.
         *
         * @param string $unique_identifier
         * @return bool|\WP_Error
         */
        public function retry_gallery_migration( $unique_identifier ) {
            $gallery_migrator = $this->get_gallery_migrator();
            $galleries = $gallery_migrator->get_objects_to_migrate();
            $gallery_index = null;
            $gallery = null;

            foreach ( $galleries as $index => $candidate ) {
                if ( ! is_object( $candidate ) || ! method_exists( $candidate, 'unique_identifier' ) ) {
                    continue;
                }

                if ( $candidate->unique_identifier() === $unique_identifier ) {
                    $gallery_index = $index;
                    $gallery = $candidate;
                    break;
                }
            }

            if ( null === $gallery ) {
                return new \WP_Error( 'foogallery_migrate_missing_gallery', __( 'Gallery not found.', 'foogallery-migrate' ) );
            }

            $migrated_objects = $this->get_migrated_objects();

            if ( array_key_exists( $unique_identifier, $migrated_objects ) ) {
                unset( $migrated_objects[ $unique_identifier ] );
            }

            if ( method_exists( $gallery, 'has_children' ) && $gallery->has_children() ) {
                $children = $gallery->get_children();
                foreach ( $children as $index => $child ) {
                    if ( ! is_object( $child ) || ! method_exists( $child, 'unique_identifier' ) ) {
                        continue;
                    }

                    $child_key = $child->unique_identifier();
                    $child_has_error = false;
                    if ( method_exists( $child, 'has_error' ) && $child->has_error() ) {
                        $child_has_error = true;
                    } else if ( isset( $child->migration_status ) && Migratable::PROGRESS_ERROR === $child->migration_status ) {
                        $child_has_error = true;
                    }

                    if ( $child_has_error ) {
                        $child->migrated = false;
                        $child->migration_status = Migratable::PROGRESS_NOT_STARTED;
                        $child->error = false;
                        $child->migrated_id = 0;

                        if ( array_key_exists( $child_key, $migrated_objects ) ) {
                            unset( $migrated_objects[ $child_key ] );
                        }
                    }

                    $children[ $index ] = $child;
                }

                $gallery->children = $children;
            }

            $gallery->migrated = false;
            $gallery->migration_status = Migratable::PROGRESS_NOT_STARTED;
            $gallery->migrated_child_count = 0;
            $gallery->progress = 0;
            if ( property_exists( $gallery, 'error' ) ) {
                $gallery->error = false;
            }

            $galleries[ $gallery_index ] = $gallery;
            $this->set_migrator_setting( self::KEY_GALLERIES, $galleries );
            $this->set_migrator_setting( self::KEY_MIGRATED, $migrated_objects );

            $gallery_migrator->queue_objects_for_migration(
                array(
                    $unique_identifier => array(
                        'id'       => $unique_identifier,
                        'migrated' => false,
                        'current'  => false,
                        'title'    => isset( $gallery->title ) ? $gallery->title : '',
                    ),
                )
            );
            $gallery_migrator->migrate();

            return true;
        }

        /**
         * Checks migrated images for missing attachment files and marks errors.
         *
         * @param string $unique_identifier
         * @return array|\WP_Error
         */
        public function check_for_migration_errors( $unique_identifier = '' ) {
            $gallery_migrator = $this->get_gallery_migrator();
            $galleries = $gallery_migrator->get_objects_to_migrate();
            $migrated_objects = $this->get_migrated_objects();
            $checked = 0;
            $errors = 0;
            $found_gallery = false;

            foreach ( $galleries as $index => $gallery ) {
                if ( ! is_object( $gallery ) || ! method_exists( $gallery, 'unique_identifier' ) ) {
                    continue;
                }

                if ( '' !== $unique_identifier && $gallery->unique_identifier() !== $unique_identifier ) {
                    continue;
                }

                $found_gallery = true;
                $result = $this->check_gallery_for_missing_files( $gallery, $migrated_objects );
                $galleries[ $index ] = $result['gallery'];
                $migrated_objects = $result['migrated_objects'];
                $checked += $result['checked'];
                $errors += $result['errors'];
            }

            if ( '' !== $unique_identifier && ! $found_gallery ) {
                return new \WP_Error( 'foogallery_migrate_missing_gallery', __( 'Gallery not found.', 'foogallery-migrate' ) );
            }

            $this->set_migrator_setting( self::KEY_GALLERIES, $galleries );
            $this->set_migrator_setting( self::KEY_MIGRATED, $migrated_objects );

            return array(
                'checked' => $checked,
                'errors'  => $errors,
            );
        }

        /**
         * Checks a gallery's children for missing attachment files and marks errors.
         *
         * @param object $gallery
         * @param array $migrated_objects
         * @return array
         */
        private function check_gallery_for_missing_files( $gallery, $migrated_objects ) {
            $checked = 0;
            $errors = 0;
            $has_child_error = false;

            if ( method_exists( $gallery, 'has_children' ) && $gallery->has_children() ) {
                $children = $gallery->get_children();
                foreach ( $children as $index => $child ) {
                    if ( ! is_object( $child ) || ! method_exists( $child, 'unique_identifier' ) ) {
                        continue;
                    }

                    $child_key = $child->unique_identifier();
                    if ( isset( $migrated_objects[ $child_key ] ) && is_object( $migrated_objects[ $child_key ] ) ) {
                        $child = $migrated_objects[ $child_key ];
                    }

                    if ( isset( $child->migration_status ) && Migratable::PROGRESS_ERROR === $child->migration_status ) {
                        $children[ $index ] = $child;
                        $has_child_error = true;
                        continue;
                    }

                    $attachment_id = isset( $child->migrated_id ) ? (int) $child->migrated_id : 0;
                    if ( $attachment_id <= 0 ) {
                        $children[ $index ] = $child;
                        continue;
                    }

                    $checked++;
                    $attachment_path = get_attached_file( $attachment_id );
                    if ( empty( $attachment_path ) || ! file_exists( $attachment_path ) ) {
                        $child->error = new \WP_Error(
                            'foogallery_migrate_missing_file',
                            __( 'Attachment file is missing after migration.', 'foogallery-migrate' )
                        );
                        $child->migration_status = Migratable::PROGRESS_ERROR;
                        $child->migrated = true;
                        $has_child_error = true;
                        $errors++;
                    }

                    if ( ! isset( $child->migration_status ) || Migratable::PROGRESS_ERROR !== $child->migration_status ) {
                        $attachment_size = wp_filesize( $attachment_path );
                        if ( false === $attachment_size ) {
                            $child->error = new \WP_Error(
                                'foogallery_migrate_missing_file_size',
                                __( 'Attachment file size could not be determined after migration.', 'foogallery-migrate' )
                            );
                            $child->migration_status = Migratable::PROGRESS_ERROR;
                            $child->migrated = true;
                            $has_child_error = true;
                            $errors++;
                        }
                    }

                    if ( ! isset( $child->migration_status ) || Migratable::PROGRESS_ERROR !== $child->migration_status ) {
                        $attachment_url = wp_get_attachment_url( $attachment_id );
                        if ( ! empty( $attachment_url ) ) {
                            $response = wp_remote_head(
                                $attachment_url,
                                array(
                                    'timeout'     => 5,
                                    'redirection' => 2,
                                )
                            );
                            $status_code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
                            if ( 200 !== $status_code ) {
                                $child->error = new \WP_Error(
                                    'foogallery_migrate_missing_file',
                                    __( 'Attachment file could not be loaded after migration.', 'foogallery-migrate' )
                                );
                                $child->migration_status = Migratable::PROGRESS_ERROR;
                                $child->migrated = true;
                                $has_child_error = true;
                                $errors++;
                            }
                        }
                    }

                    $children[ $index ] = $child;
                    $migrated_objects[ $child_key ] = $child;
                }

                $gallery->children = $children;
            }

            if ( $has_child_error ) {
                $gallery->error = new \WP_Error(
                    'foogallery_migrate_child_error',
                    __( 'One or more images are missing after migration.', 'foogallery-migrate' )
                );
                $gallery->migration_status = Migratable::PROGRESS_ERROR;
                $gallery->migrated = true;
				$migrated_objects[ $gallery->unique_identifier() ] = $gallery;
            }

            return array(
                'gallery'          => $gallery,
                'migrated_objects' => $migrated_objects,
                'checked'          => $checked,
                'errors'           => $errors,
            );
        }

        /**
         * Checks a single gallery for missing attachment files and marks errors.
         *
         * @param string $unique_identifier
         * @return array|\WP_Error
         */
        public function check_gallery_migration_errors( $unique_identifier ) {
            return $this->check_for_migration_errors( $unique_identifier );
        }
	}
}
