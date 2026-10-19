<?php
/** Producer-only, operation-local authentication. Never reconstructed from SQL. */
namespace FooPlugins\FooGalleryMigrate;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class SnapshotPageWitness {
	const VERSION = 'snapshot-page-witness-v1';
	private $columns;
	private $scope;
	private $capacity;
	private $digests = array();
	private $binding;
	private $descriptor;
	private $pid;

	public function __construct( $columns, $scope, $capacity ) {
		if ( null !== $this->pid ) { self::fail(); }
		if ( ! is_array( $columns ) || ! is_array( $scope ) || count( $scope ) !== 3 || ! is_int( $capacity ) || $capacity < 0 ) { self::fail(); }
		$this->columns = $columns; $this->scope = $scope; $this->capacity = $capacity; $this->pid = getmypid();
	}
	public function __clone() { self::fail(); }
	public function __sleep() { self::fail(); }
	public function __wakeup() { self::fail(); }
	/** Conservative construction/resize + retained index + hash/lookup floor.
	 * Not allocator calibration: qualification must retain this entire floor. */
	public static function workspace( $rows ) {
		return StorageScaleAdmission::checked_add( GenericRetainingModel::descriptor_bytes(), StorageScaleAdmission::checked_add(
			StorageScaleAdmission::checked_multiply( 2, GenericRetainingModel::container( $rows ) ),
			StorageScaleAdmission::checked_multiply( $rows, GenericRetainingModel::string_bytes( 32 ) ) ) );
	}
	/** Values are the EXACT defaults/arguments used by INSERT, plus returned ID. */
	public function append( $row ) {
		if ( null !== $this->binding || getmypid() !== $this->pid || count( $this->digests ) >= $this->capacity ) { self::fail(); }
		$id = self::number( $row['id'] );
		if ( isset( $this->digests[$id] ) ) { self::fail(); }
		$this->digests[$id] = $this->commitment( $row );
	}
	public function seal( $binding, $descriptor ) {
		if ( null !== $this->binding || count( $this->digests ) !== $this->capacity || $binding['stats']['record_count'] !== $this->capacity ) { self::fail(); }
		$this->binding = $binding; $this->descriptor = $descriptor;
	}
	public function scope_matches( $scope ) { return $this->scope === $scope && $this->pid === getmypid(); }
	public function matches( $binding, $descriptor ) {
		return getmypid() === $this->pid && null !== $this->binding && $binding === $this->binding && $descriptor === $this->descriptor;
	}
	/** Hash scalars incrementally, in the fixed schema-version column order.
	 * Canonicalize native-driver unsigned decimal/HEX representations only.
	 * NULL is different from an empty string, zero, or an absent column. */
	private function commitment( $row ) {
		if ( ! is_array( $row ) || count( $row ) !== count( $this->columns ) ) { self::fail(); }
		foreach ( array( 'blog_id', 'state_id', 'generation' ) as $i => $name ) {
			if ( ! array_key_exists( $name, $row ) || self::number( $row[$name] ) !== $this->scope[$i] ) { self::fail(); }
		}
		$h = hash_init( 'sha256' ); hash_update( $h, self::VERSION . "\0" );
		foreach ( $this->columns as $name => $spec ) {
			if ( ! array_key_exists( $name, $row ) ) { self::fail(); }
			$v = $row[$name]; hash_update( $h, strlen( $name ) . ':' . $name );
			if ( null === $v ) { if ( ! $spec[1] ) { self::fail(); } hash_update( $h, 'N;' ); continue; }
			if ( false !== strpos( $spec[0], 'int' ) ) { $v = (string) self::number( $v ); }
			else if ( 'binary(32)' === $spec[0] ) {
				if ( ! is_string( $v ) || ! preg_match( '/^[a-f0-9]{64}$/Di', $v ) ) { self::fail(); } $v = strtolower( $v );
			} else if ( ! is_string( $v ) ) { self::fail(); }
			hash_update( $h, 'S' . strlen( $v ) . ':' ); hash_update( $h, $v );
		}
		return hash_final( $h, true );
	}
	/** Called on the same bounded raw page later passed to native decoding. */
	public function authenticate( $rows ) {
		if ( null === $this->binding || getmypid() !== $this->pid ) { self::fail(); }
		$seen = array();
		foreach ( $rows as $row ) {
			$id = self::number( $row['id'] );
			if ( isset( $seen[$id] ) || ! isset( $this->digests[$id] ) || $this->digests[$id] !== $this->commitment( $row ) ) { self::fail(); }
			$seen[$id] = true;
		}
	}
	private static function number( $v ) {
		if ( ( ! is_int( $v ) && ! is_string( $v ) ) || (string) (int) $v !== (string) $v || (int) $v < 0 ) { self::fail(); }
		return (int) $v;
	}
	private static function fail() { throw new \RuntimeException( 'Snapshot producer page witness refused before decode.' ); }
}
