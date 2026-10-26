<?php
namespace FooPlugins\FooGalleryMigrate\Tests;
use FooPlugins\FooGalleryMigrate\StorageAdmin;
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/support/EscapingRenderFixtures.php';

/**
 * Isolate renderer globals and DOM allocations from the suite's memory witnesses.
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class OutputBoundaryTest extends TestCase {
	/** @dataProvider render_cases */
	public function test_source_values_are_text_and_controls_survive_buffered_rendering( $kind, $table ): void {
		$text = '\"><img id="fgm-probe" src=x onerror="alert(1)"><script id="fgm-script">alert(1)</script> & 雪';
		$html = render_escaping_probe( $kind, $table, $text );
		$this->assertStringContainsString( esc_html( $text ), $html );
		$this->assertStringNotContainsString( '<img id="fgm-probe"', $html );
		$this->assertStringNotContainsString( '<script id="fgm-script"', $html );
		$dom = new \DOMDocument(); @$dom->loadHTML( $html ); $xpath = new \DOMXPath( $dom );
		$this->assertSame( 0, $xpath->query( '//*[@id="fgm-probe" or @id="fgm-script" or @onerror]' )->length );
		$this->assertGreaterThan( 0, $xpath->query( '//input[@type="checkbox"]' )->length );
		$this->assertGreaterThan( 0, $xpath->query( '//button' )->length );
		$nonce = 'galleries' === $kind ? 'foogallery_migrate' : ( 'albums' === $kind ? 'foogallery_album_migrate' : 'foogallery_content_migrate' );
		$this->assertSame( 1, $xpath->query( '//input[@type="hidden" and @name="' . $nonce . '" and string-length(@value)>0]' )->length );
	}
	public static function render_cases(): array {
		$cases = array(); foreach ( array( 'galleries', 'albums', 'content' ) as $kind ) {
			$cases[] = array( $kind, false ); $cases[] = array( $kind, true );
		} return $cases;
	}
	/** @dataProvider buffered_forms */
	public function test_stale_table_render_does_not_leak_partial_markup_or_close_the_callers_buffer( $kind ): void {
		$level = ob_get_level(); ob_start(); echo 'outer preserved';
		try { render_escaping_probe( $kind, true, 'Unpublished source value', true ); $this->fail( 'Expected stale-context refusal.' ); }
		catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( 'changed', $error->getMessage() );
			$this->assertSame( $level + 1, ob_get_level() );
			$this->assertSame( 'outer preserved', ob_get_contents() );
		} finally { while ( ob_get_level() > $level ) { ob_end_clean(); } }
	}
	public static function buffered_forms(): array { return array( array( 'galleries' ), array( 'albums' ), array( 'content' ) ); }

	/** @dataProvider diagnostic_helpers */
	public function test_exception_payload_is_preserved_as_data_and_escaped_once_at_the_html_boundary( $class, $method, $prefix ): void {
		$message = '<svg id="fgm-exception" onload="alert(1)">A & B</svg>';
		$reflection = new \ReflectionClass( $class ); $call = $reflection->getMethod( $method ); $call->setAccessible( true );
		try {
			$call->invokeArgs( $call->isStatic() ? null : $reflection->newInstanceWithoutConstructor(), 'required' === $method ? array( false, $message ) : array( $message ) );
			$this->fail( 'Expected diagnostic exception.' );
		} catch ( \RuntimeException $error ) {
			$this->assertStringContainsString( $prefix . $message, $error->getMessage() );
			$GLOBALS['foogallery_migrate_test_capabilities'] = array( 'manage_options' => true );
			$GLOBALS['foogallery_migrate_test_blog_id'] = 7;
			$status = array( 'backend' => 'unavailable', 'phase' => 'unavailable', 'target' => 'option', 'retained' => false,
				'discardable' => false, 'option_bytes' => '0', 'records' => null, 'schema' => 'not ready', 'problem' => $error->getMessage(), 'calibrated' => true );
			ob_start(); try { StorageAdmin::render_troubleshooting( $status ); $html = ob_get_clean(); }
			catch ( \Throwable $render_error ) { ob_end_clean(); throw $render_error; }
			$this->assertStringContainsString( esc_html( $error->getMessage() ), $html );
			$this->assertStringNotContainsString( '<svg id="fgm-exception"', $html );
			$this->assertStringNotContainsString( '&amp;lt;svg', $html );
		} finally { unset( $GLOBALS['foogallery_migrate_test_capabilities'], $GLOBALS['foogallery_migrate_test_blog_id'] ); }
	}
	public static function diagnostic_helpers(): array {
		$p = 'FooPlugins\\FooGalleryMigrate\\';
		return array(
			array( $p . 'SnapshotReadProfile', 'fail', 'Snapshot finite host cell refused: ' ),
			array( $p . 'TableSnapshot', 'fail', 'Legacy table snapshot: ' ),
			array( $p . 'LegacyStorageSchema', 'fail', 'Legacy storage schema: ' ),
			array( $p . 'LegacyTableRepository', 'fail', 'Legacy table repository: ' ),
			array( $p . 'LegacyRecordCodec', 'fail', 'Legacy storage record codec: ' ),
			array( $p . 'StorageCleanup', 'required', '' ),
		);
	}

	public function test_status_catches_raw_diagnostics_without_rendering_them(): void {
		$text = '<svg id="fgm-status" onload="alert(1)">status & detail</svg>';
		$GLOBALS['foogallery_migrate_test_capabilities'] = array( 'manage_options' => true );
		$GLOBALS['foogallery_migrate_test_blog_id'] = 7;
		$GLOBALS['wpdb'] = new OutputStatusFailureDb( $text );
		try {
			ob_start(); $status = StorageAdmin::status(); $unexpected = ob_get_clean();
			$this->assertSame( '', $unexpected ); $this->assertSame( $text, $status['problem'] );
			$GLOBALS['wpdb'] = new \stdClass();
			ob_start(); StorageAdmin::render_troubleshooting( $status ); $html = ob_get_clean();
			$this->assertStringContainsString( esc_html( $text ), $html );
			$this->assertStringNotContainsString( '<svg id="fgm-status"', $html );
		} finally { unset( $GLOBALS['wpdb'], $GLOBALS['foogallery_migrate_test_capabilities'], $GLOBALS['foogallery_migrate_test_blog_id'] ); }
	}
}

class OutputStatusFailureDb {
	public $prefix = 'wp_'; public $base_prefix = 'wp_'; public $options = 'wp_options';
	private $message;
	public function __construct( $message ) { $this->message = $message; }
	public function prepare( $sql, $args ) { return $sql; }
	public function get_var( $sql ) { throw new \RuntimeException( $this->message ); }
}
