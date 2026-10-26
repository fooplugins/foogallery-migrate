<?php
/**
 * Fresh R0 fixture only: 121 real AIGPL galleries, one image and category each.
 *
 * wp eval-file tests/integration/storage-release-many-roots.php /tmp/foogallery-migrate-r0.XXXXXX seed --path=SITE --user=fgmr0admin
 * Repeat seed until phase=ready, then use verify-source. No conversion is done here.
 */
use FooPlugins\FooGalleryMigrate\LegacyStorageSchema;
use FooPlugins\FooGalleryMigrate\Plugins\Aigpl;
use FooPlugins\FooGalleryMigrate\StorageAdmin;
use FooPlugins\FooGalleryMigrate\StorageAuthority;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! isset( $args ) || count( $args ) !== 2 ||
	! in_array( $args[1], array( 'seed', 'verify-source' ), true ) ) {
	throw new RuntimeException( 'An owned WP-CLI site and seed or verify-source are required.' );
}
$site = realpath( $args[0] );
if ( false === $site || ! preg_match( '~^/(?:private/)?tmp/foogallery-migrate-r0\.[A-Za-z0-9]+$~D', $site ) ||
	realpath( ABSPATH ) !== $site ) {
	throw new RuntimeException( 'Only the loaded disposable R0 site is accepted.' );
}
$manifest_path = $site . '/.fgm-r0-owner.json';
$manifest = is_file( $manifest_path ) ? json_decode( file_get_contents( $manifest_path ), true ) : null;
$repo = realpath( dirname( dirname( __DIR__ ) ) );
if ( ! is_array( $manifest ) || ! isset( $manifest['kind'], $manifest['site'], $manifest['database'],
	$manifest['host'], $manifest['repo'], $manifest['container'], $manifest['container_id'] ) ||
	$manifest['kind'] !== 'foogallery-migrate-r0-v1' || realpath( $manifest['site'] ) !== $site ||
	! preg_match( '/^fgm_r0_[a-z0-9]+$/D', $manifest['database'] ) ||
	! preg_match( '/^127\.0\.0\.1:[0-9]{2,5}$/D', $manifest['host'] ) ||
	! preg_match( '/^fgm-r0-[A-Za-z0-9]+$/D', $manifest['container'] ) ||
	! preg_match( '/^[a-f0-9]{64}$/D', $manifest['container_id'] ) ||
	$repo !== realpath( $manifest['repo'] ) || ! is_file( $site . '/wp-config.php' ) ) {
	throw new RuntimeException( 'Owned disposable manifest or repository differs.' );
}
$plugin_dir = $site . '/wp-content/plugins/foogallery-migrate';
if ( isset( $manifest['package'] ) ) {
	if ( is_link( $plugin_dir ) || realpath( $plugin_dir ) !== $plugin_dir ) {
		throw new RuntimeException( 'Owned package directory differs.' );
	}
	$identity_output = array(); $identity_code = 0;
	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/storage-release-package-identity.php' ) .
		' verify-tree ' . escapeshellarg( $plugin_dir ) . ' ' . escapeshellarg( $manifest_path ) . ' 2>/dev/null',
		$identity_output, $identity_code );
	if ( $identity_code !== 0 || count( $identity_output ) !== 1 ||
		trim( $identity_output[0] ) !== $manifest['package']['tree_sha256'] ) {
		throw new RuntimeException( 'Owned package tree identity differs.' );
	}
} elseif ( realpath( $plugin_dir ) !== $repo ) {
	throw new RuntimeException( 'Owned plugin source symlink differs.' );
}
$container_check = array(); $container_code = 0;
exec( 'docker inspect --format ' . escapeshellarg( '{{.Id}} {{index .Config.Labels "foogallery-migrate-r0"}}' ) .
	' ' . escapeshellarg( $manifest['container'] ) . ' 2>/dev/null', $container_check, $container_code );
