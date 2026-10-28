<?php
namespace FooPlugins\FooGalleryMigrate\Tests;
use PHPUnit\Framework\TestCase;
use FooPlugins\FooGalleryMigrate\StorageScalarObserver;
use FooPlugins\FooGalleryMigrate\SnapshotReadTraceStream;
final class SnapshotAcquisitionCollectorTest extends TestCase {
    private function set( $name, $value ) {
        $r = new \ReflectionProperty( StorageScalarObserver::class, $name );
        if ( PHP_VERSION_ID < 80100 ) { $r->setAccessible( true ); } $r->setValue( null, $value );
    }
    protected function tearDown(): void { $this->set( 'acquisition_stream', null ); $this->set( 'acquisition_sequence', 0 ); }
    public function test_inactive_path_does_not_validate_or_allocate_a_trace() {
        $this->set( 'acquisition_stream', null );
        StorageScalarObserver::acquisition( -1, new \stdClass(), -1 );
        $this->assertTrue( true );
    }
    public function test_scalar_events_stream_immediately_with_unchanged_guard_operands() {
        $path = tempnam( getenv( 'TMPDIR' ), 'seed-collector-' );
        try {
            $writer = SnapshotReadTraceStream::writer( $path, array( 'purpose' => 'UNIT_NOT_MEASURED' ) );
            $this->set( 'acquisition_stream', $writer ); $this->set( 'acquisition_sequence', 0 );
            StorageScalarObserver::acquisition_guard( 8192, 262144, 2097152, 0, 33554432, 64, 268435456 );
            StorageScalarObserver::acquisition( 4, 65536, 8 );
            $this->set( 'acquisition_stream', null ); $hash = $writer->finish();
            $reader = SnapshotReadTraceStream::reader( $path, $hash, 2 );
            $first = $reader->next_event(); $second = $reader->next_event();
            $this->assertSame( 30, $first['phase'] ); $this->assertSame( 8192, $first['forecast'] );
            $this->assertSame( 262144, $first['pending'] ); $this->assertSame( 33554432, $second['reserve'] );
            $this->assertSame( 64, $second['amplification'] ); $this->assertSame( 2, $second['sequence'] );
            $this->assertSame( 8, $second['depth'] ); $this->assertSame( 17, count( $second ) );
            foreach ( $second as $value ) { $this->assertTrue( is_int( $value ) || is_float( $value ) ); }
            $this->assertNull( $reader->next_event() );
        } finally { unlink( $path ); }
    }
    public function test_armed_collector_refuses_out_of_domain_scalars() {
        $path = tempnam( getenv( 'TMPDIR' ), 'seed-bound-' );
        try {
            $writer = SnapshotReadTraceStream::writer( $path, array( 'purpose' => 'UNIT_NOT_MEASURED' ) );
            $this->set( 'acquisition_stream', $writer );
            $this->expectException( \RuntimeException::class ); StorageScalarObserver::acquisition( 41 );
        } finally { $this->set( 'acquisition_stream', null ); $writer->finish(); unlink( $path ); }
    }
}
