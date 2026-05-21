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
        protected const COMPACT_MARKER = '_foogallery_migrate_compact';
        protected const COMPACT_VERSION = 1;
        protected const SETTING_OVERRIDE_GALLERY_LAYOUT = 'override_gallery_layout';
        protected const SETTING_PAGE_SIZE = 'page_size';
        protected const SETTING_DEBUG_ENABLED = 'debug_enabled';

        /**
         * Returns a setting for the migrator.
         *
         * @return mixed
         */
        public function get_migrator_setting( $name, $default = false ) {
            $settings = get_option( FOOGALLERY_MIGRATE_OPTION_DATA );

            if ( isset( $settings ) && is_array( $settings ) && array_key_exists( $name, $settings ) ) {
                return $this->hydrate_migrator_setting( $name, $settings[ $name ] );
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

            if ( !isset( $settings ) || ! is_array( $settings ) ) {
                $settings = array();
            }

            $settings[ $name ] = $this->compact_migrator_setting( $name, $value );

            update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $settings, false );
        }

        /**
         * Compacts large migration settings before they are persisted.
         *
         * @param string $name Setting name.
         * @param mixed $value Setting value.
         * @return mixed
         */
        protected function compact_migrator_setting( $name, $value ) {
            if ( $this->is_compact_payload( $value ) ) {
                return $value;
            }

            switch ( $name ) {
                case self::KEY_PLUGINS:
                    return $this->compact_plugins( $value );
                case self::KEY_GALLERIES:
                case self::KEY_ALBUMS:
                    return $this->compact_migratable_collection( $value, false );
                case self::KEY_MIGRATED:
                    return $this->compact_migratable_collection( $value, true );
            }

            return $value;
        }

        /**
         * Hydrates compact migration settings back into runtime objects.
         *
         * @param string $name Setting name.
         * @param mixed $value Setting value.
         * @return mixed
         */
        protected function hydrate_migrator_setting( $name, $value ) {
            if ( ! $this->is_compact_payload( $value ) ) {
                return $value;
            }

            switch ( $name ) {
                case self::KEY_PLUGINS:
                    return $this->hydrate_plugins( $value );
                case self::KEY_GALLERIES:
                case self::KEY_ALBUMS:
                    return $this->hydrate_migratable_collection( $value, false );
                case self::KEY_MIGRATED:
                    return $this->hydrate_migratable_collection( $value, true );
            }

            return $value;
        }

        /**
         * Builds a compact payload wrapper.
         *
         * @param string $type Payload type.
         * @param array $items Payload items.
         * @return array
         */
        protected function compact_payload( $type, $items ) {
            return array(
                self::COMPACT_MARKER => self::COMPACT_VERSION,
                'type'               => $type,
                'items'              => $items,
            );
        }

        /**
         * Returns true when a setting uses the compact payload wrapper.
         *
         * @param mixed $value Setting value.
         * @return bool
         */
        protected function is_compact_payload( $value ) {
            return is_array( $value ) && isset( $value[ self::COMPACT_MARKER ] );
        }

        /**
         * Compacts detected plugin objects to name/status records.
         *
         * @param mixed $plugins Plugin list.
         * @return mixed
         */
        protected function compact_plugins( $plugins ) {
            if ( ! is_array( $plugins ) ) {
                return $plugins;
            }

            $items = array();
            foreach ( $plugins as $plugin ) {
                if ( ! is_object( $plugin ) || ! method_exists( $plugin, 'name' ) ) {
                    continue;
                }

                $items[] = array(
                    'name'        => $plugin->name(),
                    'is_detected' => ! empty( $plugin->is_detected ),
                );
            }

            return $this->compact_payload( self::KEY_PLUGINS, $items );
        }

        /**
         * Hydrates compact plugin records into the available plugin adapters.
         *
         * @param array $payload Compact payload.
         * @return array
         */
        protected function hydrate_plugins( $payload ) {
            $plugins = array();
            $items = isset( $payload['items'] ) && is_array( $payload['items'] ) ? $payload['items'] : array();

            foreach ( $items as $item ) {
                if ( ! is_array( $item ) || empty( $item['name'] ) ) {
                    continue;
                }

                $plugin = $this->get_available_plugin_by_name( $item['name'] );
                if ( false === $plugin ) {
                    continue;
                }

                $plugin->is_detected = ! empty( $item['is_detected'] );
                $plugins[] = $plugin;
            }

            return $plugins;
        }

        /**
         * Returns an available plugin adapter by name.
         *
         * @param string $name Plugin name.
         * @return Plugin|false
         */
        protected function get_available_plugin_by_name( $name ) {
            if ( ! function_exists( 'foogallery_migrate_get_available_plugins' ) ) {
                return false;
            }

            foreach ( foogallery_migrate_get_available_plugins() as $plugin ) {
                if ( is_object( $plugin ) && method_exists( $plugin, 'name' ) && $plugin->name() === $name ) {
                    return $plugin;
                }
            }

            return false;
        }

        /**
         * Compacts a list of migratable objects.
         *
         * @param mixed $objects Object list.
         * @param bool $preserve_keys Preserve array keys.
         * @return mixed
         */
        protected function compact_migratable_collection( $objects, $preserve_keys ) {
            if ( ! is_array( $objects ) ) {
                return $objects;
            }

            $items = array();
            foreach ( $objects as $key => $object ) {
                $record = $this->compact_migratable_object( $object );

                if ( $preserve_keys ) {
                    $items[ $key ] = $record;
                } else {
                    $items[] = $record;
                }
            }

            return $this->compact_payload( 'migratable', $items );
        }

        /**
         * Hydrates a compact migratable object collection.
         *
         * @param array $payload Compact payload.
         * @param bool $preserve_keys Preserve array keys.
         * @return array
         */
        protected function hydrate_migratable_collection( $payload, $preserve_keys ) {
            $objects = array();
            $items = isset( $payload['items'] ) && is_array( $payload['items'] ) ? $payload['items'] : array();

            foreach ( $items as $key => $record ) {
                $object = $this->hydrate_migratable_object( $record );

                if ( $preserve_keys ) {
                    $objects[ $key ] = $object;
                } else {
                    $objects[] = $object;
                }
            }

            return $objects;
        }

        /**
         * Compacts a migratable object into scalar data.
         *
         * @param mixed $object Migratable object.
         * @return mixed
         */
        protected function compact_migratable_object( $object ) {
            if ( ! is_object( $object ) || ! method_exists( $object, 'type' ) ) {
                return $this->compact_plain_value( $object );
            }

            $record = array(
                'object_type' => $object->type(),
            );

            if ( isset( $object->plugin ) && is_object( $object->plugin ) && method_exists( $object->plugin, 'name' ) ) {
                $record['plugin_name'] = $object->plugin->name();
            }

            $properties = array(
                'ID',
                'source_url',
                'slug',
                'title',
                'caption',
                'description',
                'alt',
                'date',
                'migration_status',
                'migrated',
                'migrated_child_count',
                'progress',
                'part_of_migration',
                'migrated_id',
                'migrated_title',
                'children_count',
            );

            foreach ( $properties as $property ) {
                if ( isset( $object->$property ) || property_exists( $object, $property ) ) {
                    $value = $object->$property;
                    if ( is_scalar( $value ) || null === $value ) {
                        $record[ $property ] = $value;
                    }
                }
            }

            if ( isset( $object->settings ) && ! empty( $object->settings ) ) {
                $record['settings'] = $this->compact_plain_value( $object->settings );
            }

            if ( isset( $object->children ) && is_array( $object->children ) && count( $object->children ) > 0 ) {
                $children = array();
                foreach ( $object->children as $child ) {
                    $children[] = $this->compact_migratable_object( $child );
                }
                $record['children'] = $children;
            }

            $error = false;
            if ( method_exists( $object, 'has_error' ) && $object->has_error() && method_exists( $object, 'get_error' ) ) {
                $error = $object->get_error();
            } else if ( isset( $object->error ) && false !== $object->error ) {
                $error = $object->error;
            }

            if ( false !== $error ) {
                $record['error'] = $this->compact_error( $error );
            }

            return $record;
        }

        /**
         * Hydrates a compact migratable record into a runtime object.
         *
         * @param mixed $record Compact record.
         * @return mixed
         */
        protected function hydrate_migratable_object( $record ) {
            if ( ! is_array( $record ) || empty( $record['object_type'] ) ) {
                return $record;
            }

            switch ( $record['object_type'] ) {
                case 'gallery':
                    $plugin = ! empty( $record['plugin_name'] ) ? $this->get_available_plugin_by_name( $record['plugin_name'] ) : false;
                    if ( false === $plugin ) {
                        return $record;
                    }
                    $object = new Objects\Gallery( $plugin );
                    break;
                case 'album':
                    $plugin = ! empty( $record['plugin_name'] ) ? $this->get_available_plugin_by_name( $record['plugin_name'] ) : false;
                    if ( false === $plugin ) {
                        return $record;
                    }
                    $object = new Objects\Album( $plugin );
                    break;
                case 'image':
                    $object = new Objects\Image();
                    break;
                default:
                    return $record;
            }

            $properties = array(
                'ID',
                'source_url',
                'slug',
                'title',
                'caption',
                'description',
                'alt',
                'date',
                'migration_status',
                'migrated',
                'migrated_child_count',
                'progress',
                'part_of_migration',
                'migrated_id',
                'migrated_title',
                'children_count',
            );

            foreach ( $properties as $property ) {
                if ( array_key_exists( $property, $record ) ) {
                    $object->$property = $record[ $property ];
                }
            }

            if ( array_key_exists( 'settings', $record ) ) {
                $object->settings = $record['settings'];
            }

            if ( isset( $record['children'] ) && is_array( $record['children'] ) ) {
                $children = array();
                foreach ( $record['children'] as $child_record ) {
                    $children[] = $this->hydrate_migratable_object( $child_record );
                }
                $object->children = $children;

                if ( ! array_key_exists( 'children_count', $record ) ) {
                    $object->children_count = count( $children );
                }
            }

            if ( isset( $record['error'] ) ) {
                $object->error = $this->hydrate_error( $record['error'] );
            }

            return $object;
        }

        /**
         * Compacts a plain value recursively without preserving PHP objects.
         *
         * @param mixed $value Value to compact.
         * @return mixed
         */
        protected function compact_plain_value( $value ) {
            if ( is_scalar( $value ) || null === $value ) {
                return $value;
            }

            if ( is_object( $value ) ) {
                $value = get_object_vars( $value );
            }

            if ( is_array( $value ) ) {
                $compact = array();
                foreach ( $value as $key => $item ) {
                    $compact[ $key ] = $this->compact_plain_value( $item );
                }
                return $compact;
            }

            return null;
        }

        /**
         * Compacts an error value to code/message data.
         *
         * @param mixed $error Error value.
         * @return array
         */
        protected function compact_error( $error ) {
            if ( is_wp_error( $error ) ) {
                return array(
                    'code'    => $error->get_error_code(),
                    'message' => $error->get_error_message(),
                );
            }

            if ( is_string( $error ) ) {
                return array(
                    'code'    => '',
                    'message' => $error,
                );
            }

            return array(
                'code'    => '',
                'message' => __( 'Unknown Error', 'foogallery-migrate' ),
            );
        }

        /**
         * Hydrates compact error data.
         *
         * @param mixed $error Error data.
         * @return mixed
         */
        protected function hydrate_error( $error ) {
            if ( ! is_array( $error ) ) {
                return $error;
            }

            $code = isset( $error['code'] ) ? $error['code'] : '';
            $message = isset( $error['message'] ) ? $error['message'] : __( 'Unknown Error', 'foogallery-migrate' );

            if ( class_exists( 'WP_Error' ) ) {
                return new \WP_Error( $code, $message );
            }

            return $message;
        }

        /**
         * Returns the saved user settings for the migrator.
         *
         * @return array
         */
        public function get_settings() {
            $defaults = array(
                self::SETTING_OVERRIDE_GALLERY_LAYOUT => '',
                self::SETTING_PAGE_SIZE => 20,
                self::SETTING_DEBUG_ENABLED => function_exists( 'foogallery_is_debug' ) && foogallery_is_debug(),
            );

            $settings = get_option( FOOGALLERY_MIGRATE_OPTION_SETTINGS, array() );

            if ( ! is_array( $settings ) ) {
                $settings = array();
            }

            $settings = array_merge( $defaults, $settings );
            $settings[ self::SETTING_OVERRIDE_GALLERY_LAYOUT ] = $this->sanitize_override_gallery_layout( $settings[ self::SETTING_OVERRIDE_GALLERY_LAYOUT ] );
            $settings[ self::SETTING_PAGE_SIZE ] = is_scalar( $settings[ self::SETTING_PAGE_SIZE ] ) ? absint( $settings[ self::SETTING_PAGE_SIZE ] ) : $defaults[ self::SETTING_PAGE_SIZE ];
            $settings[ self::SETTING_DEBUG_ENABLED ] = ! empty( $settings[ self::SETTING_DEBUG_ENABLED ] );

            return $settings;
        }

        /**
         * Saves the user settings for the migrator.
         *
         * @param array $settings Settings to save.
         * @return void
         */
        public function save_settings( $settings ) {
            $current_settings = $this->get_settings();

            if ( ! is_array( $settings ) ) {
                $settings = array();
            }

            $settings = array_merge( $current_settings, $settings );
            $settings[ self::SETTING_OVERRIDE_GALLERY_LAYOUT ] = $this->sanitize_override_gallery_layout( $settings[ self::SETTING_OVERRIDE_GALLERY_LAYOUT ] );
            $settings[ self::SETTING_PAGE_SIZE ] = is_scalar( $settings[ self::SETTING_PAGE_SIZE ] ) ? absint( $settings[ self::SETTING_PAGE_SIZE ] ) : $current_settings[ self::SETTING_PAGE_SIZE ];
            $settings[ self::SETTING_DEBUG_ENABLED ] = ! empty( $settings[ self::SETTING_DEBUG_ENABLED ] );

            update_option( FOOGALLERY_MIGRATE_OPTION_SETTINGS, $settings, false );
        }

        /**
         * Gets all available FooGallery gallery templates.
         *
         * @return array
         */
        public function get_available_gallery_templates() {
            $templates = array();

            if ( ! function_exists( 'foogallery_gallery_templates' ) ) {
                return $templates;
            }

            foreach ( foogallery_gallery_templates() as $template ) {
                if ( ! is_array( $template ) || empty( $template['slug'] ) ) {
                    continue;
                }

                $slug = (string) $template['slug'];
                $templates[ $slug ] = isset( $template['name'] ) ? (string) $template['name'] : $slug;
            }

            return $templates;
        }

        /**
         * Gets the selected gallery layout override.
         *
         * @return string
         */
        public function get_override_gallery_template() {
            $settings = $this->get_settings();

            return $settings[ self::SETTING_OVERRIDE_GALLERY_LAYOUT ];
        }

        /**
         * Gets the saved migration page size.
         *
         * @return int
         */
        public function get_page_size() {
            $settings = $this->get_settings();

            return absint( apply_filters( 'foogallery_migrate_page_size', $settings[ self::SETTING_PAGE_SIZE ] ) );
        }

        /**
         * Returns true if migration debug output is enabled.
         *
         * @return bool
         */
        public function is_debug_enabled() {
            $settings = $this->get_settings();

            return ! empty( $settings[ self::SETTING_DEBUG_ENABLED ] );
        }

        /**
         * Sanitizes the selected gallery layout override.
         *
         * @param string $value Gallery template slug.
         * @return string
         */
        protected function sanitize_override_gallery_layout( $value ) {
            if ( ! is_scalar( $value ) ) {
                return '';
            }

            $value = sanitize_key( (string) $value );

            if ( '' === $value ) {
                return '';
            }

            $templates = $this->get_available_gallery_templates();

            return array_key_exists( $value, $templates ) ? $value : '';
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
