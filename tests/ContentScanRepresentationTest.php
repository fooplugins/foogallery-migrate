<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\ContentScanRepository;
use FooPlugins\FooGalleryMigrate\LegacyCollectionEnvelope;
use FooPlugins\FooGalleryMigrate\LegacyStateCodec;
use PHPUnit\Framework\TestCase;

class ContentScanRepresentationTest extends TestCase {
    /** The real UI's former scalar exceeded the native token budget at 5K. */
    public function test_findings_are_records_not_head_metadata(): void {
        $items = array();
        for ( $i = 0; $i < 5016; ++$i ) { $items[$i] = array( 'post_id' => $i, 'plugin_name' => 'Unrelated' ); }
        $state = array( 'items' => $items, 'progress' => array( 'started' => true, 'scanned' => 5016 ) );
        $envelope = new LegacyCollectionEnvelope();
        $split = $envelope->split( ContentScanRepository::STATE_KEY, 'map', $state );
        $this->assertSame( 5016, $split['root']['root_count'] );
        $this->assertSame( $items, $split['items'] );
        $this->assertLessThan( 2048, strlen( ( new LegacyStateCodec() )->encode_collection_root( $split['root'] ) ) );
        $this->assertSame( $state, $envelope->rebuild( $split['root'], $items ) );
    }

    public function test_content_envelope_preserves_order_types_and_digest(): void {
        $state = array( 'progress' => array( 'cursor' => 9, 'unknown' => false ), 'items' => array(
            '09' => array( 'binary' => "\xff\x00", 'nested' => array( null, false, -0.0, 1.25 ) ),
            9 => null, 'tail' => false,
        ), 'unknown' => "\x00\xff" );
        $envelope = new LegacyCollectionEnvelope(); $codec = new LegacyStateCodec();
        $split = $envelope->split( ContentScanRepository::STATE_KEY, 'map', $state );
        $this->assertSame( $state, $envelope->rebuild( $split['root'], $split['items'] ) );
        $hash = hash_init( 'sha256' ); hash_update( $hash, "legacy-logical-v1\0" );
        $envelope->begin_digest( $hash, $split['root'] );
        foreach ( $split['items'] as $key => $value ) { $envelope->digest_item( $hash, $split['root'], $key, $value ); }
        $envelope->end_digest( $hash, $split['root'] );
        $this->assertSame( $codec->snapshot_digest( $state ), hash_final( $hash ) );
    }
}
