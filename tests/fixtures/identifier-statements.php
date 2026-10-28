<?php
// Characterized before bucket 3: only {{table}} becomes a native %i binding.
// Each entry is [source file, old SQL template, table suffix, value arguments].
$snapshot = 'class-table-snapshot.php';
$lock = 'SELECT option_name FROM `{{table}}` WHERE option_name IN (%s,%s,%s) ORDER BY option_name FOR UPDATE';
$hash = 'SELECT SHA2(option_value,256) FROM `{{table}}` WHERE option_name=%s LIMIT 1';
$count = 'SELECT COUNT(*) AS n FROM `{{table}}` WHERE blog_id=%d';
$markers = array( 'foogallery_migrate_storage_control', 'foogallery_migrate_storage_maintenance', 'foogallery-migrate-data' );
return array(
	array( 'class-storage-admin.php', 'SELECT 1 AS owned FROM `{{table}}` WHERE option_name=%s AND BINARY option_value=BINARY %s', 'options', array( 'foogallery_migrate_storage_schema_version', '1' ) ),
	array( 'class-storage-admin.php', 'SELECT COUNT(*) FROM `{{table}}` WHERE blog_id=%d', 'foogallery_migrate_records', array( 7 ) ),
	array( 'class-storage-authority.php', 'SELECT 1 AS present FROM `{{table}}` WHERE blog_id = %d LIMIT 1', 'foogallery_migrate_state', array( 7 ) ),
	array( $snapshot, $lock, 'options', $markers ),
	array( $snapshot, $hash, 'options', array( 'foogallery-migrate-data' ) ),
	array( $snapshot, $count, 'foogallery_migrate_records', array( 7 ) ),
	array( $snapshot, $count, 'foogallery_migrate_records', array( 7 ) ),
	array( $snapshot, $lock, 'options', $markers ),
	array( $snapshot, $hash, 'options', array( 'foogallery-migrate-data' ) ),
	array( $snapshot, $count, 'foogallery_migrate_records', array( 7 ) ),
	array( $snapshot, 'DELETE FROM `{{table}}` WHERE blog_id=%d AND state_id=%d AND generation=1', 'foogallery_migrate_records', array( 7, 19 ) ),
	array( $snapshot, 'DELETE FROM `{{table}}` WHERE blog_id=%d AND id=%d AND revision=%d AND active_generation=%d', 'foogallery_migrate_state', array( 7, 19, 3, 2 ) ),
	array( $snapshot, 'SELECT id FROM `{{table}}` WHERE blog_id = %d AND state_key_hash = UNHEX(%s) LIMIT 2 FOR UPDATE', 'foogallery_migrate_state', array( 7, str_repeat( 'a', 64 ) ) ),
	array( $snapshot, 'SELECT id FROM `{{table}}` WHERE blog_id = %d AND id = %d FOR UPDATE', 'foogallery_migrate_state', array( 7, 19 ) ),
	array( 'plugins/class-wp-photo-album-plus.php', "SELECT id, name, description, a_order, a_parent, p_order_by, suba_order_by, timestamp, status, capability
                FROM {{table}}
                ORDER BY a_order ASC, id ASC", 'wppa_albums', array() ),
	array( 'plugins/class-wp-photo-album-plus.php', "SELECT id, album, ext, name, description, p_order, mean_rating, rating_count,
                    owner, timestamp, status, alt, filename, modified, exifdtm,
                    videox, videoy, duration, misc
                FROM {{table}}
                WHERE album > 0
                ORDER BY id ASC", 'wppa_photos', array() ),
);
