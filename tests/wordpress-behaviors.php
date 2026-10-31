<?php
require __DIR__ . '/wordpress-media-fixture.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once ABSPATH . 'wp-admin/includes/user.php';
use StructuredFields\Behaviors;
use StructuredFields\Catalog;
use StructuredFields\Review;
use StructuredFields\Snapshot;
use StructuredFields\Store;

$run = wp_generate_uuid4(); $prefix = 'behavior-test-' . $run;
$id = 'media-test-' . $run; $admin = get_current_user_id(); $user = 0; $checks = 0;
$previous_state = get_option( 'sf_behavior_state', null );
function sf9_check( $condition, $message ) { global $checks; if ( ! $condition ) throw new RuntimeException( $message ); echo 'PASS ' . ++$checks . ': ' . $message . PHP_EOL; }
function sf9_api( $path, $body = null ) {
    $r = new WP_REST_Request( null === $body ? 'GET' : 'POST', '/structured-fields/v1/' . $path );
    if ( null !== $body ) { $r->set_header( 'content-type', 'application/json' ); $r->set_body( Snapshot::json( $body ) ); }
    $response = rest_do_request( $r ); return array( $response->get_status(), json_decode( Snapshot::json( $response->get_data() ), true ) );
}
function sf9_ref( $behavior, $key, $target ) {
    return array( 'key' => $key, 'id' => $behavior['id'], 'revision' => $behavior['revision'], 'target' => $target, 'settings' => $behavior['defaults'] );
}
function sf9_register( $component ) { return sf9_api( 'catalog', array( 'component' => $component, 'expectedVersion' => 0 ) ); }
try {
    $registry = Behaviors::listing(); $entries = array_column( $registry['items'], null, 'id' );
    sf9_check( $registry['selectionOrder'] === array( 'native-html', 'css', 'javascript' ) && ! $registry['native']['qa']['javascriptRequired'], 'Native HTML precedes CSS, then JavaScript' );
    sf9_check( 'css' === $entries['fade-in']['implementation'] && 'javascript' === $entries['image-zoom']['implementation'] && ! $registry['canRegisterCode'], 'Only two bundled behaviors are registered; code registration is unavailable' );
    foreach ( $entries as $entry ) if ( ! $entry['enabled'] ) throw new RuntimeException( 'Run with the bundled behaviors enabled.' );
    wp_set_current_user( 0 ); sf9_check( 403 === sf9_api( 'behaviors' )[0], 'Anonymous callers cannot discover the workspace registry' );
    wp_set_current_user( $admin );
    $user = wp_insert_user( array( 'user_login' => 'sf_behavior_' . $run, 'user_pass' => wp_generate_password( 32 ), 'role' => 'sf_author' ) );
    if ( is_wp_error( $user ) ) throw new RuntimeException( 'Could not create temporary author.' );
    wp_set_current_user( $user );
    $context = sf9_api( 'catalog/context' )[1];
    sf9_check( isset( $context['behaviors']['items'][0]['settingsSchema'] ) && ! $context['behaviors']['canManageAvailability'], 'Author AI handoff exposes typed settings without code-management authority' );
    sf9_check( 404 === sf9_api( 'behaviors', array( 'source' => 'alert(1)' ) )[0], 'There is no executable-code registration endpoint' );
    $recipe = $context['structureExample']; $recipe['id'] = $prefix; $recipe['key'] = 'behavior_' . str_replace( '-', '_', $run );
    $recipe['layout'] = 'columns'; $recipe['fields'] = array( array( 'id' => 'photo', 'key' => 'photo', 'label' => 'Photo', 'type' => 'image', 'required' => false ) );
    $recipe['behaviors'] = array( sf9_ref( $entries['fade-in'], 'appearance', 'root' ), sf9_ref( $entries['image-zoom'], 'enlarge', 'photo' ) );
    $body = array( 'recipe' => $recipe, 'expectedVersion' => 0, 'reuse' => array( 'catalogToken' => $context['catalogToken'],
        'considered' => array( array( 'id' => $context['components'][0]['id'], 'revision' => $context['components'][0]['revision'] ) ), 'reason' => 'Existing shared components do not offer the required bounded image behavior.' ) );
    $result = sf9_api( 'catalog/structures', $body );
    sf9_check( 200 === $result[0], 'Author can bind trusted behavior references to a content-free shared structure' );
    if ( 200 !== $result[0] ) throw new RuntimeException( Snapshot::json( $result ) );
    $component = $result[1]['component'];
    sf9_check( 2 === count( $component['behaviors'] ) && '' === $component['fields'][0]['value'], 'Binding does not introduce example content or executable source' );
    $duplicate = $body; $duplicate['recipe']['id'] .= '-copy'; $duplicate['recipe']['key'] .= '_copy';
    $duplicate['recipe']['behaviors'] = array_reverse( $duplicate['recipe']['behaviors'] ); $duplicate['recipe']['behaviors'][0]['key'] = 'other_name';
    $reused = sf9_api( 'catalog/structures', $duplicate );
    sf9_check( 200 === $reused[0] && ! empty( $reused[1]['reused'] ) && $prefix === $reused[1]['id'], 'Renaming/reordering bindings cannot create a duplicate shared structure' );
    $media = sf_media_test_create( $id );
    $old = Store::read( $id, 1 );
    sf9_check( $old['digest'] === Snapshot::validate( $old['payload'] )['digest'] && 2 === json_decode( $old['payload'] )->schemaVersion, 'Existing v2 payload and renderer digest round-trip unchanged' );
    $block = array( 'id' => 'photo-one', 'componentId' => $component['id'], 'componentRevision' => $component['revision'], 'variantId' => 'default',
        'values' => array( 'photo' => array( 'value' => '', 'alt' => 'First private photo', 'assetRef' => $media['staged']['image'] ) ) );
    $second = $block; $second['id'] = 'photo-two'; $second['values']['photo']['alt'] = 'Second private photo';
    $second['behaviorSettings'] = array( 'enlarge' => array( 'enabled' => true, 'showCaption' => false ), 'appearance' => array( 'enabled' => true, 'durationMs' => 600 ) );
    $article = array( 'id' => $id, 'revision' => 2, 'title' => 'Behavior verification', 'blocks' => array( $block, $second ) );
    $saved = sf9_api( 'articles/compose', array( 'article' => $article, 'expectedVersion' => 1 ) );
    sf9_check( 200 === $saved[0] && 3 === $saved[1]['snapshot']['schemaVersion'], 'Composition selects v3 only when behavior contracts are present' );
    $row = Store::read( $id ); $validated = Snapshot::validate( $row['payload'] );
    sf9_check( $row['digest'] === $validated['digest'] && $row['payload'] === $validated['payload'], 'V3 canonical settings, payload and digest are stable on round-trip' );
    sf9_check( str_contains( $row['rendered'], 'data-sf-zoom="caption"' ) && str_contains( $row['rendered'], 'data-sf-zoom="image"' ) && str_contains( $row['rendered'], 'data-sf-fade="600"' ), 'Two instances retain independent typed settings and semantic image bindings' );
    $html = Behaviors::preview( StructuredFields\Media::preview_html( $row ), json_decode( $row['payload'] ) );
    sf9_check( str_contains( $html, 'data-sf-zoom' ) && str_contains( $html, 'src=' ), 'Preview binds owner-private image URLs before enhancement' );
    $scripts = array_values( array_filter( wp_scripts()->queue, static function( $h ) { return str_starts_with( $h, 'sf-behavior-' ); } ) );
    sf9_check( 1 === count( $scripts ) && str_contains( $scripts[0], 'image-zoom' ), 'Multiple instances load image JS once; CSS appearance loads no JS' );
    $review = sf9_api( 'articles/' . $id . '/reviews', array( 'expectedVersion' => 2 ) );
    sf9_check( 200 === $review[0] && 'article-managed-assets-and-behaviors' === $review[1]['manifest']['scope'], 'V3 review covers the pinned behavior contract as well as private media' );
    foreach ( array( 'unknown-binding', 'wrong-type', 'unknown-setting', 'incomplete-settings' ) as $case ) {
        $bad = $article; $bad['revision'] = 3;
        if ( 'unknown-binding' === $case ) $bad['blocks'][0]['behaviorSettings'] = array( 'forged' => array( 'enabled' => true ) );
        if ( 'wrong-type' === $case ) $bad['blocks'][1]['behaviorSettings']['enlarge']['showCaption'] = 'true';
        if ( 'unknown-setting' === $case ) $bad['blocks'][1]['behaviorSettings']['enlarge']['source'] = 'alert(1)';
        if ( 'incomplete-settings' === $case ) unset( $bad['blocks'][1]['behaviorSettings']['enlarge']['showCaption'] );
        sf9_check( 400 === sf9_api( 'articles/compose', array( 'article' => $bad, 'expectedVersion' => 2 ) )[0], 'Reject article settings: ' . $case );
    }
    $snapshot = $saved[1]['snapshot']; $snapshot['schemaVersion'] = 2;
    try { Snapshot::validate( Snapshot::json( $snapshot ) ); $rejected = false; } catch ( Throwable $e ) { $rejected = true; }
    sf9_check( $rejected, 'V2 remains strict and does not silently accept or discard new behavior fields' );
    wp_set_current_user( $admin );
    foreach ( array( 'unknown-revision', 'missing-target', 'wrong-target', 'duplicate-key', 'duplicate-target', 'extra-code', 'arbitrary-selector', 'bad-duration', 'wrong-settings' ) as $case ) {
        $bad = $component; unset( $bad['revision'] ); $bad['id'] = $prefix . '-bad'; $bad['key'] = 'behavior_bad';
        if ( 'unknown-revision' === $case ) $bad['behaviors'][0]['revision'] = str_repeat( '0', 64 );
        if ( 'missing-target' === $case ) $bad['behaviors'][1]['target'] = 'missing';
        if ( 'wrong-target' === $case ) $bad['behaviors'][0]['target'] = 'photo';
        if ( 'duplicate-key' === $case ) $bad['behaviors'][1]['key'] = 'appearance';
        if ( 'duplicate-target' === $case ) { $bad['behaviors'][] = $bad['behaviors'][1]; $bad['behaviors'][2]['key'] = 'another'; }
        if ( 'extra-code' === $case ) $bad['behaviors'][1]['source'] = 'alert(1)';
        if ( 'arbitrary-selector' === $case ) $bad['behaviors'][1]['target'] = 'body img';
        if ( 'bad-duration' === $case ) $bad['behaviors'][0]['settings']['durationMs'] = 500;
        if ( 'wrong-settings' === $case ) $bad['behaviors'][1]['settings']['durationMs'] = 200;
        sf9_check( 400 === sf9_register( $bad )[0] && ! Catalog::read( $bad['id'] ), 'Reject unsafe or incompatible binding: ' . $case );
    }
    $state = Behaviors::state();
    $change = array( 'revision' => $entries['image-zoom']['revision'], 'enabled' => false, 'expectedVersion' => $state['version'] );
    wp_set_current_user( $user ); sf9_check( 403 === sf9_api( 'behaviors/image-zoom/availability', $change )[0], 'Authors cannot stop or activate site executable behavior' );
    wp_set_current_user( $admin ); $stopped = sf9_api( 'behaviors/image-zoom/availability', $change );
    sf9_check( 200 === $stopped[0] && ! Behaviors::active( 'image-zoom', $change['revision'] ), 'Administrator can stop an exact behavior revision' );
    $new = $component; unset( $new['revision'] ); $new['id'] = $prefix . '-stopped'; $new['key'] = 'stopped_new';
    sf9_check( 400 === sf9_register( $new )[0] && ! Catalog::read( $new['id'] ), 'New registrations cannot introduce a stopped behavior revision' );
    sf9_check( 409 === sf9_api( 'behaviors/image-zoom/availability', $change )[0], 'Stale availability changes fail compare-and-swap' );
    wp_set_current_user( $user );
    $static = Behaviors::preview( StructuredFields\Media::preview_html( $row ), json_decode( $row['payload'] ) );
    sf9_check( ! str_contains( $static, 'data-sf-zoom' ) && str_contains( $static, '<img' ) && str_contains( $static, 'data-sf-fade' ), 'Stopped JS leaves private images and independent CSS behavior intact' );
    sf9_check( count( sf9_api( 'articles/' . $id )[1]['behaviorWarnings'] ) === 1, 'Stopped behavior is reported explicitly to the article client' );
    sf9_check( 'invalidated' === sf9_api( 'reviews/' . $review[1]['reviewId'] )[1]['state'], 'Behavior availability changes invalidate existing review decisions' );
    $article['revision'] = 3;
    sf9_check( 200 === sf9_api( 'articles/compose', array( 'article' => $article, 'expectedVersion' => 2 ) )[0], 'Stopping execution does not prevent the owner editing existing content' );
    wp_set_current_user( $admin ); $change['enabled'] = true; $change['expectedVersion'] = Behaviors::state()['version'];
    sf9_check( 200 === sf9_api( 'behaviors/image-zoom/availability', $change )[0], 'Administrator can explicitly restore a stopped bundled revision' );
    wp_set_current_user( $user );
    sf9_check( 'invalidated' === sf9_api( 'reviews/' . $review[1]['reviewId'] )[1]['state'], 'Restoring behavior never silently restores an old review approval' );
    sf9_check( $old['payload'] === Store::read( $id, 1 )['payload'] && $old['rendered'] === Store::read( $id, 1 )['rendered'], 'Legacy saved article payload and HTML were never migrated or rewritten' );
    echo 'WordPress behaviors: ' . $checks . ' passed.', PHP_EOL;
} finally {
    wp_set_current_user( $admin ); sf_media_test_cleanup( $id );
    $wpdb->delete( Catalog::versions(), array( 'component_id' => $prefix ) ); $wpdb->delete( Catalog::table(), array( 'component_id' => $prefix ) );
    if ( $user && ! is_wp_error( $user ) ) wp_delete_user( $user );
    if ( null === $previous_state ) delete_option( 'sf_behavior_state' ); else update_option( 'sf_behavior_state', $previous_state, false );
}
