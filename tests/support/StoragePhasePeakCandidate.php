<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\LegacyStateCodec;

/**
 * Executable retained-source LIVE-BLOCK phase candidate, deliberately offline.
 * No profile, production autoload/receiver, SQL, unserialize or json_decode.
 * A fit is NOT admission: arena/VM/cleanup headroom is still unjustified.
 * See docs/storage-phase-peak-candidate.md for source derivations and exclusions.
 */
class StoragePhasePeakCandidate {
	private $max;
	private $domain;
	private $budget;
	private $census;

	private function __construct( $domain, $budget ) {
		$this->shape( $domain, array( 'source_version', 'abi', 'allocator', 'segment_bytes', 'driver', 'precision', 'serialize_precision' ) );
		if ( ! in_array( $domain['source_version'], array( '5.4.0', '5.6.20' ), true ) || 'lp64-nts-nondebug' !== $domain['abi'] ||
			'zend-mm' !== $domain['allocator'] || 262144 !== $domain['segment_bytes'] || 'none' !== $domain['driver'] ) { $this->fail( 'unknown_domain' ); }
		// No decimal float work is estimated, even at these settings. These are
		// explicit source-model inputs, NOT assertions about the executing runtime.
		if ( ! is_int( $domain['precision'] ) || $domain['precision'] < 0 || $domain['precision'] > 17 ||
			! is_int( $domain['serialize_precision'] ) || $domain['serialize_precision'] < 0 || $domain['serialize_precision'] > 17 ) { $this->fail( 'precision_domain' ); }
		$this->shape( $budget, array( 'integer_max', 'limit_bytes', 'baseline_bytes', 'reserve_bytes' ) );
		$this->max = $budget['integer_max'];
		if ( ! is_int( $this->max ) || $this->max < 1 || $this->max > PHP_INT_MAX ) { $this->fail( 'integer_domain' ); }
		foreach ( $budget as $amount ) { $this->integer( $amount ); }
		if ( 0 === $budget['limit_bytes'] || 0 === $budget['reserve_bytes'] ) { $this->fail( 'unknown_budget' ); }
		$this->domain = $domain; $this->budget = $budget;
		$this->census = array( 'nodes' => 0, 'arrays' => 0, 'entries' => 0, 'strings' => 0, 'string_bytes' => 0,
			'key_strings' => 0, 'key_bytes' => 0, 'depth' => 0 );
	}

	public static function option( $raw, $domain, $budget ) {
		$self = new self( $domain, $budget );
		if ( ! is_string( $raw ) || strlen( $raw ) > 16777216 || 'a:' !== substr( $raw, 0, 2 ) ) { $self->fail( 'option_domain' ); }
		$offset = 0; $self->scan( $raw, $offset, 0, false );
		if ( $offset !== strlen( $raw ) ) { $self->fail( 'trailing_bytes' ); }
		$c = $self->census; $length = strlen( $raw );
		$graph = $self->graph( $c['nodes'], $c['arrays'], $c['entries'], $c['strings'], $c['string_bytes'], $c['key_strings'], $c['key_bytes'] );
		// All tokens, not just the final surviving graph: includes displaced
		// duplicates and PHP5.6 keys retained until var_destroy. Two pointer lists.
		$tracking = $self->sum( 2 * $self->block( 32 ), $self->multiply( $self->sum( $self->ceil_div( $c['nodes'], 1024 ),
			$self->ceil_div( $self->multiply( 2, $c['nodes'] ), 1024 ) ), $self->block( 8208 ) ) );
		// serialize() reference identity table: bound one entry per visited node,
		// each identity key <=32 bytes, without taking scalar/array reuse credit.
		$references = $self->graph( $c['nodes'], 1, $c['nodes'], 0, 0, $c['nodes'], $self->multiply( 32, $c['nodes'] ) );
		$raw_buffers = $self->multiply( 2, $self->block( $self->sum( $length, 3 ) ) ); // raw + CONCAT/substr overlap; no driver.
		$parity = $self->sum( $self->smart_overlap( $length ), $self->block( $self->sum( $length, 1 ) ) );
		$terms = array( 'raw_buffers' => $raw_buffers, 'source_graph' => $graph, 'tracking' => $tracking,
			'parity_buffers' => $parity, 'serializer_references' => $references, 'cow_graph' => $graph );
		$phases = array( 'decode' => $self->sum( $raw_buffers, $graph, $tracking ),
			'parity' => $self->sum( $raw_buffers, $graph, $graph, $parity, $references ),
			'verification_overlap' => $self->sum( $raw_buffers, $graph, $graph, $graph, $tracking, $parity, $references ) );
		return $self->result( $terms, $phases, false );
	}

