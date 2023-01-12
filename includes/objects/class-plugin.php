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

        /**
         * Migrates any settings for the gallery.
         *
         * @param $gallery Gallery
         * @return void
         */
        //abstract function migrate_settings( $gallery );
//
       abstract function find_albums();
//
//        abstract function get_content();

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

            $migrated_object = foogallery_migrate_migrator_instance()->has_object_been_migrated( $data['unique_identifier'] );
            if($migrated_object) {                           
                $gallery = foogallery_migrate_migrator_instance()->get_migrated_objects()[$data['unique_identifier']];
            } else {
  
                $gallery = new Gallery( $this );
                $gallery->ID = $data['id'];
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

            $migrated_object = foogallery_migrate_migrator_instance()->has_object_been_migrated( $data['unique_identifier'] );
            if($migrated_object) {                           
                $album = foogallery_migrate_migrator_instance()->get_migrated_objects()[$data['unique_identifier']];
            } else {
  
                $album = new Album( $this );
                $album->ID = $data['ID'];
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

            $image = new Image();
            $image->source_url = $data['source_url'];
            $image->caption = $data['caption'];
            $image->alt = $data['alt'];
            $image->date = $data['date'];
            $image->data = $data['data'];
            return $image; 

        }
    }
}