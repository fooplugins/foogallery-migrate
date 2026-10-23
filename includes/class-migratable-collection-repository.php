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
	/** Table form metadata only; option callers keep their original snapshot path. */
	public function view_context( $bucket ) {
		$bucket = $this->bucket( $bucket );
		if ( ! $this->backend instanceof LegacyTableBackend ) { throw new \RuntimeException( 'Table collection view required.' ); }
		return $this->backend->collection_view_context( $bucket );
	}
	private function hydrate_view_record( $bucket, $row ) {
		if ( ! is_array( $row ) || array_keys( $row ) !== array( 'key', 'value' ) ) { throw new \RuntimeException( 'Invalid collection view row.' ); }
		$payload = array( MigratorSettings::COMPACT_MARKER => MigratorSettings::COMPACT_VERSION,
			'type' => 'migratable', 'items' => array( $row['key'] => $row['value'] ) );
		$objects = $this->decode( $bucket, $payload );
		if ( ! is_array( $objects ) || count( $objects ) !== 1 || ! array_key_exists( $row['key'], $objects ) ) { throw new \RuntimeException( 'Collection view hydration changed key.' ); }
		return array( 'key' => $row['key'], 'value' => $objects[$row['key']] );
	}
	public function view_page( $bucket, $offset, $limit ) {
		$bucket = $this->bucket( $bucket );
		if ( ! $this->backend instanceof LegacyTableBackend ) { throw new \RuntimeException( 'Table collection view required.' ); }
		$page = $this->backend->collection_view_page( $bucket, $offset, $limit ); $rows = array();
		foreach ( $page['rows'] as $row ) { $rows[] = $this->hydrate_view_record( $bucket, $row ); }
		return array( 'rows' => $rows, 'head' => $page['head'] );
	}
	public function first_started( $bucket ) {
		$bucket = $this->bucket( $bucket );
		if ( ! $this->backend instanceof LegacyTableBackend ) { throw new \RuntimeException( 'Table collection view required.' ); }
		$page = $this->backend->filtered_page( $bucket, 1, array( 'migration_status' => 'started' ) );
		if ( ! count( $page['rows'] ) ) { return array( 'found' => false, 'head' => null ); }
		$row = $this->hydrate_view_record( $bucket, $page['rows'][0] );
		if ( ! is_object( $row['value'] ) || $row['value']->migration_status !== 'started' ) { throw new \RuntimeException( 'Started collection projection changed.' ); }
		return array( 'found' => true, 'head' => $page['revision'], 'value' => $row['value'] );
	}
	public function read_snapshot( $bucket ) {
		$bucket = $this->bucket( $bucket );
		if ( $this->backend instanceof LegacyTableBackend ) {
			$raw = $this->backend->collection_snapshot( $bucket ); $this->validate_collection( $raw );
			return $this->decode( $bucket, $raw );
		}
		$raw = $this->backend->read( $bucket );
		if ( ! $raw['found'] ) { return array(); }
		$this->validate_collection( $raw['value'] );
		return $this->decode( $bucket, $raw['value'] );
	}
	public function replace_snapshot( $bucket, $objects ) {
		if ( ! is_array( $objects ) ) { return false; }
		if ( $this->backend instanceof LegacyTableBackend ) {
			$bucket = $this->bucket( $bucket );
			return $this->backend->replace_collection( $bucket, $this->encode( $bucket, $objects ) );
		}
		return $this->write_values( array( $this->bucket( $bucket ) => $objects ) );
	}
	public function update_with_state( $bucket, $objects, $state ) {
		if ( ! is_array( $objects ) ) { return false; }
		$bucket = $this->bucket( $bucket );
		if ( $this->backend instanceof LegacyTableBackend ) {
			return $this->backend->replace_collection( $bucket, $this->encode( $bucket, $objects ), array( $bucket . '-state' => $this->encode( $bucket . '-state', $state ) ) );
		}
		return $this->write_values( array( $bucket => $objects, $bucket . '-state' => $state ) );
	}
	/** Locator: exact root PHP key and exact child keys along children[]. */
	public function update_record( $bucket, $locator, $object ) {
		$bucket = $this->bucket( $bucket );
		if ( ! is_array( $locator ) || ! array_key_exists( 'root', $locator ) || ! isset( $locator['path'] ) || ! is_array( $locator['path'] ) ) { return false; }
		self::exact_keys( array( $locator['root'] ) );
		foreach ( $locator['path'] as $key ) { self::exact_keys( array( $key ) ); }
		if ( $this->backend instanceof LegacyTableBackend ) {
			$encoded = $this->encode( $bucket, array( $object ) );
			return $this->backend->update_collection_record( $bucket, $locator, $encoded['items'][0] );
		}
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
