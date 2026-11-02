<?php
require_once __DIR__ . '/wordpress-connection-context.php';
// WP-CLI eval-file, with both post-type and taxonomy fixtures installed as mu-plugins.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() ||
    ! preg_match( '/^sf_deft_post_types_[a-f0-9]+$/D', DB_NAME ) ) throw new RuntimeException( 'Use a disposable taxonomy test database.' );
use Atshift\Deft\{Connections, Mcp, Publications, Snapshot, Store, Taxonomies};
global $wpdb, $checks;
$checks = 0; $run = wp_generate_uuid4();
function sft_check( $ok, $message ) { global $checks; if ( ! $ok ) throw new RuntimeException( $message ); ++$checks; }
function sft_good( $value ) { if ( is_wp_error( $value ) ) throw new RuntimeException( $value->get_error_message() ); return $value; }
function sft_call( $tool, $args = array() ) { return sft_good( Mcp::execute( $tool, $args ) ); }
function sft_key( $user, $publish ) {
    global $wpdb; $id = wp_generate_uuid4(); $secret = bin2hex( random_bytes( 32 ) );
    $wpdb->insert( Connections::table(), array( 'id' => $id, 'owner_id' => $user, 'name' => 'Disposable taxonomy QA',
        'secret_hash' => hash( 'sha256', $secret ), 'created' => time(), 'expires' => time() + HOUR_IN_SECONDS, 'authoring' => 1, 'publishing' => (int) $publish ) );
    return array( 'token' => 'sfc_' . $id . '_' . $secret, 'route' => $publish ? Connections::PUBLISH_ROUTE : Connections::ROUTE );
}
function sft_connect( $key ) {
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1'; $r = new WP_REST_Request( 'POST', $key['route'] );
    $r->set_body( '{}' ); $r->set_header( 'authorization', 'Bearer ' . $key['token'] );
    sft_good( sf_test_begin_connection( $r ) );
}
function sft_draft() {
    return sft_call( 'save-draft', array( 'expectedVersion' => 0,
        'article' => (object) array( 'id' => 'taxonomy-' . wp_generate_uuid4(), 'revision' => 1, 'title' => 'Taxonomy publication QA', 'blocks' => array() ) ) );
}
function sft_intent( $d, $selection, $type = 'post', $now = true, $version = 0 ) {
    $body = array( 'articleId' => $d['articleId'], 'expectedVersion' => $d['version'], 'expectedPublicationVersion' => $version,
        'requestId' => wp_generate_uuid4(), 'postType' => $type, 'timing' => $now ? 'now' : 'scheduled',
        'publishAt' => $now ? '' : gmdate( 'Y-m-d\TH:i:s+00:00', time() + HOUR_IN_SECONDS ), 'timeZone' => $now ? '' : 'UTC' );
    if ( null !== $selection ) $body['taxonomies'] = $selection;
    return $body;
}
function sft_select( $taxonomy, $ids ) { return array( array( 'taxonomy' => $taxonomy, 'termIds' => $ids ) ); }
function sft_term( $name, $tax, $args = array() ) { return (int) sft_good( wp_insert_term( $name, $tax, $args ) )['term_id']; }
function sft_due( $id ) {
    global $wpdb; $job = Publications::read( $id );
    $wpdb->update( Publications::table(), array( 'publish_at' => time() - 2 ), array( 'article_id' => $id ) );
    Publications::run( $id, (int) $job['version'] ); return sft_good( Publications::state( $id ) );
}
function sft_ids( $post, $tax ) { $ids = sft_good( wp_get_object_terms( $post, $tax, array( 'fields' => 'ids' ) ) ); sort( $ids, SORT_NUMERIC ); return $ids; }

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]->ID;
wp_set_current_user( $admin );
$category = sft_term( 'Announcements ' . $run, 'category' );
$child = sft_term( 'Meetups ' . $run, 'category', array( 'parent' => $category ) );
$tag = sft_term( 'Reading ' . $run, 'post_tag' );
$event = sft_term( 'Community ' . $run, 'sf_event_topic' );
$other = sft_term( 'Other ' . $run, 'sf_event_topic' );
$password = wp_generate_password( 32 );
$user = sft_good( wp_insert_user( array( 'user_login' => 'taxonomy-' . $run, 'user_pass' => $password, 'role' => 'atshde_publisher' ) ) );
$keys = array( 'draft' => sft_key( $user, false ), 'publish' => sft_key( $user, true ) );
sft_connect( $keys['draft'] );
$tax = array_column( sft_call( 'list-taxonomies', array( 'postType' => 'post' ) )['items'], null, 'taxonomy' );
sft_check( array( 'category', 'post_tag' ) === array_keys( $tax ) && ! $tax['category']['accountCanAssign'], 'Discovery reports native assignment rights without changing the DeFt publisher role.' );
sft_check( (int) get_option( 'default_category' ) === $tax['category']['defaultTerm']['id'], 'Discovery explains the ordinary-post default category.' );
sft_check( is_wp_error( Mcp::execute( 'list-terms', array( 'postType' => 'post', 'taxonomy' => 'category' ) ) ), 'Term lookup requires native taxonomy permission.' );
sft_check( is_wp_error( Mcp::execute( 'set-publication', array() ) ), 'Taxonomy discovery does not grant draft connections publication.' );
$actor = get_userdata( $user );
foreach ( array( 'edit_posts', 'edit_pages', 'publish_pages', 'edit_published_pages', 'edit_sf_test_events', 'publish_sf_test_events', 'edit_published_sf_test_events', 'assign_sf_event_terms' ) as $cap ) $actor->add_cap( $cap );
wp_set_current_user( 0 ); sft_connect( $keys['draft'] );
sft_check( ! sft_call( 'context' )['capabilities']['publish'], 'Native taxonomy rights do not broaden connection scope.' );
sft_check( array() === sft_call( 'list-taxonomies', array( 'postType' => 'page' ) )['items'], 'Pages without attached taxonomies are honestly empty.' );
sft_check( array( 'sf_default_topic', 'sf_event_topic' ) === array_column( sft_call( 'list-taxonomies', array( 'postType' => 'sf_test_event' ) )['items'], 'taxonomy' ), 'Private, nonqueryable, detached and internal taxonomies are excluded.' );
$found = sft_call( 'list-terms', array( 'postType' => 'post', 'taxonomy' => 'category', 'search' => $run ) );
sft_check( array( $category, $child ) === array_column( $found['items'], 'id' ) && $category === $found['items'][1]['parent'], 'Lookup includes unused terms and hierarchy with integer IDs.' );
for ( $i = 0; $i < 52; ++$i ) sft_term( 'Pagination-' . $run . '-' . $i, 'post_tag' );
$page1 = sft_call( 'list-terms', array( 'postType' => 'post', 'taxonomy' => 'post_tag', 'search' => 'Pagination-' . $run ) );
$page2 = sft_call( 'list-terms', array( 'postType' => 'post', 'taxonomy' => 'post_tag', 'search' => 'Pagination-' . $run, 'page' => 2 ) );
sft_check( count( $page1['items'] ) === 50 && $page1['nextPage'] === 2 && count( $page2['items'] ) === 2 && ! $page2['hasMore'] && ! array_intersect( array_column( $page1['items'], 'id' ), array_column( $page2['items'], 'id' ) ), 'Term search is bounded and paginated without overlaps.' );
foreach ( array( array( 'postType' => 'atshde_article' ), array( 'postType' => '../post' ), array( 'postType' => 'post', 'extra' => true ) ) as $bad ) sft_check( is_wp_error( Mcp::execute( 'list-taxonomies', $bad ) ), 'Invalid taxonomy discovery inputs denied.' );
foreach ( array( array( 'taxonomy' => 'sf_event_topic' ), array( 'taxonomy' => 'post_tag', 'page' => 0 ), array( 'taxonomy' => 'post_tag', 'page' => '1' ), array( 'taxonomy' => 'post_tag', 'search' => str_repeat( 'a', 201 ) ) ) as $bad ) sft_check( is_wp_error( Mcp::execute( 'list-terms', $bad + array( 'postType' => 'post' ) ) ), 'Invalid term discovery inputs denied.' );
wp_set_current_user( 0 ); sft_check( is_wp_error( Taxonomies::listing( array( 'postType' => 'post' ) ) ), 'Anonymous taxonomy discovery denied.' ); sft_connect( $keys['publish'] );
$d = sft_draft(); $before = Store::read( $d['articleId'] );
$selection = array_merge( sft_select( 'category', array( $child, $category ) ), sft_select( 'post_tag', array( $tag ) ) );
$intent = sft_intent( $d, $selection ); $published = sft_call( 'set-publication', $intent );
sft_check( 'published' === $published['state'] && array( $category, $child ) === sft_ids( $published['postId'], 'category' ) && array( $tag ) === sft_ids( $published['postId'], 'post_tag' ), 'Post is published with the exact selected categories and tag.' );
sft_check( $published['postId'] === sft_call( 'set-publication', $intent )['postId'], 'Identical requests are idempotent.' );
sft_check( is_wp_error( Mcp::execute( 'set-publication', array_merge( $intent, array( 'taxonomies' => array() ) ) ) ), 'Changed selection cannot reuse an existing request ID.' );
sft_check( $before['digest'] === Store::read( $d['articleId'] )['digest'] && $before['payload'] === Store::read( $d['articleId'] )['payload'], 'Classification leaves private canonical draft untouched.' );
sft_check( $published['manifest']->taxonomies[0]->termIds === array( $category, $child ) && $published['taxonomyDetails'][1]['terms'][0]['id'] === $tag, 'Selected IDs and names are pinned and shown in status.' );
$event_result = sft_call( 'set-publication', sft_intent( sft_draft(), sft_select( 'sf_event_topic', array( $event ) ), 'sf_test_event' ) );
sft_check( 'published' === $event_result['state'] && array( $event ) === sft_ids( $event_result['postId'], 'sf_event_topic' ), 'Custom destination gets its own registered taxonomy.' );
$page_result = sft_call( 'set-publication', sft_intent( sft_draft(), array(), 'page' ) );
sft_check( 'published' === $page_result['state'] && array() === $page_result['taxonomies'], 'Page with no classification can publish.' );
$empty_tags = sft_call( 'set-publication', sft_intent( sft_draft(), sft_select( 'post_tag', array() ) ) );
sft_check( 'published' === $empty_tags['state'] && array() === sft_ids( $empty_tags['postId'], 'post_tag' ), 'Explicit empty tags are supported.' );
$legacy = sft_call( 'set-publication', sft_intent( sft_draft(), null ) );
sft_check( 'published' === $legacy['state'] && null === $legacy['taxonomies'] && array( (int) get_option( 'default_category' ) ) === sft_ids( $legacy['postId'], 'category' ), 'Omitted classification preserves WordPress default category and legacy manifest.' );
$count_before = (int) wp_count_terms( array( 'taxonomy' => 'post_tag', 'hide_empty' => false ) );
$d = sft_draft();
$bad_selections = array( null, 'tag-name', (object) array(), sft_select( 'post_tag', array( 'New unwanted tag' ) ), sft_select( 'post_tag', array( (string) $tag ) ),
    sft_select( 'post_tag', array( 0 ) ), sft_select( 'post_tag', array( 2147483647 ) ), sft_select( 'post_tag', array( $category ) ),
    sft_select( 'post_tag', array( $tag, $tag ) ), sft_select( 'post_tag', range( 1, 21 ) ), sft_select( 'category', array() ),
    sft_select( 'sf_event_topic', array( $event ) ), array_merge( sft_select( 'post_tag', array() ), sft_select( 'post_tag', array() ) ),
    array( array( 'taxonomy' => 'post_tag', 'termIds' => array(), 'create' => 'new' ) ), array_fill( 0, 9, array( 'taxonomy' => 'post_tag', 'termIds' => array() ) ) );
