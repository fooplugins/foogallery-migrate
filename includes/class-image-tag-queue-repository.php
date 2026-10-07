<?php
namespace FooPlugins\FooGalleryMigrate;
if ( ! defined( 'ABSPATH' ) ) { exit; }

class ImageTagQueueRepository extends ScalarStateRepository {
	const STATE_KEY = 'image-tag-sync';
	public function read_snapshot() { return $this->get( self::STATE_KEY, array() ); }
	public function replace_snapshot( $state ) {
		if ( ! is_array( $state ) ) { return false; }
		return $this->write_values( array( self::STATE_KEY => $state ) );
	}
	private function change( $change ) {
		if ( $this->backend instanceof LegacyTableBackend ) { return $this->backend->change_image_tag_queue( $change ); }
		return $this->backend->mutate( function ( $data ) use ( $change ) {
			$state = array_key_exists( self::STATE_KEY, $data ) ? $data[self::STATE_KEY] : array();
			if ( ! is_array( $state ) ) { return false; }
			$state = call_user_func( $change, $state );
			if ( false === $state ) { return false; }
			$data[self::STATE_KEY] = $state; return $data;
		} );
	}
	public function update_with_sync_state( $items, $state ) {
		if ( ! is_array( $items ) || ! is_array( $state ) || array_key_exists( 'items', $state ) ) { return false; }
		return $this->change( function ( $current ) use ( $items, $state ) {
			$current = array_replace( $current, $state ); $current['items'] = $items; return $current;
		} );
	}
	public function update_item( $key, $item, $state ) {
		self::exact_keys( array( $key ) );
		if ( ! is_array( $state ) || array_key_exists( 'items', $state ) ) { return false; }
		return $this->change( function ( $current ) use ( $key, $item, $state ) {
			if ( ! isset( $current['items'] ) || ! is_array( $current['items'] ) || ! array_key_exists( $key, $current['items'] ) ) { return false; }
			$current = array_replace( $current, $state ); $current['items'][$key] = $item; return $current;
		} );
	}
	public function append_error( $error ) {
		return $this->change( function ( $state ) use ( $error ) {
			if ( isset( $state['errors'] ) && ! is_array( $state['errors'] ) ) { return false; }
			if ( ! isset( $state['errors'] ) ) { $state['errors'] = array(); }
			$state['errors'][] = $error; return $state;
		} );
	}
	public function clear() { return $this->delete( self::STATE_KEY ); }
}
