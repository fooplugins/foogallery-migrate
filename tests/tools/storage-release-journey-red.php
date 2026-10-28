<?php
/** Host-only positive barrier. WP functions are doubles: NOT a native/public-site receipt. */
require dirname( __DIR__ ) . '/bootstrap.php';
require dirname( __DIR__ ) . '/support/StorageReleaseJourney.php';
$GLOBALS['wp_version'] = 'host-test-only';
$GLOBALS['foogallery_migrate_test_blog_id'] = 1;
$GLOBALS['foogallery_migrate_test_capabilities'] = array( 'manage_options' => true );
$action = 'foogallery_migrate_storage_option_import_1';
$GLOBALS['foogallery_migrate_test_nonce'] = array( 'action' => $action, 'value' => wp_create_nonce( $action ), 'result' => 1 );
class JourneyNoSqlDb {
	public $prefix = 'wp_'; public $base_prefix = 'wp_'; public $calls = 0;
	public function __call( $name, $args ) { ++$this->calls; throw new LogicException( 'Unexpected storage SQL before release-policy resolution.' ); }
}
$GLOBALS['wpdb'] = new JourneyNoSqlDb();
try {
	\FooPlugins\FooGalleryMigrate\Tests\StorageReleaseJourney::preflight();
	echo "Public positive barrier passed on host doubles ONLY; no native/customer success proved.\n";
} catch ( RuntimeException $error ) {
	echo json_encode( array( 'status' => 'RED', 'scope' => 'host WP doubles calling REAL shipped resolver, NOT native floor or customer proof',
		'phase' => 'preflight-release-policy-resolution', 'message' => $error->getMessage(), 'sql_calls' => $GLOBALS['wpdb']->calls,
		'fixture_targets' => array( 'native_conversion_images' => 20000, 'actual_aigpl_images' => 10000 ), 'feature_accepted' => false ) ) . "\n";
	exit( 1 );
}
