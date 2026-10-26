<?php
/** Lossless compact collection headers, separate from independently indexed items. */
namespace FooPlugins\FooGalleryMigrate;
if ( ! defined( 'ABSPATH' ) ) { exit; }

class LegacyCollectionEnvelope {
	private $codec;
	public function __construct( $codec = null ) {
		if ( null !== $codec && ! $codec instanceof LegacyStateCodec ) { $this->fail( 'Invalid collection codec.' ); }
		$this->codec = null === $codec ? new LegacyStateCodec() : $codec;
	}

	/** Only established compact collection keys interpret the marker as a wrapper. */
	public function split( $key, $kind, $value ) {
		if ( ! is_array( $value ) || ! in_array( $kind, array( 'map', 'list' ), true ) ) { $this->fail( 'Invalid collection.' ); }
		$root = array( 'version' => 1, 'kind' => $kind, 'root_count' => 0 );
		$items = $value;
		if ( in_array( $key, array( MigratorSettings::KEY_PLUGINS, MigratorSettings::KEY_GALLERIES,
			MigratorSettings::KEY_ALBUMS, MigratorSettings::KEY_MIGRATED ), true ) && array_key_exists( MigratorSettings::COMPACT_MARKER, $value ) ) {
			$type = MigratorSettings::KEY_PLUGINS === $key ? 'plugins' : 'migratable';
			if ( 1 !== $value[MigratorSettings::COMPACT_MARKER] || ! isset( $value['type'], $value['items'] ) ||
				$type !== $value['type'] || ! is_array( $value['items'] ) ) { $this->fail( 'Unsupported compact wrapper; retain option authority.' ); }
			$items = $value['items']; $headers = $value; unset( $headers['items'] );
			$root['wrapper'] = array( 'field_order' => array_keys( $value ), 'values' => $headers );
		}
		if ( LegacyRecordCodec::CONTENT_STATE_KEY === $key ) {
			if ( 'map' !== $kind || ! isset( $value['items'], $value['progress'] ) || ! is_array( $value['items'] ) || ! is_array( $value['progress'] ) ) { $this->fail( 'Invalid content scan envelope.' ); }
			$items = $value['items']; $headers = $value; unset( $headers['items'] );
			$next = 0; foreach ( $items as $item_key => $item ) { if ( is_int( $item_key ) && $item_key >= $next ) { if ( PHP_INT_MAX === $item_key ) { $this->fail( 'Content selector exhausted.' ); } $next = $item_key + 1; } }
			$root['wrapper'] = array( 'field_order' => array_keys( $value ), 'values' => $headers, 'content' => 1, 'next_key' => $next );
		}
		if ( ImageTagQueueRepository::STATE_KEY === $key ) {
			if ( 'map' !== $kind || ! array_key_exists( 'items', $value ) || ! is_array( $value['items'] ) ) { $this->fail( 'Invalid image-tag queue envelope.' ); }
			$items = $value['items']; $headers = $value; unset( $headers['items'] );
			$root['wrapper'] = array( 'field_order' => array_keys( $value ), 'values' => $headers, 'image_tag_queue' => 1 );
		}
		$root['root_count'] = count( $items );
		$this->validate( $root, $key );
		if ( 'list' === $kind && count( $items ) && array_keys( $items ) !== range( 0, count( $items ) - 1 ) ) { $this->fail( 'List keys are not a canonical ordered list.' ); }
		return array( 'root' => $root, 'items' => $items );
	}

