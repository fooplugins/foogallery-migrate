<?php
namespace FooPlugins\FooGalleryMigrate;
if ( ! defined( 'ABSPATH' ) ) { exit; }

class ImageTagQueueRepository extends ScalarStateRepository {
	const STATE_KEY = 'image-tag-sync';
	private $replacement = null;
	/** Table mode keeps the previous active generation readable until finalize. */
	public function beginReplacement() {
		if ( $this->backend instanceof LegacyTableBackend ) { return $this->backend->image_tag_queue_begin(); }
		$this->replacement = array(); return true;
	}
	/** Handled failures may abandon only this instance's unpublished build. */
	public function abortReplacement() {
		if ( $this->backend instanceof LegacyTableBackend ) { return $this->backend->image_tag_queue_abort(); }
		$this->replacement = null; return true;
	}
	public function getManyBuildRowsByKeys( $keys ) {
		self::exact_keys( $keys );
		if ( $this->backend instanceof LegacyTableBackend ) { return $this->backend->image_tag_queue_build_rows( $keys ); }
		if ( null === $this->replacement ) { return false; }
		$result = array(); foreach ( $keys as $key ) { $result[] = array( 'found' => isset( $this->replacement[$key]), 'key' => $key,
			'value' => isset( $this->replacement[$key] ) ? $this->replacement[$key] : null ); }
		return $result;
	}
	public function applyEngineComputedBuildPage( $rows ) {
		if ( ! is_array( $rows ) || count( $rows ) > 100 ) { return false; }
		if ( $this->backend instanceof LegacyTableBackend ) { return $this->backend->image_tag_queue_build_page( $rows ); }
		if ( null === $this->replacement ) { return false; }
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['attachment_id'] ) || ! is_int( $row['attachment_id'] ) || $row['attachment_id'] < 1 ) { return false; }
			$this->replacement[$row['attachment_id']] = $row;
		}
		return true;
	}
	public function finalizeReplacement( $summary ) {
		if ( ! is_array( $summary ) || array_key_exists( 'items', $summary ) ) { return false; }
		if ( $this->backend instanceof LegacyTableBackend ) { return $this->backend->image_tag_queue_finalize( $summary ); }
		if ( null === $this->replacement ) { return false; }
		$items = array_values( $this->replacement ); $this->replacement = null;
		return $this->replace_snapshot( array_merge( array( 'items' => $items ), $summary ) );
	}
	public function nextBatch( $limit, $cursor = null ) {
		if ( ! is_int( $limit ) || $limit < 1 || $limit > 100 ) { return false; }
		if ( $this->backend instanceof LegacyTableBackend ) { return $this->backend->image_tag_queue_page( $limit, $cursor ); }
		$state = $this->read_snapshot(); if ( ! isset( $state['items'] ) || ! is_array( $state['items'] ) ) { return false; }
		$start = null === $cursor ? 0 : $cursor;
		if ( ! is_int( $start ) || $start < 0 ) { return false; }
		$total = isset( $state['total'] ) ? absint( $state['total'] ) : count( $state['items'] );
		if ( $start > $total ) { return false; }
		$end = min( $total, $start + $limit ); $rows = array();
		for ( $position = $start; $position < $end; ++$position ) {
			$found = isset( $state['items'][$position] );
			$rows[] = array( 'key' => $position, 'found' => $found,
				'value' => $found && is_array( $state['items'][$position] ) ? $state['items'][$position] : array() );
		}
		return array( 'rows' => $rows, 'cursor' => $end >= $total ? null : $end );
	}
	public function applyBatchPatch( $changes, $summary, $revision = null ) {
		if ( ! is_array( $changes ) || count( $changes ) > 100 || ! is_array( $summary ) || array_key_exists( 'items', $summary ) ) { return false; }
		if ( $this->backend instanceof LegacyTableBackend ) { return $this->backend->image_tag_queue_checkpoint( $changes, $summary, $revision ); }
		return $this->change( function ( $state ) use ( $changes, $summary ) {
			if ( ! isset( $state['items'] ) || ! is_array( $state['items'] ) ) { return false; }
			foreach ( $changes as $change ) {
				if ( ! is_array( $change ) || array_keys( $change ) !== array( 'key', 'value' ) || ! array_key_exists( $change['key'], $state['items'] ) ) { return false; }
				$state['items'][$change['key']] = $change['value'];
			}
			return array_replace( $state, $summary );
		} );
	}
	public function summary() {
		if ( $this->backend instanceof LegacyTableBackend ) { return $this->backend->image_tag_queue_summary(); }
		$row = $this->backend->read( self::STATE_KEY );
		if ( ! $row['found'] ) { return array( 'found' => false, 'summary' => array(), 'revision' => null ); }
		$state = $this->decode( self::STATE_KEY, $row['value'] );
		if ( ! is_array( $state ) || ! isset( $state['items'] ) || ! is_array( $state['items'] ) ) { throw new \RuntimeException( 'Invalid image-tag queue state.' ); }
		$total = count( $state['items'] ); unset( $state['items'] );
		return array( 'found' => true, 'summary' => $state, 'revision' => null, 'total' => $total );
	}
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
	public function clear() { return $this->backend instanceof LegacyTableBackend ? $this->backend->image_tag_queue_clear() : $this->delete( self::STATE_KEY ); }
}
