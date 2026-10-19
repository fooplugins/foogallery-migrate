<?php
/**
 * Inert compact-record split/flatten/rebuild seam for the approved shared schema.
 * No authority, SQL, object instantiation, provisioning or implicit conversion.
 * Historical runtime objects are deliberately refused, never silently compacted.
 *
 * @package FooPlugins\FooGalleryMigrate
 */
namespace FooPlugins\FooGalleryMigrate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LegacyRecordCodec {
	const VERSION = 1;
	const CONTENT_STATE_KEY = 'block-shortcode_scan_state';
	/** Existing scan occurrence identity; shared with the record-root index. */
	public static function content_occurrence_key( $item ) {
		return md5( implode( '|', array(
			isset( $item['post_id'] ) ? (int) $item['post_id'] : 0,
			isset( $item['plugin_name'] ) ? $item['plugin_name'] : '',
			isset( $item['type'] ) ? $item['type'] : '',
			isset( $item['original_content'] ) ? $item['original_content'] : '',
			isset( $item['match_offset'] ) ? (int) $item['match_offset'] : 0,
			isset( $item['scan_occurrence'] ) ? (int) $item['scan_occurrence'] : 0,
		) ) );
	}

	private $codec;
	private $max_records;
	private $max_tree_bytes;
	private $scale;
	private $max_depth;

	/** Budgets bound one affected root tree, not all stored state. */
	public function __construct( $codec = null, $max_records = 10000, $max_tree_bytes = 16777216, $scale = null ) {
		if ( null !== $scale && ( ! $scale instanceof StorageScaleAdmission || $max_records > $scale->limit( 'max_rows' ) || $max_tree_bytes > $scale->limit( 'max_bytes' ) ) ) { $this->fail( 'Invalid finite scale.' ); }
		$this->scale = $scale;
		$this->max_depth = null === $scale ? 64 : $scale->limit( 'max_depth' );
		if ( ( null !== $codec && ! $codec instanceof LegacyStateCodec ) ||
			! is_int( $max_records ) || $max_records < 1 || ! is_int( $max_tree_bytes ) || $max_tree_bytes < 1 ) {
			$this->fail( 'Invalid tree codec or budget.' );
		}
		if ( null !== $scale && null !== $codec && ! $codec->uses_storage_scale( $scale ) ) { $this->fail( 'Codec does not share the finite operation budget.' ); }
		$this->codec = null === $codec ? new LegacyStateCodec( 1048576, 64, 10000, $scale ) : $codec;
		$this->max_records = $max_records;
		$this->max_tree_bytes = $max_tree_bytes;
	}

	/**
	 * Preparatory facts for the CURRENT v1 representation, not memory approval.
	 * No make_row/tag/JSON encoding, full field_order/values copy, hash or output
	 * collection is constructed. Only O(logical depth + row depth) fixed metadata
	 * and bounded scalar scratch are used. Input is still caller-owned/live.
	 * The named sink describes ownership, NOT arbitrary callable behavior or SQL
	 * driver bounds. Runtime/container/COW/native coefficients remain UNKNOWN.
	 * Tagged metrics cover payload + mutable envelopes, not projection/hash
	 * scratch. encoded_bytes covers the same columns as encoded_bytes(row).
	 * Native default JSON escaping is counted; custom codec/WP encoders and
	 * narrower injected codec limits are NOT certified by this observation.
	 */
	public function describe_tree( $key, $record, $ownership, $state_key = null ) {
		if ( 'retaining-row' !== $ownership && 'repository-sink' !== $ownership ) { $this->fail( 'Unsupported footprint ownership.' ); }
		$this->tick();
		$d = array( 'version' => 1, 'ownership' => $ownership, 'additional_allocation_bytes' => null,
			'records' => 0, 'payload_bytes' => 0, 'encoded_bytes' => 0, 'encoded_odd_rows' => 0, 'encoded_large_columns' => 0, 'max_row_bytes' => 0,
			'tagged_arrays' => 0, 'tagged_entries' => 0, 'tagged_nodes' => 0, 'raw_string_bytes' => 0,
			'max_row_tagged_arrays' => 0, 'max_row_tagged_nodes' => 0, 'max_row_tagged_entries' => 0,
			'base64_bytes' => 0, 'typed_key_bytes' => 0, 'projection_bytes' => 0,
			'max_record_fields' => 0, 'max_siblings' => 0, 'active_row_frames' => 0, 'max_ancestor_row_bytes' => 0,
			'logical_nodes' => 0, 'logical_entries' => 0, 'logical_arrays' => 0, 'logical_string_bytes' => 0,
			'logical_max_depth' => 0, 'logical_max_width' => 0, 'logical_max_string_bytes' => 0 );
		$this->footprint_logical( $record, 0, $d );
		$this->footprint_tree( $key, $record, 0, 0, $d, $state_key );
		$d['retained_output_rows'] = 'retaining-row' === $ownership ? $d['records'] : 0;
		return $d;
	}

	/**
	 * Encoded, caller-retained rows: observe BEFORE positional index/hydration.
	 * Checks byte columns, checksum and lexical bounds only. NOT a replacement
	 * for validate_row, native JSON syntax/untagging, ancestry or logical hashes.
	 * Native token counts bound syntax work, not decoded allocation coefficients.
	 * No adjacency/index/sort, key decoding or full JSON graph is constructed.
	 */
	public function describe_rows( $rows, $ownership ) {
		$this->tick();
		if ( 'caller-rows-rebuild' !== $ownership || ! is_array( $rows ) || ! count( $rows ) || count( $rows ) > $this->max_records ) { $this->fail( 'Unsupported footprint rows/ownership.' ); }
		$d = array( 'version' => 1, 'ownership' => $ownership, 'additional_allocation_bytes' => null,
			'encoded_bytes' => 0, 'max_row_bytes' => 0, 'max_payload_pair_bytes' => 0, 'max_key_decode_bytes_upper_bound' => 0,
			'node_index_entries' => count( $rows ), 'visited_entries' => count( $rows ), 'adjacency_entries' => 0,
			'parent_buckets_upper_bound' => 0, 'sort_cow_entries_upper_bound' => 0, 'native_tokens' => 0, 'max_native_depth' => 0, 'max_row_native_tokens' => 0 );
		foreach ( $rows as $row ) {
			$this->tick(); $bytes = $this->footprint_encoded_bytes( $row );
			if ( $bytes > LegacyTableReader::MAX_RECORD_BYTES || ! isset( $row['payload_hash'], $row['payload_bytes'], $row['depth'] ) ||
				! array_key_exists( 'parent_node_hash', $row ) || ! is_string( $row['payload'] ) || ! is_string( $row['mutable_payload'] ) ||
				! is_string( $row['key_value'] ) || $this->number( $row['depth'] ) > $this->max_depth ) { $this->fail( 'Invalid footprint row.' ); }
			$pair = StorageScaleAdmission::checked_add( strlen( $row['payload'] ), strlen( $row['mutable_payload'] ) );
			if ( $this->number( $row['payload_bytes'] ) !== $pair || $this->hex( $row['payload_hash'] ) !== hash( 'sha256', $row['payload'] ) ) { $this->fail( 'Payload integrity/byte-count mismatch.' ); }
			$this->footprint_add( $d, 'encoded_bytes', $bytes );
			if ( $d['encoded_bytes'] > $this->max_tree_bytes ) { $this->fail( 'Tree exceeds footprint byte budget.' ); }
			$d['max_row_bytes'] = max( $d['max_row_bytes'], $bytes );
			$d['max_payload_pair_bytes'] = max( $d['max_payload_pair_bytes'], $pair );
			// Encoded length is a safe upper bound without allocating decoded keys.
			$d['max_key_decode_bytes_upper_bound'] = max( $d['max_key_decode_bytes_upper_bound'], strlen( $row['key_value'] ) );
			if ( null !== $row['parent_node_hash'] ) { $this->hex( $row['parent_node_hash'] ); ++$d['adjacency_entries']; }
			$row_tokens = 0;
			foreach ( array( 'payload', 'mutable_payload' ) as $field ) {
				// Match inspect_json's existing physical token ceiling. Split payload
				// may have one extra logical edge; observe its lexical depth separately.
				$scan = 'payload' === $field ? $this->codec->inspect_json_record_payload( $row[$field] ) : $this->codec->inspect_json( $row[$field] );
				$this->footprint_add( $d, 'native_tokens', $scan['tokens'] );
				$row_tokens = StorageScaleAdmission::checked_add( $row_tokens, $scan['tokens'] );
				$d['max_native_depth'] = max( $d['max_native_depth'], $scan['max_depth'] );
			}
			$d['max_row_native_tokens'] = max( $d['max_row_native_tokens'], $row_tokens );
		}
		// Without allocating an adjacency map, each edge could own a bucket and
		// every edge could overlap one sibling sort/COW span. Explicit upper bounds.
		$d['parent_buckets_upper_bound'] = $d['adjacency_entries'];
		$d['sort_cow_entries_upper_bound'] = $d['adjacency_entries'];
		return $d;
	}

