<?php

if ( ! defined( 'WPINC' ) ) {
	define( 'WPINC', 'wp-includes' );
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'FOOGM_NAMESPACE' ) ) {
	define( 'FOOGM_NAMESPACE', 'FooPlugins\\FooGalleryMigrate' );
}

if ( ! defined( 'FOOGM_DIR' ) ) {
	define( 'FOOGM_DIR', dirname( __DIR__ ) );
}

if ( ! defined( 'FOOGALLERY_MIGRATE_OPTION_DATA' ) ) {
	define( 'FOOGALLERY_MIGRATE_OPTION_DATA', 'foogallery-migrate-data' );
}

if ( ! defined( 'FOOGALLERY_MIGRATE_OPTION_SETTINGS' ) ) {
	define( 'FOOGALLERY_MIGRATE_OPTION_SETTINGS', 'foogallery-migrate-settings' );
}

if ( ! defined( 'FOOGALLERY_CPT_GALLERY' ) ) {
	define( 'FOOGALLERY_CPT_GALLERY', 'foogallery' );
}

if ( ! defined( 'FOOGALLERY_CPT_ALBUM' ) ) {
	define( 'FOOGALLERY_CPT_ALBUM', 'fooalbum' );
}

if ( ! defined( 'FOOGALLERY_META_TEMPLATE' ) ) {
	define( 'FOOGALLERY_META_TEMPLATE', '_foogallery_template' );
}

if ( ! defined( 'FOOGALLERY_META_SETTINGS' ) ) {
	define( 'FOOGALLERY_META_SETTINGS', '_foogallery_settings' );
}

if ( ! defined( 'FOOGALLERY_META_SETTINGS_OLD' ) ) {
	define( 'FOOGALLERY_META_SETTINGS_OLD', 'foogallery_settings' );
}

if ( ! defined( 'FOOGALLERY_META_CUSTOM_CSS' ) ) {
	define( 'FOOGALLERY_META_CUSTOM_CSS', 'foogallery_custom_css' );
}

if ( ! defined( 'FOOGALLERY_META_ATTACHMENTS' ) ) {
	define( 'FOOGALLERY_META_ATTACHMENTS', '_foogallery_attachments' );
}

if ( ! defined( 'FOOGALLERY_ALBUM_META_TEMPLATE' ) ) {
	define( 'FOOGALLERY_ALBUM_META_TEMPLATE', '_fooalbum_template' );
}

if ( ! defined( 'FOOGALLERY_ALBUM_META_GALLERIES' ) ) {
	define( 'FOOGALLERY_ALBUM_META_GALLERIES', '_fooalbum_galleries' );
}

if ( ! defined( 'FOOGALLERY_ALBUM_META_SORT' ) ) {
	define( 'FOOGALLERY_ALBUM_META_SORT', 'foogallery_album_sort' );
}

if ( ! defined( 'FOOGALLERY_ATTACHMENT_TAXONOMY_TAG' ) ) {
	define( 'FOOGALLERY_ATTACHMENT_TAXONOMY_TAG', 'foogallery_attachment_tag' );
}

if ( ! defined( 'FOOGALLERY_PRO_PLAN_EXPERT' ) ) {
	define( 'FOOGALLERY_PRO_PLAN_EXPERT', 'pro' );
}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

$GLOBALS['foogallery_migrate_test_options'] = array();
$GLOBALS['foogallery_migrate_test_plugins'] = array();
$GLOBALS['foogallery_migrate_test_posts'] = array();
$GLOBALS['foogallery_migrate_test_post_meta'] = array();
$GLOBALS['foogallery_migrate_test_post_meta_updates'] = array();
$GLOBALS['foogallery_migrate_test_attached_files'] = array();
$GLOBALS['foogallery_migrate_test_attachment_urls'] = array();
$GLOBALS['foogallery_migrate_test_attachment_image_urls'] = array();
$GLOBALS['foogallery_migrate_test_attachment_image_calls'] = array();
$GLOBALS['foogallery_migrate_test_attachment_url_to_postid'] = array();
$GLOBALS['foogallery_migrate_test_object_terms'] = array();
$GLOBALS['foogallery_migrate_test_set_object_terms'] = array();
$GLOBALS['foogallery_migrate_test_imported_attachments'] = array();
$GLOBALS['foogallery_migrate_test_remote_head'] = array();
$GLOBALS['foogallery_migrate_test_gallery_templates'] = array();
$GLOBALS['foogallery_migrate_test_taxonomies'] = array();
$GLOBALS['foogallery_migrate_test_foogallery_fs'] = null;

