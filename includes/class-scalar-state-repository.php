<?php
/** Option logical state adapter. Authority and codec policy stay above persistence. */
namespace FooPlugins\FooGalleryMigrate;
if ( ! defined( 'ABSPATH' ) ) { exit; }

class ScalarStateRepository {
	protected $backend;
	protected $settings;

	public function __construct( $backend, $settings ) {
		// Table dispatch is deliberately reserved for the serial integration lane.
		if ( ! $backend instanceof LegacyOptionBackend || ! $settings instanceof MigratorSettings ) {
			throw new \RuntimeException( 'Unsupported repository backend. No option fallback is permitted.' );
		}
		$this->backend = $backend;
		$this->settings = $settings;
	}

	protected function decode( $key, $value ) {
		if ( ! is_callable( array( $this->settings, 'hydrate_state_value' ) ) ) {
			throw new \RuntimeException( 'Logical state codec bridge is not installed.' );
		}
		return $this->settings->hydrate_state_value( $key, $value );
	}
	protected function encode( $key, $value ) {
		if ( ! is_callable( array( $this->settings, 'compact_state_value' ) ) ) {
			throw new \RuntimeException( 'Logical state codec bridge is not installed.' );
		}
		return $this->settings->compact_state_value( $key, $value );
	}
	public function get( $key, $default = false ) {
		self::exact_keys( array( $key ) );
		$row = $this->backend->read( $key );
		return $row['found'] ? $this->decode( $key, $row['value'] ) : $default;
	}
	public function read_many( $keys ) {
		// Reuse exact-key validation without interpreting collection wrappers.
		$rows = self::exact_keys( $keys );
		$data = $this->backend->read_all();
		foreach ( $rows as $key ) {
			$found = array_key_exists( $key, $data );
			$result[] = array( 'found' => $found, 'key' => $key, 'value' => $found ? $this->decode( $key, $data[$key] ) : null );
		}
		return isset( $result ) ? $result : array();
	}
	protected static function exact_keys( $keys ) {
		if ( ! is_array( $keys ) || count( $keys ) > 100 ) { throw new \RuntimeException( 'Need at most 100 exact keys.' ); }
		$seen = array();
		foreach ( $keys as $key ) {
			if ( ! is_int( $key ) && ! is_string( $key ) ) { throw new \RuntimeException( 'Invalid typed key.' ); }
			if ( array_keys( array( $key => true ) ) !== array( $key ) || array_key_exists( $key, $seen ) ) {
				throw new \RuntimeException( 'Coercible or duplicate key.' );
			}
			$seen[$key] = true;
		}
		return $keys;
	}
	public function set( $key, $value ) { self::exact_keys( array( $key ) ); return $this->mutate_many( array( $key => $value ) ); }
	public function mutate_many( $values ) {
		if ( ! is_array( $values ) || array_key_exists( MigratorSettings::KEY_MIGRATED, $values ) ||
			array_key_exists( MigratorSettings::KEY_MIGRATED_REVISION, $values ) ) { return false; }
		return $this->write_values( $values );
	}
	protected function write_values( $values ) {
		return $this->backend->mutate( function ( $data ) use ( $values ) {
			$previous = serialize( $data );
			foreach ( $values as $key => $value ) { $data[$key] = $this->encode( $key, $value ); }
			return serialize( $data ) === $previous ? true : $data;
		} );
	}
	public function delete( $key ) {
		self::exact_keys( array( $key ) );
		if ( MigratorSettings::KEY_MIGRATED === $key || MigratorSettings::KEY_MIGRATED_REVISION === $key ) { return false; }
		return $this->backend->mutate( function ( $data ) use ( $key ) {
			if ( ! array_key_exists( $key, $data ) ) { return true; }
			unset( $data[$key] ); return $data;
		} );
	}
}