	private function footprint_encoded_bytes( $row ) {
		if ( ! is_array( $row ) ) { $this->fail( 'Invalid footprint row.' ); }
		$bytes = 0;
		foreach ( array( 'payload', 'mutable_payload', 'key_value', 'source_plugin_value', 'source_id_value', 'legacy_identifier_value' ) as $field ) {
			if ( 'legacy_identifier_value' === $field && ! isset( $row[$field] ) ) { continue; }
			if ( ! array_key_exists( $field, $row ) || ( null !== $row[$field] && ! is_string( $row[$field] ) ) ) { $this->fail( 'Invalid footprint byte column.' ); }
			$bytes = StorageScaleAdmission::checked_add( $bytes, null === $row[$field] ? 0 : strlen( $row[$field] ) );
			if ( $bytes > LegacyTableReader::MAX_RECORD_BYTES ) { $this->fail( 'Row footprint exceeds byte budget.' ); }
		}
		return $bytes;
	}

	private function footprint_add( &$d, $field, $n ) { $d[$field] = StorageScaleAdmission::checked_add( $d[$field], $n ); }

	private function footprint_logical( $v, $depth, &$d ) {
		$this->tick(); $this->footprint_add( $d, 'logical_nodes', 1 );
		if ( $depth > $this->max_depth || $d['logical_nodes'] > ( null === $this->scale ? 262144 : $this->scale->limit( 'max_tokens' ) ) ) { $this->fail( 'Logical footprint exceeds depth/token budget.' ); }
		$d['logical_max_depth'] = max( $d['logical_max_depth'], $depth );
		if ( is_array( $v ) ) {
			$this->footprint_add( $d, 'logical_arrays', 1 ); $this->footprint_add( $d, 'logical_entries', count( $v ) );
			$d['logical_max_width'] = max( $d['logical_max_width'], count( $v ) );
			foreach ( $v as $k => $item ) { $this->footprint_logical( $k, $depth + 1, $d ); $this->footprint_logical( $item, $depth + 1, $d ); }
		} else if ( is_string( $v ) ) {
			$this->footprint_add( $d, 'logical_string_bytes', strlen( $v ) );
			$d['logical_max_string_bytes'] = max( $d['logical_max_string_bytes'], strlen( $v ) );
		}
		else if ( ! is_null( $v ) && ! is_bool( $v ) && ! is_int( $v ) && ( ! is_float( $v ) || ! is_finite( $v ) ) ) { $this->fail( 'Unsupported footprint scalar.' ); }
	}

	private function footprint_metrics() {
		return array( 'json_bytes' => 0, 'tagged_arrays' => 0, 'tagged_entries' => 0, 'tagged_nodes' => 0, 'raw_string_bytes' => 0, 'base64_bytes' => 0 );
	}

	private function footprint_array( $count, &$m ) {
		$this->tick(); $this->footprint_add( $m, 'tagged_nodes', 1 );
		$this->footprint_add( $m, 'tagged_arrays', StorageScaleAdmission::checked_add( 2, $count ) );
		$this->footprint_add( $m, 'tagged_entries', $count );
		$this->footprint_add( $m, 'json_bytes', StorageScaleAdmission::checked_add( 12, 0 === $count ? 0 : StorageScaleAdmission::checked_multiply( 4, $count ) - 1 ) );
	}

	private function footprint_value( $v, &$m ) {
		$this->tick();
		if ( is_array( $v ) ) {
			$this->footprint_array( count( $v ), $m );
			foreach ( $v as $k => $item ) { $this->footprint_value( $k, $m ); $this->footprint_value( $item, $m ); }
			return;
		}
		$this->footprint_add( $m, 'tagged_nodes', 1 ); $this->footprint_add( $m, 'tagged_arrays', 1 );
		if ( null === $v ) { $bytes = 8; }
		else if ( is_bool( $v ) ) { $bytes = $v ? 16 : 17; }
		else if ( is_int( $v ) ) { $bytes = 14 + strlen( (string) $v ); }
		else if ( is_string( $v ) || ( is_float( $v ) && is_finite( $v ) ) ) {
			$is_string = is_string( $v );
			// Only eight-byte float scratch, matching the codec's canonical endian.
			$raw = $is_string ? $v : pack( 'd', $v );
			if ( ! $is_string && pack( 'S', 1 ) === "\x01\x00" ) { $raw = strrev( $raw ); }
			$length = strlen( $raw ); $encoded = 0; $slashes = 0;
			for ( $i = 0; $i < $length; $i += min( 3, $length - $i ) ) {
				if ( 0 === $i % 4095 ) { $this->tick(); }
				$chunk = base64_encode( substr( $raw, $i, 3 ) );
				$encoded = StorageScaleAdmission::checked_add( $encoded, strlen( $chunk ) );
				$slashes = StorageScaleAdmission::checked_add( $slashes, substr_count( $chunk, '/' ) );
			}
			$this->footprint_add( $m, 'base64_bytes', $encoded );
			if ( $is_string ) { $this->footprint_add( $m, 'raw_string_bytes', $length ); }
			$bytes = StorageScaleAdmission::checked_add( $is_string ? 13 : 12, StorageScaleAdmission::checked_add( $encoded, $slashes ) );
		} else { $this->fail( 'Unsupported footprint scalar.' ); }
		$this->footprint_add( $m, 'json_bytes', $bytes );
	}

