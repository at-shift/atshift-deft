<?php
// wp eval-file tests/wordpress-post-types.php --path=<disposable WordPress>
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() ||
    ! preg_match( '/^sf_deft_(?:post_types|appearance)_[a-f0-9]+$/D', DB_NAME ) ) throw new RuntimeException( 'Use a disposable local database.' );
if ( ! function_exists( 'sf_test_register_post_types' ) ) require __DIR__ . '/wordpress-post-types-fixture.php';
sf_test_register_post_types();

use StructuredFields\{Access, Assets, Behaviors, Catalog, Connections, Mcp, PostTypes, Publications, PublicationMedia, Review, Snapshot, Store};

global $component, $checks, $wpdb;
$checks = 0; $run = wp_generate_uuid4();
function sfpt_check( $ok, $message ) {
    global $checks; if ( ! $ok ) throw new RuntimeException( $message ); ++$checks;
}
function sfpt_good( $result ) {
    if ( is_wp_error( $result ) ) throw new RuntimeException( $result->get_error_code() . ': ' . $result->get_error_message() );
    return $result;
}
function sfpt_request( $body ) {
    $r = new WP_REST_Request( 'POST' ); $r->set_header( 'content-type', 'application/json' ); $r->set_body( Snapshot::json( $body ) ); return $r;
}
function sfpt_key( $user, $publishing ) {
    global $wpdb; $id = wp_generate_uuid4(); $secret = bin2hex( random_bytes( 32 ) );
    $wpdb->insert( Connections::table(), array( 'id' => $id, 'owner_id' => $user, 'name' => 'Disposable post-type test',
        'secret_hash' => hash( 'sha256', $secret ), 'created' => time(), 'expires' => time() + HOUR_IN_SECONDS,
        'authoring' => 1, 'publishing' => (int) $publishing ) );
    return array( 'token' => 'sfc_' . $id . '_' . $secret, 'route' => $publishing ? Connections::PUBLISH_ROUTE : Connections::ROUTE );
}
function sfpt_connect( $key ) {
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1'; $r = new WP_REST_Request( 'POST', $key['route'] );
    $r->set_body( '{}' ); $r->set_header( 'authorization', 'Bearer ' . $key['token'] );
    sfpt_good( Connections::guard( null, rest_get_server(), $r ) );
}
function sfpt_call( $name, $input = array() ) { return sfpt_good( Mcp::execute( $name, json_decode( Snapshot::json( $input ), true ) ) ); }
function sfpt_draft( $with_media = false ) {
    global $component, $wpdb;
    $id = 'destination-' . wp_generate_uuid4();
    $article = (object) array( 'id' => $id, 'revision' => 1, 'title' => 'Destination <b>literal</b> & test', 'blocks' => array() );
    sfpt_call( 'save-draft', array( 'expectedVersion' => 0, 'article' => $article ) );
    $values = (object) array( 'image' => (object) array( 'value' => '', 'alt' => '' ), 'document' => (object) array( 'value' => '', 'alt' => '' ) );
    if ( $with_media ) {
        foreach ( array( 'image' => array( 'photo.png', 'image/png', file_get_contents( ABSPATH . 'wp-content/themes/twentytwentyfive/screenshot.png' ) ),
            'document' => array( 'details.pdf', 'application/pdf', "%PDF-1.4\n%%EOF\n" ) ) as $field => $file ) {
            $asset = wp_generate_uuid4(); $hash = hash( 'sha256', $file[2] ); $path = Assets::directory() . '/' . $asset . '.bin';
            file_put_contents( $path, $file[2] ); chmod( $path, 0600 );
            $wpdb->insert( Assets::table(), array( 'asset_id' => $asset, 'article_id' => $id, 'purpose' => 'publication',
                'sha256' => $hash, 'filename' => $file[0], 'mime' => $file[1], 'bytes' => strlen( $file[2] ),
                'created_by' => get_current_user_id(), 'created_at' => current_time( 'mysql', true ) ) );
            $values->$field->alt = 'Destination test ' . $field;
            $values->$field->assetRef = (object) array( 'kind' => 'staged', 'id' => $asset, 'sha256' => $hash );
        }
    }
    $article->revision = 2;
    $article->blocks = array( (object) array( 'id' => 'resources', 'componentId' => $component->id, 'componentRevision' => $component->revision,
        'variantId' => 'default', 'values' => $values ) );
    return sfpt_call( 'save-draft', array( 'expectedVersion' => 1, 'article' => $article ) );
}
function sfpt_intent( $draft, $type = 'post', $version = 0, $now = false ) {
    return array( 'articleId' => $draft['articleId'], 'expectedVersion' => $draft['version'], 'expectedPublicationVersion' => $version,
        'requestId' => wp_generate_uuid4(), 'timing' => $now ? 'now' : 'scheduled',
        'publishAt' => $now ? '' : gmdate( 'Y-m-d\TH:i:s+00:00', time() + HOUR_IN_SECONDS ), 'timeZone' => $now ? '' : 'UTC', 'postType' => $type );
}
function sfpt_due( $id, $version = null ) {
    global $wpdb; $job = Publications::read( $id );
    $wpdb->update( Publications::table(), array( 'publish_at' => time() - 5 ), array( 'article_id' => $id ) );
    Publications::run( $id, $version ?? (int) $job['version'] ); return sfpt_good( Publications::state( $id ) );
}

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]->ID;
wp_set_current_user( $admin );
$sample = json_decode( file_get_contents( dirname( __DIR__ ) . '/wordpress/atshift-deft/media-sample.json' ) );
$component = $sample->components[0]; $component->id = 'destination-media-' . $run; $component->key = 'destination_' . str_replace( '-', '_', $run ); unset( $component->revision );
$zoom = array_column( Behaviors::listing()['items'], null, 'id' )['image-zoom'];
$component->behaviors = array( (object) array( 'key' => 'enlarge', 'id' => $zoom['id'], 'revision' => $zoom['revision'], 'target' => 'image', 'settings' => (object) $zoom['defaults'] ) );
$component = sfpt_good( Catalog::register( sfpt_request( array( 'component' => $component, 'expectedVersion' => 0 ) ) ) )['component'];
$password = wp_generate_password( 32 );
$user = sfpt_good( wp_insert_user( array( 'user_login' => 'destination-' . $run, 'user_pass' => $password, 'role' => 'sf_publisher' ) ) );
$other = sfpt_good( wp_insert_user( array( 'user_login' => 'other-destination-' . $run, 'user_pass' => wp_generate_password( 32 ), 'role' => 'sf_publisher' ) ) );
$draft_key = sfpt_key( $user, false ); $pub_key = sfpt_key( $user, true ); $other_key = sfpt_key( $other, true );
sfpt_connect( $draft_key );
$context = sfpt_call( 'context' );
sfpt_check( ! $context['capabilities']['publish'] && array( 'post' ) === array_column( sfpt_call( 'list-post-types' )['items'], 'postType' ), 'Draft connection discovers only account-eligible destinations without gaining publication authority.' );
sfpt_check( is_wp_error( Mcp::execute( 'set-publication', array() ) ), 'Discovery cannot bypass draft-only scope.' );
$draft = sfpt_draft(); sfpt_connect( $pub_key );
sfpt_check( PostTypes::can_publish( 'post' ) && ! current_user_can( 'publish_posts' ), 'Existing DEFT publisher remains able to publish normal posts without changing roles.' );
foreach ( array( 'page', 'sf_test_event', 'sf_test_story', 'sf_article', 'attachment', 'revision', 'wp_template', 'custom_css', 'sf_test_private', 'sf_test_hidden', 'sf_test_no_body', 'sf_test_no_create', 'unknown', '', 'POST', '../page', 'page\n', array( 'post' ), 1, null ) as $type ) {
    sfpt_check( is_wp_error( Mcp::execute( 'set-publication', sfpt_intent( $draft, $type ) ) ) && ! Publications::read( $draft['articleId'] ), 'Invalid or unauthorized destination does not reserve or fall back: ' . wp_json_encode( $type ) );
}
$actor = get_userdata( $user );
foreach ( array( 'edit_pages', 'publish_pages', 'edit_published_pages', 'edit_posts', 'publish_posts', 'edit_sf_test_events', 'publish_sf_test_events', 'edit_published_sf_test_events' ) as $cap ) $actor->add_cap( $cap );
wp_set_current_user( 0 ); sfpt_connect( $pub_key );
$types = sfpt_call( 'list-post-types' );
sfpt_check( array( 'page', 'post', 'sf_test_event', 'sf_test_story' ) === array_column( $types['items'], 'postType' ), 'Eligible native and custom destinations are discovered without a DEFT allowlist or REST exposure requirement.' );
sfpt_check( array_reduce( $types['items'], static function( $ok, $type ) { return $ok && $type['accountCanPublish']; }, true ), 'Per-type publication capabilities are reported.' );
sfpt_check( isset( sfpt_call( 'context' )['publication']['schema']['properties']['postType'] ), 'MCP context advertises destination schema.' );
wp_set_current_user( 0 ); sfpt_check( is_wp_error( PostTypes::listing() ), 'Anonymous destination discovery is denied.' ); sfpt_connect( $pub_key );
$actor->remove_cap( 'publish_sf_test_events' ); wp_set_current_user( 0 ); sfpt_connect( $pub_key );
sfpt_check( ! array_column( sfpt_call( 'list-post-types' )['items'], null, 'postType' )['sf_test_event']['accountCanPublish'], 'A creatable type without publication rights is not advertised as publishable.' );
sfpt_check( is_wp_error( Mcp::execute( 'set-publication', sfpt_intent( $draft, 'sf_test_event' ) ) ), 'Create permission alone cannot publish.' );
$actor->add_cap( 'publish_sf_test_events' ); wp_set_current_user( 0 ); sfpt_connect( $pub_key );

