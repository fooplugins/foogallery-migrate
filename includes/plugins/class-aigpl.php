<?php
/**
 * FooGallery Migrator AIGPL Plugin Class
 *
 * @package FooPlugins\FooGalleryMigrate
 */

namespace FooPlugins\FooGalleryMigrate\Plugins;

use FooPlugins\FooGalleryMigrate\Objects\Plugin;

if ( ! class_exists( 'FooPlugins\FooGalleryMigrate\Plugins\Aigpl' ) ) {

    /**
     * Class Aigpl
     *
     * Migrates Album and Image Gallery Plus Lightbox data without loading the
     * source plugin.
     *
     * @package FooPlugins\FooGalleryMigrate
     */
    class Aigpl extends Plugin {

        const POST_TYPE = 'aigpl_gallery';
        const TAXONOMY = 'aigpl_cat';
        const META_GALLERY_IMAGES = '_aigpl_gallery_imgs';

        /**
         * Request-level lookup of imported attachments referenced by AIGPL galleries.
         *
         * @var array<int,array>
         */
        private $attachment_lookup = array();

        /**
         * Whether the request-level attachment lookup has been primed.
         *
         * @var bool
         */
        private $attachment_lookup_primed = false;

        /**
         * Keep request-only caches out of the persisted migration option.
         *
         * @return array
         */
        public function __sleep() {
            return array( 'is_detected' );
        }

        /**
         * Reinitialize request-only caches after loading from the migration option.
         *
         * @return void
         */
        public function __wakeup() {
            $this->attachment_lookup = array();
            $this->attachment_lookup_primed = false;
        }

        /**
         * Name of the plugin.
         *
         * @return string
         */
        function name() {
            return 'AIGPL';
        }

        /**
         * Detects data from the gallery plugin without loading its PHP.
         *
         * @return bool
         */
        function detect() {
            global $wpdb;

            return (bool) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT 1
                    FROM {$wpdb->posts} p
                    INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                    WHERE p.post_type = %s
                    AND p.post_status = 'publish'
                    AND pm.meta_key = %s
                    AND pm.meta_value <> ''
                    LIMIT 1",
                    self::POST_TYPE,
                    self::META_GALLERY_IMAGES
                )
            );
        }

        /**
         * Find all AIGPL galleries.
         *
         * @return array
         */
        function find_galleries() {
            $galleries = array();
            $aigpl_galleries = $this->get_aigpl_galleries();

            $this->prime_attachment_lookup( $aigpl_galleries );

            foreach ( $aigpl_galleries as $aigpl_gallery ) {
                $gallery = $this->get_gallery_from_post( $aigpl_gallery );

                if ( false !== $gallery ) {
                    $galleries[] = $gallery;
                }
            }

            return $galleries;
        }

        /**
         * Find all AIGPL albums.
         *
         * AIGPL stores gallery images on aigpl_gallery posts and uses aigpl_cat
         * terms as album/category groupings for album shortcodes.
         *
         * @return array
         */
        function find_albums() {
            $albums = array();
            $albums_by_term_id = array();
            $seen_children = array();
            $galleries_by_id = array();
            $aigpl_galleries = $this->get_aigpl_galleries();

            $this->prime_attachment_lookup( $aigpl_galleries );

            foreach ( $aigpl_galleries as $aigpl_gallery ) {
                $gallery = $this->get_gallery_from_post( $aigpl_gallery );

                if ( false !== $gallery ) {
                    $galleries_by_id[ (int) $aigpl_gallery->ID ] = $gallery;
                }
            }

            if ( empty( $galleries_by_id ) ) {
                return $albums;
            }

            $category_gallery_rows = $this->get_aigpl_category_gallery_rows();
            if ( ! is_array( $category_gallery_rows ) ) {
                return $albums;
            }

            foreach ( $category_gallery_rows as $row ) {
                $term_id = isset( $row->term_id ) ? (int) $row->term_id : 0;
                $gallery_id = isset( $row->gallery_id ) ? (int) $row->gallery_id : 0;

                if ( $term_id < 1 || ! isset( $galleries_by_id[ $gallery_id ] ) ) {
                    continue;
                }

                if ( ! isset( $albums_by_term_id[ $term_id ] ) ) {
                    $title = isset( $row->name ) ? $row->name : '';
                    $data = array(
                        'ID'             => $term_id,
                        'title'          => $title,
                        'data'           => null,
                        'fooalbum_title' => $title,
                    );

                    $album = $this->get_album( $data );
                    $album->children = array();
                    $album->children_count = 0;

                    $albums_by_term_id[ $term_id ] = $album;
                    $seen_children[ $term_id ] = array();
                }

                if ( isset( $seen_children[ $term_id ][ $gallery_id ] ) ) {
                    continue;
                }

                $albums_by_term_id[ $term_id ]->children[] = $galleries_by_id[ $gallery_id ];
                $albums_by_term_id[ $term_id ]->children_count = count( $albums_by_term_id[ $term_id ]->children );
                $seen_children[ $term_id ][ $gallery_id ] = true;
            }

            foreach ( $albums_by_term_id as $album ) {
                if ( $album->children_count > 0 ) {
                    $albums[] = $album;
                }
            }

            return $albums;
        }

        /**
         * Returns the gallery template.
         *
         * @param object $gallery Gallery object.
         * @return string
         */
        function get_gallery_template( $gallery ) {
            return 'default';
        }

        /**
         * Gets the settings for the gallery.
         *
         * @param object $gallery Gallery object.
         * @param array  $settings Existing default settings.
         * @return array
         */
        function get_gallery_settings( $gallery, $settings ) {
            $gallery_template = $this->get_migration_gallery_template( $gallery );

            if ( ! is_array( $settings ) ) {
                $settings = array();
            }

            if ( ! array_key_exists( $gallery_template . '_caption_title_source', $settings ) ) {
                $settings[ $gallery_template . '_caption_title_source' ] = 'title';
            }

            if ( ! array_key_exists( $gallery_template . '_caption_desc_source', $settings ) ) {
                $settings[ $gallery_template . '_caption_desc_source' ] = 'caption';
            }

            if ( ! array_key_exists( $gallery_template . '_lightbox', $settings ) ) {
                $settings[ $gallery_template . '_lightbox' ] = 'foobox';
            }

            return $settings;
        }

        /**
         * Returns shortcode regex patterns for AIGPL.
         *
         * Only single explicit numeric IDs or category IDs are matched.
         *
         * @return array
         */
        function get_shortcode_patterns() {
            return array(
                '/\[(?:aigpl-gallery-slider|aigpl-gallery)(?=[\s\]])[^\]]*\bid\s*=\s*(?:"(\d+)"|\'(\d+)\'|(\d+)(?=[\s\]]))[^\]]*\]/i',
                '/\[(?:aigpl-gallery-album-slider|aigpl-gallery-album)(?=[\s\]])(?![^\]]*\bcategory\s*=)[^\]]*\bid\s*=\s*(?:"(\d+)"|\'(\d+)\'|(\d+)(?=[\s\]]))[^\]]*\]/i',
                '/\[(?:aigpl-gallery-album-slider|aigpl-gallery-album)(?=[\s\]])[^\]]*\bcategory\s*=\s*(?:"(\d+)"|\'(\d+)\'|(\d+)(?=[\s\]]))[^\]]*\]/i',
            );
        }

        /**
         * Returns Gutenberg block patterns for AIGPL.
         *
         * @return array
         */
        function get_block_patterns() {
            return array(
                'aigpl/gallery'              => array(),
                'aigpl/gallery-slider'       => array(),
                'aigpl/gallery-album'        => array(),
                'aigpl/gallery-album-slider' => array(),
            );
        }

        /**
         * Returns the migrated object type for a detected shortcode/block.
         *
         * @param string $original_content Original shortcode or serialized block content.
         * @param string $block_name Block name, if the detected content is a block.
         * @return string
         */
        function get_content_object_type( $original_content, $block_name = '' ) {
            $is_album_content = (
                'aigpl/gallery-album' === $block_name ||
                'aigpl/gallery-album-slider' === $block_name ||
                preg_match( '/\[(?:aigpl-gallery-album-slider|aigpl-gallery-album)(?=[\s\]])/i', $original_content )
            );

            if ( $is_album_content && $this->content_references_category( $original_content ) ) {
                return 'album';
            }

            return 'gallery';
        }

        /**
         * Return published AIGPL gallery posts with image metadata.
         *
         * @return array
         */
        private function get_aigpl_galleries() {
            global $wpdb;

            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT DISTINCT p.*
                    FROM {$wpdb->posts} p
                    INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                    WHERE p.post_type = %s
                    AND p.post_status = 'publish'
                    AND pm.meta_key = %s
                    AND pm.meta_value <> ''
                    ORDER BY p.post_date DESC, p.ID DESC",
                    self::POST_TYPE,
                    self::META_GALLERY_IMAGES
                )
            );
        }

        /**
         * Return AIGPL category-to-gallery relationship rows.
         *
         * @return array
         */
        private function get_aigpl_category_gallery_rows() {
            global $wpdb;

            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT DISTINCT
                        t.term_id,
                        t.name,
                        t.slug,
                        tt.description,
                        tt.parent,
                        tr.object_id AS gallery_id
                    FROM {$wpdb->terms} t
                    INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
                    INNER JOIN {$wpdb->term_relationships} tr ON tt.term_taxonomy_id = tr.term_taxonomy_id
                    INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
                    INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                    WHERE tt.taxonomy = %s
                    AND p.post_type = %s
                    AND p.post_status = 'publish'
                    AND pm.meta_key = %s
                    AND pm.meta_value <> ''
                    ORDER BY t.name ASC, t.term_id ASC, p.post_date DESC, p.ID DESC",
                    self::TAXONOMY,
                    self::POST_TYPE,
                    self::META_GALLERY_IMAGES
                )
            );
        }

        /**
         * Returns true when detected content explicitly references AIGPL categories.
         *
         * @param string $content Shortcode or serialized block content.
         * @return bool
         */
        private function content_references_category( $content ) {
            return is_string( $content ) && (bool) preg_match( '/(?:\bcategory\b|["\']category["\'])\s*(?:=|:)/i', $content );
        }

        /**
         * Build a FooGallery migratable gallery from an AIGPL post.
         *
         * @param object $aigpl_gallery AIGPL gallery post.
         * @return object|false
         */
        private function get_gallery_from_post( $aigpl_gallery ) {
            $image_count = $this->get_valid_gallery_image_count( $aigpl_gallery->ID );

            if ( $image_count < 1 ) {
                return false;
            }

            $data = array(
                'ID'             => (int) $aigpl_gallery->ID,
                'title'          => $aigpl_gallery->post_title,
                'data'           => null,
                'children'       => array(),
                'children_count' => $image_count,
                'settings'       => array(),
            );

            return $this->get_gallery( $data );
        }

        /**
         * Load deferred child images for a gallery when it is migrated.
         *
         * @param object $object Migratable object.
         * @return array
         */
        public function load_object_children( $object ) {
            if ( ! is_object( $object ) || 'gallery' !== $object->type() ) {
                return array();
            }

            $gallery = get_post( absint( $object->ID ) );
            if ( ! $gallery || self::POST_TYPE !== $gallery->post_type ) {
                return array();
            }

            $this->prime_attachment_lookup( array( $gallery ) );

            return $this->find_images( $object->ID );
        }

        /**
         * Find all valid images for an AIGPL gallery.
         *
         * @param int $gallery_id AIGPL gallery post ID.
         * @return array
         */
        private function find_images( $gallery_id ) {
            $images = array();
            $aigpl_images = $this->get_gallery_image_ids( $gallery_id );

            foreach ( $aigpl_images as $attachment_id ) {
                $attachment = $this->get_cached_attachment_data( $attachment_id );

                if ( ! $attachment || empty( $attachment['source_url'] ) ) {
                    continue;
                }

                $data = array(
                    'source_url'  => $attachment['source_url'],
                    'slug'        => $attachment['post_name'],
                    'title'       => $attachment['post_title'],
                    'caption'     => $attachment['post_excerpt'],
                    'description' => $attachment['post_content'],
                    'alt'         => $attachment['alt'],
                    'date'        => $attachment['post_date'],
                    'data'        => null,
                );

                $images[] = $this->get_image( $data );
            }

            return $images;
        }

        /**
         * Counts valid imported attachments for an AIGPL gallery.
         *
         * @param int $gallery_id AIGPL gallery post ID.
         * @return int
         */
        private function get_valid_gallery_image_count( $gallery_id ) {
            $count = 0;

            foreach ( $this->get_gallery_image_ids( $gallery_id ) as $attachment_id ) {
                if ( $this->get_cached_attachment_data( $attachment_id ) ) {
                    $count++;
                }
            }

            return $count;
        }

        /**
         * Prime gallery image metadata and referenced attachments in bulk.
         *
         * @param array $aigpl_galleries AIGPL gallery posts.
         * @return void
         */
        private function prime_attachment_lookup( $aigpl_galleries ) {
            global $wpdb;

            $this->attachment_lookup = array();
            $this->attachment_lookup_primed = true;

            if ( empty( $aigpl_galleries ) ) {
                return;
            }

            $gallery_ids = array_map( 'intval', wp_list_pluck( $aigpl_galleries, 'ID' ) );
            update_meta_cache( 'post', $gallery_ids );

            $attachment_ids = array();
            foreach ( $gallery_ids as $gallery_id ) {
                $attachment_ids = array_merge( $attachment_ids, $this->get_gallery_image_ids( $gallery_id ) );
            }

            $attachment_ids = array_values( array_unique( array_filter( array_map( 'absint', $attachment_ids ) ) ) );
            if ( empty( $attachment_ids ) ) {
                return;
            }

            foreach ( array_chunk( $attachment_ids, 1000 ) as $chunk ) {
                $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
                $query_args = array_merge(
                    array(
                        '_wp_attached_file',
                        '_wp_attachment_image_alt',
                    ),
                    $chunk
                );

                $attachments = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT
                            p.ID,
                            p.guid,
                            p.post_name,
                            p.post_title,
                            p.post_excerpt,
                            p.post_content,
                            p.post_date,
                            file_meta.meta_value AS attached_file,
                            alt_meta.meta_value AS alt
                        FROM {$wpdb->posts} p
                        LEFT JOIN {$wpdb->postmeta} file_meta
                            ON file_meta.post_id = p.ID
                            AND file_meta.meta_key = %s
                        LEFT JOIN {$wpdb->postmeta} alt_meta
                            ON alt_meta.post_id = p.ID
                            AND alt_meta.meta_key = %s
                        WHERE p.post_type = 'attachment'
                        AND p.ID IN ($placeholders)",
                        $query_args
                    ),
                    ARRAY_A
                );

                foreach ( $attachments as $attachment ) {
                    $attachment['source_url'] = $this->build_attachment_url( $attachment );
                    $this->attachment_lookup[ (int) $attachment['ID'] ] = $attachment;
                }
            }
        }

        /**
         * Get previously primed attachment data, falling back for direct calls/tests.
         *
         * @param int $attachment_id Attachment ID.
         * @return array|null
         */
        private function get_cached_attachment_data( $attachment_id ) {
            $attachment_id = absint( $attachment_id );

            if ( $attachment_id > 0 && isset( $this->attachment_lookup[ $attachment_id ] ) ) {
                return $this->attachment_lookup[ $attachment_id ];
            }

            if ( $this->attachment_lookup_primed ) {
                return null;
            }

            $attachment = $attachment_id > 0 ? get_post( $attachment_id ) : null;
            if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
                return null;
            }

            return array(
                'ID'           => (int) $attachment->ID,
                'guid'         => $attachment->guid,
                'post_name'    => $attachment->post_name,
                'post_title'   => $attachment->post_title,
                'post_excerpt' => $attachment->post_excerpt,
                'post_content' => $attachment->post_content,
                'post_date'    => $attachment->post_date,
                'alt'          => get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
                'source_url'   => wp_get_attachment_url( $attachment_id ),
            );
        }

        /**
         * Build an attachment URL from minimal database fields.
         *
         * @param array $attachment Attachment row.
         * @return string
         */
        private function build_attachment_url( $attachment ) {
            $file = isset( $attachment['attached_file'] ) ? (string) $attachment['attached_file'] : '';

            if ( '' === $file ) {
                return isset( $attachment['guid'] ) ? (string) $attachment['guid'] : '';
            }

            if ( preg_match( '|^https?://|i', $file ) ) {
                return $file;
            }

            $uploads = wp_get_upload_dir();
            $file = wp_normalize_path( $file );
            $uploads_basedir = wp_normalize_path( trailingslashit( $uploads['basedir'] ) );

            if ( 0 === strpos( $file, $uploads_basedir ) ) {
                $file = ltrim( substr( $file, strlen( $uploads_basedir ) ), '/' );
            }

            return trailingslashit( $uploads['baseurl'] ) . ltrim( $file, '/' );
        }

        /**
         * Return normalized AIGPL image attachment IDs while preserving order.
         *
         * @param int $gallery_id AIGPL gallery post ID.
         * @return array
         */
        private function get_gallery_image_ids( $gallery_id ) {
            $image_ids = array();
            $aigpl_images = get_post_meta( $gallery_id, self::META_GALLERY_IMAGES, true );

            if ( ! is_array( $aigpl_images ) || empty( $aigpl_images ) ) {
                return $image_ids;
            }

            foreach ( $aigpl_images as $attachment_id ) {
                $attachment_id = absint( $attachment_id );

                if ( $attachment_id > 0 ) {
                    $image_ids[] = $attachment_id;
                }
            }

            return $image_ids;
        }
    }
}
