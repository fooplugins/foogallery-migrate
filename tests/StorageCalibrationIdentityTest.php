<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\StorageCalibration;
use PHPUnit\Framework\TestCase;

class StorageCalibrationIdentityTest extends TestCase {
	public function test_distinct_malformed_raw_identities_fail_closed_without_repair(): void {
		$previous = getenv( 'ZEND_MM_SEG_SIZE' );
		$wp = isset( $GLOBALS['wp_version'] ) ? $GLOBALS['wp_version'] : null;
		$GLOBALS['wp_version'] = '6.9-test';
		try {
			foreach ( array( "262144\xff", "524288\xff" ) as $raw ) {
				putenv( 'ZEND_MM_SEG_SIZE=' . $raw );
				$this->assertSame( $raw, StorageCalibration::identity()['allocator_environment']['ZEND_MM_SEG_SIZE'] );
				$this->assertFalse( json_encode( StorageCalibration::identity() ) );
				$this->assertNull( StorageCalibration::fingerprint() );
			}
		} finally {
			putenv( false === $previous ? 'ZEND_MM_SEG_SIZE' : 'ZEND_MM_SEG_SIZE=' . $previous );
			if ( null === $wp ) { unset( $GLOBALS['wp_version'] ); } else { $GLOBALS['wp_version'] = $wp; }
		}
	}

	public function test_identity_binds_decoder_transitive_paths_and_allocator_environment(): void {
		$previous = isset( $GLOBALS['wp_version'] ) ? $GLOBALS['wp_version'] : null;
		$GLOBALS['wp_version'] = '6.9-test';
		try {
			$identity = StorageCalibration::identity();
			foreach ( array( 'legacy-option-decoder', 'legacy-collection-envelope', 'migrator-settings', 'content-scan-repository', 'image-tag-queue-repository' ) as $class ) {
				$this->assertSame( hash_file( 'sha256', dirname( __DIR__ ) . '/includes/class-' . $class . '.php' ), $identity[$class] );
			}
			foreach ( array( 'USE_ZEND_ALLOC', 'ZEND_MM_SEG_SIZE', 'ZEND_MM_MEM_TYPE', 'ZEND_MM_COMPACT' ) as $name ) {
				$this->assertSame( getenv( $name ), $identity['allocator_environment'][$name] );
			}
			$this->assertSame( hash( 'sha256', json_encode( $identity ) ), StorageCalibration::fingerprint() );
			$this->assertInstanceOf( \WP_Error::class, StorageCalibration::policy( null ) );
		} finally {
			if ( null === $previous ) { unset( $GLOBALS['wp_version'] ); } else { $GLOBALS['wp_version'] = $previous; }
		}
	}
}
