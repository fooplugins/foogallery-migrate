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

if ( ! defined( 'FOOGALLERY_META_ATTACHMENTS' ) ) {
	define( 'FOOGALLERY_META_ATTACHMENTS', '_foogallery_attachments' );
}

if ( ! defined( 'FOOGALLERY_ALBUM_META_TEMPLATE' ) ) {
	define( 'FOOGALLERY_ALBUM_META_TEMPLATE', '_fooalbum_template' );
}

if ( ! defined( 'FOOGALLERY_ALBUM_META_GALLERIES' ) ) {
	define( 'FOOGALLERY_ALBUM_META_GALLERIES', '_fooalbum_galleries' );
}

$GLOBALS['foogallery_migrate_test_options'] = array();
$GLOBALS['foogallery_migrate_test_plugins'] = array();
$GLOBALS['foogallery_migrate_test_post_meta_updates'] = array();
$GLOBALS['foogallery_migrate_test_attached_files'] = array();
$GLOBALS['foogallery_migrate_test_attachment_urls'] = array();
$GLOBALS['foogallery_migrate_test_remote_head'] = array();

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

function __( $text, $domain = 'default' ) {
	return $text;
}

function _n( $single, $plural, $number, $domain = 'default' ) {
	return 1 === (int) $number ? $single : $plural;
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
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

function foogallery_get_setting( $key ) {
	return '';
}

function wp_insert_post( $args ) {
	static $post_id = 1000;
	$post_id++;
	return $post_id;
}

function add_post_meta( $post_id, $meta_key, $meta_value, $unique = false ) {
	$GLOBALS['foogallery_migrate_test_post_meta_updates'][] = compact( 'post_id', 'meta_key', 'meta_value', 'unique' );
	return true;
}

function update_post_meta( $post_id, $meta_key, $meta_value ) {
	$GLOBALS['foogallery_migrate_test_post_meta_updates'][] = compact( 'post_id', 'meta_key', 'meta_value' );
	return true;
}

function get_attached_file( $attachment_id ) {
	return isset( $GLOBALS['foogallery_migrate_test_attached_files'][ $attachment_id ] )
		? $GLOBALS['foogallery_migrate_test_attached_files'][ $attachment_id ]
		: false;
}

function wp_filesize( $path ) {
	return file_exists( $path ) ? filesize( $path ) : false;
}

function wp_get_attachment_url( $attachment_id ) {
	return isset( $GLOBALS['foogallery_migrate_test_attachment_urls'][ $attachment_id ] )
		? $GLOBALS['foogallery_migrate_test_attachment_urls'][ $attachment_id ]
		: '';
}

function wp_remote_head( $url, $args = array() ) {
	return isset( $GLOBALS['foogallery_migrate_test_remote_head'][ $url ] )
		? $GLOBALS['foogallery_migrate_test_remote_head'][ $url ]
		: array( 'response' => array( 'code' => 200 ) );
}

function wp_remote_retrieve_response_code( $response ) {
	return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
}

require dirname( __DIR__ ) . '/vendor/autoload.php';
