<?php
namespace FooPlugins\FooGalleryMigrate\Tests;

use FooPlugins\FooGalleryMigrate\StorageAdmin;
use FooPlugins\FooGalleryMigrate\StorageCleanup;
use FooPlugins\FooGalleryMigrate\StoragePreflight;
use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ReleaseHardeningTest extends TestCase {
    protected function setUp(): void {
        require __DIR__ . '/fixtures/release-hardening-translations.php';
        $GLOBALS['foogallery_migrate_test_capabilities'] = array('manage_options'=>false);
        $GLOBALS['foogallery_migrate_test_blog_id'] = 1;
        $GLOBALS['foogallery_migrate_test_is_multisite'] = false;
        $GLOBALS['wpdb'] = new \stdClass();
    }

    public function test_core_source_refuses_direct_execution_but_loads_in_wordpress(): void {
        $output = array(); $exit = null;
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -d error_reporting=-1 ' .
            escapeshellarg(dirname(__DIR__) . '/includes/plugins/class-word-press-core.php') . ' 2>&1', $output, $exit);
        $this->assertSame(0, $exit, implode("\n", $output));
        $this->assertSame(array(), $output, 'Direct execution must exit without class-loading errors or paths.');
        $this->assertTrue(class_exists('FooPlugins\\FooGalleryMigrate\\Plugins\\WordPressCore'));
    }

    public static function admin_methods(): array {
        return array(array('status'), array('render'), array('render_troubleshooting'));
    }

    /** @dataProvider admin_methods */
    public function test_unauthorized_storage_messages_escape_after_translation($method): void {
        try { call_user_func(array(StorageAdmin::class, $method)); $this->fail('Authorization must abort.'); }
        catch (\FooGalleryMigrateTestDie $error) {
            $expected = \esc_html(\FooPlugins\FooGalleryMigrate\__('Storage access denied.', 'foogallery-migrate'));
            $this->assertSame($expected, $error->getMessage());
            $this->assertStringNotContainsString('<em', $error->getMessage());
        }
    }

    public static function uninstall_failures(): array {
        return array('exception'=>array(false), 'throwable'=>array(true));
    }

    /** @dataProvider uninstall_failures */
    public function test_uninstall_refusal_escapes_translation_without_removing_data($throwable): void {
        define('WP_UNINSTALL_PLUGIN', 'foogallery-migrate/migrate.php');
        if ($throwable) {
            // Deliberately malformed context raises TypeError in PHP 7+ before SQL.
            $GLOBALS['wpdb'] = (object)array('prefix'=>array(), 'base_prefix'=>'wp_', 'options'=>'wp_options');
        }
        $GLOBALS['foogallery_migrate_test_options'] = array('protected'=>'unchanged');
        try { StorageCleanup::uninstall(); $this->fail('Uninstall must abort on unknown ownership.'); }
        catch (\FooGalleryMigrateTestDie $error) {
            $message = 'FooGallery Migrate cleanup could not complete. Plugin files were retained.';
            $this->assertSame(\esc_html(\FooPlugins\FooGalleryMigrate\__($message, 'foogallery-migrate')), $error->getMessage());
            $this->assertSame(array('protected'=>'unchanged'), $GLOBALS['foogallery_migrate_test_options']);
        }
    }

    public static function preflight_errors(): array {
        return array(
            array('forbidden', 'You are not authorized to inspect migration storage.'),
            array('invalid_request', 'Invalid migration storage request.'),
            array('invalid_nonce', 'The migration storage request has expired or is invalid.'),
            array('size_unavailable', 'Migration storage size could not be safely inspected.'),
            array('memory_unknown', 'The effective migration storage decode budget could not be determined.'),
            array('decode_policy_required', 'A benchmarked migration storage decode policy is required. No conversion was started.'),
            array('decode_refused', 'Migration storage is too large for the available conversion budget. Retain the database; use an administrator-assisted, higher-memory conversion path. No state was changed.')
        );
    }

    /** @dataProvider preflight_errors */
    public function test_preflight_errors_are_translated_extractable_and_keep_codes($code, $message): void {
        $preflight = new StoragePreflight($GLOBALS['wpdb']);
        $policy = array('max_raw_bytes'=>1, 'amplification'=>1, 'reserve_bytes'=>1);
        $GLOBALS['foogallery_migrate_test_capabilities']['manage_options'] = $code !== 'forbidden';
        if ($code === 'invalid_nonce') {
            $GLOBALS['foogallery_migrate_test_nonce'] = array('action'=>'foogallery_migrate_storage_option_import_1', 'value'=>'old', 'result'=>0);
            $error = $preflight->option_import(array('blog_id'=>1, 'scope'=>'site', 'target'=>'table', 'confirmation'=>'switch-to-table', 'nonce'=>'old'));
        } elseif ($code === 'size_unavailable') {
            $method = new \ReflectionMethod(StoragePreflight::class, 'option_size'); $method->setAccessible(true);
            $error = $method->invoke($preflight);
        } elseif ($code === 'memory_unknown') {
            $error = StoragePreflight::decode_budget(0, 'unknown', 0, $policy);
        } elseif ($code === 'decode_policy_required') {
            $error = StoragePreflight::decode_budget(0, '256M', 0, null);
        } elseif ($code === 'decode_refused') {
            $error = StoragePreflight::decode_budget(2, '256M', 0, $policy);
        } else { $error = $preflight->option_import(array()); }
        $this->assertInstanceOf(\WP_Error::class, $error);
        $this->assertSame('foogallery_migrate_storage_' . $code, $error->get_error_code());
        $this->assertSame(\FooPlugins\FooGalleryMigrate\__($message, 'foogallery-migrate'), $error->get_error_message());
        $this->assertStringContainsString('msgid "' . $message . '"', file_get_contents(dirname(__DIR__) . '/languages/foogallery-migrate.pot'), 'The real build extractor must discover each preflight error literal.');
    }

    public function test_all_storage_fatal_sinks_use_escaped_translations(): void {
        $count = 0;
        foreach (array('class-storage-admin.php', 'class-storage-cleanup.php') as $file) {
            $source = file_get_contents(dirname(__DIR__) . '/includes/' . $file);
            $this->assertDoesNotMatchRegularExpression('/wp_die\(\s*__\(/', $source);
            $count += preg_match_all('/wp_die\(\s*esc_html__\(/', $source);
        }
        $this->assertSame(6, $count);
    }
}