	private function footprint_tree( $key, $record, $depth, $ancestor_bytes, &$d, $state_key ) {
		$this->tick();
		if ( $d['records'] >= $this->max_records || $depth > $this->max_depth || ( ! is_int( $key ) && ! is_string( $key ) ) ) { $this->fail( 'Record footprint exceeds budget or key domain.' ); }
		$probe = array( $key => true ); foreach ( $probe as $actual => $unused ) { if ( $actual !== $key ) { $this->fail( 'Coercible footprint key.' ); } }
		$m = $this->footprint_metrics(); $mutable_count = 0; $values_count = 0; $child_count = null;
		if ( is_array( $record ) ) {
			foreach ( $record as $field => $value ) {
				if ( 'children' === $field && is_array( $value ) ) { $child_count = count( $value ); }
				else if ( in_array( $field, $this->mutable_fields(), true ) ) { ++$mutable_count; }
				else { ++$values_count; }
			}
			$this->footprint_array( 5, $m );
			$this->footprint_value( 'version', $m ); $this->footprint_value( self::VERSION, $m );
			$this->footprint_value( 'kind', $m ); $this->footprint_value( 'array', $m );
			$this->footprint_value( 'field_order', $m ); $this->footprint_array( count( $record ), $m );
			$i = 0; foreach ( $record as $field => $unused ) { $this->footprint_value( $i++, $m ); $this->footprint_value( $field, $m ); }
			$this->footprint_value( 'child_count', $m ); $this->footprint_value( $child_count, $m );
			$this->footprint_value( 'values', $m ); $this->footprint_array( $values_count, $m );
			foreach ( $record as $field => $value ) {
				if ( ( 'children' === $field && null !== $child_count ) || in_array( $field, $this->mutable_fields(), true ) ) { continue; }
				$this->footprint_value( $field, $m ); $this->footprint_value( $value, $m );
			}
			$d['max_record_fields'] = max( $d['max_record_fields'], count( $record ) );
		} else {
			$this->footprint_array( 3, $m );
			foreach ( array( 'version' => self::VERSION, 'kind' => 'value', 'value' => $record ) as $field => $value ) { $this->footprint_value( $field, $m ); $this->footprint_value( $value, $m ); }
		}
		$payload = StorageScaleAdmission::checked_add( 22, $m['json_bytes'] );
		$this->footprint_array( $mutable_count, $m );
		if ( is_array( $record ) ) { foreach ( $record as $field => $value ) {
			if ( in_array( $field, $this->mutable_fields(), true ) ) { $this->footprint_value( $field, $m ); $this->footprint_value( $value, $m ); }
		} }
		$pair = StorageScaleAdmission::checked_add( 44, $m['json_bytes'] );
		if ( $payload > 1048576 || $pair - $payload > 1048576 ) { $this->fail( 'Record footprint exceeds encoded codec budget.' ); }
		// Exact raw base64 key length without constructing the full string.
		$key_metrics = $this->footprint_metrics(); $this->footprint_value( (string) $key, $key_metrics );
		$key_bytes = $key_metrics['base64_bytes']; $projection_bytes = 0;
		$large_columns = ( $payload > 3072 ? 1 : 0 ) + ( $pair - $payload > 3072 ? 1 : 0 ) + ( $key_bytes > 3072 ? 1 : 0 );
		if ( is_array( $record ) ) {
			foreach ( array( 'plugin_name', 'ID' ) as $field ) {
				if ( ! isset( $record[$field] ) && ! array_key_exists( $field, $record ) ) { continue; }
				$v = $record[$field];
				if ( 'plugin_name' === $field && null === $v ) { continue; }
				if ( 'plugin_name' === $field ? ! is_string( $v ) : ( ! is_scalar( $v ) && null !== $v ) ) { $this->fail( 'Unsupported projection footprint.' ); }
				$pm = $this->footprint_metrics(); $this->footprint_value( (string) $v, $pm );
				$column_bytes = StorageScaleAdmission::checked_add( 22, $pm['json_bytes'] );
				if ( $column_bytes > 3072 ) { ++$large_columns; }
				$projection_bytes = StorageScaleAdmission::checked_add( $projection_bytes, $column_bytes );
			}
		}
		if ( $this->has_content_projection( $state_key, 0 === $depth ) ) {
			// md5 occurrence keys are always 32 ASCII bytes. Observe the exact
			// typed column before allocating the occurrence/hash or any row.
			$pm = $this->footprint_metrics(); $this->footprint_value( str_repeat( '0', 32 ), $pm );
			$column_bytes = StorageScaleAdmission::checked_add( 22, $pm['json_bytes'] );
			if ( $column_bytes > 3072 ) { ++$large_columns; }
			$projection_bytes = StorageScaleAdmission::checked_add( $projection_bytes, $column_bytes );
		}
		$bytes = StorageScaleAdmission::checked_add( $pair, StorageScaleAdmission::checked_add( $key_bytes, $projection_bytes ) );
		if ( $bytes > LegacyTableReader::MAX_RECORD_BYTES ) { $this->fail( 'Row footprint exceeds byte budget.' ); }
		$this->footprint_add( $d, 'encoded_odd_rows', $bytes & 1 ); $this->footprint_add( $d, 'encoded_large_columns', $large_columns );
		$this->footprint_add( $d, 'records', 1 ); $this->footprint_add( $d, 'payload_bytes', $pair );
		$this->footprint_add( $d, 'encoded_bytes', $bytes ); $this->footprint_add( $d, 'typed_key_bytes', $key_bytes ); $this->footprint_add( $d, 'projection_bytes', $projection_bytes );
		foreach ( array( 'tagged_arrays', 'tagged_entries', 'tagged_nodes', 'raw_string_bytes', 'base64_bytes' ) as $field ) { $this->footprint_add( $d, $field, $m[$field] ); }
		foreach ( array( 'tagged_arrays', 'tagged_entries', 'tagged_nodes' ) as $field ) { $d['max_row_' . $field] = max( $d['max_row_' . $field], $m[$field] ); }
		if ( $d['encoded_bytes'] > $this->max_tree_bytes ) { $this->fail( 'Tree footprint exceeds byte budget.' ); }
		$ancestor_bytes = StorageScaleAdmission::checked_add( $ancestor_bytes, $bytes );
		$d['max_row_bytes'] = max( $d['max_row_bytes'], $bytes );
		$d['active_row_frames'] = max( $d['active_row_frames'], $depth + 1 );
		$d['max_ancestor_row_bytes'] = max( $d['max_ancestor_row_bytes'], $ancestor_bytes );
		if ( null !== $child_count ) {
			$d['max_siblings'] = max( $d['max_siblings'], $child_count );
			foreach ( $record['children'] as $k => $child ) { $this->footprint_tree( $k, $child, $depth + 1, $ancestor_bytes, $d, $state_key ); }
		}
	}

	/**
	 * Emit rows individually in preorder; never allocate a second complete row
	 * collection. The consumer MUST stage/rollback partial output on any failure.
	 * Hashes are hex for prepared fixed UNHEX() SQL, not raw binary transport.
	 */
	public function flatten_tree( $state_key, $key, $record, $item_order, $emit ) {
		if ( ! is_callable( $emit ) ) { $this->fail( 'A staged row consumer is required.' ); }
		// A final INTERNAL digest sink is the only measured non-retaining owner.
		// No callable/flag supplied by a caller can assert this capability.
		$qualified = $emit instanceof RepositoryFlattenModel;
		if ( $qualified ) {
			if ( get_class( $this ) !== __CLASS__ ) { $this->fail( 'Unmeasured record codec.' ); }
			$emit->qualify( $this->codec, $this->scale );
			$this->scale->admit_additional( $emit->descriptor_bytes( 64 ) );
			$d = $this->describe_tree( $key, $record, 'repository-sink', $state_key );
			if ( $d['records'] > $this->scale->limit( 'max_physical_rows_per_table' ) ||
				$d['records'] > $this->scale->limit( 'max_physical_rows' ) ||
				$d['encoded_bytes'] > $this->scale->limit( 'max_physical_bytes' ) ) { $this->fail( 'Physical flatten budget exceeded.' ); }
			$plan = $emit->project( $d, $state_key );
			$emit->observe( 'descriptor', $plan );
			$this->scale->admit_additional( $plan['after_descriptor_bytes'] );
		}
		$phase = null; $row_phase = null; $slots = null;
		if ( ! $qualified && null !== $this->scale && GenericRetainingModel::supports( $this, $this->codec, $this->scale ) ) {
			$phase = $this->scale->open_retaining_phase( $this, GenericRetainingModel::descriptor_bytes() );
			$d = $this->describe_tree( $key, $record, 'retaining-row', $state_key );
			$plan = GenericRetainingModel::staged_flatten_plan( $d, $state_key );
			$this->scale->plan_retaining_phase( $phase, $this, $plan['future'], $plan['private'], $plan['external'], $plan['events'] );
			// Floor-compatible packed preallocation: no append, resize or export.
			$slots = array_fill( 0, $d['records'], null );
			$this->scale->realize_retaining_event( $phase, $this, 0, $plan['future'] );
			// Independently owned prospective graph, not slot-only admission or a
			// sample credit. Reserve every row before preflight/staging can grow it.
			$row_phase = $this->scale->open_retaining_phase( $this, 0 );
			$this->scale->plan_retaining_phase( $row_phase, $this, $plan['rows_future'], 0, 0, $plan['rows_events'] );
		}
		$item_order = $this->number( $item_order );
		$stats = array( 'record_count' => 0, 'payload_bytes' => 0, 'encoded_bytes' => 0 );
		$preflight = $stats;
		$this->admit( 0 );
		$this->preflight_tree( $this->codec->key_hash( $state_key ), null, $key, $record, $item_order, 0, $preflight, $phase, $slots );
		if ( $qualified || null !== $phase ) {
			if ( $preflight['record_count'] !== $d['records'] || $preflight['payload_bytes'] !== $d['payload_bytes'] ||
				$preflight['encoded_bytes'] !== $d['encoded_bytes'] ) { $this->fail( 'Descriptor/preflight mismatch.' ); }
			if ( $qualified ) { $emit->observe( 'preflight' ); }
		}
		// Row ancestry is not logical depth: children contributes two edges,
		// and ordinary/mutable fields may nest without creating any child row.
		// Finish the shared logical admission before invoking the consumer.
		if ( null !== $this->scale ) { $this->codec->snapshot_digest( $record ); }
		if ( $qualified ) { $emit->observe( 'digest' ); }
		// Supported producers have admitted the COMPLETE prospective row graph,
		// slot shell, workspace and external list. Unsupported profiles retain
		// aggregate amplification; a slot-only scope is never sufficient.
		if ( $qualified || null !== $row_phase ) { $this->admit( 0 ); }
		else {
			$this->admit( $preflight['encoded_bytes'] );
		}
		if ( null !== $phase ) {
			// Whole-tree digest above runs with hashes only, never the full row graph.
			$this->stage_retaining_node( $this->codec->key_hash( $state_key ), null, $key, $record, $item_order, 0, $stats, $phase, $slots, $row_phase );
			if ( $stats !== $preflight || count( $slots ) !== $d['records'] ) { $this->fail( 'Incomplete retaining staging.' ); }
			// Row locals have unwound, every exact event completed, and the graph
			// is now sampled live. Never close an aborted or incomplete row scope.
			$this->scale->close_retaining_phase( $row_phase, $this );
			// All recursive row locals have unwound. Own only the suffix at callback.
			for ( $i = 0; $i < $d['records']; ++$i ) {
				$this->tick(); $this->admit( 0 );
				if ( ! isset( $slots[$i] ) || ! is_array( $slots[$i] ) ) { $this->fail( 'Invalid staged row slot.' ); }
				$row = $slots[$i]; unset( $slots[$i] );
				if ( false === call_user_func( $emit, $row ) ) { $this->fail( 'Staged consumer refused the write.' ); }
				unset( $row );
			}
			unset( $slots, $row, $d, $plan, $preflight );
			// Never finally-close: an interrupted producer still owns its liability.
			$this->scale->close_retaining_phase( $phase, $this );
		} else {
			$this->flatten_node( $this->codec->key_hash( $state_key ), null, $key, $record, $item_order, 0, $emit, $stats );
		}
		if ( $qualified ) { $emit->observe( 'emission' ); }
		return $stats;
	}

