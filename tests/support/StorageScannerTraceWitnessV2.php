<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

require_once __DIR__ . '/StorageFixedHelperBoundsV2.php';

/**
 * OFFLINE, conditional PHP5 trace-argument source branch; NOT a complete trace
 * model, allocator simulation, native runtime execution or admission estimate.
 * See docs/storage-scanner-trace-dependency.md for the precise stopping premise.
 */
class StorageScannerTraceWitnessV2 {
	private $version;
	private $frames;

	public function __construct( $version, $helper_hash = null ) {
		$this->frames = new StorageFixedHelperBoundsV2( $version );
		if ( null !== $helper_hash ) { $this->frames->assert_helper_hash( $helper_hash ); }
		$this->version = $version;
	}

	public function bindings() {
		$sources = array(
			'5.4.0' => array(
				'Zend/zend_exceptions.c' => 'e1a0cc59b1bad59035c5c211e4be7cac046f726944d2245068b6c8bd0b930f9e',
				'Zend/zend_builtin_functions.c' => '55ff516ed35df6619457939ac45d305a3362bfd86ef77400c86d470974c7fb9d',
				'Zend/zend.h' => 'e5d352e69218966ece14affcf101c9e4848f4a51042a37147ec6e24015fac377',
				'Zend/zend_variables.c' => '63b499b0869a343056c6477c0c21f6006aa5137efc121f33a49ac4cd2ad09267' ),
			'5.6.20' => array(
				'Zend/zend_exceptions.c' => 'b8057664f911c044cacf9f4130231b62aafbc888a4ef69d7da6ccfee9e0193d6',
				'Zend/zend_builtin_functions.c' => 'ff69225481fad3fdf380eed0a13e9a40ffb79d8d6201a26e3185a00012922d9c',
				'Zend/zend.h' => 'dafb6da9a2df7178e2a1779ccb05b96686164c9f90be5742a648192096f1aa4f',
				'Zend/zend_variables.c' => '0bf09e05f4fc9e6c7bee066cd396271619c5c85ecc34a17c3522a4b28685f8c2' ),
		);
		return array( 'source_version' => $this->version, 'helper_sha256' => $this->frames->bindings()['helper'],
			'trace_sources' => $sources[$this->version], 'compiler_and_VM_sources' => $this->frames->bindings()['sources'] );
	}

	public function argument( $raw_bytes, $refcount, $is_ref, $interned ) {
		if ( ! is_int( $raw_bytes ) || $raw_bytes < 0 || $raw_bytes > 60000 ||
			! is_int( $refcount ) || $refcount < 1 || ! is_bool( $is_ref ) || ! is_bool( $interned ) ) {
			throw new \InvalidArgumentException( 'scanner_trace_witness_domain' );
		}
		// builtin_functions: get_args -> zend.h SEPARATE_ZVAL_TO_MAKE_IS_REF
		// -> SEPARATE_ZVAL -> variables.c noninterned string copy constructor.
		$box = ! $is_ref && $refcount > 1;
		$request = $box && ! $interned ? $raw_bytes + 1 : 0;
		// Conditional LP64, nondebug, cookies/protection off, alignment8. The
		// nominal true size is NOT retained arena capacity or split-tail debt.
		$block = $request ? max( 32, (int) ( ceil( ( $request + 16 ) / 8 ) * 8 ) ) : 0;
		return array( 'phase' => 'exception_trace_capture', 'new_box' => $box,
			'new_backing_request' => $request, 'nominal_block_bytes' => $block,
			'covered_by_short_backing_premise' => $request <= 136 );
	}

	public function report() {
		$cache = array();
		foreach ( $this->frames->descriptors() as $method => $descriptor ) {
			$cache[$method] = 8 * $descriptor['cache_slots_upper'];
		}
		$inventory = $this->frames->envelope( 16 );
		$copy = $this->argument( 60000, 2, false, false );
		// IF the large-block search selects an isolated former VM hole:
		// alloc.c split branch has remainder >=minimum32; no coalescing with a
		// USED trace backing block until that backing storage is released.
		$vm_block = 130984; // source VM emalloc(130968), aligned header16.
		$remainder = $vm_block - $copy['nominal_block_bytes'];
		return array( 'status' => 'PRECISE_TRACE_DEPENDENCY_NOT_HEADROOM_ACCEPTANCE',
			'accepted_context' => array( 'raw_bytes' => 60000, 'nodes' => 8003, 'arrays' => 16, 'depth' => 16 ),
			'separate_rejection_diagnostic_depth' => 64,
			'ordinary_sites' => array(
				'raw_alias' => array( 'new_backing_request' => 0, 'phase' => 'ordinary_lexical_scan_only' ),
				'byte_index' => array( 'request_upper' => 2, 'lifetime' => 'repeating_transient' ),
				'token_substring' => array( 'request_upper' => 129, 'lifetime' => 'until_token_or_scan_return' ),
				'literal_substring' => array( 'request_upper' => 3, 'lifetime' => 'transient_compare' ),
				'scalar_cast_and_concat' => array( 'request_upper' => 23, 'lifetime' => 'transient' ) ),
			'fixed_method_cache_requests' => $cache,
			'fixed_method_cache_lifetime' => 'at_most_once_per_seven_op_arrays_not_per_node',
			'inherited_payload_inventory_not_charge' => $inventory['scanner_box_and_string_payload_cap'],
			'inherited_cache_inventory_not_charge' => $inventory['fixed_method_cache_block_cap'],
			'ordinary_inventory_complete_allocator_charge' => false,
			'falsified_premise' => 'raw_arguments_remain_backing_aliases_during_exception_trace_capture',
			'conditional_raw_argument_copy' => $copy,
			'conditional_page_hole' => array( 'former_VM_block_bytes' => $vm_block,
				'remainder_bytes' => $remainder, 'remainder_can_fit_vm_block' => $remainder >= $vm_block,
				'proves_actual_placement' => false, 'proves_segment_growth' => false ),
			'live_page_bounds_not_retained_capacity' => array( 'stable' => $inventory['stable_added_pages_upper'],
				'transfer' => $inventory['transfer_added_pages_upper'] ),
			'arena_retention_proven' => false, 'fits_justified_helper_budget' => false,
			'missing_terms' => array( 'complete_trace_capacity_family', 'scanner_box_and_cache_lowering',
				'exceptional_VM_page_retention', 'allocator_cache_rest_resize_frontiers' ) );
	}
}
