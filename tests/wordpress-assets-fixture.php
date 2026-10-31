<?php
// Only disposable asset-test-* articles in the isolated local database may be touched.
require dirname( __DIR__ ) . '/.local/wordpress/wp-load.php';
use StructuredFields\Assets;
use StructuredFields\Store;
if ( DB_NAME !== 'sf_prototype_local' || home_url() !== 'http://127.0.0.1:8128' ) throw new RuntimeException( 'Tests require the isolated local site.' );
$action = $argv[1] ?? '';
$id = $argv[2] ?? '';
if ( ! preg_match( '/^asset-test-[a-f0-9-]{36}$/D', $id ) ) throw new RuntimeException( 'Not a disposable article.' );
global $wpdb;
$table = Assets::table();
$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE article_id=%s", $id ), ARRAY_A );
if ( 'cleanup' === $action ) {
    foreach ( $rows as $row ) {
        $path = Assets::directory() . '/' . $row['asset_id'] . '.bin';
        if ( is_file( $path ) ) unlink( $path );
    }
    $wpdb->delete( $table, array( 'article_id' => $id ) );
    $snapshots = Store::table();
    foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM $snapshots WHERE article_id=%s", $id ) ) as $post ) wp_delete_post( $post, true );
    $wpdb->delete( $snapshots, array( 'article_id' => $id ) );
} elseif ( 'tamper' === $action && count( $rows ) ) {
    file_put_contents( Assets::directory() . '/' . $rows[0]['asset_id'] . '.bin', 'tamper', FILE_APPEND );
} elseif ( 'quota' === $action && count( $rows ) ) {
    $wpdb->update( $table, array( 'bytes' => Assets::SITE_BYTES ), array( 'asset_id' => $rows[0]['asset_id'] ) );
} else {
    throw new RuntimeException( 'Unsupported fixture action.' );
}
echo "fixture-ok\n";