$published = array();
foreach ( array( 'post', 'page', 'sf_test_event' ) as $type ) {
    $d = sfpt_draft( true ); $before = Store::read( $d['articleId'] ); $body = sfpt_intent( $d, $type, 0, true );
    $state = sfpt_call( 'set-publication', $body ); $published[$type] = $state;
    sfpt_check( 'published' === $state['state'] && $type === $state['postType'] && $type === get_post_type( $state['postId'] ), 'Immediate publication reaches requested type: ' . $type . ' ' . $state['error'] );
    sfpt_check( $type === $state['manifest']->postType && $state['url'] === get_permalink( $state['postId'] ), 'Result and manifest identify destination and public permalink: ' . $type );
    sfpt_check( Snapshot::json( sfpt_call( 'set-publication', $body ) ) === Snapshot::json( $state ), 'Identical retry is idempotent: ' . $type );
    sfpt_check( is_wp_error( Mcp::execute( 'set-publication', array_merge( $body, array( 'postType' => 'page' === $type ? 'post' : 'page' ) ) ) ), 'A changed destination cannot reuse requestId: ' . $type );
    sfpt_check( is_wp_error( Mcp::execute( 'set-publication', sfpt_intent( $d, 'page', 1, true ) ) ), 'Published entries cannot be relocated by a new request: ' . $type );
    $row = Store::read( $d['articleId'] );
    sfpt_check( $before['payload'] === $row['payload'] && $before['digest'] === $row['digest'] && 'sf_article' === get_post_type( $row['post_id'] ), 'Publishing does not migrate or mutate the private draft: ' . $type );
    sfpt_check( get_the_title( $state['postId'] ) === Snapshot::post_title( $d['snapshot']->article->title ), 'Literal title escaping survives destination change: ' . $type );
    $GLOBALS['wp_query'] = new WP_Query( array( 'post_type' => $type, 'p' => $state['postId'] ) ); Publications::assets();
    sfpt_check( wp_style_is( 'sf-publication-' . $state['postId'], 'enqueued' ) && str_contains( implode( '', wp_styles()->get_data( 'sf-publication-' . $state['postId'], 'after' ) ), '.sf-resources' ), 'Pinned CSS is enqueued: ' . $type );
    sfpt_check( (bool) array_filter( wp_scripts()->queue, static function( $handle ) { return str_contains( $handle, 'image-zoom' ); } ), 'Bundled image behavior is enqueued: ' . $type );
    $job = Publications::read( $d['articleId'] );
    foreach ( json_decode( $job['files'], true ) as $key => $file ) {
        $served = PublicationMedia::bytes( $job['publication_id'], $key );
        sfpt_check( $file['sha256'] === hash( 'sha256', $served[1] ), 'Frozen file is accessible for public destination: ' . $type . ' ' . $file['mime'] );
    }
    $key = array_key_first( json_decode( $job['files'], true ) );
    foreach ( array( array( 'post_status' => 'draft' ), array( 'post_status' => 'private' ), array( 'post_password' => 'test' ), array( 'post_type' => 'sf_test_story' ), array( 'post_author' => $other ) ) as $change ) {
        $original = array_intersect_key( get_post( $state['postId'], ARRAY_A ), $change );
        wp_update_post( array_merge( array( 'ID' => $state['postId'] ), $change ) );
        $denied = false; try { PublicationMedia::bytes( $job['publication_id'], $key ); } catch ( Throwable $error ) { $denied = true; }
        sfpt_check( $denied && 'withdrawn' === Publications::state( $d['articleId'] )['state'], 'Media and public status fail closed after post changes: ' . $type . ' ' . key( $change ) );
        wp_update_post( array_merge( array( 'ID' => $state['postId'] ), $original ) );
    }
    sfpt_connect( $other_key ); sfpt_check( is_wp_error( Mcp::execute( 'get-publication', array( 'articleId' => $d['articleId'] ) ) ), 'Destination support preserves owner isolation: ' . $type ); sfpt_connect( $pub_key );
}

