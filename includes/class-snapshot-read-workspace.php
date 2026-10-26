<?php
/** Private disk workspace for finite offline qualification, not product storage.
 * Fixed-width integer records, no serialized objects, extensions or raw graphs.
 * Anonymous tmpfile handles close on success, refusal, or process termination. */
namespace FooPlugins\FooGalleryMigrate;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class SnapshotReadWorkspace {
	private $files = array();
	private $records;
	private $slots;
	private $depth = 0;
	private $active = 0;
	public function __construct( $records ) {
		$this->records = $records;
		$this->slots = StorageScaleAdmission::checked_multiply( 3, StorageScaleAdmission::checked_add( 7, StorageScaleAdmission::checked_multiply( 4, $records ) ) );
		StorageScaleAdmission::checked_multiply( $this->slots, 60 ); // Offset overflow refusal before any seek.
		foreach ( array( 'seen', 'before', 'stack', 'residual' ) as $name ) {
			$this->files[$name] = tmpfile(); if ( ! is_resource( $this->files[$name] ) ) { self::fail(); }
		}
	}
	private static function fail() { throw new \RuntimeException( 'Snapshot qualification workspace IO/bound refused.' ); }
	public function reset_trace() {
		foreach ( array( 'seen', 'before', 'stack' ) as $name ) { if ( ! ftruncate( $this->files[$name], 0 ) ) { self::fail(); } }
		$this->depth = 0; $this->active = 0;
	}
	private function slot( $id ) {
		$parts = explode( ':', $id );
		$read = array_search( $parts[0], array( 'staged', 'read1', 'read2' ), true );
		$seam = array_search( $parts[1], array( 'root-native', 'page-chain', 'whole-record-digest', 'cow-resize', 'envelope', 'final-digest', 'exit' ), true );
		$row = (int) $parts[2];
		if ( false === $seam ) {
			$kind = array_search( $parts[1], array( 'key-native', 'payload-native', 'attach', 'unwind' ), true );
			if ( false === $kind || $row < 1 || $row > $this->records ) { self::fail(); }
			$seam = 7 + ( $row - 1 ) * 4 + $kind;
		} else if ( $row !== 0 ) { self::fail(); }
		if ( false === $read ) { self::fail(); }
		return $read * ( 7 + 4 * $this->records ) + $seam;
	}
	private function read( $name, $offset, $bytes ) {
		if ( fseek( $this->files[$name], $offset ) !== 0 ) { self::fail(); }
		$data = fread( $this->files[$name], $bytes ); if ( false === $data ) { self::fail(); }
		return str_pad( $data, $bytes, "\0" );
	}
	private function write( $name, $offset, $data ) {
		if ( fseek( $this->files[$name], $offset ) !== 0 || fwrite( $this->files[$name], $data ) !== strlen( $data ) ) { self::fail(); }
	}
	private function numbers( $data ) { return array( (int) substr( $data, 0, 20 ), (int) substr( $data, 20, 20 ), (int) substr( $data, 40, 20 ) ); }
	private function record( $values ) { return sprintf( '%020d%020d%020d', $values[0], $values[1], $values[2] ); }
	public function seen( $key ) {
		$parts = explode( ':', $key ); $edge = array_pop( $parts );
		return $this->read( 'seen', 2 * $this->slot( implode( ':', $parts ) ) + ( $edge === 'after' ? 1 : 0 ), 1 ) === '1';
	}
	public function mark( $key ) {
		$parts = explode( ':', $key ); $edge = array_pop( $parts );
		$this->write( 'seen', 2 * $this->slot( implode( ':', $parts ) ) + ( $edge === 'after' ? 1 : 0 ), '1' );
	}
	public function push( $id, $values ) {
		$slot = $this->slot( $id );
		$this->write( 'stack', $this->depth * 20, sprintf( '%020d', $slot ) ); ++$this->depth;
		$this->write( 'before', $slot * 60, $this->record( $values ) ); ++$this->active;
	}
	public function pop( $id ) {
		$slot = $this->slot( $id );
		if ( ! $this->depth || (int) $this->read( 'stack', ( $this->depth - 1 ) * 20, 20 ) !== $slot ) { throw new \RuntimeException( 'Snapshot finite host cell refused: unwound seam ordering' ); }
		--$this->depth; --$this->active; return $this->numbers( $this->read( 'before', $slot * 60, 60 ) );
	}
	public function depth() { return $this->depth; }
	public function active() { return $this->active; }
	public function residual( $id, $value ) {
		$offset = $this->slot( $id ) * 60; $v = $this->numbers( $this->read( 'residual', $offset, 60 ) );
		$this->write( 'residual', $offset, $this->record( array( $v[0] + 1, $v[0] ? min( $v[1], $value ) : $value, max( $v[2], $value ) ) ) );
	}
	public function spread( $repeats ) {
		$spread = 0;
		for ( $slot = 0; $slot < $this->slots; ++$slot ) {
			$v = $this->numbers( $this->read( 'residual', $slot * 60, 60 ) );
			if ( $v[0] !== $repeats ) { throw new \RuntimeException( 'Snapshot finite host cell refused: repeat seam mismatch' ); }
			$spread = max( $spread, $v[2] - $v[1] );
		}
		return $spread;
	}
	public function __destruct() { foreach ( $this->files as $file ) { if ( is_resource( $file ) ) { fclose( $file ); } } }
}
