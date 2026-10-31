<?php
require __DIR__ . '/wordpress-media-fixture.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
use StructuredFields\{Catalog, CatalogContext, ComponentBuilder, Snapshot, Assets};
$run = wp_generate_uuid4(); $prefix = 'quota-test-' . $run; $users = array(); $checks = 0; $admin = get_current_user_id();
function sfq_check( $ok, $label ) { global $checks; if ( ! $ok ) throw new RuntimeException( $label ); echo 'PASS ' . ++$checks . ': ' . $label . PHP_EOL; }
function sfq_recipe( $index ) {
    global $run, $prefix; $recipe = ComponentBuilder::structure_example(); $recipe->layout = 'stack';
    $recipe->id = $prefix . '-s' . $index; $recipe->key = 'quota_' . str_replace( '-', '_', $run ) . '_' . $index;
    $recipe->fields[0]->key = $recipe->key; return $recipe;
}
function sfq_create( $recipe, $expected = 0 ) {
    $context = CatalogContext::get( new WP_REST_Request( 'GET' ) );
    $body = array( 'recipe' => $recipe, 'expectedVersion' => $expected, 'reuse' => array( 'catalogToken' => $context['catalogToken'],
        'considered' => array( array( 'id' => $context['components'][0]['id'], 'revision' => $context['components'][0]['revision'] ) ),
        'reason' => 'This fixture needs a distinct typed question slot.' ) );
    $r = new WP_REST_Request( 'POST' ); $r->set_header( 'content-type', 'application/json' ); $r->set_body( Snapshot::json( $body ) );
    return Catalog::structure( $r );
}
$limit_filter = static fn( $limit, $actor ) => 2;
try {
    foreach ( array( 'a', 'b' ) as $side ) {
        $uid = wp_insert_user( array( 'user_login' => 'sf_quota_' . $side . '_' . $run, 'user_pass' => wp_generate_password( 32 ), 'role' => 'sf_author' ) );
        if ( is_wp_error( $uid ) ) throw new RuntimeException( 'Fixture user failed.' );
        $users[] = $uid; update_user_meta( $uid, '_sf_quota_run', $run );
    }
    wp_set_current_user( $users[0] );
    for ( $i = 0; $i < 20; $i++ ) { $result = sfq_create( sfq_recipe( $i ) ); if ( is_wp_error( $result ) ) throw new RuntimeException( $result->get_error_message() ); }
    $denied = sfq_create( sfq_recipe( 20 ) );
    sfq_check( is_wp_error( $denied ) && 'sf_shared_component_quota' === $denied->get_error_code(), 'One creator cannot exceed 20 shared definitions' );
    sfq_check( 20 === Catalog::shared_usage( $users[0] ), 'Usage counts original shared creators' );
    $duplicate = sfq_recipe( 0 ); $duplicate->id = $prefix . '-duplicate'; $duplicate->key = 'renamed';
    $reused = sfq_create( $duplicate );
    sfq_check( ! is_wp_error( $reused ) && $reused['reused'] && 20 === Catalog::shared_usage( $users[0] ), 'Reuse works at quota and does not consume another slot' );
    wp_set_current_user( $users[1] ); $reused = sfq_create( $duplicate );
    sfq_check( ! is_wp_error( $reused ) && $reused['reused'] && 0 === Catalog::shared_usage( $users[1] ), 'Another author reuses a shared definition without copying or charge' );
    $created = sfq_create( sfq_recipe( 21 ) );
    sfq_check( ! is_wp_error( $created ) && 1 === Catalog::shared_usage( $users[1] ), 'Another author can still create after the first reaches quota' );
    wp_set_current_user( $admin ); $first = Catalog::read( $prefix . '-s0' ); $before = $first['payload'];
    $recipe = sfq_recipe( 0 ); $recipe->fields[1]->label = 'Updated answer';
    $updated = sfq_create( $recipe, 1 );
    sfq_check( ! is_wp_error( $updated ) && (int) Catalog::read( $first['component_id'] )['creator_id'] === $users[0] && 20 === Catalog::shared_usage( $users[0] ), 'Manager updates cannot reset creator attribution or consume new slots' );
    $r = new WP_REST_Request( 'POST' ); $r->set_url_params( array( 'id' => $first['component_id'] ) );
    $r->set_header( 'content-type', 'application/json' ); $r->set_body( '{"expectedVersion":2,"enabled":false}' );
    sfq_check( ! is_wp_error( Catalog::availability( $r ) ) && 20 === Catalog::shared_usage( $users[0] ), 'Retirement preserves creator usage and existing definitions' );
    $wpdb->update( Catalog::table(), array( 'creator_id' => null ), array( 'component_id' => $first['component_id'] ) );
    $wpdb->update( Catalog::versions(), array( 'created_at' => '2000-01-01 00:00:00' ), array( 'component_id' => $first['component_id'], 'revision' => $first['selected_revision'] ) );
    sfq_check( 20 === Catalog::shared_usage( $users[0] ), 'Legacy creator is recovered from the earliest stored revision' );
    $wpdb->update( Catalog::versions(), array( 'created_at' => '2000-01-01 00:00:00' ), array( 'component_id' => $first['component_id'] ) );
    sfq_check( Catalog::shared_usage( $admin ) >= 1 && 20 === Catalog::shared_usage( $users[0] ), 'Ambiguous legacy timestamp ties count all earliest creators conservatively' );
    Catalog::install();
    sfq_check( $before === Catalog::read( $first['component_id'], $first['selected_revision'] )['payload'], 'Schema upgrade does not rewrite immutable component payloads' );
    wp_set_current_user( $users[0] ); $context = CatalogContext::get( new WP_REST_Request( 'GET' ) );
    sfq_check( 20 === $context['limits']['sharedComponentsPerCreator'] && 100 * 1024 * 1024 === $context['limits']['privateMediaBytesPerUser'], 'AI context exposes own configured limits' );
    add_filter( 'sf_shared_component_limit_per_user', $limit_filter, 10, 2 );
    wp_set_current_user( $users[1] );
    sfq_check( 2 === Catalog::shared_limit( $users[1] ) && ! is_wp_error( sfq_create( sfq_recipe( 21 ), 1 ) ), 'Trusted limit override preserves unchanged retries' );
    ( new WP_User( $users[1] ) )->set_role( 'sf_designer' );
    wp_set_current_user( 0 ); wp_set_current_user( $users[1] );
    $start = microtime( true ) + 2; $jobs = array();
    foreach ( array( 'a', 'b' ) as $side ) {
        $process = proc_open( array( PHP_BINARY, __DIR__ . '/wordpress-quota-race.php', (string) $users[1], $run, $side, (string) $start ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes ); $jobs[] = array( $process, $pipes );
    }
    $results = array(); foreach ( $jobs as list( $process, $pipes ) ) { $results[] = json_decode( stream_get_contents( $pipes[1] ), true ); $error = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] ); if ( 0 !== proc_close( $process ) ) throw new RuntimeException( $error ); }
    sfq_check( 1 === count( array_filter( $results, static fn( $r ) => ! empty( $r['id'] ) ) ) && 1 === count( array_filter( $results, static fn( $r ) => 'sf_shared_component_quota' === ( $r['error'] ?? '' ) ) ) && 2 === Catalog::shared_usage( $users[1] ), 'Concurrent raw-design registrations cannot overrun creator quota' );
    $personal = ComponentBuilder::example(); $personal->id = $prefix . '-private'; $personal->key = 'quota_private'; $personal->layout = 'columns'; $personal->fields[0]->key = 'private_quota_slot';
    $context = CatalogContext::get( new WP_REST_Request( 'GET' ) );
    $r = new WP_REST_Request( 'POST' ); $r->set_header( 'content-type', 'application/json' );
    $r->set_body( Snapshot::json( array( 'recipe' => $personal, 'expectedVersion' => 0, 'reuse' => array( 'catalogToken' => $context['catalogToken'],
        'considered' => array( array( 'id' => $context['components'][0]['id'], 'revision' => $context['components'][0]['revision'] ) ), 'reason' => 'The personal fixture requires a distinct private field.' ) ) ) );
    sfq_check( ! is_wp_error( Catalog::personal( $r ) ), 'Shared creator quota does not block an available personal slot' );
    $r->set_url_params( array( 'id' => $personal->id ) ); $r->set_body( Snapshot::json( array( 'id' => $prefix . '-shared-copy', 'key' => 'quota_shared_copy', 'expectedVersion' => 1 ) ) );
    $shared = Catalog::share( $r );
    sfq_check( is_wp_error( $shared ) && 'sf_shared_component_quota' === $shared->get_error_code(), 'Explicit sharing cannot bypass the shared creator quota' );
    remove_filter( 'sf_shared_component_limit_per_user', $limit_filter );
    $table = Catalog::table(); $versions = Catalog::versions(); $legacy = $prefix . '-s1';
    $history = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $versions WHERE component_id=%s", $legacy ), ARRAY_A );
    $wpdb->update( $table, array( 'creator_id' => null ), array( 'component_id' => $legacy ) ); $wpdb->delete( $versions, array( 'component_id' => $legacy ) );
    sfq_check( 20 === Catalog::shared_usage( $users[0] ) && 3 === Catalog::shared_usage( $users[1] ), 'Missing legacy attribution is not treated as exempt system ownership' );
    foreach ( $history as $revision ) $wpdb->insert( $versions, $revision );
    $wpdb->update( $table, array( 'creator_id' => 0 ), array( 'component_id' => $legacy ) );
    sfq_check( 19 === Catalog::shared_usage( $users[0] ) && 2 === Catalog::shared_usage( $users[1] ), 'Explicit system creator zero remains distinct from unknown legacy origin' );
    $wpdb->update( $table, array( 'creator_id' => $users[0] ), array( 'component_id' => $legacy ) );
    $query_filter = static fn( $sql ) => str_starts_with( $sql, $wpdb->prepare( 'SELECT COUNT(*) FROM %i c WHERE c.owner_id=0', $table ) ) ? 'SELECT NULL' : $sql;
    add_filter( 'query', $query_filter );
    try { $result = sfq_create( sfq_recipe( 22 ) ); }
    finally { remove_filter( 'query', $query_filter ); }
    sfq_check( is_wp_error( $result ) && 'sf_catalog_storage' === $result->get_error_code() && ! Catalog::read( $prefix . '-s22' ), 'Unavailable quota accounting fails closed without insertion' );
    $bad_limit = static fn() => 'unlimited';
    add_filter( 'sf_shared_component_limit_per_user', $bad_limit ); add_filter( 'sf_private_media_limit_per_user', $bad_limit );
    sfq_check( 20 === Catalog::shared_limit( $users[0] ) && 100 * 1024 * 1024 === Assets::user_limit( $users[0] ), 'Invalid configured limits fall back to finite defaults' );
    remove_filter( 'sf_shared_component_limit_per_user', $bad_limit ); remove_filter( 'sf_private_media_limit_per_user', $bad_limit );
    $per_actor = static fn( $limit, $actor ) => $actor === $users[0] ? 50 : 100;
    add_filter( 'sf_private_media_limit_per_user', $per_actor, 10, 2 );
    sfq_check( 50 === Assets::user_limit( $users[0] ) && 100 === Assets::user_limit( $users[1] ), 'Capacity configuration receives the effective uploader identity' );
    remove_filter( 'sf_private_media_limit_per_user', $per_actor );
    echo "Shared quota checks: $checks passed.\n";
} finally {
    remove_filter( 'sf_shared_component_limit_per_user', $limit_filter ); wp_set_current_user( $admin );
    $table = Catalog::table(); foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT component_id FROM $table WHERE component_id LIKE %s", $wpdb->esc_like( $prefix . '-' ) . '%' ) ) as $id ) {
        $wpdb->delete( Catalog::versions(), array( 'component_id' => $id ) ); $wpdb->delete( $table, array( 'component_id' => $id ) );
    }
    foreach ( $users as $uid ) if ( get_user_meta( $uid, '_sf_quota_run', true ) === $run ) wp_delete_user( $uid );
}
