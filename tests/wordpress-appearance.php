<?php
require_once __DIR__ . '/wordpress-connection-context.php';
// Run with WP-CLI eval-file in a disposable local database, never on a user site.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! preg_match( '/^sf_deft_(?:appearance|post_types)_[a-f0-9]+$/D', DB_NAME ) ) {
    throw new RuntimeException( 'Use a disposable local appearance-test database.' );
}

use Atshift\Deft\Catalog;
use Atshift\Deft\ComponentBuilder;
use Atshift\Deft\Connections;
use Atshift\Deft\Mcp;
use Atshift\Deft\Snapshot;
use Atshift\Deft\Store;
use Atshift\Deft\Publications;

$GLOBALS['sf_appearance_checks'] = 0; $run = wp_generate_uuid4();
function sf_appearance_check( $ok, $message ) {
    if ( ! $ok ) throw new RuntimeException( $message );
    ++$GLOBALS['sf_appearance_checks'];
}
function sf_appearance_call( $name, $body = array() ) {
    $result = Mcp::execute( $name, json_decode( Snapshot::json( $body ), true ) );
    if ( is_wp_error( $result ) ) throw new RuntimeException( $result->get_error_code() . ': ' . $result->get_error_message() );
    return $result;
}
function sf_appearance_key( $user, $authoring, $publishing = false ) {
    global $wpdb;
    $id = wp_generate_uuid4(); $secret = bin2hex( random_bytes( 32 ) );
    $wpdb->insert( Connections::table(), array( 'id' => $id, 'owner_id' => $user, 'name' => 'Disposable appearance test',
        'secret_hash' => hash( 'sha256', $secret ), 'created' => time(), 'expires' => time() + HOUR_IN_SECONDS,
        'authoring' => (int) $authoring, 'publishing' => (int) $publishing ) );
    return array( 'token' => 'sfc_' . $id . '_' . $secret, 'route' => $publishing ? Connections::PUBLISH_ROUTE : Connections::ROUTE );
}
function sf_appearance_connect( $key ) {
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $request = new WP_REST_Request( 'POST', $key['route'] );
    $request->set_body( '{}' );
    $request->set_header( 'authorization', 'Bearer ' . $key['token'] );
    $result = sf_test_begin_connection( $request );
    if ( is_wp_error( $result ) ) throw new RuntimeException( $result->get_error_message() );
}
function sf_appearance_body( $recipe, $version = 0 ) {
    $context = sf_appearance_call( 'context' );
    return array( 'recipe' => $recipe, 'expectedVersion' => $version, 'reuse' => array(
        'catalogToken' => $context['catalogToken'], 'considered' => array_map( static function( $c ) {
            return array( 'id' => $c['id'], 'revision' => $c['revision'] );
        }, $context['components'] ), 'reason' => 'The existing components lack this reusable field-key appearance contract.' ) );
}
function sf_appearance_theme_state() {
    global $wpdb;
    return Snapshot::json( array( get_stylesheet(), get_theme_mods(), $wpdb->get_results(
        "SELECT ID,post_content FROM {$wpdb->posts} WHERE post_type IN ('custom_css','wp_global_styles') ORDER BY ID", ARRAY_A ) ) );
}
function sf_appearance_block( $id, $component, $variant = 'default' ) {
    $values = array();
    foreach ( $component->fields as $field ) $values[$field->key] = (object) array(
        'value' => 'link' === $field->type ? 'https://example.invalid/register' : ( 'image' === $field->type ? '' : 'Private example: ' . $field->label ),
        'alt' => 'link' === $field->type ? 'Registration details' : '' );
    return (object) array( 'id' => $id, 'componentId' => $component->id, 'componentRevision' => $component->revision,
        'variantId' => $variant, 'values' => (object) $values );
}

