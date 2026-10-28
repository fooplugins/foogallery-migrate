<?php
/** Offline, hash-bound NDJSON transport. Never an observer or admission profile. */
namespace FooPlugins\FooGalleryMigrate;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class SnapshotReadTraceStream {
	const FORMAT = 'snapshot-read-ndjson-v1';
	const LINE_BYTES = 65536;
	private $file;
	private $hash;
	private $header;
	private $expected_hash;
	private $expected_events;
	private $events = 0;
	private $writing = false;
	private $closed = false;
	private function __construct() {}
	private static function fail() { throw new \RuntimeException( 'Snapshot trace stream refused: framing/hash/count/bound' ); }
	public static function writer( $path, $header ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Offline qualification-author path, not request input; NDJSON must stream without whole-trace buffering.
		$self = new self(); $self->writing = true; $self->file = fopen( $path, 'wb' );
		if ( ! is_resource( $self->file ) ) { self::fail(); }
		$self->hash = hash_init( 'sha256' );
		$self->write( array( 'format' => self::FORMAT, 'trace' => $header ) ); return $self;
	}
	public static function reader( $path, $hash, $events ) {
		if ( ! is_string( $hash ) || ! preg_match( '/\A[a-f0-9]{64}\z/D', $hash ) || ! is_int( $events ) || $events < 1 ) { self::fail(); }
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- qualify_files supplies an offline author path; read() bounds each line and next_event() verifies hash/count/EOF.
		$self = new self(); $self->file = fopen( $path, 'rb' );
		if ( ! is_resource( $self->file ) ) { self::fail(); }
		$self->hash = hash_init( 'sha256' ); $self->expected_hash = $hash; $self->expected_events = $events;
		$record = $self->read();
		if ( array_keys( $record ) !== array( 'format', 'trace' ) || $record['format'] !== self::FORMAT || ! is_array( $record['trace'] ) || isset( $record['trace']['events'] ) ) { self::fail(); }
		$self->header = $record['trace']; return $self;
	}
	private function write( $record ) {
		$line = json_encode( $record );
		if ( false === $line || strlen( $line ) + 1 > self::LINE_BYTES ) { self::fail(); }
		$line .= "\n";
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Writes one LINE_BYTES-bounded NDJSON record to the owned stream and rejects short writes.
		if ( fwrite( $this->file, $line ) !== strlen( $line ) ) { self::fail(); }
		hash_update( $this->hash, $line );
	}
	private function read() {
		$line = fgets( $this->file, self::LINE_BYTES + 1 );
		if ( false === $line || substr( $line, -1 ) !== "\n" ) { self::fail(); }
		hash_update( $this->hash, $line ); $record = json_decode( $line, true );
		if ( ! is_array( $record ) || JSON_ERROR_NONE !== json_last_error() ) { self::fail(); } return $record;
	}
	/** May be called directly by a finite real-seam observer; no graph buffering. */
	public function append( $event ) {
		if ( ! $this->writing || $this->closed || ! is_array( $event ) ) { self::fail(); }
		$this->write( array( 'event' => $event ) ); $this->events = StorageScaleAdmission::checked_add( $this->events, 1 );
	}
	public function finish() {
		if ( ! $this->writing || $this->closed ) { self::fail(); }
		$this->write( array( 'end' => $this->events ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Flush and close the owned native stream before returning its final hash; WP_Filesystem has no stream-handle equivalent.
		if ( ! fflush( $this->file ) || ! fclose( $this->file ) ) { self::fail(); }
		$this->closed = true; return hash_final( $this->hash );
	}
	public function header() { return $this->header; }
	public function next_event() {
		if ( $this->writing || $this->closed ) { self::fail(); }
		$record = $this->read();
		if ( array_keys( $record ) === array( 'event' ) && is_array( $record['event'] ) && $this->events < $this->expected_events ) { ++$this->events; return $record['event']; }
		if ( array_keys( $record ) !== array( 'end' ) || $record['end'] !== $this->events || $this->events !== $this->expected_events || false !== fgetc( $this->file ) || ! feof( $this->file ) || hash_final( $this->hash ) !== $this->expected_hash ) { self::fail(); }
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the owned reader handle after framing, count, EOF and hash verification.
		fclose( $this->file ); $this->closed = true; return null;
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close any still-open owned handle on refusal or early exit; no filename is deleted here.
	public function __destruct() { if ( is_resource( $this->file ) ) { fclose( $this->file ); } }
}
