<?php
/** Closed additive UTF-8 native bootstrap domain; NOT the original A7 corpus. */
namespace FooPlugins\FooGalleryMigrate\Tests;
final class SnapshotAcquisitionSeed {
    public static function cells() {
        $cells = array();
        foreach ( array( 'scalar', 'dense', 'escaped', 'depth', 'wide', 'mixed' ) as $shape ) {
            foreach ( array( 16, 64, 256 ) as $n ) { $cells[$shape . '-' . $n] = array( $shape, $n, 'training' ); }
        }
        $cells['UNUSED128'] = array( 'held128', 128, 'held_out' );
        $cells['UNUSED256'] = array( 'held256', 256, 'held_out' );
        return $cells;
    }
    public static function value( $cell ) {
        $cells = self::cells();
        if ( ! isset( $cells[$cell] ) ) { throw new \RuntimeException( 'Unknown closed seed cell.' ); }
        list( $shape, $n ) = $cells[$cell]; $roots = array();
        $mixed = in_array( $shape, array( 'mixed', 'held128', 'held256' ), true );
        $wide = 'wide' === $shape || $mixed;
        $groups = $wide ? 4 : 1;
        for ( $g = 0; $g < $groups; ++$g ) {
            $children = array();
            for ( $i = $g; $i < $n; $i += $groups ) {
                $image = array( 'object_type' => 'image', 'ID' => '00' . $i,
                    'source_url' => 'https://example.invalid/seed/' . $cell . '/' . $i . '.jpg', 'migrated' => false );
                if ( 'dense' === $shape || $mixed ) {
                    $image['unknown'] = array( '09' => null, 9 => false, 'unicode' => '雪🌍', 'float' => 0.5 );
                    for ( $k = 0; $k < 8; ++$k ) { $image['unknown']['key-' . $k] = $i + $k; }
                }
                if ( 'escaped' === $shape || $mixed ) { $image['escaped'] = str_repeat( "雪🌍 \" / \n\t", 8 + $i % 3 ); }
                if ( $mixed && 0 === $i % ( 'held128' === $shape ? 11 : ( 'held256' === $shape ? 13 : 17 ) ) ) {
                    $image['children'] = array( 'nested-09' => array( 'object_type' => 'image', 'unknown' => array( false, null, '雪' ) ) );
                }
                $children[0 === $i % 3 ? 'image-00' . $i : $i] = $image;
            }
            $roots['gallery-00' . $g] = array( 'object_type' => 'gallery', 'ID' => '00' . $g, 'title' => 'Seed 雪 ' . $cell,
                'children' => $children, 'unknown' => array( null, false, 'native-UTF8' ) );
        }
        $header = array( 'leaf' => '雪 \" / ' . $cell );
        if ( 'depth' === $shape || $mixed ) { for ( $d = 0; $d < 8; ++$d ) { $header = array( 'depth-' . $d => $header ); } }
        return array( '_foogallery_migrate_compact' => 1, 'type' => 'migratable', 'unknown-header' => $header, 'items' => $roots );
    }
}
