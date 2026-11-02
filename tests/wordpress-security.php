<?php
namespace Atshift\Deft {
    // Count real file hashing without replacing its integrity behavior.
    function hash_file( ...$args ) { $GLOBALS['sf_security_hashes']++; return \hash_file( ...$args ); }
}
namespace {
require __DIR__ . '/wordpress-media-fixture.php';
require_once __DIR__ . '/wordpress-connection-context.php';
use Atshift\Deft\{Snapshot, Store, Media, Assets, ComponentBuilder, Connections, Publications, Review, Reservations};
$id = 'media-test-' . wp_generate_uuid4(); $count = $failures = 0; $keys = array(); $hook = null;
$GLOBALS['sf_security_hashes'] = 0;
function sfs_check( $ok, $label ) { global $count, $failures; $count++; if ( ! $ok ) $failures++; echo ( $ok ? 'PASS ' : 'FAIL ' ) . $count . ': ' . $label . PHP_EOL; }
function sfs_reject( $fn ) { try { $fn(); return false; } catch ( Throwable $e ) { return true; } }
function sfs_held_reservation( $job ) {
    $post = $job && $job['post_id'] ? get_post( (int) $job['post_id'] ) : null;
    return $post && Publications::matches_post( $job, $post ) && Reservations::INACTIVE === $post->post_status &&
        ! is_post_publicly_viewable( $post ) && '' === $post->post_content;
}
function sfs_key() {
    global $wpdb, $keys;
    $id = wp_generate_uuid4(); $secret = bin2hex( random_bytes( 32 ) ); $keys[] = $id;
    $wpdb->insert( Connections::table(), array( 'id' => $id, 'owner_id' => get_current_user_id(), 'name' => 'Disposable security test',
        'secret_hash' => hash( 'sha256', $secret ), 'created' => time(), 'expires' => time() + 3600, 'publishing' => 1 ) );
    $request = new WP_REST_Request( 'POST', Connections::PUBLISH_ROUTE );
    $request->set_body( '{}' );
    $request->set_header( 'authorization', 'Bearer sfc_' . $id . '_' . $secret );
    if ( null !== sf_test_begin_connection( $request ) ) throw new RuntimeException( 'Fixture authentication failed.' );
    return $id;
}
try {
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $fixture = sf_media_test_create( $id ); $snapshot = $fixture['snapshot'];
    $titles = array( '<img src=x onerror=alert(1)>', '<svg onload=alert(1)>', '日本語 & "引用" <b>文字</b> &lt;tag&gt;' );
    foreach ( $titles as $title ) {
        $row = Store::read( $id ); $snapshot->article->revision++; $snapshot->article->title = $title;
        $saved = Store::save( Snapshot::validate( Snapshot::json( $snapshot ) ), (int) $row['version'] );
        if ( is_wp_error( $saved ) ) throw new RuntimeException( $saved->get_error_message() );
        $html = get_the_title( $saved['postId'] ); $dom = new DOMDocument();
        @$dom->loadHTML( '<?xml encoding="UTF-8"><html><body><h1>' . $html . '</h1></body></html>' );
        sfs_check( 0 === $dom->getElementsByTagName( 'img' )->length && 0 === $dom->getElementsByTagName( 'svg' )->length &&
            $title === $dom->getElementsByTagName( 'h1' )->item( 0 )->textContent, 'Native title is inert and preserves literal text: ' . $title );
        sfs_check( json_decode( Store::read( $id )['payload'] )->article->title === $title, 'Canonical title remains unchanged' );
    }
    $recipe = ComponentBuilder::example(); $recipe->layout = 'stack'; $recipe->fields = array();
    for ( $i = 0; $i < 12; $i++ ) $recipe->fields[] = (object) array( 'id' => 'image' . $i, 'key' => 'image' . $i, 'label' => 'Image', 'type' => 'image', 'required' => true, 'value' => '', 'alt' => '' );
    $component = ComponentBuilder::build( $recipe ); $snapshot->components = array( $component ); $snapshot->article->blocks = array();
    for ( $i = 0; $i < 50; $i++ ) {
        $values = new stdClass(); foreach ( $component->fields as $f ) $values->{$f->key} = (object) array( 'value' => '', 'alt' => 'Image description', 'assetRef' => $fixture['staged']['image'] );
        $snapshot->article->blocks[] = (object) array( 'id' => 'block' . $i, 'componentId' => $component->id, 'componentRevision' => $component->revision, 'variantId' => 'default', 'values' => $values );
    }
    $GLOBALS['sf_security_hashes'] = 0;
    $rejected = sfs_reject( static fn() => Snapshot::validate( Snapshot::json( $snapshot ) ) );
    sfs_check( $rejected && 0 === $GLOBALS['sf_security_hashes'], '600 repeated bindings rejected before any file hashing' );
    $snapshot->article->blocks = array_slice( $snapshot->article->blocks, 0, 4 );
    $GLOBALS['sf_security_hashes'] = 0; $valid = Snapshot::validate( Snapshot::json( $snapshot ) );
    sfs_check( 1 === $GLOBALS['sf_security_hashes'], '48 bindings verify one unique image once per validation batch' );
    $snapshot->article->revision++; $saved = Store::save( $valid = Snapshot::validate( Snapshot::json( $snapshot ) ), (int) Store::read( $id )['version'] );
    if ( is_wp_error( $saved ) ) throw new RuntimeException( $saved->get_error_message() );
    $row = Store::read( $id ); $GLOBALS['sf_security_hashes'] = 0; Review::manifest( $row );
    sfs_check( 1 === $GLOBALS['sf_security_hashes'], 'Review manifest and preview share one verified media batch' );
    $preview = new DOMDocument(); @$preview->loadHTML( Media::preview_html( $row ) ); $urls = array();
    foreach ( $preview->getElementsByTagName( 'img' ) as $image ) $urls[] = $image->getAttribute( 'src' );
    sfs_check( 48 === count( $urls ) && 1 === count( array_unique( $urls ) ), 'Repeated image bindings share one authenticated resource URL' );
    $refs = Media::references( $snapshot );
    $attachment_refs = $refs;
    foreach ( $attachment_refs as &$usage ) $usage['ref'] = $fixture['attachments']['image']; unset( $usage );
    $GLOBALS['sf_security_hashes'] = 0; Media::resolve_batch( $attachment_refs, $id );
    sfs_check( 1 === $GLOBALS['sf_security_hashes'], 'Repeated existing attachments are verified once per batch' );
    $bad = $refs; $last = array_key_last( $bad ); $bad[$last]['type'] = 'link';
    sfs_check( sfs_reject( static fn() => Media::resolve_batch( $bad, $id ) ), 'Warm image verification never authorizes a PDF field' );
    $bad = $refs; $bad[$last]['ref'] = clone $bad[$last]['ref']; $bad[$last]['ref']->sha256 = str_repeat( '0', 64 );
    sfs_check( sfs_reject( static fn() => Media::resolve_batch( $bad, $id ) ), 'Warm verification never accepts a different pinned hash' );
    sfs_check( sfs_reject( static fn() => Media::resolve_batch( $refs, 'different-article' ) ), 'Batch verification remains article-scoped' );
    $path = Assets::directory() . '/' . $fixture['staged']['image']->id . '.bin'; $bytes = file_get_contents( $path );
    try {
        $changed = $bytes; $changed[strlen( $changed ) - 1] = chr( ord( $changed[strlen( $changed ) - 1] ) ^ 1 ); file_put_contents( $path, $changed ); clearstatcache();
        sfs_check( sfs_reject( static fn() => Media::preview_html( $row ) ), 'A later batch detects same-size changed bytes; no stale request cache' );
    } finally { file_put_contents( $path, $bytes ); clearstatcache(); }
    foreach ( array( 'revoke', 'expire' ) as $case ) {
        $key = sfs_key(); $triggered = false;
        $hook = static function( $sql ) use ( &$hook, &$triggered, $key, $case ) {
            global $wpdb;
            if ( str_contains( $sql, $wpdb->prepare( 'SELECT * FROM %i', Assets::table() ) ) ) {
                remove_filter( 'query', $hook ); $triggered = true;
                if ( 'revoke' === $case ) { $wpdb->delete( Connections::table(), array( 'id' => $key ) ); Publications::revoke_connection( $key ); }
                else $wpdb->update( Connections::table(), array( 'expires' => time() - 1 ), array( 'id' => $key ) );
            }
            return $sql;
        };
        add_filter( 'query', $hook );
        $request = new WP_REST_Request( 'POST' ); $request->set_header( 'content-type', 'application/json' );
        $request->set_body( wp_json_encode( array( 'articleId' => $id, 'expectedVersion' => (int) $row['version'], 'expectedPublicationVersion' => 0,
            'requestId' => wp_generate_uuid4(), 'timing' => 'scheduled', 'publishAt' => gmdate( 'Y-m-d\TH:i:s+00:00', time() + 3600 ), 'timeZone' => 'UTC' ) ) );
        $result = Publications::set( $request ); remove_filter( 'query', $hook ); $hook = null;
        $job = Publications::read( $id );
        sfs_check( $triggered && is_wp_error( $result ) && ! $job, $case . ' during manifest work cannot become a session-origin reservation' );
        if ( $job ) { wp_clear_scheduled_hook( Publications::HOOK, array( $id, (int) $job['version'] ) ); $wpdb->delete( Publications::table(), array( 'article_id' => $id ) ); }
        sf_test_end_connection();
    }
    $key = sfs_key(); $result = Publications::set( $request );
    if ( is_wp_error( $result ) ) throw new RuntimeException( $result->get_error_message() );
    $wpdb->update( Publications::table(), array( 'publish_at' => time() - 1 ), array( 'article_id' => $id ) );
    $triggered = false;
    $hook = static function( $sql ) use ( &$hook, &$triggered, $key ) {
        global $wpdb;
        if ( str_contains( $sql, $wpdb->prepare( 'SELECT * FROM %i', Assets::table() ) ) ) {
            remove_filter( 'query', $hook ); $triggered = true;
            $wpdb->delete( Connections::table(), array( 'id' => $key ) ); Publications::revoke_connection( $key );
        }
        return $sql;
    };
    add_filter( 'query', $hook ); Publications::run( $id, $result['version'] ); remove_filter( 'query', $hook ); $hook = null;
    $job = Publications::read( $id );
    sfs_check( $triggered && 'cancelled' === $job['state'] && sfs_held_reservation( $job ), 'Revocation during worker validation keeps the destination reservation inactive and nonpublic' );
    $wpdb->delete( Publications::table(), array( 'article_id' => $id ) );
    sf_test_end_connection();
    $key_a = sfs_key(); $initial = Publications::set( $request );
    if ( is_wp_error( $initial ) ) throw new RuntimeException( $initial->get_error_message() );
    sf_test_end_connection();
    sfs_key(); $triggered = false;
    $replacement = clone $request; $body = json_decode( $replacement->get_body() );
    $body->requestId = wp_generate_uuid4(); $body->expectedPublicationVersion = $initial['version']; $replacement->set_body( wp_json_encode( $body ) );
    $hook = static function( $sql ) use ( &$hook, &$triggered, $key_a ) {
        global $wpdb;
        if ( str_contains( $sql, $wpdb->prepare( 'SELECT * FROM %i', Assets::table() ) ) ) {
            remove_filter( 'query', $hook ); $triggered = true;
            $wpdb->delete( Connections::table(), array( 'id' => $key_a ) ); Publications::revoke_connection( $key_a );
        }
        return $sql;
    };
    add_filter( 'query', $hook ); $result = Publications::set( $replacement ); remove_filter( 'query', $hook ); $hook = null;
    $job = Publications::read( $id );
    sfs_check( $triggered && is_wp_error( $result ) && 409 === ( $result->get_error_data()['status'] ?? 0 ) &&
        'cancelled' === $job['state'] && 2 === (int) $job['version'], 'Another valid key cannot overwrite a cancellation using a stale version' );
    wp_clear_scheduled_hook( Publications::HOOK, array( $id, (int) $job['version'] ) );
    $wpdb->delete( Publications::table(), array( 'article_id' => $id ) );
    $result = Publications::set( $request );
    if ( is_wp_error( $result ) ) throw new RuntimeException( $result->get_error_message() );
    $legacy = array( 'connection_id' => '', 'publish_at' => time() - 1 );
    if ( 2 <= (int) get_option( 'atshde_publications_schema_version' ) ) $legacy['authorization'] = '';
    $wpdb->update( Publications::table(), $legacy, array( 'article_id' => $id ) );
    Publications::run( $id, $result['version'] ); $job = Publications::read( $id );
    sfs_check( 'blocked' === $job['state'] && sfs_held_reservation( $job ), 'Legacy reservations with lost issuer provenance remain inactive and require explicit rescheduling' );
} finally {
    if ( $hook ) remove_filter( 'query', $hook );
    sf_test_end_connection();
    wp_set_current_user( get_user_by( 'login', 'sf_local_admin' )->ID );
    foreach ( $keys as $key ) $wpdb->delete( Connections::table(), array( 'id' => $key ) );
    sf_media_test_cleanup( $id );
}
echo "Security regression checks: $count checks, $failures failures.\n";
exit( $failures ? 1 : 0 );
}
