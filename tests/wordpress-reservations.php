<?php
require_once __DIR__ . '/wordpress-connection-context.php';
// wp eval-file tests/wordpress-reservations.php --path=<disposable WordPress>
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() ||
    ! preg_match( '/^sf_deft_post_types_[a-f0-9]+$/D', DB_NAME ) ) throw new RuntimeException( 'Use a disposable local database.' );
use Atshift\Deft\{Access, Connections, Drafts, Mcp, Publications, Reservations, Snapshot, Store};
global $wpdb, $sf_res_checks; $sf_res_checks = 0;
function sfre_check( $ok, $label ) { global $sf_res_checks; if ( ! $ok ) throw new RuntimeException( $label ); ++$sf_res_checks; }
function sfre_good( $value ) { if ( is_wp_error( $value ) ) throw new RuntimeException( $value->get_error_code() . ': ' . $value->get_error_message() ); return $value; }
function sfre_new() {
    $sample = json_decode( file_get_contents( WP_PLUGIN_DIR . '/atshift-deft/sample.json' ) );
    $sample->schemaVersion = 2;
    $sample->article->id = 'reservation-' . wp_generate_uuid4(); $sample->article->title = 'Destination reservation ' . $sample->article->id;
    return sfre_good( Store::save( Snapshot::validate( Snapshot::json( $sample ) ), 0 ) );
}
function sfre_intent( $draft, $type = 'post', $version = 0, $now = false ) {
    return array( 'articleId' => $draft['articleId'], 'expectedVersion' => $draft['version'], 'expectedPublicationVersion' => $version,
        'requestId' => wp_generate_uuid4(), 'timing' => $now ? 'now' : 'scheduled', 'publishAt' => $now ? '' : gmdate( 'Y-m-d\TH:i:s+00:00', time() + HOUR_IN_SECONDS ),
        'timeZone' => $now ? '' : 'UTC', 'postType' => $type );
}
function sfre_set( $body ) { return sfre_good( Mcp::execute( 'set-publication', $body ) ); }
function sfre_cancel( $job ) { return sfre_good( Mcp::execute( 'cancel-publication', array( 'articleId' => $job['articleId'], 'expectedPublicationVersion' => $job['version'] ) ) ); }
function sfre_listed( $id ) { return in_array( $id, array_column( Store::listing(), 'articleId' ), true ); }
function sfre_due( $job ) { global $wpdb; $wpdb->update( Publications::table(), array( 'publish_at' => time() - 5 ), array( 'article_id' => $job['articleId'] ) ); Publications::run( $job['articleId'], $job['version'] ); return sfre_good( Publications::state( $job['articleId'] ) ); }

$owner = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]->ID;
wp_set_current_user( $owner ); $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$id = wp_generate_uuid4(); $secret = bin2hex( random_bytes( 32 ) );
$wpdb->insert( Connections::table(), array( 'id' => $id, 'owner_id' => $owner, 'name' => 'Reservation suite', 'secret_hash' => hash( 'sha256', $secret ),
    'created' => time(), 'expires' => time() + HOUR_IN_SECONDS, 'publishing' => 1, 'authoring' => 1 ) );
$request = new WP_REST_Request( 'POST', Connections::PUBLISH_ROUTE ); $request->set_body( '{}' ); $request->set_header( 'authorization', 'Bearer sfc_' . $id . '_' . $secret );
sfre_good( sf_test_begin_connection( $request ) );

foreach ( array( 'post', 'page', 'sf_test_story' ) as $type ) {
    $draft = sfre_new(); $row = Store::read( $draft['articleId'] );
    sfre_check( sfre_listed( $draft['articleId'] ), 'New draft is initially listed.' );
    $intent = sfre_intent( $draft, $type ); $job = sfre_set( $intent ); $post_id = $job['postId'];
    sfre_check( $post_id > 0 && $type === get_post_type( $post_id ) && Reservations::STATUS === get_post_status( $post_id ), 'Reservation appears in the chosen post type.' );
    sfre_check( '' === get_post( $post_id )->post_content && ! is_post_publicly_viewable( $post_id ), 'Destination placeholder has no public body.' );
    sfre_check( ! wp_next_scheduled( 'publish_future_post', array( $post_id ) ), 'No independent core publication event exists.' );
    sfre_check( ! sfre_listed( $draft['articleId'] ) && Store::read( $draft['articleId'] ) === $row, 'Reservation leaves draft list without changing snapshot.' );
    sfre_check( in_array( $post_id, get_posts( array( 'post_type' => $type, 'post_status' => Reservations::STATUS, 'fields' => 'ids', 'posts_per_page' => -1 ) ), true ), 'Destination status query finds reservation.' );
    sfre_check( in_array( $draft['articleId'], array_column( sfre_good( Mcp::execute( 'list-publications', array() ) )['items'], 'articleId' ), true ), 'AI can rediscover reservation.' );
    sfre_check( sfre_set( $intent )['postId'] === $post_id, 'Idempotent retry retains one destination entry.' );
    sfre_check( ! current_user_can( 'edit_post', $post_id ) && ! current_user_can( 'delete_post', $post_id ), 'Native editor cannot bypass DeFt reservation checks.' );
    $caps = get_post_type_object( $type )->cap;
    sfre_check( ! current_user_can( $caps->edit_post, $post_id ) && ! current_user_can( $caps->delete_post, $post_id ), 'Type-specific capabilities also protect the reservation.' );
    sfre_check( is_wp_error( wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ), true ) ), 'Direct update cannot publish reservation early.' );
    check_and_publish_future_post( $post_id );
    sfre_check( Reservations::STATUS === get_post_status( $post_id ), 'Core future publisher ignores DeFt status.' );
    Publications::run( $draft['articleId'], $job['version'] );
    sfre_check( Reservations::STATUS === get_post_status( $post_id ), 'DeFt cron does not publish before due time.' );
    $actions = Reservations::actions( array( 'edit' => 'edit', 'trash' => 'trash' ), get_post( $post_id ) );
    sfre_check( isset( $actions['sf_manage'] ) && ! isset( $actions['edit'], $actions['trash'] ), 'Owner can manage reservation from destination row.' );
    $done = sfre_due( $job );
    sfre_check( 'published' === $done['state'] && $post_id === $done['postId'], 'Due publication reuses destination entry.' );
    sfre_check( str_contains( get_post( $post_id )->post_content, 'sf-article' ) && ! sfre_listed( $draft['articleId'] ), 'Published body is rendered and no longer in draft list.' );
}