	public function validate( $root, $key = null ) {
		if ( ! is_array( $root ) || ! isset( $root['version'], $root['kind'], $root['root_count'] ) || 1 !== $root['version'] ||
			! in_array( $root['kind'], array( 'map', 'list' ), true ) || ! is_int( $root['root_count'] ) || $root['root_count'] < 0 ||
			array_keys( $root ) !== ( array_key_exists( 'wrapper', $root ) ? array( 'version', 'kind', 'root_count', 'wrapper' ) : array( 'version', 'kind', 'root_count' ) ) ) {
			$this->fail( 'Malformed collection metadata.' );
		}
		if ( ! array_key_exists( 'wrapper', $root ) ) { return; }
		$wrapper = $root['wrapper'];
		$content = is_array( $wrapper ) && array_key_exists( 'content', $wrapper );
		$queue = is_array( $wrapper ) && array_key_exists( 'image_tag_queue', $wrapper );
		if ( $content && $queue ) { $this->fail( 'Ambiguous collection wrapper.' ); }
		$fields = $content ? array( 'field_order', 'values', 'content', 'next_key' ) : ( $queue ? array( 'field_order', 'values', 'image_tag_queue' ) : array( 'field_order', 'values' ) );
		if ( ! is_array( $wrapper ) || array_keys( $wrapper ) !== $fields ||
			! is_array( $wrapper['field_order'] ) || ! count( $wrapper['field_order'] ) ||
			array_keys( $wrapper['field_order'] ) !== range( 0, count( $wrapper['field_order'] ) - 1 ) || ! is_array( $wrapper['values'] ) ) { $this->fail( 'Malformed wrapper metadata.' ); }
		$seen = array(); $headers = array();
		foreach ( $wrapper['field_order'] as $field ) {
			$this->codec->key_value( $field );
			if ( array_keys( array( $field => true ) ) !== array( $field ) || array_key_exists( $field, $seen ) ) { $this->fail( 'Duplicate or coercible wrapper field.' ); }
			$seen[$field] = true;
			if ( 'items' !== $field ) { $headers[] = $field; }
		}
		$values = $wrapper['values'];
		if ( $content ) {
			if ( 1 !== $wrapper['content'] || ! is_int( $wrapper['next_key'] ) || $wrapper['next_key'] < 0 || 'map' !== $root['kind'] || ( null !== $key && LegacyRecordCodec::CONTENT_STATE_KEY !== $key ) ||
				! isset( $seen['items'], $seen['progress'], $values['progress'] ) || ! is_array( $values['progress'] ) || array_keys( $values ) !== $headers ) { $this->fail( 'Malformed content checkpoint metadata.' ); }
			return;
		}
		if ( $queue ) {
			if ( 1 !== $wrapper['image_tag_queue'] || 'map' !== $root['kind'] ||
				( null !== $key && ImageTagQueueRepository::STATE_KEY !== $key ) || ! isset( $seen['items'] ) || array_keys( $values ) !== $headers ) { $this->fail( 'Malformed image-tag queue metadata.' ); }
			return;
		}
		if ( LegacyRecordCodec::CONTENT_STATE_KEY === $key ) { $this->fail( 'Content record envelope required; recover legacy scalar through explicit conversion.' ); }
		if ( ImageTagQueueRepository::STATE_KEY === $key ) { $this->fail( 'Image-tag queue envelope required; preserve legacy scalar separately.' ); }
		if ( ! isset( $seen['items'] ) || array_keys( $values ) !== $headers || ! isset( $values[MigratorSettings::COMPACT_MARKER], $values['type'] ) ||
			1 !== $values[MigratorSettings::COMPACT_MARKER] || ! in_array( $values['type'], array( 'migratable', 'plugins' ), true ) ) { $this->fail( 'Incomplete or unsupported wrapper metadata.' ); }
		if ( null !== $key && ( ! in_array( $key, array( MigratorSettings::KEY_PLUGINS, MigratorSettings::KEY_GALLERIES,
			MigratorSettings::KEY_ALBUMS, MigratorSettings::KEY_MIGRATED ), true ) ||
			$values['type'] !== ( MigratorSettings::KEY_PLUGINS === $key ? 'plugins' : 'migratable' ) ) ) { $this->fail( 'Wrapper metadata does not match its state key.' ); }
	}

	public function rebuild( $root, $items ) {
		$this->validate( $root );
		if ( ! is_array( $items ) || count( $items ) !== $root['root_count'] || ( 'list' === $root['kind'] && count( $items ) && array_keys( $items ) !== range( 0, count( $items ) - 1 ) ) ) { $this->fail( 'Root key/order/count parity failed.' ); }
		if ( ! array_key_exists( 'wrapper', $root ) ) { return $items; }
		$value = array();
		foreach ( $root['wrapper']['field_order'] as $field ) { $value[$field] = 'items' === $field ? $items : $root['wrapper']['values'][$field]; }
		return $value;
	}

	/** Feed the exact existing canonical domain without assembling all item roots. */
	public function begin_digest( $hash, $root ) {
		$this->validate( $root );
		if ( ! array_key_exists( 'wrapper', $root ) ) { hash_update( $hash, 'array:' . $root['root_count'] . ':' ); return; }
		hash_update( $hash, 'array:' . count( $root['wrapper']['field_order'] ) . ':' );
		foreach ( $root['wrapper']['field_order'] as $field ) {
			$this->digest_value( $hash, $field, 1 );
			if ( 'items' === $field ) { hash_update( $hash, 'array:' . $root['root_count'] . ':' ); break; }
			$this->digest_value( $hash, $root['wrapper']['values'][$field], 1 );
		}
	}
	public function digest_item( $hash, $root, $key, $value ) {
		$depth = array_key_exists( 'wrapper', $root ) ? 2 : 1;
		$this->digest_value( $hash, $key, $depth ); $this->digest_value( $hash, $value, $depth );
	}
	public function end_digest( $hash, $root ) {
		if ( ! array_key_exists( 'wrapper', $root ) ) { return; }
		$after = false;
		foreach ( $root['wrapper']['field_order'] as $field ) {
			if ( $after ) { $this->digest_value( $hash, $field, 1 ); $this->digest_value( $hash, $root['wrapper']['values'][$field], 1 ); }
			if ( 'items' === $field ) { $after = true; }
		}
	}
	private function digest_value( $hash, $value, $depth ) {
		if ( $depth > 64 ) { $this->fail( 'Collection exceeds canonical depth budget.' ); }
		if ( is_array( $value ) ) {
			hash_update( $hash, 'array:' . count( $value ) . ':' );
			foreach ( $value as $key => $item ) { $this->digest_value( $hash, $key, $depth + 1 ); $this->digest_value( $hash, $item, $depth + 1 ); }
		} else { $encoded = $this->codec->encode( $value ); hash_update( $hash, 'value:' . strlen( $encoded ) . ':' . $encoded ); }
	}
	private function fail( $message ) { throw new \RuntimeException( $message ); }
}
