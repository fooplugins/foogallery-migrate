<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

/**
 * Finite native-scale refusal trace extension. Not all scale operations, compiler
 * frames, callbacks, allocator capacity, hydration or a whole-operation bound.
 * Returned dependencies are bound, but NEVER invented as active trace frames.
 */
class StorageNativeScaleTrace {
	public static function dependencies() {
		return array(
			'StorageScaleAdmission' => array( 'tick', 'admit_memory', 'valid', 'now', 'limit', 'checked_add', 'memory_sample', 'fail' ),
			'StoragePreflight' => array( 'decode_budget', 'memory_bytes', 'nonnegative_integer', 'policy_error', 'refused_error', 'error' ),
			'StorageMemoryBaseline' => array( 'sample' ),
			'StorageScalarObserver' => array( 'milestone', 'refusal' ) );
	}
	public static function assert_domain() {
		$c = 'FooPlugins\\FooGalleryMigrate\\StorageScalarObserver';
		foreach ( array( 'enabled', 'milestones_enabled' ) as $name ) {
			$p = new \ReflectionProperty( $c, $name );
			if ( false !== $p->getValue() ) { throw new \RuntimeException( 'diagnostic_observer_domain' ); }
		}
		// File bindings belong to caller v3. These method boundaries make the
		// transitive native domain explicit rather than treating the file as a
		// hash-only replacement for a semantic witness.
		foreach ( self::dependencies() as $class => $methods ) {
			foreach ( $methods as $method ) { new \ReflectionMethod( 'FooPlugins\\FooGalleryMigrate\\' . $class, $method ); }
		}
	}
	public static function paths( $depth ) {
		if ( ! is_int( $depth ) || $depth < 0 || $depth > 64 ) { throw new \InvalidArgumentException( 'native_scale_trace_domain' ); }
		$s = 'StorageScaleAdmission::'; $d = 'LegacyOptionDecoder::';
		$out = array();
		// decode entry/end tick, and scan tick BEFORE its depth guard. One path
		// represents both decode tick sites; no sum across invocations/siblings.
		foreach ( array_merge( array( 0 ), range( 1, $depth + 2 ) ) as $scans ) {
			$out[] = array_merge( array( $s . 'fail', $s . 'tick', $d . 'tick' ), array_fill( 0, $scans, $d . 'scan' ), array( $d . 'decode' ) );
		}
		// The pre/post-hydration admit_memory sites have identical active frame
		// shapes. tick can refuse inside admit_memory; otherwise it has returned.
		$out[] = array( $s . 'fail', $s . 'tick', $s . 'admit_memory', $d . 'decode' );
		$out[] = array( $s . 'fail', $s . 'admit_memory', $d . 'decode' );
		return $out;
	}
	public static function cases( $local, $raw, $depth, $file ) {
		self::assert_domain(); $cases = array();
		foreach ( self::paths( $depth ) as $path ) {
			$cases[] = array( 'methods_in_visitation_order' => $path, 'material' => $local->path_material( $path, $raw, $file ),
				'failure_origin' => 'native_scale_refusal', 'native_hydration_overlap_nominal_upper' => null );
		}
		return $cases;
	}
}
