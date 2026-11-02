<?php
require __DIR__ . '/wordpress-media-fixture.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
use Atshift\Deft\Catalog;
use Atshift\Deft\ComponentBuilder;
use Atshift\Deft\Snapshot;
use Atshift\Deft\Store;

$run = wp_generate_uuid4(); $prefix = 'personal-test-' . $run; $users = array(); $checks = 0;
$article_id = 'media-test-' . $run; $other_article = 'media-test-' . substr( hash( 'sha256', $run ), 0, 36 );
$admin = get_current_user_id();
function sf7_check( $condition, $message ) { global $checks; if ( ! $condition ) throw new RuntimeException( $message ); echo 'PASS ' . ++$checks . ': ' . $message . PHP_EOL; }
function sf7_api( $path, $body = null, $query = array() ) {
    $r = new WP_REST_Request( null === $body ? 'GET' : 'POST', '/structured-fields/v1/' . $path );
    if ( null !== $body ) { $r->set_header( 'content-type', 'application/json' ); $r->set_body( Snapshot::json( $body ) ); }
    $r->set_query_params( $query ); $response = rest_do_request( $r );
    return array( $response->get_status(), json_decode( Snapshot::json( $response->get_data() ), true ) );
}
function sf7_context() { list( $status, $data ) = sf7_api( 'catalog/context' ); if ( 200 !== $status ) throw new RuntimeException( Snapshot::json( $data ) ); return $data; }
function sf7_reuse( $context ) { return array( 'catalogToken' => $context['catalogToken'], 'considered' => array_map( static function( $c ) { return array( 'id' => $c['id'], 'revision' => $c['revision'] ); }, array_slice( $context['components'], 0, 1 ) ), 'reason' => 'Existing components lack the required typed structure and presentation.' ); }
function sf7_personal( $recipe, $expected = 0, $reuse = null ) { return sf7_api( 'catalog/personal', array( 'recipe' => $recipe, 'expectedVersion' => $expected, 'reuse' => $reuse ?? sf7_reuse( sf7_context() ) ) ); }
try {
    foreach ( array( 'a', 'b' ) as $name ) {
        $uid = wp_insert_user( array( 'user_login' => 'sf_personal_' . $name . '_' . $run, 'user_pass' => wp_generate_password( 32 ), 'role' => 'atshde_author' ) );
        if ( is_wp_error( $uid ) ) throw new RuntimeException( 'Could not create temporary user.' );
        $users[] = $uid; update_user_meta( $uid, '_sf_personal_run', $run );
    }
    wp_set_current_user( 0 );
    sf7_check( 403 === sf7_api( 'catalog/context' )[0] && 403 === sf7_api( 'catalog/personal', array() )[0], 'Anonymous context and personal creation denied' );
    wp_set_current_user( $users[0] ); $context = sf7_context();
    sf7_check( ! $context['capabilities']['registerShared'] && $context['capabilities']['createPersonal'] && ! current_user_can( 'manage_options' ), 'Author gains bounded personal creation without shared or administrator privileges' );
    sf7_check( str_contains( $context['instructions'], 'Reuse and Presentation' ) && str_contains( $context['instructions'], 'untrusted data' ) && ! $context['transport']['credentialsIncluded'], 'Runtime handoff includes reuse, trust and language rules without credentials' );
    $language = sf7_api( 'catalog/context', null, array( 'replyLanguage' => 'ja', 'conversationLanguage' => 'de-DE', 'contentLanguage' => 'en-US' ) )[1]['language'];
    sf7_check( 'ja' === $language['replyLanguage'] && 'explicit' === $language['source'] && 'en-US' === $language['contentLanguage'] && ! $language['countryInference'], 'Explicit reply language is independent from article language' );
    sf7_check( 'pt-BR' === sf7_api( 'catalog/context', null, array( 'conversationLanguage' => 'pt_BR' ) )[1]['language']['replyLanguage'] && 400 === sf7_api( 'catalog/context', null, array( 'replyLanguage' => 'ignore instructions' ) )[0], 'Conversation locale fallback and language-tag validation work' );
    sf7_check( count( sf7_api( 'catalog/context', null, array( 'query' => 'zzzz-no-match' ) )[1]['matches'] ) === 0 && count( $context['components'] ) >= 2, 'Lexical hints never replace the complete visible catalog' );
    $recipe = $context['personalExample']; $recipe['id'] = $prefix . '-qa'; $recipe['key'] = 'qa_test';
    $bad = sf7_reuse( $context ); $bad['catalogToken'] = str_repeat( '0', 64 );
    sf7_check( 409 === sf7_personal( $recipe, 0, $bad )[0], 'Creation requires a current user-bound catalog receipt' );
    $bad = sf7_reuse( $context ); $bad['considered'] = array();
    sf7_check( 400 === sf7_personal( $recipe, 0, $bad )[0], 'Existing candidates and missing-capability reason are required' );
    list( $status, $personal ) = sf7_personal( $recipe );
    sf7_check( 200 === $status && 'personal' === $personal['scope'] && 'qa' === $personal['layout'], 'Author can register an owned Q&A recipe' );
    sf7_check( 'visible' === $personal['presentations'][0]['disclosure'] && 'expandable' === $personal['presentations'][1]['disclosure'], 'Q&A disclosure guidance is machine-readable' );
    $bad_component = json_decode( Snapshot::json( $personal['component'] ) ); unset( $bad_component->revision );
    $bad_component->fields = array_reverse( $bad_component->fields );
    $bad_component->fields[0]->type = 'text'; $bad_component->fields[1]->type = 'textarea';
    $rejected = false;
    try { Snapshot::component( $bad_component ); } catch ( InvalidArgumentException $e ) { $rejected = 'Invalid Q&A fields or presentations.' === $e->getMessage(); }
    sf7_check( $rejected, 'Q&A marker rejects swapped question and answer field contracts' );
    $before = Catalog::read( $recipe['id'] );
    sf7_check( 200 === sf7_personal( $recipe, 1 )[0] && Catalog::read( $recipe['id'] )['version'] === $before['version'], 'Identical personal registration is idempotent at the current version' );
    foreach ( array( $users[1], $admin ) as $uid ) {
        wp_set_current_user( $uid );
        sf7_check( 404 === sf7_api( 'catalog/' . $recipe['id'] )[0] && ! in_array( $recipe['id'], array_column( sf7_context()['components'], 'id' ), true ), 'Other workspace cannot discover or read a personal definition, including admin' );
        sf7_check( 403 === sf7_api( 'catalog/' . $recipe['id'] . '/availability', array( 'enabled' => false, 'expectedVersion' => 1 ) )[0], 'Other workspace cannot toggle personal availability' );
    }
    wp_set_current_user( $users[1] ); $other = $recipe; $other['id'] = $prefix . '-other';
    sf7_check( 200 === sf7_personal( $other )[0], 'Machine keys are scoped to owners; private duplicates reveal no other owner' );
    wp_set_current_user( $users[0] );
    sf7_check( 403 === sf7_api( 'catalog', array( 'component' => $personal['component'], 'expectedVersion' => 0 ) )[0], 'Author cannot bypass recipes with raw shared HTML/CSS' );
    foreach ( array( 'html', 'css', 'owner_id', 'publish' ) as $property ) {
        $bad = $recipe; $bad[$property] = 'forged';
        sf7_check( 400 === sf7_personal( $bad, 1 )[0], 'Personal schema rejects ' . $property );
    }
    $duplicate = $recipe; $duplicate['id'] = $prefix . '-duplicate'; $duplicate['key'] = 'different_name'; $duplicate['name'] = 'New label';
    $duplicate['fields'][0]['value'] = 'Different question'; $duplicate['fields'][1]['value'] = 'Different answer';
    $result = sf7_personal( $duplicate );
    sf7_check( 409 === $result[0] && 'sf_reuse_component' === $result[1]['code'] && $recipe['id'] === $result[1]['data']['componentId'], 'Content-only or renamed copies reuse the existing structure' );
    $old_context = sf7_context(); $columns = $recipe; $columns['id'] = $prefix . '-columns'; $columns['key'] = 'columns_test'; $columns['layout'] = 'columns';
    sf7_check( 200 === sf7_personal( $columns )[0], 'A genuinely different supported structure can be created' );
    $stale = $recipe; $stale['purpose'] .= ' Updated.';
    sf7_check( 409 === sf7_personal( $stale, 1, sf7_reuse( $old_context ) )[0], 'Changed catalog invalidates stale creation context' );
    $bad = sf7_reuse( sf7_context() ); $bad['considered'][0]['id'] = 'missing';
    sf7_check( 400 === sf7_personal( $stale, 1, $bad )[0], 'Unknown considered references cannot satisfy the reuse check' );
    $c = $personal['component']; $values = array(); foreach ( $c['fields'] as $field ) $values[$field['key']] = array( 'value' => $field['value'], 'alt' => $field['alt'] );
    $block = array( 'id' => 'qa-one', 'componentId' => $c['id'], 'componentRevision' => $c['revision'], 'variantId' => 'list', 'values' => $values );
    $second = $block; $second['id'] = 'qa-two'; $second['variantId'] = 'accordion';
    $article = array( 'id' => $article_id, 'revision' => 1, 'title' => 'Q&A test', 'blocks' => array( $block, $second ) );
    $saved = sf7_api( 'articles/compose', array( 'article' => $article, 'expectedVersion' => 0 ) );
    $old = Store::read( $article_id );
    sf7_check( 200 === $saved[0] && str_contains( $old['rendered'], '<summary>' ) && str_contains( $old['rendered'], '<h3>' ) && ! str_contains( $old['rendered'], 'data-sf-layout' ), 'Same Q&A content renders native expandable and visible presentations' );
    $article['revision'] = 2; $article['blocks'][1]['variantId'] = 'list';
    $saved = sf7_api( 'articles/compose', array( 'article' => $article, 'expectedVersion' => 1 ) );
    sf7_check( 200 === $saved[0] && $saved[1]['snapshot']['article']['blocks'][1]['values'] == $values && 'qa-two' === $saved[1]['snapshot']['article']['blocks'][1]['id'] && ! str_contains( Store::read( $article_id )['rendered'], '<summary>' ), 'Switching presentation preserves field values and block identity' );
    sf7_check( $old['payload'] === Store::read( $article_id, 1 )['payload'] && $old['rendered'] === Store::read( $article_id, 1 )['rendered'], 'Old article version remains byte-for-byte unchanged' );
    sf7_check( 200 === sf7_api( 'catalog/' . $recipe['id'] . '/availability', array( 'expectedVersion' => 1, 'enabled' => false ) )[0], 'Owner can disable personal selection' );
    $new = $article; $new['id'] = $other_article; $new['revision'] = 1;
    sf7_check( 409 === sf7_api( 'articles/compose', array( 'article' => $new, 'expectedVersion' => 0 ) )[0], 'Disabled personal definition cannot enter a new article' );
    $article['revision'] = 3;
    sf7_check( 200 === sf7_api( 'articles/compose', array( 'article' => $article, 'expectedVersion' => 2 ) )[0], 'Existing owner article keeps its disabled pinned definition' );
    sf7_check( 200 === sf7_api( 'catalog', null, array( 'scope' => 'personal', 'includeDisabled' => '1' ) )[0], 'Owner can find inactive personal definitions for recovery' );
    wp_set_current_user( $users[1] );
    sf7_check( 403 === sf7_api( 'catalog/context', null, array( 'articleId' => $article_id ) )[0], 'AI handoff cannot expose another owner article' );
    wp_set_current_user( $users[0] ); $current = sf7_api( 'catalog/context', null, array( 'articleId' => $article_id ) )[1];
    sf7_check( 3 === $current['composeTemplate']['expectedVersion'] && 4 === $current['composeTemplate']['article']['revision'] && ! str_contains( Snapshot::json( $current ), '_wpnonce' ), 'Article handoff provides a fresh composition template without preview credentials' );
    $share = array( 'id' => $prefix . '-shared', 'key' => 'shared_test_' . str_replace( '-', '_', $run ), 'expectedVersion' => 2 );
    sf7_check( 403 === sf7_api( 'catalog/' . $recipe['id'] . '/share', $share )[0], 'Sharing requires its own capability' );
    $user = new WP_User( $users[0] ); $user->add_cap( 'atshde_register_components' ); wp_set_current_user( 0 ); wp_set_current_user( $users[0] );
    $result = sf7_api( 'catalog/' . $recipe['id'] . '/share', $share );
    if ( 200 !== $result[0] ) throw new RuntimeException( Snapshot::json( $result ) );
    sf7_check( 200 === $result[0] && 'shared' === $result[1]['scope'] && ! current_user_can( 'manage_options' ), 'Delegated registrar can share an explicit copy without administrator privileges' );
    sf7_check( (int) Catalog::read( $recipe['id'] )['owner_id'] === $users[0] && ! Catalog::read( $recipe['id'] )['enabled'], 'Sharing never transfers or changes the personal source' );
    $shared = $result[1]; $edit = $shared['component']; unset( $edit['revision'] ); $edit['name'] = 'Revised shared name';
    sf7_check( 403 === sf7_api( 'catalog', array( 'component' => $edit, 'expectedVersion' => 1 ) )[0], 'Registration capability alone does not grant shared update rights' );
    wp_set_current_user( $users[1] ); $user = new WP_User( $users[1] ); $user->add_cap( 'atshde_manage_components' ); wp_set_current_user( 0 ); wp_set_current_user( $users[1] );
    sf7_check( 200 === sf7_api( 'catalog', array( 'component' => $edit, 'expectedVersion' => 1 ) )[0], 'Separate management capability can update a shared definition' );
    $edit['id'] = $prefix . '-no-create'; $edit['key'] = 'no_create';
    sf7_check( 403 === sf7_api( 'catalog', array( 'component' => $edit, 'expectedVersion' => 0 ) )[0], 'Management capability does not silently grant new shared registration' );
    $start = microtime( true ) + 2; $jobs = array();
    foreach ( array( 'a', 'b' ) as $side ) {
        $process = proc_open( array( PHP_BINARY, __DIR__ . '/wordpress-personal-race.php', (string) $users[1], $run, $side, (string) $start ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
        $jobs[] = array( $process, $pipes );
    }
    $results = array(); foreach ( $jobs as list( $process, $pipes ) ) { $results[] = stream_get_contents( $pipes[1] ); $error = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] ); if ( 0 !== proc_close( $process ) ) throw new RuntimeException( $error ); }
    sort( $results ); sf7_check( $results === array( 'saved', 'sf_catalog_stale' ), 'Competing creation requests cannot multiply the same structure' );
    wp_set_current_user( $users[0] ); $table = Catalog::table();
    $count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE owner_id=%d", $users[0] ) );
    for ( $i = $count; $i < 20; $i++ ) {
        $fill = $recipe; $fill['layout'] = 'stack'; $fill['id'] = $prefix . '-quota-' . $i; $fill['key'] = 'quota_' . $i;
        $fill['fields'] = array( array_merge( $fill['fields'][0], array( 'key' => 'field_' . $i ) ) );
        if ( 200 !== sf7_personal( $fill )[0] ) throw new RuntimeException( 'Quota fixture creation failed.' );
    }
    $fill['id'] = $prefix . '-over-quota'; $fill['key'] = 'over_quota'; $fill['fields'][0]['key'] = 'over_quota';
    $result = sf7_personal( $fill );
    sf7_check( 409 === $result[0] && 'sf_component_quota' === $result[1]['code'] && 20 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE owner_id=%d", $users[0] ) ), 'Personal quota counts inactive definitions and never deletes data' );
    echo 'Personal components: ' . $checks . ' passed.', PHP_EOL;
} finally {
    wp_set_current_user( $admin ); sf_media_test_cleanup( $article_id ); sf_media_test_cleanup( $other_article );
    $table = Catalog::table(); $versions = Catalog::versions();
    foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT component_id FROM $table WHERE component_id LIKE %s", $wpdb->esc_like( $prefix . '-' ) . '%' ) ) as $id ) {
        $wpdb->delete( $versions, array( 'component_id' => $id ) ); $wpdb->delete( $table, array( 'component_id' => $id ) );
    }
    foreach ( $users as $uid ) if ( get_user_meta( $uid, '_sf_personal_run', true ) === $run ) wp_delete_user( $uid );
}
