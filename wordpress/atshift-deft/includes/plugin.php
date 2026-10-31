<?php
namespace StructuredFields;

defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/class-access.php';
require_once __DIR__ . '/class-snapshot.php';
require_once __DIR__ . '/class-behaviors.php';
require_once __DIR__ . '/class-store.php';
require_once __DIR__ . '/class-drafts.php';
require_once __DIR__ . '/class-assets.php';
require_once __DIR__ . '/class-media.php';
require_once __DIR__ . '/class-transfer.php';
require_once __DIR__ . '/class-review.php';
require_once __DIR__ . '/class-catalog.php';
require_once __DIR__ . '/class-component-usage.php';
require_once __DIR__ . '/class-component-appearance.php';
require_once __DIR__ . '/class-component-builder.php';
require_once __DIR__ . '/class-catalog-context.php';
require_once __DIR__ . '/class-onboarding.php';
require_once __DIR__ . '/class-connections.php';
require_once __DIR__ . '/class-oauth-store.php';
require_once __DIR__ . '/class-oauth.php';
require_once __DIR__ . '/class-oauth-consent.php';
require_once __DIR__ . '/class-mcp.php';
require_once __DIR__ . '/class-ai-workflow.php';
require_once __DIR__ . '/class-publications.php';
require_once __DIR__ . '/class-reservations.php';
require_once __DIR__ . '/class-post-types.php';
require_once __DIR__ . '/class-taxonomies.php';
require_once __DIR__ . '/class-publication-media.php';
require_once __DIR__ . '/class-public-structure.php';
require_once __DIR__ . '/connections-page.php';
require_once __DIR__ . '/catalog-page.php';

register_activation_hook( PLUGIN_FILE, static function() { Store::install(); Drafts::install(); Assets::install(); Transfer::install(); Review::install(); Access::install(); Catalog::install(); } );

add_action( 'init', static function() {
    Reservations::register();
    if ( 4 !== (int) get_option( 'sf_connections_schema_version' ) ) Connections::install();
    if ( 1 !== (int) get_option( 'sf_oauth_schema_version' ) ) OAuthStore::install();
    if ( 3 !== (int) get_option( 'sf_publications_schema_version' ) ) Publications::install();
    if ( 1 !== (int) get_option( 'sf_drafts_schema_version' ) ) Drafts::install();
    if ( 3 !== (int) get_option( 'sf_access_schema_version' ) ) Access::install();
    if ( 1 !== (int) get_option( 'sf_brand_version' ) ) Access::update_brand_labels();
    if ( 1 !== (int) get_option( 'sf_assets_schema_version' ) ) Assets::install();
    if ( 2 !== (int) get_option( 'sf_transfers_schema_version' ) ) Transfer::install();
    if ( 2 !== (int) get_option( 'sf_reviews_schema_version' ) ) Review::install();
    if ( 4 !== (int) get_option( 'sf_catalog_schema_version' ) ) Catalog::install();
    register_post_type( 'sf_article', array( 'label' => __( 'Structured drafts', 'atshift-deft' ), 'public' => false, 'publicly_queryable' => true,
        'show_ui' => false, 'show_in_rest' => false, 'exclude_from_search' => true, 'rewrite' => false,
        'supports' => array( 'title', 'editor' ), 'map_meta_cap' => true, 'capability_type' => 'sf_article',
        'capabilities' => array_fill_keys( array( 'edit_posts', 'edit_others_posts', 'publish_posts', 'read_private_posts', 'create_posts', 'delete_posts' ), 'manage_options' ) ) );
} );

