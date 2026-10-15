<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/StorageReceiverNewComposition.php';

/** Selected NEW-copy families only; neither an ingress cap nor admission. */
class StorageReceiverNewCompositionTest extends TestCase {
	public function test_input_length_not_normalized_length_owns_the_duplicate_buffer() {
		foreach ( array( '5.4.0', '5.6.20' ) as $version ) {
			$m = new StorageReceiverNewComposition( $version );
			$t = new StorageProductCallerTrace( $version );
			$input = str_repeat( '\\q', 65536 ); $output = str_repeat( 'q', 65536 );
			$r = $m->string_copy( $input, $output );
			$this->assertSame( strlen( $input ) + 1, $r['duplicate_request_bytes'] );
			$this->assertLessThanOrEqual( $r['input_copy_request_from_output_upper'], $r['duplicate_request_bytes'] );
			$this->assertSame( $t->nominal( strlen( $input ) + 1 ), $r['duplicate_nominal'] );
			$this->assertGreaterThan( $t->nominal( strlen( $output ) + 1 ), $r['duplicate_nominal'] );
			$this->assertNull( $r['original_backing_extent'] );
			$this->assertFalse( $r['original_freed'] );
		}
	}
	public function test_empty_trailing_slash_and_nul_relations_include_the_terminator() {
		$m = new StorageReceiverNewComposition( '5.4.0' );
		foreach ( array( '', '\\', '\\0', '\\a\\', "a\0b", '\\\\' ) as $input ) {
			$r = $m->string_copy( $input, stripslashes( $input ) );
			$this->assertSame( strlen( $input ) + 1, $r['duplicate_request_bytes'] );
			$this->assertLessThanOrEqual( 2 * strlen( stripslashes( $input ) ) + 1, strlen( $input ) );
			$this->assertGreaterThanOrEqual( 32, $r['duplicate_nominal'] );
		}
	}
	public function test_shallow_cow_uses_new_current_elements_not_original_capacity() {
		foreach ( array( '5.4.0', '5.6.20' ) as $version ) {
			$m = new StorageReceiverNewComposition( $version );
			$t = new StorageProductCallerTrace( $version );
			$keys = array( 'action', 'operation', 'blog_id', 'scope', 'target', 'confirmation', 'nonce' );
			$r = $m->shallow_copy( $keys );
			$this->assertSame( $t->nominal( 32 ) + $t->shallow_array( $keys ), $r['copy_nominal'] );
			$this->assertSame( 7, $r['child_addrefs'] );
			$this->assertSame( 8, $r['new_vector_capacity'] );
			$this->assertNull( $r['original_capacity'] );
			$this->assertFalse( $r['deep_child_copy'] );
			$numeric = $m->shallow_copy( array( 0, 1 ) );
			$this->assertLessThan( $m->shallow_copy( array( 'long-key-0', 'long-key-1' ) )['copy_nominal'], $numeric['copy_nominal'] );
			$this->assertSame( 0, $m->shallow_copy( array() )['child_addrefs'] );
		}
	}
	public function test_composition_keeps_unproved_families_null_not_zero_or_a_policy() {
		$m = new StorageReceiverNewComposition( '5.6.20' );
		$r = $m->compose( array( array( '\\q', 'q' ), array( '\\q', 'q' ) ), array( array( 'nonce' ), array( 0 ) ) );
		$this->assertSame( 2, $r['string_visits'] ); // Equal leaves are not deduplicated.
		$this->assertSame( 2, $r['array_visits'] );
		$this->assertGreaterThan( 0, $r['selected_copy_family_nominal_sum'] );
		foreach ( array( 'callback_vm_bytes', 'failure_cleanup_overlap_bytes', 'whole_operation_new_upper', 'retained_arena_upper', 'rejected_graph_upper' ) as $field ) {
			$this->assertNull( $r[$field] );
		}
		$this->assertTrue( $r['originals_retained_without_free_credit'] );
		$this->assertFalse( $r['safe_admission_approved'] );
		$this->assertFalse( $r['complete_traversal_proven'] );
		$this->assertSame( ( new StorageProductArgumentRetention( '5.6.20' ) )->bindings(), $m->bindings()['retained_helpers'] );
		foreach ( $m->bindings()['retained_helpers']['product_files'] as $file => $hash ) {
			$this->assertSame( $hash, hash_file( 'sha256', dirname( __DIR__ ) . '/' . $file ) );
		}
	}
	public function test_incorrect_normalization_is_not_an_observed_transform() {
		$this->expectException( \InvalidArgumentException::class );
		( new StorageReceiverNewComposition( '5.4.0' ) )->string_copy( '\\q', 'different' );
	}
}
