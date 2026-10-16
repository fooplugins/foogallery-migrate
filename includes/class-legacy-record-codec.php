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
	 * Emit rows individually in preorder; never allocate a second complete row
	 * collection. The consumer MUST stage/rollback partial output on any failure.
	 * Hashes are hex for prepared fixed UNHEX() SQL, not raw binary transport.
	 */
	public function flatten_tree( $state_key, $key, $record, $item_order, $emit ) {
		if ( ! is_callable( $emit ) ) { $this->fail( 'A staged row consumer is required.' ); }
		$item_order = $this->number( $item_order );
		$stats = array( 'record_count' => 0, 'payload_bytes' => 0, 'encoded_bytes' => 0 );
		$preflight = $stats;
		$this->admit( 0 );
		$this->preflight_tree( $this->codec->key_hash( $state_key ), null, $key, $record, $item_order, 0, $preflight );
		// Row ancestry is not logical depth: children contributes two edges,
		// and ordinary/mutable fields may nest without creating any child row.
		// Finish the shared logical admission before invoking the consumer.
		if ( null !== $this->scale ) { $this->codec->snapshot_digest( $record ); }
		$this->admit( $preflight['encoded_bytes'] );
		$this->flatten_node( $this->codec->key_hash( $state_key ), null, $key, $record, $item_order, 0, $emit, $stats );
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
		$this->admit( $bytes );
		$state_hash = $this->codec->key_hash( $state_key );
		// Index immutable encoded rows by their caller-owned positions. Validate
		// every encoded identity/checksum before hydrating any split envelope.
		// Neither this index nor adjacency retains decoded payloads or full rows.
		foreach ( $rows as $position => $row ) {
			$row = $this->validate_row( $row, $state_hash );
			$hash = $row['node_hash'];
			if ( isset( $nodes[ $hash ] ) ) { $this->fail( 'Duplicate node identity.' ); }

			$nodes[ $hash ] = $position;
			if ( null !== $row['parent_node_hash'] ) {
				$parent = $row['parent_node_hash'];
				$order = $row['item_order'];
				if ( isset( $children[ $parent ][ $order ] ) ) { $this->fail( 'Duplicate sibling order.' ); }
				$children[ $parent ][ $order ] = $position;
			}
		}
		unset( $row );
		if ( ! isset( $nodes[ $root_hash ] ) || null !== $rows[ $nodes[ $root_hash ] ]['parent_node_hash'] || $this->number( $rows[ $nodes[ $root_hash ] ]['depth'] ) !== 0 ) {
			$this->fail( 'Missing or invalid root identity.' );
		}
		$visited = array();
		$result = $this->rebuild_node( $nodes[ $root_hash ], 0, $state_hash, $rows, $children, $visited );
		if ( count( $visited ) !== count( $nodes ) ) { $this->fail( 'Orphan, cycle or unrelated root in tree.' ); }
		return $result;
	}

	private function mutable_fields() {
		return array( 'migration_status', 'migrated', 'migrated_child_count', 'progress', 'part_of_migration',
			'migrated_id', 'migrated_title', 'children_count', 'children_errors', 'error' );
	}

	private function make_row( $state_hash, $parent, $key, $record, $order, $depth ) {
		$this->tick();
		$this->admit( LegacyTableReader::MAX_RECORD_BYTES );
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
		return $row;
	}

	private function preflight_tree( $state_hash, $parent, $key, $record, $order, $depth, &$stats ) {
		$this->tick();
		if ( $depth > $this->max_depth || $stats['record_count'] >= $this->max_records ) { $this->fail( 'Tree exceeds the depth/record budget.' ); }
		$row = $this->make_row( $state_hash, $parent, $key, $record, $order, $depth );
		$bytes = $this->encoded_bytes( $row );
		if ( $bytes > LegacyTableReader::MAX_RECORD_BYTES || $bytes > $this->max_tree_bytes - $stats['encoded_bytes'] ) {
			$this->fail( 'Row/tree exceeds the encoded byte budget.' );
		}
		$stats['record_count']++;
		$stats['payload_bytes'] += $row['payload_bytes'];
		$stats['encoded_bytes'] += $bytes;
		if ( is_array( $record ) && isset( $record['children'] ) && is_array( $record['children'] ) ) {
			if ( count( $record['children'] ) > $this->max_records - $stats['record_count'] ) { $this->fail( 'Tree exceeds the record budget.' ); }
			$child_order = 0;
			foreach ( $record['children'] as $child_key => $child ) {
				$this->preflight_tree( $state_hash, $row['node_hash'], $child_key, $child, $child_order++, $depth + 1, $stats );
			}
		}
	}

	private function flatten_node( $state_hash, $parent, $key, $record, $order, $depth, $emit, &$stats ) {
		$row = $this->make_row( $state_hash, $parent, $key, $record, $order, $depth );
		// Complete-record digest work is bounded by the preflight above, unlike
		// snapshot_digest(), which intentionally spans an entire logical store.
		$row['logical_hash'] = $this->codec->snapshot_digest( $record );
		$stats['record_count']++; $stats['payload_bytes'] += $row['payload_bytes']; $stats['encoded_bytes'] += $this->encoded_bytes( $row );
		if ( false === call_user_func( $emit, $row ) ) { $this->fail( 'Staged consumer refused the write.' ); }
		if ( is_array( $record ) && isset( $record['children'] ) && is_array( $record['children'] ) ) {
			$child_order = 0;
			foreach ( $record['children'] as $child_key => $child ) {
				$this->flatten_node( $state_hash, $row['node_hash'], $child_key, $child, $child_order++, $depth + 1, $emit, $stats );
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
		$key = $this->key( $row['key_type'], $row['key_value'] );
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

	private function rebuild_node( $position, $depth, $state_hash, &$rows, &$children, &$visited ) {
		$this->tick();
		if ( $depth > $this->max_depth || isset( $visited[ $position ] ) || ! isset( $rows[ $position ] ) || $this->number( $rows[ $position ]['depth'] ) !== $depth ) {
			$this->fail( 'Invalid tree ancestry/depth.' );
		}
		$visited[ $position ] = true;
		$row = $this->validate_row( $rows[ $position ], $state_hash );
		$hash = $row['node_hash'];
		$base = $this->codec->decode_record_payload( $row['payload'] );
		$mutable = $this->codec->decode( $row['mutable_payload'] );
		$child_rows = isset( $children[ $hash ] ) ? $children[ $hash ] : array();
		if ( ! is_array( $base ) || ! isset( $base['version'], $base['kind'] ) || self::VERSION !== $base['version'] || ! is_array( $mutable ) ) {
			$this->fail( 'Unsupported record split envelope.' );
		}
		if ( 'value' === $base['kind'] ) {
			if ( array_keys( $base ) !== array( 'version', 'kind', 'value' ) || is_array( $base['value'] ) || count( $mutable ) || count( $child_rows ) ) {
				$this->fail( 'Invalid scalar split envelope.' );
			}
			$record = $base['value'];
		} else if ( 'array' === $base['kind'] ) {
			if ( array_keys( $base ) !== array( 'version', 'kind', 'field_order', 'child_count', 'values' ) ||
				! is_array( $base['field_order'] ) || ! is_array( $base['values'] ) ||
				( count( $base['field_order'] ) && array_keys( $base['field_order'] ) !== range( 0, count( $base['field_order'] ) - 1 ) ) ) {
				$this->fail( 'Invalid ordered record envelope.' );
			}
			$count = $base['child_count'];
			if ( null !== $count ) {
				$count = $this->number( $count );
				if ( count( $child_rows ) !== $count ) { $this->fail( 'Missing or extra children.' ); }
			} else if ( count( $child_rows ) ) { $this->fail( 'Unexpected children.' ); }
			// Place this node's own fields before descending. Envelope arrays must
			// not remain live at every ancestor while the final children grow.
			$record = array(); $used_base = 0; $used_mutable = 0; $used_children = false;
			foreach ( $base['field_order'] as $field ) {
				$this->codec->key_value( $field );
				$probe = array( $field => true ); $keys = array_keys( $probe );
				if ( $keys[0] !== $field || array_key_exists( $field, $record ) ) { $this->fail( 'Duplicate or coercible field key.' ); }
				if ( 'children' === $field && null !== $count ) {
					$record[ $field ] = array(); $used_children = true;
				} else if ( in_array( $field, $this->mutable_fields(), true ) ) {
					if ( ! array_key_exists( $field, $mutable ) ) { $this->fail( 'Missing mutable field.' ); }
					$record[ $field ] = $mutable[ $field ]; $used_mutable++;
				} else {
					if ( ! array_key_exists( $field, $base['values'] ) ) { $this->fail( 'Missing base field.' ); }
					$record[ $field ] = $base['values'][ $field ]; $used_base++;
				}
			}
			if ( $used_base !== count( $base['values'] ) || $used_mutable !== count( $mutable ) || ( null !== $count && ! $used_children ) ) {
				$this->fail( 'Unaccounted fields or overlapping split values.' );
			}
			unset( $base, $mutable, $probe, $keys );
			ksort( $child_rows, SORT_NUMERIC );
			$order = 0;
			foreach ( $child_rows as $child_order => $child_position ) {
				if ( $child_order !== $order++ ) { $this->fail( 'Invalid sibling order.' ); }
				$key = $this->key( $rows[ $child_position ]['key_type'], $rows[ $child_position ]['key_value'] );
				if ( array_key_exists( $key, $record['children'] ) ) { $this->fail( 'Duplicate typed child key.' ); }
				$record['children'][ $key ] = $this->rebuild_node( $child_position, $depth + 1, $state_hash, $rows, $children, $visited );
			}
		} else { $this->fail( 'Unknown record kind.' ); }
		if ( $row['logical_hash'] !== $this->codec->snapshot_digest( $record ) ) { $this->fail( 'Complete logical-record hash mismatch.' ); }
		foreach ( $this->projections( $record ) as $field => $expected ) {
			if ( ! array_key_exists( $field, $row ) ) { $this->fail( 'Missing derived projection: ' . $field ); }
			$actual = $row[ $field ];
			if ( is_int( $expected ) ) { $actual = $this->number( $actual ); }
			else if ( null !== $expected && substr( $field, -5 ) === '_hash' ) { $actual = $this->hex( $actual ); }
			if ( $actual !== $expected ) { $this->fail( 'Derived projection mismatch: ' . $field ); }
		}
		return $record;
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
}