foreach ( $bad_selections as $bad ) {
    $input = sft_intent( $d, array() ); $input['taxonomies'] = $bad;
    sft_check( is_wp_error( Mcp::execute( 'set-publication', $input ) ) && ! Publications::read( $d['articleId'] ), 'Malformed, unsupported, missing or duplicate terms cannot create a reservation: ' . Snapshot::json( $bad ) );
}
sft_check( $count_before === (int) wp_count_terms( array( 'taxonomy' => 'post_tag', 'hide_empty' => false ) ), 'Invalid strings never create terms.' );
sft_check( is_wp_error( Mcp::execute( 'set-publication', sft_intent( $d, sft_select( 'category', array( $category ) ), 'page' ) ) ), 'Post category cannot leak into page destination.' );
sft_check( is_wp_error( Mcp::execute( 'set-publication', sft_intent( $d, sft_select( 'sf_default_topic', array() ), 'sf_test_event' ) ) ), 'Default-backed taxonomy cannot promise an empty selection.' );
$deny = static function( $caps, $cap, $uid, $args ) use ( $tag ) { return 'assign_term' === $cap && ( $args[0] ?? 0 ) === $tag ? array( 'do_not_allow' ) : $caps; };
add_filter( 'map_meta_cap', $deny, 100, 4 );
sft_check( ! sft_call( 'list-terms', array( 'postType' => 'post', 'taxonomy' => 'post_tag', 'search' => 'Reading ' . $run ) )['items'][0]['accountCanAssign'], 'Per-term capability filters are reported.' );
sft_check( is_wp_error( Mcp::execute( 'set-publication', sft_intent( $d, sft_select( 'post_tag', array( $tag ) ) ) ) ), 'Per-term capability filters block assignment.' );
remove_filter( 'map_meta_cap', $deny, 100 );
$bounded = array();
for ( $i = 0; $i < 6; ++$i ) {
    $tax_name = 'sf_limit_' . $i; register_taxonomy( $tax_name, 'post', array( 'public' => true ) ); $ids = array();
    for ( $j = 0; $j < 20; ++$j ) $ids[] = sft_term( 'Limit ' . $run . '-' . $j, $tax_name );
    $bounded[] = array( 'taxonomy' => $tax_name, 'termIds' => $ids );
}
$limit_draft = sft_draft();
sft_check( is_wp_error( Mcp::execute( 'set-publication', sft_intent( $limit_draft, $bounded ) ) ) && ! Publications::read( $limit_draft['articleId'] ), 'Total term limit blocks more than 100 even within per-taxonomy bounds.' );
$bounded = array_slice( $bounded, 0, 5 );
$limit_result = sft_call( 'set-publication', sft_intent( $limit_draft, $bounded ) );
sft_check( 'published' === $limit_result['state'] && 100 === array_sum( array_map( static function( $item ) { return count( $item['termIds'] ); }, $limit_result['taxonomies'] ) ), 'Exactly 100 valid terms can publish without silent truncation.' );
for ( $i = 0; $i < 6; ++$i ) unregister_taxonomy( 'sf_limit_' . $i );
$d = sft_draft(); $reserved = sft_call( 'set-publication', sft_intent( $d, sft_select( 'sf_event_topic', array( $event ) ), 'sf_test_event', false ) );
$replaced = sft_call( 'set-publication', sft_intent( $d, sft_select( 'sf_event_topic', array( $other ) ), 'sf_test_event', false, 1 ) );
Publications::run( $d['articleId'], 1 ); sft_check( in_array( Publications::state( $d['articleId'] )['state'], array( 'scheduled', 'overdue' ), true ) && 'atshde_reserved' === get_post_status( Publications::state( $d['articleId'] )['postId'] ), 'Old reservation worker cannot publish replaced terms.' );
$replaced = sft_due( $d['articleId'] );
sft_check( 'published' === $replaced['state'] && array( $other ) === sft_ids( $replaced['postId'], 'sf_event_topic' ), 'Rescheduling uses only the new selected terms.' );
foreach ( array( 'delete', 'rename', 'slug', 'parent', 'detached', 'unregistered', 'private', 'capability' ) as $case ) {
    $term = sft_term( 'Reserved ' . $case . ' ' . $run, 'sf_event_topic' ); $d = sft_draft();
    sft_call( 'set-publication', sft_intent( $d, sft_select( 'sf_event_topic', array( $term ) ), 'sf_test_event', false ) );
    if ( 'delete' === $case ) wp_delete_term( $term, 'sf_event_topic' );
    elseif ( 'rename' === $case ) wp_update_term( $term, 'sf_event_topic', array( 'name' => 'Changed name ' . $run ) );
    elseif ( 'slug' === $case ) wp_update_term( $term, 'sf_event_topic', array( 'slug' => 'changed-' . $run ) );
    elseif ( 'parent' === $case ) wp_update_term( $term, 'sf_event_topic', array( 'parent' => $event ) );
    elseif ( 'detached' === $case ) unregister_taxonomy_for_object_type( 'sf_event_topic', 'sf_test_event' );
    elseif ( 'unregistered' === $case ) unregister_taxonomy( 'sf_event_topic' );
    elseif ( 'private' === $case ) get_taxonomy( 'sf_event_topic' )->public = false;
    else { $actor->remove_cap( 'assign_sf_event_terms' ); wp_set_current_user( 0 ); sft_connect( $keys['publish'] ); }
    $state = sft_due( $d['articleId'] );
    sft_check( 'blocked' === $state['state'] && 'publish' !== get_post_status( $state['postId'] ), 'Changed taxonomy blocks due job without silently dropping selection: ' . $case );
    sf_test_register_taxonomies(); $actor->add_cap( 'assign_sf_event_terms' ); wp_set_current_user( 0 ); sft_connect( $keys['publish'] );
}
$d = sft_draft(); sft_call( 'set-publication', sft_intent( $d, sft_select( 'post_tag', array( $tag ) ), 'post', false ) );
$job = Publications::read( $d['articleId'] ); $tampered = json_decode( $job['manifest'], true ); $tampered['taxonomies'][0]['termIds'] = array(); $tampered['taxonomies'][0]['terms'] = array();
$wpdb->update( Publications::table(), array( 'manifest' => Snapshot::json( $tampered ) ), array( 'article_id' => $d['articleId'] ) );
sft_check( 'blocked' === sft_due( $d['articleId'] )['state'], 'Manifest selection tampering is caught by the stored hash.' );
foreach ( array( 'assignment', 'publication' ) as $stage ) {
    $d = sft_draft();
    $mutate = static function( $post_id, $terms, $tt_ids, $tax ) use ( &$mutate, $other ) {
        if ( 'sf_event_topic' !== $tax ) return;
        remove_action( 'set_object_terms', $mutate, 100 ); wp_set_object_terms( $post_id, array( $other ), $tax );
    };
    $transition = static function( $new, $old, $post ) use ( $other ) { if ( 'publish' === $new && 'sf_test_event' === $post->post_type ) wp_set_object_terms( $post->ID, array( $other ), 'sf_event_topic' ); };
    if ( 'assignment' === $stage ) add_action( 'set_object_terms', $mutate, 100, 4 ); else add_action( 'transition_post_status', $transition, 100, 3 );
    $state = sft_call( 'set-publication', sft_intent( $d, sft_select( 'sf_event_topic', array( $event ) ), 'sf_test_event' ) );
    remove_action( 'set_object_terms', $mutate, 100 ); remove_action( 'transition_post_status', $transition, 100 );
    sft_check( 'blocked' === $state['state'] && 'draft' === get_post_status( $state['postId'] ), 'Unexpected term mutation rolls back public status: ' . $stage . ' ' . $state['error'] );
}
foreach ( array( 'list-taxonomies', 'list-terms' ) as $tool ) {
    $ability = wp_get_ability( 'structured-fields/' . $tool );
    sft_check( $ability && ! $ability->get_meta()['show_in_rest'] && ! $ability->get_meta()['mcp']['public'], 'Taxonomy tool is not exposed globally.' );
}
$out = getenv( 'SF_TAXONOMIES_ARTIFACT_DIR' );
if ( $out && is_dir( $out ) ) {
    file_put_contents( $out . '/taxonomies-fixture.json', Snapshot::json( array( 'site' => home_url(), 'user' => $user, 'keys' => $keys,
        'login' => array( 'username' => 'taxonomy-' . $run, 'password' => $password ),
        'category' => $category, 'child' => $child, 'tag' => $tag, 'event' => $event, 'published' => $published, 'eventPublished' => $event_result ) ) );
    chmod( $out . '/taxonomies-fixture.json', 0600 );
}
echo Snapshot::json( array( 'taxonomy_checks' => $checks, 'postUrl' => $published['url'], 'eventUrl' => $event_result['url'] ) ), PHP_EOL;