if ( $container_code !== 0 || count( $container_check ) !== 1 ||
	trim( $container_check[0] ) !== $manifest['container_id'] . ' owned' ) {
	throw new RuntimeException( 'Owned SQL container identity differs.' );
}
ini_set( 'memory_limit', '256M' );
global $wpdb, $wp_version;
function many_roots_check( $okay, $message ) {
	if ( ! $okay ) { throw new RuntimeException( $message ); }
}
many_roots_check( ini_get( 'memory_limit' ) === '256M' && ! is_multisite() &&
	DB_NAME === $manifest['database'] && DB_HOST === $manifest['host'] &&
	$wpdb->get_var( 'SELECT DATABASE()' ) === $manifest['database'] && ! $wpdb->last_error,
	'Loaded site, SQL database, or effective memory limit differs.' );
many_roots_check( function_exists( 'foogallery_migrate_migrator_instance' ) &&
	function_exists( 'foogallery_default_gallery_template' ) && defined( 'FOOGALLERY_VERSION' ) &&
	get_current_user_id() > 0 && wp_get_current_user()->user_login === 'fgmr0admin' &&
	current_user_can( 'manage_options' ), 'Actual plugins or owned administrator are unavailable.' );

$step = $args[1];
$owner = 'fgm-many-roots-' . substr( $site, strrpos( $site, '.' ) + 1 );
$owner_key = '_fgm_many_roots_owner';
$journal_key = 'fgm_many_roots_fixture_v1';
$identity = hash( 'sha256', $site . '|' . $manifest['database'] . '|' . $manifest['container_id'] );
$total = 121; $batch = 16;
$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=' );
$png_hash = hash( 'sha256', $png );
$upload = wp_upload_dir( null, false ); // Discovery must not create a directory before ownership checks.
$expected_parent = $site . '/wp-content';
$expected_upload = $site . '/wp-content/uploads';
$assert_upload = function ( $must_exist ) use ( $upload, $expected_parent, $expected_upload ) {
	$exists = file_exists( $expected_upload );
	many_roots_check( empty( $upload['error'] ) && isset( $upload['basedir'], $upload['baseurl'] ) &&
		is_dir( $expected_parent ) && ! is_link( $expected_parent ) &&
		realpath( $expected_parent ) === $expected_parent &&
		$upload['basedir'] === $expected_upload && ! is_link( $expected_upload ) &&
		( ! $exists || ( is_dir( $expected_upload ) && realpath( $expected_upload ) === $expected_upload ) ) &&
		( ! $must_exist || $exists ),
		'Upload root must be the canonical owned site directory, without a symlink escape.' );
};
$assert_upload( false );
$media_dir = $expected_upload . '/' . $owner;
$raw_option = function ( $name ) use ( $wpdb ) {
	$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM `{$wpdb->options}` WHERE option_name=%s", $name ) );
	many_roots_check( ! $wpdb->last_error, 'Raw option lookup failed.' );
	return $value;
};
$save = function ( $value ) use ( $journal_key, $raw_option ) {
	update_option( $journal_key, $value, false );
	$raw = $raw_option( $journal_key );
	many_roots_check( is_string( $raw ) && maybe_unserialize( $raw ) === $value &&
		get_option( $journal_key, null ) === $value, 'Owned journal durable readback failed.' );
};
$owned_count = function ( $type ) use ( $wpdb, $owner_key, $owner ) {
	$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$wpdb->posts}` p INNER JOIN `{$wpdb->postmeta}` m ON m.post_id=p.ID WHERE p.post_type=%s AND m.meta_key=%s AND m.meta_value=%s", $type, $owner_key, $owner ) );
	many_roots_check( ! $wpdb->last_error, 'Owned post count failed.' );
	return (int) $count;
};
$baseline = function () use ( $wpdb ) {
	$count = $wpdb->get_var( "SELECT COUNT(*) FROM `{$wpdb->posts}`" );
	many_roots_check( ! $wpdb->last_error && (int) $count <= 16, 'Source fixture has too many pre-existing posts.' );
	$rows = $wpdb->get_results( "SELECT ID, post_type, post_status, post_title, post_content FROM `{$wpdb->posts}` ORDER BY ID ASC LIMIT 17", ARRAY_A );
	many_roots_check( ! $wpdb->last_error && is_array( $rows ) && count( $rows ) === (int) $count,
		'Pre-existing post baseline differs.' );
	return $rows;
};
$assert_baseline = function ( $rows ) {
	many_roots_check( is_array( $rows ) && count( $rows ) <= 16, 'Invalid protected baseline.' );
	foreach ( $rows as $row ) {
		$post = get_post( (int) $row['ID'] );
		many_roots_check( $post && (string) $post->ID === (string) $row['ID'] &&
			$post->post_type === $row['post_type'] && $post->post_status === $row['post_status'] &&
			$post->post_title === $row['post_title'] && $post->post_content === $row['post_content'],
			'Unrelated pre-existing post changed.' );
	}
};
$assert_pristine = function () use ( $raw_option, $wpdb ) {
	foreach ( array( FOOGALLERY_MIGRATE_OPTION_DATA, StorageAuthority::CONTROL_OPTION,
		StorageAuthority::AUTHORITY_OPTION, StorageAuthority::MAINTENANCE_OPTION,
		StorageAuthority::STORAGE_BACKUP_OPTION, LegacyStorageSchema::VERSION_OPTION,
		LegacyStorageSchema::LOCK_OPTION ) as $name ) {
		many_roots_check( null === $raw_option( $name ), 'Seeding refuses existing migration state or schema marker.' );
	}
	$schema = new LegacyStorageSchema( $wpdb );
	foreach ( array_keys( $schema->definitions() ) as $table ) {
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		many_roots_check( ! $wpdb->last_error && null === $found, 'Seeding refuses storage table remnants.' );
	}
	$status = StorageAdmin::status();
	many_roots_check( $status['backend'] === 'option' && $status['phase'] === 'stable' &&
		$status['problem'] === '', 'Seeding requires clean option authority.' );
};
$shortcode_line = function ( $unit ) {
	return sprintf( "\n[aigpl-gallery id=\"%d\"] [aigpl-gallery-album category=\"%d\"]",
		$unit['gallery'], $unit['term'] );
};
$assert_shortcodes = function ( $content, $units ) {
	$pattern = '/' . get_shortcode_regex( array( 'aigpl-gallery', 'aigpl-gallery-album' ) ) . '/s';
	$matched = preg_match_all( $pattern, $content, $shortcodes, PREG_SET_ORDER );
	many_roots_check( $matched === 2 * count( $units ), 'Protected content shortcode count differs.' );
	foreach ( $units as $index => $unit ) {
		$gallery = $shortcodes[2 * $index]; $album = $shortcodes[2 * $index + 1];
		many_roots_check( $gallery[1] === '' && $gallery[2] === 'aigpl-gallery' &&
			$gallery[6] === '' && shortcode_parse_atts( $gallery[3] ) === array( 'id' => (string) $unit['gallery'] ) &&
			$album[1] === '' && $album[2] === 'aigpl-gallery-album' &&
			$album[6] === '' && shortcode_parse_atts( $album[3] ) === array( 'category' => (string) $unit['term'] ),
			'Protected content shortcode type, attribute or order differs at index ' . $index . '.' );
	}
};

register_post_type( Aigpl::POST_TYPE, array( 'public' => false ) );
register_taxonomy( Aigpl::TAXONOMY, Aigpl::POST_TYPE, array( 'public' => false ) );
$journal = get_option( $journal_key, null );
if ( $step === 'seed' && null === $journal ) {
	$assert_pristine();
	$existing = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$wpdb->posts}` WHERE post_type=%s", Aigpl::POST_TYPE ) );
	$terms = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$wpdb->term_taxonomy}` WHERE taxonomy=%s", Aigpl::TAXONOMY ) );
	many_roots_check( ! $wpdb->last_error && (int) $existing === 0 && (int) $terms === 0 &&
		$owned_count( 'attachment' ) === 0 && $owned_count( 'page' ) === 0 &&
		! file_exists( $media_dir ) && ! is_link( $media_dir ), 'Fresh AIGPL source or owned media already exists.' );
	$journal = array( 'identity' => $identity, 'owner' => $owner, 'phase' => 'seeding',
		'total' => $total, 'units' => array(), 'baseline' => $baseline() );
	many_roots_check( add_option( $journal_key, $journal, '', false ), 'Owned journal creation failed.' );
}
many_roots_check( is_array( $journal ) && isset( $journal['identity'], $journal['owner'],
	$journal['phase'], $journal['total'], $journal['units'], $journal['baseline'] ) &&
	$journal['identity'] === $identity && $journal['owner'] === $owner &&
	$journal['total'] === $total && is_array( $journal['units'] ) &&
	count( $journal['units'] ) <= $total &&
	( count( $journal['units'] ) === 0 ||
		array_keys( $journal['units'] ) === range( 0, count( $journal['units'] ) - 1 ) ) &&
	in_array( $journal['phase'], array( 'seeding', 'ready' ), true ), 'Owned journal is absent or mismatched.' );

$verify_units = function ( $full ) use ( &$journal, $owner, $owner_key, $media_dir,
	$png_hash, $owned_count, $assert_baseline, $assert_shortcodes, $shortcode_line, $total ) {
	$units = $journal['units']; $count = count( $units );
	$assert_baseline( $journal['baseline'] );
	global $wpdb;
	$all_galleries = $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM `{$wpdb->posts}` WHERE post_type=%s", Aigpl::POST_TYPE
	) );
	$term_count = wp_count_terms( Aigpl::TAXONOMY, array( 'hide_empty' => false ) );
	many_roots_check( ! $wpdb->last_error && ! is_wp_error( $term_count ) &&
		(int) $all_galleries === $count, 'Unowned or missing AIGPL gallery source exists.' );
	many_roots_check( $count === $owned_count( 'attachment' ) &&
		$count === $owned_count( Aigpl::POST_TYPE ) &&
		$count === (int) $term_count &&
		( $journal['phase'] === 'ready' ? 1 : 0 ) === $owned_count( 'page' ),
		'Owned source counts differ from journal.' );
	if ( $count > 0 ) {
		many_roots_check( is_dir( $media_dir ) && ! is_link( $media_dir ), 'Owned media directory differs.' );
	} else {
		many_roots_check( ! file_exists( $media_dir ) && ! is_link( $media_dir ), 'Unexpected owned media directory.' );
	}
	$files = is_dir( $media_dir ) ? scandir( $media_dir ) : array( '.', '..' );
	many_roots_check( is_array( $files ) && count( $files ) === $count + 2,
		'Owned media count or unexpected file differs.' );
	$galleries = array(); $terms = array(); $attachments = array();
	foreach ( $units as $index => $unit ) {
		many_roots_check( is_array( $unit ) && isset( $unit['gallery'], $unit['attachment'], $unit['term'] ) &&
			is_int( $unit['gallery'] ) && is_int( $unit['attachment'] ) && is_int( $unit['term'] ),
			'Owned unit journal is invalid.' );
		$galleries[] = $unit['gallery']; $terms[] = $unit['term']; $attachments[] = $unit['attachment'];
		$gallery = get_post( $unit['gallery'] ); $attachment = get_post( $unit['attachment'] );
		$term = get_term( $unit['term'], Aigpl::TAXONOMY );
		$relative = $owner . '/' . $index . '.png';
		$file = $media_dir . '/' . $index . '.png';
		$meta = wp_get_attachment_metadata( $unit['attachment'] );
		$relations = wp_get_object_terms( $unit['gallery'], Aigpl::TAXONOMY, array( 'fields' => 'ids' ) );
		many_roots_check( $gallery && $gallery->post_type === Aigpl::POST_TYPE &&
			$gallery->post_status === 'publish' && $gallery->post_title === $owner . ' gallery ' . sprintf( '%03d', $index ) &&
			get_post_meta( $unit['gallery'], $owner_key, true ) === $owner &&
			get_post_meta( $unit['gallery'], Aigpl::META_GALLERY_IMAGES, true ) === array( $unit['attachment'] ) &&
			$attachment && $attachment->post_type === 'attachment' && $attachment->post_status === 'inherit' &&
			$attachment->post_mime_type === 'image/png' &&
			get_post_meta( $unit['attachment'], $owner_key, true ) === $owner &&
			get_post_meta( $unit['attachment'], '_wp_attached_file', true ) === $relative &&
			is_array( $meta ) && isset( $meta['file'], $meta['width'], $meta['height'] ) &&
			$meta['file'] === $relative && (int) $meta['width'] === 1 && (int) $meta['height'] === 1 &&
			$term && ! is_wp_error( $term ) && $term->name === $owner . ' category ' . sprintf( '%03d', $index ) &&
			$term->slug === sanitize_title( $term->name ) && ! is_wp_error( $relations ) &&
			array_map( 'intval', $relations ) === array( $unit['term'] ) &&
			is_file( $file ) && ! is_link( $file ) && $png_hash === hash_file( 'sha256', $file ),
			'Owned AIGPL source, term, attachment or file differs at index ' . $index . '.' );
	}
	many_roots_check( $count === count( array_unique( $galleries ) ) &&
		$count === count( array_unique( $attachments ) ) && $count === count( array_unique( $terms ) ),
		'Owned source IDs are not unique.' );
	$proof = array( 'galleries' => $count, 'albums' => $count,
		'attachments' => $count, 'gallery_ids_sha256' => hash( 'sha256', implode( ',', $galleries ) ),
		'term_ids_sha256' => hash( 'sha256', implode( ',', $terms ) ),
		'attachment_ids_sha256' => hash( 'sha256', implode( ',', $attachments ) ),
		'png_sha256' => $png_hash, 'files_checked' => $count );
	if ( $full ) {
		many_roots_check( $journal['phase'] === 'ready' && $count === $total &&
			isset( $journal['page'], $journal['content_sha256'], $journal['gallery_ids_sha256'],
				$journal['term_ids_sha256'], $journal['attachment_ids_sha256'] ) &&
			$proof['gallery_ids_sha256'] === $journal['gallery_ids_sha256'] &&
			$proof['term_ids_sha256'] === $journal['term_ids_sha256'] &&
			$proof['attachment_ids_sha256'] === $journal['attachment_ids_sha256'],
			'Completed source or pinned order differs.' );
		$page = get_post( $journal['page'] );
		$content = 'Original AIGPL many-root source:';
		foreach ( $units as $unit ) {
			$content .= $shortcode_line( $unit );
		}
		many_roots_check( $page && $page->post_type === 'page' && $page->post_status === 'publish' &&
			get_post_meta( $page->ID, $owner_key, true ) === $owner &&
			$page->post_content === $content && hash( 'sha256', $content ) === $journal['content_sha256'],
			'Protected shortcode page differs.' );
		$assert_shortcodes( $page->post_content, $units );
		$adapter = new Aigpl(); $found_galleries = $adapter->find_galleries();
		$found_albums = $adapter->find_albums();
		many_roots_check( count( $found_galleries ) === $total && count( $found_albums ) === $total,
			'Actual AIGPL adapter source count differs.' );
		foreach ( $found_galleries as $index => $found ) {
			many_roots_check( (int) $found->ID === $galleries[$total - 1 - $index] &&
				(int) $found->children_count === 1, 'Actual gallery source order differs.' );
		}
		foreach ( $found_albums as $index => $found ) {
			many_roots_check( (int) $found->ID === $terms[$index] &&
				(int) $found->children_count === 1 &&
				count( $found->children ) === 1 &&
				(int) $found->children[0]->ID === $galleries[$index],
				'Actual album source order or membership differs.' );
		}
		$proof['page'] = (int) $page->ID; $proof['content_sha256'] = $journal['content_sha256'];
	}
	return $proof;
};

if ( $step === 'seed' ) {
	many_roots_check( $journal['phase'] === 'seeding', 'Completed fixture refuses reseed.' );
	$assert_pristine();
	$verify_units( false );
	if ( ! is_dir( $expected_upload ) ) {
		$assert_upload( false );
		many_roots_check( wp_mkdir_p( $expected_upload ), 'Owned upload root could not be created.' );
	}
	$assert_upload( true );
	many_roots_check( ! is_link( $media_dir ) && ( is_dir( $media_dir ) || wp_mkdir_p( $media_dir ) ),
		'Owned media directory cannot be created.' );
	$end = min( $total, count( $journal['units'] ) + $batch );
	for ( $index = count( $journal['units'] ); $index < $end; ++$index ) {
		$file = $media_dir . '/' . $index . '.png';
		many_roots_check( ! file_exists( $file ) && ! is_link( $file ) &&
			file_put_contents( $file, $png, LOCK_EX ) !== false, 'Owned media collision or write failure.' );
		$relative = $owner . '/' . $index . '.png';
		$attachment = wp_insert_post( array( 'post_type' => 'attachment', 'post_status' => 'inherit',
			'post_mime_type' => 'image/png', 'post_title' => $owner . ' image ' . sprintf( '%03d', $index ),
			'guid' => $upload['baseurl'] . '/' . $relative,
			'meta_input' => array( $owner_key => $owner ) ), true );
		many_roots_check( ! is_wp_error( $attachment ) && $attachment > 0, 'Owned attachment insertion failed.' );
		many_roots_check( update_post_meta( $attachment, '_wp_attached_file', $relative ) !== false &&
			update_post_meta( $attachment, '_wp_attachment_metadata', array( 'width' => 1,
				'height' => 1, 'file' => $relative, 'sizes' => array() ) ) !== false,
			'Owned attachment metadata failed.' );
		$gallery = wp_insert_post( array( 'post_type' => Aigpl::POST_TYPE, 'post_status' => 'publish',
			'post_title' => $owner . ' gallery ' . sprintf( '%03d', $index ),
			'meta_input' => array( $owner_key => $owner ) ), true );
		many_roots_check( ! is_wp_error( $gallery ) && $gallery > 0 &&
			update_post_meta( $gallery, Aigpl::META_GALLERY_IMAGES, array( (int) $attachment ) ) !== false,
			'Owned AIGPL gallery insertion failed.' );
		$name = $owner . ' category ' . sprintf( '%03d', $index );
		$inserted = wp_insert_term( $name, Aigpl::TAXONOMY, array( 'slug' => sanitize_title( $name ) ) );
		many_roots_check( ! is_wp_error( $inserted ) && isset( $inserted['term_id'] ) &&
			(int) $inserted['term_id'] > 0, 'Owned AIGPL category insertion failed.' );
		$term = (int) $inserted['term_id'];
		$related = wp_set_object_terms( $gallery, array( $term ), Aigpl::TAXONOMY, false );
		many_roots_check( ! is_wp_error( $related ) && count( $related ) === 1,
			'Owned gallery/category relationship failed.' );
		$journal['units'][] = array( 'gallery' => (int) $gallery,
			'attachment' => (int) $attachment, 'term' => $term );
		$save( $journal );
	}
	if ( count( $journal['units'] ) === $total ) {
		$verify_units( false );
		$content = 'Original AIGPL many-root source:';
		$galleries = array(); $terms = array(); $attachments = array();
		foreach ( $journal['units'] as $unit ) {
			$galleries[] = $unit['gallery']; $terms[] = $unit['term']; $attachments[] = $unit['attachment'];
			$content .= $shortcode_line( $unit );
		}
		$assert_shortcodes( $content, $journal['units'] );
		$page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish',
			'post_title' => $owner . ' source content', 'post_content' => $content,
			'meta_input' => array( $owner_key => $owner ) ), true );
		many_roots_check( ! is_wp_error( $page ) && $page > 0, 'Protected source page insertion failed.' );
		$journal['page'] = (int) $page; $journal['content_sha256'] = hash( 'sha256', $content );
		$journal['gallery_ids_sha256'] = hash( 'sha256', implode( ',', $galleries ) );
		$journal['term_ids_sha256'] = hash( 'sha256', implode( ',', $terms ) );
		$journal['attachment_ids_sha256'] = hash( 'sha256', implode( ',', $attachments ) );
		$journal['phase'] = 'ready'; $save( $journal );
	}
}
$proof = $verify_units( $step === 'verify-source' );
echo json_encode( array( 'step' => $step, 'site' => $site, 'database' => $manifest['database'],
	'container_id' => $manifest['container_id'], 'php' => PHP_VERSION, 'wordpress' => $wp_version,
	'memory_limit' => ini_get( 'memory_limit' ), 'owner' => $owner,
	'phase' => $journal['phase'], 'expected' => $total, 'source' => $proof ) ) . "\n";
