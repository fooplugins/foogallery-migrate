<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\StorageAdmin;
use FooPlugins\FooGalleryMigrate\StorageAuthority;
use FooPlugins\FooGalleryMigrate\StorageCapacityPolicy;
use FooPlugins\FooGalleryMigrate\StorageCli;
use FooPlugins\FooGalleryMigrate\StorageConversion;
use FooPlugins\FooGalleryMigrate\StorageOperations;
use FooPlugins\FooGalleryMigrate\StoragePreflight;
use FooPlugins\FooGalleryMigrate\StorageProvisioning;
use PHPUnit\Framework\TestCase;

class StorageSingleSitePolicyTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['wpdb'] = new SingleSitePolicyNoSql();
		$GLOBALS['foogallery_migrate_test_blog_id'] = 1;
		$GLOBALS['foogallery_migrate_test_is_multisite'] = true;
		$GLOBALS['foogallery_migrate_test_capabilities'] = array( 'manage_options' => true, 'manage_network_options' => true );
	}
	protected function tearDown(): void {
		$GLOBALS['foogallery_migrate_test_is_multisite'] = false;
		unset( $GLOBALS['wpdb'], $GLOBALS['foogallery_migrate_test_blog_id'], $GLOBALS['foogallery_migrate_test_capabilities'], $GLOBALS['foogallery_migrate_test_nonce'] );
	}
	private function request( $operation, $target = 'table' ) {
		$purpose = 'recover' === $operation ? 'recover' : ( 'replace' === $operation ? 'option_reimport' : ( 'discard' === $operation ? 'discard' : ( 'provision' === $operation ? 'provision_schema' : ( 'table' === $target ? 'option_import' : 'table_export' ) ) ) );
		$confirmation = 'recover' === $operation ? 'recover-storage' : ( 'replace' === $operation ? 'replace-retained-with-table' : ( 'discard' === $operation ? 'discard-copy' : ( 'provision' === $operation ? 'provision-shared-schema' : 'switch-to-' . $target ) ) );
		$GLOBALS['foogallery_migrate_test_nonce'] = array( 'action' => 'foogallery_migrate_storage_' . $purpose . '_1', 'value' => 'valid', 'result' => 1 );
		return array( 'blog_id' => 1, 'scope' => 'provision' === $operation ? 'installation' : 'site', 'target' => $target, 'confirmation' => $confirmation, 'nonce' => 'valid' );
	}
	private function denied( $result, $suffix = 'single_site_required' ) {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'foogallery_migrate_storage_' . $suffix, $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['wpdb']->calls );
	}
	public function test_even_network_administrators_cannot_start_storage_operations_before_sql(): void {
		foreach ( array( 'provision', 'preflight', 'switch', 'recover', 'replace', 'discard' ) as $operation ) {
			$this->denied( ( new StorageOperations() )->dispatch( $operation, $this->request( $operation ) ) );
		}
		foreach ( array( 'switch', 'recover' ) as $operation ) {
			$this->denied( ( new StorageOperations() )->dispatch( $operation, $this->request( $operation, 'option' ) ) );
		}
	}
	public function test_direct_coordinator_preflight_provisioning_and_release_resolver_cannot_bypass_site_policy(): void {
		$r = $this->request( 'switch' );
		$this->denied( ( new StorageConversion() )->switch_backend( $r ) );
		$this->denied( ( new StoragePreflight() )->option_import( $r ) );
		$this->denied( StorageProvisioning::provision( $this->request( 'provision' ) ) );
		$this->denied( StorageCapacityPolicy::resolve() );
	}
	public function test_authentication_and_nonce_checks_are_not_weakened_by_site_policy(): void {
		$r = $this->request( 'switch' ); $r['nonce'] = 'wrong';
		$this->denied( ( new StorageOperations() )->dispatch( 'switch', $r ), 'invalid_nonce' );
		$GLOBALS['foogallery_migrate_test_capabilities']['manage_options'] = false;
		$this->denied( ( new StorageOperations() )->dispatch( 'switch', $r ), 'forbidden' );
	}
	public function test_multisite_ui_offers_no_table_or_recovery_mutations_even_with_network_capability(): void {
		foreach ( array( 'option', 'table' ) as $backend ) {
			foreach ( array( 'stable', 'copying' ) as $phase ) {
				$s = array( 'backend' => $backend, 'phase' => $phase, 'target' => 'table', 'retained' => false, 'discardable' => true, 'schema' => 'not ready', 'calibrated' => false, 'problem' => '', 'option_bytes' => '123', 'records' => null );
				$this->assertSame( array(), StorageAdmin::actions( $s ) );
				ob_start(); StorageAdmin::render( $s ); $html = ob_get_clean();
				$this->assertStringNotContainsString( '<form', $html );
				$this->assertStringContainsString( 'Multisite continues to use options', $html );
			}
		}
	}
	public function test_cli_schema_and_discard_share_the_public_authorization_purposes(): void {
		foreach ( array( 'provision' => 'provision-shared-schema', 'discard' => 'discard-copy' ) as $operation => $confirm ) {
			$this->request( $operation );
			$GLOBALS['foogallery_migrate_test_nonce']['value'] = wp_create_nonce( $GLOBALS['foogallery_migrate_test_nonce']['action'] );
			$this->denied( StorageCli::execute( $operation, array( 'target' => 'table', 'confirm' => $confirm ) ) );
		}
	}
	public function test_single_site_guard_is_not_an_option_backend_disable_switch(): void {
		$this->assertInstanceOf( \WP_Error::class, StorageAuthority::table_mode_error() );
		$GLOBALS['foogallery_migrate_test_is_multisite'] = false;
		$this->assertFalse( StorageAuthority::table_mode_error() );
		$this->assertTrue( StorageConversion::authorize_request( $this->request( 'switch' ) ) );
	}
	public function test_preexisting_multisite_table_authority_never_falls_back_to_options(): void {
		$db = new SingleSitePolicyTableEvidence(); $GLOBALS['wpdb'] = $db;
		$before = $db->raw;
		$result = StorageAuthority::runtime_record();
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'foogallery_migrate_storage_single_site_required', $result->get_error_code() );
		$this->assertCount( 1, $db->calls, 'Read only the authoritative control; never stale options or tables.' );
		$this->assertSame( $before, $db->raw, 'Keep existing recovery evidence unchanged.' );
	}
}
class SingleSitePolicyNoSql {
	public $prefix = 'wp_';
	public $options = 'wp_options';
	public $calls = array();
	public function __call( $name, $args ) { $this->calls[] = $name; throw new \RuntimeException( 'Denied storage operations must not inspect or mutate SQL.' ); }
}
class SingleSitePolicyTableEvidence extends SingleSitePolicyNoSql {
	public $last_error = '';
	public $raw;
	public function __construct() {
		$this->raw = json_encode( array( 'version' => 1, 'blog_id' => 1, 'backend' => 'table', 'epoch' => 1,
			'switch_id' => str_repeat( 'a', 32 ), 'from' => 'option', 'to' => 'table', 'phase' => 'stable',
			'table_id' => str_repeat( 'a', 32 ), 'source_digest' => str_repeat( 'b', 64 ), 'logical_digest' => str_repeat( 'c', 64 ),
			'schema' => 1, 'codec' => 1, 'created' => 1, 'updated' => 1 ) );
	}
	public function prepare( $sql, $args ) {
		if ( array( StorageAuthority::CONTROL_OPTION ) !== $args ) { throw new \RuntimeException( 'Unexpected evidence read.' ); }
		return $sql;
	}
	public function get_var( $sql ) { $this->calls[] = $sql; return 'v:' . $this->raw; }
}