$d = sfpt_draft(); $body = sfpt_intent( $d, 'page' ); $state = sfpt_call( 'set-publication', $body );
sfpt_check( 'scheduled' === $state['state'] && $state['postId'] > 0 && 'sf_reserved' === get_post_status( $state['postId'] ) && ! is_post_publicly_viewable( $state['postId'] ), 'Selecting a future destination creates a private reservation.' );
$replacement = sfpt_call( 'set-publication', sfpt_intent( $d, 'sf_test_event', 1 ) );
sfpt_check( 'sf_test_event' === $replacement['postType'] && 2 === $replacement['version'], 'A fresh request can change destination before publication.' );
sfpt_check( 'overdue' === sfpt_due( $d['articleId'], 1 )['state'] && 'sf_reserved' === get_post_status( $replacement['postId'] ), 'Old scheduled worker cannot publish replaced destination.' );
sfpt_check( 'sf_test_event' === get_post_type( sfpt_due( $d['articleId'], 2 )['postId'] ), 'Current scheduled worker publishes only the selected destination.' );

foreach ( array( 'unregistered', 'nonpublic', 'noeditor', 'permission' ) as $case ) {
    $d = sfpt_draft(); sfpt_call( 'set-publication', sfpt_intent( $d, 'sf_test_event' ) );
    if ( 'unregistered' === $case ) unregister_post_type( 'sf_test_event' );
    elseif ( 'nonpublic' === $case ) get_post_type_object( 'sf_test_event' )->public = false;
    elseif ( 'noeditor' === $case ) remove_post_type_support( 'sf_test_event', 'editor' );
    else { $actor->remove_cap( 'publish_sf_test_events' ); wp_set_current_user( 0 ); sfpt_connect( $pub_key ); }
    $state = sfpt_due( $d['articleId'] );
    sfpt_check( 'blocked' === $state['state'] && 'publish' !== get_post_status( $state['postId'] ) && 'sf_test_event' === $state['postType'], 'Due jobs block without fallback after destination change: ' . $case );
    sf_test_register_post_types(); $actor->add_cap( 'publish_sf_test_events' ); wp_set_current_user( 0 ); sfpt_connect( $pub_key );
}
$d = sfpt_draft(); $old = sfpt_intent( $d ); unset( $old['postType'] ); $scheduled = sfpt_call( 'set-publication', $old );
sfpt_check( 'post' === $scheduled['postType'], 'Legacy input defaults to ordinary posts.' );
$legacy = Review::manifest( Store::read( $d['articleId'] ) );
$wpdb->update( Publications::table(), array( 'manifest' => Snapshot::json( $legacy ), 'manifest_hash' => hash( 'sha256', Snapshot::json( $legacy ) ) ), array( 'article_id' => $d['articleId'] ) );
sfpt_check( 'published' === sfpt_due( $d['articleId'] )['state'], 'Pre-change reservations with old manifests remain publishable as ordinary posts.' );
$d = sfpt_draft(); sfpt_call( 'set-publication', sfpt_intent( $d, 'page' ) );
$wpdb->update( Publications::table(), array( 'post_type' => 'sf_test_event' ), array( 'article_id' => $d['articleId'] ) );
sfpt_check( 'blocked' === sfpt_due( $d['articleId'] )['state'], 'Pinned destination tampering invalidates the publication manifest.' );

