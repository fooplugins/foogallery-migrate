<?php
/** Adapters ONLY for the separately named disposable native positive lane. */
require_once __DIR__ . '/storage-a7-options-harness.php';

function foogm_a7_positive_seed( $wpdb, $blog, $expected ) {
	list( $sql, $wp ) = \FooPlugins\FooGalleryMigrate\Tests\StorageA7OptionsHarness::wordpress_readers( $wpdb );
	$persisted = $sql( FOOGALLERY_MIGRATE_OPTION_DATA );
	if ( $persisted !== array( 'bytes' => $expected['raw_bytes'], 'sha256' => $expected['sha256'] ) ) { throw new RuntimeException( 'Native SQL binary seed mismatch.' ); }
	$value = $wp( FOOGALLERY_MIGRATE_OPTION_DATA );
	$logical = ( new \FooPlugins\FooGalleryMigrate\LegacyStateCodec() )->snapshot_digest( $value );
	if ( ! is_array( $value ) || $logical !== $expected['logical_sha256'] || hash( 'sha256', serialize( $value ) ) !== $expected['sha256'] ) { throw new RuntimeException( 'Native fresh WordPress seed mismatch.' ); }
	return array( 'format' => 'a7-options-seed-v1', 'corpus' => \FooPlugins\FooGalleryMigrate\Tests\StorageA7OptionsFixture::NAME,
		'stage' => 'seed', 'owner' => FOOGM_A7_OWNER, 'blog_id' => $blog, 'status' => 'measurement_only', 'contract_decision' => 'pending',
		'method_approved' => false, 'profile_approved' => false, 'outcome' => 'SUCCESS', 'first_failure' => null,
		'fixture' => $expected, 'persisted_sql' => $persisted, 'wp_logical_sha256' => $logical );
}

function foogm_a7_positive_run( $wpdb, $blog, $downstream, &$receipt ) {
	$seed = json_decode( file_get_contents( FOOGM_A7_SEED_RECEIPT ), true );
	$seed = isset( $seed['positive_seed'] ) ? $seed['positive_seed'] : null;
	list( $sql, $wp ) = \FooPlugins\FooGalleryMigrate\Tests\StorageA7OptionsHarness::wordpress_readers( $wpdb );
	$read_sql = function ( $name ) use ( $sql, &$receipt ) { $value = $sql( $name ); $receipt['fresh_read']['option'] = $name; $receipt['fresh_read']['sql'] = $value; return $value; };
	$read_wp = function ( $name ) use ( $wp, &$receipt ) {
		$value = $wp( $name );
		$receipt['fresh_read']['wp_logical_sha256'] = ( new \FooPlugins\FooGalleryMigrate\LegacyStateCodec() )->snapshot_digest( $value );
		$receipt['fresh_read']['wp_serialized_sha256'] = hash( 'sha256', serialize( $value ) );
		return $value;
	};
	return \FooPlugins\FooGalleryMigrate\Tests\StorageA7OptionsHarness::run( FOOGM_A7_STAGE, $seed, FOOGM_A7_OWNER, $blog, $read_sql, $read_wp, $downstream );
}

/** Separate bounded read-only evidence operation; never a product approval. */
function foogm_a7_positive_scale( $policy ) {
	return new \FooPlugins\FooGalleryMigrate\StorageScaleAdmission( array( 'version' => 1, 'max_tokens' => 262144,
		'max_rows' => 24576, 'max_bytes' => 16777216, 'max_depth' => 64, 'max_heads' => 100,
		'max_physical_rows_per_table' => 24576, 'max_physical_rows' => 49152, 'max_physical_bytes' => 33554432,
		'memory_ceiling_bytes' => 2147483647, 'max_seconds' => 10 ), $policy, microtime( true ) + 10.0 );
}

function foogm_a7_product_pins( $root ) {
	$root = realpath( $root ); $pins = array();
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		$relative = substr( $file->getPathname(), strlen( $root ) + 1 );
		if ( 0 === strpos( $relative, 'tests/' ) || 0 === strpos( $relative, '.git/' ) ) { continue; }
		if ( $file->isLink() ) { throw new RuntimeException( 'Product symlink refused.' ); }
		if ( $file->isFile() ) { $pins[$relative] = hash_file( 'sha256', $file->getPathname() ); }
	}
	ksort( $pins ); return $pins;
}
function foogm_a7_product_classes( $root ) {
	$classes = array(); $root = realpath( $root ) . '/';
	foreach ( array( 'StorageOperations', 'StorageConversion', 'LegacyStateCodec', 'TableSnapshot', 'StorageControl' ) as $short ) {
		if ( ! class_exists( 'FooPlugins\\FooGalleryMigrate\\' . $short ) ) { throw new RuntimeException( 'Required product class absent.' ); }
	}
	foreach ( get_declared_classes() as $name ) {
		if ( 0 !== strpos( $name, 'FooPlugins\\FooGalleryMigrate\\' ) || 0 === strpos( $name, 'FooPlugins\\FooGalleryMigrate\\Tests\\' ) ) { continue; }
		$file = realpath( ( new ReflectionClass( $name ) )->getFileName() );
		if ( 0 !== strpos( $file, $root ) ) { throw new RuntimeException( 'Product source fallback refused.' ); }
		$classes[$name] = array( 'file' => $file, 'sha256' => hash_file( 'sha256', $file ) );
	}
	ksort( $classes ); return $classes;
}