sf_appearance_check( Connections::ready(), 'Official MCP Adapter is available.' );
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]->ID;
$a = wp_insert_user( array( 'user_login' => 'appearance-a-' . $run, 'user_pass' => wp_generate_password( 32 ), 'role' => 'atshde_publisher' ) );
$b = wp_insert_user( array( 'user_login' => 'appearance-b-' . $run, 'user_pass' => wp_generate_password( 32 ), 'role' => 'atshde_author' ) );
sf_appearance_check( ! is_wp_error( $a ) && ! is_wp_error( $b ), 'Create isolated test owners.' );
$key = sf_appearance_key( $a, true ); $other = sf_appearance_key( $b, true ); $old = sf_appearance_key( $a, false );
// Core may create the theme's initial global-styles record lazily on first render.
wp_get_global_stylesheet();
wp_get_custom_css();
$theme_before = sf_appearance_theme_state();
sf_appearance_connect( $old );
sf_appearance_check( ! sf_appearance_call( 'context' )['capabilities']['styleStructures'], 'Draft-only keys do not gain styling authority.' );
sf_appearance_check( is_wp_error( Mcp::execute( 'create-structure', array() ) ), 'Draft-only writes are denied.' );
sf_appearance_connect( $key );
$context = sf_appearance_call( 'context' );
sf_appearance_check( $context['capabilities']['styleStructures'] && ! $context['capabilities']['manageShared'], 'Bounded styling does not grant shared-management authority.' );
sf_appearance_check( isset( $context['schemas']['structure']['properties']['recipe']['properties']['appearance'] ), 'Live authoring schema advertises typed appearance.' );

$recipe = ComponentBuilder::structure_example();
$recipe->id = 'appearance-' . $run; $recipe->key = 'appearance_' . str_replace( '-', '_', $run );
$recipe->name = 'Announcement panel'; $recipe->purpose = 'Reusable announcement layout'; $recipe->layout = 'columns';
$recipe->fields = array();
foreach ( array( 'heading' => 'text', 'body' => 'textarea', 'deadline' => 'text', 'registration' => 'link' ) as $name => $type ) {
    $recipe->fields[] = (object) array( 'id' => $name, 'key' => $name, 'label' => ucfirst( $name ), 'type' => $type, 'required' => true );
}
$recipe->appearance = json_decode( '{"container":{"backgroundColor":"#ffffff","borderColor":"#cbd5d1","borderRadius":8,"padding":20,"gap":16},"columns":"wide-left","fields":[{"key":"heading","span":"full","text":{"color":"#153d38","fontSize":28,"fontWeight":"bold"}},{"key":"deadline","box":{"backgroundColor":"#fff0ec","padding":16},"text":{"color":"#8c2920","fontWeight":"bold"}},{"key":"registration","linkStyle":"button","text":{"color":"#153d38","fontWeight":"bold"}}]}' );
$result = sf_appearance_call( 'create-structure', sf_appearance_body( $recipe ) ); $c = $result['component'];
sf_appearance_check( 'shared' === $result['scope'] && 'structure' === $result['definitionKind'], 'Decoration is a normal reusable shared structure.' );
sf_appearance_check( isset( $result['appearance'], $result['structureRecipe']->appearance ), 'The catalog exposes reusable appearance and a content-free recipe.' );
sf_appearance_check( ! array_filter( $c->fields, static function( $f ) { return '' !== $f->value || '' !== $f->alt; } ), 'No article examples enter the shared component.' );
sf_appearance_check( str_contains( $c->css, '.sf-article > .sf-block > .sf-appearance-' ) && ! str_contains( $c->css, 'nth-child' ), 'Generated decoration is scoped and not positional.' );
sf_appearance_check( str_contains( $c->css, 'sf-appearance-field-deadline' ) && str_contains( $c->html, 'sf-appearance-value-deadline' ), 'Stable field keys identify both layout and text targets.' );
sf_appearance_check( str_contains( $c->css, ':focus-visible' ) && str_contains( $c->css, '@media(max-width:600px)' ), 'Button focus and mobile stacking are emitted.' );