$d = sfpt_draft(); $deny_edit = static function( $caps, $cap, $user_id, $args ) {
    if ( 'edit_post' === $cap && isset( $args[0] ) && 'sf_test_event' === get_post_type( $args[0] ) ) return array( 'do_not_allow' );
    return $caps;
};
add_filter( 'map_meta_cap', $deny_edit, 100, 4 );
$blocked = sfpt_call( 'set-publication', sfpt_intent( $d, 'sf_test_event', 0, true ) );
sfpt_check( 'blocked' === $blocked['state'] && 'draft' === get_post_status( $blocked['postId'] ), 'Object-specific edit capability filters block publication even with primitive capabilities.' );
remove_filter( 'map_meta_cap', $deny_edit, 100 );
wp_update_post( array( 'ID' => $blocked['postId'], 'post_type' => 'page' ) );
$wpdb->update( Publications::table(), array( 'state' => 'processing' ), array( 'article_id' => $d['articleId'] ) );
sfpt_check( 'blocked' === sfpt_due( $d['articleId'] )['state'] && 'draft' === get_post_status( $blocked['postId'] ), 'Recovery cannot publish a post moved to another destination outside DEFT.' );
$d = sfpt_draft(); sfpt_call( 'set-publication', sfpt_intent( $d, 'page' ) );
$cancelled = sfpt_call( 'cancel-publication', array( 'articleId' => $d['articleId'], 'expectedPublicationVersion' => 1 ) );
sfpt_check( 'page' === $cancelled['postType'] && 'cancelled' === sfpt_due( $d['articleId'], 1 )['state'], 'Cancellation retains the destination and prevents stale workers from publishing.' );

$ability = wp_get_ability( 'structured-fields/list-post-types' );
sfpt_check( $ability && ! $ability->get_meta()['show_in_rest'] && ! $ability->get_meta()['mcp']['public'], 'Discovery ability is not exposed by generic global servers.' );
$out = getenv( 'SF_POST_TYPES_ARTIFACT_DIR' );
if ( $out && is_dir( $out ) ) {
    file_put_contents( $out . '/post-types-fixture.json', Snapshot::json( array( 'site' => home_url(), 'user' => $user,
        'login' => array( 'username' => 'destination-' . $run, 'password' => $password ),
        'draftKey' => $draft_key, 'publishingKey' => $pub_key, 'otherKey' => $other_key, 'published' => $published ) ) );
    chmod( $out . '/post-types-fixture.json', 0600 );
}
echo Snapshot::json( array( 'post_type_checks' => $checks, 'published' => array_map( static function( $state ) { return $state['url']; }, $published ) ) ), PHP_EOL;
