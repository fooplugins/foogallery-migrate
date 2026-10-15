<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

require_once __DIR__ . '/StorageScannerTraceCapacity.php';

/** OFFLINE source-bound finite product suffix, conditional LP64/NTS only. */
class StorageProductCallerTrace {
	private $local;
	private $version;
	public function __construct( $version ) {
		$this->local = new StorageScannerTraceCapacity( $version ); $this->version = $version;
	}
	public function bindings() {
		return array( 'product_files' => array(
			'includes/class-storage-conversion.php' => 'ef8edcef2bfa56e1d2ef1ef4a90e278ac701249c85e51395c0ee615c4d9eeccb',
			'includes/class-storage-operations.php' => '6564431c7b92e1e5f0e25f046c4c2d0cde7c58dc50afc27864670b47b0872fba',
			'includes/class-storage-control.php' => '79a800d83585512117a3c672c2df2e6e0b90a7fa8aaa9b1c297c8b085a9615a4',
			'includes/class-legacy-option-decoder.php' => 'cf7ef2038a5395f70d64f9f2702c3735066a74048cb00dfddc0f97202b1777c3' ),
			'original_material_members_manifest_sha256' => '2b304920b0de417aa34d8263629a24bec279052919b0fabb98da207686fa826a',
			'local_bindings' => ( new StorageScannerTraceWitness( $this->version ) )->bindings() );
	}
	/** Closed OUTER key shapes; values are shared, not recursively copied. */
	public function shapes() {
		return array( 'request' => array( 'blog_id', 'scope', 'target', 'confirmation', 'nonce' ),
			'source' => array( 'physical', 'value' ),
			'record' => array( 'version', 'blog_id', 'backend', 'epoch', 'switch_id', 'from', 'to', 'phase', 'table_id',
				'source_digest', 'logical_digest', 'schema', 'codec', 'created', 'updated', 'retained_digest' ) );
	}
	private function frame( $class, $method, $arguments ) {
		return array( 'class' => 'FooPlugins\\FooGalleryMigrate\\' . $class, 'method' => $method, 'arguments' => $arguments );
	}
	private function edge( $file, $line, $callee, $expression ) {
		return array( 'file' => 'includes/class-storage-' . $file . '.php', 'line' => $line, 'callee' => $callee, 'expression' => $expression );
	}
	/** All five decode-reaching option_snapshot callers; no table/encode path invented. */
	public function paths() {
		$out = array();
		foreach ( array( 'switch', 'replace', 'recover' ) as $operation ) {
			foreach ( array( 'direct' => null, 'copying' => 185, 'verified' => 192, 'flipped' => 213 ) as $phase => $line ) {
				// Replacement is authorized ONLY toward table. Its advance branches
				// use table_snapshot, never the option decode being modeled here.
				if ( 'replace' === $operation && null !== $line ) { continue; }
				foreach ( array( false, true ) as $handle ) {
					$f = array( $this->frame( 'StorageConversion', 'option_snapshot', array() ) );
					$e = array( $this->edge( 'conversion', 284, 'decode', '->decode( $raw )' ) );
					if ( null !== $line ) {
						$f[] = $this->frame( 'StorageConversion', 'advance', array( 'record', 'source' ) );
						$e[] = $this->edge( 'conversion', $line, 'option_snapshot', '$this->option_snapshot()' );
					}
					if ( 'recover' === $operation ) {
						$f[] = $this->frame( 'StorageConversion', 'recover', array( 'request' ) );
						$e[] = $this->edge( 'conversion', null === $line ? 112 : 115, null === $line ? 'option_snapshot' : 'advance', null === $line ? '$this->option_snapshot()' : '$this->advance( $r, $source )' );
					} else {
						$f[] = $this->frame( 'StorageConversion', 'switch_storage', array( 'request', 'replacement' ) );
						$e[] = $this->edge( 'conversion', null === $line ? 67 : 91, null === $line ? 'option_snapshot' : 'advance', null === $line ? '$this->option_snapshot()' : '$this->advance( $record, $source )' );
						$method = 'replace' === $operation ? 'replace_retained' : 'switch_backend';
						$f[] = $this->frame( 'StorageConversion', $method, array( 'request' ) );
						$e[] = $this->edge( 'conversion', 'replace' === $operation ? 43 : 38, 'switch_storage', '$this->switch_storage( $request, ' . ( 'replace' === $operation ? 'true' : 'false' ) . ' )' );
					}
					$f[] = $this->frame( 'StorageOperations', 'dispatch', array( 'operation', 'request' ) );
					$callee = 'recover' === $operation ? 'recover' : ( 'replace' === $operation ? 'replace_retained' : 'switch_backend' );
					$e[] = $this->edge( 'operations', 'replace' === $operation ? 35 : 36, $callee, '->' . $callee . '( $request )' );
					if ( $handle ) {
						$f[] = $this->frame( 'StorageOperations', 'handle', array() );
						$e[] = $this->edge( 'operations', 57, 'dispatch', '->dispatch( $operation, $request )' );
					}
					$out[] = array( 'operation' => $operation, 'phase' => $phase, 'receiver_handle' => $handle,
						'decode_direction' => null === $line ? 'option_to_table' : 'table_to_option',
						'frames' => $f, 'edges' => $e, 'source_may_be_null' => 'recover' === $operation && 'flipped' === $phase,
						'outer_boundary' => 'unresolved_external_receiver' );
				}
			}
		}
		return $out;
	}
	public function nominal( $request ) { return $this->local->nominal( $request ); }
	/** Copy initializes at num_elements, NOT the original historical capacity. */
	public function shallow_array( $keys ) {
		$v = $this->local->hash_vector( count( $keys ) );
		$bytes = $this->nominal( 72 ) + $v['vector_nominal'];
		foreach ( $keys as $key ) { $bytes += $this->nominal( 72 + strlen( $key ) + 1 ); }
		return $bytes; // GC box is charged by visit(), shared children only addrefs.
	}
	public function slots( $path ) {
		$out = array();
		foreach ( $path['frames'] as $i => $frame ) {
			foreach ( $frame['arguments'] as $j => $kind ) {
				$out[] = array( 'slot' => $i . ':' . $j, 'identity' => $kind, 'kind' => $kind,
					'refcount' => 2, 'is_ref' => false );
			}
		}
		return $out;
	}
	/** Conditional per-slot separation; identities describe sharing, not dedup keys. */
	public function visit( $slots ) {
		$seen = array(); $boxes = 0; $arrays = 0; $bytes = 0; $shapes = $this->shapes();
		foreach ( $slots as $s ) {
			if ( ! isset( $s['slot'], $s['identity'], $s['kind'], $s['refcount'], $s['is_ref'] ) ||
				! is_string( $s['slot'] ) || ! is_string( $s['identity'] ) || ! is_int( $s['refcount'] ) || $s['refcount'] < 1 || ! is_bool( $s['is_ref'] ) ||
				! in_array( $s['kind'], array( 'request', 'record', 'source', 'replacement', 'operation' ), true ) ) { throw new \InvalidArgumentException( 'product_trace_slot' ); }
			if ( isset( $seen[$s['slot']] ) ) { continue; } $seen[$s['slot']] = true;
			if ( $s['is_ref'] || 1 === $s['refcount'] ) { continue; }
			++$boxes;
			if ( isset( $shapes[$s['kind']] ) ) { ++$arrays; $bytes += $this->shallow_array( $shapes[$s['kind']] ); }
			elseif ( 'operation' === $s['kind'] ) { $bytes += $this->nominal( 8 ); } // recover/replace7+NUL.
		}
		return array( 'new_boxes' => $boxes, 'new_shallow_arrays' => $arrays, 'backing_nominal' => $bytes );
	}
	public function join_vector( $local_frames, $suffix_frames ) {
		$a = $this->local->hash_vector( $local_frames ); $this->local->hash_vector( $suffix_frames );
		// Validate each input before adding. There is ONE trace array shell and
		// ONE vector. Local old/new overlap is replaced, never double charged.
		$v = $this->local->hash_vector( $local_frames + $suffix_frames );
		return array( 'capacity' => $v['capacity'], 'catch_delta' => $v['vector_nominal'] - $a['vector_nominal'],
			'resize_overlap_delta' => $v['old_vector_overlap'] - $a['old_vector_overlap'],
			'catch_nominal' => $v['vector_nominal'], 'construction_nominal' => $v['vector_nominal'] + $v['old_vector_overlap'] );
	}
	private function array_shell( $n ) {
		return $this->nominal( 32 ) + $this->nominal( 72 ) + $this->local->hash_vector( $n )['vector_nominal'];
	}
	private function suffix_material( $path, $file ) {
		$bytes = 0;
		foreach ( $path['frames'] as $frame ) {
			$n = count( $frame['arguments'] );
			$bytes += $this->nominal( 72 ); // new outer trace bucket; not a new trace shell/vector.
			$bytes += $this->array_shell( 6 );
			foreach ( array( 'file', 'line', 'function', 'class', 'type', 'args' ) as $key ) { $bytes += $this->nominal( 72 + strlen( $key ) + 1 ); }
			$bytes += 5 * $this->nominal( 32 ) + $this->nominal( $file + 1 ) +
				$this->nominal( strlen( $frame['method'] ) + 1 ) + $this->nominal( strlen( $frame['class'] ) + 1 ) + $this->nominal( 3 );
			$bytes += $this->array_shell( $n ) + $n * $this->nominal( 72 );
		}
		$s = $this->visit( $this->slots( $path ) );
		return $bytes + $s['new_boxes'] * $this->nominal( 32 ) + $s['backing_nominal'];
	}
	public function envelope( $raw, $depth, $source_file_bytes, $rejected = false ) {
		$local = $this->local->envelope( $raw, $depth, $source_file_bytes, $rejected );
		$paths = $this->paths(); $cases = array(); $catch = 0; $construction = 0; $frames = 0;
		foreach ( $local['cases'] as $local_case ) {
			$l = $local_case['material'];
			foreach ( $paths as $id => $path ) {
				$suffix = $this->suffix_material( $path, $source_file_bytes ); $v = $this->join_vector( $l['frames'], count( $path['frames'] ) );
				$c = $l['catch_nominal'] + $suffix + $v['catch_delta'];
				$b = $l['construction_nominal'] + $suffix + $v['catch_delta'] + $v['resize_overlap_delta'];
				$n = $l['frames'] + count( $path['frames'] );
				$catch = max( $catch, $c ); $construction = max( $construction, $b ); $frames = max( $frames, $n );
				$cases[] = array( 'path_id' => $id, 'local_methods' => $local_case['methods_in_visitation_order'], 'local' => $l,
					'joined_frames' => $n, 'suffix_material_nominal' => $suffix, 'vector' => $v, 'catch_nominal' => $c, 'construction_nominal' => $b );
			}
		}
		return array( 'status' => 'CONDITIONAL_FINITE_PRODUCT_TRACE_NOMINAL_PREREQUISITE_ONLY', 'abi' => 'LP64_NTS_NONDEBUG_ALIGNMENT8_ZEND_MM',
			'source_version' => $this->version, 'bindings' => $this->bindings(), 'domain' => $local['domain'],
			'paths' => $paths, 'cases' => $cases, 'max_joined_frames' => $frames, 'catch_nominal_upper' => $catch, 'construction_nominal_upper' => $construction,
			'filename_extent_proven' => false, 'existing_product_argument_graph_nominal_upper' => null, 'external_receiver_trace_nominal_upper' => null,
			'object_store_resize_nominal_upper' => null, 'whole_operation_nominal_upper' => null, 'retained_arena_upper' => null,
			'arena_retention_proven' => false, 'shared_gc_frontier_proven' => false, 'complete_trace_capacity_proven' => false,
			'fits_justified_helper_budget' => false, 'task_scope_completed' => false, 'children_released' => false );
	}
}