	/**
	 * Rebuild precisely ONE complete affected root from scoped, size-preflighted
	 * rows. The reader/repository remains responsible for blog/state/generation,
	 * revision and missing-head checks. Extra physical columns never enter hashes.
	 */
	public function rebuild_tree( $state_key, $root_hash, $rows ) {
		$root_hash = $this->hex( $root_hash );
		if ( ! is_array( $rows ) || ! count( $rows ) || count( $rows ) > $this->max_records ) {
			$this->fail( 'Missing tree or exceeded record budget.' );
		}
		$nodes = array(); $children = array(); $bytes = 0;
		// Bound aggregate bytes before any JSON decoding. The SQL reader must
		// independently check DB column sizes BEFORE fetching these LONGTEXTs.
		foreach ( $rows as $row ) {
			$size = $this->encoded_bytes( $row );
			if ( $size > LegacyTableReader::MAX_RECORD_BYTES || $size > $this->max_tree_bytes - $bytes ) { $this->fail( 'Tree exceeds the encoded byte budget.' ); }
			$bytes += $size;
		}
		$phase = null; $sequence = 0; $preorder = false; $cursor = null;
		if ( null !== $this->scale && GenericRetainingModel::supports( $this, $this->codec, $this->scale ) ) {
			$phase = $this->scale->open_retaining_phase( $this, GenericRetainingModel::descriptor_bytes() );
			$d = $this->describe_rows( $rows, 'caller-rows-rebuild' );
			// Lexical maximum establishes the workspace BEFORE native hydration.
			$this->scale->plan_retaining_phase( $phase, $this, 0, StorageScaleAdmission::checked_add( GenericRetainingModel::rebuild_descriptor_bytes( $d ), GenericRetainingModel::preorder_metadata_bytes() ), 0, 0 );
			$preorder = $this->certify_preorder( $rows, $root_hash );
			$plan = GenericRetainingModel::rebuild_plan( $rows, $d, $this->codec, $this->scale, $preorder );
			$this->scale->close_retaining_phase( $phase, $this );
			$phase = $this->scale->open_retaining_phase( $this, 0 );
			$this->scale->plan_retaining_phase( $phase, $this, $plan['future'], $plan['index_stage'], 0, $plan['events'], $plan['parts']['index_growth'], $plan['after_indexes'] );
		} else { $this->admit( $bytes ); }
		$state_hash = $this->codec->key_hash( $state_key );
		// Index immutable encoded rows by their caller-owned positions. Validate
		// every encoded identity/checksum before hydrating any split envelope.
		// Neither this index nor adjacency retains decoded payloads or full rows.
		foreach ( $rows as $position => $row ) {
			$row = $this->validate_row( $row, $state_hash );
			$hash = $row['node_hash'];
			if ( isset( $nodes[ $hash ] ) ) { $this->fail( 'Duplicate node identity.' ); }

			$nodes[ $hash ] = $position;
			if ( null === $phase && null !== $row['parent_node_hash'] ) {
				$parent = $row['parent_node_hash'];
				$order = $row['item_order'];
				if ( isset( $children[ $parent ][ $order ] ) ) { $this->fail( 'Duplicate sibling order.' ); }
				$children[ $parent ][ $order ] = $position;
			}
		}
		if ( null !== $phase && ! $preorder ) {
			// The prospective adjacency bound came from declared child counts.
			// Authenticate those bounds BEFORE allocating a child bucket, even for
			// adversarial/reordered rows. Never trust topology metadata as credit.
			foreach ( $rows as $position => $row ) {
				$row = $this->validate_row( $row, $state_hash );
				$parent = $row['parent_node_hash'];
				if ( null === $parent ) { continue; }
				if ( ! isset( $nodes[$parent] ) ) { $this->fail( 'Missing parent identity.' ); }
				$base = $this->codec->decode_record_payload( $rows[$nodes[$parent]]['payload'] );
				$bound = isset( $base['child_count'] ) ? $base['child_count'] : 0;
				unset( $base );
				if ( ! is_int( $bound ) || $bound <= 0 || ( isset( $children[$parent] ) && count( $children[$parent] ) >= $bound ) ) { $this->fail( 'Child bucket exceeds declared bound.' ); }
				$order = $row['item_order'];
				if ( isset( $children[$parent][$order] ) ) { $this->fail( 'Duplicate sibling order.' ); }
				$children[$parent][$order] = $position;
			}
		}
		unset( $row );
		if ( ! isset( $nodes[ $root_hash ] ) || null !== $rows[ $nodes[ $root_hash ] ]['parent_node_hash'] || $this->number( $rows[ $nodes[ $root_hash ] ]['depth'] ) !== 0 ) {
			$this->fail( 'Missing or invalid root identity.' );
		}
		$root_position = $nodes[ $root_hash ];
		// Sort producer-owned buckets ONCE, before any recursive reader aliases
		// them. No ancestor retains a sorted COW copy during hydration. The index
		// stage covers this single sort overlap as well as construction growth.
		foreach ( $children as &$bucket ) {
			$this->tick(); $order = 0; $ordered = true;
			foreach ( $bucket as $child_order => $unused ) {
				$this->tick(); if ( $child_order !== $order++ ) { $ordered = false; break; }
			}
			// Native ksort converts even an ordered packed bucket to mixed.
			// Preserve its layout when it already has the required order.
			if ( ! $ordered ) { ksort( $bucket, SORT_NUMERIC ); }
		}
		unset( $bucket );
		if ( $preorder ) {
			// All identities/checksums and duplicates were checked above. Dense
			// certified topology plus the monotone cursor proves exact coverage;
			// no node can be revisited or skipped. Drop the private identity map
			// BEFORE admitting hydration, not as a sample/forecast credit. Keep
			// every original index/workspace/output charge and coefficient.
			$nodes = array(); $cursor = 0;
		}
		// Noncertified input still reuses the complete node map as its visited
		// set: null means visited; caller positions are int/string, never null.
		if ( null !== $phase ) { $this->scale->complete_retaining_indexes( $phase, $this ); }
		$result = $this->rebuild_node( $root_position, 0, $state_hash, $rows, $children, $nodes, $phase, $sequence, $cursor );
		if ( $preorder && $cursor !== count( $rows ) ) { $this->fail( 'Unconsumed preorder rows.' ); }
		foreach ( $nodes as $unvisited ) {
			$this->tick(); if ( null !== $unvisited ) { $this->fail( 'Orphan, cycle or unrelated root in tree.' ); }
		}
		unset( $children, $nodes );
		if ( null !== $phase ) { $this->scale->close_retaining_phase( $phase, $this ); }
		return $result;
	}

	/** Snapshot-only scoped reconstruction. Generic rebuild_tree stays indexed.
	 * This conservative path intentionally grants NO completion credit: attached
	 * output does not by itself prove which aggregate future allocations ended.
	 * Keep the entire original forecast through root and final digest work.
	 */
	public function rebuild_snapshot_stream( $state_key, $metadata, $source, $expected, $admit, $owner = null ) {
		if ( ! $source instanceof LegacySnapshotRowReader || ! is_callable( $admit ) ) { $this->fail( 'Invalid snapshot row source.' ); }
		if ( ! $owner instanceof SnapshotOwnedEventPlan || ! $owner->accepts( $state_key, $expected, $source ) ) { $owner = null; }
		StorageScalarObserver::acquisition( 6 );
		$ledger = array( 'visited' => 0, 'encoded_bytes' => 0, 'payload_bytes' => 0 );
		$state_hash = $this->codec->key_hash( $state_key ); $result = array();
		$order = 0; $id = 0; $previous = null;
		while ( true ) {
			$page = $source->page( null, $order, $id );
			if ( ! count( $page ) ) { break; }
			foreach ( $page as $row ) {
				call_user_func( $admit, 0 );
				$row = $this->validate_row( $row, $state_hash );
				$order = $row['item_order']; $id = $this->number( $row['id'] );
				if ( ( null !== $previous && $order <= $previous ) || ( 'list' === $metadata['kind'] && $order !== count( $result ) ) || array_key_exists( $row['key'], $result ) ) { $this->fail( 'Duplicate or noncanonical root key/order.' ); }
				if ( 'migrated' === $state_key && ( $row['legacy_identifier_value'] !== $this->codec->key_value( $row['key'] ) || strtolower( $row['legacy_identifier_hash'] ) !== $this->codec->key_hash( $row['key'] ) ) ) { $this->fail( 'History identifier projection mismatch.' ); }
				$completion = null;
				StorageScalarObserver::acquisition( 12 );
				$result[$row['key']] = $this->rebuild_stream_node( $row, 0, $state_hash, $source, $expected, $ledger, $admit, $owner, $completion );
				StorageScalarObserver::acquisition( 13 );
				if ( null !== $owner ) { $owner->attached( $source, $row, $completion ); }
				unset( $completion );
				$previous = $order;
			}
			unset( $page, $row );
		}
		foreach ( $ledger as $field => $actual ) {
			$name = 'visited' === $field ? 'record_count' : $field;
			if ( $actual !== $expected[$name] ) { $this->fail( 'Unreachable, missing or changed snapshot rows.' ); }
		}
		if ( count( $result ) !== $metadata['root_count'] ) { $this->fail( 'Root count mismatch.' ); }
		call_user_func( $admit, 0 );
		StorageScalarObserver::acquisition( 16 );
		$result = ( new LegacyCollectionEnvelope( $this->codec ) )->rebuild( $metadata, $result );
		StorageScalarObserver::acquisition( 17 );
		return $result;
	}

