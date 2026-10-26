<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

/**
 * Restricted source-path witness for BOTH retained PHP5 allocators, not a VM.
 * LP64/nondebug/default cookies+protection/alignment; only large allocations
 * <=default VM request, no realloc/rest/small-cache path. Witness traces have
 * one eligible large free block or one exact-size match, so no approximation
 * of tree search is needed. This does NOT establish helper reachability or an
 * arena bound.
 * See docs/storage-option-headroom-prototype.md, bounded retention checkpoint.
 */
class StorageVmRetentionWitness {
	private $segments = array();
	private $allocations = 0;
	private $vm_allocations = 0;
	private $peak_live_vm_pages = 0;
	private $version;

	public function __construct( $version ) {
		if ( ! in_array( $version, array( '5.4.0', '5.6.20' ), true ) ) { $this->fail(); }
		$this->version = $version;
	}
	public function allocate( $id, $request ) {
		if ( ! is_string( $id ) || '' === $id || ! is_int( $request ) || $request < 1024 || $request > 130968 || $this->allocations >= 64 ) { $this->fail(); }
		$size = (int) ( ceil( ( $request + 16 ) / 8 ) * 8 );
		$eligible = array();
		foreach ( $this->segments as $s => $blocks ) {
			foreach ( $blocks as $b => $block ) {
				if ( $id === $block['id'] ) { $this->fail(); }
				if ( null === $block['id'] && $block['size'] >= $size ) { $eligible[] = array( $s, $b ); }
			}
		}
		// Exact-size large blocks win the source search. Refuse all remaining
		// ambiguous searches rather than emulate every tree/cache/rest branch.
		if ( count( $eligible ) > 1 ) {
			$exact = array();
			foreach ( $eligible as $entry ) { if ( $size === $this->segments[$entry[0]][$entry[1]]['size'] ) { $exact[] = $entry; } }
			if ( 1 !== count( $exact ) ) { $this->fail(); }
			$eligible = $exact;
		}
		if ( ! $eligible ) {
			$this->segments[] = array( array( 'id' => null, 'size' => 262144 - 32, 'vm' => false ) );
			$eligible[] = array( array_key_last( $this->segments ), 0 );
		}
		list( $s, $b ) = $eligible[0];
		$remaining = $this->segments[$s][$b]['size'] - $size;
		$vm = 130968 === $request;
		$new = array( array( 'id' => $id, 'size' => $size + ( $remaining < 32 ? $remaining : 0 ), 'vm' => $vm ) );
		if ( $remaining >= 32 ) { $new[] = array( 'id' => null, 'size' => $remaining, 'vm' => false ); }
		array_splice( $this->segments[$s], $b, 1, $new );
		++$this->allocations;
		if ( $vm ) { ++$this->vm_allocations; }
		$this->peak_live_vm_pages = max( $this->peak_live_vm_pages, $this->snapshot()['live_vm_pages'] );
	}
	public function release( $id ) {
		foreach ( $this->segments as $s => $blocks ) {
			foreach ( $blocks as $b => $block ) {
				if ( null === $id || $id !== $block['id'] ) { continue; }
				$this->segments[$s][$b]['id'] = null; $this->segments[$s][$b]['vm'] = false;
				$merged = array();
				foreach ( $this->segments[$s] as $part ) {
					$last = count( $merged ) - 1;
					if ( $last >= 0 && null === $part['id'] && null === $merged[$last]['id'] ) { $merged[$last]['size'] += $part['size']; }
					else { $merged[] = $part; }
				}
				if ( 1 === count( $merged ) && null === $merged[0]['id'] ) { unset( $this->segments[$s] ); }
				else { $this->segments[$s] = $merged; }
				return;
			}
		}
		$this->fail();
	}
	public function snapshot() {
		$vm = 0; $ordinary = 0;
		foreach ( $this->segments as $blocks ) {
			foreach ( $blocks as $block ) {
				if ( null === $block['id'] ) { continue; }
				if ( $block['vm'] ) { ++$vm; } else { $ordinary += $block['size']; }
			}
		}
		return array( 'source_version' => $this->version, 'arena_bytes' => count( $this->segments ) * 262144,
			'live_vm_pages' => $vm, 'peak_live_vm_pages' => $this->peak_live_vm_pages,
			'vm_allocations' => $this->vm_allocations, 'ordinary_live_bytes' => $ordinary );
	}
	public function repeated_child( $parent_free, $reservation, $retained, $calls ) {
		foreach ( array( $parent_free, $reservation, $retained ) as $amount ) {
			if ( ! is_int( $amount ) || $amount < 0 || $amount > 130944 || 0 !== $amount % 8 ) { $this->fail(); }
		}
		if ( $reservation < 1 || $retained > $reservation || ( '5.4.0' === $this->version && $retained !== $reservation ) ||
			! is_int( $calls ) || $calls < 1 || $calls > 64 ) { $this->fail(); }
		// Child return restores the same parent top each time. In 5.6 the
		// reserved argument tail is released at frame_base BEFORE child calls.
		$acquisitions = 0;
		for ( $i = 0; $i < $calls; ++$i ) { if ( $reservation > $parent_free ) { ++$acquisitions; } }
		return array( 'page_acquisitions' => $acquisitions, 'peak_live_child_pages' => $acquisitions ? 1 : 0,
			'child_top_bytes' => $retained, 'reservation_bytes' => $reservation );
	}
	private function fail() { throw new \InvalidArgumentException( 'witness_domain' ); }
}