add_filter( 'map_meta_cap', array( Access::class, 'preview_caps' ), 10, 4 );
add_filter( 'map_meta_cap', array( Reservations::class, 'capabilities' ), 20, 4 );
add_filter( 'wp_insert_post_empty_content', array( Reservations::class, 'block_edit' ), 10, 2 );
add_filter( 'display_post_states', array( Reservations::class, 'post_states' ), 10, 2 );
add_filter( 'post_date_column_status', array( Reservations::class, 'date_status' ), 10, 2 );
add_filter( 'post_row_actions', array( Reservations::class, 'actions' ), 10, 2 );
add_filter( 'page_row_actions', array( Reservations::class, 'actions' ), 10, 2 );
add_filter( 'gettext_with_context', static function( $translation, $text, $context, $domain ) {
    if ( 'default' !== $domain || 'User role' !== $context ) return $translation;
    switch ( $text ) {
        case 'atshift DEFT author': return __( 'atshift DEFT author', 'atshift-deft' );
        case 'atshift DEFT designer': return __( 'atshift DEFT designer', 'atshift-deft' );
        case 'atshift DEFT publisher': return __( 'atshift DEFT publisher', 'atshift-deft' );
        default: return $translation;
    }
}, 10, 4 );
add_filter( 'is_post_type_viewable', static function( $viewable, $post_type ) {
    // Queryable only for an authorized preview, never a publicly viewable content type.
    return 'sf_article' === $post_type->name ? false : $viewable;
}, 10, 2 );
add_action( 'deleted_user', array( ComponentUsage::class, 'delete_user' ) );
add_action( 'deleted_user', array( Connections::class, 'delete_user' ) );
add_action( 'deleted_user', array( OAuthStore::class, 'delete_user' ) );
add_action( 'init', array( OAuth::class, 'discovery' ), 20 );
add_action( 'rest_api_init', array( OAuth::class, 'routes' ) );
add_action( 'admin_post_sf_oauth_authorize', array( OAuthConsent::class, 'handle' ) );
add_action( 'admin_post_nopriv_sf_oauth_authorize', array( OAuthConsent::class, 'handle' ) );
add_action( 'wp_abilities_api_categories_init', static function() {
    wp_register_ability_category( 'structured-fields', array( 'label' => 'atshift DEFT', 'description' => 'Private structured drafts.' ) );
} );
add_action( 'wp_abilities_api_init', array( Mcp::class, 'register' ) );
add_action( 'mcp_adapter_init', array( Mcp::class, 'server' ) );
add_filter( 'rest_pre_dispatch', array( Connections::class, 'guard' ), 5, 3 );
add_filter( 'rest_post_dispatch', array( Connections::class, 'finish' ), 99, 3 );
add_action( Publications::HOOK, array( Publications::class, 'run' ), 10, 2 );
add_action( 'template_redirect', array( PublicationMedia::class, 'serve' ), -10 );
add_action( 'wp_enqueue_scripts', array( Publications::class, 'assets' ), 20 );
add_action( 'rest_api_init', array( PublicStructure::class, 'routes' ) );
add_action( 'wp_head', array( PublicStructure::class, 'head' ), 20 );

add_filter( 'wp_insert_post_data', static function( $data ) {
    if ( 'sf_article' === $data['post_type'] ) $data['post_status'] = 'draft';
    return $data;
} );

function permission() {
    return Access::workspace() ? true : Access::denied();
}

