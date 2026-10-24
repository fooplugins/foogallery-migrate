<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
\FooPlugins\FooGalleryMigrate\StorageAdmin::render_troubleshooting(
	isset( $foogallery_migrate_storage_status ) && is_array( $foogallery_migrate_storage_status ) ? $foogallery_migrate_storage_status : null
);