	private function rebuild_stream_node( $row, $depth, $state_hash, $source, $expected, &$ledger, $admit, $owner, &$completion ) {
		call_user_func( $admit, 0 );
		if ( $depth > $this->max_depth || $row['depth'] !== $depth || ++$ledger['visited'] > $expected['record_count'] || $row['encoded_bytes'] > $expected['encoded_bytes'] - $ledger['encoded_bytes'] ) { $this->fail( 'Invalid streamed ancestry or footprint.' ); }
		$ledger['encoded_bytes'] += $row['encoded_bytes']; $ledger['payload_bytes'] += $row['payload_bytes'];
		StorageScalarObserver::acquisition( 8, $row['payload_bytes'], $depth );
		list( $record, $count ) = $this->rebuild_record_fields( $row );
		StorageScalarObserver::acquisition( 9, $row['payload_bytes'], $depth );
		// Capture own scalar cost before descendants; NEVER describe the subtree
		// at attachment. No event exists until validation below has returned.
		$own = null === $owner ? 0 : SnapshotOwnedEventPlan::own_bytes( $record, null !== $count, strlen( $row['key_value'] ) );
		$next = 0; $order = 0; $id = 0;
		if ( null !== $count && $count > $expected['record_count'] - $ledger['visited'] ) { $this->fail( 'Impossible child count.' ); }
		while ( null !== $count && $next < $count ) {
			$page = $source->page( $row['node_hash'], $order, $id );
			if ( ! count( $page ) || count( $page ) > $count - $next ) { $this->fail( 'Missing or extra children.' ); }
			foreach ( $page as $child ) {
				call_user_func( $admit, 0 ); $child = $this->validate_row( $child, $state_hash );
				$order = $child['item_order']; $id = $this->number( $child['id'] );
				if ( $order !== $next++ || array_key_exists( $child['key'], $record['children'] ) ) { $this->fail( 'Duplicate typed child key or sibling order.' ); }
				$child_completion = null;
				StorageScalarObserver::acquisition( 12, 0, $depth );
				$record['children'][$child['key']] = $this->rebuild_stream_node( $child, $depth + 1, $state_hash, $source, $expected, $ledger, $admit, $owner, $child_completion );
				StorageScalarObserver::acquisition( 13, 0, $depth );
				if ( null !== $owner ) { $owner->attached( $source, $child, $child_completion ); }
				unset( $child_completion );
			}
			unset( $page, $child );
		}
		call_user_func( $admit, 0 );
		$this->validate_record_output( $row, $record, $depth, $state_hash );
		StorageScalarObserver::acquisition( 15, 0, $depth );
		if ( null !== $owner ) { $completion = array( 'node' => $row['node_hash'], 'depth' => $depth, 'bytes' => $own ); }
		return $record;
	}

	private function mutable_fields() {
		return array( 'migration_status', 'migrated', 'migrated_child_count', 'progress', 'part_of_migration',
			'migrated_id', 'migrated_title', 'children_count', 'children_errors', 'error' );
	}

	private function has_content_projection( $state_key, $root ) {
		return $root && self::CONTENT_STATE_KEY === $state_key;
	}

	private function make_row( $state_hash, $parent, $key, $record, $order, $depth, $phase = null ) {
		$this->tick();
		$this->admit( null === $phase ? LegacyTableReader::MAX_RECORD_BYTES : 0 );
		$this->codec->key_value( $key );
		$probe = array( $key => true ); $keys = array_keys( $probe );
		if ( $keys[0] !== $key ) { $this->fail( 'Invalid or coercible typed key.' ); }
		$mutable = array(); $values = array(); $child_count = null;
		if ( is_array( $record ) ) {
			foreach ( $record as $field => $value ) {
				if ( 'children' === $field && is_array( $value ) ) { $child_count = count( $value ); }
				else if ( in_array( $field, $this->mutable_fields(), true ) ) { $mutable[ $field ] = $value; }
				else { $values[ $field ] = $value; }
			}
			$base = array( 'version' => self::VERSION, 'kind' => 'array', 'field_order' => array_keys( $record ),
				'child_count' => $child_count, 'values' => $values );
		} else {
			$base = array( 'version' => self::VERSION, 'kind' => 'value', 'value' => $record );
		}
		$payload = $this->codec->encode_record_payload( $base ); $mutation = $this->codec->encode( $mutable );
		$row = array( 'row_kind' => null === $parent ? 'root' : 'child',
			'node_hash' => $this->codec->node_hash( $state_hash, $parent, $key ), 'parent_node_hash' => $parent,
			'key_type' => is_int( $key ) ? 'integer' : 'string', 'key_value' => base64_encode( (string) $key ),
			'item_order' => $order, 'depth' => $depth, 'payload' => $payload, 'mutable_payload' => $mutation,
			'payload_hash' => hash( 'sha256', $payload ),
			'payload_bytes' => strlen( $payload ) + strlen( $mutation ) );
		$row = array_merge( $row, $this->projections( $record ) );
		if ( $this->has_content_projection( $state_hash === $this->codec->key_hash( self::CONTENT_STATE_KEY ) ? self::CONTENT_STATE_KEY : null, null === $parent ) ) {
			$occurrence = self::content_occurrence_key( $record );
			$row['legacy_identifier_value'] = $this->codec->key_value( $occurrence );
			$row['legacy_identifier_hash'] = $this->codec->key_hash( $occurrence );
		}
		return $row;
	}

	/** Conversion-only preorder producer: false is a handled row-boundary yield.
	 * No complete-root row array or PHP continuation is retained. */
	public function walk_conversion_tree( $state_key, $key, $record, $order, $emit ) {
		if ( null === $this->scale || null === $this->scale->capacity() || ! is_callable( $emit ) ) { $this->fail( 'Conversion row walk requires an operation budget.' ); }
		$stats = array( 'record_count' => 0, 'encoded_bytes' => 0 );
		return $this->walk_conversion_node( $this->codec->key_hash( $state_key ), null, $key, $record, $order, 0, $emit, $stats );
	}
	private function walk_conversion_node( $state, $parent, $key, $record, $order, $depth, $emit, &$stats ) {
		$this->tick(); $this->admit( 0 );
		if ( $depth > $this->max_depth || $stats['record_count'] >= $this->max_records ) { $this->fail( 'Conversion tree exceeds finite shape.' ); }
		$row = $this->make_row( $state, $parent, $key, $record, $order, $depth );
		$row['logical_hash'] = $this->codec->snapshot_digest( $record );
		if ( null === $parent && $state === $this->codec->key_hash( 'migrated' ) ) { $row['legacy_identifier_value'] = $this->codec->key_value( $key ); $row['legacy_identifier_hash'] = $this->codec->key_hash( $key ); }
		$bytes = $this->encoded_bytes( $row );
		if ( $bytes > $this->scale->capacity()->limit( 'max_row_bytes' ) || $bytes > $this->max_tree_bytes - $stats['encoded_bytes'] ) { $this->fail( 'Conversion row/tree exceeds finite bytes.' ); }
		++$stats['record_count']; $stats['encoded_bytes'] += $bytes;
		// Row-subgraph logical tokens, excluding already-emitted child subtrees.
		$own = $record; $children = is_array( $record ) && isset( $record['children'] ) && is_array( $record['children'] );
		if ( $children ) { unset( $own['children'] ); }
		$footprint = $this->codec->snapshot_footprint( $own ); $tokens = $footprint['tokens'] + 1 + ( $children ? 2 : 0 ); unset( $own, $footprint );
		if ( false === call_user_func( $emit, $row, $tokens, $bytes ) ) { return false; }
		$node = $row['node_hash']; unset( $row );
		if ( $children ) {
			$ordinal = 0;
			foreach ( $record['children'] as $child_key => $child ) {
				if ( ! $this->walk_conversion_node( $state, $node, $child_key, $child, $ordinal++, $depth + 1, $emit, $stats ) ) { return false; }
			}
		}
		return true;
	}

