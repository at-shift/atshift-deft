<?php
// Run only against the installed ZIP in the disposable destination test environment.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() ||
    ! preg_match( '/^sf_deft_post_types_[a-f0-9]+$/D', DB_NAME ) ) throw new RuntimeException( 'Use a disposable test database.' );
use StructuredFields\{Connections, Mcp, Publications, PublicStructure, Snapshot, Store};
global $wpdb, $checks;
$checks = 0;
function sfj_check( $ok, $message ) { global $checks; if ( ! $ok ) throw new RuntimeException( $message ); ++$checks; }
function sfj_good( $value ) { if ( is_wp_error( $value ) ) throw new RuntimeException( $value->get_error_message() ); return $value; }
function sfj_http( $id ) { return rest_do_request( new WP_REST_Request( 'GET', '/structured-fields/v1/public-structure/' . $id ) ); }
function sfj_flat( $nodes ) {
    $result = array(); foreach ( $nodes as $node ) { $result[] = $node; $result = array_merge( $result, sfj_flat( $node['children'] ?? array() ) ); } return $result;
}
$owner = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]->ID;
$actor = get_userdata( $owner );
foreach ( array( 'edit_sf_test_events', 'publish_sf_test_events', 'edit_published_sf_test_events' ) as $cap ) $actor->add_cap( $cap );
$key = wp_generate_uuid4(); $secret = bin2hex( random_bytes( 32 ) );
$wpdb->insert( Connections::table(), array( 'id' => $key, 'owner_id' => $owner, 'name' => 'Disposable structure QA', 'secret_hash' => hash( 'sha256', $secret ),
    'created' => time(), 'expires' => time() + HOUR_IN_SECONDS, 'authoring' => 1, 'publishing' => 1 ) );
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$r = new WP_REST_Request( 'POST', Connections::PUBLISH_ROUTE ); $r->set_header( 'authorization', 'Bearer sfc_' . $key . '_' . $secret ); $r->set_body( '{}' );
sfj_good( Connections::guard( null, rest_get_server(), $r ) );
$published = array();
foreach ( array( 'post', 'page', 'sf_test_event' ) as $type ) {
    $sample = json_decode( file_get_contents( WP_PLUGIN_DIR . '/atshift-deft/sample.json' ) );
    $sample->schemaVersion = 2;
    $sample->article->id = 'json-' . wp_generate_uuid4();
    $sample->article->title = 'Public structure 日本語 & "quotes"';
    $saved = sfj_good( Store::save( Snapshot::validate( Snapshot::json( $sample ) ), 0 ) );
    $result = sfj_good( Mcp::execute( 'set-publication', array( 'articleId' => $saved['articleId'], 'expectedVersion' => $saved['version'], 'expectedPublicationVersion' => 0,
        'requestId' => wp_generate_uuid4(), 'postType' => $type, 'timing' => 'now', 'publishAt' => '', 'timeZone' => '' ) ) );
    sfj_check( 'published' === $result['state'], 'Real publication succeeds: ' . $type );
    $published[ $type ] = $result;
    $data = PublicStructure::read( $result['postId'] );
    sfj_check( $data['postType'] === $type && 1 === $data['schemaVersion'], 'Structure uses actual published destination.' );
    sfj_check( ( 'post' === $type ? 'BlogPosting' : 'WebPage' ) === PublicStructure::linked_data( $data )['@type'], 'Conservative JSON-LD type.' );
    $encoded = wp_json_encode( $data );
    foreach ( array( 'componentRevision', 'componentId', 'intro-one', 'Article introduction', 'secret_hash', $secret, 'owner_id', 'payload' ) as $private ) sfj_check( ! str_contains( $encoded, $private ), 'No internal field: ' . $private );
    sfj_check( str_contains( $encoded, 'A new way to compose' ), 'Rendered public text is present.' );
}
$p = $published['post']['postId']; $original = get_post( $p )->post_content;
$body = '<div class="sf-article"><div class="sf-block" data-sf-block="secret-block"><section class="sf-built-columns secret-class"><h2>見出し &amp; text</h2><p>Hello <strong>world</strong> <a href="https://example.org/public">link</a></p></section>' .
    '<details><summary>Question</summary><p>Answer</p></details><ul><li>One</li></ul><img src="https://example.org/a.jpg" alt="Public image">' .
    '<p hidden>hidden-secret</p><p aria-hidden="true">aria-secret</p><p style="display:none">style-secret</p><script>script-secret</script><style>css-secret</style><form>form-secret</form><!-- comment-secret -->' .
    '<a href="javascript:alert(1)" onclick="secret-handler">Unsafe URL</a><a href="https://user:password@example.org">Credentials</a><a href="/public">Relative</a><table><tr><td colspan="2">Cell</td></tr></table></div></div>';