add_action( 'rest_api_init', static function() {
    register_rest_route( 'structured-fields/v1', '/post-types', array( 'methods' => 'GET', 'permission_callback' => __NAMESPACE__ . '\\permission', 'callback' => array( PostTypes::class, 'listing' ) ) );
    register_rest_route( 'structured-fields/v1', '/post-types/(?P<postType>[a-z0-9_-]{1,20})/taxonomies', array( 'methods' => 'GET', 'permission_callback' => __NAMESPACE__ . '\\permission',
        'callback' => static function( $r ) { return Taxonomies::listing( array( 'postType' => $r['postType'] ) ); } ) );
    register_rest_route( 'structured-fields/v1', '/post-types/(?P<postType>[a-z0-9_-]{1,20})/taxonomies/(?P<taxonomy>[a-z0-9_-]{1,32})/terms', array( 'methods' => 'GET', 'permission_callback' => __NAMESPACE__ . '\\permission',
        'args' => array( 'search' => array( 'type' => 'string', 'maxLength' => 200, 'default' => '' ), 'page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 10000, 'default' => 1 ) ),
        'callback' => static function( $r ) { return Taxonomies::terms( array( 'postType' => $r['postType'], 'taxonomy' => $r['taxonomy'], 'search' => $r['search'], 'page' => $r['page'] ) ); } ) );
    register_rest_route( 'structured-fields/v1', '/publications', array( 'methods' => 'POST', 'permission_callback' => array( Publications::class, 'permission' ), 'callback' => array( Publications::class, 'set' ) ) );
    register_rest_route( 'structured-fields/v1', '/publications/cancel', array( 'methods' => 'POST', 'permission_callback' => array( Publications::class, 'permission' ), 'callback' => array( Publications::class, 'cancel' ) ) );
    register_rest_route( 'structured-fields/v1', '/publications/(?P<id>[a-zA-Z0-9][a-zA-Z0-9_-]{0,79})', array( 'methods' => 'GET', 'permission_callback' => __NAMESPACE__ . '\\permission', 'callback' => static function( $r ) { return Publications::state( $r['id'] ); } ) );
    register_rest_route( 'structured-fields/v1', '/connections', array(
        array( 'methods' => 'GET', 'permission_callback' => array( Connections::class, 'permission' ), 'callback' => array( Connections::class, 'listing' ) ),
        array( 'methods' => 'POST', 'permission_callback' => array( Connections::class, 'permission' ), 'callback' => array( Connections::class, 'create' ) ),
    ) );
    register_rest_route( 'structured-fields/v1', '/connections/(?P<id>[a-f0-9-]{36})/revoke', array( 'methods' => 'POST',
        'permission_callback' => array( Connections::class, 'permission' ), 'callback' => array( Connections::class, 'revoke' ) ) );
    register_rest_route( 'structured-fields/v1', '/behaviors', array( 'methods' => 'GET', 'permission_callback' => __NAMESPACE__ . '\\permission', 'callback' => array( Behaviors::class, 'listing' ) ) );
    register_rest_route( 'structured-fields/v1', '/behaviors/(?P<id>[a-z-]+)/availability', array( 'methods' => 'POST', 'permission_callback' => static function() { return Access::workspace() && current_user_can( 'manage_options' ) ? true : Access::denied(); }, 'callback' => array( Behaviors::class, 'availability' ) ) );
    register_rest_route( 'structured-fields/v1', '/catalog', array(
        array( 'methods' => 'GET', 'permission_callback' => __NAMESPACE__ . '\\permission', 'callback' => array( Catalog::class, 'listing' ) ),
        array( 'methods' => 'POST', 'permission_callback' => array( Catalog::class, 'permission' ), 'callback' => array( Catalog::class, 'register' ) ),
    ) );
    register_rest_route( 'structured-fields/v1', '/catalog/schema', array( 'methods' => 'GET', 'permission_callback' => __NAMESPACE__ . '\\permission', 'callback' => array( Catalog::class, 'schemas' ) ) );
    register_rest_route( 'structured-fields/v1', '/catalog/context', array( 'methods' => 'GET', 'permission_callback' => __NAMESPACE__ . '\\permission', 'callback' => array( CatalogContext::class, 'get' ) ) );
    register_rest_route( 'structured-fields/v1', '/catalog/personal', array( 'methods' => 'POST', 'permission_callback' => __NAMESPACE__ . '\\permission', 'callback' => array( Catalog::class, 'personal' ) ) );
    register_rest_route( 'structured-fields/v1', '/catalog/structures', array( 'methods' => 'POST', 'permission_callback' => __NAMESPACE__ . '\\permission', 'callback' => array( Catalog::class, 'structure' ) ) );
    register_rest_route( 'structured-fields/v1', '/catalog/(?P<id>[a-zA-Z0-9][a-zA-Z0-9_-]{0,79})/share', array( 'methods' => 'POST', 'permission_callback' => array( Catalog::class, 'permission' ), 'callback' => array( Catalog::class, 'share' ) ) );
    register_rest_route( 'structured-fields/v1', '/catalog/(?P<id>[a-zA-Z0-9][a-zA-Z0-9_-]{0,79})', array( 'methods' => 'GET', 'permission_callback' => __NAMESPACE__ . '\\permission', 'callback' => array( Catalog::class, 'get' ) ) );
    register_rest_route( 'structured-fields/v1', '/catalog/(?P<id>[a-zA-Z0-9][a-zA-Z0-9_-]{0,79})/availability', array( 'methods' => 'POST', 'permission_callback' => __NAMESPACE__ . '\\permission', 'callback' => array( Catalog::class, 'availability' ) ) );
    register_rest_route( 'structured-fields/v1', '/articles/compose', array( 'methods' => 'POST', 'permission_callback' => __NAMESPACE__ . '\\permission', 'callback' => array( Catalog::class, 'compose' ) ) );
    register_rest_route( 'structured-fields/v1', '/articles/(?P<id>[a-zA-Z0-9][a-zA-Z0-9_-]{0,79})/transfers', array( 'methods' => 'POST',
        'permission_callback' => array( Assets::class, 'permission' ), 'callback' => array( Transfer::class, 'issue' ) ) );
    register_rest_route( 'structured-fields/v1', '/transfers/(?P<transfer>[a-f0-9-]{36})', array( 'methods' => 'POST',
        'permission_callback' => static function( $r ) { $grant = Transfer::authenticate( $r ); return is_wp_error( $grant ) ? $grant : true; },
        'callback' => array( Transfer::class, 'receive' ) ) );
    register_rest_route( 'structured-fields/v1', '/transfers/(?P<transfer>[a-f0-9-]{36})/revoke', array( 'methods' => 'POST',
        'permission_callback' => array( Assets::class, 'permission' ), 'callback' => array( Transfer::class, 'revoke' ) ) );
    register_rest_route( 'structured-fields/v1', '/articles/(?P<id>[a-zA-Z0-9][a-zA-Z0-9_-]{0,79})/reviews', array( 'methods' => 'POST',
        'permission_callback' => array( Review::class, 'permission' ), 'callback' => array( Review::class, 'create' ) ) );
    register_rest_route( 'structured-fields/v1', '/reviews/(?P<review>[a-f0-9-]{36})', array( 'methods' => 'GET',
        'permission_callback' => array( Review::class, 'permission' ), 'callback' => static function( $r ) {
            $row = Review::read( $r['review'] ); return $row && Access::article( $row['article_id'] ) ? Review::state( $row ) : new \WP_Error( 'sf_missing', __( 'Review not found.', 'atshift-deft' ), array( 'status' => 404 ) );
        } ) );
    register_rest_route( 'structured-fields/v1', '/media', array( 'methods' => 'GET',
        'permission_callback' => array( Assets::class, 'permission' ), 'callback' => array( Media::class, 'listing' ) ) );
    register_rest_route( 'structured-fields/v1', '/media/(?P<id>[1-9][0-9]{0,17})', array( 'methods' => 'GET',
        'permission_callback' => array( Assets::class, 'permission' ), 'callback' => static function( $request ) {
            try { return Media::attachment_response( $request['id'] ); } catch ( \Throwable $error ) {
                return new \WP_Error( 'sf_media_missing', __( 'This local media file is missing, unsupported or inaccessible.', 'atshift-deft' ), array( 'status' => 404 ) );
            }
        } ) );
    register_rest_route( 'structured-fields/v1', '/articles/(?P<id>[a-zA-Z0-9][a-zA-Z0-9_-]{0,79})/assets', array(
        array( 'methods' => 'GET', 'permission_callback' => array( Assets::class, 'permission' ),
            'callback' => static function( $request ) { return Assets::listing( $request['id'] ); } ),
        array( 'methods' => 'POST', 'permission_callback' => array( Assets::class, 'permission' ), 'callback' => array( Assets::class, 'receive' ) ),
    ) );
    register_rest_route( 'structured-fields/v1', '/articles', array(
        array( 'methods' => 'GET', 'permission_callback' => __NAMESPACE__ . '\\permission', 'args' => array( 'state' => array( 'type' => 'string', 'enum' => array( 'draft', 'trash' ), 'default' => 'draft' ) ),
            'callback' => static function( $request ) { return Store::listing( $request['state'] ); } ),
        array( 'methods' => 'POST', 'permission_callback' => __NAMESPACE__ . '\\permission', 'callback' => static function( $request ) {
            if ( strlen( $request->get_body() ) > 4 * 1024 * 1024 ) return new \WP_Error( 'sf_size', __( 'Payload exceeds 4 MB.', 'atshift-deft' ), array( 'status' => 413 ) );
            if ( ! $request->is_json_content_type() ) return new \WP_Error( 'sf_json', __( 'Use application/json.', 'atshift-deft' ), array( 'status' => 415 ) );
            try {
                $body = json_decode( $request->get_body(), false, 128, JSON_THROW_ON_ERROR );
                if ( ! $body instanceof \stdClass || count( get_object_vars( $body ) ) !== 2 || ! isset( $body->snapshot, $body->expectedVersion ) || ! is_int( $body->expectedVersion ) || $body->expectedVersion < 0 ) throw new \InvalidArgumentException( __( 'Supply snapshot and integer expectedVersion only.', 'atshift-deft' ) );
                return Store::save( Snapshot::validate( Snapshot::json( $body->snapshot ) ), $body->expectedVersion );
            } catch ( \Throwable $error ) {
                return new \WP_Error( 'sf_invalid', $error->getMessage(), array( 'status' => 400 ) );
            }
        } ),
    ) );
    foreach ( array( 'trash' => 'trash', 'restore' => 'draft' ) as $action => $state ) {
        register_rest_route( 'structured-fields/v1', '/articles/(?P<id>[a-zA-Z0-9][a-zA-Z0-9_-]{0,79})/' . $action, array(
            'methods' => 'POST', 'permission_callback' => __NAMESPACE__ . '\\permission',
            'callback' => static function( $request ) use ( $state ) { return Drafts::request( $request, $state ); },
        ) );
    }
    register_rest_route( 'structured-fields/v1', '/articles/(?P<id>[a-zA-Z0-9][a-zA-Z0-9_-]{0,79})', array(
        'methods' => 'GET', 'permission_callback' => __NAMESPACE__ . '\\permission', 'callback' => static function( $request ) {
            $version = $request->get_param( 'version' );
            if ( null !== $version && ( ( ! is_string( $version ) && ! is_int( $version ) ) || ! preg_match( '/^[1-9][0-9]{0,8}$/D', (string) $version ) ) ) return new \WP_Error( 'sf_version', __( 'Invalid version.', 'atshift-deft' ), array( 'status' => 400 ) );
            $row = Store::read( $request['id'], null === $version ? null : (int) $version );
            return $row && Access::article( $row['article_id'] ) ? Store::response( $row ) : new \WP_Error( 'sf_missing', __( 'Article not found.', 'atshift-deft' ), array( 'status' => 404 ) );
        },
    ) );
} );

add_action( 'admin_post_sf_asset', array( Assets::class, 'serve' ) );
add_action( 'admin_post_sf_ai_guide', array( Onboarding::class, 'download' ) );
add_action( 'admin_post_nopriv_sf_ai_guide', array( Onboarding::class, 'download' ) );
add_action( 'admin_post_nopriv_sf_asset', array( Assets::class, 'serve' ) );
add_action( 'admin_post_sf_media', array( Media::class, 'serve' ) );
add_action( 'admin_post_nopriv_sf_media', array( Media::class, 'serve' ) );
add_action( 'admin_post_sf_decide', array( Review::class, 'submit' ) );
add_action( 'admin_post_nopriv_sf_decide', array( Review::class, 'submit' ) );
add_action( 'admin_init', static function() {
    // Read-only route detection adds privacy headers; it grants no review authority.
    if ( 'structured-fields-review' === sanitize_key( wp_unslash( $_GET['page'] ?? '' ) ) ) {
        nocache_headers();
        header( 'Referrer-Policy: no-referrer' );
        header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
    }
} );

add_filter( 'rest_post_dispatch', static function( $response, $server, $request ) {
    if ( str_starts_with( $request->get_route(), '/structured-fields/v1/' ) ) {
        $response->header( 'Cache-Control', 'private, no-store, max-age=0' );
        $response->header( 'X-Robots-Tag', 'noindex, nofollow' );
    }
    return $response;
}, 10, 3 );

add_filter( 'the_title', array( Store::class, 'title' ), PHP_INT_MAX, 2 );

add_action( 'template_redirect', static function() {
    $requested_id = absint( get_query_var( 'p' ) );
    if ( ! is_singular( 'sf_article' ) && ( ! $requested_id || 'sf_article' !== get_post_type( $requested_id ) ) ) return;
    global $wpdb;
    $post_id = get_queried_object_id() ?: $requested_id;
    $table = Store::table();
    $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE post_id=%d', $table, $post_id ), ARRAY_A );
    $version = is_string( $_GET['sf_version'] ?? null ) ? wp_unslash( $_GET['sf_version'] ) : '';
    $nonce = is_string( $_GET['_sf_nonce'] ?? null ) ? wp_unslash( $_GET['_sf_nonce'] ) : '';
    if ( ! $row || ! Access::article( $row['article_id'] ) || ! is_preview() ||
        (string) $row['version'] !== $version ||
        ! wp_verify_nonce( $nonce, 'sf-preview-' . $post_id . '-' . $row['digest'] ) ) {
        wp_die( esc_html__( 'This preview requires its authorized WordPress session and exact saved version.', 'atshift-deft' ), esc_html__( 'Private preview', 'atshift-deft' ), array( 'response' => 403 ) );
    }
    nocache_headers();
    header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
    header( 'Referrer-Policy: no-referrer' );
    header( "Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'none'; object-src 'none'; base-uri 'self'; form-action 'none'; frame-ancestors 'self'" );
    try { $rendered = Behaviors::preview( Media::preview_html( $row ), json_decode( $row['payload'] ) ); } catch ( \Throwable $error ) {
        wp_die( esc_html__( 'A referenced media file is unavailable, changed or no longer permitted.', 'atshift-deft' ), esc_html__( 'Private preview', 'atshift-deft' ), array( 'response' => 503 ) );
    }
    // User values are plain text: do not execute shortcodes, blocks, embeds or other content filters.
    remove_all_filters( 'the_content' );
    add_filter( 'the_content', static function( $content ) use ( $rendered, $post_id ) {
        return get_the_ID() === $post_id ? $rendered : $content;
    } );
    add_action( 'wp_enqueue_scripts', static function() use ( $row ) {
        wp_register_style( 'sf-preview', false, array(), VERSION );
        wp_enqueue_style( 'sf-preview' );
        wp_add_inline_style( 'sf-preview', '.sf-article{overflow-wrap:anywhere}.sf-article img{max-width:100%;height:auto}.sf-article p{white-space:pre-line}.sf-article table{display:block;overflow:auto}.sf-article pre{white-space:pre-wrap}' . str_replace( '<', '\\3c ', $row['css'] ) );
    } );
}, 0 );

add_action( 'admin_menu', static function() {
    $cap = current_user_can( 'manage_options' ) ? 'manage_options' : 'sf_edit_drafts';
    add_menu_page( 'atshift DEFT', 'atshift DEFT', $cap, 'structured-fields', __NAMESPACE__ . '\\admin_page', 'dashicons-layout', 81 );
    add_submenu_page( 'structured-fields', __( 'Articles', 'atshift-deft' ), __( 'Articles', 'atshift-deft' ), $cap, 'structured-fields', __NAMESPACE__ . '\\admin_page' );
    add_submenu_page( 'structured-fields', __( 'Components', 'atshift-deft' ), __( 'Components', 'atshift-deft' ), $cap, 'structured-fields-catalog', __NAMESPACE__ . '\\catalog_page' );
    add_submenu_page( 'structured-fields', __( 'AI connections', 'atshift-deft' ), __( 'AI connections', 'atshift-deft' ), $cap, 'structured-fields-connections', __NAMESPACE__ . '\\connections_page' );
    $review_hook = add_submenu_page( '', __( 'Review structured article', 'atshift-deft' ), __( 'Review', 'atshift-deft' ), $cap, 'structured-fields-review', array( Review::class, 'page' ) );
    add_action( 'load-' . $review_hook, static function() { Review::authorize_page(); global $title; $title = __( 'Review structured article', 'atshift-deft' ); } );
} );

function admin_page() {
    if ( ! Access::workspace() ) return;
    $guide_copy = Onboarding::copy();
    $first_visit = Onboarding::first_visit();
    $owner = wp_get_current_user();
    $role_names = array();
    foreach ( $owner->roles as $role ) {
        $name = wp_roles()->roles[ $role ]['name'] ?? $role;
        $role_names[] = translate_user_role( $name );
    }
    ?>
    <div class="wrap" id="sf-workspace">
        <h1>atshift DEFT <small><?php echo esc_html( VERSION ); ?></small></h1>
        <?php if ( $first_visit ) : ?>
        <dialog id="sf-ai-welcome" aria-labelledby="sf-ai-welcome-title">
            <button type="button" id="sf-ai-welcome-close" class="button" title="<?php esc_attr_e( 'Close', 'atshift-deft' ); ?>" aria-label="<?php esc_attr_e( 'Close', 'atshift-deft' ); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>
            <h2 id="sf-ai-welcome-title"><?php echo esc_html( $guide_copy['title'] ); ?></h2>
            <p><?php echo esc_html( $guide_copy['start'] ); ?></p>
            <a class="button button-primary" id="sf-ai-welcome-download" href="<?php echo esc_url( Onboarding::url() ); ?>" autofocus><span class="dashicons dashicons-download" aria-hidden="true"></span><?php echo esc_html( $guide_copy['download'] ); ?></a>
        </dialog>
        <?php endif; ?>
        <div class="sf-toolbar">
            <a class="button" id="sf-ai-guide" href="<?php echo esc_url( Onboarding::url() ); ?>"><span class="dashicons dashicons-download" aria-hidden="true"></span><?php echo esc_html( $guide_copy['download'] ); ?></a>
            <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=structured-fields-connections' ) ); ?>"><span class="dashicons dashicons-admin-links" aria-hidden="true"></span><?php echo esc_html( $guide_copy['connect'] ); ?></a>
            <button class="button" id="sf-new"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> <?php esc_html_e( 'New article', 'atshift-deft' ); ?></button>
            <button class="button" id="sf-handoff" title="<?php esc_attr_e( 'Download article handoff', 'atshift-deft' ); ?>" aria-label="<?php esc_attr_e( 'Download article handoff', 'atshift-deft' ); ?>"><span class="dashicons dashicons-download" aria-hidden="true"></span> <?php esc_html_e( 'Article handoff', 'atshift-deft' ); ?></button>
            <button class="button" id="sf-import-button"><span class="dashicons dashicons-upload" aria-hidden="true"></span> <?php esc_html_e( 'Import snapshot', 'atshift-deft' ); ?></button><input type="file" id="sf-import" accept="application/json,.json" hidden>
            <button class="button" id="sf-sample"><?php esc_html_e( 'Load sample', 'atshift-deft' ); ?></button>
            <button class="button" id="sf-media-sample"><?php esc_html_e( 'Media sample', 'atshift-deft' ); ?></button>
            <button class="button" id="sf-refresh" title="<?php esc_attr_e( 'Refresh articles', 'atshift-deft' ); ?>" aria-label="<?php esc_attr_e( 'Refresh articles', 'atshift-deft' ); ?>"><span class="dashicons dashicons-update" aria-hidden="true"></span></button>
        </div>
        <p id="sf-drafts-help"><?php esc_html_e( 'This is a private workspace for preparing articles. Choose Posts, Pages or an available custom post type as the destination. Once published or scheduled, an article leaves this draft list and appears in its destination list. Cancelled, invalidated or failed reservations return here. Saved content and files are retained.', 'atshift-deft' ); ?></p>
        <p id="sf-status" role="status"><?php esc_html_e( 'Private drafts', 'atshift-deft' ); ?></p>
        <div class="sf-layout"><nav id="sf-draft-sidebar" aria-label="<?php esc_attr_e( 'Articles', 'atshift-deft' ); ?>">
            <div id="sf-draft-owner">
                <p><?php
                    /* translators: %s: current WordPress user's display name. */
                    echo esc_html( sprintf( __( 'Draft owner: %s', 'atshift-deft' ), $owner->display_name ) );
                ?></p>
                <p><?php
                    /* translators: %s: translated WordPress role names. */
                    echo esc_html( sprintf( __( 'WordPress role: %s', 'atshift-deft' ), implode( ', ', $role_names ) ) );
                ?></p>
            </div>
            <div class="sf-draft-filters" role="group" aria-label="<?php esc_attr_e( 'Draft status', 'atshift-deft' ); ?>">
                <button type="button" class="button" id="sf-drafts-tab" aria-pressed="true"><?php esc_html_e( 'Drafts', 'atshift-deft' ); ?></button>
                <button type="button" class="button" id="sf-trash-tab" aria-pressed="false"><span class="dashicons dashicons-trash" aria-hidden="true"></span><?php esc_html_e( 'Trash', 'atshift-deft' ); ?></button>
            </div>
            <div id="sf-articles"></div>
        </nav><main>
            <h2 id="sf-title"><?php esc_html_e( 'No article selected', 'atshift-deft' ); ?></h2>
            <p id="sf-version"></p>
            <p id="sf-behavior-status" role="status"></p>
            <div id="sf-publication" class="sf-toolbar" hidden>
                <span id="sf-publication-status" role="status"></span>
                <a id="sf-publication-link" class="button" target="_blank" rel="noopener" hidden><?php esc_html_e( 'View published post', 'atshift-deft' ); ?> <span class="dashicons dashicons-external" aria-hidden="true"></span></a>
                <?php if ( Access::publisher() ) : ?><button id="sf-publication-cancel" class="button" hidden><span class="dashicons dashicons-no-alt" aria-hidden="true"></span> <?php esc_html_e( 'Cancel reservation', 'atshift-deft' ); ?></button><?php endif; ?>
            </div>
            <div class="sf-toolbar">
                <button class="button button-primary" id="sf-save" disabled><span class="dashicons dashicons-saved" aria-hidden="true"></span> <?php esc_html_e( 'Save draft', 'atshift-deft' ); ?></button>
                <button class="button" id="sf-export" title="<?php esc_attr_e( 'Export snapshot', 'atshift-deft' ); ?>" aria-label="<?php esc_attr_e( 'Export snapshot', 'atshift-deft' ); ?>" disabled><span class="dashicons dashicons-download" aria-hidden="true"></span></button>
                <a class="button" id="sf-preview" target="_blank" rel="noopener" hidden><?php esc_html_e( 'Open theme preview', 'atshift-deft' ); ?> <span class="dashicons dashicons-external" aria-hidden="true"></span></a>
                <button class="button" id="sf-review" disabled><span class="dashicons dashicons-visibility" aria-hidden="true"></span> <?php esc_html_e( 'Review', 'atshift-deft' ); ?></button>
            </div>
            <?php Onboarding::publication_help(); ?>
            <details id="sf-editor" hidden>
                <summary><?php esc_html_e( 'Edit article', 'atshift-deft' ); ?></summary>
                <label for="sf-article-title"><?php esc_html_e( 'Title', 'atshift-deft' ); ?></label><input id="sf-article-title" type="text" maxlength="200">
                <div class="sf-toolbar">
                    <label for="sf-component-choice"><?php esc_html_e( 'Component', 'atshift-deft' ); ?></label><select id="sf-component-choice"></select>
                    <button class="button" id="sf-component-add" title="<?php esc_attr_e( 'Add component', 'atshift-deft' ); ?>" aria-label="<?php esc_attr_e( 'Add component', 'atshift-deft' ); ?>"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span></button>
                    <button class="button" id="sf-catalog-refresh" title="<?php esc_attr_e( 'Refresh components', 'atshift-deft' ); ?>" aria-label="<?php esc_attr_e( 'Refresh components', 'atshift-deft' ); ?>"><span class="dashicons dashicons-update" aria-hidden="true"></span></button>
                </div>
                <div id="sf-blocks"></div>
            </details>
            <section id="sf-assets" hidden aria-labelledby="sf-assets-heading">
                <h2 id="sf-assets-heading"><?php esc_html_e( 'Private files', 'atshift-deft' ); ?></h2>
                <p id="sf-assets-status" role="status"></p>
                <div class="sf-toolbar">
                    <label for="sf-asset-purpose"><?php esc_html_e( 'Use', 'atshift-deft' ); ?></label>
                    <select id="sf-asset-purpose"><option value="reference"><?php esc_html_e( 'Reference only', 'atshift-deft' ); ?></option><option value="publication"><?php esc_html_e( 'Publication candidate', 'atshift-deft' ); ?></option></select>
                    <button class="button" id="sf-upload-button"><span class="dashicons dashicons-upload" aria-hidden="true"></span> <?php esc_html_e( 'Add files', 'atshift-deft' ); ?></button>
                    <input type="file" id="sf-upload" accept="image/jpeg,image/png,image/webp,application/pdf" multiple hidden>
                </div>
                <ul id="sf-asset-list"></ul>
            </section>
            <section id="sf-media-fields" hidden aria-labelledby="sf-media-heading">
                <h2 id="sf-media-heading"><?php esc_html_e( 'Article media', 'atshift-deft' ); ?></h2>
                <div class="sf-toolbar">
                    <label for="sf-media-search"><?php esc_html_e( 'Existing WordPress media', 'atshift-deft' ); ?></label>
                    <input id="sf-media-search" type="search" maxlength="200">
                    <button class="button" id="sf-media-find" title="<?php esc_attr_e( 'Search media', 'atshift-deft' ); ?>" aria-label="<?php esc_attr_e( 'Search media', 'atshift-deft' ); ?>"><span class="dashicons dashicons-search" aria-hidden="true"></span></button>
                    <button class="button" id="sf-media-more" hidden><?php esc_html_e( 'More results', 'atshift-deft' ); ?></button>
                </div>
                <div id="sf-media-bindings"></div>
            </section>
            <iframe id="sf-frame" title="<?php esc_attr_e( 'WordPress theme preview', 'atshift-deft' ); ?>" hidden></iframe>
        </main></div>
    </div>
    <?php
}

add_action( 'admin_enqueue_scripts', static function( $hook ) {
    $catalog = get_plugin_page_hookname( 'structured-fields-catalog', 'structured-fields' ) === $hook;
    $connections = get_plugin_page_hookname( 'structured-fields-connections', 'structured-fields' ) === $hook;
    if ( 'toplevel_page_structured-fields' !== $hook && ! $catalog && ! $connections ) return;
    wp_enqueue_style( 'sf-admin', plugins_url( 'admin.css', PLUGIN_FILE ), array(), VERSION );
    if ( $connections ) {
        wp_enqueue_script( 'sf-connection-profiles', plugins_url( 'connection-profiles.js', PLUGIN_FILE ), array( 'wp-i18n' ), VERSION, true );
        wp_set_script_translations( 'sf-connection-profiles', 'atshift-deft', dirname( PLUGIN_FILE ) . '/languages' );
    }
    wp_enqueue_script( 'sf-admin', plugins_url( $connections ? 'connections.js' : ( $catalog ? 'catalog.js' : 'admin.js' ), PLUGIN_FILE ), $connections ? array( 'wp-i18n', 'sf-connection-profiles' ) : array( 'wp-i18n' ), VERSION, true );
    wp_set_script_translations( 'sf-admin', 'atshift-deft', dirname( PLUGIN_FILE ) . '/languages' );
    wp_add_inline_script( 'sf-admin', 'window.sfWordPress=' . wp_json_encode( array( 'root' => rest_url( 'structured-fields/v1/' ), 'nonce' => wp_create_nonce( 'wp_rest' ), 'canManageCatalog' => Catalog::can_manage(), 'canRegisterCatalog' => Catalog::can_register(), 'locale' => str_replace( '_', '-', get_user_locale() ), 'sample' => plugins_url( 'sample.json', PLUGIN_FILE ), 'mediaSample' => plugins_url( 'media-sample.json', PLUGIN_FILE ) ) ) . ';', 'before' );
} );
