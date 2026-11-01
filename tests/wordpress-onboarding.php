<?php
require __DIR__ . '/wordpress-media-fixture.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
use StructuredFields\Onboarding;
use StructuredFields\Catalog;
use StructuredFields\CatalogContext;
use StructuredFields\Snapshot;
use StructuredFields\Store;

$run = wp_generate_uuid4(); $prefix = 'onboarding-' . $run; $users = array(); $admin = get_current_user_id(); $checks = 0;
$id = 'media-test-' . $run;
function sfo_check( $value, $message ) { global $checks; if ( ! $value ) throw new RuntimeException( $message ); echo 'PASS ' . ++$checks . ': ' . $message . PHP_EOL; }
function sfo_render() { unload_textdomain( 'atshift-deft', true ); ob_start(); StructuredFields\admin_page(); return ob_get_clean(); }
$test_locale = static function() { return get_user_locale(); };
add_filter( 'pre_determine_locale', $test_locale );
try {
    wp_set_current_user( 0 );
    sfo_check( ! Onboarding::first_visit() && '' === sfo_render(), 'Anonymous access cannot consume a first visit or render the workspace' );
    try { Onboarding::document(); $denied = false; } catch ( Throwable $e ) { $denied = true; }
    sfo_check( $denied, 'Anonymous guide generation is denied' );
    foreach ( array( 'sf_author', 'sf_author', 'subscriber' ) as $i => $role ) {
        $uid = wp_insert_user( array( 'user_login' => $prefix . '-' . $i, 'user_pass' => wp_generate_password( 32 ), 'role' => $role ) );
        if ( is_wp_error( $uid ) ) throw new RuntimeException( 'Could not create disposable user.' );
        $users[] = $uid;
    }
    wp_set_current_user( $users[2] );
    sfo_check( ! Onboarding::first_visit() && '' === sfo_render(), 'Subscribers cannot consume onboarding or see the download link' );
    wp_set_current_user( $users[0] ); update_user_meta( $users[0], 'locale', 'ja' );
    $first = sfo_render();
    sfo_check( str_contains( $first, 'id="sf-ai-welcome"' ) && str_contains( $first, '手順書をダウンロードして、いつものAIに読ませてください' ) && str_contains( $first, 'AIが接続を案内し' ), 'First Articles visit starts with giving the guide to AI in Japanese' );
    sfo_check( str_contains( $first, '段組や見た目も相談できます' ) && str_contains( $first, '今のテーマに馴染ませたい' ) && str_contains( $first, '記事用に別のレイアウトを組みたい' ), 'Japanese introduction explains theme-aware and separate article layout choices' );
    sfo_check( str_contains( $first, '<dialog id="sf-ai-welcome"' ) && str_contains( $first, 'id="sf-ai-welcome-download"' ) && ! str_contains( $first, 'AIへの自動接続・記事の公開には対応していません' ), 'First-use modal contains the focused instruction and download without technical detail' );
    sfo_check( str_contains( $first, 'id="sf-ai-guide"' ) && str_contains( $first, 'AI用手順書をダウンロード' ), 'Download is available before creating or selecting an article' );
    $dom = new DOMDocument(); @$dom->loadHTML( '<?xml encoding="UTF-8">' . $first ); $xpath = new DOMXPath( $dom );
    $links = $xpath->query( '//dialog[@id="sf-ai-welcome"]//a' );
    sfo_check( 1 === $links->length && 'sf-ai-welcome-download' === $links->item( 0 )->getAttribute( 'id' ), 'The first-use modal has only the guide download action, not connection setup' );
    sfo_check( strpos( $first, 'id="sf-ai-guide"' ) < strpos( $first, 'page=structured-fields-connections' ), 'The persistent toolbar also places the guide before connection settings' );
    sfo_check( metadata_exists( 'user', $users[0], '_sf_ai_welcome_seen_' . get_current_blog_id() ), 'First visit is stored per account and site, not in browser storage' );
    $second = sfo_render();
    sfo_check( ! str_contains( $second, 'id="sf-ai-welcome"' ) && str_contains( $second, 'id="sf-ai-guide"' ), 'Subsequent visits omit the introduction but retain the download' );
    wp_set_current_user( $users[1] ); $other = sfo_render();
    sfo_check( str_contains( $other, 'id="sf-ai-welcome"' ) && str_contains( $other, 'Give this guide to your usual AI' ) && str_contains( $other, 'guide you through connecting' ), 'A different account has its own guide-first English introduction' );
    wp_set_current_user( $users[0] );
    $context = CatalogContext::get( new WP_REST_Request( 'GET' ) );
    $recipe = $context['personalExample']; $recipe->id = $prefix; $recipe->key = 'private_' . str_replace( '-', '_', $run );
    $recipe->name = 'PRIVATE_COMPONENT_' . $run; $recipe->fields[0]->value = 'PRIVATE_EXAMPLE_' . $run;
    $request = new WP_REST_Request( 'POST' ); $request->set_header( 'content-type', 'application/json' );
    $request->set_body( Snapshot::json( array( 'recipe' => $recipe, 'expectedVersion' => 0, 'reuse' => array(
        'catalogToken' => $context['catalogToken'], 'considered' => array( array( 'id' => $context['components'][0]['id'], 'revision' => $context['components'][0]['revision'] ) ),
        'reason' => 'This private fixture has a distinct bounded Q&A structure for privacy verification.' ) ) ) );
    $personal = Catalog::personal( $request );
    if ( is_wp_error( $personal ) ) throw new RuntimeException( $personal->get_error_message() );
    $media = sf_media_test_create( $id );
    $doc = Onboarding::document();
    sfo_check( str_starts_with( $doc, '# atshift DEFT: Start with Your AI' ) && str_contains( $doc, '## Authoring Rules' ), 'Downloaded Markdown contains the introduction and full AI authoring instructions' );
    sfo_check( str_contains( $doc, 'https://wordpress.org/plugins/mcp-adapter/' ) && str_contains( $doc, 'version 0.7.1 or newer, installed and active' ) && str_contains( $doc, 'Plugins > Add New Plugin' ) && str_contains( $doc, 'Install Now > Activate' ), 'Downloaded guide links the required official MCP Adapter and explains installation and activation' );
    sfo_check( strpos( $doc, '## Before Connecting: Install MCP Adapter' ) < strpos( $doc, '## Connection Details' ) && str_contains( $doc, 'Do not install, update or activate plugins without explicit approval' ) && str_contains( $doc, 'ask for confirmation from the administrator' ), 'Dependency setup precedes client settings without assuming authority or a live installation check' );
    sfo_check( str_contains( $doc, '## Choose the Actual Client' ) && str_contains( $doc, 'Gemini CLI' ) && str_contains( $doc, 'limited beta' ) && str_contains( $doc, 'pre-register a public client' ), 'Guide distinguishes service clients, limited availability and pre-registered OAuth' );
    sfo_check( str_contains( $doc, 'Never put one person\'s key in a' ) && str_contains( $doc, 'SDK tests do not prove' ), 'Guide preserves per-owner credentials and real-client verification boundaries' );
    sfo_check( strpos( $doc, '## First Reply: Guide the Connection' ) < strpos( $doc, '## Connection Details' ) && strpos( $doc, '## Connection Details' ) < strpos( $doc, '## When Connected' ) && strpos( $doc, '## When Connected' ) < strpos( $doc, '## Manual Fallback: JSON Proposals' ), 'Guide presents AI-led setup, connection verification and explicit manual fallback in order' );
    sfo_check( ! str_contains( $doc, 'OAuth-only clients are not supported' ) && str_contains( $doc, 'PKCE S256 is required' ), 'Generated guide does not contradict the implemented pre-registered OAuth support' );
    sfo_check( str_contains( $doc, 'transport.connectedToAI=true' ) && str_contains( $doc, 'transport.mcpEndpoint matching' ) && str_contains( $doc, 'Do not save a test draft, upload a file or publish as a connection check' ), 'Connection verification requires live tools and remains read-only' );
    sfo_check( str_contains( $doc, 'No separate sample file or real materials are needed' ) && str_contains( $doc, 'Fictional practice brief' ) && str_contains( $doc, 'Proceed only after the user chooses the sample' ), 'One downloaded guide contains a fictional sample requiring explicit opt-in' );
    sfo_check( str_contains( $doc, 'The sample is optional, not an onboarding gate' ) && str_contains( $doc, 'Do not offer it' ) && str_contains( $doc, 'existing article brief without asking for it again' ), 'Skipping, resuming and completing the sample preserve the original conversation' );
    sfo_check( str_contains( $doc, '### Design and Layout Guidance' ) && str_contains( $doc, 'Work with the current theme\'s look' ) && str_contains( $doc, 'Assemble a separate article layout' ) && str_contains( $doc, 'Do not add a mandatory design questionnaire' ), 'AI guidance introduces both layout directions without another onboarding gate' );
    sfo_check( str_contains( $doc, 'MCP context does not expose the active theme\'s styles' ) && str_contains( $doc, 'pixel-perfect reproduction' ) && str_contains( $doc, 'do not automatically add components or media to the sample' ), 'Design guidance preserves theme-inspection limits and the bounded sample' );
    sfo_check( str_contains( $doc, 'expectedVersion 0 and fresh block IDs' ) && str_contains( $doc, 'different article ID for' ) && str_contains( $doc, 'set-publication or schedule the sample' ) && str_contains( $doc, 'capabilities.portablePreview' ), 'Sample instructions isolate drafts, forbid publication and respect preview authority' );
    preg_match( '/```json\n(.*?)\n```/s', $doc, $matches );
    $data = json_decode( $matches[1] ?? '', false, 128, JSON_THROW_ON_ERROR );
    sfo_check( $data->sharedComponents && isset( $data->schemas->compose, $data->behaviors->items, $data->composeTemplate ), 'One file includes shared component contracts, schemas, behavior catalog and new-article template' );
    sfo_check( ! $data->privateArticlesIncluded && ! $data->personalComponentsIncluded && ! $data->usageIncluded && ! $data->transport->credentialsIncluded, 'Export scope explicitly excludes private work, usage and credentials' );
    sfo_check( ! str_contains( $doc, 'PRIVATE_COMPONENT_' . $run ) && ! str_contains( $doc, 'PRIVATE_EXAMPLE_' . $run ) && ! str_contains( $doc, $media['staged']['image']->id ) && ! str_contains( $doc, $id ), 'Even the current user private component, draft and media IDs are excluded' );
    sfo_check( ! str_contains( $doc, 'sf_local_admin' ) && ! str_contains( $doc, wp_create_nonce( 'sf-ai-guide' ) ) && ! str_contains( $doc, wp_create_nonce( 'wp_rest' ) ), 'Guide contains no account name or actual WordPress nonces' );
    Snapshot::shape( $data->composeTemplate, Catalog::schemas()['compose'] );
    $entry = $data->sharedComponents[0]; $block = $entry->blockTemplate;
    foreach ( $data->sharedComponents as $c ) {
        sfo_check( ! property_exists( $c, 'html' ) && ! property_exists( $c, 'css' ) && ! property_exists( $c, 'usedByMe' ) && ! property_exists( $c, 'usedRevisions' ), 'Shared contract omits source and usage: ' . $c->id );
    }
    sfo_check( 0 === $data->composeTemplate->expectedVersion && ! $data->composeTemplate->article->blocks && $block->componentRevision === $entry->revision, 'New-article proposal and block templates use current catalog identity' );
    sfo_check( str_contains( $doc, 'fresh Article handoff' ) && str_contains( $doc, 'Automatic AI/MCP connection' ) && str_contains( $doc, 'Prefer native HTML, then CSS' ), 'Guide preserves edit handoff, connection limits and native/CSS-first behavior guidance' );
    parse_str( wp_parse_url( html_entity_decode( Onboarding::url() ), PHP_URL_QUERY ), $query );
    sfo_check( 'sf_ai_guide' === $query['action'] && wp_verify_nonce( $query['_wpnonce'], 'sf-ai-guide' ), 'Download URL is authenticated and nonce-protected' );
    echo 'WordPress onboarding: ' . $checks . ' passed.', PHP_EOL;
} finally {
    remove_filter( 'pre_determine_locale', $test_locale );
    unload_textdomain( 'atshift-deft', true );
    wp_set_current_user( $admin ); sf_media_test_cleanup( $id );
    $wpdb->delete( Catalog::versions(), array( 'component_id' => $prefix ) ); $wpdb->delete( Catalog::table(), array( 'component_id' => $prefix ) );
    foreach ( $users as $uid ) wp_delete_user( $uid );
}
