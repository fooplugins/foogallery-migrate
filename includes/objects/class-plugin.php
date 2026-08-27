<?php
/**
 * FooGallery Migrator Plugin Base Class
 *
 * @package FooPlugins\FooGalleryMigrate
 */

namespace FooPlugins\FooGalleryMigrate\Objects;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
use FooPlugins\FooGalleryMigrate\Objects\Gallery;
use FooPlugins\FooGalleryMigrate\Objects\Image;
use FooPlugins\FooGalleryMigrate\Objects\Album;

if ( ! class_exists( 'FooPlugins\FooGalleryMigrate\Objects\Plugin' ) ) {

    /**
     * Class Plugin
     *
     * @package FooPlugins\FooGalleryMigrate
     */
    abstract class Plugin {

        public $is_detected = false;

        /**
         * The name of the Plugin.
         * @return string
         */
        abstract function name();

        /**
         * Detects data from the gallery plugin.
         * @return bool
         */
        abstract function detect();

        /**
         * Returns all galleries for the plugin.
         *
         * @return array<Gallery>
         */
        abstract function find_galleries();

        /**
         * Returns the closest possible gallery template
         *
         * @param $gallery Gallery
         * @return string
         */
        abstract function get_gallery_template( $gallery );

        /**
         * Returns the gallery template that should be used during migration.
         *
         * @param $gallery Gallery
         * @return string
         */
        function get_migration_gallery_template( $gallery ) {
            $override_gallery_template = foogallery_migrate_migrator_instance()->get_override_gallery_template();

            if ( ! empty( $override_gallery_template ) ) {
                return $override_gallery_template;
            }

            return $this->get_gallery_template( $gallery );
        }

        /**
         * Returns the closest possible gallery settings
         *
         * @param $gallery Gallery
         * @param $default_settings array
         * @return array
         */
        abstract function get_gallery_settings( $gallery, $default_settings );

        abstract function find_albums();

        function find_objects( $type ) {
            if ( 'albums' === $type ) {
                return $this->find_albums();
            }
            return $this->find_galleries();
        }

        /**
         * Returns the gallery object
         * @param $data array
         * @return $gallery
         */
        function get_gallery( $data = array() ) {
            $gallery = new Gallery( $this );
            $gallery->ID = $data['ID'];

            $migrated_object = foogallery_migrate_migrator_instance()->has_object_been_migrated( $gallery->unique_identifier() );
            if ( $migrated_object ) {
                $gallery = foogallery_migrate_migrator_instance()->get_migrated_objects()[$gallery->unique_identifier()];
            } else {
                $gallery->title = $data['title'];
                $gallery->foogallery_title = $data['title'];
                $gallery->data = $data['data'];
                $gallery->children = $data['children'];
                if ( array_key_exists( 'children_count', $data ) ) {
                    $gallery->children_count = absint( $data['children_count'] );
                } else {
                    $gallery->children_count = is_array( $data['children'] ) ? count( $data['children'] ) : 0;
                }
                $gallery->settings = $data['settings'];
            }   

            return $gallery;         
        }

        /**
         * Returns the album object
         *
         * @param $data array
         * @return $album
         */
        function get_album( $data = array() ) {
            $album = new Album( $this );
            $album->ID = $data['ID'];

            $migrated_object = foogallery_migrate_migrator_instance()->has_object_been_migrated( $album->unique_identifier() );
            if ( $migrated_object ) {
                $album = foogallery_migrate_migrator_instance()->get_migrated_objects()[$album->unique_identifier()];
            } else {
                $album->title = $data['title'];
                $album->data = $data['data'];
                $album->fooalbum_title = $data['fooalbum_title'];
            }

            return $album;         
        }        

        /**
         * Returns the image object
         * @param $data array
         * @return $image
         */
        function get_image( $data = array() ) {

            $migrated_object = foogallery_migrate_migrator_instance()->has_object_been_migrated( $data['source_url'] );
            if ( $migrated_object ) {
                $image = foogallery_migrate_migrator_instance()->get_migrated_objects()[$data['source_url']];
            } else {
                $image = new Image( $this );
                if ( array_key_exists( 'ID', $data ) ) {
                    $image->ID = absint( $data['ID'] );
                } else if ( isset( $data['data'] ) && is_object( $data['data'] ) && isset( $data['data']->pid ) ) {
                    $image->ID = absint( $data['data']->pid );
                } else if ( isset( $data['data'] ) && is_object( $data['data'] ) && isset( $data['data']->id ) ) {
                    $image->ID = absint( $data['data']->id );
                }
                $image->source_url = $data['source_url'];
                if ( array_key_exists( 'slug', $data ) ) {
                    $image->slug = $data['slug'];
                }
                if ( array_key_exists( 'title', $data ) ) {
                    $image->title = $data['title'];
                }
                if ( array_key_exists( 'caption', $data ) ) {
                    $image->caption = $data['caption'];
                }
                if ( array_key_exists( 'description', $data ) ) {
                    $image->description = $data['description'];
                }
                $image->alt = $data['alt'];
                $image->date = $data['date'];
                $image->data = $data['data'];
            }

            return $image;
        }

        /**
         * Returns shortcode regex patterns for this plugin.
         * Should return an array of regex patterns that match shortcodes.
         * Example: array( '/\[envira\s+id=["\']?(\d+)["\']?/i' )
         *
         * @return array Array of regex patterns
         */
        function get_shortcode_patterns() {
            return array();
        }

        /**
         * Returns Gutenberg block patterns for this plugin.
         * Should return an associative array of block names.
         * Example: array( 'envira/gallery' => array() )
         *
         * @return array Associative array of block names
         */
        function get_block_patterns() {
            return array();
        }

        /**
         * Returns the migrated object type for a detected shortcode/block.
         *
         * Supported return values are 'gallery', 'album', and 'image'.
         *
         * @param string $original_content Original shortcode or serialized block content.
         * @param string $block_name Block name, if the detected content is a block.
         * @return string
         */
        function get_content_object_type( $original_content, $block_name = '' ) {
            return 'gallery';
        }

        /**
         * Returns the migrated image identifier for a detected image shortcode.
         *
         * Image migrations are tracked by their original source URL. Plugins that
         * expose single-image shortcodes can resolve their source image ID to that
         * identifier so content migration can replace it with attachment markup.
         *
         * @param int $image_id Source plugin image ID.
         * @return string|false
         */
        function get_content_image_identifier( $image_id ) {
            return false;
        }

        /**
         * Returns replacement content for source content that does not need a migrated FooGallery object.
         *
         * @param string $original_content Original shortcode or serialized block content.
         * @param string $block_name Block name, if the detected content is a block.
         * @return string|false Replacement content, or false to use the normal migrated-object replacement.
         */
        function get_content_replacement_content( $original_content, $block_name = '' ) {
            return false;
        }

        /**
         * Returns true when the source plugin has image tags that FooGallery can migrate.
         *
         * @return bool
         */
        function has_migratable_image_tags() {
            return false;
        }

        /**
         * Returns source image tag names that can be assigned to FooGallery media tags.
         *
         * @param Image $image Source image object.
         * @return array Tag names.
         */
        function get_image_tags( $image ) {
            return array();
        }

        /**
         * Finds source images with tags for post-migration attachment tag sync.
         *
         * @return array Image tag sync items.
         */
        function find_image_tag_sync_items() {
            return array();
        }
    }
}