	public static function table_json( $json, $domain, $budget ) {
		$self = new self( $domain, $budget );
		try { $scan = ( new LegacyStateCodec() )->inspect_json( $json ); }
		catch ( \RuntimeException $error ) { $self->fail( 'json_pre_rejection' ); }
		// Lexical tokens include duplicate/discarded members and malformed tags.
		// No syntax-validity claim and no native graph is allocated by this tool.
		$n = $scan['tokens']; $length = strlen( $json );
		$self->census = $scan;
		// Every token treated as a container AND scalar string AND inserted key:
		// conservative capacity census, not coefficients fitted to measurements.
		$native = $self->graph( $n, $n, $n, $n, $length, $n, $length );
		// During failed untag, only a token-bounded partial logical graph exists.
		// Successful logical graph has fewer nodes. Charge the larger census.
		$logical = $native;
		$utf16 = $self->block( $self->sum( $self->multiply( 2, $length ), 3 ) );
		$parser = $self->sum( $self->block( 4128 ), $self->block( 4 * 512 ), $self->block( 32 ) );
		$token_buffers = $self->multiply( 2, $self->smart_overlap( $length ) );
		// base64_decode requests encoded length+1, not decoded length. Decoded
		// bytes, canonical re-encode and its realloc old/new can coexist.
		$base64 = $self->sum( $self->block( $self->sum( $length, 1 ) ), $self->smart_overlap( $self->base64_length( $length ) ) );
		// array_keys/range/key-probe arrays: charge three full token-census
		// containers, covering the two validation arrays and typed-key probe.
		$validation = $self->multiply( 3, $self->graph( $n, 1, $n, 0, 0, 0, 0 ) );
		$raw_buffers = $self->multiply( 2, $self->block( $self->sum( $length, 1 ) ) );
		$parity = $self->sum( $self->smart_overlap( $length ), $self->block( $self->sum( $length, 1 ) ), $utf16 );
		$terms = array( 'raw_buffers' => $raw_buffers, 'native_graph' => $native, 'logical_graph' => $logical,
			'utf16' => $utf16, 'parser' => $parser, 'token_buffers' => $token_buffers, 'base64' => $base64,
			'validation_arrays' => $validation, 'cow_graph' => $logical, 'target_graph' => $logical, 'parity_buffers' => $parity );
		$decode = $self->sum( $raw_buffers, $native, $logical, $utf16, $parser, $token_buffers, $base64, $validation );
		$phases = array( 'native_decode_and_untag' => $decode,
			'verification_overlap' => $self->sum( $decode, $logical, $logical, $parity ) );
		return $self->result( $terms, $phases, false );
	}

	private function scan( $raw, &$offset, $depth, $key ) {
		if ( $depth > 64 || ++$this->census['nodes'] > 30000 || $offset >= strlen( $raw ) ) { $this->fail( 'option_bounds' ); }
		$this->census['depth'] = max( $depth, $this->census['depth'] );
		$type = $raw[$offset++];
		if ( $key && 'i' !== $type && 's' !== $type ) { $this->fail( 'key_type' ); }
		if ( 'N' === $type ) { $this->literal( $raw, $offset, ';' ); return; }
		$this->literal( $raw, $offset, ':' );
		if ( 'i' === $type || 'b' === $type ) {
			$token = $this->token( $raw, $offset, ';', 'i' === $type ? 21 : 1 );
			if ( 'b' === $type ? ( '0' !== $token && '1' !== $token ) :
				( ! preg_match( '/^(0|-?[1-9][0-9]*)$/D', $token ) || (string) (int) $token !== $token ) ) { $this->fail( 'scalar_domain' ); }
			return;
		}
		if ( 's' === $type ) {
			$length = $this->unsigned( $raw, $offset ); $this->literal( $raw, $offset, '"' );
			if ( $length > strlen( $raw ) - $offset ) { $this->fail( 'string_bounds' ); }
			++$this->census['strings']; $this->census['string_bytes'] = $this->sum( $this->census['string_bytes'], $length );
			if ( $key ) { ++$this->census['key_strings']; $this->census['key_bytes'] = $this->sum( $this->census['key_bytes'], $length ); }
			$offset += $length; $this->literal( $raw, $offset, '";' ); return;
		}
		if ( 'a' !== $type ) { $this->fail( 'native_decimal_or_unsupported_token' ); }
		$count = $this->unsigned( $raw, $offset );
		if ( ( 0 === $depth && $count > 100 ) || $count > ( 30000 - $this->census['nodes'] ) / 2 ) { $this->fail( 'entry_bounds' ); }
		++$this->census['arrays']; $this->census['entries'] = $this->sum( $this->census['entries'], $count );
		$this->literal( $raw, $offset, '{' );
		for ( $i = 0; $i < $count; ++$i ) { $this->scan( $raw, $offset, $depth + 1, true ); $this->scan( $raw, $offset, $depth + 1, false ); }
		$this->literal( $raw, $offset, '}' );
	}