	private function preflight_tree( $state_hash, $parent, $key, $record, $order, $depth, &$stats, $phase = null, &$slots = null ) {
		$this->tick();
		if ( $depth > $this->max_depth || $stats['record_count'] >= $this->max_records ) { $this->fail( 'Tree exceeds the depth/record budget.' ); }
		$row = $this->make_row( $state_hash, $parent, $key, $record, $order, $depth, $phase );
		$bytes = $this->encoded_bytes( $row );
		if ( $bytes > LegacyTableReader::MAX_RECORD_BYTES || $bytes > $this->max_tree_bytes - $stats['encoded_bytes'] ) {
			$this->fail( 'Row/tree exceeds the encoded byte budget.' );
		}
		$stats['record_count']++;
		$stats['payload_bytes'] += $row['payload_bytes'];
		$stats['encoded_bytes'] += $bytes;
		if ( null !== $slots ) {
			$index = $stats['record_count'] - 1;
			if ( ! array_key_exists( $index, $slots ) || null !== $slots[$index] ) { $this->fail( 'Invalid empty hash slot.' ); }
			$this->admit( 0 );
			$slots[$index] = $this->codec->snapshot_digest( $record );
		}
		if ( is_array( $record ) && isset( $record['children'] ) && is_array( $record['children'] ) ) {
			if ( count( $record['children'] ) > $this->max_records - $stats['record_count'] ) { $this->fail( 'Tree exceeds the record budget.' ); }
			$child_order = 0;
			foreach ( $record['children'] as $child_key => $child ) {
				$this->preflight_tree( $state_hash, $row['node_hash'], $child_key, $child, $child_order++, $depth + 1, $stats, $phase, $slots );
			}
		}
	}

	/** Exact qualified producer only: replace cached hashes, never emit recursively. */
	private function stage_retaining_node( $state_hash, $parent, $key, $record, $order, $depth, &$stats, $phase, &$slots, $row_phase = null ) {
		$this->tick(); $this->admit( 0 );
		$index = $stats['record_count'];
		if ( $depth > $this->max_depth || $index >= $this->max_records ||
			! isset( $slots[$index] ) || ! is_string( $slots[$index] ) ||
			! preg_match( '/^[a-f0-9]{64}$/D', $slots[$index] ) ) { $this->fail( 'Invalid cached logical hash slot.' ); }
		$row = $this->make_row( $state_hash, $parent, $key, $record, $order, $depth, $phase );
		$row['logical_hash'] = $slots[$index];
		$bytes = $this->encoded_bytes( $row );
		if ( $bytes > LegacyTableReader::MAX_RECORD_BYTES || $bytes > $this->max_tree_bytes - $stats['encoded_bytes'] ) {
			$this->fail( 'Row/tree exceeds the encoded byte budget.' );
		}
		$stats['record_count']++; $stats['payload_bytes'] += $row['payload_bytes']; $stats['encoded_bytes'] += $bytes;
		$node_hash = $row['node_hash'];
		$slots[$index] = $row; unset( $row );
		if ( null !== $row_phase ) {
			$this->scale->realize_retaining_event( $row_phase, $this, $index, GenericRetainingModel::row_forecast( $bytes, GenericRetainingModel::row_large_columns( $slots[$index] ) ) );
		}
		if ( is_array( $record ) && isset( $record['children'] ) && is_array( $record['children'] ) ) {
			$child_order = 0;
			foreach ( $record['children'] as $child_key => $child ) {
				$this->stage_retaining_node( $state_hash, $node_hash, $child_key, $child, $child_order++, $depth + 1, $stats, $phase, $slots, $row_phase );
			}
		}
	}

	private function flatten_node( $state_hash, $parent, $key, $record, $order, $depth, $emit, &$stats, $phase = null ) {
		$row = $this->make_row( $state_hash, $parent, $key, $record, $order, $depth, $phase );
		// Complete-record digest work is bounded by the preflight above, unlike
		// snapshot_digest(), which intentionally spans an entire logical store.
		$row['logical_hash'] = $this->codec->snapshot_digest( $record );
		$stats['record_count']++; $stats['payload_bytes'] += $row['payload_bytes']; $stats['encoded_bytes'] += $this->encoded_bytes( $row );
		if ( null !== $phase ) {
			$this->scale->realize_retaining_event( $phase, $this, $stats['record_count'] - 1, GenericRetainingModel::row_forecast( $this->encoded_bytes( $row ), GenericRetainingModel::row_large_columns( $row ) ) );
		}
		if ( false === call_user_func( $emit, $row ) ) { $this->fail( 'Staged consumer refused the write.' ); }
		if ( is_array( $record ) && isset( $record['children'] ) && is_array( $record['children'] ) ) {
			$child_order = 0;
			foreach ( $record['children'] as $child_key => $child ) {
				$this->flatten_node( $state_hash, $row['node_hash'], $child_key, $child, $child_order++, $depth + 1, $emit, $stats, $phase );
			}
		}
	}

	private function validate_row( $row, $state_hash ) {
		$this->tick();
		$required = array( 'row_kind', 'node_hash', 'parent_node_hash', 'key_type', 'key_value', 'item_order', 'depth',
			'payload', 'mutable_payload', 'payload_hash', 'logical_hash', 'payload_bytes' );
		if ( ! is_array( $row ) || array_diff( $required, array_keys( $row ) ) ) { $this->fail( 'Incomplete record row.' ); }
		foreach ( array( 'node_hash', 'payload_hash', 'logical_hash' ) as $field ) { $row[ $field ] = $this->hex( $row[ $field ] ); }
		if ( null !== $row['parent_node_hash'] ) { $row['parent_node_hash'] = $this->hex( $row['parent_node_hash'] ); }
		foreach ( array( 'item_order', 'depth', 'payload_bytes' ) as $field ) { $row[ $field ] = $this->number( $row[ $field ] ); }
		if ( $row['depth'] > $this->max_depth || $row['row_kind'] !== ( null === $row['parent_node_hash'] ? 'root' : 'child' ) ) {
			$this->fail( 'Invalid depth or row kind.' );
		}
		StorageScalarObserver::acquisition( 7, strlen( $row['key_value'] ) );
		$key = $this->key( $row['key_type'], $row['key_value'] );
		StorageScalarObserver::acquisition( 10, strlen( $row['key_value'] ) );
		if ( $row['node_hash'] !== $this->codec->node_hash( $state_hash, $row['parent_node_hash'], $key ) ) { $this->fail( 'Node/key/parent identity mismatch.' ); }
		foreach ( array( 'payload', 'mutable_payload' ) as $field ) {
			if ( ! is_string( $row[ $field ] ) ) { $this->fail( 'Invalid encoded payload.' ); }
		}
		if ( $row['payload_bytes'] !== strlen( $row['payload'] ) + strlen( $row['mutable_payload'] ) ||
			$row['payload_hash'] !== hash( 'sha256', $row['payload'] ) ) { $this->fail( 'Payload integrity/byte-count mismatch.' ); }
		$row['encoded_bytes'] = $this->encoded_bytes( $row );
		if ( $row['encoded_bytes'] > LegacyTableReader::MAX_RECORD_BYTES ) { $this->fail( 'Row exceeds the byte budget.' ); }
		$row['key'] = $key;
		return $row;
	}

