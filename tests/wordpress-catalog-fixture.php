<?php
require_once __DIR__ . '/wordpress-media-fixture.php';
use StructuredFields\Catalog;
use StructuredFields\Snapshot;
use StructuredFields\Store;
$action = $argv[1] ?? ''; $run = $argv[2] ?? '';
if ( ! preg_match( '/^[a-f0-9-]{36}$/D', $run ) ) throw new RuntimeException( 'Disposable run ID required.' );
$id = 'catalog-test-' . $run;
if ( 'cleanup-shared' === $action ) {
    $key = $argv[3] ?? ''; $user = get_user_by( 'login', 'sf_test_a_' . $run ); $row = Catalog::read( $key );
    if ( ! preg_match( '/^shared-[a-f0-9-]{36}$/D', $key ) || ! $user || get_user_meta( $user->ID, '_sf_test_run', true ) !== $run ||
        ! $row || (int) $row['owner_id'] !== 0 || (int) $row['updated_by'] !== (int) $user->ID ) throw new RuntimeException( 'Not a disposable shared fixture.' );
    $wpdb->delete( Catalog::table(), array( 'component_id' => $key ) ); $wpdb->delete( Catalog::versions(), array( 'component_id' => $key ) );
    $wpdb->delete( StructuredFields\ComponentUsage::table(), array( 'component_id' => $key ) );
    echo 'fixture-ok';
} elseif ( 'cleanup-ui' === $action ) {
    $article = $argv[3] ?? '';
    $user = get_userdata( StructuredFields\Access::owner( $article ) );
    if ( ! preg_match( '/^article-[a-f0-9-]{36}$/D', $article ) || ! $user ||
        ! in_array( $user->user_login, array( 'sf_test_a_' . $run, 'sf_test_b_' . $run ), true ) ||
        get_user_meta( $user->ID, '_sf_test_run', true ) !== $run || StructuredFields\Access::owner( $article ) !== (int) $user->ID ) throw new RuntimeException( 'Not a disposable UI draft.' );
    $assets = StructuredFields\Assets::table();
    if ( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $assets WHERE article_id=%s", $article ) ) ) throw new RuntimeException( 'UI cleanup expects no files.' );
    $table = Store::table();
    foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM $table WHERE article_id=%s", $article ) ) as $post ) wp_delete_post( $post, true );
    $wpdb->delete( $table, array( 'article_id' => $article ) );
    echo 'fixture-ok';
} elseif ( 'cleanup' === $action ) {
    foreach ( array( $id, $id . '-other', $id . '-race' ) as $key ) {
        $wpdb->delete( Catalog::table(), array( 'component_id' => $key ) );
        $wpdb->delete( Catalog::versions(), array( 'component_id' => $key ) );
        $wpdb->delete( StructuredFields\ComponentUsage::table(), array( 'component_id' => $key ) );
    }
    echo 'fixture-ok';
} elseif ( 'count' === $action ) {
    $table = Catalog::versions();
    echo $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE component_id=%s", $id ) );
} elseif ( in_array( $action, array( 'race-a', 'race-b' ), true ) ) {
    $row = Catalog::read( $id ); $c = json_decode( $row['payload'] );
    unset( $c->revision ); $c->css .= '\n.race-' . $action . '{color:#222}';
    $request = new WP_REST_Request( 'POST', '/structured-fields/v1/catalog' );
    $request->set_header( 'content-type', 'application/json' );
    $request->set_body( Snapshot::json( array( 'component' => $c, 'expectedVersion' => (int) $row['version'] ) ) );
    $start = (float) ( $argv[3] ?? 0 );
    if ( $start > microtime( true ) + 10 ) throw new RuntimeException( 'Invalid race start.' );
    while ( microtime( true ) < $start ) usleep( 1000 );
    $result = Catalog::register( $request );
    echo is_wp_error( $result ) ? $result->get_error_code() : 'saved';
} else throw new RuntimeException( 'Unknown fixture operation.' );
