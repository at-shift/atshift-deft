<?php
require __DIR__ . '/wordpress-media-fixture.php';
use StructuredFields\Snapshot;
use StructuredFields\Store;
use StructuredFields\Media;
use StructuredFields\Assets;
$id = 'media-test-' . wp_generate_uuid4();
$count = 0;
function media_check( $test, $label ) { global $count; if ( ! $test ) throw new RuntimeException( $label ); echo 'PASS ' . ++$count . ': ' . $label . "\n"; }
function media_reject( $fn, $label ) { try { $fn(); } catch ( Throwable $error ) { media_check( true, $label ); return; } media_check( false, $label ); }
function media_copy( $value ) { return json_decode( Snapshot::json( $value ) ); }
$limited = 0;
try {
    $fixture = sf_media_test_create( $id );
    $value = $fixture['snapshot']; $value->article->revision = 2;
    foreach ( array( 'image', 'document' ) as $key ) {
        $value->article->blocks[0]->values->$key->assetRef = $fixture['staged'][ $key ];
        $value->article->blocks[0]->values->$key->alt = 'image' === $key ? 'A contextual image description' : 'Download the application';
    }
    $valid = Snapshot::validate( Snapshot::json( $value ) );
    media_check( str_contains( $valid['html'], 'data-sf-media=' ) && ! str_contains( $valid['html'], 'admin-post.php' ), 'Stored rendering contains stable bindings, not session URLs' );
    media_check( ! str_contains( $valid['payload'], 'contentUrl' ) && ! str_contains( $valid['payload'], Assets::directory() ), 'Snapshot does not persist access tokens or server paths' );
    $save = Store::save( $valid, 1 ); media_check( ! is_wp_error( $save ) && 2 === $save['version'], 'Save private references as immutable v2 snapshot' );
    $row = Store::read( $id, 2 );
    $html = Media::preview_html( $row );
    media_check( str_contains( $html, 'action=sf_media' ) && str_contains( $html, 'download=' ) && ! str_contains( $html, 'data-sf-media' ), 'Private preview resolves images and PDF download links' );
    media_check( str_contains( $html, 'A contextual image description' ) && str_contains( $html, 'Download the application' ), 'Contextual alt and link text survive rendering' );
    media_check( ! str_contains( Store::read( $id, 1 )['payload'], 'assetRef' ), 'Older snapshot is not rewritten' );
    media_check( 2 === Store::save( Snapshot::validate( Snapshot::json( $value ) ), 2 )['version'], 'Same v2 save is idempotent' );
    $reordered = media_copy( $value );
    $reordered->article->blocks[0]->values->image->assetRef = (object) array_reverse( get_object_vars( $reordered->article->blocks[0]->values->image->assetRef ), true );
    media_check( Snapshot::validate( Snapshot::json( $reordered ) )['digest'] === $valid['digest'], 'Reference property order does not change the digest' );
    foreach ( array( 'reference', 'hash', 'cross-article', 'wrong-type', 'url-and-ref', 'missing-alt', 'missing-version', 'unknown-version' ) as $case ) {
        $bad = media_copy( $value );
        if ( 'reference' === $case ) $bad->article->blocks[0]->values->document->assetRef = $fixture['staged']['reference'];
        if ( 'hash' === $case ) $bad->article->blocks[0]->values->image->assetRef->sha256 = str_repeat( '0', 64 );
        if ( 'cross-article' === $case ) $bad->article->id = 'different-article';
        if ( 'wrong-type' === $case ) $bad->article->blocks[0]->values->image->assetRef = $fixture['staged']['document'];
        if ( 'url-and-ref' === $case ) $bad->article->blocks[0]->values->image->value = '/image.png';
        if ( 'missing-alt' === $case ) $bad->article->blocks[0]->values->image->alt = '';
        if ( 'missing-version' === $case ) unset( $bad->schemaVersion );
        if ( 'unknown-version' === $case ) $bad->schemaVersion = 4;
        media_reject( static fn() => Snapshot::validate( Snapshot::json( $bad ) ), 'Reject ' . $case );
    }
    $bad = media_copy( $fixture['snapshot'] ); $bad->article->blocks[0]->values->image = (object) array( 'value' => '/wp-admin/admin-post.php?action=sf_asset&asset=123', 'alt' => 'bypass' );
    media_reject( static fn() => Snapshot::validate( Snapshot::json( $bad ) ), 'Reject raw session file URLs in URL fields' );
    $value->article->revision = 3;
    foreach ( array( 'image', 'document' ) as $key ) $value->article->blocks[0]->values->$key->assetRef = $fixture['attachments'][ $key ];
    $before = wp_count_posts( 'attachment' )->inherit;
    $save = Store::save( Snapshot::validate( Snapshot::json( $value ) ), 2 );
    media_check( ! is_wp_error( $save ) && 3 === $save['version'], 'Reuse existing WordPress image and PDF by ID/hash' );
    media_check( wp_count_posts( 'attachment' )->inherit === $before, 'Reusing media creates no duplicate attachments' );
    $attachment = (int) $fixture['attachments']['image']->id;
    media_check( 'Original alt' === get_post_meta( $attachment, '_wp_attachment_image_alt', true ) && 'Original caption' === get_post( $attachment )->post_excerpt, 'Reuse does not change original alt or caption' );
    $path = get_attached_file( $attachment ); $original = file_get_contents( $path );
    file_put_contents( $path, $original . 'changed' ); clearstatcache();
    media_reject( static fn() => Media::preview_html( Store::read( $id, 3 ) ), 'Changed existing media blocks preview instead of switching bytes' );
    file_put_contents( $path, $original ); clearstatcache();
    media_check( str_contains( Media::preview_html( Store::read( $id, 3 ) ), 'action=sf_media' ), 'Original pinned bytes restore the preview' );
    $original_meta = get_post_meta( $attachment, '_wp_attached_file', true );
    update_post_meta( $attachment, '_wp_attached_file', ABSPATH . 'wp-config.php' );
    media_reject( static fn() => Media::attachment( (string) $attachment ), 'Reject attachment paths outside uploads' );
    update_post_meta( $attachment, '_wp_attached_file', $original_meta );
    $limited = wp_insert_user( array( 'user_login' => 'sf-media-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'editor' ) );
    wp_set_current_user( $limited );
    media_reject( static fn() => Media::resolve( $fixture['attachments']['image'], $id, 'image' ), 'Editor cannot resolve private preview media' );
    wp_set_current_user( get_user_by( 'login', 'sf_local_admin' )->ID );
    wp_delete_attachment( $attachment, true );
    media_reject( static fn() => Media::preview_html( Store::read( $id, 3 ) ), 'Deleted media blocks preview without changing saved content' );
    media_check( json_decode( Store::read( $id, 3 )['payload'] )->article->blocks[0]->values->image->assetRef->id === (string) $attachment, 'Saved reference remains unchanged after deletion' );
    echo "Media binding checks: $count passed.\n";
} finally {
    wp_set_current_user( get_user_by( 'login', 'sf_local_admin' )->ID );
    sf_media_test_cleanup( $id );
    if ( $limited && ! is_wp_error( $limited ) ) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $limited ); }
}