$duplicate = json_decode( Snapshot::json( $recipe ) ); $duplicate->id .= '-copy'; $duplicate->key .= '_copy';
$duplicate->name = 'Different label'; $duplicate->appearance->fields = array_reverse( $duplicate->appearance->fields );
$duplicate->appearance->container->backgroundColor = '#FFFFFF';
$reused = sf_appearance_call( 'create-structure', sf_appearance_body( $duplicate ) );
sf_appearance_check( ! empty( $reused['reused'] ) && $c->id === $reused['id'] && ! Catalog::read( $duplicate->id ), 'Normalized styling deduplicates despite key order, color case and names.' );
sf_appearance_check( is_wp_error( Mcp::execute( 'create-structure', json_decode( Snapshot::json( sf_appearance_body( $recipe, 1 ) ), true ) ) ), 'AI cannot overwrite shared definitions.' );

$bad_appearances = array(
    '{"css":"body{display:none}"}', '{"container":{"position":"fixed"}}', '{"container":{"backgroundColor":"url(https://example.invalid)"}}',
    '{"container":{"color":"#ffffff;display:none"}}', '{"container":{"padding":-1}}', '{"container":{"padding":49}}',
    '{"container":{"padding":"20"}}', '{"container":{"fontSize":0}}', '{"container":{"fontWeight":"900"}}',
    '{"fields":[{"key":"missing","text":{"color":"#ff0000"}}]}', '{"fields":[{"key":"body"},{"key":"body"}]}',
    '{"fields":[{"key":"body","selector":"body"}]}', '{"fields":[{"key":"body","linkStyle":"button"}]}',
    '{"fields":[{"key":"heading\"],body","text":{"color":"#ff0000"}}]}', '{"container":{"backgroundColor":"</style><script>alert(1)</script>"}}',
    '{"container":{"color":"var(--private-data)"}}', '{"container":{"shadow":"url(https://example.invalid)"}}'
);
foreach ( $bad_appearances as $json ) {
    $bad = clone $recipe; $bad->appearance = json_decode( $json ); $rejected = false;
    try { ComponentBuilder::structure( $bad ); } catch ( InvalidArgumentException $error ) { $rejected = true; }
    sf_appearance_check( $rejected, 'Reject unsafe or invalid appearance: ' . $json );
}
$bad = clone $recipe; $bad->css = 'body{display:none}';
sf_appearance_check( is_wp_error( Mcp::execute( 'create-structure', json_decode( Snapshot::json( sf_appearance_body( $bad ) ), true ) ) ), 'Raw CSS remains rejected through the actual authoring dispatcher.' );

$blue = json_decode( Snapshot::json( $recipe ) ); $blue->id .= '-blue'; $blue->key .= '_blue'; $blue->name = 'Blue announcement';
$blue->appearance->fields[0]->text->color = '#243d85';
$second = sf_appearance_call( 'create-structure', sf_appearance_body( $blue ) )['component'];
preg_match( '/sf-appearance-[a-f0-9]{24}/', $c->html, $scope_a ); preg_match( '/sf-appearance-[a-f0-9]{24}/', $second->html, $scope_b );
sf_appearance_check( $scope_a[0] !== $scope_b[0] && ! str_contains( $second->css, $scope_a[0] ), 'Distinct appearances cannot overwrite each other through a shared class.' );
$reordered = clone $recipe; $reordered->fields = array_reverse( $recipe->fields ); $reordered_c = ComponentBuilder::structure( $reordered );
sf_appearance_check( str_contains( $reordered_c->css, 'sf-appearance-value-deadline{color:#8c2920;' ), 'Field reordering does not retarget deadline styling.' );

