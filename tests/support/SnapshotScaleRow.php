<?php
/** Lossless immutable host backing only; driver reads still materialize fresh arrays. */
namespace FooPlugins\FooGalleryMigrate\Tests;

final class SnapshotScaleRow implements \ArrayAccess, \IteratorAggregate {
	private $layout;
	private $values = array();

	/** Layout is shared by PHP COW; neither it nor the packed scalar values is exposed. */
	public function __construct( array $layout, array $values ) {
		$this->layout = $layout;
		// Copy values, not caller references (including reference-bearing argument arrays).
		foreach ( $values as $value ) { $this->values[] = $value; }
	}
	public function offsetExists( $offset ): bool {
		return isset( $this->layout[$offset] ) && isset( $this->values[$this->layout[$offset]] );
	}
	#[\ReturnTypeWillChange]
	public function offsetGet( $offset ) {
		return $this->values[$this->layout[$offset]];
	}
	public function offsetSet( $offset, $value ): void { throw new \LogicException( 'Host row is immutable.' ); }
	public function offsetUnset( $offset ): void { throw new \LogicException( 'Host row is immutable.' ); }
	public function getIterator(): \Traversable {
		foreach ( $this->layout as $column => $position ) { yield $column => $this->values[$position]; }
	}
}
