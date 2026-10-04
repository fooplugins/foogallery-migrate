<?php
namespace FooPlugins\FooGalleryMigrate;
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Whole-option collections. Partial updates retain raw unrelated records/fields. */
class MigratableCollectionRepository extends ScalarStateRepository {
	private function bucket( $bucket ) {
		if ( ! in_array( $bucket, array( MigratorSettings::KEY_GALLERIES, MigratorSettings::KEY_ALBUMS ), true ) ) {
			throw new \RuntimeException( 'Invalid migratable collection.' );
		}
		return $bucket;
	}
	private function validate_collection( $collection ) {
		if ( ! is_array( $collection ) || ( array_key_exists( MigratorSettings::COMPACT_MARKER, $collection ) &&
			( MigratorSettings::COMPACT_VERSION !== $collection[MigratorSettings::COMPACT_MARKER] || ! isset( $collection['items'], $collection['type'] ) ||
			! is_array( $collection['items'] ) || 'migratable' !== $collection['type'] ) ) ) {
			throw new \RuntimeException( 'Unsupported collection. No fallback.' );
		}
	}
	public function read_snapshot( $bucket ) {
		$bucket = $this->bucket( $bucket );
		$raw = $this->backend->read( $bucket );
		if ( ! $raw['found'] ) { return array(); }
		$this->validate_collection( $raw['value'] );
		return $this->decode( $bucket, $raw['value'] );
	}
	public function replace_snapshot( $bucket, $objects ) {
		if ( ! is_array( $objects ) ) { return false; }
		return $this->write_values( array( $this->bucket( $bucket ) => $objects ) );
	}
	public function update_with_state( $bucket, $objects, $state ) {
		if ( ! is_array( $objects ) ) { return false; }
		$bucket = $this->bucket( $bucket );
		return $this->write_values( array( $bucket => $objects, $bucket . '-state' => $state ) );
	}
	/** Locator: exact root PHP key and exact child keys along children[]. */
	public function update_record( $bucket, $locator, $object ) {
		$bucket = $this->bucket( $bucket );
		if ( ! is_array( $locator ) || ! array_key_exists( 'root', $locator ) || ! isset( $locator['path'] ) || ! is_array( $locator['path'] ) ) { return false; }
		self::exact_keys( array( $locator['root'] ) );
		foreach ( $locator['path'] as $key ) { self::exact_keys( array( $key ) ); }
		return $this->backend->mutate( function ( $data ) use ( $bucket, $locator, $object ) {
			if ( ! isset( $data[$bucket] ) || ! is_array( $data[$bucket] ) ) { return false; }
			$this->validate_collection( $data[$bucket] );
			$compact = array_key_exists( MigratorSettings::COMPACT_MARKER, $data[$bucket] );
			$items = $compact ? ( isset( $data[$bucket]['items'] ) ? $data[$bucket]['items'] : null ) : $data[$bucket];
			if ( ! is_array( $items ) || ! array_key_exists( $locator['root'], $items ) ) { return false; }
			$replacement = $object;
			if ( $compact ) {
				$encoded = $this->encode( $bucket, array( $object ) );
				$replacement = $encoded['items'][0];
			}
			$ok = true;
			$items[$locator['root']] = $this->replace_child( $items[$locator['root']], $locator['path'], $replacement, $ok );
			if ( ! $ok ) { return false; }
			if ( $compact ) { $data[$bucket]['items'] = $items; } else { $data[$bucket] = $items; }
			return $data;
		} );
	}
	private function replace_child( $node, $path, $replacement, &$ok ) {
		if ( ! count( $path ) ) { return $replacement; }
		$key = array_shift( $path );
		$children = is_object( $node ) ? ( isset( $node->children ) ? $node->children : null ) : ( is_array( $node ) && isset( $node['children'] ) ? $node['children'] : null );
		if ( ! is_array( $children ) || ! array_key_exists( $key, $children ) ) { $ok = false; return $node; }
		$children[$key] = $this->replace_child( $children[$key], $path, $replacement, $ok );
		if ( is_object( $node ) ) { $node = clone $node; $node->children = $children; } else { $node['children'] = $children; }
		return $node;
	}
}