class FooGalleryMigrateTestFreemius {
	private $can_use_premium_code;
	private $plans;

	public function __construct( $can_use_premium_code = false, $plans = array() ) {
		$this->can_use_premium_code = $can_use_premium_code;
		$this->plans = $plans;
	}

	public function can_use_premium_code() {
		return $this->can_use_premium_code;
	}

	public function is_plan_or_trial( $plan, $exact = false ) {
		return ! empty( $this->plans[ $plan ] );
	}

	public function is_plan( $plan, $exact = false ) {
		return ! empty( $this->plans[ $plan ] );
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;

		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}
	}
}

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['foogallery_migrate_test_options'] )
		? $GLOBALS['foogallery_migrate_test_options'][ $name ]
		: $default;
}

function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['foogallery_migrate_test_options'][ $name ] = $value;
	return true;
}

function delete_option( $name ) {
	unset( $GLOBALS['foogallery_migrate_test_options'][ $name ] );
	return true;
}

function absint( $maybeint ) {
	return abs( intval( $maybeint ) );
}

function sanitize_key( $key ) {
	$key = strtolower( (string) $key );
	return preg_replace( '/[^a-z0-9_\-]/', '', $key );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_url( $url ) {
	return esc_attr( $url );
}

function wp_kses_post( $content ) {
	return $content;
}

function __( $text, $domain = 'default' ) {
	return $text;
}

function _n( $single, $plural, $number, $domain = 'default' ) {
	return 1 === (int) $number ? $single : $plural;
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function apply_filters( $hook_name, $value ) {
	return $value;
}

function wp_raise_memory_limit( $context = 'admin' ) {
	return true;
}

function taxonomy_exists( $taxonomy ) {
	return in_array( $taxonomy, $GLOBALS['foogallery_migrate_test_taxonomies'], true );
}

function wp_get_object_terms( $object_id, $taxonomy, $args = array() ) {
	$object_id = absint( $object_id );

	if (
		! isset( $GLOBALS['foogallery_migrate_test_object_terms'][ $taxonomy ] ) ||
		! isset( $GLOBALS['foogallery_migrate_test_object_terms'][ $taxonomy ][ $object_id ] )
	) {
		return array();
	}

	$terms = $GLOBALS['foogallery_migrate_test_object_terms'][ $taxonomy ][ $object_id ];
	if ( isset( $args['fields'] ) && 'names' === $args['fields'] ) {
		return array_values( $terms );
	}

	return array_map(
		function( $term ) use ( $taxonomy ) {
			return (object) array(
				'name'     => $term,
				'taxonomy' => $taxonomy,
			);
		},
		$terms
	);
}

function wp_set_object_terms( $object_id, $terms, $taxonomy, $append = false ) {
	if ( ! taxonomy_exists( $taxonomy ) ) {
		return new WP_Error( 'invalid_taxonomy', 'Invalid taxonomy.' );
	}

	$object_id = absint( $object_id );
	$terms = is_array( $terms ) ? array_values( $terms ) : array( $terms );

	if ( $append && isset( $GLOBALS['foogallery_migrate_test_object_terms'][ $taxonomy ][ $object_id ] ) ) {
		$terms = array_merge( $GLOBALS['foogallery_migrate_test_object_terms'][ $taxonomy ][ $object_id ], $terms );
	}

	$terms = array_values( array_unique( array_filter( array_map( 'strval', $terms ) ) ) );
	$GLOBALS['foogallery_migrate_test_object_terms'][ $taxonomy ][ $object_id ] = $terms;
	$GLOBALS['foogallery_migrate_test_set_object_terms'][] = compact( 'object_id', 'terms', 'taxonomy', 'append' );

	return $terms;
}

function foogallery_import_attachment( $attachment_data ) {
	static $attachment_id = 6000;

	$attachment_id++;
	$GLOBALS['foogallery_migrate_test_imported_attachments'][] = array(
		'id'   => $attachment_id,
		'data' => $attachment_data,
	);
	$GLOBALS['foogallery_migrate_test_posts'][ $attachment_id ] = (object) array(
		'ID'           => $attachment_id,
		'post_type'    => 'attachment',
		'post_status'  => 'inherit',
		'post_title'   => isset( $attachment_data['title'] ) ? $attachment_data['title'] : '',
		'post_excerpt' => isset( $attachment_data['caption'] ) ? $attachment_data['caption'] : '',
		'post_content' => isset( $attachment_data['description'] ) ? $attachment_data['description'] : '',
	);
	$GLOBALS['foogallery_migrate_test_attachment_urls'][ $attachment_id ] = isset( $attachment_data['url'] ) ? $attachment_data['url'] : '';

	if (
		isset( $attachment_data['tags'] ) &&
		is_array( $attachment_data['tags'] ) &&
		count( $attachment_data['tags'] ) > 0 &&
		taxonomy_exists( FOOGALLERY_ATTACHMENT_TAXONOMY_TAG )
	) {
		wp_set_object_terms( $attachment_id, $attachment_data['tags'], FOOGALLERY_ATTACHMENT_TAXONOMY_TAG, false );
	}

	return $attachment_id;
}

function foogallery_fs() {
	return $GLOBALS['foogallery_migrate_test_foogallery_fs'];
}

function foogallery_migrate_get_available_plugins() {
	return $GLOBALS['foogallery_migrate_test_plugins'];
}

function foogallery_migrate_migrator_instance() {
	return $GLOBALS['foogallery_migrate_engine_instance'];
}

function foogallery_default_gallery_template() {
	return 'default';
}

function foogallery_gallery_templates() {
	return $GLOBALS['foogallery_migrate_test_gallery_templates'];
}

function foogallery_get_setting( $key ) {
	return '';
}

function wp_insert_post( $args ) {
	static $post_id = 1000;
	$post_id++;
	$post = (object) array_merge( $args, array( 'ID' => $post_id ) );
	$GLOBALS['foogallery_migrate_test_posts'][ $post_id ] = $post;
	return $post_id;
}

function wp_update_post( $args, $wp_error = false ) {
	$post_id = isset( $args['ID'] ) ? absint( $args['ID'] ) : 0;

	if ( $post_id < 1 || ! isset( $GLOBALS['foogallery_migrate_test_posts'][ $post_id ] ) ) {
		return $wp_error ? new WP_Error( 'missing_post', 'Post not found.' ) : 0;
	}

	foreach ( $args as $key => $value ) {
		$GLOBALS['foogallery_migrate_test_posts'][ $post_id ]->$key = $value;
	}

	return $post_id;
}

function get_post( $post_id ) {
	$post_id = absint( $post_id );

	return isset( $GLOBALS['foogallery_migrate_test_posts'][ $post_id ] )
		? $GLOBALS['foogallery_migrate_test_posts'][ $post_id ]
		: null;
}

function get_posts( $args = array() ) {
	$post_type = isset( $args['post_type'] ) ? $args['post_type'] : '';
	$meta_key = isset( $args['meta_key'] ) ? $args['meta_key'] : '';
	$meta_value = isset( $args['meta_value'] ) ? $args['meta_value'] : null;
	$posts = array();

	foreach ( $GLOBALS['foogallery_migrate_test_posts'] as $post ) {
		if ( '' !== $post_type && ( ! isset( $post->post_type ) || $post_type !== $post->post_type ) ) {
			continue;
		}

		if ( isset( $post->post_status ) && 'trash' === $post->post_status ) {
			continue;
		}

		if ( '' !== $meta_key && get_post_meta( $post->ID, $meta_key, true ) !== $meta_value ) {
			continue;
		}

		$posts[] = $post;
	}

	usort(
		$posts,
		function( $a, $b ) {
			$a_title = isset( $a->post_title ) ? $a->post_title : '';
			$b_title = isset( $b->post_title ) ? $b->post_title : '';
			return strcasecmp( $a_title, $b_title );
		}
	);

	if ( isset( $args['fields'] ) && 'ids' === $args['fields'] ) {
		return wp_list_pluck( $posts, 'ID' );
	}

	return $posts;
}

function add_post_meta( $post_id, $meta_key, $meta_value, $unique = false ) {
	$GLOBALS['foogallery_migrate_test_post_meta_updates'][] = compact( 'post_id', 'meta_key', 'meta_value', 'unique' );
	return true;
}

function update_post_meta( $post_id, $meta_key, $meta_value ) {
	$GLOBALS['foogallery_migrate_test_post_meta_updates'][] = compact( 'post_id', 'meta_key', 'meta_value' );
	return true;
}

function get_post_meta( $post_id, $meta_key = '', $single = false ) {
	$post_id = absint( $post_id );

	if ( ! isset( $GLOBALS['foogallery_migrate_test_post_meta'][ $post_id ] ) ) {
		return $single ? '' : array();
	}

	if ( '' === $meta_key ) {
		return $GLOBALS['foogallery_migrate_test_post_meta'][ $post_id ];
	}

	if ( ! array_key_exists( $meta_key, $GLOBALS['foogallery_migrate_test_post_meta'][ $post_id ] ) ) {
		return $single ? '' : array();
	}

	$value = $GLOBALS['foogallery_migrate_test_post_meta'][ $post_id ][ $meta_key ];

	return $single ? $value : array( $value );
}

function update_meta_cache( $meta_type, $object_ids ) {
	return true;
}

function wp_list_pluck( $list, $field ) {
	$values = array();

	foreach ( $list as $item ) {
		if ( is_array( $item ) && array_key_exists( $field, $item ) ) {
			$values[] = $item[ $field ];
		} else if ( is_object( $item ) && isset( $item->$field ) ) {
			$values[] = $item->$field;
		}
	}

	return $values;
}

function get_attached_file( $attachment_id ) {
	return isset( $GLOBALS['foogallery_migrate_test_attached_files'][ $attachment_id ] )
		? $GLOBALS['foogallery_migrate_test_attached_files'][ $attachment_id ]
		: false;
}

function attachment_url_to_postid( $url ) {
	static $attachment_id = 3000;

	if ( array_key_exists( $url, $GLOBALS['foogallery_migrate_test_attachment_url_to_postid'] ) ) {
		return $GLOBALS['foogallery_migrate_test_attachment_url_to_postid'][ $url ];
	}

	$attachment_id++;
	$GLOBALS['foogallery_migrate_test_attachment_url_to_postid'][ $url ] = $attachment_id;

	return $attachment_id;
}

function wp_filesize( $path ) {
	return file_exists( $path ) ? filesize( $path ) : false;
}

function wp_get_attachment_url( $attachment_id ) {
	return isset( $GLOBALS['foogallery_migrate_test_attachment_urls'][ $attachment_id ] )
		? $GLOBALS['foogallery_migrate_test_attachment_urls'][ $attachment_id ]
		: '';
}

function wp_get_attachment_caption( $attachment_id ) {
	$post = get_post( $attachment_id );

	if ( ! $post || ! isset( $post->post_type ) || 'attachment' !== $post->post_type ) {
		return false;
	}

	return isset( $post->post_excerpt ) ? $post->post_excerpt : '';
}

function wp_get_attachment_image( $attachment_id, $size = 'thumbnail', $icon = false, $attr = array() ) {
	$GLOBALS['foogallery_migrate_test_attachment_image_calls'][] = compact( 'attachment_id', 'size', 'attr' );

	$url = wp_get_attachment_url( $attachment_id );
	if (
		is_string( $size ) &&
		isset( $GLOBALS['foogallery_migrate_test_attachment_image_urls'][ $attachment_id ] ) &&
		isset( $GLOBALS['foogallery_migrate_test_attachment_image_urls'][ $attachment_id ][ $size ] )
	) {
		$url = $GLOBALS['foogallery_migrate_test_attachment_image_urls'][ $attachment_id ][ $size ];
	}
	if ( '' === $url ) {
		return '';
	}

	$attr = is_array( $attr ) ? $attr : array();
	$class = 'wp-image-' . absint( $attachment_id );
	if ( is_string( $size ) && '' !== $size ) {
		$class .= ' attachment-' . $size . ' size-' . $size;
	}
	if ( isset( $attr['class'] ) && '' !== (string) $attr['class'] ) {
		$class .= ' ' . $attr['class'];
	}
	$attr['class'] = $class;

	if ( 'thumbnail' === $size ) {
		if ( ! isset( $attr['width'] ) ) {
			$attr['width'] = 150;
		}
		if ( ! isset( $attr['height'] ) ) {
			$attr['height'] = 150;
		}
	}

	$attribute_strings = array(
		'src="' . esc_url( $url ) . '"',
	);

	foreach ( $attr as $name => $value ) {
		if ( is_scalar( $value ) && '' !== (string) $value ) {
			$attribute_strings[] = esc_attr( $name ) . '="' . esc_attr( $value ) . '"';
		}
	}

	return '<img ' . implode( ' ', $attribute_strings ) . ' />';
}

function wp_remote_head( $url, $args = array() ) {
	return isset( $GLOBALS['foogallery_migrate_test_remote_head'][ $url ] )
		? $GLOBALS['foogallery_migrate_test_remote_head'][ $url ]
		: array( 'response' => array( 'code' => 200 ) );
}

function wp_remote_retrieve_response_code( $response ) {
	return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
}

function wp_get_upload_dir() {
	return array(
		'basedir' => '/tmp/uploads',
		'baseurl' => 'https://example.test/wp-content/uploads',
	);
}

function wp_parse_url( $url, $component = -1 ) {
	return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
}

function home_url( $path = '' ) {
	return 'https://example.test' . ( '' === $path ? '' : '/' . ltrim( $path, '/' ) );
}

function site_url( $path = '' ) {
	return home_url( $path );
}

function wp_normalize_path( $path ) {
	return str_replace( '\\', '/', (string) $path );
}

function trailingslashit( $value ) {
	return rtrim( (string) $value, "/\\" ) . '/';
}

function untrailingslashit( $value ) {
	return rtrim( (string) $value, "/\\" );
}

require dirname( __DIR__ ) . '/vendor/autoload.php';

spl_autoload_register(
	function( $class ) {
		if ( false === strpos( $class, FOOGM_NAMESPACE ) ) {
			return;
		}

		$class_file = str_replace( FOOGM_NAMESPACE . '\\', '', $class );
		$class_path = explode( '\\', $class_file );
		$class_file = array_pop( $class_path );
		$class_path = strtolower( implode( '/', $class_path ) );
		$class_file = lcfirst( $class_file );
		$class_file = preg_replace( '/[A-Z]/', '_$0', $class_file );
		$class_file = strtolower( $class_file );
		$class_file = str_replace( '_', '-', $class_file );
		$class_file = str_replace( '--', '-', $class_file );

		$file_to_load = FOOGM_DIR . '/includes/' . $class_path . '/class-' . $class_file . '.php';

		if ( file_exists( $file_to_load ) ) {
			require_once $file_to_load;
		}
	}
);
