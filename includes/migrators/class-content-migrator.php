<?php
/**
 * FooGallery Content Migrator Class
 *
 * @package FooPlugins\FooGalleryMigrate
 */

namespace FooPlugins\FooGalleryMigrate\Migrators;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use FooPlugins\FooGalleryMigrate\MigratorEngine;
use FooPlugins\FooGalleryMigrate\Plugins\Photo;
use FooPlugins\FooGalleryMigrate\Pagination;

if ( ! class_exists( 'FooPlugins\FooGalleryMigrate\Migrators\ContentMigrator' ) ) {

	/**
	 * Class ContentMigrator
	 *
	 * @package FooPlugins\FooGalleryMigrate
	 */
	class ContentMigrator {

		/**
		 * @var MigratorEngine
		 */
		protected $migrator_engine;

		/**
		 * @var string
		 */
		protected $type;

		/**
		 * Initialize the ContentMigrator
		 * @param $migrator_engine MigratorEngine
		 * @param $type string
		 */
		function __construct( $migrator_engine, $type ) {
			$this->migrator_engine = $migrator_engine;
			$this->type = $type;
		}

		/**
		 * Gets a migrator setting.
		 *
		 * @param $name
		 * @param $default
		 * @return false|mixed
		 */
		function get_setting( $name, $default = false ) {
			return $this->migrator_engine->get_migrator_setting( $name, $default );
		}

		/**
		 * Sets a migrator setting.
		 *
		 * @param $name
		 * @param $value
		 * @return void
		 */
		function set_setting( $name, $value ) {
			return $this->migrator_engine->set_migrator_setting( $name, $value );
		}

		/**
		 * Return the current content scan results, optionally starting a fresh batch.
		 *
		 * @param bool $force Force a fresh scan.
		 * @return array
		 */
		function scan_content( $force = false ) {
			if ( $force ) {
				$this->scan_content_batch( true );
			}

			return $this->get_content_items();
		}

		/**
		 * Process one bounded, resumable batch of posts containing possible gallery content.
		 *
		 * A batch's findings and cursor are persisted as one state record. If parsing or
		 * persistence fails, the prior state remains resumable and no cursor is advanced.
		 *
		 * @param bool $reset Start a fresh scan without discarding the prior state until this batch succeeds.
		 * @return array Scan progress.
		 * @throws \RuntimeException When the batch state cannot be persisted.
		 */
		function scan_content_batch( $reset = false ) {
			$state = $reset ? array(
				'items' => array(),
				'progress' => $this->default_scan_progress(),
			) : $this->get_scan_state();
			$content_items = $state['items'];
			$progress = $state['progress'];

			if ( ! $reset && $progress['complete'] ) {
				return $progress;
			}

			$plugins = $this->migrator_engine->get_plugins();
			if ( empty( $plugins ) ) {
				$progress['started'] = true;
				$progress['complete'] = true;
				$this->persist_scan_state( $content_items, $progress );
				return $progress;
			}

			$batch_size = (int) apply_filters( 'foogallery_migrate_content_scan_batch_size', 20 );
			$batch_size = max( 1, min( 100, $batch_size ) );
			$time_limit = (float) apply_filters( 'foogallery_migrate_content_scan_time_limit', 10 );
			$time_limit = max( 1, min( 30, $time_limit ) );
			$started_at = microtime( true );

			global $wpdb;
			$shortcode_like = '%' . $wpdb->esc_like( '[' ) . '%';
			$block_like = '%' . $wpdb->esc_like( '<!-- wp:' ) . '%';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$posts = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_title, post_content, post_type, post_status
					FROM {$wpdb->posts}
					WHERE post_status = 'publish'
					AND post_type IN ('post', 'page')
					AND (post_content LIKE %s OR post_content LIKE %s)
					AND ID > %d
					ORDER BY ID ASC
					LIMIT %d",
					$shortcode_like,
					$block_like,
					(int) $progress['cursor'],
					$batch_size + 1
				)
			);
			$posts = is_array( $posts ) ? $posts : array();
			$has_more_posts = count( $posts ) > $batch_size;
			if ( $has_more_posts ) {
				$posts = array_slice( $posts, 0, $batch_size );
			}

			$seen_items = array();
			foreach ( $content_items as $item ) {
				if ( is_array( $item ) ) {
					$seen_items[ $this->content_item_key( $item ) ] = true;
				}
			}

			$processed = 0;
			foreach ( $posts as $post ) {
				if ( $processed > 0 && microtime( true ) - $started_at >= $time_limit ) {
					break;
				}

				$found_items = $this->find_shortcodes_and_blocks_in_content( $post, $plugins );
				foreach ( $found_items as $occurrence => $item ) {
					$item['scan_occurrence'] = (int) $occurrence;
					$item_key = $this->content_item_key( $item );
					if ( ! isset( $seen_items[ $item_key ] ) ) {
						$content_items[] = $item;
						$seen_items[ $item_key ] = true;
					}
				}

				$progress['cursor'] = (int) $post->ID;
				$progress['scanned']++;
				$processed++;
			}

			$progress['started'] = true;
			$progress['complete'] = ! $has_more_posts && $processed === count( $posts );

			$this->persist_scan_state( $content_items, $progress );

			return $progress;
		}

		/**
		 * Get persisted content scan progress.
		 *
		 * @return array
		 */
		function get_scan_progress() {
			$state = $this->get_scan_state();
			return $state['progress'];
		}

		/**
		 * Get the atomic content scan state, importing legacy separate settings when present.
		 *
		 * @return array
		 */
		private function get_scan_state() {
			$state = $this->get_setting( $this->type . '_scan_state', false );
			if ( is_array( $state ) && isset( $state['items'], $state['progress'] ) && is_array( $state['items'] ) && is_array( $state['progress'] ) ) {
				$state['progress'] = array_merge( $this->default_scan_progress(), $state['progress'] );
				return $state;
			}

			$legacy_items = $this->get_setting( $this->type, false );
			if ( is_array( $legacy_items ) ) {
				$legacy_progress = $this->get_setting( $this->type . '_scan_progress', array() );
				if ( ! is_array( $legacy_progress ) || empty( $legacy_progress ) ) {
					$legacy_progress = array(
						'started' => true,
						'complete' => true,
					);
				}
				return array(
					'items' => $legacy_items,
					'progress' => array_merge( $this->default_scan_progress(), $legacy_progress ),
				);
			}

			return array(
				'items' => array(),
				'progress' => $this->default_scan_progress(),
			);
		}

		/**
		 * Get current discovered content items.
		 *
		 * @return array
		 */
		private function get_content_items() {
			$state = $this->get_scan_state();
			return $state['items'];
		}

		/**
		 * Update discovered content items without losing the saved scan position.
		 *
		 * @param array $content_items Content findings.
		 * @return void
		 */
		private function persist_content_items( $content_items ) {
			$state = $this->get_scan_state();
			$this->persist_scan_state( $content_items, $state['progress'] );
		}

		/**
		 * Return a new content scan progress record.
		 *
		 * @return array
		 */
		private function default_scan_progress() {
			return array(
				'started' => false,
				'cursor' => 0,
				'scanned' => 0,
				'complete' => false,
			);
		}

		/**
		 * Persist findings and cursor together so a failed write cannot skip content.
		 *
		 * @param array $content_items Content findings.
		 * @param array $progress Scan progress.
		 * @return void
		 * @throws \RuntimeException When the state cannot be persisted.
		 */
		private function persist_scan_state( $content_items, $progress ) {
			$persisted = $this->set_setting(
				$this->type . '_scan_state',
				array(
					'items' => $content_items,
					'progress' => $progress,
				)
			);
			if ( false === $persisted ) {
				throw new \RuntimeException( 'Unable to persist content scan progress.' );
			}
		}

		/**
		 * Build a stable key so retried batches cannot duplicate discovered items.
		 *
		 * @param array $item Content item.
		 * @return string
		 */
		private function content_item_key( $item ) {
			return md5(
				implode(
					'|',
					array(
						isset( $item['post_id'] ) ? (int) $item['post_id'] : 0,
						isset( $item['plugin_name'] ) ? $item['plugin_name'] : '',
						isset( $item['type'] ) ? $item['type'] : '',
						isset( $item['original_content'] ) ? $item['original_content'] : '',
						isset( $item['match_offset'] ) ? (int) $item['match_offset'] : 0,
						isset( $item['scan_occurrence'] ) ? (int) $item['scan_occurrence'] : 0,
					)
				)
			);
		}

		/**
		 * Find shortcodes and blocks in a post's content.
		 *
		 * @param object $post WordPress post object
		 * @param array $plugins Array of plugin objects
		 * @return array
		 */
		private function find_shortcodes_and_blocks_in_content( $post, $plugins ) {
			$found_items = array();

			if ( empty( $post->post_content ) ) {
				return $found_items;
			}

			$all_blocks = array();
			if ( function_exists( 'parse_blocks' ) ) {
				$blocks = parse_blocks( $post->post_content );
				if ( ! empty( $blocks ) && is_array( $blocks ) ) {
					$all_blocks = $this->get_all_blocks_recursive( $blocks );
				}
			}

			$is_inside_block = function( $position ) use ( $post ) {
				$content_before = substr( $post->post_content, 0, $position );
				if ( preg_match_all( '/<!--\s*wp:([^\s]+)\s+([^>]*)-->/', $content_before, $all_opens, PREG_OFFSET_CAPTURE ) ) {
					$last_idx = count( $all_opens[0] ) - 1;
					$open_pos = $all_opens[0][$last_idx][1];
					$block_name = $all_opens[1][$last_idx][0];

					if ( $block_name === 'html' || $block_name === 'core/html' ||
						 $block_name === 'shortcode' || $block_name === 'core/shortcode' ||
						 strpos( $block_name, '/html' ) !== false || strpos( $block_name, '/shortcode' ) !== false ) {
						return false;
					}

					$content_after_open = substr( $post->post_content, $open_pos );
					$escaped_block_name = preg_quote( $block_name, '/' );
					if ( preg_match( '/<!--\s*\/wp:' . $escaped_block_name . '\s*-->/', $content_after_open, $close_match, PREG_OFFSET_CAPTURE ) ) {
						$close_pos = $open_pos + $close_match[0][1] + strlen( $close_match[0][0] );
						if ( $position >= $open_pos && $position < $close_pos ) {
							return true;
						}
					}
				}
				return false;
			};

			foreach ( $plugins as $plugin ) {
				if ( ! is_object( $plugin ) || ! $plugin->is_detected ) {
					continue;
				}

				try {
					if ( method_exists( $plugin, 'find_content_occurrences' ) ) {
						$occurrences = $plugin->find_content_occurrences( $post );
						foreach ( $occurrences as $occurrence ) {
							if ( empty( $occurrence['source_id'] ) || empty( $occurrence['original_content'] ) ) {
								continue;
							}

							$item = $this->create_content_item(
								$post,
								$plugin,
								$occurrence['source_id'],
								$occurrence['type'],
								$occurrence['original_content'],
								isset( $occurrence['match_offset'] ) ? (int) $occurrence['match_offset'] : 0,
								isset( $occurrence['block_name'] ) ? $occurrence['block_name'] : ''
							);
							if ( ! empty( $occurrence['source_context'] ) ) {
								$item['source_context'] = $occurrence['source_context'];
							}
							if ( ! empty( $occurrence['attributes'] ) ) {
								$item['attributes'] = $occurrence['attributes'];
							}
							if ( isset( $occurrence['match_offset'] ) ) {
								$item['match_offset'] = (int) $occurrence['match_offset'];
							}
							$found_items[] = $item;
						}
					}

					$block_patterns = $plugin->get_block_patterns();
					if ( ! empty( $block_patterns ) && is_array( $block_patterns ) && ! empty( $all_blocks ) ) {
						foreach ( $block_patterns as $block_name => $pattern ) {
							if ( empty( $block_name ) || ! is_string( $block_name ) ) {
								continue;
							}

							foreach ( $all_blocks as $block ) {
								if ( isset( $block['blockName'] ) && $block['blockName'] === $block_name ) {
									$gallery_id = $this->extract_gallery_id_from_block( $block, $plugin );

									if ( $gallery_id ) {
										$found_items[] = $this->create_content_item( $post, $plugin, $gallery_id, 'block', serialize_block( $block ), 0, $block_name );
									}
								}
							}
						}
					}

					$shortcode_patterns = $plugin->get_shortcode_patterns();
					if ( ! empty( $shortcode_patterns ) && is_array( $shortcode_patterns ) ) {
						$matched_shortcodes = array();
						foreach ( $shortcode_patterns as $pattern ) {
							if ( empty( $pattern ) || ! is_string( $pattern ) ) {
								continue;
							}
							$matches = array();
							$result = preg_match_all( $pattern, $post->post_content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
							if ( $result !== false && $result > 0 ) {
								foreach ( $matches as $match ) {
									if ( isset( $match[0] ) && is_array( $match[0] ) ) {
										$match_offset = isset( $match[0][1] ) ? $match[0][1] : 0;
										$match_content = isset( $match[0][0] ) ? $match[0][0] : '';
										$match_key = $plugin->name() . '|' . $match_offset . '|' . $match_content;

										if ( isset( $matched_shortcodes[ $match_key ] ) ) {
											continue;
										}
										$matched_shortcodes[ $match_key ] = true;

										if ( ! $is_inside_block( $match_offset ) ) {
											$direct_replacement_item = $this->create_direct_replacement_content_item( $post, $plugin, 'shortcode', $match_content, $match_offset );
											if ( $direct_replacement_item ) {
												$found_items[] = $direct_replacement_item;
												continue;
											}

											$gallery_id = $this->extract_gallery_id_from_match( $match, $plugin );
											if ( $gallery_id ) {
												$found_items[] = $this->create_content_item( $post, $plugin, $gallery_id, 'shortcode', $match_content, $match_offset );
											}
										}
									}
								}
							}
						}
					}
				} catch ( \Exception $e ) {
					throw $e;
				}
			}

			return $found_items;
		}

		/**
		 * Create a content item array.
		 *
		 * @param object $post WordPress post object
		 * @param object $plugin Plugin object
		 * @param int $gallery_id Gallery ID
		 * @param string $type Content type ('block' or 'shortcode')
		 * @param string $original_content Original content
		 * @param int $match_offset Match offset (for shortcodes)
		 * @param string $block_name Block name (for blocks)
		 * @return array
		 */
		private function create_content_item( $post, $plugin, $gallery_id, $type, $original_content, $match_offset = 0, $block_name = '' ) {
			$object_type = 'gallery';
			if ( is_object( $plugin ) && method_exists( $plugin, 'get_content_object_type' ) ) {
				$object_type = $plugin->get_content_object_type( $original_content, $block_name );
			}
			if ( ! in_array( $object_type, array( 'album', 'gallery', 'image', 'dynamic_gallery' ), true ) ) {
				$object_type = 'gallery';
			}

			$item = array(
				'post_id' => (int) $post->ID,
				'post_title' => $post->post_title,
				'post_type' => $post->post_type,
				'plugin_name' => $plugin->name(),
				'gallery_id' => is_numeric( $gallery_id ) ? (int) $gallery_id : ( is_scalar( $gallery_id ) ? (string) $gallery_id : '' ),
				'object_type' => $object_type,
				'type' => $type,
				'original_content' => $original_content,
				'migrated' => $this->is_gallery_migrated( $plugin, $gallery_id, $object_type ),
				'migrated_foogallery_id' => $this->get_migrated_foogallery_id( $plugin, $gallery_id, $object_type ),
			);

			if ( $type === 'block' && $block_name ) {
				$item['block_name'] = $block_name;
			}

			if ( $type === 'shortcode' ) {
				$item['match_offset'] = $match_offset;
			}

			return $item;
		}

		/**
		 * Create a content item that can be replaced without a migrated FooGallery object.
		 *
		 * @param object $post WordPress post object.
		 * @param object $plugin Plugin object.
		 * @param string $type Content type ('block' or 'shortcode').
		 * @param string $original_content Original content.
		 * @param int $match_offset Match offset (for shortcodes).
		 * @param string $block_name Block name (for blocks).
		 * @return array|false Content item, or false when no direct replacement is available.
		 */
		private function create_direct_replacement_content_item( $post, $plugin, $type, $original_content, $match_offset = 0, $block_name = '' ) {
			if ( ! is_object( $plugin ) || ! method_exists( $plugin, 'get_content_replacement_content' ) ) {
				return false;
			}

			$replacement_content = $plugin->get_content_replacement_content( $original_content, $block_name );
			if ( ! is_string( $replacement_content ) || '' === trim( $replacement_content ) ) {
				return false;
			}

			$object_type = 'gallery';
			if ( method_exists( $plugin, 'get_content_object_type' ) ) {
				$object_type = $plugin->get_content_object_type( $original_content, $block_name );
			}
			if ( ! in_array( $object_type, array( 'album', 'gallery', 'image', 'dynamic_gallery' ), true ) ) {
				$object_type = 'gallery';
			}

			$item = array(
				'post_id' => (int) $post->ID,
				'post_title' => $post->post_title,
				'post_type' => $post->post_type,
				'plugin_name' => $plugin->name(),
				'gallery_id' => 0,
				'object_type' => $object_type,
				'type' => $type,
				'original_content' => $original_content,
				'migrated' => false,
				'migrated_foogallery_id' => false,
				'replacement_content' => $replacement_content,
			);

			if ( $type === 'block' && $block_name ) {
				$item['block_name'] = $block_name;
			}

			if ( $type === 'shortcode' ) {
				$item['match_offset'] = $match_offset;
			}

			return $item;
		}

		/**
		 * Extract gallery ID from a regex match.
		 *
		 * @param array $match Regex match array
		 * @param object $plugin Plugin object
		 * @return int|false
		 */
		private function extract_gallery_id_from_match( $match, $plugin ) {
			$extracted_id = false;

			$id = $this->get_first_numeric_match( $match );
			if ( false !== $id ) {
				$extracted_id = $id;
			}

			if ( ! $extracted_id && isset( $match[0] ) && is_array( $match[0] ) && isset( $match[0][0] ) ) {
				$full_match = $match[0][0];
				if ( preg_match( '/\bid=["\']?(\d+)["\']?/', $full_match, $id_match ) ) {
					$extracted_id = (int) $id_match[1];
				}
				if ( ! $extracted_id && preg_match( '/\bids=["\']?(\d+)["\']?/', $full_match, $id_match ) ) {
					$extracted_id = (int) $id_match[1];
				}
				if ( ! $extracted_id && preg_match( '/gallery_ids=["\']?(\d+)["\']?/', $full_match, $id_match ) ) {
					$extracted_id = (int) $id_match[1];
				}
				if ( ! $extracted_id && preg_match( '/\s+(\d+)/', $full_match, $id_match ) ) {
					$extracted_id = (int) $id_match[1];
				}
			}

			if ( $extracted_id && $plugin->name() === '10Web' ) {
				$resolved_id = $this->resolve_10web_gallery_id_from_shortcode( $extracted_id );
				return $resolved_id ? $resolved_id : $extracted_id;
			}

			return $extracted_id ? $extracted_id : false;
		}

		/**
		 * Extract ID from block content (innerContent, innerHTML, or serialized).
		 *
		 * @param array $content_array Array of content strings to search in
		 * @param object $plugin Plugin object
		 * @return int|false The extracted ID or false if not found
		 */
		private function extract_id_from_block_content( $content_array, $plugin ) {
			foreach ( $content_array as $content ) {
				if ( ! is_string( $content ) || empty( $content ) ) {
					continue;
				}

				if ( preg_match( '/\[ngg(?:allery)?[^\]]*ids=["\']?(\d+)["\']?/', $content, $matches ) ) {
					if ( isset( $matches[1] ) && is_numeric( $matches[1] ) ) {
						return (int) $matches[1];
					}
				}
				if ( preg_match( '/\[(?:nggallery|ngg|ngg_images)[^\]]*id\s*=\s*(?:"(\d+)"|\'(\d+)\'|(\d+)(?=[\s\]]))/', $content, $matches ) ) {
					$id = $this->get_first_numeric_match( $matches );
					if ( false !== $id ) {
						return $id;
					}
				}

				$temp_id = $this->extract_id_from_10web_shortcode( $content );
				if ( $temp_id ) {
					if ( $plugin->name() === '10Web' ) {
						return $this->resolve_10web_gallery_id_from_shortcode( $temp_id );
					}
					return $temp_id;
				}

				if ( preg_match( '/(?:data-)?(?:gallery-)?id\s*=\s*(?:"(\d+)"|\'(\d+)\'|(\d+)(?=[\s\]]))/', $content, $matches ) ) {
					$id = $this->get_first_numeric_match( $matches );
					if ( false !== $id ) {
						return $id;
					}
				}
			}
			return false;
		}

		/**
		 * Return the first numeric capture from preg_match or preg_match_all data.
		 *
		 * @param array $matches Regex match data.
		 * @return int|false
		 */
		private function get_first_numeric_match( $matches ) {
			foreach ( $matches as $index => $match ) {
				if ( 0 === $index ) {
					continue;
				}

				$value = is_array( $match ) && isset( $match[0] ) ? $match[0] : $match;
				if ( is_numeric( $value ) ) {
					return (int) $value;
				}
			}

			return false;
		}

		/**
		 * Extract ID from 10Web Photo Gallery shortcode patterns.
		 *
		 * @param string $content Content to search in
		 * @return int|false The extracted ID or false if not found
		 */
		private function extract_id_from_10web_shortcode( $content ) {
			if ( preg_match( '/\[(?:Best_Wordpress_Gallery|bwg)[^\]]*id=["\']?(\d+)["\']?/', $content, $matches ) ) {
				if ( isset( $matches[1] ) && is_numeric( $matches[1] ) ) {
					return (int) $matches[1];
				}
			}
			return false;
		}

		/**
		 * Resolve gallery_id from shortcode_id for 10Web Photo Gallery.
		 *
		 * @param int $shortcode_id Shortcode ID from the shortcode
		 * @return int|false The actual gallery_id or false if not found
		 */
		private function resolve_10web_gallery_id_from_shortcode( $shortcode_id ) {
			global $wpdb;

			$shortcode_table = $wpdb->prefix . Photo::FM_PHOTO_TABLE_SHORTCODE;

			$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$shortcode_table}'" );

			if ( ! $table_exists ) {
				return (int) $shortcode_id;
			}

			$shortcode_data = $wpdb->get_row( $wpdb->prepare(
				"SELECT tagtext FROM {$shortcode_table} WHERE id = %d",
				$shortcode_id
			) );

			if ( ! $shortcode_data || empty( $shortcode_data->tagtext ) ) {
				return (int) $shortcode_id;
			}

			$tagtext = $shortcode_data->tagtext;

			if ( preg_match( '/gallery_id\s*=\s*["\']?(\d+)["\']?/', $tagtext, $matches ) ) {
				return (int) $matches[1];
			}

			if ( preg_match( '/\bid\s*=\s*["\']?(\d+)["\']?/', $tagtext, $matches ) ) {
				return (int) $matches[1];
			}

			if ( preg_match( '/\[Best_Wordpress_Gallery[^\]]*id=["\']?(\d+)["\']?/', $tagtext, $matches ) ) {
				return (int) $matches[1];
			}
			if ( preg_match( '/\[bwg[^\]]*id=["\']?(\d+)["\']?/', $tagtext, $matches ) ) {
				return (int) $matches[1];
			}

			return (int) $shortcode_id;
		}

		/**
		 * Extract gallery ID from a Gutenberg block.
		 *
		 * @param array $block Block array
		 * @param object $plugin Plugin object
		 * @return int|false
		 */
		private function extract_gallery_id_from_block( $block, $plugin ) {
			$extracted_id = false;

			if ( isset( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
				$attrs = $block['attrs'];
				$id_keys = array( 'id', 'galleryId', 'galleryID', 'galleryid', 'gallery_id', 'gallery', 'gid', 'shortcode_id' );

				foreach ( $id_keys as $key ) {
					if ( isset( $attrs[ $key ] ) ) {
						$id = $attrs[ $key ];
						if ( is_numeric( $id ) ) {
							$extracted_id = (int) $id;
							break;
						}
						if ( is_array( $id ) && isset( $id['id'] ) && is_numeric( $id['id'] ) ) {
							$extracted_id = (int) $id['id'];
							break;
						}
						if ( is_string( $id ) && is_numeric( $id ) ) {
							$extracted_id = (int) $id;
							break;
						}
					}
				}
			}

			if ( ! $extracted_id && isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
				$extracted_id = $this->extract_id_from_block_content( $block['innerContent'], $plugin );
			}

			if ( ! $extracted_id && isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) && ! empty( $block['innerHTML'] ) ) {
				$extracted_id = $this->extract_id_from_block_content( array( $block['innerHTML'] ), $plugin );
				if ( $extracted_id ) {
					return $extracted_id;
				}
			}

			if ( function_exists( 'serialize_block' ) ) {
				$serialized = serialize_block( $block );

				if ( preg_match( '/["\']?(?:id|galleryId|galleryID|galleryid|gallery_id|gid)["\']?\s*:\s*(?:"(\d+)"|\'(\d+)\'|(\d+)(?=[,\}\]]))/', $serialized, $matches ) ) {
					$id = 0;
					if ( isset( $matches[1] ) && is_numeric( $matches[1] ) ) {
						$id = (int) $matches[1];
					} else if ( isset( $matches[2] ) && is_numeric( $matches[2] ) ) {
						$id = (int) $matches[2];
					} else if ( isset( $matches[3] ) && is_numeric( $matches[3] ) ) {
						$id = (int) $matches[3];
					}
					if ( $id > 0 ) {
						return $id;
					}
				}

				$extracted_id = $this->extract_id_from_block_content( array( $serialized ), $plugin );
				if ( $extracted_id ) {
					return $extracted_id;
				}
				if ( preg_match( '/["\']?shortcode_id["\']?\s*:\s*(?:"(\d+)"|\'(\d+)\'|(\d+)(?=[,\}\]]))/', $serialized, $matches ) ) {
					$id = 0;
					if ( isset( $matches[1] ) && is_numeric( $matches[1] ) ) {
						$id = (int) $matches[1];
					} else if ( isset( $matches[2] ) && is_numeric( $matches[2] ) ) {
						$id = (int) $matches[2];
					} else if ( isset( $matches[3] ) && is_numeric( $matches[3] ) ) {
						$id = (int) $matches[3];
					}
					if ( $id > 0 ) {
						return $id;
					}
				}
			}

			if ( $extracted_id && $plugin->name() === '10Web' ) {
				$resolved_id = $this->resolve_10web_gallery_id_from_shortcode( $extracted_id );
				if ( $resolved_id ) {
					return $resolved_id;
				}
			}

			return $extracted_id ? $extracted_id : false;
		}

		/**
		 * Recursively get all blocks including nested ones.
		 *
		 * @param array $blocks Array of blocks
		 * @return array Flat array of all blocks
		 */
		private function get_all_blocks_recursive( $blocks ) {
			$all_blocks = array();

			foreach ( $blocks as $block ) {
				if ( ! empty( $block['blockName'] ) ) {
					$all_blocks[] = $block;
				}

				if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
					$all_blocks = array_merge( $all_blocks, $this->get_all_blocks_recursive( $block['innerBlocks'] ) );
				}
			}

			return $all_blocks;
		}

		/**
		 * Find migrated object matching plugin and source ID.
		 *
		 * @param object $plugin Plugin object.
		 * @param int $gallery_id Gallery, album, or image ID from the source plugin.
		 * @param string $object_type Source object type.
		 * @return object|false Migrated object or false if not found.
		 */
		private function find_migrated_object( $plugin, $gallery_id, $object_type = 'gallery' ) {
			$migrated_objects = $this->migrator_engine->get_migrated_objects();
			$gallery_id = (string) $gallery_id;
			$plugin_name = $plugin->name();
			if ( ! in_array( $object_type, array( 'album', 'gallery', 'image' ), true ) ) {
				$object_type = 'gallery';
			}

			if ( 'image' === $object_type ) {
				return $this->find_migrated_image_object( $plugin, $gallery_id );
			}

			foreach ( $migrated_objects as $migrated_object ) {
				if ( $migrated_object->type() !== $object_type ) {
					continue;
				}

				if ( ! isset( $migrated_object->plugin ) || ! is_object( $migrated_object->plugin ) ) {
					continue;
				}

				$migrated_plugin_name = $migrated_object->plugin->name();
				if ( $migrated_plugin_name !== $plugin_name ) {
					continue;
				}

				$migrated_id = isset( $migrated_object->ID ) ? (string) $migrated_object->ID : '';

				if ( $migrated_id !== $gallery_id ) {
					continue;
				}

				if ( ! isset( $migrated_object->migrated ) || ! $migrated_object->migrated ) {
					continue;
				}

				if ( ! isset( $migrated_object->migrated_id ) || (int) $migrated_object->migrated_id <= 0 ) {
					continue;
				}

				return $migrated_object;
			}

			return false;
		}

		/**
		 * Find a migrated image object by resolving the source image ID to its unique identifier.
		 *
		 * @param object $plugin Plugin object.
		 * @param int $image_id Image ID from the source plugin.
		 * @return object|false Migrated image object or false if not found.
		 */
		private function find_migrated_image_object( $plugin, $image_id ) {
			if ( ! is_object( $plugin ) || ! method_exists( $plugin, 'get_content_image_identifier' ) ) {
				return false;
			}

			$identifier = $plugin->get_content_image_identifier( $image_id );
			if ( ! is_string( $identifier ) || '' === $identifier ) {
				return false;
			}

			$migrated_objects = $this->migrator_engine->get_migrated_objects();
			if ( ! isset( $migrated_objects[ $identifier ] ) || ! is_object( $migrated_objects[ $identifier ] ) ) {
				return false;
			}

			$migrated_object = $migrated_objects[ $identifier ];
			if ( ! method_exists( $migrated_object, 'type' ) || 'image' !== $migrated_object->type() ) {
				return false;
			}

			if ( ! isset( $migrated_object->migrated ) || ! $migrated_object->migrated ) {
				return false;
			}

			if ( ! isset( $migrated_object->migrated_id ) || (int) $migrated_object->migrated_id <= 0 ) {
				return false;
			}

			return $migrated_object;
		}

		/**
		 * Check if a gallery has been migrated.
		 *
		 * @param object $plugin Plugin object
		 * @param int $gallery_id Gallery ID
		 * @return bool
		 */
		private function is_gallery_migrated( $plugin, $gallery_id, $object_type = 'gallery' ) {
			return $this->find_migrated_object( $plugin, $gallery_id, $object_type ) !== false;
		}

		/**
		 * Get the migrated FooGallery ID for a gallery.
		 *
		 * @param object $plugin Plugin object
		 * @param int $gallery_id Gallery ID
		 * @return int|false
		 */
		private function get_migrated_foogallery_id( $plugin, $gallery_id, $object_type = 'gallery' ) {
			$migrated_object = $this->find_migrated_object( $plugin, $gallery_id, $object_type );
			return $migrated_object ? (int) $migrated_object->migrated_id : false;
		}

		/**
		 * Build replacement markup for a migrated single image shortcode.
		 *
		 * @param array $item Content migration item.
		 * @return string Replacement markup.
		 */
		private function build_image_replacement_content( $item ) {
			$attachment_id = ! empty( $item['migrated_foogallery_id'] ) ? absint( $item['migrated_foogallery_id'] ) : 0;
			if ( $attachment_id < 1 ) {
				return '';
			}

			$attributes = $this->parse_shortcode_attributes( isset( $item['original_content'] ) ? $item['original_content'] : '' );
			$image_attributes = array();
			$align = $this->get_shortcode_alignment( $attributes );
			$caption = $this->get_attachment_caption_text( $attachment_id );

			$width = $this->get_shortcode_dimension( $attributes, array( 'w', 'width' ) );
			if ( $width > 0 ) {
				$image_attributes['width'] = $width;
			}

			$height = $this->get_shortcode_dimension( $attributes, array( 'h', 'height' ) );
			if ( $height > 0 ) {
				$image_attributes['height'] = $height;
			}

			foreach ( array( 'alt', 'alttext' ) as $alt_key ) {
				if ( isset( $attributes[ $alt_key ] ) && is_scalar( $attributes[ $alt_key ] ) && '' !== (string) $attributes[ $alt_key ] ) {
					$image_attributes['alt'] = (string) $attributes[ $alt_key ];
					break;
				}
			}

			if ( '' === $caption && 'alignnone' !== $align ) {
				$image_attributes['class'] = $align;
			}

			$image_markup = '';
			if ( function_exists( 'wp_get_attachment_image' ) ) {
				$image_size = ( $width > 0 || $height > 0 ) ? 'full' : 'thumbnail';
				$markup = wp_get_attachment_image( $attachment_id, $image_size, false, $image_attributes );
				if ( is_string( $markup ) && '' !== $markup ) {
					$image_markup = $markup;
				}
			}

			if ( ! function_exists( 'wp_get_attachment_url' ) ) {
				return '';
			}

			$url = wp_get_attachment_url( $attachment_id );
			if ( empty( $url ) ) {
				return '';
			}

			if ( '' === $image_markup ) {
				$classes = array( 'wp-image-' . $attachment_id );
				if ( '' === $caption && 'alignnone' !== $align ) {
					$classes[] = $align;
				}
				$image_attributes['class'] = implode( ' ', $classes );

				$attribute_strings = array(
					'src="' . esc_url( $url ) . '"',
				);

				foreach ( $image_attributes as $name => $value ) {
					if ( is_scalar( $value ) && '' !== (string) $value ) {
						$attribute_strings[] = esc_attr( $name ) . '="' . esc_attr( $value ) . '"';
					}
				}

				$image_markup = '<img ' . implode( ' ', $attribute_strings ) . ' />';
			}

			$image_content = '<a href="' . esc_url( $url ) . '">' . $image_markup . '</a>';
			if ( '' === $caption ) {
				return $image_content;
			}

			$caption_width = $this->get_caption_width( $width, $image_markup );
			if ( $caption_width < 1 ) {
				return $image_content;
			}

			return '[caption id="attachment_' . $attachment_id . '" align="' . esc_attr( $align ) . '" width="' . $caption_width . '"]' . $image_content . ' ' . $caption . '[/caption]';
		}

		/**
		 * Get the WordPress alignment class matching a legacy shortcode's float value.
		 *
		 * @param array $attributes Parsed shortcode attributes.
		 * @return string WordPress alignment class.
		 */
		private function get_shortcode_alignment( $attributes ) {
			if ( isset( $attributes['float'] ) && is_scalar( $attributes['float'] ) ) {
				switch ( sanitize_key( $attributes['float'] ) ) {
					case 'center':
						return 'aligncenter';
					case 'left':
						return 'alignleft';
					case 'right':
						return 'alignright';
				}
			}

			return 'alignnone';
		}

		/**
		 * Get caption text for an attachment, falling back to description for older migrations.
		 *
		 * @param int $attachment_id Attachment post ID.
		 * @return string Caption text.
		 */
		private function get_attachment_caption_text( $attachment_id ) {
			$caption = '';

			if ( function_exists( 'wp_get_attachment_caption' ) ) {
				$caption = wp_get_attachment_caption( $attachment_id );
			}

			if ( ! is_scalar( $caption ) || '' === trim( (string) $caption ) ) {
				$post = get_post( $attachment_id );
				if ( is_object( $post ) ) {
					if ( isset( $post->post_excerpt ) && '' !== trim( (string) $post->post_excerpt ) ) {
						$caption = $post->post_excerpt;
					} else if ( isset( $post->post_content ) && '' !== trim( (string) $post->post_content ) ) {
						$caption = $post->post_content;
					}
				}
			}

			if ( ! is_scalar( $caption ) || '' === trim( (string) $caption ) ) {
				return '';
			}

			$caption = trim( (string) $caption );
			if ( function_exists( 'wp_kses_post' ) ) {
				return wp_kses_post( $caption );
			}

			return strip_tags( $caption );
		}

		/**
		 * Get the caption shortcode width from explicit shortcode dimensions or image markup.
		 *
		 * @param int $width Explicit shortcode width.
		 * @param string $image_markup Image HTML.
		 * @return int Caption width.
		 */
		private function get_caption_width( $width, $image_markup ) {
			if ( $width > 0 ) {
				return $width;
			}

			if ( is_string( $image_markup ) && preg_match( '/\swidth=["\']?(\d+)["\']?/', $image_markup, $matches ) ) {
				return absint( $matches[1] );
			}

			return 0;
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
				return is_array( $attributes ) ? $attributes : array();
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
		 * Read the first positive integer dimension from shortcode attributes.
		 *
		 * @param array $attributes Parsed shortcode attributes.
		 * @param array $keys Attribute keys to inspect.
		 * @return int Positive dimension or zero.
		 */
		private function get_shortcode_dimension( $attributes, $keys ) {
			foreach ( $keys as $key ) {
				if ( isset( $attributes[ $key ] ) && is_scalar( $attributes[ $key ] ) ) {
					$value = absint( $attributes[ $key ] );
					if ( $value > 0 ) {
						return $value;
					}
				}
			}

			return 0;
		}

		/**
		 * Returns true when a content item has everything needed for replacement.
		 *
		 * @param array $item Content migration item.
		 * @return bool
		 */
		private function is_content_item_replaceable( $item ) {
			if ( ! is_array( $item ) ) {
				return false;
			}

			if ( ! empty( $item['migrated'] ) && ! empty( $item['migrated_foogallery_id'] ) ) {
				return true;
			}

			return isset( $item['replacement_content'] ) && is_string( $item['replacement_content'] ) && '' !== trim( $item['replacement_content'] );
		}

		/**
		 * Get a display label for a content item object type.
		 *
		 * @param array $item Content migration item.
		 * @return string
		 */
		private function get_content_item_object_type_label( $item ) {
			$object_type = isset( $item['object_type'] ) ? $item['object_type'] : 'gallery';

			if ( 'album' === $object_type ) {
				return __( 'Album', 'foogallery-migrate' );
			}

			if ( 'image' === $object_type ) {
				return __( 'Image', 'foogallery-migrate' );
			}

			if ( 'dynamic_gallery' === $object_type ) {
				return __( 'Dynamic Gallery', 'foogallery-migrate' );
			}

			return __( 'Gallery', 'foogallery-migrate' );
		}

		/**
		 * Replace shortcodes/blocks in selected posts.
		 *
		 * @param array $selected_items Array of content item keys to replace
		 * @param array $expected_post_contents Optional post content snapshots captured before entity migration.
		 * @return array Results with success count and errors
		 */
		function replace_content( $selected_items, $expected_post_contents = array() ) {
			$content_items = $this->get_content_items();
			$replaced_count = 0;
			$errors = array();

			$posts_to_update = array();

			foreach ( $selected_items as $item_key ) {
				if ( ! isset( $content_items[ $item_key ] ) ) {
					continue;
				}

				$item = $content_items[ $item_key ];
				$object_type = isset( $item['object_type'] ) && in_array( $item['object_type'], array( 'album', 'gallery', 'image', 'dynamic_gallery' ), true ) ? $item['object_type'] : 'gallery';
				$has_direct_replacement = isset( $item['replacement_content'] ) && is_string( $item['replacement_content'] ) && '' !== trim( $item['replacement_content'] );

				if ( ! $this->is_content_item_replaceable( $item ) ) {
					$errors[] = sprintf(
						__( '%1$s %2$s from %3$s in post "%4$s" has not been migrated yet.', 'foogallery-migrate' ),
						$this->get_content_item_object_type_label( $item ),
						$item['gallery_id'],
						$item['plugin_name'],
						$item['post_title']
					);
					continue;
				}

				$post_id = $item['post_id'];
				if ( ! isset( $posts_to_update[ $post_id ] ) ) {
					$post = get_post( $post_id );
					if ( ! $post || 'publish' !== $post->post_status || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
						$errors[] = sprintf(
							__( 'Published post or page %d not found.', 'foogallery-migrate' ),
							$post_id
						);
						continue;
					}
					if ( isset( $expected_post_contents[ $post_id ] ) && $post->post_content !== $expected_post_contents[ $post_id ] ) {
						$errors[] = sprintf(
							/* translators: %s: post title. */
							__( 'Post or page "%s" changed during migration and was not updated. Refresh the scan and try again.', 'foogallery-migrate' ),
							$post->post_title
						);
						continue;
					}
					$posts_to_update[ $post_id ] = array(
						'post' => $post,
						'content' => $post->post_content,
						'replacements' => array(),
					);
				}

				$new_content = '';
				if ( $has_direct_replacement ) {
					$new_content = $item['replacement_content'];
				} else if ( 'image' === $object_type ) {
					$new_content = $this->build_image_replacement_content( $item );
				} else if ( $item['type'] === 'shortcode' ) {
					if ( 'album' === $object_type ) {
						$new_content = '[foogallery-album id="' . $item['migrated_foogallery_id'] . '"]';
					} else {
						$new_content = '[foogallery id="' . $item['migrated_foogallery_id'] . '"]';
					}
				} else if ( $item['type'] === 'block' ) {
					if ( 'album' === $object_type ) {
						$new_content = '<!-- wp:shortcode -->[foogallery-album id="' . $item['migrated_foogallery_id'] . '"]<!-- /wp:shortcode -->';
					} else {
						$new_content = '<!-- wp:fooplugins/foogallery {"id":' . $item['migrated_foogallery_id'] . '} /-->';
					}
				}

				if ( $new_content ) {
					$posts_to_update[ $post_id ]['replacements'][] = array(
						'old' => $item['original_content'],
						'new' => $new_content,
						'offset' => isset( $item['match_offset'] ) ? (int) $item['match_offset'] : false,
					);
				}
			}

			foreach ( $posts_to_update as $post_id => $post_data ) {
				$updated_content = $post_data['content'];
				usort( $post_data['replacements'], array( $this, 'sort_replacements_descending' ) );
				$applied_count = 0;
				foreach ( $post_data['replacements'] as $replacement ) {
					$offset = $replacement['offset'];
					if ( false === $offset ) {
						$offset = strpos( $updated_content, $replacement['old'] );
					}

					if ( false === $offset || substr( $updated_content, $offset, strlen( $replacement['old'] ) ) !== $replacement['old'] ) {
						$errors[] = sprintf(
							/* translators: %s: post title. */
							__( 'The selected gallery occurrence in "%s" changed after detection and was not replaced. Refresh the scan and try again.', 'foogallery-migrate' ),
							$post_data['post']->post_title
						);
						continue;
					}

					$updated_content = substr_replace( $updated_content, $replacement['new'], $offset, strlen( $replacement['old'] ) );
					$applied_count++;
				}

				if ( 0 === $applied_count ) {
					continue;
				}

				$current_post = get_post( $post_id );
				if ( ! $current_post || 'publish' !== $current_post->post_status || ! in_array( $current_post->post_type, array( 'post', 'page' ), true ) || $current_post->post_content !== $post_data['content'] ) {
					$errors[] = sprintf(
						/* translators: %s: post title. */
						__( 'Post or page "%s" changed during migration and was not updated. Refresh the scan and try again.', 'foogallery-migrate' ),
						$post_data['post']->post_title
					);
					continue;
				}

				$result = wp_update_post( array(
					'ID' => $post_id,
					'post_content' => $updated_content,
				), true );

				if ( is_wp_error( $result ) ) {
					$errors[] = sprintf(
						__( 'Error updating post "%s": %s', 'foogallery-migrate' ),
						$post_data['post']->post_title,
						$result->get_error_message()
					);
				} else {
					$replaced_count += $applied_count;
				}
			}

			$this->set_setting( $this->type, false );
			$this->set_setting( $this->type . '_scan_state', false );

			return array(
				'success' => $replaced_count,
				'errors' => $errors,
			);
		}

		/**
		 * Sort exact replacements from the end of the post to the beginning.
		 *
		 * @param array $left Left replacement.
		 * @param array $right Right replacement.
		 * @return int
		 */
		function sort_replacements_descending( $left, $right ) {
			$left_offset = false === $left['offset'] ? -1 : (int) $left['offset'];
			$right_offset = false === $right['offset'] ? -1 : (int) $right['offset'];
			if ( $left_offset === $right_offset ) {
				return 0;
			}

			return ( $left_offset > $right_offset ) ? -1 : 1;
		}

		/**
		 * Migrate selected WP core gallery entities, then replace their occurrences.
		 *
		 * @param array $selected_items Content item keys.
		 * @return array
		 */
		function migrate_and_replace_content( $selected_items ) {
			$content_items = $this->get_content_items();
			if ( ! is_array( $content_items ) ) {
				$content_items = array();
			}
			$core_source_ids = array();
			$preflight_errors = array();
			$preflight_post_contents = array();
			$selected_item_keys = array_unique( array_map( 'absint', (array) $selected_items ) );

			foreach ( $selected_item_keys as $item_key ) {
				if ( ! isset( $content_items[ $item_key ] ) || ! is_array( $content_items[ $item_key ] ) ) {
					$preflight_errors[] = __( 'One or more selected content items are no longer available. Refresh the scan and try again.', 'foogallery-migrate' );
					continue;
				}

				$item = $content_items[ $item_key ];
				$post = isset( $item['post_id'] ) ? get_post( $item['post_id'] ) : false;
				$offset = isset( $item['match_offset'] ) ? (int) $item['match_offset'] : false;
				$original_content = isset( $item['original_content'] ) ? (string) $item['original_content'] : '';
				$is_eligible_post = $post && 'publish' === $post->post_status && in_array( $post->post_type, array( 'post', 'page' ), true );
				if ( ! $is_eligible_post || false === $offset || '' === $original_content || substr( $post->post_content, $offset, strlen( $original_content ) ) !== $original_content ) {
					$preflight_errors[] = sprintf(
						/* translators: %s: post title. */
						__( 'The selected gallery occurrence in "%s" changed after detection. Refresh the scan and try again.', 'foogallery-migrate' ),
						isset( $item['post_title'] ) ? $item['post_title'] : ''
					);
					continue;
				}
				$preflight_post_contents[ (int) $post->ID ] = $post->post_content;

				$is_core = isset( $item['plugin_name'] ) && 'WordPress Core' === $item['plugin_name'];
				if ( $is_core && ( empty( $item['migrated'] ) || empty( $item['migrated_foogallery_id'] ) ) ) {
					$core_source_ids[ (string) $item['gallery_id'] ] = true;
				} elseif ( ! $is_core && ( empty( $item['migrated'] ) || empty( $item['migrated_foogallery_id'] ) ) ) {
					$preflight_errors[] = sprintf(
						/* translators: %s: post title. */
						__( 'The selected gallery in "%s" has not been migrated yet.', 'foogallery-migrate' ),
						isset( $item['post_title'] ) ? $item['post_title'] : ''
					);
				}
			}

			if ( ! empty( $preflight_errors ) ) {
				return array(
					'success' => 0,
					'errors'  => $preflight_errors,
				);
			}

			if ( ! empty( $core_source_ids ) ) {
				$gallery_migrator = $this->migrator_engine->get_gallery_migrator();
				$galleries = $gallery_migrator->get_objects_to_migrate( true );
				$queue = array();
				$maximum_steps = 1;

				foreach ( $galleries as $gallery ) {
					if ( 'WordPress Core' !== $gallery->plugin->name() || ! isset( $core_source_ids[ (string) $gallery->ID ] ) ) {
						continue;
					}
					$queue[ $gallery->unique_identifier() ] = array(
						'id'       => $gallery->unique_identifier(),
						'migrated' => false,
						'current'  => false,
						'title'    => $gallery->title,
					);
					$maximum_steps += $gallery->get_children_count() + 1;
				}

				if ( count( $queue ) !== count( $core_source_ids ) ) {
					return array(
						'success' => 0,
						'errors'  => array( __( 'One or more selected core galleries changed after detection. Refresh the scan and try again.', 'foogallery-migrate' ) ),
					);
				}

				$gallery_migrator->queue_objects_for_migration( $queue );
				for ( $step = 0; $step < $maximum_steps; $step++ ) {
					$state = $gallery_migrator->get_state();
					if ( is_array( $state ) && isset( $state['queued'], $state['completed'] ) && (int) $state['queued'] === (int) $state['completed'] ) {
						break;
					}
					$gallery_migrator->migrate();
				}

				$state = $gallery_migrator->get_state();
				if ( ! is_array( $state ) || empty( $state['queued'] ) || (int) $state['completed'] !== (int) $state['queued'] ) {
					return array(
						'success' => 0,
						'errors'  => array( __( 'One or more core galleries could not be migrated. Review the Galleries tab for details.', 'foogallery-migrate' ) ),
					);
				}

				$migrated_core_ids = array();
				foreach ( $gallery_migrator->get_objects_to_migrate() as $gallery ) {
					$source_id = isset( $gallery->ID ) ? (string) $gallery->ID : '';
					if ( 'WordPress Core' === $gallery->plugin->name() && isset( $core_source_ids[ $source_id ] ) && ! empty( $gallery->migrated ) && ! empty( $gallery->migrated_id ) ) {
						$migrated_core_ids[ $source_id ] = (int) $gallery->migrated_id;
					}
				}

				if ( count( $migrated_core_ids ) !== count( $core_source_ids ) ) {
					return array(
						'success' => 0,
						'errors'  => array( __( 'One or more core galleries could not be verified after migration. Review the Galleries tab for details.', 'foogallery-migrate' ) ),
					);
				}

				foreach ( $content_items as $item_key => $item ) {
					$source_id = is_array( $item ) && isset( $item['gallery_id'] ) ? (string) $item['gallery_id'] : '';
					if ( isset( $migrated_core_ids[ $source_id ] ) && isset( $item['plugin_name'] ) && 'WordPress Core' === $item['plugin_name'] ) {
						$content_items[ $item_key ]['migrated'] = true;
						$content_items[ $item_key ]['migrated_foogallery_id'] = $migrated_core_ids[ $source_id ];
					}
				}
				$this->persist_content_items( $content_items );
			}

			return $this->replace_content( $selected_item_keys, $preflight_post_contents );
		}

		/**
		 * Render the content migration form.
		 *
		 * @return void
		 */
		function render_content_form() {
			$state = $this->get_scan_state();
			$content_items = $state['items'];
			$progress = $state['progress'];
			$has_scanned_content = ! empty( $progress['started'] );
			$scan_paused = $has_scanned_content && ! $progress['complete'];

			wp_nonce_field( 'foogallery_content_migrate', 'foogallery_content_migrate', false );

			if ( $scan_paused ) {
				echo '<div class="notice notice-warning inline"><p>';
				printf(
					// translators: %d is the number of posts and pages already scanned.
					esc_html__( 'Content scan paused after %d posts/pages. Use Resume Scan to continue from the saved position.', 'foogallery-migrate' ),
					absint( $progress['scanned'] )
				);
				echo '</p></div>';
			}


			if ( empty( $content_items ) ) {
				if ( $has_scanned_content && $progress['complete'] ) {
					echo '<p>' . esc_html__( 'No gallery shortcodes or blocks found in your content.', 'foogallery-migrate' ) . '</p>';
				} else if ( ! $scan_paused ) {
					echo '<p>' . esc_html__( 'Content has not been scanned yet.', 'foogallery-migrate' ) . '</p>';
				}
				echo '<p><small>' . esc_html__( 'Scan published posts and pages for gallery shortcodes and blocks.', 'foogallery-migrate' ) . '</small></p>';
			} else {
					$url = foogallery_migrate_admin_url( 'content' );
					$page = 1;
					if ( defined( 'DOING_AJAX' ) ) {
						if ( array_key_exists( 'foogallery_content_migrate_paged', $_POST ) ) {
							$url = esc_url_raw( wp_unslash( $_POST['foogallery_content_migrate_url'] ) );
							$page = absint( wp_unslash( $_POST['foogallery_content_migrate_paged'] ) );
						} else {
							$url = wp_get_referer();
							if ( $url ) {
								$parts = parse_url( $url );
								if ( isset( $parts['query'] ) ) {
									parse_str( $parts['query'], $query );
									if ( isset( $query['content_paged'] ) ) {
										$page = absint( $query['content_paged'] );
									}
								}
							}
						}
					} elseif ( array_key_exists( 'content_paged', $_GET ) ) {
						$page = absint( wp_unslash( $_GET['content_paged'] ) );
					}
					if ( $page < 1 ) {
						$page = 1;
					}
					$url = add_query_arg( 'content_paged', $page, $url ) . '#shortcodes';

					$content_items_count = count( $content_items );
					printf(
						'<p><strong>%s</strong></p>',
						esc_html( sprintf( _n( '%d gallery occurrence found.', '%d gallery occurrences found.', $content_items_count, 'foogallery-migrate' ), $content_items_count ) )
					);
					$page_size = $this->migrator_engine->get_page_size();
					$show_pagination = $page_size > 0;

					if ( $show_pagination ) {
						$pagination = new Pagination();
						$pagination->items( $content_items_count );
						$pagination->limit( $page_size );
						$pagination->parameterName( 'content_paged' );
						$pagination->url = $url;
						$pagination->currentPage( $page );
						$pagination->calculate();
						$start = $pagination->start;
						$end = $pagination->end;
					} else {
						$start = 0;
						$end = $content_items_count - 1;
					}

				$enabled_count = 0;
				$checked_count = 0;
				$paginated_items = array();

				for ( $counter = $start; $counter <= $end; $counter++ ) {
					if ( $counter >= $content_items_count ) {
						break;
					}
					if ( ! isset( $content_items[ $counter ] ) ) {
						continue;
					}
					$item = $content_items[ $counter ];
					if ( ! is_array( $item ) || ! isset( $item['post_id'] ) ) {
						continue;
					}
					$paginated_items[ $counter ] = $item;
					$is_actionable = $this->is_content_item_replaceable( $item ) || ( isset( $item['plugin_name'] ) && 'WordPress Core' === $item['plugin_name'] );
					if ( $is_actionable ) {
						$enabled_count++;
						$checked_count++;
					}
				}
				$all_checked = ( $enabled_count > 0 && $enabled_count === $checked_count );
				?>
				<table class="wp-list-table widefat fixed striped table-view-list pages">
					<thead>
						<tr>
							<td id="cb" class="manage-column column-cb check-column">
								<label class="screen-reader-text" for="cb-select-all-content"><?php esc_html_e( 'Select All', 'foogallery-migrate' ); ?></label>
								<input id="cb-select-all-content" type="checkbox" <?php echo $all_checked ? 'checked="checked"' : ''; ?> />
							</td>
							<th scope="col" class="manage-column">
								<span><?php esc_html_e( 'Post/Page', 'foogallery-migrate' ); ?></span>
							</th>
							<th scope="col" class="manage-column">
								<span><?php esc_html_e( 'Source Plugin', 'foogallery-migrate' ); ?></span>
							</th>
							<th scope="col" class="manage-column">
								<span><?php esc_html_e( 'Source Context', 'foogallery-migrate' ); ?></span>
							</th>
							<th scope="col" class="manage-column">
								<span><?php esc_html_e( 'Type', 'foogallery-migrate' ); ?></span>
							</th>
							<th scope="col" class="manage-column">
								<span><?php esc_html_e( 'Migrated ID', 'foogallery-migrate' ); ?></span>
							</th>
							<th scope="col" class="manage-column">
								<span><?php esc_html_e( 'Status', 'foogallery-migrate' ); ?></span>
							</th>
						</tr>
					</thead>
					<tbody>
					<?php
					foreach ( $paginated_items as $key => $item ) {
						if ( ! is_array( $item ) || ! isset( $item['post_id'] ) ) {
							continue;
						}

						$post_edit_link = admin_url( 'post.php?post=' . absint( $item['post_id'] ) . '&action=edit' );
						$foogallery_edit_link = '';
						if ( ! empty( $item['migrated_foogallery_id'] ) ) {
							$foogallery_edit_link = admin_url( 'post.php?post=' . absint( $item['migrated_foogallery_id'] ) . '&action=edit' );
						}
						$is_migrated = ! empty( $item['migrated'] ) && ! empty( $item['migrated_foogallery_id'] );
						$is_core = isset( $item['plugin_name'] ) && 'WordPress Core' === $item['plugin_name'];
						$is_direct_replacement = isset( $item['replacement_content'] ) && is_string( $item['replacement_content'] ) && '' !== trim( $item['replacement_content'] );
						$is_actionable = $this->is_content_item_replaceable( $item ) || $is_core;
						?>
						<tr class="<?php echo esc_attr( ($key % 2 === 0) ? 'alternate' : '' ); ?>">
							<th scope="row" class="check-column">
								<?php if ( $is_actionable ) { ?>
									<input name="content-item[]" type="checkbox" checked="checked" value="<?php echo esc_attr( $key ); ?>">
								<?php } else { ?>
									<input name="content-item[]" type="checkbox" disabled="disabled" value="<?php echo esc_attr( $key ); ?>">
								<?php } ?>
							</th>
							<td>
								<a href="<?php echo esc_url( $post_edit_link ); ?>" target="_blank">
									<strong><?php echo esc_html( $item['post_title'] ); ?></strong>
								</a>
								<br>
								<small><?php echo esc_html( ucfirst( $item['post_type'] ) ); ?></small>
							</td>
							<td>
								<?php echo esc_html( $item['plugin_name'] ); ?>
							</td>
							<td>
								<?php echo esc_html( isset( $item['source_context'] ) ? $item['source_context'] : $item['gallery_id'] ); ?>
							</td>
							<td>
								<?php
								echo esc_html( ucfirst( $item['type'] ) . ' / ' . $this->get_content_item_object_type_label( $item ) );
								?>
							</td>
							<td>
								<?php if ( $item['migrated_foogallery_id'] ) { ?>
									<a href="<?php echo esc_url( $foogallery_edit_link ); ?>" target="_blank">
										<?php echo esc_html( $item['migrated_foogallery_id'] ); ?>
									</a>
								<?php } else { ?>
									<span style="color: #999;">—</span>
								<?php } ?>
							</td>
							<td>
								<?php if ( $is_migrated ) { ?>
									<span style="color: #080;"><?php esc_html_e( 'Migrated', 'foogallery-migrate' ); ?></span>
								<?php } elseif ( $is_core ) { ?>
									<span style="color: #2271b1;"><?php esc_html_e( 'Ready to migrate', 'foogallery-migrate' ); ?></span>
								<?php } else if ( $is_direct_replacement ) { ?>
									<span style="color: #080;"><?php esc_html_e( 'Ready', 'foogallery-migrate' ); ?></span>
								<?php } else { ?>
									<span style="color: #f60;"><?php esc_html_e( 'Not Migrated', 'foogallery-migrate' ); ?></span>
								<?php } ?>
							</td>
						</tr>
						<?php
					}
					?>
					</tbody>
				</table>
				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<?php if ( $show_pagination ) { echo wp_kses_post( $pagination->render( false ) ); } ?>
					</div>
				</div>
				<?php
				echo '<input type="hidden" name="foogallery_content_migrate_paged" value="' . esc_attr( $page ) . '" />';
				echo '<input type="hidden" name="foogallery_content_migrate_url" value="' . esc_url( $url ) . '" />';
			}
			?>
			<p>
				<button name="action" value="foogallery_migrate_content"
						class="button button-primary replace_content"><?php esc_html_e( 'Migrate & Replace Selected', 'foogallery-migrate' ); ?></button>
				<button name="action" value="foogallery_migrate_refresh_content"
						class="button refresh_content" data-reset="<?php echo $scan_paused ? '0' : '1'; ?>">
					<?php echo $scan_paused ? esc_html__( 'Resume Scan', 'foogallery-migrate' ) : ( $has_scanned_content ? esc_html__( 'Refresh Scan', 'foogallery-migrate' ) : esc_html__( 'Scan Content', 'foogallery-migrate' ) ); ?>
				</button>
			</p>
			<p id="foogallery_migrate_content_progress" aria-live="polite"></p>
			<div id="foogallery_migrate_content_spinner" style="width:20px; display: inline-block;">
				<span class="spinner"></span>
			</div>
			<?php
		}
	}
}
