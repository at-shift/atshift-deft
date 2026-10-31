<?php
require dirname( __DIR__ ) . '/.local/wordpress/wp-load.php';
use StructuredFields\Snapshot;
use StructuredFields\Store;
if ( DB_NAME !== 'sf_prototype_local' || home_url() !== 'http://127.0.0.1:8128' ) throw new RuntimeException( 'Tests require the isolated local site.' );
$count = 0;
function check( $pass, $message ) {
    global $count;
    if ( ! $pass ) throw new RuntimeException( $message );
    echo 'PASS ' . ++$count . ': ' . $message . "\n";
}
function fixture() {
    $value = json_decode( file_get_contents( dirname( __DIR__ ) . '/wordpress/atshift-deft/sample.json' ) );
    $value->article->id = 'test-' . wp_generate_uuid4();
    return $value;
}
function rehash( $value ) {
    foreach ( $value->components as $c ) {
        $copy = clone $c; unset( $copy->revision );
        $c->revision = hash( 'sha256', Snapshot::json( $copy ) );
        foreach ( $value->article->blocks as $b ) if ( $b->componentId === $c->id ) $b->componentRevision = $c->revision;
    }
    return $value;
}
function rejected( $value, $message ) {
    try { Snapshot::validate( Snapshot::json( $value ) ); } catch ( Throwable $error ) { check( true, $message ); return; }
    check( false, $message );
}
function api( $method, $path, $body = null ) {
    $req = new WP_REST_Request( $method, '/structured-fields/v1/' . $path );
    if ( null !== $body ) { $req->set_header( 'content-type', 'application/json' ); $req->set_body( Snapshot::json( $body ) ); }
    return rest_do_request( $req );
}
$admin = get_user_by( 'login', 'sf_local_admin' );
wp_set_current_user( $admin->ID );
$sample = fixture();
$ids = array( $sample->article->id );
$limited = 0;
try {
    $validated = Snapshot::validate( Snapshot::json( $sample ) );
    check( str_contains( $validated['html'], 'A new way to compose' ), 'PHP accepts browser SHA-256 component and renders it' );
    $reordered = json_decode( Snapshot::json( $sample ) );
    $reordered->components[0] = (object) array_reverse( get_object_vars( $reordered->components[0] ), true );
    $reordered->article = (object) array_reverse( get_object_vars( $reordered->article ), true );
    check( Snapshot::validate( Snapshot::json( $reordered ) )['digest'] === $validated['digest'], 'JSON property order does not change the saved identity' );
    $media = fixture(); $c = $media->components[0];
    $c->html = '<section><img data-field="image" src="/assets/atelier.png" alt="Atelier"><a data-field="link" href="https://example.org/">Visit</a></section>';
    $c->fields = array(
        (object) array( 'id'=>'image', 'key'=>'image', 'label'=>'Image', 'type'=>'image', 'required'=>true, 'value'=>'/assets/atelier.png', 'alt'=>'Atelier' ),
        (object) array( 'id'=>'link', 'key'=>'link', 'label'=>'Link', 'type'=>'link', 'required'=>true, 'value'=>'https://example.org/', 'alt'=>'Visit' ),
    );
    $media->article->blocks[0]->values = (object) array( 'image'=>(object)array('value'=>'/assets/atelier.png','alt'=>'A room'), 'link'=>(object)array('value'=>'https://example.org/?a=1&b=2','alt'=>'Read more') );
    $media = rehash( $media );
    $rendered = Snapshot::validate( Snapshot::json( $media ) )['html'];
    check( str_contains( $rendered, 'alt="A room"' ) && str_contains( $rendered, '>Read more</a>' ), 'Image and link values render with contextual text' );
    $media->article->blocks[0]->values->link->value = 'javascript:alert(1)'; rejected( $media, 'Reject executable field URL' );
    $media->article->blocks[0]->values->link->value = 'https://example.org/';
    $media->article->blocks[0]->values->image->alt = ''; rejected( $media, 'Reject missing image alternative text' );
    $bad = clone $sample; $bad->publishAuthorized = true; rejected( $bad, 'Reject unknown authorization claims' );
    $bad = fixture(); $bad->article->revision = '1'; rejected( $bad, 'Reject coerced revision type' );
    $bad = fixture(); $bad->components[0]->html .= '<script>alert(1)</script>'; rejected( rehash( $bad ), 'Reject executable markup even with a valid hash' );
    $bad = fixture(); $bad->components[0]->html = str_replace( '<section ', '<section onclick="alert(1)" ', $bad->components[0]->html ); rejected( rehash( $bad ), 'Reject event handlers' );
    $bad = fixture(); $bad->components[0]->html = str_replace( '<h2 ', '<h2 id="collision" ', $bad->components[0]->html ); rejected( rehash( $bad ), 'Reject reusable HTML IDs' );
    $bad = fixture(); $bad->components[0]->name = 'Tampered'; rejected( $bad, 'Reject component hash mismatch' );
    $bad = fixture(); $bad->article->blocks[] = clone $bad->article->blocks[0]; rejected( $bad, 'Reject duplicate block IDs' );
    $bad = fixture(); $bad->article->blocks[0]->variantId = 'unknown'; rejected( $bad, 'Reject unknown variant' );
    $bad = fixture(); unset( $bad->article->blocks[0]->values->heading ); rejected( $bad, 'Reject missing field values' );
    $bad = fixture(); $bad->article->blocks[0]->values->heading->value = ''; rejected( $bad, 'Reject empty required field' );
    $bad = fixture(); $bad->components[0]->css = '@import url(https://example.org/style.css);'; rejected( rehash( $bad ), 'Reject external CSS import' );
    $plain = fixture(); $plain->article->blocks[0]->values->body->value = '<img src=x onerror=alert(1)> [secret]';
    $html = Snapshot::validate( Snapshot::json( $plain ) )['html'];
    check( str_contains( $html, '&lt;img' ) && ! str_contains( $html, '<img' ), 'Field strings remain plain text' );
    $first = api( 'POST', 'articles', array( 'snapshot' => $sample, 'expectedVersion' => 0 ) );
    check( 200 === $first->get_status(), 'REST saves draft' );
    $saved = $first->get_data();
    check( 1 === $saved['version'] && false === $saved['publishAuthorized'], 'Save returns version, never publication authorization' );
    check( get_post_status( $saved['postId'] ) === 'draft', 'Preview post is draft only' );
    check( ! is_post_type_viewable( 'sf_article' ) && is_post_type_viewable( 'post' ), 'Private previews are not advertised as public content and ordinary posts stay viewable' );
    check( get_post_type_object( 'sf_article' )->publicly_queryable && str_contains( Store::preview_url( Store::read( $sample->article->id ) ), 'preview=true' ), 'Private preview keeps its explicit authorized query route' );
    $read = api( 'GET', 'articles/' . $sample->article->id );
    check( $read->get_data()['snapshot'] == $sample, 'Round-trip preserves canonical article and components' );
    $same = api( 'POST', 'articles', array( 'snapshot' => $sample, 'expectedVersion' => 1 ) )->get_data();
    check( $same['version'] === 1, 'Identical save is idempotent' );
    check( 409 === api( 'POST', 'articles', array( 'snapshot' => $sample, 'expectedVersion' => 0 ) )->get_status(), 'Reject stale expected version' );
    $sample->article->title = 'Revised';
    check( 409 === api( 'POST', 'articles', array( 'snapshot' => $sample, 'expectedVersion' => 1 ) )->get_status(), 'Reject replacing contents at the same article revision' );
    $sample->article->revision++;
    $second = api( 'POST', 'articles', array( 'snapshot' => $sample, 'expectedVersion' => 1 ) )->get_data();
    check( $second['version'] === 2 && $second['postId'] !== $saved['postId'], 'New version gets an immutable separate preview draft' );
    check( json_decode( Store::read( $sample->article->id, 1 )['payload'] )->article->title !== 'Revised', 'Older saved version is retained' );
    check( 400 === api( 'POST', 'articles', array( 'snapshot' => $sample, 'expectedVersion' => 2, 'publish' => true ) )->get_status(), 'Reject unknown write envelope flags' );
    check( 404 === api( 'POST', 'publish', array() )->get_status(), 'No publication endpoint exists' );
    wp_update_post( array( 'ID' => $saved['postId'], 'post_status' => 'publish' ) );
    check( 'draft' === get_post_status( $saved['postId'] ), 'Preview type cannot be published through wp_update_post' );
    wp_set_current_user( 0 );
    check( 403 === api( 'GET', 'articles' )->get_status(), 'Anonymous cannot list drafts' );
    check( 403 === api( 'GET', 'articles/' . $sample->article->id )->get_status(), 'Anonymous cannot read a known article ID' );
    check( 403 === api( 'POST', 'articles', array( 'snapshot' => $sample, 'expectedVersion' => 2 ) )->get_status(), 'Anonymous cannot save drafts' );
    $limited = wp_insert_user( array( 'user_login' => 'sf-test-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password(), 'role' => 'editor' ) );
    wp_set_current_user( $limited );
    check( 403 === api( 'GET', 'articles' )->get_status(), 'Editor cannot import administrator-owned markup and CSS' );
    echo "WordPress integration: $count passed.\n";
} finally {
    wp_set_current_user( $admin->ID );
    global $wpdb;
    $table = Store::table();
    foreach ( $ids as $id ) {
        foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM $table WHERE article_id=%s", $id ) ) as $post ) wp_delete_post( $post, true );
        $wpdb->delete( $table, array( 'article_id' => $id ) );
    }
    if ( $limited && ! is_wp_error( $limited ) ) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $limited ); }
}