	/**
	 * Eligibility only, under admitted row workspace + fixed metadata. Never
	 * reindex/copy rows, use/reset their internal pointer or retain decoded rows.
	 * At most 65 three-slot frames: parent identity, remaining count, next order.
	 * A false result selects the original indexed reader BEFORE any output.
	 */
	private function certify_preorder( &$rows, $root_hash ) {
		$frames = array(); $top = -1; $next = 0;
		foreach ( $rows as $position => $row ) {
			$this->tick();
			if ( $position !== $next++ || ! isset( $row['node_hash'], $row['depth'], $row['item_order'] ) || ! array_key_exists( 'parent_node_hash', $row ) ) { return false; }
			// Normalize only local topology scalars as the indexed reader does.
			// Full identity/key/checksum validation still builds the complete map.
			$row['node_hash'] = $this->hex( $row['node_hash'] );
			if ( null !== $row['parent_node_hash'] ) { $row['parent_node_hash'] = $this->hex( $row['parent_node_hash'] ); }
			$row['depth'] = $this->number( $row['depth'] ); $row['item_order'] = $this->number( $row['item_order'] );
			if ( $row['depth'] > $this->max_depth ) { return false; }
			while ( $top >= 0 && 0 === $frames[$top][1] ) { unset( $frames[$top--] ); }
			if ( 0 === $position ) {
				if ( $row['node_hash'] !== $root_hash || null !== $row['parent_node_hash'] || 0 !== $row['depth'] ) { return false; }
			} else {
				if ( $top < 0 || $row['parent_node_hash'] !== $frames[$top][0] || $row['depth'] !== $top + 1 || $row['item_order'] !== $frames[$top][2] ) { return false; }
				--$frames[$top][1]; ++$frames[$top][2];
			}
			$base = $this->codec->decode_record_payload( $row['payload'] );
			if ( ! is_array( $base ) || ! isset( $base['kind'] ) ) { return false; }
			$count = 0;
			if ( 'array' === $base['kind'] && array_key_exists( 'child_count', $base ) ) {
				if ( null !== $base['child_count'] ) { $count = $base['child_count']; }
			} else if ( 'value' !== $base['kind'] ) { return false; }
			if ( ! is_int( $count ) || $count < 0 || $count >= count( $rows ) ) { return false; }
			unset( $base );
			if ( $count ) {
				if ( $top >= 64 ) { return false; }
				$frames[++$top] = array( $row['node_hash'], $count, 0 );
			}
		}
		while ( $top >= 0 && 0 === $frames[$top][1] ) { unset( $frames[$top--] ); }
		return $top < 0;
	}

	private function rebuild_node( $position, $depth, $state_hash, &$rows, &$children, &$visited, $phase = null, &$sequence = 0, &$cursor = null ) {
		$this->tick();
		if ( $depth > $this->max_depth || ! isset( $rows[ $position ] ) || $this->number( $rows[ $position ]['depth'] ) !== $depth ) {
			$this->fail( 'Invalid tree ancestry/depth.' );
		}
		$row = $this->validate_row( $rows[ $position ], $state_hash );
		$preorder = null !== $cursor;
		if ( $preorder && $position !== $cursor++ ) { $this->fail( 'Invalid preorder cursor.' ); }
		$hash = $row['node_hash'];
		if ( ! $preorder ) {
			if ( ! isset( $visited[ $hash ] ) ) { $this->fail( 'Invalid tree ancestry/depth.' ); }
			$visited[ $hash ] = null;
		}
		$child_rows = isset( $children[ $hash ] ) ? $children[ $hash ] : array();
		// The certificate checked exact direct-child counts without an adjacency
		// allocation. This is not permission to skip envelope/identity/digest work.
		list( $record, $count ) = $this->rebuild_record_fields( $row );
		$child_total = $preorder && null !== $count ? $count : count( $child_rows );
		if ( $child_total !== ( null === $count ? 0 : $count ) ) { $this->fail( 'Missing or extra children.' ); }
		if ( null !== $phase ) { $this->scale->realize_retaining_event( $phase, $this, $sequence++, GenericRetainingModel::own_record_bytes( $record, null !== $count, strlen( $row['key_value'] ) ) ); }
		if ( null !== $count ) {
			$order = 0;
			if ( $preorder ) {
				for ( ; $order < $child_total; ++$order ) {
					$child_position = $cursor;
					if ( ! isset( $rows[$child_position] ) || $this->hex( $rows[$child_position]['parent_node_hash'] ) !== $hash || $this->number( $rows[$child_position]['item_order'] ) !== $order ) { $this->fail( 'Invalid parent or sibling order.' ); }
					$key = $this->key( $rows[$child_position]['key_type'], $rows[$child_position]['key_value'] );
					if ( array_key_exists( $key, $record['children'] ) ) { $this->fail( 'Duplicate typed child key.' ); }
					$record['children'][$key] = $this->rebuild_node( $child_position, $depth + 1, $state_hash, $rows, $children, $visited, $phase, $sequence, $cursor );
				}
			} else {
				foreach ( $child_rows as $child_order => $child_position ) {
					if ( $child_order !== $order++ ) { $this->fail( 'Invalid sibling order.' ); }
					$key = $this->key( $rows[ $child_position ]['key_type'], $rows[ $child_position ]['key_value'] );
					if ( array_key_exists( $key, $record['children'] ) ) { $this->fail( 'Duplicate typed child key.' ); }
					$record['children'][ $key ] = $this->rebuild_node( $child_position, $depth + 1, $state_hash, $rows, $children, $visited, $phase, $sequence );
				}
			}
		}
		$this->validate_record_output( $row, $record, $depth, $state_hash );
		return $record;
	}

	/** Shared split-envelope rules for indexed and scoped reconstruction. */
	private function rebuild_record_fields( $row ) {
		$base = $this->codec->decode_record_payload( $row['payload'] );
		$mutable = $this->codec->decode( $row['mutable_payload'] );
		StorageScalarObserver::acquisition( 11, strlen( $row['mutable_payload'] ) );
		if ( ! is_array( $base ) || ! isset( $base['version'], $base['kind'] ) || self::VERSION !== $base['version'] || ! is_array( $mutable ) ) { $this->fail( 'Unsupported record split envelope.' ); }
		if ( 'value' === $base['kind'] ) {
			if ( array_keys( $base ) !== array( 'version', 'kind', 'value' ) || is_array( $base['value'] ) || count( $mutable ) ) { $this->fail( 'Invalid scalar split envelope.' ); }
			return array( $base['value'], null );
		}
		if ( 'array' !== $base['kind'] || array_keys( $base ) !== array( 'version', 'kind', 'field_order', 'child_count', 'values' ) || ! is_array( $base['field_order'] ) || ! is_array( $base['values'] ) ||
			( count( $base['field_order'] ) && array_keys( $base['field_order'] ) !== range( 0, count( $base['field_order'] ) - 1 ) ) ) { $this->fail( 'Invalid ordered record envelope.' ); }
		$count = null === $base['child_count'] ? null : $this->number( $base['child_count'] );
		$record = array(); $used_base = 0; $used_mutable = 0; $used_children = false;
		foreach ( $base['field_order'] as $field ) {
			$this->codec->key_value( $field ); $probe = array( $field => true ); $keys = array_keys( $probe );
			if ( $keys[0] !== $field || array_key_exists( $field, $record ) ) { $this->fail( 'Duplicate or coercible field key.' ); }
			if ( 'children' === $field && null !== $count ) { $record[$field] = array(); $used_children = true; }
			else if ( in_array( $field, $this->mutable_fields(), true ) ) {
				if ( ! array_key_exists( $field, $mutable ) ) { $this->fail( 'Missing mutable field.' ); }
				$record[$field] = $mutable[$field]; ++$used_mutable;
			} else {
				if ( ! array_key_exists( $field, $base['values'] ) ) { $this->fail( 'Missing base field.' ); }
				$record[$field] = $base['values'][$field]; ++$used_base;
			}
		}
		if ( $used_base !== count( $base['values'] ) || $used_mutable !== count( $mutable ) || ( null !== $count && ! $used_children ) ) { $this->fail( 'Unaccounted fields or overlapping split values.' ); }
		return array( $record, $count );
	}

	private function validate_record_output( $row, $record, $depth, $state_hash ) {
		StorageScalarObserver::acquisition( 14, 0, $depth );
		if ( $row['logical_hash'] !== $this->codec->snapshot_digest( $record ) ) { $this->fail( 'Complete logical-record hash mismatch.' ); }
		$projections = $this->projections( $record );
		if ( 0 === $depth && $state_hash === $this->codec->key_hash( self::CONTENT_STATE_KEY ) ) {
			$occurrence = self::content_occurrence_key( $record );
			$projections['legacy_identifier_value'] = $this->codec->key_value( $occurrence );
			$projections['legacy_identifier_hash'] = $this->codec->key_hash( $occurrence );
		}
		foreach ( $projections as $field => $expected ) {
			if ( ! array_key_exists( $field, $row ) ) { $this->fail( 'Missing derived projection: ' . $field ); }
			$actual = $row[ $field ];
			if ( is_int( $expected ) ) { $actual = $this->number( $actual ); }
			else if ( null !== $expected && substr( $field, -5 ) === '_hash' ) { $actual = $this->hex( $actual ); }
			if ( $actual !== $expected ) { $this->fail( 'Derived projection mismatch: ' . $field ); }
		}
	}

