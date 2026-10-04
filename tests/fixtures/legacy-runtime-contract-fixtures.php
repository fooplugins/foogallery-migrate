<?php
/** Pure shared fixture factory; no global WordPress stubs. */
namespace FooPlugins\FooGalleryMigrate\Tests\Fixtures;

use FooPlugins\FooGalleryMigrate\Objects\Image;

function legacy_runtime_contract_fixture( $binary = true ) {
	$image = new Image();
	$image->source_url = 'https://example.test/MixedCase-' . ( $binary ? "\xff" : '雪' ) . '.jpg';
	$image->ID = '009';
	$image->migrated = true;
	$image->migrated_id = 71;
	$image->migration_status = 'completed';
	$image->settings = array( '09' => false, 9 => null );
	$historical = clone $image;
	$historical->settings['unknown'] = $binary ? "\xff\x00" : '雪';
	$historical->children = array( '09' => clone $image, 9 => null );
	return array(
		'image' => $image,
		'historical' => $historical,
		'logical' => array(
			'plugins' => array(), 'galleries' => array( $historical ), 'galleries-state' => false,
			'albums' => array(), 'albums-state' => null,
			'migrated' => array( $image->unique_identifier() => $historical ), 'migrated-revision' => 4,
			'image-tag-sync' => array( 'items' => array( array( 'attachment_id' => 71, 'tags' => array( '雪' ) ) ), 'errors' => array(), 'processed' => 0, 'total' => 1 ),
			'wordpress-core-mode' => 'media',
			'block-shortcode_scan_state' => array( 'items' => array( '09' => array( 'post_id' => '09', 'original_content' => '[gallery]' ), 9 => array( 'post_id' => 9, 'original_content' => '[gallery]' ) ), 'progress' => array( 'cursor' => 9 ) ),
			'block-shortcode' => array( '09' => false, 9 => null ), 'block-shortcode_scan_progress' => null,
			'unknown' => $binary ? "\xff\x00" : '雪', 'stored-false' => false, 'stored-null' => null,
		),
	);
}
