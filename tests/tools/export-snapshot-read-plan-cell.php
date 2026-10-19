<?php
/** SYNTHETIC_NOT_MEASURED exporter for real API/CLI floor regressions only. */
require getenv( 'QUAL_BOOTSTRAP' ) ? getenv( 'QUAL_BOOTSTRAP' ) : dirname( __DIR__ ) . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/SnapshotReadProfilePlanFloorsTest.php';
use FooPlugins\FooGalleryMigrate\Tests\SnapshotReadProfilePlanFloorsTest;
use FooPlugins\FooGalleryMigrate\SnapshotReadProfile;
if ( $argc !== 3 || ! isset( SnapshotReadProfilePlanFloorsTest::cases()[$argv[1]] ) || ! is_dir( $argv[2] ) ) { throw new RuntimeException( 'Expected named synthetic case and existing output directory.' ); }
list( $cell, $raw, $compiled ) = SnapshotReadProfilePlanFloorsTest::fixture( $argv[1] );
file_put_contents( $argv[2] . '/cell.json', json_encode( $cell, JSON_PRETTY_PRINT ) . "\n" );
foreach ( $raw as $i => $trace ) { file_put_contents( $argv[2] . '/trace-' . ( $i + 1 ) . '.json', $trace ); }
$result = array( 'SYNTHETIC_NOT_MEASURED' => true, 'actual_compiled_plan' => $compiled );
try {
	$profile = SnapshotReadProfile::qualify( $cell, $raw );
	$result['accepted'] = true; $result['supports_actual_compiled_plan'] = $profile->supports( $compiled, $cell['identity'] );
} catch ( Exception $e ) { $result['accepted'] = false; $result['reason'] = $e->getMessage(); }
print json_encode( $result, JSON_PRETTY_PRINT ) . "\n";
