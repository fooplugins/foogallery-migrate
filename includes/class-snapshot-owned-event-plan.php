<?php
/**
	* Internal, unqualified scalar snapshot plan and attachment-event witness.
	* Disabled by its repository owner. NEVER passed to the retaining-credit ledger.
	* Coefficients are candidate maxima, not runtime/host qualification or approval.
	* No records, payloads, decoded graph, per-node ledger or callbacks are retained.
	*/
namespace FooPlugins\FooGalleryMigrate;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class SnapshotOwnedEventPlan {
	private $future = 0;
	private $records = 0;
	private $children_bytes = 0;
	private $max_resize = 0;
	private $max_native_tokens = 0;
	private $max_row_bytes = 0;
	private $max_depth = 0;
	private $max_string = 0;
	private $fields = 0;
	private $keys = 0;
	private $strings = 0;
	private $binding;
	private $source;
	private $remaining = 0;
	private $events = 0;
	private $last_depth = -1;
	private $last_bytes = 0;
	private $private_bytes = 0;
	private $complete = false;

	public static function descriptor_bytes() { return GenericRetainingModel::descriptor_bytes(); }
	/** Extra actual bound only; never add/remove legacy observer calls. */
	public static function admit_descriptor( $scale ) {
		if ( null !== $scale ) { $scale->admit_snapshot_stats_workspace( self::descriptor_bytes() ); return; }
		$limit = StoragePreflight::memory_bytes( ini_get( 'memory_limit' ) );
		$used = max( memory_get_usage( true ), memory_get_peak_usage( true ) );
		// An extra construction-only check, NOT an alternative decode policy. All
		// original caller/scale forecasts and reserves still apply unchanged.
		if ( false === $limit || ( -1 !== $limit && ( $used > $limit || self::descriptor_bytes() > $limit - $used ) ) ) { self::fail(); }
	}
	private static function add( $a, $b ) { return StorageScaleAdmission::checked_add( $a, $b ); }
	private static function mul( $a, $b ) { return StorageScaleAdmission::checked_multiply( $a, $b ); }
	private function strings( $value, $depth ) {
		if ( $depth > 64 ) { self::fail(); }
		if ( is_string( $value ) ) {
			$this->strings = self::add( $this->strings, strlen( $value ) );
			$this->max_string = max( $this->max_string, strlen( $value ) );
		} else if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) { $this->strings( $k, $depth + 1 ); $this->strings( $v, $depth + 1 ); }
		}
	}
	/** Called inside the existing discard walk, with its one already-built row. */
	public function observe( $record, $row, $codec ) {
		if ( null !== $this->binding ) { self::fail(); }
		$has = is_array( $record ) && isset( $record['children'] ) && is_array( $record['children'] );
		$this->future = self::add( $this->future, self::own_bytes( $record, $has, strlen( $row['key_value'] ) ) );
		$this->records = self::add( $this->records, 1 );
		$this->keys = self::add( $this->keys, strlen( $row['key_value'] ) );
		if ( is_array( $record ) ) {
			$this->fields = self::add( $this->fields, count( $record ) );
			foreach ( $record as $field => $v ) { $this->strings( $field, 1 ); if ( ! $has || 'children' !== $field ) { $this->strings( $v, 1 ); } }
		} else { $this->strings( $record, 0 ); }
		if ( $has ) {
			$size = GenericRetainingModel::container( count( $record['children'] ) );
			$this->children_bytes = self::add( $this->children_bytes, $size ); $this->max_resize = max( $this->max_resize, $size );
		}
		$tokens = 0;
		foreach ( array( 'payload', 'mutable_payload' ) as $field ) {
			$scan = 'payload' === $field ? $codec->inspect_json_record_payload( $row[$field] ) : $codec->inspect_json( $row[$field] );
			$tokens = self::add( $tokens, $scan['tokens'] );
		}
		$this->max_native_tokens = max( $this->max_native_tokens, $tokens );
		// Include all physical columns (plus a history identifier upper bound).
		$bytes = $row['payload_bytes'];
		foreach ( array( 'key_value', 'source_plugin_value', 'source_id_value', 'legacy_identifier_value' ) as $field ) {
			if ( isset( $row[$field] ) ) { $bytes = self::add( $bytes, strlen( $row[$field] ) ); }
		}
		$bytes = self::add( $bytes, self::add( 96, self::mul( 2, strlen( $row['key_value'] ) ) ) );
		$this->max_row_bytes = max( $this->max_row_bytes, $bytes );
		$this->max_depth = max( $this->max_depth, $row['depth'] );
	}
	public static function own_bytes( $record, $has, $key_bytes ) { return GenericRetainingModel::own_record_bytes( $record, $has, $key_bytes ); }
	/** Fixed-size scalar descriptor; no source value or per-record table. */
	public function descriptor( $root_count, $root_bytes ) {
		$root = GenericRetainingModel::container( $root_count );
		$growth = self::add( self::add( $this->children_bytes, $root ), max( $this->max_resize, $root ) );
		// All ancestor bounded pages overlap row JSON/tag/untag/output work.
		// Match the transport's simultaneous driver+PHP window maximum; include
		// every ancestor window, metadata slots and resize overlap independently.
		$width = min( LegacySnapshotRowReader::WINDOW_ROWS, $this->records );
		$page = self::add( 16384, self::mul( 2, self::mul( $width, self::add( $this->max_row_bytes, 8192 ) ) ) );
		$pages = self::mul( self::add( $this->max_depth, 1 ), $page );
		$native = self::add( self::mul( 1536, $this->max_native_tokens ), self::mul( 16, $this->max_row_bytes ) );
		$digest = self::add( 16384, self::mul( 16, max( $this->max_string, $root_bytes ) ) );
		$envelope = self::add( self::mul( 4, $root ), self::mul( 16, $root_bytes ) );
		$private = self::add( self::descriptor_bytes(), self::add( $growth, self::add( $pages, self::add( $native, self::add( $digest, $envelope ) ) ) ) );
		return array( 'future' => $this->future, 'records' => $this->records, 'private' => $private,
			'fields' => $this->fields, 'keys' => $this->keys, 'strings' => $this->strings,
			'growth_resize_cow' => $growth, 'pages' => $pages, 'native_tagged' => $native, 'digest' => $digest, 'envelope' => $envelope );
	}
	/** Fresh ownership for EACH exact source; immutable descriptors carry no credit. */
	public function bind( $descriptor, $binding, $source ) {
		if ( null !== $this->binding || ! $source instanceof LegacySnapshotRowReader || $descriptor['records'] !== $binding['stats']['record_count'] ) { self::fail(); }
		$this->binding = $binding; $this->source = $source;
		$this->remaining = $descriptor['future']; $this->private_bytes = $descriptor['private'];
		$this->records = $descriptor['records'];
	}
	public function accepts( $key, $stats, $source ) {
		return ! $this->complete && null !== $this->binding && $this->binding['key'] === $key && $this->binding['stats'] === $stats && $this->owns_source( $source );
	}
	public function matches( $binding ) { return $this->binding === $binding; }
	public function owns_source( $source ) { return $source === $this->source; }
	/** Caller has assigned validated output and the recursive callee has unwound.
		* Own bytes ONLY; children/output capacities, pages and digest stay private.
		* This shadow event does not alter memory admission or release any obligation.
		*/
	public function attached( $source, $row, $completion ) {
		if ( ! $this->owns_source( $source ) || $this->complete || ! is_array( $completion ) ||
			$completion['node'] !== $row['node_hash'] || $completion['depth'] !== $row['depth'] ||
			! is_int( $completion['bytes'] ) || $completion['bytes'] < 0 || $completion['bytes'] > $this->remaining || $this->events >= $this->records ) { self::fail(); }
		$this->remaining -= $completion['bytes']; ++$this->events;
		$this->last_depth = $completion['depth']; $this->last_bytes = $completion['bytes'];
	}
	/** After complete digest/token validation and repository temporary release.
		* Private maxima remain recorded, NOT credited even on success. On standalone
		* return, output ownership transfers to the caller, never replacement credit.
		*/
	public function finish() {
		if ( $this->complete || $this->remaining || $this->events !== $this->records ) { self::fail(); }
		$this->complete = true; $this->source = null;
	}
	private static function fail() { throw new \RuntimeException( 'Invalid snapshot-owned event plan.' ); }
}