$draft = sfre_new(); $job = sfre_set( sfre_intent( $draft ) ); $post_id = $job['postId'];
$changed = sfre_set( sfre_intent( $draft, 'page', $job['version'] ) );
sfre_check( $post_id === $changed['postId'] && 'page' === get_post_type( $post_id ), 'Reschedule and destination change reuse the same placeholder.' );
Publications::run( $draft['articleId'], $job['version'] );
sfre_check( 'scheduled' === Publications::state( $draft['articleId'] )['state'], 'Old cron cannot act on rescheduled reservation.' );
$cancelled = sfre_cancel( $changed );
sfre_check( 'cancelled' === $cancelled['state'] && Reservations::INACTIVE === get_post_status( $post_id ) && sfre_listed( $draft['articleId'] ), 'Cancellation hides reservation and returns unchanged draft.' );
sfre_check( ! get_post_status_object( Reservations::INACTIVE )->show_in_admin_all_list, 'Inactive placeholder is excluded from destination All list.' );
$renewed = sfre_set( sfre_intent( $draft, 'post', $cancelled['version'] ) );
sfre_check( $post_id === $renewed['postId'] && Reservations::STATUS === get_post_status( $post_id ), 'New reservation reuses inactive placeholder.' );
$cancelled = sfre_cancel( $renewed );

$draft = sfre_new(); $job = sfre_set( sfre_intent( $draft ) );
$snapshot = Store::response( Store::read( $draft['articleId'] ) )['snapshot']; $snapshot->article->revision++; $snapshot->article->title .= ' edited';
$updated = sfre_good( Store::save( Snapshot::validate( Snapshot::json( $snapshot ) ), $draft['version'] ) );
sfre_check( 'invalidated' === Publications::state( $draft['articleId'] )['state'] && Reservations::INACTIVE === get_post_status( $job['postId'] ) && sfre_listed( $draft['articleId'] ), 'Editing a reserved draft invalidates its destination entry and restores listing.' );
$blocked = sfre_due( $job );
sfre_check( 'blocked' === $blocked['state'] && Reservations::INACTIVE === get_post_status( $job['postId'] ), 'Invalidated cron remains nonpublic.' );

$draft = sfre_new(); $job = sfre_set( sfre_intent( $draft, 'page' ) );
$deny = static fn( $caps, $cap, $user, $args ) => in_array( $cap, array( 'edit_post', 'edit_page' ), true ) && (int) ( $args[0] ?? 0 ) === $job['postId'] ? array( 'do_not_allow' ) : $caps;
add_filter( 'map_meta_cap', $deny, 90, 4 );
try { $blocked = sfre_due( $job ); } finally { remove_filter( 'map_meta_cap', $deny, 90 ); }
sfre_check( 'blocked' === $blocked['state'] && Reservations::INACTIVE === get_post_status( $job['postId'] ) && sfre_listed( $draft['articleId'] ), 'Lost object permission blocks publication and returns draft.' );

$draft = sfre_new(); $job = sfre_set( sfre_intent( $draft ) );
$filter = static fn( $pre, $event ) => Publications::HOOK === $event->hook ? false : $pre;
add_filter( 'pre_schedule_event', $filter, 10, 2 );
try { $failed = Mcp::execute( 'set-publication', sfre_intent( $draft, 'post', $job['version'] ) ); } finally { remove_filter( 'pre_schedule_event', $filter, 10 ); }
sfre_check( is_wp_error( $failed ) && 'blocked' === Publications::read( $draft['articleId'] )['state'] && sfre_listed( $draft['articleId'] ), 'Cron scheduling failure cannot strand a hidden draft.' );
sfre_check( Reservations::INACTIVE === get_post_status( $job['postId'] ), 'Failed schedule hides its destination placeholder.' );

$draft = sfre_new(); $job = sfre_set( sfre_intent( $draft ) );
$wpdb->delete( Connections::table(), array( 'id' => $id ) ); Publications::revoke_connection( $id );
sfre_check( 'cancelled' === Publications::read( $draft['articleId'] )['state'] && Reservations::INACTIVE === get_post_status( $job['postId'] ) && sfre_listed( $draft['articleId'] ), 'Key revocation removes reservation and restores draft.' );
sfre_due( $job );
sfre_check( Reservations::INACTIVE === get_post_status( $job['postId'] ), 'Stale cron after revocation does not publish.' );
sfre_check( false === get_post_status_object( Reservations::STATUS )->public && true === get_post_status_object( Reservations::STATUS )->exclude_from_search, 'Reservation is never a public search status.' );
echo wp_json_encode( array( 'reservation_checks' => $sf_res_checks, 'passed' => true ) ) . "\n";
