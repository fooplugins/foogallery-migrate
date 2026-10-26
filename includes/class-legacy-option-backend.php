<?php
/**
 * Physical option backend. No compaction, hydration, history policy or selection.
 * Option mode intentionally retains whole-option cost and WP concurrency semantics.
 *
 * @package FooPlugins\FooGalleryMigrate
 */
namespace FooPlugins\FooGalleryMigrate;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class LegacyOptionBackend {
	private $writer_admission;

	/** The production engine binds admission; injected repository seams stay inert. */
	public function __construct( $writer_admission = null ) {
		if ( null !== $writer_admission && ! is_callable( $writer_admission ) ) { throw new \RuntimeException( 'Invalid storage writer admission.' ); }
		$this->writer_admission = $writer_admission;
	}
	private function assert_writer() {
		if ( null !== $this->writer_admission && true !== call_user_func( $this->writer_admission ) ) { throw new \RuntimeException( 'Storage writer was not admitted.' ); }
	}
	/** Raw logical state; historical objects and unknown fields are untouched. */
	public function read_all() {
		$missing = new \stdClass();
		$data = get_option( FOOGALLERY_MIGRATE_OPTION_DATA, $missing );
		if ( $missing === $data ) { return array(); }
		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( 'Migration state options are unreadable. No state was changed.' );
		}
		return $data;
	}

	/** Presence is separate from false/null. Reads never rewrite old formats. */
	public function read( $key ) {
		$data = $this->read_all();
		$found = array_key_exists( $key, $data );
		return array( 'found' => $found, 'value' => $found ? $data[$key] : null );
	}

	public function exists() {
		return is_array( get_option( FOOGALLERY_MIGRATE_OPTION_DATA ) );
	}

	/** Raw map/list records only; compact wrapper interpretation stays above us. */
	public function roots( $state_key, $keys ) {
		if ( ! is_array( $keys ) || count( $keys ) > TableReader::MAX_ROWS ) {
			throw new \RuntimeException( 'Need at most 100 exact root keys.' );
		}
		$seen = array();
		foreach ( array_merge( array( $state_key ), $keys ) as $key ) {
			if ( ! is_int( $key ) && ! is_string( $key ) ) { throw new \RuntimeException( 'Invalid typed root key.' ); }
			$probe = array( $key => true );
			if ( array_keys( $probe ) !== array( $key ) ) { throw new \RuntimeException( 'Coercible root key.' ); }
		}
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $seen ) ) { throw new \RuntimeException( 'Duplicate root key.' ); }
			$seen[$key] = true;
		}
		if ( ! count( $keys ) ) { return array(); }
		$state = $this->read( $state_key );
		if ( ! $state['found'] || ! is_array( $state['value'] ) ) { throw new \RuntimeException( 'Missing or invalid collection.' ); }
		$result = array();
		foreach ( $keys as $key ) {
			$found = array_key_exists( $key, $state['value'] );
			$result[] = array( 'found' => $found, 'key' => $key, 'value' => $found ? $state['value'][$key] : null );
		}
		return $result;
	}

	/**
	 * A trusted synchronous repository/facade computes a complete raw change.
	 * It owns compaction and coupled revision policy; this backend commits once.
	 * Return true for a verified no-op, false to refuse, or an array to persist.
	 * No-op never creates an option. This is NOT a concurrency CAS.
	 */
	public function mutate( $change ) {
		if ( ! is_callable( $change ) ) { return false; }
		$this->assert_writer();
		try { $data = $this->read_all(); }
		catch ( \RuntimeException $error ) { return false; }
		$changed = call_user_func( $change, $data );
		$this->assert_writer();
		return true === $changed ? true : $this->replace_all( $changed );
	}

	/** Explicit full replacement (including clear), never a table hot-path API. */
	public function replace_all( $data ) {
		if ( ! is_array( $data ) ) { return false; }
		$this->assert_writer();
		update_option( FOOGALLERY_MIGRATE_OPTION_DATA, $data, false );
		$this->assert_writer();
		$saved = get_option( FOOGALLERY_MIGRATE_OPTION_DATA );
		// WP caches serialized bytes; reads can create new object identities.
		// Compare exact serialized state, not object identity or loose equality.
		return is_array( $saved ) && ( $data === $saved || serialize( $data ) === serialize( $saved ) );
	}
}
