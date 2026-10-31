<?php
require __DIR__ . '/wordpress-media-fixture.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
use StructuredFields\Catalog;
use StructuredFields\ComponentUsage;
use StructuredFields\Snapshot;
use StructuredFields\Store;

$run = wp_generate_uuid4(); $prefix = 'structure-test-' . $run; $users = array(); $checks = 0;
$articles = array( 'media-test-' . $run, 'media-test-' . wp_generate_uuid4(), 'media-test-' . wp_generate_uuid4() );
$admin = get_current_user_id();
function sf8_check( $condition, $message ) { global $checks; if ( ! $condition ) throw new RuntimeException( $message ); echo 'PASS ' . ++$checks . ': ' . $message . PHP_EOL; }
function sf8_api( $path, $body = null, $query = array() ) {
    $r = new WP_REST_Request( null === $body ? 'GET' : 'POST', '/structured-fields/v1/' . $path );
    if ( null !== $body ) { $r->set_header( 'content-type', 'application/json' ); $r->set_body( Snapshot::json( $body ) ); }
    $r->set_query_params( $query ); $response = rest_do_request( $r );
    return array( $response->get_status(), json_decode( Snapshot::json( $response->get_data() ), true ) );
}
function sf8_context() { $r = sf8_api( 'catalog/context' ); if ( 200 !== $r[0] ) throw new RuntimeException( Snapshot::json( $r ) ); return $r[1]; }
function sf8_body( $recipe, $expected = 0 ) {
    $context = sf8_context();
    return array( 'recipe' => $recipe, 'expectedVersion' => $expected, 'reuse' => array( 'catalogToken' => $context['catalogToken'],
        'considered' => array_map( static function( $c ) { return array( 'id' => $c['id'], 'revision' => $c['revision'] ); }, array_slice( $context['components'], 0, 1 ) ),
        'reason' => 'The existing shared components do not provide this required typed structure.' ) );
}
function sf8_structure( $recipe, $expected = 0 ) { return sf8_api( 'catalog/structures', sf8_body( $recipe, $expected ) ); }
function sf8_article( $id, $c, $values ) {
    return array( 'id' => $id, 'revision' => 1, 'title' => 'Private article', 'blocks' => array( array( 'id' => 'block-one',
        'componentId' => $c['id'], 'componentRevision' => $c['revision'], 'variantId' => 'list', 'values' => $values ) ) );
}
try {
    foreach ( array( 'a', 'b' ) as $side ) {
        $uid = wp_insert_user( array( 'user_login' => 'sf_structure_' . $side . '_' . $run, 'user_pass' => wp_generate_password( 32 ), 'role' => 'sf_author' ) );
        if ( is_wp_error( $uid ) ) throw new RuntimeException( 'Could not create temporary user.' );
        $users[] = $uid; update_user_meta( $uid, '_sf_structure_run', $run );
    }
    wp_set_current_user( 0 ); sf8_check( 403 === sf8_api( 'catalog/structures', array() )[0], 'Anonymous structure registration denied' );
    wp_set_current_user( $users[0] ); $context = sf8_context();
    sf8_check( $context['capabilities']['createStructure'] && ! $context['capabilities']['registerShared'], 'Authors can register bounded structures without raw-design authority' );
    sf8_check( $context['selectionOrder'] === array( 'usedComponents', 'sharedComponents', 'createStructure' ) && ! $context['usedComponents'], 'Handoff prioritizes own usage, then the global catalog, then creation' );
    $recipe = $context['structureExample']; $recipe['id'] = $prefix . '-qa'; $recipe['key'] = 'qa_' . str_replace( '-', '_', $run );
    foreach ( array( 'value', 'alt', 'assetRef' ) as $prop ) {
        $bad = $recipe; $bad['fields'][0][$prop] = 'private-data';
        sf8_check( 400 === sf8_structure( $bad )[0], 'Global structure rejects article field property ' . $prop );
    }
    foreach ( array( 'html', 'css', 'js', 'behavior', 'owner_id' ) as $prop ) {
        $bad = $recipe; $bad[$prop] = 'forged'; sf8_check( 400 === sf8_structure( $bad )[0], 'Bounded registration rejects ' . $prop );
    }
    $bad = sf8_body( $recipe ); $bad['reuse']['catalogToken'] = str_repeat( '0', 64 );
    sf8_check( 409 === sf8_api( 'catalog/structures', $bad )[0], 'A genuinely new structure requires fresh catalog review' );
    $registered = sf8_structure( $recipe );
    if ( 200 !== $registered[0] ) throw new RuntimeException( Snapshot::json( $registered ) );
    $c = $registered[1]['component']; $row = Catalog::read( $c['id'] );
    sf8_check( 'shared' === $registered[1]['scope'] && 'structure' === $row['definition_kind'] && 0 === (int) $row['owner_id'], 'New structure belongs to the site catalog, not the creator' );
    sf8_check( ! array_filter( $c['fields'], static function( $f ) { return '' !== $f['value'] || '' !== $f['alt']; } ) && ! str_contains( $row['recipe'], '"value"' ), 'Canonical slots and stored recipe contain no input values or media references' );
    sf8_check( ! sf8_context()['usedComponents'], 'Registration alone does not forge article usage' );
    wp_set_current_user( $users[1] ); $other = sf8_api( 'catalog/' . $c['id'] )[1];
    sf8_check( $c['revision'] === $other['revision'] && ! $other['usedByMe'] && ! $other['canEdit'], 'Another author can discover the same ID and version, but cannot manage it' );
    $duplicate = $recipe; $duplicate['id'] = $prefix . '-duplicate'; $duplicate['key'] = 'renamed'; $duplicate['name'] = 'Another label';
    $result = sf8_structure( $duplicate );
    sf8_check( 200 === $result[0] && $result[1]['reused'] && $c['id'] === $result[1]['id'] && ! Catalog::read( $duplicate['id'] ), 'Identical shared structure resolves to the original ID without copying' );
    $changed = $recipe; $changed['purpose'] .= ' Modified.';
    sf8_check( 403 === sf8_structure( $changed, 1 )[0] && 403 === sf8_api( 'catalog/' . $c['id'] . '/availability', array( 'expectedVersion' => 1, 'enabled' => false ) )[0], 'Reuse authority is not change or disable authority' );
    $values = array( 'question' => array( 'value' => 'A private question', 'alt' => '' ), 'answer' => array( 'value' => 'A confidential answer', 'alt' => '' ) );
    $article = sf8_article( $articles[1], $c, $values ); $empty = $article; $empty['blocks'][0]['values']['answer']['value'] = '';
    sf8_check( 400 === sf8_api( 'articles/compose', array( 'article' => $empty, 'expectedVersion' => 0 ) )[0] && ! ComponentUsage::references(), 'Empty required article values fail without changing the usage list' );
    sf8_check( 200 === sf8_api( 'articles/compose', array( 'article' => $article, 'expectedVersion' => 0 ) )[0], 'Another author can save values against the shared definition without approval' );
    $saved = Store::read( $articles[1] );
    sf8_check( ComponentUsage::references() === array( array( 'id' => $c['id'], 'revision' => $c['revision'] ) ) && sf8_api( 'catalog', null, array( 'scope' => 'used' ) )[1]['items'][0]['id'] === $c['id'], 'Successful save automatically records an exact-version reference, not a copied definition' );
    $article['revision'] = 2;
    sf8_check( 409 === sf8_api( 'articles/compose', array( 'article' => $article, 'expectedVersion' => 0 ) )[0] && 1 === count( ComponentUsage::references() ), 'Stale article saves cannot duplicate usage records' );
    wp_set_current_user( $users[0] );
    sf8_check( ! sf8_context()['usedComponents'] && ! sf8_api( 'catalog/' . $c['id'] )[1]['usedByMe'], 'Creator cannot discover the other author usage through the catalog' );
    sf8_check( 403 === sf8_api( 'catalog/context', null, array( 'articleId' => $articles[1] ) )[0] && ! str_contains( Snapshot::json( sf8_context() ), 'confidential answer' ), 'Shared catalog does not expose article content or cross-owner handoff' );
    $mine = sf8_article( $articles[0], $c, $values ); $mine['blocks'][0]['values']['answer']['value'] = 'Different owner content';
    sf8_check( 200 === sf8_api( 'articles/compose', array( 'article' => $mine, 'expectedVersion' => 0 ) )[0], 'Two owners can use one catalog definition with independent content' );
    $legacy = sf8_context()['personalExample']; $legacy['id'] = $prefix . '-private'; $legacy['key'] = 'private_test'; $legacy['layout'] = 'columns';
    $legacy['fields'][0]['value'] = 'PRIVATE_EXAMPLE_' . $run;
    sf8_check( 200 === sf8_api( 'catalog/personal', sf8_body( $legacy ) )[0], 'Legacy private component path remains available' );
    $private_before = Catalog::read( $legacy['id'] ); Catalog::install();
    sf8_check( $private_before['payload'] === Catalog::read( $legacy['id'] )['payload'] && (int) Catalog::read( $legacy['id'] )['owner_id'] === $users[0], 'Repeated schema installation does not migrate or publish private definitions' );
    wp_set_current_user( $users[1] );
    sf8_check( 404 === sf8_api( 'catalog/' . $legacy['id'] )[0] && ! str_contains( Snapshot::json( sf8_context() ), 'PRIVATE_EXAMPLE_' ), 'Global discovery still excludes another owner legacy private definitions' );
    $safe = $recipe; $safe['id'] = $prefix . '-safe-columns'; $safe['key'] = 'safe_columns'; $safe['layout'] = 'columns';
    $result = sf8_structure( $safe );
    sf8_check( 200 === $result[0] && $safe['id'] === $result[1]['id'] && ! str_contains( Snapshot::json( $result ), 'PRIVATE_EXAMPLE_' ) && $private_before['payload'] === Catalog::read( $legacy['id'] )['payload'], 'Equivalent private structure is neither revealed nor promoted during global registration' );
    wp_set_current_user( $admin ); $raw = $c; unset( $raw['revision'] );
    sf8_check( 400 === sf8_api( 'catalog', array( 'component' => $raw, 'expectedVersion' => 1 ) )[0], 'Raw registration cannot turn a content-free structure into an example-bearing design' );
    $revised = $recipe; $revised['fields'][1]['label'] = 'Detailed answer';
    $result = sf8_structure( $revised, 1 );
    sf8_check( 200 === $result[0] && 2 === $result[1]['version'] && $c['revision'] !== $result[1]['revision'], 'Manager can register a new immutable structure revision' );
    sf8_check( $saved['payload'] === Store::read( $articles[1], 1 )['payload'] && $saved['rendered'] === Store::read( $articles[1], 1 )['rendered'], 'Shared updates do not rewrite existing article payload or rendering' );
    wp_set_current_user( $users[1] ); $article['revision'] = 2;
    sf8_check( 200 === sf8_api( 'articles/compose', array( 'article' => $article, 'expectedVersion' => 1 ) )[0] && $c['revision'] === sf8_context()['usedComponents'][0]['usedRevisions'][0], 'Existing article and usage retain the old exact revision' );
    $new = $article; $new['id'] = $articles[2]; $new['revision'] = 1;
    sf8_check( 409 === sf8_api( 'articles/compose', array( 'article' => $new, 'expectedVersion' => 0 ) )[0], 'Old usage is not authority to use a superseded version in a new article' );
    wp_set_current_user( $admin );
    sf8_check( 200 === sf8_api( 'catalog/' . $c['id'] . '/availability', array( 'expectedVersion' => 2, 'enabled' => false ) )[0], 'Only management can retire a shared structure' );
    wp_set_current_user( $users[1] );
    sf8_check( 409 === sf8_structure( $duplicate )[0], 'Creating an equivalent structure cannot bypass retirement' );
    wp_set_current_user( $admin ); $active = $c; unset( $active['revision'] );
    $active['id'] = $prefix . '-zz-active'; $active['key'] = 'active_qa'; $active['name'] = 'Explicitly available alternative';
    sf8_check( 200 === sf8_api( 'catalog', array( 'component' => $active, 'expectedVersion' => 0 ) )[0], 'Trusted design registration can provide an explicit active alternative' );
    wp_set_current_user( $users[1] ); $result = sf8_structure( $duplicate );
    sf8_check( 200 === $result[0] && $result[1]['reused'] && $active['id'] === $result[1]['id'], 'Active equivalent is preferred even when an earlier-sorting equivalent is retired' );
    $start = microtime( true ) + 2; $jobs = array();
    foreach ( array( 'a', 'b' ) as $index => $side ) {
        $process = proc_open( array( PHP_BINARY, __DIR__ . '/wordpress-structures-race.php', (string) $users[$index], $run, $side, (string) $start ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes ); $jobs[] = array( $process, $pipes );
    }
    $results = array(); foreach ( $jobs as list( $process, $pipes ) ) { $results[] = json_decode( stream_get_contents( $pipes[1] ), true ); $error = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] ); if ( 0 !== proc_close( $process ) ) throw new RuntimeException( $error ); }
    sf8_check( isset( $results[0]['id'], $results[1]['id'] ) && $results[0]['id'] === $results[1]['id'] && 1 === count( array_filter( $results, static function( $r ) { return ! empty( $r['reused'] ); } ) ), 'Concurrent requests from two owners create one global structure and reuse its ID' );
    $media = sf_media_test_create( $articles[2] );
    $media_recipe = $recipe; $media_recipe['id'] = $prefix . '-media'; $media_recipe['key'] = 'empty_media'; $media_recipe['layout'] = 'columns';
    $media_recipe['fields'] = array(
        array( 'id' => 'photo', 'key' => 'photo', 'label' => 'Photo', 'type' => 'image', 'required' => true ),
        array( 'id' => 'document', 'key' => 'document', 'label' => 'Document', 'type' => 'link', 'required' => true ),
    );
    $result = sf8_structure( $media_recipe );
    sf8_check( 200 === $result[0] && '' === $result[1]['component']['fields'][0]['value'] && '' === $result[1]['component']['fields'][1]['alt'], 'Required image and document structures register without example URLs or alternative text' );
    $media_article = sf8_article( $articles[2], $result[1]['component'], array(
        'photo' => array( 'value' => '', 'alt' => 'Private image context', 'assetRef' => $media['staged']['image'] ),
        'document' => array( 'value' => '', 'alt' => 'Private document context', 'assetRef' => $media['staged']['document'] ),
    ) );
    $media_article['revision'] = 2; $media_article['blocks'][0]['variantId'] = 'default';
    sf8_check( 200 === sf8_api( 'articles/compose', array( 'article' => $media_article, 'expectedVersion' => 1 ) )[0], 'Content-free structures bind owner-private image and PDF references in the article' );
    wp_set_current_user( $users[0] );
    sf8_check( ! str_contains( Snapshot::json( sf8_context() ), $media['staged']['image']->id ) && ! str_contains( Snapshot::json( sf8_context() ), 'Private image context' ), 'Shared handoff reveals neither private asset IDs nor contextual alt text' );
    echo 'Global structures: ' . $checks . ' passed.', PHP_EOL;
} finally {
    wp_set_current_user( $admin ); foreach ( $articles as $id ) sf_media_test_cleanup( $id );
    $table = Catalog::table();
    foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT component_id FROM $table WHERE component_id LIKE %s", $wpdb->esc_like( $prefix . '-' ) . '%' ) ) as $id ) {
        $wpdb->delete( Catalog::versions(), array( 'component_id' => $id ) ); $wpdb->delete( $table, array( 'component_id' => $id ) );
    }
    foreach ( $users as $uid ) if ( get_user_meta( $uid, '_sf_structure_run', true ) === $run ) wp_delete_user( $uid );
}
