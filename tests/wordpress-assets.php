<?php
require dirname( __DIR__ ) . '/.local/wordpress/wp-load.php';
use StructuredFields\Assets;
if ( DB_NAME !== 'sf_prototype_local' || home_url() !== 'http://127.0.0.1:8128' ) throw new RuntimeException( 'Tests require the isolated local site.' );
$count = 0;
function check_asset( $condition, $label ) {
    global $count;
    if ( ! $condition ) throw new RuntimeException( $label );
    echo 'PASS ' . ++$count . ': ' . $label . "\n";
}
function asset_rejects( $fn, $label ) {
    try { $fn(); } catch ( Throwable $error ) { check_asset( true, $label ); return; }
    check_asset( false, $label );
}
$temp = tempnam( sys_get_temp_dir(), 'sf-asset-' );
$link = sys_get_temp_dir() . '/sf-link-' . wp_generate_uuid4();
$limited = 0;
try {
    check_asset( Assets::available(), 'Configured storage is outside the public root' );
    $original_server = $_SERVER;
    $quoted_root = sys_get_temp_dir() . "/sf-root-'" . wp_generate_uuid4();
    if ( ! mkdir( $quoted_root, 0700 ) ) throw new RuntimeException( 'Could not create disposable root.' );
    try {
        $_SERVER['DOCUMENT_ROOT'] = wp_slash( $quoted_root );
        check_asset( Assets::available(), 'Slashed server root with a quote is checked as its exact filesystem path' );
        $_SERVER['DOCUMENT_ROOT'] = array( $quoted_root );
        check_asset( ! Assets::available(), 'Non-string server document root fails closed' );
    } finally { $_SERVER = $original_server; rmdir( $quoted_root ); }
    check_asset( 0 === ( fileperms( Assets::directory() ) & 0077 ), 'Storage directory excludes group/other access' );
    asset_rejects( static fn() => Assets::validate_storage_path( ABSPATH, array( ABSPATH ) ), 'Reject public WordPress root' );
    asset_rejects( static fn() => Assets::validate_storage_path( WP_CONTENT_DIR, array( ABSPATH ) ), 'Reject directory inside public root' );
    asset_rejects( static fn() => Assets::validate_storage_path( dirname( rtrim( ABSPATH, '/' ) ), array( ABSPATH ) ), 'Reject an ancestor of public root' );
    symlink( rtrim( ABSPATH, '/' ), $link );
    asset_rejects( static fn() => Assets::validate_storage_path( $link, array( ABSPATH ) ), 'Reject symlink alias into public root' );
    asset_rejects( static fn() => Assets::validate_storage_path( SF_PRIVATE_MEDIA_DIR, array( '/sf-missing-root' ) ), 'Unknown public root fails closed' );
    asset_rejects( static fn() => Assets::validate_file( $temp, 'empty.pdf' ), 'Reject empty file' );
    $handle = fopen( $temp, 'wb' ); ftruncate( $handle, Assets::MAX_BYTES + 1 ); fclose( $handle ); clearstatcache();
    asset_rejects( static fn() => Assets::validate_file( $temp, 'large.pdf' ), 'Reject oversized file' );
    file_put_contents( $temp, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>' ); clearstatcache();
    asset_rejects( static fn() => Assets::validate_file( $temp, 'vector.svg' ), 'Reject active SVG file type' );
    asset_rejects( static fn() => Assets::validate_file( $temp, 'vector.png' ), 'Reject extension spoofing' );
    file_put_contents( $temp, '%PDF-1.4' . "\n%%EOF\n" ); clearstatcache();
    $pdf = Assets::validate_file( $temp, 'reference.pdf' );
    check_asset( $pdf['mime'] === 'application/pdf' && strlen( $pdf['sha256'] ) === 64, 'PDF receives content identity, not publication authority' );
    $image = Assets::validate_file( dirname( __DIR__ ) . '/public/assets/atelier.png', 'atelier.png' );
    check_asset( 'image/png' === $image['mime'], 'Existing image validates without being modified' );
    wp_set_current_user( 0 );
    check_asset( is_wp_error( Assets::permission() ), 'Anonymous asset permission denied' );
    $limited = wp_insert_user( array( 'user_login' => 'sf-asset-test-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'editor' ) );
    if ( is_wp_error( $limited ) ) throw new RuntimeException( 'Could not create isolated test user.' );
    wp_set_current_user( $limited );
    check_asset( current_user_can( 'upload_files' ) && is_wp_error( Assets::permission() ), 'Upload capability alone does not grant private asset access' );
    $request = new WP_REST_Request( 'GET', '/structured-fields/v1/articles/wordpress-sample/assets' );
    check_asset( 403 === rest_do_request( $request )->get_status(), 'REST rejects editor before reading asset metadata' );
    echo "Private asset checks: $count passed.\n";
} finally {
    if ( is_file( $temp ) ) unlink( $temp );
    if ( is_link( $link ) ) unlink( $link );
    if ( $limited && ! is_wp_error( $limited ) ) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $limited ); }
}
