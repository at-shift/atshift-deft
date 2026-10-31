<?php
require_once dirname( __DIR__ ) . '/.local/wordpress/wp-load.php';
use StructuredFields\Assets;
use StructuredFields\Snapshot;
use StructuredFields\Store;
if ( DB_NAME !== 'sf_prototype_local' || home_url() !== 'http://127.0.0.1:8128' ) throw new RuntimeException( 'Use the isolated local site.' );
wp_set_current_user( get_user_by( 'login', 'sf_local_admin' )->ID );

function sf_media_test_id( $id ) {
    if ( ! preg_match( '/^media-test-[a-f0-9-]{36}$/D', $id ) ) throw new RuntimeException( 'Not a disposable test article.' );
}
function sf_media_test_cleanup( $id ) {
    sf_media_test_id( $id );
    global $wpdb;
    if ( class_exists( 'StructuredFields\\Publications' ) ) {
        $job = StructuredFields\Publications::read( $id );
        if ( $job ) {
            wp_clear_scheduled_hook( StructuredFields\Publications::HOOK, array( $id, (int) $job['version'] ) );
            if ( $job['post_id'] && get_post_meta( (int) $job['post_id'], '_sf_publication', true ) === $job['publication_id'] ) wp_delete_post( (int) $job['post_id'], true );
            $dir = Assets::directory() . '/publication-' . $job['publication_id'];
            if ( is_dir( $dir ) && ! is_link( $dir ) ) { foreach ( glob( $dir . '/*.bin' ) ?: array() as $file ) unlink( $file ); rmdir( $dir ); }
            $wpdb->delete( StructuredFields\Publications::table(), array( 'article_id' => $id ) );
        }
    }
    $table = Assets::table();
    foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT asset_id FROM $table WHERE article_id=%s", $id ) ) as $asset ) {
        $path = Assets::directory() . '/' . $asset . '.bin';
        if ( is_file( $path ) ) unlink( $path );
    }
    $wpdb->delete( $table, array( 'article_id' => $id ) );
    foreach ( array( 'StructuredFields\\Transfer', 'StructuredFields\\Review' ) as $class ) {
        if ( class_exists( $class ) ) $wpdb->delete( $class::table(), array( 'article_id' => $id ) );
    }
    foreach ( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'posts_per_page' => -1,
        'meta_key' => '_sf_test_article', 'meta_value' => $id, 'fields' => 'ids' ) ) as $attachment ) wp_delete_attachment( $attachment, true );
    $table = Store::table();
    foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM $table WHERE article_id=%s", $id ) ) as $post ) wp_delete_post( $post, true );
    $wpdb->delete( $table, array( 'article_id' => $id ) );
}
function sf_media_test_create( $id ) {
    sf_media_test_id( $id );
    global $wpdb;
    $snapshot = json_decode( file_get_contents( dirname( __DIR__ ) . '/wordpress/atshift-deft/media-sample.json' ) );
    $snapshot->article->id = $id;
    $snapshot->article->title = 'Private media article QA';
    $saved = Store::save( Snapshot::validate( Snapshot::json( $snapshot ) ), 0 );
    if ( is_wp_error( $saved ) ) throw new RuntimeException( $saved->get_error_message() );
    $files = array(
        'image' => array( 'name' => 'photo.png', 'mime' => 'image/png', 'bytes' => file_get_contents( ABSPATH . 'wp-content/themes/twentytwentyfive/screenshot.png' ) ),
        'document' => array( 'name' => 'application.pdf', 'mime' => 'application/pdf', 'bytes' => "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n" ),
    );
    $refs = $attachments = array();
    foreach ( array( 'image', 'document', 'reference' ) as $key ) {
        $file = $files[ 'reference' === $key ? 'document' : $key ];
        $asset_id = wp_generate_uuid4();
        $path = Assets::directory() . '/' . $asset_id . '.bin';
        file_put_contents( $path, $file['bytes'] ); chmod( $path, 0600 );
        $hash = hash( 'sha256', $file['bytes'] );
        if ( false === $wpdb->insert( Assets::table(), array( 'asset_id' => $asset_id, 'article_id' => $id,
            'purpose' => 'reference' === $key ? 'reference' : 'publication', 'sha256' => $hash,
            'filename' => $file['name'], 'mime' => $file['mime'], 'bytes' => strlen( $file['bytes'] ),
            'created_by' => get_current_user_id(), 'created_at' => current_time( 'mysql', true ) ) ) ) throw new RuntimeException( 'Fixture insert failed.' );
        $refs[ $key ] = (object) array( 'kind' => 'staged', 'id' => $asset_id, 'sha256' => $hash );
    }
    foreach ( $files as $key => $file ) {
        $upload = wp_upload_bits( $id . '-' . $file['name'], null, $file['bytes'] );
        if ( $upload['error'] ) throw new RuntimeException( 'Fixture upload failed.' );
        $attachment = wp_insert_attachment( array( 'post_title' => $id . '-' . $key, 'post_mime_type' => $file['mime'], 'post_status' => 'inherit',
            'post_excerpt' => 'Original caption', 'meta_input' => array( '_sf_test_article' => $id, '_wp_attachment_image_alt' => 'Original alt' ) ), $upload['file'], 0, true );
        if ( is_wp_error( $attachment ) ) { unlink( $upload['file'] ); throw new RuntimeException( 'Fixture attachment failed.' ); }
        $attachments[ $key ] = (object) array( 'kind' => 'attachment', 'id' => (string) $attachment, 'sha256' => hash( 'sha256', $file['bytes'] ) );
    }
    return array( 'snapshot' => $snapshot, 'staged' => $refs, 'attachments' => $attachments );
}

if ( realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) === __FILE__ ) {
    $action = $argv[1] ?? ''; $id = $argv[2] ?? ''; sf_media_test_id( $id );
    if ( 'create' === $action ) echo Snapshot::json( sf_media_test_create( $id ) );
    elseif ( 'cleanup' === $action ) { sf_media_test_cleanup( $id ); echo 'fixture-ok'; }
    elseif ( 'tamper' === $action ) {
        $ids = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => -1,
            'meta_key' => '_sf_test_article', 'meta_value' => $id, 'fields' => 'ids' ) );
        foreach ( $ids as $attachment ) file_put_contents( get_attached_file( $attachment ), 'tampered', FILE_APPEND );
        echo 'fixture-ok';
    } elseif ( 'expire-transfers' === $action || 'expire-reviews' === $action ) {
        $table = 'expire-transfers' === $action ? StructuredFields\Transfer::table() : StructuredFields\Review::table();
        $wpdb->update( $table, array( 'expires' => time() - 1 ), array( 'article_id' => $id ) );
        echo 'fixture-ok';
    } elseif ( 'grant-hashes' === $action ) {
        $table = StructuredFields\Transfer::table();
        echo Snapshot::json( $wpdb->get_col( $wpdb->prepare( "SELECT token_hash FROM $table WHERE article_id=%s", $id ) ) );
    } else throw new RuntimeException( 'Unknown fixture operation.' );
}
