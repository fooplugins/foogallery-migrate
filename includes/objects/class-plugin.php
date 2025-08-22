<?php
/**
 * FooGallery Migrator Plugin Base Class
 *
 * @package FooPlugins\FooGalleryMigrate
 */

namespace FooPlugins\FooGalleryMigrate\Objects;
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
                $image = new Image();
                $image->source_url = $data['source_url'];
                if ( array_key_exists( 'slug', $data ) ) {
                    $image->slug = $data['slug'];
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
    }
}