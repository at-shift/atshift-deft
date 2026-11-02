<?php
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require dirname( __DIR__ ) . '/.local/wordpress/wp-load.php';
use Atshift\Deft\{Assets, PrivateBlobs, Store, Snapshot, Media, Publications, PublicationMedia};
if ( ! preg_match( '/^sf_deft_prefix_[a-f0-9]+$/D', DB_NAME ) || home_url() !== 'http://127.0.0.1:8128' ) throw new RuntimeException( 'Disposable test site required.' );
$checks = 0; $ids = array(); $article = 'portable-' . wp_generate_uuid4(); $job = null; $attachment = 0;
function portable_check( $ok, $label ) { global $checks; if ( ! $ok ) throw new RuntimeException( $label ); echo 'PASS ' . ++$checks . ': ' . $label . PHP_EOL; }
function portable_good( $value ) { if ( is_wp_error( $value ) ) throw new RuntimeException( $value->get_error_message() ); return $value; }
global $wpdb;
wp_set_current_user( get_user_by( 'login', 'sf_local_admin' )->ID );
$_COOKIE[LOGGED_IN_COOKIE] = wp_generate_auth_cookie( get_current_user_id(), time() + HOUR_IN_SECONDS, 'logged_in' );
$uploads = static function( $u ) {
    $u['basedir'] = WP_CONTENT_DIR . '/relocated-uploads'; $u['baseurl'] = content_url( '/relocated-uploads' );
    $u['path'] = $u['basedir'] . $u['subdir']; $u['url'] = $u['baseurl'] . $u['subdir']; $u['error'] = false; return $u;
};
try {
    portable_check( ! defined( 'SF_PRIVATE_MEDIA_DIR' ) && ! defined( 'SF_PUBLIC_DOCUMENT_ROOT' ), 'No legacy private-path configuration is present' );
    portable_check( WP_CONTENT_DIR !== ABSPATH . 'wp-content', 'WordPress content has been relocated' );
    portable_check( Assets::available(), 'New storage works without server directory configuration' );
    $sample = json_decode( file_get_contents( dirname( __DIR__ ) . '/wordpress/atshift-deft/media-sample.json' ) );
    $sample->article->id = $article;
    $saved = portable_good( Store::save( Snapshot::validate( Snapshot::json( $sample ) ), 0 ) );
    $files = array( 'image' => array( 'fixture.png', file_get_contents( get_theme_file_path( 'screenshot.png' ) ), 'image/png' ),
        'document' => array( 'fixture.pdf', "%PDF-1.4\n%%EOF\n", 'application/pdf' ) );
    foreach ( $files as $field => $file ) {
        $id = wp_generate_uuid4(); $ids[] = $id;
        $row = array( 'asset_id' => $id, 'article_id' => $article, 'purpose' => 'publication', 'filename' => $file[0],
            'sha256' => hash( 'sha256', $file[1] ), 'mime' => $file[2], 'bytes' => strlen( $file[1] ), 'created_by' => get_current_user_id(), 'created_at' => current_time( 'mysql', true ) );
        PrivateBlobs::put( 'asset', $id, $file[1], $row );
        portable_check( 1 === $wpdb->insert( Assets::table(), $row ), 'Private metadata saved without a filesystem path: ' . $field );
        $sample->article->blocks[0]->values->$field->assetRef = (object) array( 'kind' => 'staged', 'id' => $id, 'sha256' => $row['sha256'] );
        $sample->article->blocks[0]->values->$field->alt = 'Portable ' . $field;
    }
    $sample->article->revision = 2;
    portable_good( Store::save( Snapshot::validate( Snapshot::json( $sample ) ), 1 ) );
    portable_check( str_contains( Media::preview_html( Store::read( $article ) ), 'atshde_media' ), 'Private preview resolves database images/PDF without a legacy directory' );
    $request = new WP_REST_Request( 'POST' ); $request->set_header( 'content-type', 'application/json' );
    $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
    $request->set_body( wp_json_encode( array( 'articleId' => $article, 'expectedVersion' => 2, 'expectedPublicationVersion' => 0,
        'requestId' => wp_generate_uuid4(), 'postType' => 'post', 'timing' => 'now', 'publishAt' => '', 'timeZone' => '' ) ) );
    $result = portable_good( Publications::set( $request ) ); $job = Publications::read( $article );
    portable_check( 'published' === $result['state'], 'Immediate publication succeeds with database-only private media' );
    foreach ( Media::references( $sample ) as $key => $ref ) {
        list( $meta, $bytes ) = PublicationMedia::bytes( $job['publication_id'], $key );
        portable_check( $meta['sha256'] === hash( 'sha256', $bytes ), 'Public frozen bytes retain the pinned digest' );
    }
    add_filter( 'upload_dir', $uploads );
    $upload = wp_upload_bits( 'deft-portable.png', null, $files['image'][1] );
    portable_check( ! $upload['error'], 'WordPress upload API resolves the relocated uploads directory' );
    $attachment = portable_good( wp_insert_attachment( array( 'post_title' => 'Portable image', 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ), $upload['file'], 0, true ) );
    $meta = Media::attachment( (string) $attachment );
    portable_check( $meta['path'] === realpath( $upload['file'] ) && str_starts_with( $meta['path'], WP_CONTENT_DIR . '/relocated-uploads/' ), 'Existing media reuse follows core path APIs, not wp-content/uploads assumptions' );
    echo 'Storage portability checks: ' . $checks . ' passed.' . PHP_EOL;
} finally {
    if ( $attachment ) wp_delete_attachment( $attachment, true );
    remove_filter( 'upload_dir', $uploads );
    if ( $job ) {
        foreach ( json_decode( $job['files'] ?: '{}', true ) as $file ) PrivateBlobs::delete( 'publication', $job['publication_id'] . ':' . $file['sha256'] );
        if ( $job['post_id'] ) wp_delete_post( $job['post_id'], true );
        $wpdb->delete( Publications::table(), array( 'article_id' => $article ) );
    }
    foreach ( $ids as $id ) PrivateBlobs::delete( 'asset', $id );
    $wpdb->delete( Assets::table(), array( 'article_id' => $article ) );
    foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT post_id FROM %i WHERE article_id=%s', Store::table(), $article ) ) as $post ) wp_delete_post( $post, true );
    $wpdb->delete( Store::table(), array( 'article_id' => $article ) );
}
