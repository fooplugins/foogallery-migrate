<?php
/**
 * FooGallery Migrator Settings Class
 *
 * @package FooPlugins\FooGalleryMigrate
 */

namespace FooPlugins\FooGalleryMigrate;

use FooPlugins\FooGalleryMigrate\Objects\Plugin;

if ( ! class_exists( 'FooPlugins\FooGalleryMigrate\MigratorSettings' ) ) {

	/**
	 * Class MigratorSettings
	 *
	 * Handles user settings and persisted migration state storage.
	 *
	 * @package FooPlugins\FooGalleryMigrate
	 */
	class MigratorSettings {

		const KEY_PLUGINS = 'plugins';
		const KEY_GALLERIES = 'galleries';
		const KEY_ALBUMS = 'albums';
		const KEY_MIGRATED = 'migrated';
		const COMPACT_MARKER = '_foogallery_migrate_compact';
		const COMPACT_VERSION = 1;
		const SETTING_OVERRIDE_GALLERY_LAYOUT = 'override_gallery_layout';
		const SETTING_OVERRIDE_ALBUM_SETTINGS = 'override_album_settings';
		const SETTING_PAGE_SIZE = 'page_size';
		const SETTING_IMAGES_PER_TURN = 'images_per_turn';
		const SETTING_DEBUG_ENABLED = 'debug_enabled';

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

			if ( ! isset( $settings ) || ! is_array( $settings ) ) {
				$settings = array();
			}

			$settings[ $name ] = $this->compact_migrator_setting( $name, $value );

			update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $settings, false );
		}

		/**
		 * Clear migrator settings.
		 *
		 * @return void
		 */
		public function clear_migrator_setting() {
			update_option( FOOGALLERY_MIGRATE_OPTION_DATA, array(), false );
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
		 * Returns the saved user settings for the migrator.
		 *
		 * @return array
		 */
		public function get_settings() {
			$defaults = array(
				self::SETTING_OVERRIDE_GALLERY_LAYOUT => '',
				self::SETTING_OVERRIDE_ALBUM_SETTINGS => 0,
				self::SETTING_PAGE_SIZE => 20,
				self::SETTING_IMAGES_PER_TURN => 5,
				self::SETTING_DEBUG_ENABLED => function_exists( 'foogallery_is_debug' ) && foogallery_is_debug(),
			);

			$settings = get_option( FOOGALLERY_MIGRATE_OPTION_SETTINGS, array() );

			if ( ! is_array( $settings ) ) {
				$settings = array();
			}

			$settings = array_merge( $defaults, $settings );
			$settings[ self::SETTING_OVERRIDE_GALLERY_LAYOUT ] = $this->sanitize_override_gallery_layout( $settings[ self::SETTING_OVERRIDE_GALLERY_LAYOUT ] );
			$settings[ self::SETTING_OVERRIDE_ALBUM_SETTINGS ] = $this->sanitize_override_post_id( $settings[ self::SETTING_OVERRIDE_ALBUM_SETTINGS ], defined( 'FOOGALLERY_CPT_ALBUM' ) ? FOOGALLERY_CPT_ALBUM : '' );
			$settings[ self::SETTING_PAGE_SIZE ] = is_scalar( $settings[ self::SETTING_PAGE_SIZE ] ) ? absint( $settings[ self::SETTING_PAGE_SIZE ] ) : $defaults[ self::SETTING_PAGE_SIZE ];
			$settings[ self::SETTING_IMAGES_PER_TURN ] = is_scalar( $settings[ self::SETTING_IMAGES_PER_TURN ] ) ? $this->sanitize_positive_int( $settings[ self::SETTING_IMAGES_PER_TURN ] ) : $defaults[ self::SETTING_IMAGES_PER_TURN ];
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
			$settings[ self::SETTING_OVERRIDE_ALBUM_SETTINGS ] = $this->sanitize_override_post_id( $settings[ self::SETTING_OVERRIDE_ALBUM_SETTINGS ], defined( 'FOOGALLERY_CPT_ALBUM' ) ? FOOGALLERY_CPT_ALBUM : '' );
			$settings[ self::SETTING_PAGE_SIZE ] = is_scalar( $settings[ self::SETTING_PAGE_SIZE ] ) ? absint( $settings[ self::SETTING_PAGE_SIZE ] ) : $current_settings[ self::SETTING_PAGE_SIZE ];
			$settings[ self::SETTING_IMAGES_PER_TURN ] = is_scalar( $settings[ self::SETTING_IMAGES_PER_TURN ] ) ? $this->sanitize_positive_int( $settings[ self::SETTING_IMAGES_PER_TURN ] ) : $current_settings[ self::SETTING_IMAGES_PER_TURN ];
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
		 * Gets all available FooGallery albums that can supply album settings.
		 *
		 * @return array
		 */
		public function get_available_album_settings_sources() {
			return $this->get_available_setting_sources( defined( 'FOOGALLERY_CPT_ALBUM' ) ? FOOGALLERY_CPT_ALBUM : '' );
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
		 * Gets the selected source album to inherit settings from.
		 *
		 * @return int
		 */
		public function get_override_album_settings() {
			$settings = $this->get_settings();

			return absint( $settings[ self::SETTING_OVERRIDE_ALBUM_SETTINGS ] );
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
		 * Gets the number of images to import per migration AJAX turn.
		 *
		 * @return int
		 */
		public function get_images_per_turn() {
			$settings = $this->get_settings();

			return $this->sanitize_positive_int( apply_filters( 'foogallery_migrate_images_per_turn', $settings[ self::SETTING_IMAGES_PER_TURN ] ) );
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
		 * Gets posts that can be selected as settings sources.
		 *
		 * @param string $post_type Post type to list.
		 * @return array
		 */
		protected function get_available_setting_sources( $post_type ) {
			$sources = array();

			if ( '' === $post_type || ! function_exists( 'get_posts' ) ) {
				return $sources;
			}

			$posts = get_posts( array(
				'post_type'      => $post_type,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => -1,
				'numberposts'    => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			) );

			if ( ! is_array( $posts ) ) {
				return $sources;
			}

			foreach ( $posts as $post ) {
				if ( ! is_object( $post ) || empty( $post->ID ) ) {
					continue;
				}

				$title = isset( $post->post_title ) ? trim( (string) $post->post_title ) : '';
				if ( '' === $title ) {
					$title = sprintf( __( '(no title) #%d', 'foogallery-migrate' ), absint( $post->ID ) );
				}

				$sources[ absint( $post->ID ) ] = $title;
			}

			return $sources;
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
		 * Sanitizes a selected source post ID.
		 *
		 * @param mixed  $value Source post ID.
		 * @param string $post_type Expected post type.
		 * @return int
		 */
		protected function sanitize_override_post_id( $value, $post_type ) {
			if ( ! is_scalar( $value ) || '' === $post_type ) {
				return 0;
			}

			$post_id = absint( $value );
			if ( $post_id < 1 ) {
				return 0;
			}

			if ( ! function_exists( 'get_post' ) ) {
				return 0;
			}

			$post = get_post( $post_id );
			if ( ! is_object( $post ) || ! isset( $post->post_type ) || $post_type !== $post->post_type ) {
				return 0;
			}

			return $post_id;
		}

		/**
		 * Sanitizes a positive integer setting.
		 *
		 * @param mixed $value Setting value.
		 * @return int
		 */
		protected function sanitize_positive_int( $value ) {
			if ( ! is_scalar( $value ) ) {
				return 1;
			}

			return max( 1, absint( $value ) );
		}
	}
}
