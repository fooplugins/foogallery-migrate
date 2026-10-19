<?php
/** Complete logical overlap traversal; deliberately NOT own-output consumption. */
namespace FooPlugins\FooGalleryMigrate;
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class SnapshotReadPlan {
	const VERSION = 'authenticated-snapshot-read-v1';
	private $depth = 0;
	private $tokens = 0;
	private $string = 0;
	private $scalar = 0;
	private $containers = 0;
	private $resize = 0;
	private static function add( $a, $b ) { return StorageScaleAdmission::checked_add( $a, $b ); }
	private static function mul( $a, $b ) { return StorageScaleAdmission::checked_multiply( $a, $b ); }
	/** Includes wrapper/header fields in their original order, child containers,
	 * child keys and every unknown native value. No whole graph serialization. */
	private function walk( $value, $depth, $codec, $scale ) {
		if ( $depth > 64 ) { throw new \RuntimeException( 'Snapshot full logical overlap depth refused.' ); }
		if ( null !== $scale ) { $scale->tick(); }
		$this->depth = max( $this->depth, $depth ); $this->tokens = self::add( $this->tokens, 1 );
		if ( is_array( $value ) ) {
			$span = GenericRetainingModel::container( count( $value ) );
			$this->containers = self::add( $this->containers, $span ); $this->resize = max( $this->resize, $span );
			foreach ( $value as $k => $v ) { $this->walk( $k, $depth + 1, $codec, $scale ); $this->walk( $v, $depth + 1, $codec, $scale ); }
		} else {
			if ( is_string( $value ) ) { $this->string = max( $this->string, strlen( $value ) ); }
			// This is a scalar encode, not a subtree/record/snapshot encode.
			$this->scalar = max( $this->scalar, strlen( $codec->encode( $value ) ) );
		}
	}
	public static function compile( $value, $descriptor, $codec, $scale ) {
		$p = new self(); $p->walk( $value, 1, $codec, $scale );
		$parts = array(
			// Existing diagnostic coefficients are floors, not calibrated constants.
			'legacy_private_floor' => $descriptor['private'],
			'witness_construction_lifetime_lookup' => SnapshotPageWitness::workspace( $descriptor['records'] ),
			'whole_logical_digest_frames' => self::mul( 4096, self::add( $p->depth, 1 ) ),
			'whole_logical_scalar_hash_scratch' => self::mul( 16, max( $p->string, $p->scalar ) ),
			// Keep COMPLETE output container spans as COW, independently of own
			// events, and separately cover two caller-retained read outputs. Actual
			// source/previous output backing is never deducted by this model.
			'whole_children_wrapper_cow' => self::add( $p->containers, $p->resize ),
			'retained_two_outputs_cow' => self::mul( 2, $p->containers ),
		);
		$private = 0; foreach ( $parts as $bytes ) { $private = self::add( $private, $bytes ); }
		$full = $descriptor; $full['private'] = $private;
		return array( 'version' => self::VERSION, 'own' => $descriptor, 'full' => $full, 'parts' => $parts,
			'features' => array( 'records' => $descriptor['records'], 'logical_depth' => $p->depth, 'logical_tokens' => $p->tokens,
				'max_string' => $p->string, 'max_scalar_encoding' => $p->scalar, 'containers' => $p->containers, 'max_resize' => $p->resize ) );
	}
	/** Exact loaded runtime/INI/extension/allocator and compiler/product bytes.
	 * HOST evidence cannot authorize WordPress/native driver or old PHP. */
	public static function runtime_state() {
		$ini = ini_get_all( null, false ); ksort( $ini );
		return array( getmypid(), hash( 'sha256', json_encode( $ini ) ), get_loaded_extensions(), getenv( 'USE_ZEND_ALLOC' ) );
	}
	public static function runtime_binding() {
		$extensions = array(); foreach ( get_loaded_extensions() as $e ) { $extensions[$e] = phpversion( $e ); } ksort( $extensions );
		$ini = ini_get_all( null, false ); ksort( $ini );
		$files = array();
		foreach ( array( 'legacy-table-snapshot', 'legacy-snapshot-row-reader', 'legacy-record-codec', 'legacy-state-codec',
			'legacy-collection-envelope', 'snapshot-page-witness', 'snapshot-read-plan', 'snapshot-owned-event-plan',
			'snapshot-read-profile', 'snapshot-read-trace-stream', 'snapshot-read-workspace', 'snapshot-read-phase', 'generic-retaining-model', 'storage-scale-admission', 'storage-preflight' ) as $file ) {
			$files[$file] = hash_file( 'sha256', __DIR__ . '/class-' . $file . '.php' );
		}
		$encoder = null;
		if ( function_exists( 'wp_json_encode' ) ) { $f = new \ReflectionFunction( 'wp_json_encode' ); $file = $f->getFileName(); $encoder = array( $file ? hash_file( 'sha256', $file ) : null, $f->getStartLine(), $f->getEndLine() ); }
		return array( 'encoder' => $encoder, 'php' => PHP_VERSION, 'binary' => PHP_BINARY, 'binary_sha256' => hash_file( 'sha256', PHP_BINARY ),
			'ini_sha256' => hash( 'sha256', json_encode( $ini ) ), 'extensions_sha256' => hash( 'sha256', json_encode( $extensions ) ),
			'architecture' => php_uname( 'm' ), 'os' => PHP_OS, 'sapi' => PHP_SAPI, 'int_size' => PHP_INT_SIZE,
			'zts' => PHP_ZTS, 'debug' => PHP_DEBUG, 'allocator' => getenv( 'USE_ZEND_ALLOC' ) === '0' ? 'system' : 'zend',
			'compiler' => self::VERSION, 'product_compiler_sha256' => hash( 'sha256', json_encode( $files ) ) );
	}
}
