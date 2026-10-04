<?php
namespace FooPlugins\FooGalleryMigrate;
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Raw content findings; reads do not implicitly import legacy settings. */
class ContentScanRepository extends ScalarStateRepository {
	const STATE_KEY = 'block-shortcode_scan_state';
	private function state( $data ) {
		if ( array_key_exists( self::STATE_KEY, $data ) ) {
			$state = $data[self::STATE_KEY];
			if ( ! is_array( $state ) || ! isset( $state['items'], $state['progress'] ) || ! is_array( $state['items'] ) || ! is_array( $state['progress'] ) ) {
				throw new \RuntimeException( 'Invalid content scan state. No legacy fallback.' );
			}
			return $state;
		}
		$items = isset( $data['block-shortcode'] ) && is_array( $data['block-shortcode'] ) ? $data['block-shortcode'] : array();
		$progress = isset( $data['block-shortcode_scan_progress'] ) && is_array( $data['block-shortcode_scan_progress'] ) ? $data['block-shortcode_scan_progress'] : array();
		return array( 'items' => $items, 'progress' => $progress );
	}
	public function metadata() { return $this->state( $this->backend->read_all() )['progress']; }
	public function read_snapshot() { return $this->state( $this->backend->read_all() )['items']; }
	private function change( $change ) {
		return $this->backend->mutate( function ( $data ) use ( $change ) {
			$state = call_user_func( $change, $this->state( $data ) );
			if ( false === $state ) { return false; }
			$data[self::STATE_KEY] = $state; return $data;
		} );
	}
	public function replace_snapshot( $items ) {
		if ( ! is_array( $items ) ) { return false; }
		return $this->change( function ( $state ) use ( $items ) { $state['items'] = $items; return $state; } );
	}
	public function update_with_scan_state( $items, $progress ) {
		if ( ! is_array( $items ) || ! is_array( $progress ) ) { return false; }
		return $this->change( function ( $state ) use ( $items, $progress ) { $state['items'] = $items; $state['progress'] = $progress; return $state; } );
	}
	public function replace_post_matches( $post_id, $matches, $progress ) {
		if ( ! is_array( $matches ) || ! is_array( $progress ) ) { return false; }
		foreach ( $matches as $item ) {
			if ( ! is_array( $item ) || ! array_key_exists( 'post_id', $item ) || $post_id !== $item['post_id'] ) { return false; }
		}
		return $this->change( function ( $state ) use ( $post_id, $matches, $progress ) {
			$items = $this->without_post( $state['items'], $post_id );
			foreach ( $matches as $key => $item ) {
				if ( array_key_exists( $key, $items ) ) { return false; }
				$items[$key] = $item;
			}
			$state['items'] = $items; $state['progress'] = $progress; return $state;
		} );
	}
	private function without_post( $items, $post_id ) {
		foreach ( $items as $key => $item ) {
			if ( is_array( $item ) && array_key_exists( 'post_id', $item ) && $post_id === $item['post_id'] ) { unset( $items[$key] ); }
		}
		return $items;
	}
	public function remove_post( $post_id ) {
		return $this->change( function ( $state ) use ( $post_id ) { $state['items'] = $this->without_post( $state['items'], $post_id ); return $state; } );
	}
	public function update_match( $key, $item ) {
		self::exact_keys( array( $key ) );
		return $this->change( function ( $state ) use ( $key, $item ) {
			if ( ! array_key_exists( $key, $state['items'] ) ) { return false; }
			$state['items'][$key] = $item; return $state;
		} );
	}
	public function clear() {
		return $this->update_with_scan_state( array(), array() );
	}
}
