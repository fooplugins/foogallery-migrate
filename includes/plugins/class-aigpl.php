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
        const META_GALLERY_IMAGES = '_aigpl_gallery_imgs';

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
            foreach ( $this->get_aigpl_galleries() as $aigpl_gallery ) {
                if ( ! empty( $this->get_gallery_image_ids( $aigpl_gallery->ID ) ) ) {
                    return true;
                }
            }

            return false;
        }

        /**
         * Find all AIGPL galleries.
         *
         * @return array
         */
        function find_galleries() {
            $galleries = array();

            foreach ( $this->get_aigpl_galleries() as $aigpl_gallery ) {
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
         * @return array
         */
        function find_albums() {
            $albums = array();

            foreach ( $this->get_aigpl_galleries() as $aigpl_gallery ) {
                $gallery = $this->get_gallery_from_post( $aigpl_gallery );

                if ( false === $gallery ) {
                    continue;
                }

                $data = array(
                    'ID'             => (int) $aigpl_gallery->ID,
                    'title'          => $aigpl_gallery->post_title,
                    'data'           => $aigpl_gallery,
                    'fooalbum_title' => $aigpl_gallery->post_title,
                );

                $album = $this->get_album( $data );
                $album->children = array( $gallery );

                $albums[] = $album;
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
            $gallery_template = $this->get_gallery_template( $gallery );

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
         * Only single explicit numeric IDs are matched.
         *
         * @return array
         */
        function get_shortcode_patterns() {
            return array(
                '/\[(?:aigpl-gallery-slider|aigpl-gallery)(?=[\s\]])[^\]]*\bid\s*=\s*(?:"(\d+)"|\'(\d+)\'|(\d+)(?=[\s\]]))[^\]]*\]/i',
                '/\[(?:aigpl-gallery-album-slider|aigpl-gallery-album)(?=[\s\]])[^\]]*\bid\s*=\s*(?:"(\d+)"|\'(\d+)\'|(\d+)(?=[\s\]]))[^\]]*\]/i',
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
            if (
                'aigpl/gallery-album' === $block_name ||
                'aigpl/gallery-album-slider' === $block_name ||
                preg_match( '/\[(?:aigpl-gallery-album-slider|aigpl-gallery-album)(?=[\s\]])/i', $original_content )
            ) {
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
         * Build a FooGallery migratable gallery from an AIGPL post.
         *
         * @param object $aigpl_gallery AIGPL gallery post.
         * @return object|false
         */
        private function get_gallery_from_post( $aigpl_gallery ) {
            $images = $this->find_images( $aigpl_gallery->ID );

            if ( empty( $images ) ) {
                return false;
            }

            $data = array(
                'ID'       => (int) $aigpl_gallery->ID,
                'title'    => $aigpl_gallery->post_title,
                'data'     => $aigpl_gallery,
                'children' => $images,
                'settings' => array(),
            );

            return $this->get_gallery( $data );
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
                $attachment = get_post( $attachment_id );

                if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
                    continue;
                }

                $source_url = wp_get_attachment_url( $attachment_id );

                if ( empty( $source_url ) ) {
                    continue;
                }

                $data = array(
                    'source_url'  => $source_url,
                    'slug'        => $attachment->post_name,
                    'title'       => $attachment->post_title,
                    'caption'     => $attachment->post_excerpt,
                    'description' => $attachment->post_content,
                    'alt'         => get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
                    'date'        => $attachment->post_date,
                    'data'        => $attachment,
                );

                $images[] = $this->get_image( $data );
            }

            return $images;
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
