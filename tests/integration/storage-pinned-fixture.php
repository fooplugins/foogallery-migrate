<?php
/** Test-only interception at the pinned transport boundary; never shipped. */
if ( ! defined( 'FOOGM_STORAGE_TEST_DISPOSABLE' ) || true !== FOOGM_STORAGE_TEST_DISPOSABLE ) { throw new RuntimeException( 'Disposable fixture required.' ); }

class FgmFixturePinnedConnection extends \FooPlugins\FooGalleryMigrate\LegacyTransactionConnection {
	public function query( $sql ) { return parent::query( apply_filters( 'query', $sql ) ); }
	public function get_results( $sql, $format ) { return parent::get_results( apply_filters( 'query', $sql ), $format ); }
}

// Existing races must intercept the statement actually executed, not wpdb's
// now-unused path. No injection API or query hook is added to production code.
function fgm_fixture_pin_gate() {
	global $wpdb;
	$gate = \FooPlugins\FooGalleryMigrate\StorageMaintenanceGate::current();
	$property = new ReflectionProperty( $gate, 'connection' );
	if ( PHP_VERSION_ID < 80100 ) { $property->setAccessible( true ); }
	$property->setValue( $gate, new FgmFixturePinnedConnection( $wpdb ) );
	return $gate;
}
fgm_fixture_pin_gate();
