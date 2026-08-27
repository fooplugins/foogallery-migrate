<?php
/**
 * FooGallery Migrator WP Photo Album Plus Plugin Class
 *
 * @package FooPlugins\FooGalleryMigrate
 */

namespace FooPlugins\FooGalleryMigrate\Plugins;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use FooPlugins\FooGalleryMigrate\Objects\Plugin;

if ( ! class_exists( 'FooPlugins\FooGalleryMigrate\Plugins\WpPhotoAlbumPlus' ) ) {

    /**
     * Migrate public local images from WP Photo Album Plus.
     */
    class WpPhotoAlbumPlus extends Plugin {

        /** @var array */
        private $album_rows = null;

        /** @var array */
        private $photo_rows = null;

        /** @var array */
        private $valid_photos_by_album = null;

        /** @var array */
        private $galleries_by_id = null;

        /**
         * Keep request-only source data out of persisted migration state.
         *
         * @return array
         */
        public function __sleep() {
            return array( 'is_detected' );
        }

        /**
         * Reset request-only caches after legacy serialized state is loaded.
         *
         * @return void
         */
        public function __wakeup() {
            $this->album_rows = null;
            $this->photo_rows = null;
            $this->valid_photos_by_album = null;
            $this->galleries_by_id = null;
        }

        /**
         * Source name used in persisted migration identifiers.
         *
         * @return string
         */
        function name() {
            return 'WP Photo Album Plus';
        }

        /**
         * Detect both exact WPPA tables for the current source context.
         *
         * Active WPPA constants are authoritative and may intentionally point at
         * global multisite tables. When WPPA is inactive, only the current site's
         * prefix is considered.
         *
         * @return bool
         */
        function detect() {
            $tables = $this->get_table_names();

            return $this->table_exists( $tables['albums'] ) && $this->table_exists( $tables['photos'] );
        }

        /**
         * Find every WPPA album that has at least one migratable image.
         *
         * @return array
         */
        function find_galleries() {
            $this->prime_source_data();

            return array_values( $this->galleries_by_id );
        }

        /**
         * Flatten WPPA subalbum trees into FooGallery albums containing galleries.
         *
         * FooGallery albums cannot contain nested albums. A WPPA album with child
         * albums therefore becomes one FooGallery album containing its own gallery,
         * when non-empty, followed by every non-empty descendant gallery.
         *
         * @return array
         */
        function find_albums() {
            $this->prime_source_data();

            $albums = array();
            $children_by_parent = $this->get_album_children();

            foreach ( $this->album_rows as $album_id => $row ) {
                if ( empty( $children_by_parent[ $album_id ] ) || ! $this->is_public_album( $row ) ) {
                    continue;
                }

                $gallery_ids = array();
                $seen = array();
                $this->append_descendant_gallery_ids( $album_id, $children_by_parent, $gallery_ids, $seen );

                if ( empty( $gallery_ids ) ) {
                    continue;
                }

                $title = isset( $row->name ) ? $row->name : '';
                $album = $this->get_album(
                    array(
                        'ID'             => $album_id,
                        'title'          => $title,
                        'data'           => null,
                        'fooalbum_title' => $title,
                    )
                );
                $album->children = array();

                foreach ( $gallery_ids as $gallery_id ) {
                    $album->children[] = $this->galleries_by_id[ $gallery_id ];
                }

                $album->children_count = count( $album->children );
                $albums[] = $album;
            }

            return $albums;
        }

        /**
         * Return the default FooGallery template.
         *
         * @param object $gallery Gallery object.
         * @return string
         */
        function get_gallery_template( $gallery ) {
            return 'default';
        }

        /**
         * Preserve standard title and description caption sources.
         *
         * @param object $gallery Gallery object.
         * @param array  $settings Default settings.
         * @return array
         */
        function get_gallery_settings( $gallery, $settings ) {
            $template = $this->get_migration_gallery_template( $gallery );
            $settings = is_array( $settings ) ? $settings : array();

            if ( ! array_key_exists( $template . '_caption_title_source', $settings ) ) {
                $settings[ $template . '_caption_title_source' ] = 'title';
            }
            if ( ! array_key_exists( $template . '_caption_desc_source', $settings ) ) {
                $settings[ $template . '_caption_desc_source' ] = 'caption';
            }
            if ( ! array_key_exists( $template . '_lightbox', $settings ) ) {
                $settings[ $template . '_lightbox' ] = 'foobox';
            }

            return $settings;
        }

        /**
         * Load a gallery's validated images in deterministic WPPA source order.
         *
         * @param object $object Gallery object.
         * @return array
         */
        public function load_object_children( $object ) {
            if ( ! is_object( $object ) || 'gallery' !== $object->type() ) {
                return array();
            }

            $this->prime_source_data();
            $album_id = absint( $object->ID );
            if ( ! isset( $this->valid_photos_by_album[ $album_id ] ) ) {
                return array();
            }

            $rows = $this->valid_photos_by_album[ $album_id ];
            $order = $this->get_photo_order( $album_id );
            $this->sort_photo_rows( $rows, $order );

            $images = array();
            foreach ( $rows as $row ) {
                $source = $this->get_local_photo_source( $row );
                if ( false === $source ) {
                    continue;
                }

                $description = isset( $row->description ) ? $row->description : '';
                $name = isset( $row->name ) ? $row->name : '';
                $images[] = $this->get_image(
                    array(
                        'ID'          => (int) $row->id,
                        'source_url'  => $source['url'],
                        'slug'        => $this->image_slug( $row ),
                        'title'       => $name,
                        'caption'     => $description,
                        'description' => $description,
                        'alt'         => isset( $row->alt ) ? $row->alt : '',
                        'date'        => $this->get_valid_photo_date( $row ),
                        'data'        => null,
                    )
                );
            }

            return $images;
        }

        /**
         * Match only a single, explicit positive numeric album value.
         *
         * Dynamic selectors, names, encrypted IDs and combined expressions are
         * deliberately left untouched.
         *
         * @return array
         */
        function get_shortcode_patterns() {
            return array(
                '/\[wppa(?=[\s\]])[^\]]*\]/i',
            );
        }

        /**
         * Resolve a shortcode regex match using WPPA's exact album attribute.
         *
         * @param array $match Regex match.
         * @return int|false
         */
        function get_content_match_identifier( $match ) {
            if ( ! is_array( $match ) || ! isset( $match[0] ) ) {
                return false;
            }
            $shortcode = is_array( $match[0] ) && isset( $match[0][0] ) ? $match[0][0] : $match[0];
            if ( ! is_string( $shortcode ) ) {
                return false;
            }

            return $this->get_exact_shortcode_album_id( $shortcode );
        }

        /**
         * WPPA block names whose saved content contains a WPPA shortcode.
         *
         * @return array
         */
        function get_block_patterns() {
            return array(
                'wppa/gutenberg-wppa'              => array(),
                'wp-photo-album-plus/general'      => array(),
                'wp-photo-album-plus/slideshow'    => array(),
            );
        }

        /**
         * Exact numeric album references map to their corresponding gallery.
         *
         * @param string $original_content Original source content.
         * @param string $block_name Block name.
         * @return string
         */
        function get_content_object_type( $original_content, $block_name = '' ) {
            return 'gallery';
        }

        /**
         * Resolve a WPPA block only from an exact shortcode stored by that block.
         *
         * This deliberately bypasses the content migrator's generic ID keys so an
         * unrelated block attribute cannot turn a dynamic WPPA selector into a
         * numeric gallery replacement.
         *
         * @param array $block Parsed WordPress block.
         * @return int|false
         */
        function get_content_block_identifier( $block ) {
            $contents = array();

            if ( isset( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
                foreach ( array( 'wppaShortcode', 'shortcode' ) as $key ) {
                    if ( isset( $block['attrs'][ $key ] ) && is_string( $block['attrs'][ $key ] ) ) {
                        $contents[] = $block['attrs'][ $key ];
                    }
                }
            }
            if ( isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
                foreach ( $block['innerContent'] as $content ) {
                    if ( is_string( $content ) ) {
                        $contents[] = $content;
                    }
                }
            }
            if ( isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
                $contents[] = $block['innerHTML'];
            }

            $ids = array();
			$matched_shortcodes = 0;
            foreach ( $contents as $content ) {
                foreach ( $this->get_shortcode_patterns() as $pattern ) {
                    $matches = array();
                    if ( ! preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER ) ) {
                        continue;
                    }
                    foreach ( $matches as $match ) {
						++$matched_shortcodes;
                        $id = $this->get_content_match_identifier( $match );
						if ( false === $id ) {
							return false;
						}
						$ids[ $id ] = true;
                    }
                }
            }

            if ( 0 === $matched_shortcodes || 1 !== count( $ids ) ) {
                return false;
            }

            $ids = array_keys( $ids );
            return (int) reset( $ids );
        }

		/**
		 * Use exact raw occurrences so repeated blocks retain independent offsets.
		 *
		 * @return bool
		 */
		function uses_exclusive_content_occurrences() {
			return true;
		}

		/**
		 * Find exact WPPA blocks and standalone shortcodes with byte offsets.
		 *
		 * @param object $post WordPress post.
		 * @return array
		 */
		function find_content_occurrences( $post ) {
			$content = isset( $post->post_content ) ? (string) $post->post_content : '';
			$occurrences = array();
			$block_ranges = array();

			foreach ( array_keys( $this->get_block_patterns() ) as $block_name ) {
				$name = preg_quote( $block_name, '/' );
				$pattern = '/<!--\s+wp:' . $name . '\b(?:(?!-->).)*-->(?:(?!<!--\s+\/wp:' . $name . '\s*-->).)*<!--\s+\/wp:' . $name . '\s*-->/s';
				$matches = array();
				if ( ! preg_match_all( $pattern, $content, $matches, PREG_OFFSET_CAPTURE ) ) {
					continue;
				}
				foreach ( $matches[0] as $match ) {
					$raw = $match[0];
					$offset = (int) $match[1];
					$block_ranges[] = array( $offset, $offset + strlen( $raw ) );
					$block = false;
					if ( function_exists( 'parse_blocks' ) ) {
					    $parsed = parse_blocks( $raw );
					    if ( is_array( $parsed ) && ! empty( $parsed[0] ) ) {
					        $block = $parsed[0];
					    }
					}
					if ( false === $block ) {
					    $open = array();
					    $open_pattern = '/^<!--\s+wp:' . $name . '(?:\s+(.+?))?\s*-->/s';
					    if ( ! preg_match( $open_pattern, $raw, $open ) ) {
					        continue;
					    }
					    $attrs = isset( $open[1] ) ? json_decode( trim( $open[1] ), true ) : array();
					    $close_position = strrpos( $raw, '<!--' );
					    $block = array(
					        'attrs'        => is_array( $attrs ) ? $attrs : array(),
					        'innerHTML'    => false !== $close_position ? substr( $raw, strlen( $open[0] ), $close_position - strlen( $open[0] ) ) : '',
					        'innerContent' => array(),
					    );
					}
					$id = $this->get_content_block_identifier( $block );
					if ( false !== $id ) {
						$occurrences[] = array( 'source_id' => $id, 'type' => 'block', 'original_content' => $raw, 'match_offset' => $offset, 'block_name' => $block_name );
					}
				}
			}

			foreach ( $this->get_shortcode_patterns() as $pattern ) {
				$matches = array();
				if ( ! preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
					continue;
				}
				foreach ( $matches as $match ) {
					$offset = (int) $match[0][1];
					$inside_block = false;
					foreach ( $block_ranges as $range ) {
						if ( $offset >= $range[0] && $offset < $range[1] ) {
							$inside_block = true;
							break;
						}
					}
					if ( $inside_block ) {
						continue;
					}
					$id = $this->get_content_match_identifier( $match );
					if ( false !== $id ) {
						$occurrences[] = array( 'source_id' => $id, 'type' => 'shortcode', 'original_content' => $match[0][0], 'match_offset' => $offset );
					}
				}
			}

			usort( $occurrences, function( $left, $right ) { return $left['match_offset'] - $right['match_offset']; } );
			return $occurrences;
		}

        /**
         * Parse a WPPA shortcode without accepting album-like text in another
         * attribute or a prefixed attribute such as data-album.
         *
         * @param string $shortcode Complete shortcode.
         * @return int|false
         */
        private function get_exact_shortcode_album_id( $shortcode ) {
            if ( ! is_string( $shortcode ) || ! preg_match( '/^\[wppa(?=[\s\]])/i', $shortcode ) || ']' !== substr( $shortcode, -1 ) ) {
                return false;
            }

            $attributes = substr( $shortcode, 5, -1 );
            $length = strlen( $attributes );
            $position = 0;
            $album_id = false;
            $album_count = 0;

            while ( $position < $length ) {
                while ( $position < $length && ctype_space( $attributes[ $position ] ) ) {
                    ++$position;
                }
                if ( $position >= $length ) {
                    break;
                }

                $name_start = $position;
                while ( $position < $length && preg_match( '/[A-Za-z0-9_:\-]/', $attributes[ $position ] ) ) {
                    ++$position;
                }
                if ( $name_start === $position ) {
                    return false;
                }
                $name = strtolower( substr( $attributes, $name_start, $position - $name_start ) );

                while ( $position < $length && ctype_space( $attributes[ $position ] ) ) {
                    ++$position;
                }
                if ( $position >= $length || '=' !== $attributes[ $position ] ) {
                    continue;
                }
                ++$position;
                while ( $position < $length && ctype_space( $attributes[ $position ] ) ) {
                    ++$position;
                }
                if ( $position >= $length ) {
                    return false;
                }

                $quote = $attributes[ $position ];
                if ( '"' === $quote || "'" === $quote ) {
                    ++$position;
                    $value_start = $position;
                    while ( $position < $length && $quote !== $attributes[ $position ] ) {
                        ++$position;
                    }
                    if ( $position >= $length ) {
                        return false;
                    }
                    $value = substr( $attributes, $value_start, $position - $value_start );
                    ++$position;
                } else {
                    $value_start = $position;
                    while ( $position < $length && ! ctype_space( $attributes[ $position ] ) ) {
                        ++$position;
                    }
                    $value = substr( $attributes, $value_start, $position - $value_start );
                }

                if ( 'album' === $name ) {
                    ++$album_count;
                    if ( 1 !== $album_count || ! preg_match( '/^[1-9]\d*$/', $value ) ) {
                        return false;
                    }
                    $album_id = (int) $value;
                }
            }

            return 1 === $album_count ? $album_id : false;
        }

        /**
         * Prime albums, photos and gallery candidates once per request.
         *
         * @return void
         */
        private function prime_source_data() {
            if ( null !== $this->galleries_by_id ) {
                return;
            }

            $this->album_rows = array();
            $this->photo_rows = array();
            $this->valid_photos_by_album = array();
            $this->galleries_by_id = array();

            if ( ! $this->detect() ) {
                return;
            }

            global $wpdb;
            $tables = $this->get_table_names();
            if ( ! $this->is_safe_table_name( $tables['albums'] ) || ! $this->is_safe_table_name( $tables['photos'] ) ) {
                return;
            }

            $album_rows = $wpdb->get_results(
                "SELECT id, name, description, a_order, a_parent, p_order_by, suba_order_by, timestamp, status, capability
                FROM {$tables['albums']}
                ORDER BY a_order ASC, id ASC"
            );
            $photo_rows = $wpdb->get_results(
                "SELECT id, album, ext, name, description, p_order, mean_rating, rating_count,
                    owner, timestamp, status, alt, filename, modified, exifdtm,
                    videox, videoy, duration, misc
                FROM {$tables['photos']}
                WHERE album > 0
                ORDER BY id ASC"
            );

            $album_rows = is_array( $album_rows ) ? $album_rows : array();
            $photo_rows = is_array( $photo_rows ) ? $photo_rows : array();

            foreach ( $album_rows as $row ) {
                $album_id = isset( $row->id ) ? (int) $row->id : 0;
                if ( $album_id > 0 ) {
                    $this->album_rows[ $album_id ] = $row;
                }
            }

            foreach ( $photo_rows as $row ) {
                $album_id = isset( $row->album ) ? (int) $row->album : 0;
                if ( $album_id < 1 || ! isset( $this->album_rows[ $album_id ] ) ) {
                    continue;
                }
                if ( ! $this->is_public_album( $this->album_rows[ $album_id ] ) || ! $this->is_migratable_photo( $row ) ) {
                    continue;
                }

                if ( ! isset( $this->valid_photos_by_album[ $album_id ] ) ) {
                    $this->valid_photos_by_album[ $album_id ] = array();
                }
                $this->valid_photos_by_album[ $album_id ][] = $row;
                $this->photo_rows[ (int) $row->id ] = $row;
            }

            foreach ( $this->album_rows as $album_id => $row ) {
                if ( empty( $this->valid_photos_by_album[ $album_id ] ) ) {
                    continue;
                }

                $title = isset( $row->name ) ? $row->name : '';
                $this->galleries_by_id[ $album_id ] = $this->get_gallery(
                    array(
                        'ID'             => $album_id,
                        'title'          => $title,
                        'data'           => null,
                        'children'       => array(),
                        'children_count' => count( $this->valid_photos_by_album[ $album_id ] ),
                        'settings'       => array(),
                    )
                );
            }
        }

        /**
         * Resolve authoritative active table constants or current-site inactive tables.
         *
         * @return array
         */
        private function get_table_names() {
            global $wpdb;

            return array(
                'albums' => defined( 'WPPA_ALBUMS' ) ? WPPA_ALBUMS : $wpdb->prefix . 'wppa_albums',
                'photos' => defined( 'WPPA_PHOTOS' ) ? WPPA_PHOTOS : $wpdb->prefix . 'wppa_photos',
            );
        }

        /**
         * Test a literal table name without allowing LIKE metacharacters to expand.
         *
         * @param string $table Table name.
         * @return bool
         */
        private function table_exists( $table ) {
            global $wpdb;

            if ( ! $this->is_safe_table_name( $table ) ) {
                return false;
            }

            $escaped = method_exists( $wpdb, 'esc_like' ) ? $wpdb->esc_like( $table ) : addcslashes( $table, '_%\\' );
            $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $escaped ) );

            return is_string( $found ) && $found === $table;
        }

        /**
         * Restrict interpolated identifiers to WordPress-compatible table names.
         *
         * @param string $table Table name.
         * @return bool
         */
        private function is_safe_table_name( $table ) {
            return is_string( $table ) && 1 === preg_match( '/^[A-Za-z0-9_]+$/', $table );
        }

        /**
         * Determine whether an album is public/displayable.
         *
         * @param object $row Album row.
         * @return bool
         */
        private function is_public_album( $row ) {
            $status = isset( $row->status ) ? strtolower( trim( $row->status ) ) : '';
            $capability = isset( $row->capability ) ? trim( (string) $row->capability ) : '';

            return '' === $capability && 'publish' === $status;
        }

        /**
         * Validate status, media type and canonical local display file.
         *
         * @param object $row Photo row.
         * @return bool
         */
        private function is_migratable_photo( $row ) {
            $id = isset( $row->id ) ? (int) $row->id : 0;
            $album_id = isset( $row->album ) ? (int) $row->album : 0;
            $status = isset( $row->status ) ? strtolower( trim( $row->status ) ) : '';
            $ext = isset( $row->ext ) ? strtolower( trim( $row->ext ) ) : '';

            if ( $id < 1 || $album_id < 1 ) {
                return false;
            }
            if ( ! in_array( $status, array( 'publish', 'featured', 'gold', 'silver', 'bronze' ), true ) ) {
                return false;
            }
            if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ), true ) ) {
                return false;
            }

            return false !== $this->get_local_photo_source( $row );
        }

        /**
         * Build and verify a canonical flat/tree local WPPA image path and URL.
         *
         * @param object $row Photo row.
         * @return array|false
         */
        private function get_local_photo_source( $row ) {
            $id = isset( $row->id ) ? (int) $row->id : 0;
            $ext = isset( $row->ext ) ? strtolower( trim( $row->ext ) ) : '';
            if ( $id < 1 || ! in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ), true ) ) {
                return false;
            }

            $locations = $this->get_upload_locations();
            if ( false === $locations ) {
                return false;
            }

            $relative = (string) $id;
            if ( 'tree' === get_option( 'wppa_file_system', 'flat' ) ) {
                $relative = $this->expand_photo_id( $id );
            }
            $relative .= '.' . $ext;

            $base_path = rtrim( wp_normalize_path( $locations['path'] ), '/' );
            $path = wp_normalize_path( $base_path . '/' . $relative );
            $real_base = realpath( $base_path );
            $real_path = realpath( $path );
            if ( false === $real_base || false === $real_path ) {
                return false;
            }
            $real_base = rtrim( wp_normalize_path( $real_base ), '/' );
            $real_path = wp_normalize_path( $real_path );
            if ( 0 !== strpos( $real_path, $real_base . '/' ) || ! is_file( $real_path ) ) {
                return false;
            }

            $image_types = array(
                'gif'  => IMAGETYPE_GIF,
                'jpg'  => IMAGETYPE_JPEG,
                'jpeg' => IMAGETYPE_JPEG,
                'png'  => IMAGETYPE_PNG,
            );
            if ( defined( 'IMAGETYPE_WEBP' ) ) {
                $image_types['webp'] = IMAGETYPE_WEBP;
            }
            $image_info = @getimagesize( $real_path );
            if ( ! isset( $image_types[ $ext ] ) || ! is_array( $image_info ) || ! isset( $image_info[2] ) || $image_types[ $ext ] !== (int) $image_info[2] ) {
                return false;
            }

            return array(
                'path' => $real_path,
                'url'  => rtrim( $locations['url'], '/' ) . '/' . str_replace( '%2F', '/', rawurlencode( $relative ) ),
            );
        }

        /**
         * Resolve active WPPA locations, otherwise current-site uploads only.
         *
         * @return array|false
         */
        private function get_upload_locations() {
            if ( defined( 'WPPA_UPLOAD_PATH' ) && defined( 'WPPA_UPLOAD_URL' ) ) {
                $path = WPPA_UPLOAD_PATH;
                $url = WPPA_UPLOAD_URL;
            } else {
                $uploads = wp_get_upload_dir();
                if ( ! is_array( $uploads ) || empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) {
                    return false;
                }
                $path = rtrim( $uploads['basedir'], '/\\' ) . '/wppa';
                $url = rtrim( $uploads['baseurl'], '/' ) . '/wppa';
            }

            if ( ! is_string( $path ) || '' === $path || ! is_string( $url ) || '' === $url ) {
                return false;
            }

            return array( 'path' => $path, 'url' => $url );
        }

        /**
         * Mirror WPPA's two-digit tree expansion without creating directories.
         *
         * @param int $id Photo ID.
         * @return string
         */
        private function expand_photo_id( $id ) {
            $remaining = (string) absint( $id );
            $parts = array();

            while ( strlen( $remaining ) > 2 ) {
                $parts[] = substr( $remaining, 0, 2 );
                $remaining = substr( $remaining, 2 );
            }
            $parts[] = $remaining;

            return implode( '/', $parts );
        }

        /**
         * Return per-album order, falling back to WPPA's global option.
         *
         * @param int $album_id Album ID.
         * @return int
         */
        private function get_photo_order( $album_id ) {
            $order = isset( $this->album_rows[ $album_id ]->p_order_by ) ? (int) $this->album_rows[ $album_id ]->p_order_by : 0;
            if ( 0 === $order ) {
                $order = (int) get_option( 'wppa_list_photos_by', 0 );
            }

            return $order;
        }

        /**
         * Sort supported WPPA order modes with an ascending ID tie-breaker.
         *
         * Random and unknown modes intentionally fall back to ID ascending.
         *
         * @param array $rows Photo rows.
         * @param int   $order WPPA order value.
         * @return void
         */
        private function sort_photo_rows( &$rows, $order ) {
            $field_map = array(
                1 => 'p_order',
                2 => 'name',
                4 => 'mean_rating',
                5 => 'timestamp',
                6 => 'rating_count',
                7 => 'exifdtm',
            );
            $absolute = abs( (int) $order );
            $field = isset( $field_map[ $absolute ] ) ? $field_map[ $absolute ] : 'id';
            $direction = ( (int) $order < 0 && 'id' !== $field ) ? -1 : 1;

            usort(
                $rows,
                function( $left, $right ) use ( $field, $direction ) {
                    $left_value = isset( $left->{$field} ) ? $left->{$field} : '';
                    $right_value = isset( $right->{$field} ) ? $right->{$field} : '';

                    if ( in_array( $field, array( 'p_order', 'mean_rating', 'rating_count', 'id' ), true ) ) {
                        $comparison = (float) $left_value == (float) $right_value ? 0 : ( (float) $left_value < (float) $right_value ? -1 : 1 );
                    } else {
                        $comparison = strcasecmp( (string) $left_value, (string) $right_value );
                    }

                    if ( 0 !== $comparison ) {
                        return $comparison * $direction;
                    }

                    $left_id = isset( $left->id ) ? (int) $left->id : 0;
                    $right_id = isset( $right->id ) ? (int) $right->id : 0;
                    if ( $left_id === $right_id ) {
                        return 0;
                    }

                    return $left_id < $right_id ? -1 : 1;
                }
            );
        }

        /**
         * Build public album child lists in deterministic source order.
         *
         * @return array
         */
        private function get_album_children() {
            $children = array();

            foreach ( $this->album_rows as $album_id => $row ) {
                if ( ! $this->is_public_album( $row ) ) {
                    continue;
                }
                $parent_id = isset( $row->a_parent ) ? (int) $row->a_parent : 0;
                if ( $parent_id > 0 && isset( $this->album_rows[ $parent_id ] ) ) {
                    if ( ! isset( $children[ $parent_id ] ) ) {
                        $children[ $parent_id ] = array();
                    }
                    $children[ $parent_id ][] = $album_id;
                }
            }

            foreach ( $children as $parent_id => &$ids ) {
                $parent = $this->album_rows[ $parent_id ];
                $order = isset( $parent->suba_order_by ) ? (int) $parent->suba_order_by : 0;
                if ( 0 === $order ) {
                    $order = (int) get_option( 'wppa_list_albums_by', 0 );
                }
                $field_map = array( 1 => 'a_order', 2 => 'name', 5 => 'timestamp' );
                $absolute = abs( $order );
                $field = isset( $field_map[ $absolute ] ) ? $field_map[ $absolute ] : 'id';
                $direction = ( $order < 0 && 'id' !== $field ) ? -1 : 1;
                usort(
                    $ids,
                    function( $left_id, $right_id ) use ( $field, $direction ) {
                        $left = $this->album_rows[ $left_id ];
                        $right = $this->album_rows[ $right_id ];
                        $left_value = isset( $left->{$field} ) ? $left->{$field} : '';
                        $right_value = isset( $right->{$field} ) ? $right->{$field} : '';

                        if ( in_array( $field, array( 'a_order', 'id' ), true ) ) {
                            $comparison = (int) $left_value === (int) $right_value ? 0 : ( (int) $left_value < (int) $right_value ? -1 : 1 );
                        } else {
                            $comparison = strcasecmp( (string) $left_value, (string) $right_value );
                        }

                        if ( 0 !== $comparison ) {
                            return $comparison * $direction;
                        }

                        return $left_id < $right_id ? -1 : ( $left_id === $right_id ? 0 : 1 );
                    }
                );
            }
            unset( $ids );

            return $children;
        }

        /**
         * Append a source album's own gallery and all descendant galleries once.
         *
         * @param int   $album_id Source album ID.
         * @param array $children_by_parent Child lookup.
         * @param array $gallery_ids Result IDs.
         * @param array $seen Cycle/deduplication guard.
         * @return void
         */
        private function append_descendant_gallery_ids( $album_id, $children_by_parent, &$gallery_ids, &$seen ) {
            if ( isset( $seen[ $album_id ] ) ) {
                return;
            }
            $seen[ $album_id ] = true;

            if ( isset( $this->galleries_by_id[ $album_id ] ) ) {
                $gallery_ids[] = $album_id;
            }

            if ( empty( $children_by_parent[ $album_id ] ) ) {
                return;
            }

            foreach ( $children_by_parent[ $album_id ] as $child_id ) {
                $this->append_descendant_gallery_ids( $child_id, $children_by_parent, $gallery_ids, $seen );
            }
        }

        /**
         * Use source filename only as metadata after reducing it to a basename.
         *
         * @param object $row Photo row.
         * @return string
         */
        private function image_slug( $row ) {
            $filename = isset( $row->filename ) ? basename( str_replace( '\\', '/', $row->filename ) ) : '';
            if ( '' !== $filename ) {
                return pathinfo( $filename, PATHINFO_FILENAME );
            }

            return isset( $row->name ) ? $row->name : (string) $row->id;
        }

        /**
         * Preserve the first valid EXIF, upload or modified date.
         *
         * @param object $row Photo row.
         * @return string
         */
        private function get_valid_photo_date( $row ) {
            foreach ( array( 'exifdtm', 'timestamp', 'modified' ) as $field ) {
                if ( ! isset( $row->{$field} ) || '' === trim( (string) $row->{$field} ) ) {
                    continue;
                }

                $value = trim( (string) $row->{$field} );
                foreach ( array( 'Y-m-d H:i:s', 'Y:m:d H:i:s' ) as $format ) {
                    $date = \DateTime::createFromFormat( '!' . $format, $value );
                    $errors = \DateTime::getLastErrors();
                    $is_valid = false !== $date && ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) );
                    if ( $is_valid && $date->format( $format ) === $value ) {
                        return $date->format( 'Y-m-d H:i:s' );
                    }
                }
                if ( ctype_digit( $value ) && (int) $value > 0 ) {
                    return date( 'Y-m-d H:i:s', (int) $value );
                }
            }

            return '';
        }
    }
}
