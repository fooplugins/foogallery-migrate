<?php
/** Execute the actual original20K snapshot integration test and print its HOST-only receipt. */
require dirname( __DIR__ ) . '/vendor/autoload.php';
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/StorageSnapshotScaleTest.php';

use FooPlugins\FooGalleryMigrate\Tests\StorageSnapshotScaleTest;

try {
	$test = new StorageSnapshotScaleTest( 'test_original20k_actual_snapshot_replace_and_readback' );
	$before = \PHPUnit\Framework\Assert::getCount();
	$test->runBare();
	$receipt = StorageSnapshotScaleTest::$receipt;
	$receipt['assertions'] = \PHPUnit\Framework\Assert::getCount() - $before;
	$receipt['result'] = 'HOST_SNAPSHOT_INTEGRATION_ONLY';
	print json_encode( $receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
} catch ( Throwable $error ) {
	fwrite( STDERR, get_class( $error ) . ': ' . $error->getMessage() . "\n" . $error->getTraceAsString() . "\n" );
	exit( 1 );
}