$qa = ComponentBuilder::structure_example(); $qa->id = 'appearance-qa-' . $run; $qa->key = 'qa_' . str_replace( '-', '_', $run );
$qa->appearance = json_decode( '{"container":{"borderColor":"#cbd5d1","padding":16},"fields":[{"key":"question","text":{"color":"#153d38","fontWeight":"bold"}},{"key":"answer","box":{"padding":12},"text":{"lineHeight":"relaxed"}}]}' );
$q = sf_appearance_call( 'create-structure', sf_appearance_body( $qa ) )['component'];
sf_appearance_check( str_contains( $q->css, ':is(.sf-appearance-field-question,summary)' ), 'The same Q&A field styling supports visible and accordion presentations.' );
foreach ( array( 'stack', 'qa' ) as $layout ) {
    $bad = clone $qa; $bad->layout = $layout; $bad->appearance = (object) array( 'columns' => 'equal' ); $rejected = false;
    try { ComponentBuilder::structure( $bad ); } catch ( InvalidArgumentException $error ) { $rejected = true; }
    sf_appearance_check( $rejected, 'Column settings cannot silently apply to ' . $layout );
}

$draft = $context['composeTemplate']; $draft['article']['title'] = 'DeFt component appearance verification';
$draft['article']['blocks'] = array( sf_appearance_block( 'shared-block-id', $c ), sf_appearance_block( 'blue-panel', $second ),
    sf_appearance_block( 'qa-visible', $q, 'list' ), sf_appearance_block( 'qa-disclosure', $q, 'accordion' ) );
$saved = sf_appearance_call( 'save-draft', $draft ); $id = $saved['articleId']; $row = Store::read( $id );
sf_appearance_check( 1 === $saved['version'] && str_contains( $row['css'], $c->css ) && str_contains( $row['rendered'], $scope_a[0] ), 'Saving pins generated HTML and CSS in the same immutable snapshot.' );
sf_appearance_check( str_contains( $row['rendered'], '<summary>' ) && str_contains( $row['rendered'], 'sf-appearance-field-answer' ), 'Accordion rendering preserves styling targets.' );
sf_appearance_check( is_wp_error( Mcp::execute( 'save-draft', json_decode( Snapshot::json( $draft ), true ) ) ), 'Stale article writes remain rejected.' );
$preview = sf_appearance_call( 'get-preview', array( 'articleId' => $id, 'expectedVersion' => 1 ) );
sf_appearance_check( str_contains( $preview['html'], $row['css'] ) && ! $preview['themeIncluded'], 'Portable preview includes all component decoration without theme CSS.' );
sf_appearance_check( ! str_contains( $preview['html'], '<script' ) && str_contains( $preview['html'], 'default-src' ), 'Portable preview retains its isolation policy.' );

sf_appearance_connect( $other ); $other_context = sf_appearance_call( 'context' );
$found = array_values( array_filter( $other_context['sharedComponents'], static function( $item ) use ( $c ) { return $item['id'] === $c->id; } ) )[0];
sf_appearance_check( isset( $found['structureRecipe']->appearance ) && ! $found['canEdit'], 'Other authors can discover and reuse, not overwrite, the styled component.' );
sf_appearance_check( ! str_contains( Snapshot::json( $other_context['components'] ), 'Private example:' ), 'Shared recipes never expose private article values.' );
sf_appearance_check( is_wp_error( Mcp::execute( 'get-draft', array( 'articleId' => $id ) ) ), 'Shared appearance does not allow cross-owner draft access.' );
$other_draft = $other_context['composeTemplate']; $other_draft['article']['blocks'] = array( sf_appearance_block( 'shared-block-id', $second ) );
$other_saved = sf_appearance_call( 'save-draft', $other_draft );
sf_appearance_check( ! str_contains( Store::read( $other_saved['articleId'] )['css'], $scope_a[0] ), 'A reused block ID in another article cannot pull in the first appearance.' );

