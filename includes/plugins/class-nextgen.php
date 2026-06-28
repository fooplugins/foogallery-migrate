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

                $gallery_table = $wpdb->prefix . self::NEXTGEN_TABLE_GALLERY;

                $gallery_table_like = method_exists( $wpdb, 'esc_like' ) ? $wpdb->esc_like( $gallery_table ) : $gallery_table;

                // Check if plugin's table ngg_gallery exists in database
                if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $gallery_table_like ) ) ) {
                   return false;
                }

                return (bool) $wpdb->get_var( "SELECT 1 FROM {$gallery_table} LIMIT 1" );
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
                    'ID' => $nextgen_image->pid,
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
         * Returns true when NextGEN image tags are present.
         *
         * @return bool
         */
        function has_migratable_image_tags() {
            global $wpdb;

            if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
                return false;
            }

            if ( empty( $wpdb->term_relationships ) || empty( $wpdb->term_taxonomy ) ) {
                return false;
            }

            $picture_table = $wpdb->prefix . self::NEXTGEN_TABLE_PICTURES;
            $sql = "
                SELECT 1
                FROM {$picture_table} p
                INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.pid
                INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                WHERE tt.taxonomy = %s
                LIMIT 1
            ";

            if ( method_exists( $wpdb, 'prepare' ) ) {
                $sql = $wpdb->prepare( $sql, 'ngg_tag' );
            } else {
                $sql = str_replace( '%s', "'ngg_tag'", $sql );
            }

            return (bool) $wpdb->get_var( $sql );
        }

        /**
         * Returns NextGEN image tag names for migration to FooGallery media tags.
         *
         * @param Image $image Source image object.
         * @return array Tag names.
         */
        function get_image_tags( $image ) {
            if ( ! is_object( $image ) ) {
                return array();
            }

            $image_id = 0;
            if ( isset( $image->data ) && is_object( $image->data ) && ! empty( $image->data->pid ) ) {
                $image_id = absint( $image->data->pid );
            } else if ( ! empty( $image->ID ) ) {
                $image_id = absint( $image->ID );
            }

            if ( $image_id < 1 ) {
                return array();
            }

            if ( ! function_exists( 'wp_get_object_terms' ) ) {
                return $this->get_image_tags_from_database( $image_id );
            }

            $terms = wp_get_object_terms(
                $image_id,
                'ngg_tag',
                array(
                    'fields' => 'names',
                )
            );

            if ( is_wp_error( $terms ) || ! is_array( $terms ) || empty( $terms ) ) {
                return $this->get_image_tags_from_database( $image_id );
            }

            $tags = array();
            foreach ( $terms as $term ) {
                if ( ! is_scalar( $term ) ) {
                    continue;
                }

                $term = trim( (string) $term );
                if ( '' !== $term && ! in_array( $term, $tags, true ) ) {
                    $tags[] = $term;
                }
            }

            return $tags;
        }

        /**
         * Returns NextGEN image tag names directly from persisted term rows.
         *
         * This keeps tag migration working when NextGEN is installed but inactive,
         * because the ngg_tag taxonomy is not registered in those requests.
         *
         * @param int $image_id NextGEN image ID.
         * @return array Tag names.
         */
        private function get_image_tags_from_database( $image_id ) {
            global $wpdb;

            $image_id = absint( $image_id );
            if (
                $image_id < 1 ||
                ! is_object( $wpdb ) ||
                ! method_exists( $wpdb, 'get_results' ) ||
                empty( $wpdb->terms ) ||
                empty( $wpdb->term_relationships ) ||
                empty( $wpdb->term_taxonomy )
            ) {
                return array();
            }

            $sql = "
                SELECT t.name
                FROM {$wpdb->terms} t
                INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
                INNER JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
                WHERE tr.object_id = %d
                AND tt.taxonomy = %s
                ORDER BY t.name
            ";

            if ( method_exists( $wpdb, 'prepare' ) ) {
                $sql = $wpdb->prepare( $sql, $image_id, 'ngg_tag' );
            } else {
                $sql = str_replace( array( '%d', '%s' ), array( (string) $image_id, "'ngg_tag'" ), $sql );
            }

            $rows = $wpdb->get_results( $sql );
            if ( ! is_array( $rows ) || empty( $rows ) ) {
                return array();
            }

            $tags = array();
            foreach ( $rows as $row ) {
                if ( ! is_object( $row ) || ! isset( $row->name ) || ! is_scalar( $row->name ) ) {
                    continue;
                }

                $tag = trim( (string) $row->name );
                if ( '' !== $tag && ! in_array( $tag, $tags, true ) ) {
                    $tags[] = $tag;
                }
            }

            return $tags;
        }

        /**
         * Finds tagged NextGEN images for post-migration FooGallery media tag sync.
         *
         * @return array Image tag sync items.
         */
        function find_image_tag_sync_items() {
            global $wpdb;

            if (
                ! is_object( $wpdb ) ||
                ! method_exists( $wpdb, 'get_results' ) ||
                empty( $wpdb->term_relationships ) ||
                empty( $wpdb->term_taxonomy )
            ) {
                return array();
            }

            $picture_table = $wpdb->prefix . self::NEXTGEN_TABLE_PICTURES;
            $gallery_table = $wpdb->prefix . self::NEXTGEN_TABLE_GALLERY;
            $sql = "
                SELECT DISTINCT p.pid, p.filename, p.galleryid, g.path
                FROM {$picture_table} p
                INNER JOIN {$gallery_table} g ON g.gid = p.galleryid
                INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.pid
                INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                WHERE tt.taxonomy = %s
                ORDER BY p.pid
            ";

            if ( method_exists( $wpdb, 'prepare' ) ) {
                $sql = $wpdb->prepare( $sql, 'ngg_tag' );
            } else {
                $sql = str_replace( '%s', "'ngg_tag'", $sql );
            }

            $rows = $wpdb->get_results( $sql );
            if ( ! is_array( $rows ) || empty( $rows ) ) {
                return array();
            }

            $items = array();
            foreach ( $rows as $row ) {
                if ( ! is_object( $row ) || empty( $row->pid ) || empty( $row->filename ) || empty( $row->path ) ) {
                    continue;
                }

                $source_url = trailingslashit( site_url() ) . trailingslashit( $row->path ) . $row->filename;
                $image = (object) array(
                    'ID'   => absint( $row->pid ),
                    'data' => (object) array(
                        'pid' => absint( $row->pid ),
                    ),
                );
                $tags = $this->get_image_tags( $image );
                if ( empty( $tags ) ) {
                    continue;
                }

                $items[] = array(
                    'plugin_name'     => $this->name(),
                    'source_image_id' => absint( $row->pid ),
                    'source_url'      => $source_url,
                    'tags'            => $tags,
                );
            }

            return $items;
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
                '/\[ngg\b(?=[^\]]*\b(?:source|src)\s*=\s*["\']?(?:tags|tag|image_tags|image_tag)["\']?)(?=[^\]]*\b(?:container_ids|ids|tag_ids)\s*=)[^\]]*\]/i',
                '/\[ngg\b(?=[^\]]*\btag_ids\s*=)[^\]]*\]/i',
                '/\[nggtags\b[^\]]*\b(?:gallery|album)\s*=[^\]]+\]/i',
                '/\[(?:tags|albumtags)\s*=[^\]]+\]/i',
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

            if ( $this->get_media_tag_slugs_from_shortcode( $original_content ) ) {
                return 'dynamic_gallery';
            }

            return 'gallery';
        }

        /**
         * Build direct replacement content for NextGEN dynamic tag shortcodes.
         *
         * @param string $original_content Original shortcode or serialized block content.
         * @param string $block_name Block name, if the detected content is a block.
         * @return string|false Replacement content, or false to use migrated-object replacement.
         */
        function get_content_replacement_content( $original_content, $block_name = '' ) {
            $media_tag_slugs = $this->get_media_tag_slugs_from_shortcode( $original_content );
            if ( empty( $media_tag_slugs ) ) {
                return false;
            }

            return '[foogallery media_tags="' . esc_attr( implode( ',', $media_tag_slugs ) ) . '"]';
        }

        /**
         * Extract FooGallery media tag slugs from a NextGEN tag-source shortcode.
         *
         * @param string $shortcode Shortcode text.
         * @return array Tag slugs.
         */
        private function get_media_tag_slugs_from_shortcode( $shortcode ) {
            if ( ! is_string( $shortcode ) || '' === trim( $shortcode ) ) {
                return array();
            }

            if ( preg_match( '/^\[(tags|albumtags)\s*=\s*([^\]]+)\]/i', $shortcode, $legacy_match ) ) {
                return $this->normalize_media_tag_slugs( $legacy_match[2] );
            }

            $shortcode_name = $this->get_shortcode_name( $shortcode );
            if ( '' === $shortcode_name ) {
                return array();
            }

            $attributes = $this->parse_shortcode_attributes( $shortcode );
            if ( empty( $attributes ) ) {
                return array();
            }

            if ( 'nggtags' === $shortcode_name ) {
                $tag_value = $this->get_first_shortcode_attribute( $attributes, array( 'gallery', 'album' ) );
                return $this->normalize_media_tag_slugs( $tag_value );
            }

            if ( 'ngg' !== $shortcode_name ) {
                return array();
            }

            if ( $this->is_truthy_shortcode_attribute( $attributes, 'tagcloud' ) ) {
                return array();
            }

            $source = $this->get_first_shortcode_attribute( $attributes, array( 'source', 'src' ) );
            $source = is_scalar( $source ) ? sanitize_key( str_replace( '-', '_', (string) $source ) ) : '';
            $is_tag_source = in_array( $source, array( 'tags', 'tag', 'image_tags', 'image_tag' ), true );

            if ( $is_tag_source ) {
                $tag_value = $this->get_first_shortcode_attribute( $attributes, array( 'container_ids', 'ids', 'tag_ids' ) );
            } else {
                $tag_value = $this->get_first_shortcode_attribute( $attributes, array( 'tag_ids' ) );
            }

            return $this->normalize_media_tag_slugs( $tag_value );
        }

        /**
         * Get the shortcode name.
         *
         * @param string $shortcode Shortcode text.
         * @return string Shortcode name.
         */
        private function get_shortcode_name( $shortcode ) {
            if ( ! is_string( $shortcode ) || ! preg_match( '/^\[([a-z0-9_-]+)/i', trim( $shortcode ), $matches ) ) {
                return '';
            }

            return strtolower( $matches[1] );
        }

        /**
         * Parse shortcode attributes from a matched shortcode string.
         *
         * @param string $shortcode Shortcode text.
         * @return array Parsed attributes.
         */
        private function parse_shortcode_attributes( $shortcode ) {
            if ( ! is_string( $shortcode ) || ! preg_match( '/^\[[^\s\]]+\s*([^\]]*)\]/', $shortcode, $matches ) ) {
                return array();
            }

            $attribute_text = trim( $matches[1] );
            if ( '' === $attribute_text ) {
                return array();
            }

            if ( function_exists( 'shortcode_parse_atts' ) ) {
                $attributes = shortcode_parse_atts( $attribute_text );
                return is_array( $attributes ) ? array_change_key_case( $attributes, CASE_LOWER ) : array();
            }

            $attributes = array();
            if ( preg_match_all( '/([\w-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s\]]+))/', $attribute_text, $attribute_matches, PREG_SET_ORDER ) ) {
                foreach ( $attribute_matches as $attribute_match ) {
                    $value = '';
                    if ( isset( $attribute_match[2] ) && '' !== $attribute_match[2] ) {
                        $value = $attribute_match[2];
                    } else if ( isset( $attribute_match[3] ) && '' !== $attribute_match[3] ) {
                        $value = $attribute_match[3];
                    } else if ( isset( $attribute_match[4] ) ) {
                        $value = $attribute_match[4];
                    }
                    $attributes[ strtolower( $attribute_match[1] ) ] = $value;
                }
            }

            return $attributes;
        }

        /**
         * Get the first non-empty shortcode attribute from a list of keys.
         *
         * @param array $attributes Parsed attributes.
         * @param array $keys Attribute keys.
         * @return string|false Attribute value, or false.
         */
        private function get_first_shortcode_attribute( $attributes, $keys ) {
            foreach ( $keys as $key ) {
                if ( isset( $attributes[ $key ] ) && is_scalar( $attributes[ $key ] ) && '' !== trim( (string) $attributes[ $key ] ) ) {
                    return (string) $attributes[ $key ];
                }
            }

            return false;
        }

        /**
         * Returns true when a shortcode attribute is explicitly enabled.
         *
         * @param array $attributes Parsed attributes.
         * @param string $key Attribute key.
         * @return bool
         */
        private function is_truthy_shortcode_attribute( $attributes, $key ) {
            if ( ! isset( $attributes[ $key ] ) ) {
                return false;
            }

            $value = is_scalar( $attributes[ $key ] ) ? strtolower( trim( (string) $attributes[ $key ] ) ) : '';
            return in_array( $value, array( '1', 'true', 'yes', 'on' ), true );
        }

        /**
         * Normalize a NextGEN tag list into FooGallery media tag slugs.
         *
         * @param string|false $tag_value Comma- or pipe-delimited tag list.
         * @return array Tag slugs.
         */
        private function normalize_media_tag_slugs( $tag_value ) {
            if ( ! is_scalar( $tag_value ) ) {
                return array();
            }

            $tags = preg_split( '/,|\|/', (string) $tag_value );
            if ( ! is_array( $tags ) ) {
                return array();
            }

            $slugs = array();
            foreach ( $tags as $tag ) {
                $tag = trim( html_entity_decode( (string) $tag, ENT_QUOTES, 'UTF-8' ) );
                $tag = trim( rawurldecode( $tag ) );
                $tag = trim( $tag, " \t\n\r\0\x0B\"'" );
                if ( '' === $tag || 'all' === strtolower( $tag ) ) {
                    continue;
                }

                if ( function_exists( 'sanitize_title' ) ) {
                    $slug = sanitize_title( $tag );
                } else {
                    $slug = strtolower( $tag );
                    $slug = preg_replace( '/[^a-z0-9_-]+/', '-', $slug );
                    $slug = trim( $slug, '-' );
                }

                if ( is_string( $slug ) && '' !== $slug && ! in_array( $slug, $slugs, true ) ) {
                    $slugs[] = $slug;
                }
            }

            return $slugs;
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
