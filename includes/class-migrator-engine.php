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

        const KEY_PLUGINS = 'plugins';
        const KEY_GALLERIES = 'galleries';
        const KEY_ALBUMS = 'albums';
        const KEY_CONTENT = 'block-shortcode';
        const KEY_MIGRATED = 'migrated';
        const KEY_IMAGE_TAG_SYNC = 'image-tag-sync';

        /**
         * @var MigratorSettings
         */
        protected $settings;

        /**
         * Returns the migrator settings handler.
         *
         * @return MigratorSettings
         */
        protected function settings() {
            if ( ! isset( $this->settings ) ) {
                $this->settings = new MigratorSettings();
            }

            return $this->settings;
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
         * @return void
         */
        public function set_migrator_setting( $name, $value ) {
            $this->settings()->set_migrator_setting( $name, $value );
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
            $this->settings()->clear_migrator_setting();
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

                $result = wp_set_object_terms( $attachment_id, $tags, FOOGALLERY_ATTACHMENT_TAXONOMY_TAG, true );
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
            return $this->settings()->has_migrator_setting_items( self::KEY_MIGRATED );
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
