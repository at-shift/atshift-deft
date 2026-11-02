<?php
require_once __DIR__ . '/wordpress-media-fixture.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
use Atshift\Deft\Access;
use Atshift\Deft\Store;
use Atshift\Deft\Snapshot;

$action = $argv[1] ?? ''; $run = $argv[2] ?? '';
if ( ! preg_match( '/^[a-f0-9-]{36}$/D', $run ) ) throw new RuntimeException( 'Disposable run ID required.' );
$ids = array( 'media-test-' . $run, 'media-test-' . substr( hash( 'sha256', $run ), 0, 36 ), 'media-test-' . substr( hash( 'sha256', $run . 'race' ), 0, 36 ), 'media-test-' . substr( hash( 'sha256', $run . 'db-race' ), 0, 36 ) );
$ids[] = 'media-test-' . substr( hash( 'sha256', $run . 'onboarding-sample' ), 0, 36 );
$logins = array( 'sf_test_a_' . $run, 'sf_test_b_' . $run );
if ( 'create' === $action ) {
    $result = array();
    foreach ( $logins as $i => $login ) {
        if ( get_user_by( 'login', $login ) || Store::read( $ids[$i] ) ) throw new RuntimeException( 'Fixture already exists.' );
        $password = wp_generate_password( 32 );
        $uid = wp_insert_user( array( 'user_login' => $login, 'user_email' => $login . '@example.invalid', 'user_pass' => $password, 'role' => 'atshde_author' ) );
        if ( is_wp_error( $uid ) ) throw new RuntimeException( 'Could not create disposable user.' );
        update_user_meta( $uid, '_sf_test_run', $run ); wp_set_current_user( $uid );
        $result[] = array( 'username' => $login, 'password' => $password, 'uid' => $uid, 'id' => $ids[$i], 'data' => sf_media_test_create( $ids[$i] ) );
    }
    echo wp_json_encode( array( 'users' => $result, 'race' => $ids[2], 'dbRace' => $ids[3], 'sample' => $ids[4] ) );
} elseif ( 'cleanup' === $action ) {
    foreach ( $ids as $id ) sf_media_test_cleanup( $id );
    foreach ( $logins as $login ) {
        $user = get_user_by( 'login', $login );
        if ( $user && get_user_meta( $user->ID, '_sf_test_run', true ) === $run ) {
            $component = Atshift\Deft\Catalog::read( 'workflow-' . $run );
            if ( $component && (int) $component['creator_id'] === (int) $user->ID ) {
                $wpdb->delete( Atshift\Deft\Catalog::versions(), array( 'component_id' => $component['component_id'] ) );
                $wpdb->delete( Atshift\Deft\Catalog::table(), array( 'component_id' => $component['component_id'] ) );
            }
            wp_delete_user( $user->ID );
        }
    }
    echo 'fixture-ok';
} elseif ( in_array( $action, array( 'revoke', 'restore', 'revoke-review', 'restore-review', 'publisher', 'author', 'administrator' ), true ) ) {
    $user = get_user_by( 'login', $logins[0] );
    if ( ! $user || get_user_meta( $user->ID, '_sf_test_run', true ) !== $run ) throw new RuntimeException( 'Not a fixture user.' );
    if ( 'revoke' === $action ) $user->set_role( 'subscriber' );
    elseif ( 'restore' === $action ) $user->set_role( 'atshde_author' );
    elseif ( 'revoke-review' === $action ) $user->add_cap( 'atshde_review_drafts', false );
    elseif ( 'publisher' === $action ) $user->set_role( 'atshde_publisher' );
    elseif ( 'author' === $action ) $user->set_role( 'atshde_author' );
    elseif ( 'administrator' === $action ) $user->set_role( 'administrator' );
    else $user->remove_cap( 'atshde_review_drafts' );
    echo 'fixture-ok';
} elseif ( in_array( $action, array( 'quota-fill', 'quota-usage' ), true ) ) {
    $user = get_user_by( 'login', $logins[0] );
    if ( ! $user || get_user_meta( $user->ID, '_sf_test_run', true ) !== $run || Access::owner( $ids[2] ) !== (int) $user->ID ) throw new RuntimeException( 'Not a fixture owner.' );
    $table = Atshift\Deft\Assets::table();
    $used = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(bytes),0) FROM $table WHERE created_by=%d", $user->ID ) );
    if ( 'quota-fill' === $action ) {
        $reserve = (int) ( $argv[3] ?? -1 ); if ( $reserve < 0 || $reserve > 1024 ) throw new RuntimeException( 'Invalid fixture reserve.' );
        $remaining = 100 * 1024 * 1024 - $reserve - $used; $i = 0;
        // Only accounting metadata is needed; avoid creating 100 MiB of disposable files.
        while ( $remaining > 0 ) {
            $bytes = min( Atshift\Deft\Assets::MAX_BYTES, $remaining );
            $ok = $wpdb->insert( $table, array( 'asset_id' => wp_generate_uuid4(), 'article_id' => $i % 2 ? $ids[0] : $ids[2],
                'purpose' => $i % 2 ? 'reference' : 'publication', 'sha256' => hash( 'sha256', $run . '-quota-' . $i ),
                'filename' => 'quota-fixture.pdf', 'mime' => 'application/pdf', 'bytes' => $bytes, 'created_by' => $user->ID, 'created_at' => current_time( 'mysql', true ) ) );
            if ( false === $ok ) throw new RuntimeException( 'Quota fixture insert failed.' );
            $remaining -= $bytes; $used += $bytes; $i++;
        }
    }
    echo wp_json_encode( array( 'usedBytes' => $used ) );
} elseif ( 'legacy-preview-title' === $action ) {
    $id = $argv[3] ?? ''; if ( ! in_array( $id, $ids, true ) ) throw new RuntimeException( 'Not a fixture article.' );
    $row = Store::read( $id );
    $wpdb->update( $wpdb->posts, array( 'post_title' => json_decode( $row['payload'] )->article->title ), array( 'ID' => $row['post_id'] ) );
    clean_post_cache( (int) $row['post_id'] ); echo 'fixture-ok';
} elseif ( 'publication-media-guard' === $action ) {
    $id = $argv[3] ?? ''; $state = $argv[4] ?? '';
    if ( ! in_array( $id, $ids, true ) || ! in_array( $state, array( 'public', 'job-scheduled', 'job-processing', 'job-blocked', 'job-cancelled', 'post-draft', 'post-private', 'post-pending', 'post-future', 'post-trash', 'password', 'owner', 'type', 'binding' ), true ) ) throw new RuntimeException( 'Invalid media guard fixture.' );
    $job = Atshift\Deft\Publications::read( $id );
    if ( ! $job || ! $job['post_id'] || get_user_meta( (int) $job['owner_id'], '_sf_test_run', true ) !== $run ) throw new RuntimeException( 'Not a fixture publication.' );
    $other = get_user_by( 'login', $logins[1] );
    if ( ! $other || get_user_meta( $other->ID, '_sf_test_run', true ) !== $run ) throw new RuntimeException( 'Not a fixture user.' );
    // Model stale/mismatched storage without invoking lifecycle repair hooks; reset in the HTTP test's finally block.
    $wpdb->update( Atshift\Deft\Publications::table(), array( 'state' => str_starts_with( $state, 'job-' ) ? substr( $state, 4 ) : 'published' ), array( 'article_id' => $id ) );
    $wpdb->update( $wpdb->posts, array(
        'post_status' => str_starts_with( $state, 'post-' ) ? substr( $state, 5 ) : 'publish',
        'post_password' => 'password' === $state ? 'fixture-password' : '',
        'post_author' => 'owner' === $state ? $other->ID : $job['owner_id'],
        'post_type' => 'type' === $state ? 'atshde_article' : $job['post_type'],
    ), array( 'ID' => $job['post_id'] ) );
    update_post_meta( (int) $job['post_id'], '_atshde_publication', 'binding' === $state ? wp_generate_uuid4() : $job['publication_id'] );
    clean_post_cache( (int) $job['post_id'] ); echo 'fixture-ok';
} elseif ( in_array( $action, array( 'publication-due', 'publication-run', 'publication-hidden', 'publication-public', 'publication-shortcodes', 'publication-expire', 'legacy-public-title', 'publication-recover' ), true ) ) {
    $id = $argv[3] ?? ''; if ( ! in_array( $id, $ids, true ) ) throw new RuntimeException( 'Not a fixture article.' );
    $job = Atshift\Deft\Publications::read( $id ); if ( ! $job ) throw new RuntimeException( 'No fixture publication.' );
    if ( 'publication-due' === $action ) $wpdb->update( Atshift\Deft\Publications::table(), array( 'publish_at' => time() - 10 ), array( 'article_id' => $id ) );
    elseif ( 'publication-run' === $action ) Atshift\Deft\Publications::run( $id, (int) ( $argv[4] ?? $job['version'] ) );
    elseif ( 'publication-hidden' === $action ) wp_update_post( array( 'ID' => $job['post_id'], 'post_status' => 'draft' ) );
    elseif ( 'publication-public' === $action ) wp_update_post( array( 'ID' => $job['post_id'], 'post_status' => 'publish' ) );
    elseif ( 'publication-expire' === $action ) $wpdb->update( Atshift\Deft\Connections::table(), array( 'expires' => time()-1 ), array( 'id' => $job['connection_id'] ) );
    elseif ( 'legacy-public-title' === $action || 'publication-recover' === $action ) {
        $row = Store::read( $id );
        $wpdb->update( $wpdb->posts, array( 'post_title' => json_decode( $row['payload'] )->article->title, 'post_status' => 'publication-recover' === $action ? 'draft' : 'publish' ), array( 'ID' => $job['post_id'] ) );
        clean_post_cache( (int) $job['post_id'] );
        if ( 'publication-recover' === $action ) $wpdb->update( Atshift\Deft\Publications::table(), array( 'state' => 'processing' ), array( 'article_id' => $id ) );
    }
    else {
        $calls = 0; add_shortcode( 'sf_test_execute', static function() use ( &$calls ) { $calls++; return 'EXECUTED'; } );
        $rendered = apply_filters( 'the_content', get_post_field( 'post_content', $job['post_id'] ) );
        if ( $calls || str_contains( $rendered, 'EXECUTED' ) || ! str_contains( html_entity_decode( $rendered, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), '[sf_test_execute]' ) ) throw new RuntimeException( 'Shortcode executed or text lost: calls=' . $calls . ', rendered=' . substr( $rendered, 0, 2000 ) );
    }
    echo 'fixture-ok';
} elseif ( in_array( $action, array( 'race-a', 'race-b' ), true ) ) {
    $user = get_user_by( 'login', $logins['race-a' === $action ? 0 : 1] );
    if ( ! $user || get_user_meta( $user->ID, '_sf_test_run', true ) !== $run ) throw new RuntimeException( 'Not a fixture user.' );
    wp_set_current_user( $user->ID );
    $snapshot = json_decode( file_get_contents( dirname( __DIR__ ) . '/wordpress/atshift-deft/media-sample.json' ) );
    $snapshot->article->id = $ids[3];
    $start = (float) ( $argv[3] ?? 0 );
    if ( $start > microtime( true ) + 10 ) throw new RuntimeException( 'Invalid race start.' );
    while ( microtime( true ) < $start ) usleep( 1000 );
    $saved = Store::save( Snapshot::validate( Snapshot::json( $snapshot ) ), 0 );
    echo is_wp_error( $saved ) ? $saved->get_error_code() : 'saved';
} elseif ( 'review-state' === $action ) {
    wp_set_current_user( get_user_by( 'login', $logins[0] )->ID );
    $table = Atshift\Deft\Review::table();
    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE article_id=%s ORDER BY expires DESC LIMIT 1", $ids[0] ), ARRAY_A );
    echo Atshift\Deft\Review::state( $row )['state'];
} elseif ( 'assert' === $action ) {
    $a = get_user_by( 'login', $logins[0] ); $b = get_user_by( 'login', $logins[1] );
    wp_set_current_user( $a->ID );
    if ( current_user_can( 'manage_options' ) || current_user_can( 'publish_posts' ) || current_user_can( 'edit_post', Store::read( $ids[0] )['post_id'] ) ) throw new RuntimeException( 'Excess native capabilities.' );
    if ( ! Access::article( $ids[0] ) || Access::article( $ids[1] ) ) throw new RuntimeException( 'Ownership mismatch.' );
    wp_set_current_user( get_user_by( 'login', 'sf_local_admin' )->ID );
    if ( ! current_user_can( 'manage_options' ) || Access::article( $ids[0] ) ) throw new RuntimeException( 'Admin boundary changed.' );
    wp_set_current_user( $a->ID );
    // A historical later writer must never acquire the first creator's article.
    $table = Store::table(); $latest = Store::read( $ids[0] );
    if ( (int) $latest['version'] < 2 ) throw new RuntimeException( 'Save another version first.' );
    try {
        $wpdb->update( $table, array( 'created_by' => $b->ID ), array( 'article_id' => $ids[0], 'version' => $latest['version'] ) );
        if ( Access::owner( $ids[0] ) !== (int) $a->ID ) throw new RuntimeException( 'Owner changed with latest writer.' );
    } finally { $wpdb->update( $table, array( 'created_by' => $latest['created_by'] ), array( 'article_id' => $ids[0], 'version' => $latest['version'] ) ); }
    echo 'fixture-ok';
} else throw new RuntimeException( 'Unknown fixture operation.' );
