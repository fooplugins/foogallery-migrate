<?php
/** Storage-only WP-CLI facade over the same current-site public receiver. */
namespace FooPlugins\FooGalleryMigrate;
if ( ! defined( 'ABSPATH' ) ) { exit; }

class StorageCli {
	public static function register() {
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			\WP_CLI::add_command( 'foogallery-migrate storage', array( __CLASS__, 'command' ) );
		}
	}

	/**
	 * Inspect or explicitly change only migration storage for the selected site.
	 *
	 * Requires --user with manage_options; use WP-CLI's --url to select a site.
	 * Does not migrate output, delete history or bypass calibrated admission.
	 *
	 * ## OPTIONS
	 *
	 * <operation>
	 * : status, provision, preflight, switch, recover, replace or discard.
	 *
	 * [--target=<backend>]
	 * : table or option. Required except for status; preflight requires table.
	 *
	 * [--confirm=<intent>]
	 * : provision-shared-schema, switch-to-table, switch-to-option, recover-storage, replace-retained-with-table or discard-copy.
	 *
	 * ## EXAMPLES
	 *
	 *     wp --user=admin foogallery-migrate storage status
	 *     wp --user=admin foogallery-migrate storage preflight --target=table --confirm=switch-to-table
	 *     wp --user=admin foogallery-migrate storage recover --target=table --confirm=recover-storage
	 */
	public static function command( $args, $assoc ) {
		$result = count( $args ) === 1 ? self::execute( $args[0], $assoc ) : self::invalid();
		if ( is_wp_error( $result ) ) { \WP_CLI::error( $result->get_error_code() . ': ' . $result->get_error_message() ); return; }
		\WP_CLI::line( wp_json_encode( $result ) );
	}

	public static function execute( $operation, $flags ) {
		if ( ! current_user_can( 'manage_options' ) ) { return new \WP_Error( 'foogallery_migrate_storage_forbidden', __( 'Select an authorized site administrator with --user.', 'foogallery-migrate' ) ); }
		if ( ! is_array( $flags ) || ! in_array( $operation, array( 'status', 'provision', 'preflight', 'switch', 'recover', 'replace', 'discard' ), true ) ) { return self::invalid(); }
		if ( 'status' === $operation ) { return count( $flags ) ? self::invalid() : StorageAdmin::status(); }
		if ( 2 !== count( $flags ) || ! isset( $flags['target'], $flags['confirm'] ) || ! in_array( $flags['target'], array( 'option', 'table' ), true ) ) { return self::invalid(); }
		$target = $flags['target'];
		if ( in_array( $operation, array( 'provision', 'preflight', 'replace', 'discard' ), true ) && 'table' !== $target ) { return self::invalid(); }
		$confirmation = 'recover' === $operation ? 'recover-storage' : ( 'replace' === $operation ? 'replace-retained-with-table' : ( 'provision' === $operation ? 'provision-shared-schema' : ( 'discard' === $operation ? 'discard-copy' : 'switch-to-' . $target ) ) );
		if ( $confirmation !== $flags['confirm'] ) { return self::invalid(); }
		$purpose = 'recover' === $operation ? 'recover' : ( 'replace' === $operation ? 'option_reimport' : ( 'provision' === $operation ? 'provision_schema' : ( 'discard' === $operation ? 'discard' : ( 'table' === $target ? 'option_import' : 'table_export' ) ) ) );
		$blog = get_current_blog_id();
		$request = array( 'blog_id' => $blog, 'scope' => 'provision' === $operation ? 'installation' : 'site', 'target' => $target, 'confirmation' => $confirmation, 'nonce' => wp_create_nonce( 'foogallery_migrate_storage_' . $purpose . '_' . $blog ) );
		return ( new StorageOperations() )->dispatch( $operation, $request );
	}

	private static function invalid() { return new \WP_Error( 'foogallery_migrate_storage_invalid_request', __( 'Use an exact storage operation, target and confirmation. Other flags are not admitted.', 'foogallery-migrate' ) ); }
}
