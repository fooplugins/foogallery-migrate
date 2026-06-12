<?php
/**
 * FooGallery Migrator Nextgen Plugin Class
 *
 * @package FooPlugins\FooGalleryMigrate
 */

namespace FooPlugins\FooGalleryMigrate\Plugins;

use FooPlugins\FooGalleryMigrate\Objects\Gallery;
use FooPlugins\FooGalleryMigrate\Objects\Image;
use FooPlugins\FooGalleryMigrate\Objects\Album;
use FooPlugins\FooGalleryMigrate\Objects\Plugin;

if ( ! class_exists( 'FooPlugins\FooGalleryMigrate\Plugins\Nextgen' ) ) {

    /**
     * Class Nextgen
     *
     * @package FooPlugins\FooGalleryMigrate
     */
    class Nextgen extends Plugin {

        const NEXTGEN_TABLE_GALLERY  = 'ngg_gallery';
        const NEXTGEN_TABLE_PICTURES = 'ngg_pictures';
        const NEXTGEN_TABLE_ALBUMS   = 'ngg_album';

        /**
         * Name of the plugin.
         *
         * @return string
         */
        function name() {
            return 'NextGen';
        }

        /**
         * Detects data from the gallery plugin.
         *
         * @return bool
         */
        function detect() {
            if (defined('NGG_PLUGIN_VERSION')) {
                // NextGen plugin is activated. Get out early!
                return true;
            } else {
                // Do some checks even if the plugin is not activated.
                global $wpdb;

                // Check if plugin's table ngg_gallery exists in database
                if ( !$wpdb->get_var( 'SHOW TABLES LIKE"%' . $wpdb->prefix . 'ngg_gallery%"' ) ) {
                   return false;
                }
                $galleries = $wpdb->get_results('SELECT * FROM ' . $wpdb->prefix . 'ngg_gallery');

                return count($galleries) > 0;
            }
        }

        /**
         * Find all galleries
         *
         * @return array
         */
        function find_galleries() {
            $nextgen_galleries = $this->get_nextgen_galleries();
            $galleries = array();

            if ( count( $nextgen_galleries ) != 0 ) {
                foreach ( $nextgen_galleries as $key => $nextgen_gallery ) {

					$data = array(
						'ID' => $nextgen_gallery->gid,
						'title' => $nextgen_gallery->title,
						'foogallery_title' => $nextgen_gallery->title,
						'data' => $nextgen_gallery,
						'children' => $this->find_images( $nextgen_gallery->gid, $nextgen_gallery->path ),
						'settings' => ''
					);
					
					$gallery = $this->get_gallery( $data );
                        
                    $galleries[] = $gallery;
                }
            }

            return $galleries;
        }

        /**
         * Find all images by gallery id
         * @param $gallery_id ID of the gallery
         * @param $gallery_path Image gallery path
         * @return array
         */
        private function find_images( $gallery_id, $gallery_path ) {
            $nextgen_images = $this->get_nextgen_gallery_images( $gallery_id );

            $images = array();
            foreach ( $nextgen_images as $nextgen_image ) {
                $source_url = trailingslashit( site_url() ) . trailingslashit( $gallery_path ) . $nextgen_image->filename;

                // Use alttext for both title and alt, but fallback to empty string if not set
                $alt_text = !empty($nextgen_image->alttext) ? $nextgen_image->alttext : '';

				// remove meta_data blob
				if ( isset( $nextgen_image->meta_data ) ) {
					unset( $nextgen_image->meta_data );
				}
                
                $data = array(
                    'source_url' => $source_url,
                    'slug' => $nextgen_image->filename,
                    'title' => $alt_text,
                    'caption' => $nextgen_image->description,
                    'alt' => $alt_text,
                    'description' => $nextgen_image->description,
                    'date' => $nextgen_image->imagedate,
                    'data' => $nextgen_image
                );

                $image = $this->get_image( $data );

                if ( 0 == $nextgen_image->exclude ) {
                    $images[] = $image;
                }
            }
            return $images;
        }

        /**
         * Return all galleries object data.
         *
         * @return object Object of all galleries
         */
        private function get_nextgen_galleries() {
            global $wpdb;
            $gallery_table = $wpdb->prefix . self::NEXTGEN_TABLE_GALLERY;  
            return $wpdb->get_results( "select * from {$gallery_table}" );
        }

        /**
         * Return single gallery object data.
         *
         * @param int $id ID of the gallery.
         * @return object|null Object of the gallery.
         */
        private function get_nextgen_gallery( $id ) {
            global $wpdb;
            $gallery_table = $wpdb->prefix . self::NEXTGEN_TABLE_GALLERY;

            return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$gallery_table} WHERE gid = %d", $id ) );
        }

        /**
         * Return single image object data.
         *
         * @param int $id ID of the image.
         * @return object|null Object of the image.
         */
        private function get_nextgen_image( $id ) {
            global $wpdb;
            $picture_table = $wpdb->prefix . self::NEXTGEN_TABLE_PICTURES;

            return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$picture_table} WHERE pid = %d", $id ) );
        }

        /**
         * Return single gallery object data.
         * @param $id ID of the gallery
         * @return object Object of the gallery
         */
        private function get_nextgen_gallery_images( $id ) {
            global $wpdb;
            $picture_table = $wpdb->prefix . self::NEXTGEN_TABLE_PICTURES;

            return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$picture_table} WHERE galleryid = %d order by sortorder", $id ) );
        }

        /**
         * Returns the gallery template.
         *
         * @param $gallery
         * @return string
         */
        function get_gallery_template( $gallery ) {
            return 'default';
        }

        /**
         * Gets the settings for the gallery.
         *
         * @param $gallery
         * @param $settings
         * @return array
         */
        function get_gallery_settings( $gallery, $settings ) {
            $gallery_template = $this->get_migration_gallery_template( $gallery );
            $settings[$gallery_template . '_caption_title_source'] = 'title';
            return $settings;
        }

        /**
         * Return all albums object data.
         *
         * @return object Object of all albums
         */
        private function get_nextgen_albums() {
            global $wpdb;
            $album_table = $wpdb->prefix . self::NEXTGEN_TABLE_ALBUMS;
            return $wpdb->get_results(" select * from {$album_table}");
        }


       /**
         * Return all galleris by album
         *
         * @return object Object of galleries by album 
         */
        private function get_galleries_by_album( $album_id ) {
            global $wpdb;
            $album_table = $wpdb->prefix . self::NEXTGEN_TABLE_ALBUMS;
            $gallery_table = $wpdb->prefix . self::NEXTGEN_TABLE_GALLERY;
            $get_galleries_data = $wpdb->get_row("SELECT sortorder FROM $album_table WHERE id = $album_id");
            if ( $get_galleries_data->sortorder !== '' ) {
                $galleries_id = base64_decode($get_galleries_data->sortorder);
                $galleries_id = str_replace("[", "", $galleries_id);
                $galleries_id = str_replace("]", "", $galleries_id);
                $galleries_id = str_replace('"', '', $galleries_id);

                if ( $galleries_id !== '' ) {
                    $get_galleries_data = $wpdb->get_results("SELECT * FROM {$gallery_table} WHERE gid IN ($galleries_id)");                    
                }
            } 

            return $get_galleries_data;
        }

        function find_albums() {
            $nextgen_albums = $this->get_nextgen_albums();
            $albums = array();

            if ( count( $nextgen_albums ) !== 0 ) {
                foreach ( $nextgen_albums as $key => $nextgen_album ) {

                    $data = array(
                        'ID' => $nextgen_album->id,
                        'title' => $nextgen_album->name,
                        'data' => $nextgen_album,
                        'fooalbum_title' => $nextgen_album->name,
                    );                    

                    $album = $this->get_album( $data );

                    $galleries = array();
                    $album_galleries = $this->get_galleries_by_album( $nextgen_album->id );

                    foreach( $album_galleries as $album_gallery ) {

                        $data = array(
                            'ID' => $album_gallery->gid,
                            'title' => $album_gallery->title,
                            'foogallery_title' => $album_gallery->title,
                            'data' => $album_gallery,
                            'children' => $this->find_images( $album_gallery->gid, $album_gallery->path ),
                            'settings' => ''
                        );

                        $gallery = $this->get_gallery( $data );

                        $galleries[] = $gallery;
                    }

                    $album->children = $galleries;

                    $albums[] = $album;
                }
            }

            return $albums;            
        }        
       /**
         * Returns shortcode regex patterns for NextGen.
         *
         * @return array Array of regex patterns
         */
        function get_shortcode_patterns() {
            return array(
                '/\[nggallery\b[^\]]*\bid\s*=\s*["\']?(\d+)["\']?[^\]]*\]/i',
                '/\[ngg\b[^\]]*\bids\s*=\s*["\']?(\d+)(?:\s*,\s*\d+)*["\']?[^\]]*\]/i',
                '/\[ngg\b[^\]]*\bid\s*=\s*["\']?(\d+)["\']?[^\]]*\]/i',
                '/\[ngg_images\b[^\]]*\bgallery_ids\s*=\s*["\']?(\d+)(?:\s*,\s*\d+)*["\']?[^\]]*\]/i',
                '/\[imagely\b[^\]]*\bid\s*=\s*["\']?(\d+)["\']?[^\]]*\]/i',
                '/\[singlepic\b[^\]]*\bid\s*=\s*["\']?(\d+)["\']?[^\]]*\]/i',
            );
        }

        /**
         * Returns the migrated object type for a detected NextGEN shortcode/block.
         *
         * @param string $original_content Original shortcode or serialized block content.
         * @param string $block_name Block name, if the detected content is a block.
         * @return string
         */
        function get_content_object_type( $original_content, $block_name = '' ) {
            if ( is_string( $original_content ) && preg_match( '/\[singlepic\b/i', $original_content ) ) {
                return 'image';
            }

            return 'gallery';
        }

        /**
         * Resolve a NextGEN single image ID to the original source URL.
         *
         * @param int $image_id NextGEN picture ID.
         * @return string|false
         */
        function get_content_image_identifier( $image_id ) {
            $nextgen_image = $this->get_nextgen_image( $image_id );
            if ( ! is_object( $nextgen_image ) || empty( $nextgen_image->filename ) ) {
                return false;
            }

            $gallery_path = '';
            if ( ! empty( $nextgen_image->galleryid ) ) {
                $gallery = $this->get_nextgen_gallery( $nextgen_image->galleryid );
                if ( is_object( $gallery ) && ! empty( $gallery->path ) ) {
                    $gallery_path = $gallery->path;
                }
            }

            if ( '' === $gallery_path && ! empty( $nextgen_image->path ) ) {
                $gallery_path = $nextgen_image->path;
            }

            if ( '' === $gallery_path ) {
                return false;
            }

            return trailingslashit( site_url() ) . trailingslashit( $gallery_path ) . $nextgen_image->filename;
        }

        /**
         * Returns Gutenberg block patterns for NextGen.
         *
         * @return array Associative array of block names
         */
        function get_block_patterns() {
            return array(
                'nextgen-gallery/gallery' => array(),
                'imagely/nextgen-gallery' => array(),
                'imagely/main-block' => array(),
            );
        }              
    }
}
