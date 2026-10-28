<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

/** Test doubles only: bind identifiers, retaining value placeholders/arguments. */
class IdentifierSqlFixture {
	public static function bind( $sql, &$args ) {
		if ( false === strpos( $sql, '%i' ) ) { return $sql; }
		$args = is_array( $args ) ? $args : array( $args );
		$values = array(); $index = 0;
		$sql = preg_replace_callback( '/%[idsf]/', function ( $match ) use ( &$index, &$values, $args ) {
			if ( ! array_key_exists( $index, $args ) ) { throw new \LogicException( 'Missing fixture SQL argument.' ); }
			$value = $args[$index++];
			if ( '%i' === $match[0] ) { return '`' . str_replace( '`', '``', $value ) . '`'; }
			$values[] = $value; return $match[0];
		}, $sql );
		if ( $index !== count( $args ) ) { throw new \LogicException( 'Extra fixture SQL argument.' ); }
		$args = $values;
		return $sql;
	}
}
