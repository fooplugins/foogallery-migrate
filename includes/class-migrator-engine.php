<?php
/**
 * FooGallery Migrator Engine Class
 *
 * @package FooPlugins\FooGalleryMigrate
 */

namespace FooPlugins\FooGalleryMigrate;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use FooPlugins\FooGalleryMigrate\Objects\Migratable;
use FooPlugins\FooGalleryMigrate\Objects\Plugin;

if ( !class_exists( 'FooPlugins\FooGalleryMigrate\MigratorEngine' ) ) {

	/**
	 * Class MigratorEngine
	 *
	 * @package FooPlugins\FooGalleryMigrate
	 */
	class MigratorEngine {

        const KEY_PLUGINS = 'plugins';
        const KEY_GALLERIES = 'galleries';
        const KEY_ALBUMS = 'albums';
        const KEY_CONTENT = 'block-shortcode';
        const KEY_MIGRATED = 'migrated';
        const KEY_MIGRATED_REVISION = 'migrated-revision';
        const KEY_IMAGE_TAG_SYNC = 'image-tag-sync';

        /**
         * @var MigratorSettings
         */
        protected $settings;

        /** @var MigratedStore|MigratedHistoryRepository */
        protected $migrated_store;

        /** @var array Records queued for one request-level upsert. */
        protected $pending_migrated_objects = array();

        /** @var bool */
        protected $migrated_batch_active = false;

        /** @var array Request-local compact records keyed by migrated object identifier. */
        protected $migrated_object_records = array();

        /** True only for the production options factory, not inert injected seams. */
        private $storage_guarded = false;
        private $storage_gate;
        private $storage_control;
        private $production_backend;
        /** Request-local source/output calls not yet returned to their caller. */
        private $storage_operation_depth = 0;

        /**
         * @param MigratedStore|MigratedHistoryRepository|null $migrated_store Optional injected store.
         */
        public function __construct( $migrated_store = null ) {
            if ( $migrated_store instanceof MigratedStore || $migrated_store instanceof MigratedHistoryRepository ) {
                $this->migrated_store = $migrated_store;
                if ( $migrated_store instanceof MigratedHistoryRepository ) { $this->settings = new MigratorSettings( $migrated_store->state_backend() ); }
                return;
            }
            $this->storage_gate = StorageMaintenanceGate::current();
            $error = StorageAuthority::runtime_record( $this->storage_gate );
            if ( is_wp_error( $error ) ) {
                throw new \RuntimeException( $error->get_error_message() );
            }
            $this->storage_guarded = true;
            $this->storage_control = $error;
        }

        /** Defensive admission before state, source callbacks or output changes. */
        public function assert_storage_writer() {
            if ( ! $this->storage_guarded ) { return true; }
            $error = StorageAuthority::admit_option_writer( $this->storage_gate );
            if ( is_wp_error( $error ) ) { throw new \RuntimeException( $error->get_error_message() ); }
            $this->assert_storage_context();
            return true;
        }

        public function assert_storage_context() {
            if ( ! $this->storage_guarded ) { return true; }
            $current = StorageAuthority::runtime_record( $this->storage_gate );
            if ( is_wp_error( $current ) || $current !== $this->storage_control || $this->storage_gate !== StorageMaintenanceGate::current() ) {
                throw new \RuntimeException( 'Migration storage authority/context changed; discard the cached engine and use recovery without fallback.' );
            }
            return true;
        }

        private function production_backend() {
            $this->assert_storage_context();
            if ( ! isset( $this->production_backend ) ) {
                $admission = $this->storage_guarded ? array( $this, 'assert_storage_writer' ) : null;
                if ( is_array( $this->storage_control ) && 'table' === $this->storage_control['backend'] ) {
                    // Share the marker's failure latch as well as its mysqli
                    // handle. Uncertain repository COMMIT/readback must not
                    // leave a separately healthy gate able to release a writer.
                    $connection = $this->storage_gate->connection();
                    ( new LegacyStorageSchema( $connection ) )->verify();
                    $this->production_backend = new LegacyTableBackend( $connection, get_current_blog_id(), 10000, 16777216, 10, $admission, array( $this, 'assert_storage_context' ) );
                } else { $this->production_backend = new LegacyOptionBackend( $admission ); }
            }
            return $this->production_backend;
        }

        /** The current engine is synchronous; pending request-local work is not drained. */
        public function storage_is_quiescent() {
            try {
                return $this->storage_guarded && $this->storage_gate === StorageMaintenanceGate::current() &&
                    $this->storage_gate->connection()->is_current() &&
                    0 === $this->storage_operation_depth &&
                    Objects\Migratable::storage_is_quiescent() &&
                    ! $this->migrated_batch_active && empty( $this->pending_migrated_objects );
            } catch ( \Exception $error ) { return false; }
            catch ( \Throwable $error ) { return false; }
        }

        /** Trusted synchronous-engine verdict for authenticated maintenance.
         * Across requests the writer markers, not cached engine state, prove
         * drain. Opaque/injected engines cannot supply an affirmative verdict.
         */
        public static function storage_maintenance_is_quiescent() {
            global $foogallery_migrate_engine_instance;
            return ! isset( $foogallery_migrate_engine_instance ) ||
                ( $foogallery_migrate_engine_instance instanceof self && $foogallery_migrate_engine_instance->storage_is_quiescent() );
        }

        /** Internal synchronous boundary; not a callback or fault-injection API.
         * Nested operations share the writer but cannot drain the outer call.
         * No finally: retain the declared PHP 5.4 syntax floor.
         */
        private function storage_operation( $method, $arguments = array() ) {
            $this->assert_storage_writer();
            $this->storage_operation_depth++;
            try {
                $result = call_user_func_array( array( $this, $method ), $arguments );
                $this->assert_storage_writer();
            } catch ( \Exception $error ) {
                $this->storage_operation_depth--;
                throw $error;
            } catch ( \Throwable $error ) {
                $this->storage_operation_depth--;
                throw $error;
            }
            $this->storage_operation_depth--;
            return $result;
        }

        /**
         * Returns the migrator settings handler.
         *
         * @return MigratorSettings
         */
        protected function settings() {
            $this->assert_storage_context();
            if ( ! isset( $this->settings ) ) {
                $admission = $this->storage_guarded ? array( $this, 'assert_storage_writer' ) : null;
                $this->settings = new MigratorSettings( $this->production_backend(), $admission );
            }

            return $this->settings;
        }

        /** @return MigratedStore|MigratedHistoryRepository */
        protected function migrated_store() {
            $this->assert_storage_context();
            if ( ! isset( $this->migrated_store ) ) {
                $admission = $this->storage_guarded ? array( $this, 'assert_storage_writer' ) : null;
                $this->migrated_store = new MigratedHistoryRepository( $this->production_backend(), $this->settings() );
            }

            return $this->migrated_store;
        }

        /**
         * Returns a setting for the migrator.
         *
         * @return mixed
         */
        public function get_migrator_setting( $name, $default = false ) {
            return $this->settings()->get_migrator_setting( $name, $default );
        }

        /**
         * Sets a migrator setting.
         *
         * @param $name
         * @param $value
         * @return bool Whether the setting was persisted.
         */
        public function set_migrator_setting( $name, $value ) {
            return $this->storage_operation( 'set_migrator_setting_admitted', array( $name, $value ) );
        }

        private function set_migrator_setting_admitted( $name, $value ) {
            return $this->settings()->set_migrator_setting( $name, $value );
        }

        /**
         * Returns the saved user settings for the migrator.
         *
         * @return array
         */
        public function get_settings() {
            return $this->settings()->get_settings();
        }

        /**
         * Saves the user settings for the migrator.
         *
         * @param array $settings Settings to save.
         * @return void
         */
        public function save_settings( $settings ) {
            $this->storage_operation( 'save_settings_admitted', array( $settings ) );
        }

        private function save_settings_admitted( $settings ) {
            $this->settings()->save_settings( $settings );
        }

        /**
         * Gets all available FooGallery gallery templates.
         *
         * @return array
         */
        public function get_available_gallery_templates() {
            return $this->settings()->get_available_gallery_templates();
        }

        /**
         * Gets all available FooGallery galleries that can supply gallery settings.
         *
         * @return array
         */
        public function get_available_gallery_settings_sources() {
            return $this->settings()->get_available_gallery_settings_sources();
        }

        /**
         * Gets all available FooGallery albums that can supply album settings.
         *
         * @return array
         */
        public function get_available_album_settings_sources() {
            return $this->settings()->get_available_album_settings_sources();
        }

        /**
         * Gets the selected gallery layout override.
         *
         * @return string
         */
        public function get_override_gallery_template() {
            return $this->settings()->get_override_gallery_template();
        }

        /**
         * Gets the selected source gallery to inherit settings from.
         *
         * @return int
         */
        public function get_override_gallery_settings() {
            return $this->settings()->get_override_gallery_settings();
        }

        /**
         * Gets the selected source album to inherit settings from.
         *
         * @return int
         */
        public function get_override_album_settings() {
            return $this->settings()->get_override_album_settings();
        }

        /**
         * Gets the saved migration page size.
         *
         * @return int
         */
        public function get_page_size() {
            return $this->settings()->get_page_size();
        }

        /**
         * Gets the number of images to import per migration AJAX turn.
         *
         * @return int
         */
        public function get_images_per_turn() {
            return $this->settings()->get_images_per_turn();
        }

        /**
         * Returns true if migration debug output is enabled.
         *
         * @return bool
         */
        public function is_debug_enabled() {
            return $this->settings()->is_debug_enabled();
        }

        /**
         * Clear migrator settings.
         *
         * @return void
         */
        public function clear_migrator_setting() {
            return $this->storage_operation( 'clear_migrator_setting_admitted' );
        }

        private function clear_migrator_setting_admitted() {
            $this->assert_storage_writer();
			if ( ! $this->settings()->can_clear_migrator_state() ) { throw new \RuntimeException( 'Whole table-state reset is not admitted. No partial history clear or option fallback.' ); }
			if ( ! $this->migrated_store()->clear() ) {
				return false;
			}

			return $this->settings()->clear_migrator_setting();
        }

        /**
         * Returns true if we have any saved migrator settings.
         *
         * @return bool
         */
        public function has_migrator_settings() {
            return $this->settings()->has_migrator_settings();
        }

        /**
         * Runs detection for all plugins.
         *
         * @return array<Plugin>
         */
        public function run_detection() {
            return $this->storage_operation( 'run_detection_admitted' );
        }

        private function run_detection_admitted() {
            $plugins = foogallery_migrate_get_available_plugins();

            foreach ( $plugins as $plugin ) {
                $this->assert_storage_writer();
                $plugin->is_detected = $plugin->detect();
                $this->assert_storage_writer();
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
         * Returns true when a detected source plugin has migratable image tags.
         *
         * @return bool
         */
        public function has_migratable_image_tags() {
            foreach ( $this->get_plugins() as $plugin ) {
                if ( ! is_object( $plugin ) || empty( $plugin->is_detected ) ) {
                    continue;
                }

                if ( method_exists( $plugin, 'has_migratable_image_tags' ) && $plugin->has_migratable_image_tags() ) {
                    return true;
                }
            }

            return false;
        }

        /**
         * Returns true when FooGallery Expert media tag functionality is available.
         *
         * @return bool
         */
        public function is_foogallery_expert_available() {
            if (
                defined( 'FOOGALLERY_ATTACHMENT_TAXONOMY_TAG' ) &&
                function_exists( 'taxonomy_exists' ) &&
                taxonomy_exists( FOOGALLERY_ATTACHMENT_TAXONOMY_TAG )
            ) {
                return true;
            }

            if ( ! function_exists( 'foogallery_fs' ) ) {
                return false;
            }

            $fs = foogallery_fs();
            if ( ! is_object( $fs ) ) {
                return false;
            }

            if ( method_exists( $fs, 'can_use_premium_code' ) && ! $fs->can_use_premium_code() ) {
                return false;
            }

            $expert_plan = defined( 'FOOGALLERY_PRO_PLAN_EXPERT' ) ? FOOGALLERY_PRO_PLAN_EXPERT : 'pro';
            if ( method_exists( $fs, 'is_plan_or_trial' ) ) {
                return (bool) $fs->is_plan_or_trial( $expert_plan );
            }

            if ( method_exists( $fs, 'is_plan' ) ) {
                return (bool) $fs->is_plan( $expert_plan );
            }

            return false;
        }

        /**
         * Returns true when the migration screen should warn about tagged image migrations.
         *
         * @return bool
         */
        public function should_show_image_tag_plan_warning() {
            return $this->has_migratable_image_tags() && ! $this->is_foogallery_expert_available();
        }

        /**
         * Returns the current post-migration image tag sync status.
         *
         * @return array
         */
        public function get_image_tag_sync_status() {
            return $this->format_image_tag_sync_state(
                $this->get_migrator_setting(
                    self::KEY_IMAGE_TAG_SYNC,
                    $this->get_default_image_tag_sync_state()
                )
            );
        }

        /**
         * Starts a new post-migration image tag sync job and processes the first batch.
         *
         * @return array|\WP_Error
         */
        public function start_image_tag_sync() {
            return $this->storage_operation( 'start_image_tag_sync_admitted' );
        }

        private function start_image_tag_sync_admitted() {
            if ( ! $this->can_assign_image_tag_terms() ) {
                return new \WP_Error(
                    'foogallery_migrate_image_tags_unavailable',
                    __( 'FooGallery media tags are not available.', 'foogallery-migrate' )
                );
            }

            $this->set_migrator_setting( self::KEY_IMAGE_TAG_SYNC, $this->build_image_tag_sync_state() );

            return $this->continue_image_tag_sync();
        }

        /**
         * Processes the next post-migration image tag sync batch.
         *
         * @return array|\WP_Error
         */
        public function continue_image_tag_sync() {
            return $this->storage_operation( 'continue_image_tag_sync_admitted' );
        }

        private function continue_image_tag_sync_admitted() {
            if ( ! $this->can_assign_image_tag_terms() ) {
                return new \WP_Error(
                    'foogallery_migrate_image_tags_unavailable',
                    __( 'FooGallery media tags are not available.', 'foogallery-migrate' )
                );
            }

            $state = $this->get_migrator_setting( self::KEY_IMAGE_TAG_SYNC, false );
            if ( false === $state || ! is_array( $state ) ) {
                return new \WP_Error(
                    'foogallery_migrate_image_tags_not_queued',
                    __( 'No image tag sync is queued.', 'foogallery-migrate' )
                );
            }

            $items = isset( $state['items'] ) && is_array( $state['items'] ) ? $state['items'] : array();
            $total = isset( $state['total'] ) ? absint( $state['total'] ) : count( $items );
            $processed = isset( $state['processed'] ) ? absint( $state['processed'] ) : 0;
            $batch_size = max( 1, $this->get_images_per_turn() );
            $batch_count = 0;

            while ( $processed < $total && $batch_count < $batch_size ) {
                $item = isset( $items[ $processed ] ) && is_array( $items[ $processed ] ) ? $items[ $processed ] : array();
                $processed++;
                $batch_count++;

                $attachment_id = isset( $item['attachment_id'] ) ? absint( $item['attachment_id'] ) : 0;
                $tags = isset( $item['tags'] ) ? $this->normalize_image_tag_terms( $item['tags'] ) : array();

                if ( $attachment_id < 1 || empty( $tags ) ) {
                    $state['skipped'] = isset( $state['skipped'] ) ? absint( $state['skipped'] ) + 1 : 1;
                    continue;
                }

                $this->assert_storage_writer();
                $result = wp_set_object_terms( $attachment_id, $tags, FOOGALLERY_ATTACHMENT_TAXONOMY_TAG, true );
                $this->assert_storage_writer();
                if ( is_wp_error( $result ) ) {
                    $this->add_image_tag_sync_error( $state, $item, $result->get_error_message() );
                    continue;
                }

                $state['updated'] = isset( $state['updated'] ) ? absint( $state['updated'] ) + 1 : 1;
            }

            $state['processed'] = $processed;
            $state['total'] = $total;
            $state['complete'] = $processed >= $total;
            $state['progress'] = $total > 0 ? min( 100, (int) floor( $processed / $total * 100 ) ) : 100;

            $this->set_migrator_setting( self::KEY_IMAGE_TAG_SYNC, $state );

            return $this->format_image_tag_sync_state( $state );
        }

        /**
         * Returns true when FooGallery media tag terms can be assigned.
         *
         * @return bool
         */
        private function can_assign_image_tag_terms() {
            return defined( 'FOOGALLERY_ATTACHMENT_TAXONOMY_TAG' ) &&
                function_exists( 'taxonomy_exists' ) &&
                taxonomy_exists( FOOGALLERY_ATTACHMENT_TAXONOMY_TAG ) &&
                function_exists( 'wp_set_object_terms' );
        }

        /**
         * Builds a queue of migrated attachments that should receive image tag terms.
         *
         * @return array
         */
        private function build_image_tag_sync_state() {
            $items = array();
            $seen_attachment_ids = array();
            $source_url_map = array();
            $source_id_map = array();
            $unmatched = 0;

            foreach ( $this->get_migrated_objects() as $object ) {
                if ( ! is_object( $object ) || ! method_exists( $object, 'type' ) || 'image' !== $object->type() ) {
                    continue;
                }

                $attachment_id = isset( $object->migrated_id ) ? absint( $object->migrated_id ) : 0;
                if ( $attachment_id < 1 ) {
                    continue;
                }

                $source_url = isset( $object->source_url ) && is_scalar( $object->source_url ) ? (string) $object->source_url : '';
                if ( '' !== $source_url && ! isset( $source_url_map[ $source_url ] ) ) {
                    $source_url_map[ $source_url ] = $attachment_id;
                }

                $plugin_name = $this->get_migrated_object_plugin_name( $object );
                $source_image_id = isset( $object->ID ) ? absint( $object->ID ) : 0;
                if ( '' !== $plugin_name && $source_image_id > 0 && ! isset( $source_id_map[ $plugin_name ][ $source_image_id ] ) ) {
                    if ( ! isset( $source_id_map[ $plugin_name ] ) ) {
                        $source_id_map[ $plugin_name ] = array();
                    }
                    $source_id_map[ $plugin_name ][ $source_image_id ] = $attachment_id;
                }

                $tags = $this->get_migrated_object_image_tags( $object );
                if ( empty( $tags ) ) {
                    continue;
                }

                $this->add_image_tag_sync_item(
                    $items,
                    $seen_attachment_ids,
                    array(
                        'attachment_id'   => $attachment_id,
                        'plugin_name'     => $plugin_name,
                        'source_image_id' => $source_image_id,
                        'source_url'      => $source_url,
                        'tags'            => $tags,
                        'source'          => 'state',
                    )
                );
            }

            foreach ( $this->get_plugins() as $plugin ) {
                if ( ! is_object( $plugin ) || empty( $plugin->is_detected ) || ! method_exists( $plugin, 'find_image_tag_sync_items' ) ) {
                    continue;
                }

                $fallback_items = $plugin->find_image_tag_sync_items();
                if ( ! is_array( $fallback_items ) ) {
                    continue;
                }

                foreach ( $fallback_items as $fallback_item ) {
                    if ( ! is_array( $fallback_item ) ) {
                        continue;
                    }

                    $tags = isset( $fallback_item['tags'] ) ? $this->normalize_image_tag_terms( $fallback_item['tags'] ) : array();
                    if ( empty( $tags ) ) {
                        continue;
                    }

                    $attachment_id = $this->resolve_image_tag_sync_attachment_id( $fallback_item, $source_url_map, $source_id_map );
                    if ( $attachment_id < 1 ) {
                        $unmatched++;
                        continue;
                    }

                    $fallback_item['attachment_id'] = $attachment_id;
                    $fallback_item['tags'] = $tags;
                    $fallback_item['source'] = isset( $fallback_item['source'] ) ? $fallback_item['source'] : 'fallback';
                    $this->add_image_tag_sync_item( $items, $seen_attachment_ids, $fallback_item );
                }
            }

            $total = count( $items );

            return array(
                'items'       => array_values( $items ),
                'total'       => $total,
                'processed'   => 0,
                'updated'     => 0,
                'skipped'     => 0,
                'unmatched'   => $unmatched,
                'error_count' => 0,
                'errors'      => array(),
                'complete'    => 0 === $total,
                'progress'    => 0 === $total ? 100 : 0,
            );
        }

        /**
         * Adds or merges an image tag sync queue item.
         *
         * @param array $items Queue items.
         * @param array $seen_attachment_ids Attachment lookup.
         * @param array $item Queue item.
         * @return void
         */
        private function add_image_tag_sync_item( &$items, &$seen_attachment_ids, $item ) {
            $attachment_id = isset( $item['attachment_id'] ) ? absint( $item['attachment_id'] ) : 0;
            $tags = isset( $item['tags'] ) ? $this->normalize_image_tag_terms( $item['tags'] ) : array();
            if ( $attachment_id < 1 || empty( $tags ) ) {
                return;
            }

            $key = (string) $attachment_id;
            if ( isset( $seen_attachment_ids[ $key ] ) ) {
                $index = $seen_attachment_ids[ $key ];
                $items[ $index ]['tags'] = $this->normalize_image_tag_terms( array_merge( $items[ $index ]['tags'], $tags ) );
                return;
            }

            $item['attachment_id'] = $attachment_id;
            $item['tags'] = $tags;
            $seen_attachment_ids[ $key ] = count( $items );
            $items[] = $item;
        }

        /**
         * Gets tag names for a migrated image object.
         *
         * @param object $object Migrated image object.
         * @return array
         */
        private function get_migrated_object_image_tags( $object ) {
            if ( ! isset( $object->plugin ) || ! is_object( $object->plugin ) || ! method_exists( $object->plugin, 'get_image_tags' ) ) {
                return array();
            }

            return $this->normalize_image_tag_terms( $object->plugin->get_image_tags( $object ) );
        }

        /**
         * Gets the source plugin name for a migrated object.
         *
         * @param object $object Migrated object.
         * @return string
         */
        private function get_migrated_object_plugin_name( $object ) {
            if ( ! isset( $object->plugin ) || ! is_object( $object->plugin ) || ! method_exists( $object->plugin, 'name' ) ) {
                return '';
            }

            return (string) $object->plugin->name();
        }

        /**
         * Resolves the migrated attachment ID for a fallback image tag sync item.
         *
         * @param array $item Fallback item.
         * @param array $source_url_map Source URL to attachment map.
         * @param array $source_id_map Source plugin/image ID to attachment map.
         * @return int
         */
        private function resolve_image_tag_sync_attachment_id( $item, $source_url_map, $source_id_map ) {
            if ( isset( $item['attachment_id'] ) && absint( $item['attachment_id'] ) > 0 ) {
                return absint( $item['attachment_id'] );
            }

            $plugin_name = isset( $item['plugin_name'] ) && is_scalar( $item['plugin_name'] ) ? (string) $item['plugin_name'] : '';
            $source_image_id = isset( $item['source_image_id'] ) ? absint( $item['source_image_id'] ) : 0;
            if (
                '' !== $plugin_name &&
                $source_image_id > 0 &&
                isset( $source_id_map[ $plugin_name ] ) &&
                isset( $source_id_map[ $plugin_name ][ $source_image_id ] )
            ) {
                return absint( $source_id_map[ $plugin_name ][ $source_image_id ] );
            }

            $source_url = isset( $item['source_url'] ) && is_scalar( $item['source_url'] ) ? (string) $item['source_url'] : '';
            if ( '' === $source_url ) {
                return 0;
            }

            if ( isset( $source_url_map[ $source_url ] ) ) {
                return absint( $source_url_map[ $source_url ] );
            }

            $attachment_id = $this->find_attachment_imported_from_source_url( $source_url );
            if ( $attachment_id > 0 ) {
                return $attachment_id;
            }

            if ( function_exists( 'attachment_url_to_postid' ) ) {
                return absint( attachment_url_to_postid( $source_url ) );
            }

            return 0;
        }

        /**
         * Finds an attachment imported from a source URL.
         *
         * @param string $source_url Source image URL.
         * @return int
         */
        private function find_attachment_imported_from_source_url( $source_url ) {
            if ( ! function_exists( 'get_posts' ) ) {
                return 0;
            }

            $attachments = get_posts(
                array(
                    'post_type'      => 'attachment',
                    'post_status'    => 'inherit',
                    'posts_per_page' => 1,
                    'numberposts'    => 1,
                    'fields'         => 'ids',
                    'meta_key'       => '_foogallery_imported_from',
                    'meta_value'     => $source_url,
                )
            );

            if ( ! is_array( $attachments ) || empty( $attachments ) ) {
                return 0;
            }

            $attachment = reset( $attachments );
            if ( is_object( $attachment ) && isset( $attachment->ID ) ) {
                return absint( $attachment->ID );
            }

            return absint( $attachment );
        }

        /**
         * Normalizes image tag terms.
         *
         * @param mixed $tags Tags.
         * @return array
         */
        private function normalize_image_tag_terms( $tags ) {
            if ( ! is_array( $tags ) ) {
                return array();
            }

            $normalized_tags = array();
            foreach ( $tags as $tag ) {
                if ( ! is_scalar( $tag ) ) {
                    continue;
                }

                $tag = trim( (string) $tag );
                if ( '' !== $tag && ! in_array( $tag, $normalized_tags, true ) ) {
                    $normalized_tags[] = $tag;
                }
            }

            return $normalized_tags;
        }

        /**
         * Adds an error message to the image tag sync state.
         *
         * @param array  $state Sync state.
         * @param array  $item Queue item.
         * @param string $message Error message.
         * @return void
         */
        private function add_image_tag_sync_error( &$state, $item, $message ) {
            $state['error_count'] = isset( $state['error_count'] ) ? absint( $state['error_count'] ) + 1 : 1;
            if ( ! isset( $state['errors'] ) || ! is_array( $state['errors'] ) ) {
                $state['errors'] = array();
            }

            if ( count( $state['errors'] ) >= 20 ) {
                return;
            }

            $state['errors'][] = array(
                'attachment_id' => isset( $item['attachment_id'] ) ? absint( $item['attachment_id'] ) : 0,
                'source_url'    => isset( $item['source_url'] ) && is_scalar( $item['source_url'] ) ? (string) $item['source_url'] : '',
                'message'       => $message,
            );
        }

        /**
         * Returns default image tag sync state.
         *
         * @return array
         */
        private function get_default_image_tag_sync_state() {
            return array(
                'items'       => array(),
                'total'       => 0,
                'processed'   => 0,
                'updated'     => 0,
                'skipped'     => 0,
                'unmatched'   => 0,
                'error_count' => 0,
                'errors'      => array(),
                'complete'    => true,
                'progress'    => 100,
            );
        }

        /**
         * Formats image tag sync state for UI/AJAX output.
         *
         * @param array $state Sync state.
         * @return array
         */
        private function format_image_tag_sync_state( $state ) {
            if ( ! is_array( $state ) ) {
                $state = $this->get_default_image_tag_sync_state();
            }

            return array(
                'total'       => isset( $state['total'] ) ? absint( $state['total'] ) : 0,
                'processed'   => isset( $state['processed'] ) ? absint( $state['processed'] ) : 0,
                'updated'     => isset( $state['updated'] ) ? absint( $state['updated'] ) : 0,
                'skipped'     => isset( $state['skipped'] ) ? absint( $state['skipped'] ) : 0,
                'unmatched'   => isset( $state['unmatched'] ) ? absint( $state['unmatched'] ) : 0,
                'error_count' => isset( $state['error_count'] ) ? absint( $state['error_count'] ) : 0,
                'errors'      => isset( $state['errors'] ) && is_array( $state['errors'] ) ? $state['errors'] : array(),
                'complete'    => ! empty( $state['complete'] ),
                'progress'    => isset( $state['progress'] ) ? absint( $state['progress'] ) : 0,
            );
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
         * Returns true when a migrated object differs from its request-local record.
         *
         * @param Migratable $object Migrated object candidate.
         * @return bool
         */
        private function migrated_object_has_changed( $object ) {
            $key = (string) $object->unique_identifier();
            if ( ! array_key_exists( $key, $this->migrated_object_records ) ) {
                return true;
            }

            return $this->migrated_object_records[ $key ] !== $this->settings()->compact_migrated_object( $object );
        }

        /**
         * Cache one migrated object record without querying the full store.
         *
         * @param Migratable $object Migrated object.
         * @return void
         */
        private function remember_migrated_object( $object ) {
            if ( is_object( $object ) && method_exists( $object, 'unique_identifier' ) ) {
                $this->migrated_object_records[ (string) $object->unique_identifier() ] = $this->settings()->compact_migrated_object( $object );
            }
        }

        /**
         * Advance the scalar migrated-object revision without restoring the legacy map.
         *
         * @return bool Whether the revision was persisted.
         */
        private function increment_migrated_revision() {
            if ( $this->migrated_store()->manages_revision() ) {
                return true;
            }
            return $this->set_migrator_setting( self::KEY_MIGRATED_REVISION, $this->get_migrated_revision() + 1 );
        }

        /**
         * Store a migrated object, so that it does not get migrated twice.
         *
         * @param $object Migratable
         * @return void
         */
        public function add_migrated_object( $object ) {
            return $this->storage_operation( 'add_migrated_object_admitted', array( $object ) );
        }

        private function add_migrated_object_admitted( $object ) {
            $this->assert_storage_writer();
            if ( ! is_object( $object ) || ! method_exists( $object, 'unique_identifier' ) ) {
                return false;
            }

            $key = (string) $object->unique_identifier();
            $record = array(
                'object'     => $object,
                'parent_key' => isset( $object->migration_parent_key ) ? $object->migration_parent_key : null,
            );

            if ( $this->migrated_batch_active ) {
                $this->pending_migrated_objects[ $key ] = $record;
                return true;
            }

            $changed = $this->migrated_object_has_changed( $object );
            if ( $changed && ! $this->increment_migrated_revision() ) {
                return false;
            }

            if ( false === $this->migrated_store()->upsert_batch( array( $record ) ) ) {
                return false;
            }

            $this->remember_migrated_object( $object );
            return true;
        }

        /** @return void */
        public function begin_migrated_object_batch() {
            $this->assert_storage_writer();
            $this->pending_migrated_objects = array();
            $this->migrated_batch_active = true;
        }

        /** @return bool */
        public function flush_migrated_object_batch() {
            return $this->storage_operation( 'flush_migrated_object_batch_admitted' );
        }

        private function flush_migrated_object_batch_admitted() {
            $this->assert_storage_writer();
            $records = array_values( $this->pending_migrated_objects );
			$changed = false;
			foreach ( $records as $record ) {
				if ( isset( $record['object'] ) && $this->migrated_object_has_changed( $record['object'] ) ) {
					$changed = true;
					break;
				}
			}

			if ( $changed && ! $this->increment_migrated_revision() ) {
				return false;
			}

			if ( ! empty( $records ) && false === $this->migrated_store()->upsert_batch( $records ) ) {
				return false;
			}

			foreach ( $records as $record ) {
				if ( isset( $record['object'] ) ) {
					$this->remember_migrated_object( $record['object'] );
				}
			}

			$this->pending_migrated_objects = array();
			$this->migrated_batch_active = false;
			return true;
        }

        /**
         * Check if an object has been migrated previously.
         *
         * @param $unique_identifier
         * @return bool
         */
        public function has_object_been_migrated( $unique_identifier ) {
            if ( isset( $this->pending_migrated_objects[ $unique_identifier ] ) ) {
                return true;
            }

            return $this->migrated_store()->has( $unique_identifier );
        }

        /**
         * Get all previously migrated objects.
         *
         * @return array<Migratable>
         */
        public function get_migrated_objects() {
            $objects = $this->migrated_store()->get_all();
            foreach ( $objects as $object ) {
                $this->remember_migrated_object( $object );
            }

            return $objects;
        }

        /** Inform existing content callers about explicit injection, never select authority. */
        public function uses_scoped_history() {
            return $this->migrated_store instanceof MigratedHistoryRepository
                && $this->migrated_store->state_backend() instanceof LegacyTableBackend;
        }

        /**
         * Return the revision of the migrated-object map.
         *
         * Existing installations with migrated objects predate revision tracking,
         * so they start at revision one and make legacy content scans stale once.
         *
         * @return int
         */
        public function get_migrated_revision() {
            if ( $this->migrated_store()->manages_revision() ) {
                return $this->migrated_store()->revision();
            }
            $revision = $this->get_migrator_setting( self::KEY_MIGRATED_REVISION, false );
            if ( false !== $revision ) {
                return absint( $revision );
            }

            return $this->has_migrated_objects() ? 1 : 0;
        }

        /**
         * Update a migrated object's status.
         *
         * @param string $unique_identifier
         * @param string $status
         * @return Migratable|\WP_Error
         */
        public function update_migrated_object_status( $unique_identifier, $status ) {
            return $this->storage_operation( 'update_migrated_object_status_admitted', array( $unique_identifier, $status ) );
        }

        private function update_migrated_object_status_admitted( $unique_identifier, $status ) {
            $this->assert_storage_writer();
            $object = $this->get_migrated_object( $unique_identifier );
            if ( false === $object ) {
                return new \WP_Error( 'foogallery_migrate_missing_object', __( 'Migrated object not found.', 'foogallery-migrate' ) );
            }

            if ( ! is_object( $object ) ) {
                return new \WP_Error( 'foogallery_migrate_invalid_object', __( 'Invalid migrated object.', 'foogallery-migrate' ) );
            }

            // Patch the exact stored key, not a re-derived identifier or a narrowed
            // hydrated record. Preserve unknown raw fields and wrapper metadata.
            if ( ! $this->migrated_batch_active && $this->migrated_store()->manages_revision() ) {
                $saved = $this->migrated_store()->set_status( $unique_identifier, $status );
                if ( $saved ) {
                    $object->migration_status = $status;
                    $this->remember_migrated_object( $object );
                }
            } else {
                $object->migration_status = $status;
                $saved = $this->add_migrated_object( $object );
            }
            if ( false === $saved ) {
                return new \WP_Error( 'foogallery_migrate_store_write_failed', __( 'Migrated object could not be updated.', 'foogallery-migrate' ) );
            }

            return $object;
        }

        /**
         * Delete a migrated object.
         *
         * @param string $unique_identifier
         * @return bool|\WP_Error
         */
        public function delete_migrated_object( $unique_identifier ) {
            return $this->storage_operation( 'delete_migrated_object_admitted', array( $unique_identifier ) );
        }

        private function delete_migrated_object_admitted( $unique_identifier ) {
            $this->assert_storage_writer();
            if ( ! $this->has_object_been_migrated( $unique_identifier ) ) {
                return new \WP_Error( 'foogallery_migrate_missing_object', __( 'Migrated object not found.', 'foogallery-migrate' ) );
            }

            if ( ! $this->increment_migrated_revision() ) {
                return false;
            }

            if ( ! $this->migrated_store()->delete( $unique_identifier ) ) {
                return false;
            }

            unset( $this->migrated_object_records[ $unique_identifier ] );
            return true;
        }

        /**
         * Get a previously migrated object.
         *
         * @return Migratable|bool
         */
        public function get_migrated_object( $unique_identifier ) {
            if ( isset( $this->pending_migrated_objects[ $unique_identifier ] ) ) {
                return $this->pending_migrated_objects[ $unique_identifier ]['object'];
            }

            $object = $this->migrated_store()->get( $unique_identifier );
            if ( false !== $object ) {
                $this->remember_migrated_object( $object );
            }

            return $object;
        }

        /**
         * Returns true if any objects have been migrated.
         *
         * @return bool
         */
        public function has_migrated_objects() {
            $store = $this->migrated_store();
            // The explicitly injected common repository owns presence, including
            // empty table history. Never reinterpret it using stale options.
            return $store instanceof MigratedHistoryRepository ? $store->count() > 0
                : $store->count() > 0 || $this->settings()->has_migrator_setting_items( self::KEY_MIGRATED );
        }

        /**
         * Returns a summary of migrated objects.
         *
         * @return array
         */
        public function get_migrated_objects_summary() {
            return $this->migrated_store()->summary();
        }

        /**
         * Overlays one parent's request-local children from one indexed query.
         *
         * @param object $parent Parent migratable object.
         * @return void
         */
        public function apply_migrated_children( $parent ) {
            if ( ! is_object( $parent ) || ! method_exists( $parent, 'unique_identifier' ) || ! method_exists( $parent, 'get_children' ) ) {
                return;
            }

            $parent_key = (string) $parent->unique_identifier();
            $stored = array();
            $children = $parent->get_children();
            $store = $this->migrated_store();
            if ( $store instanceof MigratedHistoryRepository ) {
                $keys = array();
                foreach ( $children as $child ) {
                    if ( is_object( $child ) && method_exists( $child, 'unique_identifier' ) ) { $key = $child->unique_identifier(); $keys[serialize( $key )] = $key; }
                }
                $migrated_children = $store->get_children_for_parent( $parent_key, array_values( $keys ) );
            } else { $migrated_children = $store->get_by_parent( $parent_key ); }
            foreach ( $migrated_children as $object ) {
                if ( is_object( $object ) && method_exists( $object, 'unique_identifier' ) ) {
                    $stored[ (string) $object->unique_identifier() ] = $object;
                }
            }

            foreach ( $children as $index => $child ) {
                if ( ! is_object( $child ) || ! method_exists( $child, 'unique_identifier' ) ) {
                    continue;
                }
                $child_key = (string) $child->unique_identifier();
                if ( isset( $stored[ $child_key ] ) ) {
                    $child = $stored[ $child_key ];
					$child->migration_store_checked = true;
                }
                $child->migration_parent_key = $parent_key;
                $children[ $index ] = $child;
            }
            $parent->children = $children;
        }

        /**
         * Reset a gallery migration and re-queue it for processing.
         *
         * @param string $unique_identifier
         * @return bool|\WP_Error
         */
        public function retry_gallery_migration( $unique_identifier ) {
            return $this->storage_operation( 'retry_gallery_migration_admitted', array( $unique_identifier ) );
        }

        private function retry_gallery_migration_admitted( $unique_identifier ) {
            $this->assert_storage_writer();
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

            $gallery->ensure_children_loaded();
            $this->apply_migrated_children( $gallery );
			$reset_records = array();

            if ( method_exists( $gallery, 'has_children' ) && $gallery->has_children() ) {
                $children = $gallery->get_children();
                foreach ( $children as $index => $child ) {
                    if ( ! is_object( $child ) || ! method_exists( $child, 'unique_identifier' ) ) {
                        continue;
                    }

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
						$reset_records[] = array( 'object' => $child, 'parent_key' => $unique_identifier );
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
			$reset_records[] = array( 'object' => $gallery, 'parent_key' => null );
			if ( false === $this->migrated_store()->upsert_batch( $reset_records ) ) {
				return new \WP_Error( 'foogallery_migrate_store_write_failed', __( 'Migrated gallery state could not be reset.', 'foogallery-migrate' ) );
			}

            $galleries[ $gallery_index ] = $gallery;
            if ( ! $this->set_migrator_setting( self::KEY_GALLERIES, $galleries ) ) {
				return new \WP_Error( 'foogallery_migrate_store_write_failed', __( 'Migrated gallery state could not be saved.', 'foogallery-migrate' ) );
			}

            if ( ! $gallery_migrator->queue_objects_for_migration(
                array(
                    $unique_identifier => array(
                        'id'       => $unique_identifier,
                        'migrated' => false,
                        'current'  => false,
                        'title'    => isset( $gallery->title ) ? $gallery->title : '',
                    ),
                )
            ) ) {
				return new \WP_Error( 'foogallery_migrate_store_write_failed', __( 'Migrated gallery state could not be saved.', 'foogallery-migrate' ) );
			}
			if ( false === $gallery_migrator->migrate() ) {
				return new \WP_Error( 'foogallery_migrate_store_write_failed', __( 'Migrated gallery state could not be saved.', 'foogallery-migrate' ) );
			}

            return true;
        }

        /**
         * Checks migrated images for missing attachment files and marks errors.
         *
         * @param string $unique_identifier
         * @return array|\WP_Error
         */
        public function check_for_migration_errors( $unique_identifier = '' ) {
            return $this->storage_operation( 'check_for_migration_errors_admitted', array( $unique_identifier ) );
        }

        private function check_for_migration_errors_admitted( $unique_identifier ) {
            $this->assert_storage_writer();
            $gallery_migrator = $this->get_gallery_migrator();
            $galleries = $gallery_migrator->get_objects_to_migrate();
            $checked = 0;
            $errors = 0;
            $found_gallery = false;
			$this->begin_migrated_object_batch();

            foreach ( $galleries as $index => $gallery ) {
                if ( ! is_object( $gallery ) || ! method_exists( $gallery, 'unique_identifier' ) ) {
                    continue;
                }

                if ( '' !== $unique_identifier && $gallery->unique_identifier() !== $unique_identifier ) {
                    continue;
                }

                $found_gallery = true;
				$gallery->ensure_children_loaded();
				$this->apply_migrated_children( $gallery );
                $result = $this->check_gallery_for_missing_files( $gallery );
                $galleries[ $index ] = $result['gallery'];
                $checked += $result['checked'];
                $errors += $result['errors'];
            }

            if ( '' !== $unique_identifier && ! $found_gallery ) {
				$this->flush_migrated_object_batch();
                return new \WP_Error( 'foogallery_migrate_missing_gallery', __( 'Gallery not found.', 'foogallery-migrate' ) );
            }

			if ( ! $this->flush_migrated_object_batch() ) {
				return new \WP_Error( 'foogallery_migrate_store_write_failed', __( 'Migrated gallery state could not be saved.', 'foogallery-migrate' ) );
			}
            if ( ! $this->set_migrator_setting( self::KEY_GALLERIES, $galleries ) ) {
				return new \WP_Error( 'foogallery_migrate_store_write_failed', __( 'Migrated gallery state could not be saved.', 'foogallery-migrate' ) );
            }

            return array(
                'checked' => $checked,
                'errors'  => $errors,
            );
        }

        /**
         * Checks a gallery's children for missing attachment files and marks errors.
         *
         * @param object $gallery
         * @return array
         */
        private function check_gallery_for_missing_files( $gallery ) {
            $checked = 0;
            $errors = 0;
            $has_child_error = false;

            if ( method_exists( $gallery, 'has_children' ) && $gallery->has_children() ) {
                $children = $gallery->get_children();
                foreach ( $children as $index => $child ) {
                    if ( ! is_object( $child ) || ! method_exists( $child, 'unique_identifier' ) ) {
                        continue;
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
					$this->add_migrated_object( $child );
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
				$this->add_migrated_object( $gallery );
            }

            return array(
                'gallery'          => $gallery,
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
            $this->assert_storage_writer();
            return $this->check_for_migration_errors( $unique_identifier );
        }
	}
}
