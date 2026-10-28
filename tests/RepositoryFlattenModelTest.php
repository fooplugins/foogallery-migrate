<?php
namespace FooPlugins\FooGalleryMigrate\Tests;
use FooPlugins\FooGalleryMigrate\LegacyRecordCodec;
use FooPlugins\FooGalleryMigrate\RepositoryFlattenModel;
use PHPUnit\Framework\TestCase;

class RepositoryFlattenModelTest extends TestCase {
	public function test_descriptor_max_scalar_includes_nested_keys_and_mutable_without_total_source_charge(): void {
		$record = array( 'long-key' => array( 'nested' => 'x' ), 'progress' => array( 'v' => str_repeat( 'z', 37 ) ), 'children' => array( 'child' => 'short' ) );
		$d = ( new LegacyRecordCodec() )->describe_tree( 'root', $record, 'repository-sink' );
		$this->assertSame( 37, $d['logical_max_string_bytes'] );
		$this->assertGreaterThan( 37, $d['logical_string_bytes'] );
		$this->assertNull( $d['additional_allocation_bytes'] );
		$this->assertSame( 0, $d['retained_output_rows'] );
		$this->assertSame( 0, ( new LegacyRecordCodec() )->describe_tree( 'root', null, 'repository-sink' )['logical_max_string_bytes'] );
	}
	public function test_long_logical_key_is_in_scalar_max(): void {
		$d = ( new LegacyRecordCodec() )->describe_tree( 'root', array( str_repeat( 'k', 43 ) => null ), 'repository-sink' );
		$this->assertSame( 43, $d['logical_max_string_bytes'] );
	}
	public function test_model_never_certifies_even_the_wordpress_test_json_shim(): void {
		$this->expectException( \RuntimeException::class );
		new RepositoryFlattenModel( RepositoryFlattenModel::PROFILE );
	}
}
