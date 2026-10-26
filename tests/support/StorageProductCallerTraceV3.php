<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

require_once __DIR__ . '/StorageProductCallerTraceCurrentBase.php';
require_once __DIR__ . '/StorageNativeScaleTrace.php';

/**
 * Current v3: one current fixed-helper/scanner v2 dependency spine.
 * V1 and its provenance stay historical. Inherited arithmetic is conditional
 * LP64/NTS source modelling, NOT newly executed PHP5 or conversion acceptance.
 */
class StorageProductCallerTraceV3 extends StorageProductCallerTraceCurrentBase {
	private $derived_paths;
	public function __construct( $version ) {
		parent::__construct( $version );
		self::assert_bindings( $this->bindings(), dirname( __DIR__, 2 ) );
		StorageNativeScaleTrace::assert_domain();
	}
	/** An imported witness must match bindings AND the freshly derived semantics. */
	public function assert_witness( $paths, $bindings, $root ) {
		self::assert_bindings( $bindings, $root );
		if ( $bindings !== $this->bindings() || $paths !== $this->paths() ) {
			throw new \RuntimeException( 'diagnostic_edge_witness' );
		}
	}
	public function bindings() {
		return array( 'product_files' => array(
			'includes/class-storage-conversion.php' => '37e354ed0eed8865ab4176b843e5385774e979980a60adbf0636da5e964590e8',
			'includes/class-storage-operations.php' => '2962b7774572ca2cbf180840c54b50c24be1a09722ff152bd3f0624bf84c85b0',
			'includes/class-storage-control.php' => 'aed056888cd5e6034a66be66188a238d718401999c1250adee683405e6f6d86c',
			'includes/class-legacy-option-decoder.php' => '78af4880b95627714d2f1dde7e907569a62a4518b5c70ba4015f4bd318d9119d',
			'includes/class-storage-scale-admission.php' => '2b29a6176242fe0bfa028a96e7d18478c45391e98bc9943717f906cd0ed19e3b',
			'includes/class-storage-preflight.php' => '7b54445198c0815f21efb3b75713d20b08814dc7fc65c7f4b9c36f297e194162',
			'includes/class-storage-memory-baseline.php' => '3c9f49d5d9bccf2c47eee9fda84e920d76847f2b9e41266b30957804dae4eae9',
			'includes/class-storage-scalar-observer.php' => '0bf1f98c0dfee9c091c3faf439006d3167ef5c40fbc4e6992b6b2d87524e6441' ),
			'original_material_members_manifest_sha256' => '2b304920b0de417aa34d8263629a24bec279052919b0fabb98da207686fa826a',
			'local_bindings' => ( new StorageScannerTraceWitnessV2( $this->source_version() ) )->bindings(),
			'diagnostic_version' => 'current-spine-caller-v3',
			'production_base_commit' => '11bd3b6a0b7ef4436f5a07dca83901e316c21062',
			'runtime_domain' => 'concrete-native-scale-null-clock-null-memory-disabled-observer',
			'scale_bindings' => StorageNativeScaleTrace::dependencies() );
	}
	/** A pinned fingerprint is mandatory; derivation must never auto-rebind it. */
	public static function assert_bindings( $bindings, $root ) {
		foreach ( $bindings['product_files'] as $file => $hash ) {
			if ( ! is_file( $root . '/' . $file ) || hash_file( 'sha256', $root . '/' . $file ) !== $hash ) {
				throw new \RuntimeException( 'diagnostic_binding:' . $file );
			}
		}
	}
	/** Inspect actual production tokens, not comments/strings resembling calls. */
	public static function calls( $class, $method, $callee ) {
		$r = new \ReflectionMethod( $class, $method );
		$lines = file( $r->getFileName() );
		$body = implode( '', array_slice( $lines, $r->getStartLine() - 1, $r->getEndLine() - $r->getStartLine() + 1 ) );
		$tokens = array(); $line = $r->getStartLine();
		foreach ( token_get_all( '<?php ' . $body ) as $token ) {
			$text = is_array( $token ) ? $token[1] : $token;
			if ( ! is_array( $token ) || ! in_array( $token[0], array( T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				$tokens[] = array( $text, $line );
			}
			$line += substr_count( $text, "\n" );
		}
		$out = array();
		foreach ( $tokens as $i => $token ) {
			if ( '->' !== $token[0] || ! isset( $tokens[$i + 2] ) || $callee !== $tokens[$i + 1][0] || '(' !== $tokens[$i + 2][0] ) { continue; }
			$args = array(); $arg = ''; $depth = 0;
			for ( $j = $i + 3; $j < count( $tokens ); ++$j ) {
				$t = $tokens[$j][0];
				if ( ')' === $t && 0 === $depth ) { if ( '' !== $arg ) { $args[] = $arg; } break; }
				if ( ',' === $t && 0 === $depth ) { $args[] = $arg; $arg = ''; continue; }
				if ( in_array( $t, array( '(', '[', '{' ), true ) ) { ++$depth; }
				if ( in_array( $t, array( ')', ']', '}' ), true ) ) { --$depth; }
				$arg .= $t;
			}
			if ( $j === count( $tokens ) ) { throw new \RuntimeException( 'diagnostic_unclosed_call' ); }
			$out[] = array( 'line' => $token[1], 'receiver' => $tokens[$i - 1][0], 'arguments' => $args );
		}
		return $out;
	}
	private function site( $class, $method, $callee, $arguments, $receiver, $index = 0, $count = 1 ) {
		$calls = self::calls( $class, $method, $callee );
		if ( count( $calls ) !== $count || ! isset( $calls[$index] ) ||
			$calls[$index]['arguments'] !== $arguments || $calls[$index]['receiver'] !== $receiver ) {
			throw new \RuntimeException( 'diagnostic_edge:' . $method . ':' . $callee );
		}
		return $calls[$index]['line'];
	}
	private function require_body( $class, $method, $expressions ) {
		$r = new \ReflectionMethod( $class, $method );
		$body = implode( '', array_slice( file( $r->getFileName() ), $r->getStartLine() - 1, $r->getEndLine() - $r->getStartLine() + 1 ) );
		foreach ( $expressions as $expression ) {
			if ( false === strpos( $body, $expression ) ) { throw new \RuntimeException( 'diagnostic_semantic:' . $method ); }
		}
	}
	public function paths() {
		self::assert_bindings( $this->bindings(), dirname( __DIR__, 2 ) );
		if ( null !== $this->derived_paths ) { return $this->derived_paths; }
		$c = 'FooPlugins\\FooGalleryMigrate\\StorageConversion';
		$o = 'FooPlugins\\FooGalleryMigrate\\StorageOperations';
		// Bind concrete receiver classes, five-key authorization, phase/direction
		// guards and the decoder object; matching a method name alone is unsafe.
		$this->require_body( $o, 'dispatch', array( '$manager = new StorageConversion( $policy, $memory, $scale )', "'replace' === \$operation", "'recover' === \$operation" ) );
		$this->require_body( $o, 'handle', array( '$receiver = new self()', '$request = wp_unslash( $_POST )', "unset( \$request['action'], \$request['operation'] )" ) );
		$this->require_body( $c, 'option_snapshot', array( '$this->option_decoder()->decode( $raw )' ) );
		$this->require_body( $c, 'option_decoder', array( "null === \$this->scale ? new LegacyOptionDecoder( \$this->policy['max_raw_bytes'] ) : \$this->scale->decoder( \$this->policy['max_raw_bytes'] )" ) );
		$this->require_body( $o, 'dispatch', array( '$policy, microtime( true ) + 10.0, null, null, $memory' ) );
		StorageNativeScaleTrace::assert_domain();
		$this->require_body( $c, 'authorize_request', array( 'count( $r ) !== 5', "( \$replacement && ( \$recovery || 'table' !== \$r['target'] ) )" ) );
		$this->require_body( $c, 'switch_storage', array( "'option' === \$from ? \$this->option_snapshot() : \$this->table_snapshot( true )", "'option' === \$from" ) );
		$this->require_body( $c, 'recover', array( "array( 'copying', 'verified' )", "'option' === \$r['from'] ? \$this->option_snapshot() : \$this->table_snapshot( false )", '$source = null;' ) );
		$this->require_body( $c, 'advance', array( "'copying' === \$r['phase']", "'verified' === \$r['phase']", "'flipped' === \$r['phase']", "'table' === \$r['to'] ? \$this->table_snapshot( false ) : \$this->option_snapshot()", "'table' === \$r['backend'] ? \$this->table_snapshot( false ) : \$this->option_snapshot()" ) );
		$paths = parent::paths();
		foreach ( $paths as &$path ) {
			foreach ( $path['edges'] as $i => &$edge ) {
				// Edges are in stack order: callee i is called by frame i.
				$frame = $path['frames'][$i]; $method = $frame['method'];
				$args = array(); $receiver = '$this'; $index = 0; $count = 1;
				if ( 'decode' === $edge['callee'] ) { $args = array( '$raw' ); $receiver = ')'; }
				elseif ( 'advance' === $method ) { $index = array_search( $path['phase'], array( 'copying', 'verified', 'flipped' ), true ); $count = 3; }
				elseif ( 'advance' === $edge['callee'] ) { $args = array( 'recover' === $method ? '$r' : '$record', '$source' ); }
				elseif ( 'switch_storage' === $edge['callee'] ) { $args = array( '$request', 'replace_retained' === $method ? 'true' : 'false' ); }
				elseif ( 'dispatch' === $method ) { $args = array( '$request' ); $receiver = '$manager'; }
				elseif ( 'handle' === $method ) { $args = array( '$operation', '$request' ); $receiver = '$receiver'; }
				$edge['line'] = $this->site( $frame['class'], $method, $edge['callee'], $args, $receiver, $index, $count );
				$edge['actual_arguments'] = $args;
				$edge['actual_receiver'] = $receiver;
				$target = new \ReflectionMethod( 'decode' === $edge['callee'] ? 'FooPlugins\\FooGalleryMigrate\\LegacyOptionDecoder' : ( 'dispatch' === $edge['callee'] ? $o : $c ), $edge['callee'] );
				if ( $target->getNumberOfParameters() !== count( $args ) || $target->returnsReference() ) { throw new \RuntimeException( 'diagnostic_arity' ); }
				foreach ( $target->getParameters() as $parameter ) { if ( $parameter->isPassedByReference() ) { throw new \RuntimeException( 'diagnostic_reference' ); } }
			}
			unset( $edge );
		}
		unset( $path );
		$this->derived_paths = $paths;
		return $paths;
	}
}