wp_update_post( wp_slash( array( 'ID' => $p, 'post_content' => $body ) ) );
$projection = PublicStructure::project( $body ); $flat = sfj_flat( $projection ); $encoded = wp_json_encode( $projection );
foreach ( array( 'secret', 'javascript:', 'password', 'onclick', 'data-sf-block' ) as $private ) sfj_check( ! str_contains( $encoded, $private ), 'Projection drops non-content: ' . $private );
sfj_check( in_array( 'columns', array_column( $flat, 'layout' ), true ), 'Built column marker survives without CSS/class names.' );
sfj_check( in_array( 'disclosure', array_column( $flat, 'type' ), true ) && str_contains( $encoded, 'Answer' ), 'Reader-expandable answer is retained.' );
sfj_check( in_array( 2, array_column( $flat, 'level' ), true ) && in_array( 2, array_column( $flat, 'colspan' ), true ), 'Headings and table spans are typed.' );
sfj_check( in_array( '/public', array_column( $flat, 'url' ), true ), 'Public relative links are retained.' );
sfj_check( str_contains( implode( '', array_column( $flat, 'text' ) ), 'world link' ), 'Whitespace between inline elements is not lost.' );
sfj_check( null === PublicStructure::project( '<div>Outside</div>' ), 'Non-DEFT body rejected.' );
sfj_check( null === PublicStructure::project( '<div class="sf-article"></div><div class="sf-article"></div>' ), 'Ambiguous roots rejected.' );
sfj_check( null === PublicStructure::project( '<div class="sf-article">' . str_repeat( '<div>', 66 ) . 'deep' . str_repeat( '</div>', 66 ) . '</div>' ), 'Deep input fails closed.' );
sfj_check( null === PublicStructure::project( '<div class="sf-article">' . str_repeat( '<br>', 10001 ) . '</div>' ), 'Node budget fails closed.' );
sfj_check( null === PublicStructure::project( str_repeat( 'a', 4 * 1024 * 1024 + 1 ) ), 'Size budget fails closed.' );
wp_set_current_user( 0 );
sfj_check( 200 === sfj_http( $p )->get_status(), 'Anonymous published structure is available without credentials.' );
sfj_check( str_contains( sfj_http( $p )->get_headers()['Cache-Control'], 'no-store' ), 'Successful response is not cached.' );
sfj_check( str_contains( wp_json_encode( PublicStructure::read( $p ) ), 'Hello' ) && ! str_contains( wp_json_encode( PublicStructure::read( $p ) ), 'A new way to compose' ), 'Projection follows current public body, not private snapshot.' );
$missing = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Not a DEFT publication', 'post_content' => $body ) );
sfj_check( 404 === sfj_http( $missing )->get_status(), 'Ordinary unrelated post is not exposed.' );
update_post_meta( $missing, '_sf_publication', get_post_meta( $p, '_sf_publication', true ) );
sfj_check( 404 === sfj_http( $missing )->get_status(), 'Copied publication marker cannot expose a different post.' );
sfj_check( 404 === sfj_http( 2147483647 )->get_status(), 'Unknown ID is uniformly missing.' );
$private_ids = array( $missing );
foreach ( array( 'draft', 'private', 'future', 'trash' ) as $status ) {
    $date = gmdate( 'Y-m-d H:i:s', time() + ( 'future' === $status ? DAY_IN_SECONDS : -HOUR_IN_SECONDS ) );
    wp_update_post( array( 'ID' => $p, 'post_status' => $status, 'post_date' => $date, 'post_date_gmt' => $date ) );
    sfj_check( 404 === sfj_http( $p )->get_status(), 'Anonymous cannot read status ' . $status );
    wp_set_current_user( $owner ); sfj_check( 404 === sfj_http( $p )->get_status(), 'Owner cannot bypass public status ' . $status ); wp_set_current_user( 0 );
}
wp_update_post( array( 'ID' => $p, 'post_status' => 'publish', 'post_password' => 'private' ) );
sfj_check( 404 === sfj_http( $p )->get_status(), 'Password protected posts are excluded even if published.' );
sfj_check( str_contains( sfj_http( $p )->get_headers()['Cache-Control'], 'no-store' ), 'Missing response is not cached.' );
wp_update_post( array( 'ID' => $p, 'post_password' => '' ) );
add_filter( 'atshift_deft_public_structure_enabled', '__return_false' );
sfj_check( 404 === sfj_http( $p )->get_status(), 'Access-control integration can veto all output.' );
remove_filter( 'atshift_deft_public_structure_enabled', '__return_false' );
wp_update_post( array( 'ID' => $p, 'post_author' => 999999 ) );
sfj_check( 404 === sfj_http( $p )->get_status(), 'Changed ownership breaks publication binding.' );
wp_update_post( array( 'ID' => $p, 'post_author' => $owner, 'post_type' => 'page' ) );
sfj_check( 404 === sfj_http( $p )->get_status(), 'Changed destination breaks publication binding.' );
wp_update_post( array( 'ID' => $p, 'post_type' => 'post' ) );
$job = Publications::read( $published['post']['articleId'] );
$wpdb->update( Publications::table(), array( 'state' => 'blocked' ), array( 'article_id' => $job['article_id'] ) );
sfj_check( 404 === sfj_http( $p )->get_status(), 'Job must still be published.' );
$wpdb->update( Publications::table(), array( 'state' => 'published' ), array( 'article_id' => $job['article_id'] ) );
$global_query = new WP_Query( array( 'p' => $p ) ); $GLOBALS['wp_query'] = $global_query;
$post = get_post( $p ); $post->post_title = '&lt;/script&gt;&lt;script&gt;alert("test")&lt;/script&gt;';
wp_cache_set( $p, $post, 'posts' );
ob_start(); PublicStructure::head(); $head = ob_get_clean();
sfj_check( 1 === substr_count( $head, '<script ' ) && 1 === substr_count( $head, '</script>' ) && ! str_contains( $head, '<script>alert' ), 'Inline JSON cannot break out of its script element.' );
sfj_check( str_contains( $head, 'rel="alternate"' ) && str_contains( $head, 'public-structure/' . $p ), 'Public page discovers structure URL.' );
add_filter( 'atshift_deft_json_ld_enabled', '__return_false' );
ob_start(); PublicStructure::head(); $head = ob_get_clean();
sfj_check( ! str_contains( $head, '<script' ) && str_contains( $head, 'rel="alternate"' ), 'SEO opt-out preserves structure discovery.' );
remove_filter( 'atshift_deft_json_ld_enabled', '__return_false' ); clean_post_cache( $p );
$out = getenv( 'SF_PUBLIC_STRUCTURE_ARTIFACT_DIR' );
if ( $out ) {
    $fixture = array( 'url' => home_url(), 'postId' => $p, 'pageId' => $published['page']['postId'], 'customId' => $published['sf_test_event']['postId'], 'missingIds' => $private_ids );
    file_put_contents( $out . '/public-structure-fixture.json', wp_json_encode( $fixture ) );
}
$wpdb->delete( Connections::table(), array( 'id' => $key ) );
echo "Public structure: $checks checks passed.\n";