	/** Pure projections only; raw logical values are always retained in envelopes. */
	private function projections( $record ) {
		$value = is_array( $record ) ? $record : array();
		$type = isset( $value['object_type'] ) ? $value['object_type'] : '';
		$status = isset( $value['migration_status'] ) ? $value['migration_status'] : '';
		foreach ( array( $type, $status ) as $word ) {
			if ( ! is_string( $word ) || strlen( $word ) > 32 ) { $this->fail( 'Unsupported indexed type/status; retain option authority.' ); }
		}
		$plugin = isset( $value['plugin_name'] ) ? $value['plugin_name'] : null;
		if ( null !== $plugin && ! is_string( $plugin ) ) { $this->fail( 'Unsupported indexed plugin name; retain option authority.' ); }
		$id = null;
		if ( array_key_exists( 'ID', $value ) ) {
			if ( ! is_scalar( $value['ID'] ) && null !== $value['ID'] ) { $this->fail( 'Unsupported source ID.' ); }
			$id = (string) $value['ID'];
		}
		$numeric = null;
		if ( 'image' === $type && null !== $id ) {
			$number = absint( $value['ID'] );
			if ( ! is_int( $number ) ) { $this->fail( 'Unsupported numeric source ID.' ); }
			if ( $number > 0 ) { $numeric = $number; }
		}
		return array( 'object_type' => $type, 'migration_status' => $status,
			'is_selected' => empty( $value['part_of_migration'] ) ? 0 : 1, 'is_migrated' => empty( $value['migrated'] ) ? 0 : 1,
			'migrated_id' => isset( $value['migrated_id'] ) ? $this->destination_id( $value['migrated_id'] ) : 0,
			'has_error' => ( isset( $value['error'] ) && false !== $value['error'] ) || ! empty( $value['children_errors'] ) ? 1 : 0,
			'source_plugin_value' => null === $plugin ? null : $this->codec->encode( $plugin ),
			'source_plugin_hash' => null === $plugin ? null : $this->codec->key_hash( $plugin ),
			'source_id_value' => null === $id ? null : $this->codec->encode( $id ), 'source_id_numeric' => $numeric,
			'source_identity_hash' => null === $plugin || null === $id ? null :
				$this->codec->snapshot_digest( array( 'source-exact-v1', $type, $plugin, $id ) ),
			'source_numeric_identity_hash' => null === $plugin || null === $numeric ? null :
				$this->codec->snapshot_digest( array( 'source-image-numeric-v1', 'image', $plugin, $numeric ) ) );
	}

	private function destination_id( $value ) {
		// Never turn a negative/float destination into a positive indexed match.
		if ( is_string( $value ) && preg_match( '/^[0-9]+$/D', $value ) ) {
			$canonical = ltrim( $value, '0' ); $canonical = '' === $canonical ? '0' : $canonical;
			return $this->number( $canonical );
		}
		if ( is_int( $value ) && $value >= 0 ) { return $value; }
		$this->fail( 'Unsupported destination ID projection; retain option authority.' );
	}

	private function encoded_bytes( $row ) {
		$this->tick();
		if ( ! is_array( $row ) ) { $this->fail( 'Missing encoded record.' ); }
		$bytes = 0;
		foreach ( array( 'payload', 'mutable_payload', 'key_value', 'source_plugin_value', 'source_id_value' ) as $field ) {
			if ( ! array_key_exists( $field, $row ) || ( null !== $row[ $field ] && ! is_string( $row[ $field ] ) ) ) { $this->fail( 'Invalid encoded column.' ); }
			$bytes += null === $row[ $field ] ? 0 : strlen( $row[ $field ] );
		}
		// A repository may add the exact history identifier; include it in the
		// aggregate cap without folding this physical lookup column into state.
		if ( isset( $row['legacy_identifier_value'] ) ) {
			if ( ! is_string( $row['legacy_identifier_value'] ) ) { $this->fail( 'Invalid encoded identifier.' ); }
			$bytes += strlen( $row['legacy_identifier_value'] );
		}
		return $bytes;
	}

	private function key( $type, $value ) {
		if ( ! is_string( $value ) ) { $this->fail( 'Invalid key bytes.' ); }
		$bytes = base64_decode( $value, true );
		if ( false === $bytes || base64_encode( $bytes ) !== $value ) { $this->fail( 'Invalid key bytes.' ); }
		if ( 'integer' === $type && (string) (int) $bytes === $bytes ) { return (int) $bytes; }
		if ( 'string' === $type ) {
			$probe = array( $bytes => true ); $keys = array_keys( $probe );
			if ( $keys[0] === $bytes ) { return $bytes; }
		}
		$this->fail( 'Invalid or coercible typed key.' );
	}

	private function number( $value ) {
		if ( ( ! is_int( $value ) && ! is_string( $value ) ) || (string) (int) $value !== (string) $value || (int) $value < 0 ) {
			$this->fail( 'Invalid unsigned integer.' );
		}
		return (int) $value;
	}

	private function hex( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^[a-fA-F0-9]{64}$/D', $value ) ) { $this->fail( 'Malformed hash.' ); }
		return strtolower( $value );
	}

	private function tick() { if ( null !== $this->scale ) { $this->scale->tick(); } }
	private function admit( $bytes ) { if ( null !== $this->scale ) { $this->scale->admit_memory( $bytes ); } }

	private function fail( $message ) {
		throw new \RuntimeException( 'Legacy storage record codec: ' . $message );
	}

	/**
	 * @internal Snapshot preflight ONLY. No callable, output owner assertion or
	 * retained rows. Unsupported codec/owner/runtime keeps aggregate admission.
	 * This is not snapshot stage/readback admission and grants no such authority.
	 */
	public function preflight_stats( $state_key, $key, $record, $item_order, $snapshot_plan = null ) {
		// Historically extra arguments (including callables) were ignored. They
		// are not plans, activation, callbacks or ownership authority.
		if ( ! $snapshot_plan instanceof SnapshotOwnedEventPlan ) { $snapshot_plan = null; }
		$phase = null;
		if ( null !== $this->scale && SnapshotStatsModel::supports( $this, $this->codec, $this->scale ) ) {
			$this->scale->admit_snapshot_stats_workspace( GenericRetainingModel::descriptor_bytes() );
			$phase = $this->scale->open_retaining_phase( $this, GenericRetainingModel::descriptor_bytes() );
			$d = $this->describe_tree( $key, $record, 'repository-sink', $state_key );
			$workspace = SnapshotStatsModel::workspace( $d, $state_key );
			// Keep the old descriptor reservation while admitting its replacement;
			// no sample or a completed row can release this private workspace.
			$this->scale->admit_snapshot_stats_workspace( $workspace );
			$this->scale->plan_retaining_phase( $phase, $this, 0, $workspace, 0, 0 );
		}
		$item_order = $this->number( $item_order );
		$stats = array( 'record_count' => 0, 'payload_bytes' => 0, 'encoded_bytes' => 0 );
		$preflight = $stats;
		$this->admit( 0 );
		$state_hash = $this->codec->key_hash( $state_key );
		$this->preflight_tree( $state_hash, null, $key, $record, $item_order, 0, $preflight, $phase );
		if ( null !== $phase && ( $preflight['record_count'] !== $d['records'] || $preflight['payload_bytes'] !== $d['payload_bytes'] ||
			$preflight['encoded_bytes'] !== $d['encoded_bytes'] ) ) { $this->fail( 'Descriptor/preflight mismatch.' ); }
		if ( null !== $this->scale ) { $this->codec->snapshot_digest( $record ); }
		$this->admit( null === $phase ? $preflight['encoded_bytes'] : 0 );
		$this->discard_stats_node( $state_hash, null, $key, $record, $item_order, 0, $stats, $phase, $snapshot_plan );
		if ( $stats !== $preflight ) { $this->fail( 'Stats/preflight mismatch.' ); }
		// All row/ancestor/codec/digest frames have unwound before release. On
		// interruption the existing owner-bound ledger remains pending instead.
		if ( null !== $phase ) { $this->scale->close_retaining_phase( $phase, $this ); }
		return $stats;
	}

	private function discard_stats_node( $state_hash, $parent, $key, $record, $order, $depth, &$stats, $phase, $snapshot_plan = null ) {
		if ( null !== $phase ) { $this->scale->admit_snapshot_stats_workspace( 0 ); }
		$row = $this->make_row( $state_hash, $parent, $key, $record, $order, $depth, $phase );
		// Match flatten's complete-record digest validation, including no-scale
		// depth/domain refusal. Digests are checked, never exported or retained.
		$this->codec->snapshot_digest( $record );
		$stats['record_count']++; $stats['payload_bytes'] += $row['payload_bytes']; $stats['encoded_bytes'] += $this->encoded_bytes( $row );
		if ( null !== $snapshot_plan ) { $snapshot_plan->observe( $record, $row, $this->codec ); }
		$hash = $row['node_hash']; unset( $row );
		if ( is_array( $record ) && isset( $record['children'] ) && is_array( $record['children'] ) ) {
			$order = 0;
			foreach ( $record['children'] as $child_key => $child ) {
				$this->discard_stats_node( $state_hash, $hash, $child_key, $child, $order++, $depth + 1, $stats, $phase, $snapshot_plan );
			}
		}
	}
}
