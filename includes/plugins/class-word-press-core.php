<?php
/**
 * WordPress core gallery migration source.
 *
 * @package FooPlugins\FooGalleryMigrate
 */

namespace FooPlugins\FooGalleryMigrate\Plugins;

use FooPlugins\FooGalleryMigrate\Objects\Image;
use FooPlugins\FooGalleryMigrate\Objects\Plugin;

if ( ! class_exists( 'FooPlugins\FooGalleryMigrate\Plugins\WordPressCore' ) ) {

    /**
     * Treats each stored core gallery occurrence as a migratable gallery.
     */
    class WordPressCore extends Plugin {

        /**
         * Source name.
         *
         * @return string
         */
        function name() {
            return 'WordPress Core';
        }

        /**
         * Detect at least one valid core gallery occurrence.
         *
         * @return bool
         */
        function detect() {
            foreach ( $this->get_eligible_posts() as $post ) {
                if ( ! empty( $this->find_content_occurrences( $post ) ) ) {
                    return true;
                }
            }

            return false;
        }

        /**
         * Build one migratable gallery per stored occurrence.
         *
         * @return array
         */
        function find_galleries() {
            $galleries = array();

            foreach ( $this->get_eligible_posts() as $post ) {
                foreach ( $this->find_content_occurrences( $post ) as $occurrence ) {
                    $children = array();
                    foreach ( $occurrence['attachment_ids'] as $attachment_id ) {
                        $image = new Image();
                        $image->source_url = wp_get_attachment_url( $attachment_id );
                        $image->migrated_id = $attachment_id;
                        $image->migrated = true;
                        $children[] = $image;
                    }

                    $data = array(
                        'ID'       => $occurrence['source_id'],
                        'title'    => $occurrence['title'],
                        'data'     => array(
                            'post_id'          => (int) $post->ID,
                            'source_context'   => $occurrence['source_context'],
                            'attributes'       => $occurrence['attributes'],
                            'original_content' => $occurrence['original_content'],
                        ),
                        'children' => $children,
                        'settings' => $occurrence['attributes'],
                    );

                    $galleries[] = $this->get_gallery( $data );
                }
            }

            return $galleries;
        }

        /**
         * Core galleries do not contain albums.
         *
         * @return array
         */
        function find_albums() {
            return array();
        }

        /**
         * Use FooGallery's default layout unless the global override applies.
         *
         * @param object $gallery Gallery object.
         * @return string
         */
        function get_gallery_template( $gallery ) {
            return 'default';
        }

        /**
         * Preserve attributes that map safely to FooGallery settings.
         *
         * The complete normalized source attributes also remain on the gallery
         * migration data for audit/debug purposes.
         *
         * @param object $gallery Gallery object.
         * @param array  $settings Existing settings.
         * @return array
         */
        function get_gallery_settings( $gallery, $settings ) {
            if ( ! is_array( $settings ) ) {
                $settings = array();
            }

            $attributes = isset( $gallery->settings ) && is_array( $gallery->settings ) ? $gallery->settings : array();
            $template = $this->get_migration_gallery_template( $gallery );
            $link = isset( $attributes['link'] ) ? $attributes['link'] : ( isset( $attributes['linkTo'] ) ? $attributes['linkTo'] : '' );

            if ( in_array( $link, array( 'file', 'media' ), true ) ) {
                $settings[ $template . '_lightbox' ] = 'foobox';
            } elseif ( 'none' === $link ) {
                $settings[ $template . '_lightbox' ] = 'none';
            }

            return $settings;
        }

        /**
         * Parse valid core gallery occurrences in a post.
         *
         * @param object $post WordPress post object.
         * @return array
         */
        function find_content_occurrences( $post ) {
            $occurrences = array();
            if ( ! is_object( $post ) || empty( $post->post_content ) ) {
                return $occurrences;
            }

            $content = (string) $post->post_content;
            $block_ranges = array();
            $gallery_blocks = array();
            if ( function_exists( 'parse_blocks' ) ) {
                $this->collect_block_occurrences( $content, $block_ranges, $gallery_blocks );
            }

            $raw_occurrences = array();
            foreach ( $gallery_blocks as $gallery_block ) {
                $attachment_ids = $this->get_block_attachment_ids( $gallery_block['block'] );
                if ( empty( $attachment_ids ) ) {
                    continue;
                }

                $raw_occurrences[] = array(
                    'type'             => 'block',
                    'block_name'       => 'core/gallery',
                    'original_content' => $gallery_block['serialized'],
                    'match_offset'     => $gallery_block['offset'],
                    'attachment_ids'   => $attachment_ids,
                    'attributes'       => $this->normalize_attributes( isset( $gallery_block['block']['attrs'] ) ? $gallery_block['block']['attrs'] : array() ),
                );
            }

            if ( function_exists( 'get_shortcode_regex' ) && function_exists( 'shortcode_parse_atts' ) ) {
                $pattern = get_shortcode_regex( array( 'gallery' ) );
                $matches = array();
                if ( preg_match_all( '/' . $pattern . '/s', $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
                    foreach ( $matches as $match ) {
                        if ( '[' === $match[1][0] || ']' === $match[6][0] ) {
                            continue;
                        }

                        $offset = (int) $match[0][1];
                        if ( ! $this->shortcode_is_allowed_at_offset( $offset, $block_ranges ) ) {
                            continue;
                        }

                        $attributes = shortcode_parse_atts( $match[3][0] );
                        $attributes = is_array( $attributes ) ? $attributes : array();
                        $attachment_ids = $this->get_shortcode_attachment_ids( $post, $attributes );
                        if ( empty( $attachment_ids ) ) {
                            continue;
                        }

                        $raw_occurrences[] = array(
                            'type'             => 'shortcode',
                            'original_content' => $match[0][0],
                            'match_offset'     => $offset,
                            'attachment_ids'   => $attachment_ids,
                            'attributes'       => $this->normalize_attributes( $attributes ),
                        );
                    }
                }
            }

            usort( $raw_occurrences, array( $this, 'sort_occurrences_by_offset' ) );
            $identity_counts = array();
            foreach ( $raw_occurrences as $index => $occurrence ) {
                $number = $index + 1;
                $identity_hash = $occurrence['type'] . '-' . substr( md5( $occurrence['original_content'] ), 0, 12 );
                $identity_counts[ $identity_hash ] = isset( $identity_counts[ $identity_hash ] ) ? $identity_counts[ $identity_hash ] + 1 : 1;
                $source_id = (int) $post->ID . '-' . $identity_hash . '-' . $identity_counts[ $identity_hash ];
                $type_label = 'block' === $occurrence['type'] ? __( 'Gallery block', 'foogallery-migrate' ) : __( 'Gallery shortcode', 'foogallery-migrate' );

                $occurrence['source_id'] = $source_id;
                $occurrence['title'] = sprintf(
                    /* translators: 1: post title, 2: gallery occurrence number. */
                    __( '%1$s - core gallery %2$d', 'foogallery-migrate' ),
                    $post->post_title,
                    $number
                );
                $occurrence['source_context'] = sprintf(
                    /* translators: 1: source type, 2: occurrence number, 3: attachment count. */
                    _n( '%1$s #%2$d (%3$d image)', '%1$s #%2$d (%3$d images)', count( $occurrence['attachment_ids'] ), 'foogallery-migrate' ),
                    $type_label,
                    $number,
                    count( $occurrence['attachment_ids'] )
                );
                $occurrences[] = $occurrence;
            }

            return $occurrences;
        }

        /**
         * Sort parsed occurrences into source order.
         *
         * @param array $left Left occurrence.
         * @param array $right Right occurrence.
         * @return int
         */
        function sort_occurrences_by_offset( $left, $right ) {
            if ( $left['match_offset'] === $right['match_offset'] ) {
                return 0;
            }

            return ( $left['match_offset'] < $right['match_offset'] ) ? -1 : 1;
        }

        /**
         * Locate exact block source ranges without canonicalizing stored markup.
         *
         * @param string $content Full post content.
         * @param array  $ranges Located block ranges.
         * @param array  $galleries Located gallery blocks.
         * @return void
         */
        private function collect_block_occurrences( $content, &$ranges, &$galleries ) {
            $matches = array();
            if ( ! preg_match_all( '/<!--\s*(\/?)wp:([^\s>]+)(.*?)-->/s', $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
                return;
            }

            $stack = array();
            foreach ( $matches as $match ) {
                $token = $match[0][0];
                $offset = (int) $match[0][1];
                $is_closing = '/' === $match[1][0];
                $name = $this->normalize_block_name( $match[2][0] );
                $is_self_closing = ! $is_closing && preg_match( '/\/\s*$/', $match[3][0] );

                if ( $is_closing ) {
                    $last_index = count( $stack ) - 1;
                    if ( $last_index < 0 || $stack[ $last_index ]['name'] !== $name ) {
                        continue;
                    }

                    $opened = array_pop( $stack );
                    $this->add_block_range(
                        $content,
                        $opened['start'],
                        $offset + strlen( $token ),
                        $name,
                        $opened['depth'],
                        $ranges,
                        $galleries
                    );
                } elseif ( $is_self_closing ) {
                    $this->add_block_range(
                        $content,
                        $offset,
                        $offset + strlen( $token ),
                        $name,
                        count( $stack ),
                        $ranges,
                        $galleries
                    );
                } else {
                    $stack[] = array(
                        'start' => $offset,
                        'name'  => $name,
                        'depth' => count( $stack ),
                    );
                }
            }
        }

        /**
         * Add one exact block range and parse gallery block data.
         *
         * @param string $content Full post content.
         * @param int    $start Start offset.
         * @param int    $end End offset.
         * @param string $name Normalized block name.
         * @param int    $depth Nesting depth.
         * @param array  $ranges Located block ranges.
         * @param array  $galleries Located gallery blocks.
         * @return void
         */
        private function add_block_range( $content, $start, $end, $name, $depth, &$ranges, &$galleries ) {
            $serialized = substr( $content, $start, $end - $start );
            $ranges[] = array(
                'start' => $start,
                'end'   => $end,
                'name'  => $name,
                'depth' => $depth,
            );

            if ( 'core/gallery' !== $name ) {
                return;
            }

            $parsed = parse_blocks( $serialized );
            if ( empty( $parsed ) || ! is_array( $parsed[0] ) || 'core/gallery' !== $parsed[0]['blockName'] ) {
                return;
            }

            $galleries[] = array(
                'block'      => $parsed[0],
                'serialized' => $serialized,
                'offset'     => $start,
            );
        }

        /**
         * Normalize parser comment names to WordPress block names.
         *
         * @param string $name Raw block name.
         * @return string
         */
        private function normalize_block_name( $name ) {
            return false === strpos( $name, '/' ) ? 'core/' . $name : $name;
        }

        /**
         * Only freeform/classic content and core shortcode blocks own shortcodes.
         *
         * @param int   $offset Match offset.
         * @param array $ranges Located block ranges.
         * @return bool
         */
        private function shortcode_is_allowed_at_offset( $offset, $ranges ) {
            $owner = false;
            foreach ( $ranges as $range ) {
                if ( $offset >= $range['start'] && $offset < $range['end'] ) {
                    if ( false === $owner || $range['depth'] >= $owner['depth'] ) {
                        $owner = $range;
                    }
                }
            }

            return false === $owner || 'core/shortcode' === $owner['name'];
        }

        /**
         * Resolve IDs from core/gallery block attributes and nested images.
         *
         * @param array $block Gallery block.
         * @return array
         */
        private function get_block_attachment_ids( $block ) {
            $ids = array();
            if ( ! empty( $block['attrs']['ids'] ) && is_array( $block['attrs']['ids'] ) ) {
                $ids = $block['attrs']['ids'];
            }

            $nested_ids = array();
            if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
                $this->collect_image_block_ids( $block['innerBlocks'], $nested_ids );
            }
            if ( ! empty( $nested_ids ) ) {
                $ids = $nested_ids;
            }

            return $this->normalize_attachment_ids( $ids );
        }

        /**
         * Recursively collect core/image IDs.
         *
         * @param array $blocks Image blocks.
         * @param array $ids Attachment IDs.
         * @return void
         */
        private function collect_image_block_ids( $blocks, &$ids ) {
            foreach ( $blocks as $block ) {
                if ( isset( $block['blockName'] ) && 'core/image' === $block['blockName'] && ! empty( $block['attrs']['id'] ) ) {
                    $ids[] = $block['attrs']['id'];
                }
                if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
                    $this->collect_image_block_ids( $block['innerBlocks'], $ids );
                }
            }
        }

        /**
         * Resolve shortcode IDs, including classic galleries without an ids attribute.
         *
         * @param object $post Post object.
         * @param array  $attributes Parsed shortcode attributes.
         * @return array
         */
        private function get_shortcode_attachment_ids( $post, $attributes ) {
            $include = '';
            $has_explicit_ids = ! empty( $attributes['ids'] );
            if ( $has_explicit_ids ) {
                $include = $attributes['ids'];
            } elseif ( ! empty( $attributes['include'] ) ) {
                $include = $attributes['include'];
            }

            $order = ! empty( $attributes['order'] ) && 'DESC' === strtoupper( $attributes['order'] ) ? 'DESC' : 'ASC';
            $orderby = ! empty( $attributes['orderby'] ) ? $attributes['orderby'] : 'menu_order ID';
            if ( $has_explicit_ids && empty( $attributes['orderby'] ) ) {
                $orderby = 'post__in';
            }

            $query = array(
                'post_status'    => 'inherit',
                'post_type'      => 'attachment',
                'post_mime_type' => 'image',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'order'          => $order,
                'orderby'        => $orderby,
            );

            if ( '' !== $include ) {
                $query['include'] = $include;
            } else {
                $query['post_parent'] = ! empty( $attributes['id'] ) ? absint( $attributes['id'] ) : (int) $post->ID;
                if ( ! empty( $attributes['exclude'] ) ) {
                    $query['post__not_in'] = array_map( 'absint', preg_split( '/\s*,\s*/', (string) $attributes['exclude'] ) );
                }
            }

            return $this->normalize_attachment_ids( get_posts( $query ) );
        }

        /**
         * Keep valid image attachments once, in source order.
         *
         * @param array $ids Candidate IDs.
         * @return array
         */
        private function normalize_attachment_ids( $ids ) {
            $normalized = array();
            foreach ( (array) $ids as $id ) {
                $id = absint( $id );
                if ( $id <= 0 || isset( $normalized[ $id ] ) ) {
                    continue;
                }
                $attachment = get_post( $id );
                if ( ! $attachment || 'attachment' !== $attachment->post_type || 0 !== strpos( (string) $attachment->post_mime_type, 'image/' ) ) {
                    continue;
                }
                $normalized[ $id ] = $id;
            }

            return array_values( $normalized );
        }

        /**
         * Preserve only scalar, known gallery attributes.
         *
         * @param array $attributes Source attributes.
         * @return array
         */
        private function normalize_attributes( $attributes ) {
            $normalized = array();
            $allowed = array( 'columns', 'link', 'linkTo', 'size', 'sizeSlug', 'imageCrop', 'orderby', 'order' );
            foreach ( $allowed as $key ) {
                if ( isset( $attributes[ $key ] ) && is_scalar( $attributes[ $key ] ) ) {
                    $normalized[ $key ] = sanitize_text_field( (string) $attributes[ $key ] );
                }
            }

            return $normalized;
        }

        /**
         * Published posts and pages match the existing content migrator scope.
         *
         * @return array
         */
        private function get_eligible_posts() {
            global $wpdb;

            return $wpdb->get_results(
                "SELECT ID, post_title, post_content, post_type, post_status
                FROM {$wpdb->posts}
                WHERE post_status = 'publish'
                AND post_type IN ('post', 'page')
                AND (post_content LIKE '%[gallery%' OR post_content LIKE '%wp:gallery%')
                ORDER BY ID ASC"
            );
        }
    }
}