sf_appearance_connect( $key ); $update = sf_appearance_body( $recipe, 1 ); $update['recipe'] = json_decode( Snapshot::json( $recipe ) );
$update['recipe']->appearance->fields[0]->text->color = '#632780';
wp_set_current_user( $admin );
$request = new WP_REST_Request( 'POST' ); $request->set_header( 'content-type', 'application/json' );
// Re-read the manager's catalog receipt; receipts are owner-bound.
$catalog_request = new WP_REST_Request( 'GET' ); $manager_context = Atshift\Deft\CatalogContext::get( $catalog_request );
$update['reuse']['catalogToken'] = $manager_context['catalogToken']; $request->set_body( Snapshot::json( $update ) );
$updated = Catalog::structure( $request );
sf_appearance_check( ! is_wp_error( $updated ) && 2 === $updated['version'], 'A manager can version the shared appearance through the existing catalog.' );
sf_appearance_check( $row['payload'] === Store::read( $id )['payload'] && $row['css'] === Store::read( $id )['css'], 'A shared revision leaves already saved article content and styling unchanged.' );
$historical = Catalog::response( Catalog::read( $c->id, $c->revision ) );
sf_appearance_check( null === $historical['structureRecipe'] && null === $historical['appearance'], 'Historical revisions do not claim the current recipe as their appearance.' );

$publish_key = sf_appearance_key( $a, true, true ); sf_appearance_connect( $publish_key );
$published = sf_appearance_call( 'set-publication', array( 'articleId' => $id, 'expectedVersion' => 1,
    'expectedPublicationVersion' => 0, 'requestId' => wp_generate_uuid4(), 'timing' => 'now', 'publishAt' => '', 'timeZone' => '' ) );
sf_appearance_check( 'published' === $published['state'], 'Publication succeeds using the exact styled saved revision on the isolated site.' );
sf_appearance_check( str_contains( get_post_field( 'post_content', $published['postId'] ), $scope_a[0] ), 'Publication keeps component-scoped HTML.' );
$GLOBALS['wp_query'] = new WP_Query( array( 'p' => $published['postId'] ) );
do_action( 'wp_enqueue_scripts' ); $styles = wp_styles();
sf_appearance_check( str_contains( Snapshot::json( $styles->registered ), $scope_a[0] ), 'Published page enqueues its stored component CSS.' );
$theme_after = sf_appearance_theme_state();
sf_appearance_check( $theme_before === $theme_after, 'Neither Additional CSS, global styles nor theme settings were changed.' );

$legacy_file = getenv( 'SF_APPEARANCE_LEGACY' );
if ( $legacy_file ) {
    foreach ( json_decode( file_get_contents( $legacy_file ), true ) as $layout => $expected ) {
        $legacy = ComponentBuilder::structure_example(); $legacy->id = 'legacy-fixture'; $legacy->key = 'legacy_fixture'; $legacy->layout = $layout;
        sf_appearance_check( $expected === ComponentBuilder::structure( $legacy )->revision, 'An unstyled ' . $layout . ' recipe is byte-compatible with released 0.5.2.' );
    }
}
$out = getenv( 'SF_APPEARANCE_ARTIFACT_DIR' );
if ( $out && is_dir( $out ) ) {
    file_put_contents( $out . '/portable-preview.html', $preview['html'] );
    file_put_contents( $out . '/appearance-fixture.json', Snapshot::json( array( 'publicUrl' => $published['url'], 'articleId' => $id,
        'checks' => $GLOBALS['sf_appearance_checks'], 'scopeA' => $scope_a[0], 'scopeB' => $scope_b[0], 'componentId' => $c->id,
        'key' => $key, 'otherKey' => $other, 'oldKey' => $old ) ) );
    chmod( $out . '/appearance-fixture.json', 0600 );
}
echo Snapshot::json( array( 'appearance_checks' => $GLOBALS['sf_appearance_checks'], 'publicUrl' => $published['url'] ) ), PHP_EOL;
