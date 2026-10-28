<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

/**
 * OFFLINE fixed-source upper descriptors and restricted source transitions.
 * No compiler emulator, tokenizer, native decoder, admission or arena claim.
 * See docs/storage-fixed-helper-bounds.md for source-site lowering obligations.
 */
class StorageFixedHelperBounds {
	const PAYLOAD = 130944;
	private $version;
	private $descriptors;
	private $pages;
	private $acquisitions;
	private $peak;
	private $transfer_frees;

	public function __construct( $version ) {
		if ( ! in_array( $version, array( '5.4.0', '5.6.20' ), true ) ) { $this->fail(); }
		$this->version = $version;
		$this->assert_helper_hash( hash_file( 'sha256', dirname( __DIR__, 2 ) . '/includes/class-legacy-option-decoder.php' ) );
		// Per SOURCE LINE ceilings, including mutually exclusive paths. No loop
		// iteration multiplier: T is allocated by the compiler, not by scan().
		$sites = array(
			'decode' => array( 30 => 12, 31 => 2, 32 => 2, 33 => 4, 37 => 10, 38 => 7, 39 => 1 ),
			'scan' => array( 94 => 12, 95 => 2, 96 => 6, 97 => 3, 98 => 3, 99 => 5,
				101 => 3, 102 => 5, 105 => 3, 106 => 8, 109 => 3, 110 => 8, 113 => 3, 114 => 2,
				116 => 5, 117 => 2, 118 => 2, 121 => 3, 124 => 12, 125 => 2, 126 => 4,
				127 => 3, 128 => 3, 130 => 2, 133 => 2 ),
			'unsigned' => array( 137 => 3, 138 => 12, 139 => 1 ),
			'token' => array( 143 => 1, 144 => 8, 145 => 3, 146 => 5, 147 => 1, 149 => 1, 151 => 2 ),
			'literal' => array( 155 => 6, 156 => 2 ),
			'fail' => array( 160 => 4 ),
		);
		// CVs include this even when the compiler does not need it. Call depth
		// and argument slots are SYNTACTIC maxima, not count(all calls).
		$shape = array( 'decode' => array( 5, 1, 6, 28 ), 'scan' => array( 11, 1, 6, 58 ),
			'unsigned' => array( 5, 1, 5, 6 ), 'token' => array( 7, 1, 4, 6 ),
			'literal' => array( 4, 2, 4, 8 ), 'fail' => array( 1, 1, 2, 4 ) );
		foreach ( $sites as $method => $lines ) {
			list( $cv, $calls, $args, $cache ) = $shape[$method]; $t = array_sum( $lines );
			$c = ( '5.4.0' === $version ? 144 : 136 ) + 16 * $cv + 32 * $t + ( '5.6.20' === $version ? 32 * $calls : 0 );
			$this->descriptors[$method] = array( 'CV_upper' => $cv, 'T_upper' => $t,
				'call_slots_upper' => $calls, 'argument_slots_upper' => $args, 'cache_slots_upper' => $cache,
				'temporary_sites' => $lines, 'C_upper' => $c, 'R_upper' => $c + ( '5.6.20' === $version ? 8 * $args : 0 ) );
		}
	}
	public function bindings() {
		$all = array(
			'5.4.0' => array( 'zend_compile.c' => '8f2a2224e28c51cb08e7e33696d9f526d57faa89310da2c13194ae754cc1beb5',
				'zend_compile.h' => 'a468e8eff789011516a7bfacf9ab4f2ff92eccb08b66888623e40885ac6cc27b',
				'zend_execute.h' => 'f9684411b47e336cdee10b64d9186662412330a1845856bb62d80032c95d3f1a',
				'zend_vm_execute.h' => '944c1e97d49750be9d55a17b87f3147a24fd5a5425de86b5486ad5272e947798' ),
			'5.6.20' => array( 'zend_compile.c' => '5b2ea003d5294971dd8503bd3b0981c537214d65e5de95f8c7e900ecae0e2aa4',
				'zend_compile.h' => '9c7e6ed4e248d9971934aa26d6008b75b177581208509fc48fc3a92b19953dea',
				'zend_execute.h' => '267e9979939104973d4562737ed43c386325fdc5a62ed395c73b31c49f08a5c8',
				'zend_execute.c' => '81cd57f54b7b1489e22048d217b6278f4d672bfc89f05ade9bbaa162032d4df6' ),
		);
		return array( 'helper' => 'cf7ef2038a5395f70d64f9f2702c3735066a74048cb00dfddc0f97202b1777c3',
			'sources' => isset( $all[$this->version] ) ? $all[$this->version] : array() );
	}
	public function descriptors() { return $this->descriptors; }
	public function assert_helper_hash( $hash ) { if ( $hash !== $this->bindings()['helper'] ) { $this->fail(); } }
	private function residual( $free ) { if ( ! is_int( $free ) || $free < 0 || $free > self::PAYLOAD || 0 !== $free % 8 ) { $this->fail(); } }
	public function frame( $method, $free ) {
		$this->residual( $free ); if ( ! isset( $this->descriptors[$method] ) ) { $this->fail(); }
		$d = $this->descriptors[$method]; $new = $d['R_upper'] > $free;
		return array( 'acquired_pages' => $new ? 1 : 0, 'child_top_bytes' => $d['C_upper'],
			'free_at_child_top' => ( $new ? self::PAYLOAD : $free ) - $d['C_upper'] );
	}
	private function reset( $free ) {
		$this->residual( $free ); $this->pages = array( ( self::PAYLOAD - $free ) / 8 );
		$this->acquisitions = 0; $this->peak = 0; $this->transfer_frees = 0;
	}
	private function acquire() { $this->pages[] = 0; ++$this->acquisitions; $this->peak = max( $this->peak, count( $this->pages ) - 1 ); }
	private function reserve( $slots ) {
		$i = count( $this->pages ) - 1;
		if ( $slots > self::PAYLOAD / 8 - $this->pages[$i] ) { $this->acquire(); ++$i; }
		$mark = array( $i, $this->pages[$i] ); $this->pages[$i] += $slots; return $mark;
	}
	private function release( $mark ) {
		list( $i, $top ) = $mark;
		if ( $i !== count( $this->pages ) - 1 ) { $this->fail(); }
		if ( 0 === $top && $i > 0 ) { array_pop( $this->pages ); } else { $this->pages[$i] = $top; }
	}
	private function push54( $argc ) {
		for ( $a = 0; $a < $argc; ++$a ) { $this->reserve( 1 ); }
		$i = count( $this->pages ) - 1;
		// execute.h305-325: full current page OR split arguments transfer, even
		// though each individual argument push already passed its own check.
		if ( $this->pages[$i] < $argc || self::PAYLOAD / 8 === $this->pages[$i] ) {
			$this->acquire(); $target = count( $this->pages ) - 1;
			for ( $a = 0; $a < $argc; ++$a ) {
				--$this->pages[$i];
				if ( 0 === $this->pages[$i] && $i > 0 ) {
					array_splice( $this->pages, $i, 1 ); --$target; --$i; ++$this->transfer_frees;
				}
			}
			$this->pages[$target] = $argc + 1; return array( $target, 0 );
		}
		$base = $this->pages[$i] - $argc; ++$this->pages[$i]; return array( $i, $base );
	}
	public function arguments54( $free, $argc ) {
		if ( '5.4.0' !== $this->version || ! is_int( $argc ) || $argc < 0 || $argc > 5 ) { $this->fail(); }
		$this->reset( $free ); $mark = $this->push54( $argc ); $this->release( $mark );
		return array( 'acquisitions' => $this->acquisitions, 'peak_added_pages' => $this->peak,
			'freed_during_transfer' => $this->transfer_frees, 'argument_bytes' => 8 * ( $argc + 1 ),
			'remaining_added_pages' => count( $this->pages ) - 1 );
	}
	public function path( $methods, $free, $iterations = 1 ) {
		$edges = array( 'decode' => array( 'scan', 'fail' ), 'scan' => array( 'scan', 'unsigned', 'token', 'literal', 'fail' ),
			'unsigned' => array( 'token', 'fail' ), 'token' => array( 'fail' ), 'literal' => array( 'fail' ), 'fail' => array() );
		$arity = array( 'decode' => 1, 'scan' => 5, 'unsigned' => 3, 'token' => 4, 'literal' => 3, 'fail' => 0 );
		if ( ! is_array( $methods ) || ! $methods || array_keys( $methods ) !== range( 0, count( $methods ) - 1 ) || count( $methods ) > 71 || ! is_int( $iterations ) || $iterations < 1 || $iterations > 64 ) { $this->fail(); }
		foreach ( $methods as $n => $method ) {
			if ( ! isset( $edges[$method] ) || ( $n && ! in_array( $method, $edges[$methods[$n - 1]], true ) ) ) { $this->fail(); }
		}
		$this->reset( $free );
		for ( $repeat = 0; $repeat < $iterations; ++$repeat ) {
			$marks = array();
			foreach ( $methods as $n => $method ) {
				if ( $n ) {
					if ( '5.4.0' === $this->version ) { $marks[] = $this->push54( $arity[$method] ); }
					else {
						$i = count( $this->pages ) - 1; $slots = $arity[$method] + 1;
						if ( $this->pages[$i] + $slots > self::PAYLOAD / 8 ) { $this->fail(); }
						$marks[] = array( $i, $this->pages[$i] ); $this->pages[$i] += $slots;
					}
				}
				$d = $this->descriptors[$method]; $mark = $this->reserve( $d['R_upper'] / 8 );
				$marks[] = $mark; $this->pages[$mark[0]] = $mark[1] + $d['C_upper'] / 8;
			}
			foreach ( array_reverse( $marks ) as $mark ) { $this->release( $mark ); }
		}
		return array( 'iterations' => $iterations, 'acquisitions' => $this->acquisitions,
			'per_iteration_acquisitions' => $this->acquisitions / $iterations,
			'peak_added_pages' => $this->peak, 'remaining_added_pages' => count( $this->pages ) - 1,
			'upper_descriptor_witness_not_exact_compiled_trace' => true );
	}
	public function envelope( $depth ) {
		if ( ! is_int( $depth ) || $depth < 0 || $depth > 64 ) { $this->fail(); }
		// Extra scan for rejected depth+1, plus unsigned/token/fail even though
		// that rejected scan cannot reach them: a conservative simultaneous cap.
		$path = array_merge( array( 'decode' ), array_fill( 0, $depth + 2, 'scan' ), array( 'unsigned', 'token', 'fail' ) );
		$payload = 0; $largest = 0; $scanner_boxes = 0; $cache = 0;
		foreach ( $path as $method ) {
			$d = $this->descriptors[$method]; $payload += $d['C_upper'] + 48;
			$largest = max( $largest, $d['R_upper'] );
			// Payload-only capacity, NOT a discharged ealloc/cache/arena term.
			$scanner_boxes += 32 * ( $d['CV_upper'] + $d['T_upper'] + 6 ) + 136 * $d['T_upper'];
		}
		foreach ( $this->descriptors as $d ) { $cache += 8 * $d['cache_slots_upper'] + 40; }
		// Noncurrent helper pages left by a checked growth have residual <Rmax.
		// 5.4 push_args can then move <=5 pointers off such a page. Its transient
		// split-pack transfer can hold one additional page BEFORE freeing it.
		// These are LIVE bounds conditional on the descriptors, never historical
		// arena bounds. Caller-owned initial pages are not charged a second time.
		$min_noncurrent_fill = self::PAYLOAD - $largest - ( '5.4.0' === $this->version ? 40 : 0 );
		$stable_pages = 1 + (int) floor( $payload / $min_noncurrent_fill );
		return array( 'depth' => $depth, 'stack_payload_upper' => $payload, 'largest_reservation_upper' => $largest,
			'scanner_box_and_string_payload_cap' => $scanner_boxes, 'fixed_method_cache_block_cap' => $cache,
			'noncurrent_page_minimum_fill' => $min_noncurrent_fill, 'stable_added_pages_upper' => $stable_pages,
			'transfer_added_pages_upper' => $stable_pages + ( '5.4.0' === $this->version ? 1 : 0 ),
			'upper_descriptor_witness' => $this->path( $path, 0 ),
			'arena_retention_proven' => false, 'fits_justified_helper_budget' => false,
			'missing_terms' => array( 'scanner_box_and_cache_lowering', 'exceptional_VM_page_retention', 'complete_trace_capacity_family' ) );
	}
	private function fail() { throw new \InvalidArgumentException( 'fixed_helper_domain' ); }
}