	private function graph( $nodes, $arrays, $entries, $strings, $bytes, $keys, $key_bytes ) {
		// For final power-of-two vector capacities C, sum(C)<=8A+4E.
		// Keep two complete vectors per container for conservative realloc/COW.
		$slots = $this->sum( $this->multiply( 8, $arrays ), $this->multiply( 4, $entries ) );
		$vectors = $this->multiply( 2, $this->sum( $this->multiply( 8, $slots ), $this->multiply( 56, $arrays ) ) );
		return $this->sum( $this->multiply( $this->block( 32 ), $nodes ), $this->multiply( $this->block( 72 ), $arrays ), $vectors,
			$this->multiply( $this->block( 72 ), $entries ), $key_bytes, $this->multiply( 8, $keys ), $bytes, $this->multiply( 56, $strings ) );
	}
	private function block( $request ) {
		// Conditional LP64 Zend-MM allocation including maximum unsplit tail24.
		$with_header = $this->sum( $request, 16 );
		$aligned = $this->multiply( 8, $this->ceil_div( $with_header, 8 ) );
		return $this->sum( max( 32, $aligned ), 24 );
	}
	private function smart_overlap( $length ) {
		return $this->multiply( 2, $this->block( max( 79, $this->sum( $length, 129 ) ) ) );
	}
	private function base64_length( $length ) { return $this->multiply( 4, $this->ceil_div( $length, 3 ) ); }
	private function unsigned( $raw, &$offset ) {
		$value = $this->token( $raw, $offset, ':', 20 );
		if ( ! preg_match( '/^(0|[1-9][0-9]*)$/D', $value ) || (string) (int) $value !== $value || (int) $value < 0 ) { $this->fail( 'unsigned_bounds' ); }
		return (int) $value;
	}
	private function token( $raw, &$offset, $terminator, $limit ) {
		$start = $offset;
		while ( $offset < strlen( $raw ) && $offset - $start <= $limit ) {
			if ( $terminator === $raw[$offset] ) { $token = substr( $raw, $start, $offset - $start ); ++$offset; return $token; }
			++$offset;
		}
		$this->fail( 'token_bounds' );
	}
	private function literal( $raw, &$offset, $literal ) {
		if ( substr( $raw, $offset, strlen( $literal ) ) !== $literal ) { $this->fail( 'option_syntax' ); }
		$offset += strlen( $literal );
	}
	private function result( $terms, $phases, $syntax_validated ) {
		$peak = max( $phases );
		$total = $this->sum( $this->budget['baseline_bytes'], $peak, $this->budget['reserve_bytes'] );
		return array( 'status' => 'candidate_only', 'admitted' => false, 'syntax_validated' => $syntax_validated,
			'domain' => $this->domain, 'budget' => $this->budget, 'census' => $this->census, 'terms' => $terms, 'phases' => $phases,
			'modeled_peak_bytes' => $peak, 'modeled_total_bytes' => $total, 'fits_modeled_budget' => $total <= $this->budget['limit_bytes'],
			'real_usage_peak_bytes' => null, 'refusal' => 'phase_headroom_unjustified',
			'missing_headroom_terms' => array( 'arena_slack', 'vm_temporaries', 'cleanup_reserve_evidence' ) );
	}
	private function shape( $value, $keys ) {
		if ( ! is_array( $value ) || count( $value ) !== count( $keys ) || array_diff( $keys, array_keys( $value ) ) ) { $this->fail( 'unknown_shape' ); }
	}
	private function integer( $value ) { if ( ! is_int( $value ) || $value < 0 || $value > $this->max ) { $this->fail( 'integer_bounds' ); } }
	private function sum() {
		$total = 0;
		foreach ( func_get_args() as $amount ) { $this->integer( $amount ); if ( $amount > $this->max - $total ) { $this->fail( 'sum_overflow' ); } $total += $amount; }
		return $total;
	}
	private function multiply( $left, $right ) {
		$this->integer( $left ); $this->integer( $right ); $product = $left * $right;
		$this->integer( $product ); return $product;
	}
	private function ceil_div( $value, $divisor ) {
		$this->integer( $value );
		// Bounded source input domains avoid lossy division beyond 2^53. Do not
		// accept a caller-supplied huge count and round it through a PHP float.
		if ( $value > 2147483647 ) { $this->fail( 'native_count_width' ); }
		return (int) ceil( $value / $divisor );
	}
	private function fail( $reason ) { throw new \InvalidArgumentException( $reason . ': candidate cannot grant admission.' ); }
}
