<?php
require_once __DIR__ . '/wordpress-connection-context.php';
// wp eval-file tests/wordpress-draft-trash.php --path=<disposable WordPress>
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() ||
    ! preg_match( '/^sf_deft_post_types_[a-f0-9]+$/D', DB_NAME ) ) throw new RuntimeException( 'Use a disposable local database.' );

use Atshift\Deft\{Access, Assets, Catalog, Connections, Drafts, Mcp, Publications, PublicationMedia, PublicStructure, Review, Snapshot, Store, Transfer};
global $wpdb, $checks;
$checks = 0;
function sfdt_check( $ok, $label ) { global $checks; if ( ! $ok ) throw new RuntimeException( $label ); ++$checks; }
function sfdt_good( $value ) { if ( is_wp_error( $value ) ) throw new RuntimeException( $value->get_error_code() . ': ' . $value->get_error_message() ); return $value; }
function sfdt_error( $value, $code, $label ) { sfdt_check( is_wp_error( $value ) && $code === $value->get_error_code(), $label . ' Got: ' . ( is_wp_error( $value ) ? $value->get_error_code() : wp_json_encode( $value ) ) ); }
function sfdt_input( $draft ) { return array( 'articleId' => $draft['articleId'], 'expectedVersion' => $draft['version'], 'expectedStateVersion' => $draft['stateVersion'] ); }
function sfdt_request( $path, $body = null, $method = null ) {
    $r = new WP_REST_Request( $method ?? ( null === $body ? 'GET' : 'POST' ), '/structured-fields/v1/' . $path );
    if ( null !== $body ) { $r->set_header( 'content-type', 'application/json' ); $r->set_body( wp_json_encode( $body ) ); }
    return rest_do_request( $r );
}
function sfdt_key( $user, $publish = false ) {
    global $wpdb; $id = wp_generate_uuid4(); $secret = bin2hex( random_bytes( 32 ) );
    $wpdb->insert( Connections::table(), array( 'id' => $id, 'owner_id' => $user, 'name' => 'Disposable trash QA',
        'secret_hash' => hash( 'sha256', $secret ), 'created' => time(), 'expires' => time() + HOUR_IN_SECONDS,
        'authoring' => 1, 'publishing' => (int) $publish ) );
    return array( 'token' => 'sfc_' . $id . '_' . $secret, 'route' => $publish ? Connections::PUBLISH_ROUTE : Connections::ROUTE );
}
function sfdt_connect( $key ) {
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $r = new WP_REST_Request( 'POST', $key['route'] ); $r->set_body( '{}' ); $r->set_header( 'authorization', 'Bearer ' . $key['token'] );
    sfdt_good( sf_test_begin_connection( $r ) );
}
function sfdt_new( $title ) {
    return sfdt_good( Mcp::execute( 'save-draft', array( 'expectedVersion' => 0,
        'article' => array( 'id' => 'trash-' . wp_generate_uuid4(), 'title' => $title, 'revision' => 1, 'blocks' => array() ) ) ) );
}
function sfdt_publish( $d, $now = false ) {
    return sfdt_good( Mcp::execute( 'set-publication', array( 'articleId' => $d['articleId'], 'expectedVersion' => $d['version'], 'expectedPublicationVersion' => 0,
        'requestId' => wp_generate_uuid4(), 'timing' => $now ? 'now' : 'scheduled', 'publishAt' => $now ? '' : gmdate( 'Y-m-d\TH:i:s+00:00', time() + HOUR_IN_SECONDS ),
        'timeZone' => $now ? '' : 'UTC', 'postType' => 'post' ) ) );
}
$run = wp_generate_uuid4();
$password = wp_generate_password( 32, false );
$login = 'trash-qa-' . $run;
$owner = sfdt_good( wp_insert_user( array( 'user_login' => $login, 'user_pass' => $password, 'role' => 'atshde_publisher' ) ) );
update_user_meta( $owner, 'locale', 'ja' );
$other = sfdt_good( wp_insert_user( array( 'user_login' => 'trash-other-' . $run, 'user_pass' => wp_generate_password( 32 ), 'role' => 'atshde_publisher' ) ) );
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]->ID;
$key = sfdt_key( $owner ); $pubkey = sfdt_key( $owner, true );
sfdt_connect( $key );
$draft = sfdt_new( 'Discardable draft <b>literal title</b>' ); $id = $draft['articleId'];
sfdt_check( 'draft' === $draft['draftState'] && 0 === $draft['stateVersion'], 'Existing/new snapshots default to active without migration.' );
$template = $draft['composeTemplate']; $template['article']->title = 'Saved twice';
$draft = sfdt_good( Mcp::execute( 'save-draft', $template ) );
$before = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE article_id=%s ORDER BY version', Store::table(), $id ), ARRAY_A );
$review_request = new WP_REST_Request( 'POST' ); $review_request->set_param( 'id', $id );
$review_request->set_header( 'content-type', 'application/json' ); $review_request->set_body( '{"expectedVersion":2}' );
$review = sfdt_good( Review::create( $review_request ) );
$transfer_request = new WP_REST_Request( 'POST' ); $transfer_request->set_param( 'id', $id ); $transfer_request->set_header( 'content-type', 'application/json' );
$transfer_request->set_body( wp_json_encode( array( 'expectedVersion' => 2, 'filename' => 'proof.pdf', 'mime' => 'application/pdf', 'bytes' => 15, 'sha256' => str_repeat( 'a', 64 ), 'purpose' => 'reference' ) ) );
$grant = sfdt_good( Transfer::issue( $transfer_request ) );
$upload_request = new WP_REST_Request( 'POST' ); $upload_request->set_param( 'transfer', $grant['transferId'] ); $upload_request->set_header( 'x-sf-transfer-token', $grant['token'] );
sfdt_good( Transfer::authenticate( $upload_request ) );
sfdt_error( Mcp::execute( 'trash-draft', array_merge( sfdt_input( $draft ), array( 'expectedVersion' => 1 ) ) ), 'sf_conflict', 'Stale content version cannot trash.' );
foreach ( array( array(), array( 'expectedVersion' => '2' ), array( 'expectedStateVersion' => -1 ), array( 'force' => true ), array( 'articleId' => '../bad' ) ) as $bad ) {
    $input = $bad ? array_merge( sfdt_input( $draft ), $bad ) : array();
    sfdt_error( Mcp::execute( 'trash-draft', $input ), 'sf_draft_input', 'Strict lifecycle input validation.' );
}
foreach ( array( 0, $other, $admin ) as $user ) {
    wp_set_current_user( $user ); sfdt_error( Drafts::change( sfdt_input( $draft ), 'trash' ), 'sf_forbidden', 'Anonymous, other owner and administrator cannot trash another owner draft.' );
}
sfdt_connect( $key );
$asset = wp_generate_uuid4(); $bytes = "%PDF-1.4\n%%EOF\n";
$path = Assets::directory() . '/' . $asset . '.bin'; file_put_contents( $path, $bytes ); chmod( $path, 0600 );
$wpdb->insert( Assets::table(), array( 'asset_id' => $asset, 'article_id' => $id, 'purpose' => 'reference', 'sha256' => hash( 'sha256', $bytes ),
    'filename' => 'reference.pdf', 'mime' => 'application/pdf', 'bytes' => strlen( $bytes ), 'created_by' => $owner, 'created_at' => current_time( 'mysql', true ) ) );
$trashed = sfdt_good( Mcp::execute( 'trash-draft', sfdt_input( $draft ) ) );
Drafts::install();
sfdt_check( 1 === (int) get_option( 'atshde_drafts_schema_version' ) && 1 === Drafts::state( $id )['stateVersion'] && 'trash' === Drafts::state( $id )['draftState'], 'Repeated activation/schema installation preserves trash state.' );
sfdt_check( 'trash' === $trashed['draftState'] && 1 === $trashed['stateVersion'] && ! isset( $trashed['previewUrl'], $trashed['snapshot'] ), 'Trash returns metadata only with new state version.' );
sfdt_check( ! Access::article( $id ) && Access::owns_article( $id ), 'Trash blocks active access but keeps ownership.' );
sfdt_check( ! in_array( $id, array_column( Store::listing(), 'articleId' ), true ), 'Trash disappears from draft list.' );
sfdt_check( array( $id ) === array_column( sfdt_good( Mcp::execute( 'list-drafts', array( 'state' => 'trash' ) ) )['drafts'], 'articleId' ), 'Trash is discoverable via MCP.' );
sfdt_error( Mcp::execute( 'get-draft', array( 'articleId' => $id ) ), 'sf_missing', 'Trashed content cannot be resumed.' );
sfdt_error( Mcp::execute( 'save-draft', $draft['composeTemplate'] ), 'sf_forbidden', 'Saving cannot resurrect trashed ID.' );
sfdt_error( Assets::listing( $id ), 'sf_forbidden', 'Trashed private files are unavailable.' );
sfdt_error( Transfer::authenticate( $upload_request ), 'sf_transfer_denied', 'Existing file transfer grant cannot access trash.' );
sfdt_error( Review::state( Review::read( $review['reviewId'] ) ), 'sf_forbidden', 'Review is unavailable while in trash.' );
sfdt_error( Mcp::execute( 'get-preview', array( 'articleId' => $id, 'expectedVersion' => 2 ) ), 'sf_missing', 'Portable preview cannot read trash.' );
sfdt_check( is_file( $path ) && $bytes === file_get_contents( $path ), 'Trash preserves private file bytes.' );
sfdt_check( $before === $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE article_id=%s ORDER BY version', Store::table(), $id ), ARRAY_A ), 'Trash does not mutate or remove saved versions.' );
foreach ( $before as $row ) sfdt_check( 'draft' === get_post_status( $row['post_id'] ), 'Hidden preview posts avoid native automatic trash purge.' );
sfdt_error( Mcp::execute( 'trash-draft', sfdt_input( $draft ) ), 'sf_conflict', 'Uncertain repeated trash must refresh.' );
sfdt_error( Mcp::execute( 'restore-draft', sfdt_input( $draft ) ), 'sf_conflict', 'Stale restore cannot act.' );
foreach ( array( $other, $admin ) as $user ) {
    wp_set_current_user( $user ); sfdt_check( ! Store::listing( 'trash' ), 'Trash listing is owner-only, including admins.' );
    sfdt_error( Drafts::change( sfdt_input( $trashed ), 'draft' ), 'sf_forbidden', 'Other owners cannot restore.' );
}
sfdt_connect( $key );
$restored = sfdt_good( Mcp::execute( 'restore-draft', sfdt_input( $trashed ) ) );
sfdt_check( 'draft' === $restored['draftState'] && 2 === $restored['stateVersion'] && 2 === $restored['version'], 'Restore does not create a snapshot or publish.' );
sfdt_check( Access::article( $id ) && sfdt_good( Assets::listing( $id ) )['assets'][0]['assetId'] === $asset, 'Restored content and file access return.' );
sfdt_error( Mcp::execute( 'trash-draft', sfdt_input( $draft ) ), 'sf_conflict', 'Old pre-trash view cannot trash again after restore (ABA).' );
sfdt_good( Mcp::execute( 'get-draft', array( 'articleId' => $id ) ) );
sfdt_check( ! Publications::read( $id ), 'Lifecycle operations never create publication.' );

// REST uses the same owner and version checks, without accepting a body ID override.
sf_test_end_connection();
wp_set_current_user( $owner );
$route = 'articles/' . $id . '/trash'; $body = array( 'expectedVersion' => 2, 'expectedStateVersion' => 2 );
sfdt_check( 400 === sfdt_request( $route, $body + array( 'articleId' => 'other' ) )->get_status(), 'REST rejects body ID override.' );
sfdt_check( 404 === sfdt_request( $route, null )->get_status(), 'GET cannot mutate trash.' );
sfdt_check( 200 === sfdt_request( $route, $body )->get_status(), 'REST trashes current draft.' );
sfdt_check( 404 === sfdt_request( 'articles/' . $id )->get_status(), 'REST cannot load trash.' );
$history = new WP_REST_Request( 'GET', '/structured-fields/v1/articles/' . $id ); $history->set_param( 'version', 1 );
sfdt_check( 404 === rest_do_request( $history )->get_status(), 'Trash hides old versions.' );
sfdt_check( 200 === sfdt_request( 'articles/' . $id . '/restore', array( 'expectedVersion' => 2, 'expectedStateVersion' => 3 ) )->get_status(), 'REST restores.' );
wp_set_current_user( 0 ); sfdt_check( 403 === sfdt_request( $route, $body )->get_status(), 'REST rejects anonymous writes.' );

sfdt_connect( $pubkey );
foreach ( array( 'scheduled', 'processing', 'blocked' ) as $state ) {
    $d = sfdt_new( 'Reserved ' . $state ); $job = sfdt_publish( $d );
    $wpdb->update( Publications::table(), array( 'state' => $state ), array( 'article_id' => $d['articleId'] ) );
    sfdt_error( Mcp::execute( 'trash-draft', sfdt_input( $d ) ), 'sf_publication_active', 'Publication ' . $state . ' prevents trash.' );
    sfdt_check( 'draft' === Drafts::state( $d['articleId'] )['draftState'], 'Reservation remains active after rejected trash.' );
    if ( 'processing' === $state ) $wpdb->update( Publications::table(), array( 'state' => 'blocked' ), array( 'article_id' => $d['articleId'] ) );
    sfdt_good( Mcp::execute( 'cancel-publication', array( 'articleId' => $d['articleId'], 'expectedPublicationVersion' => 1 ) ) );
    $t = sfdt_good( Mcp::execute( 'trash-draft', sfdt_input( $d ) ) );
    Publications::run( $d['articleId'], 1 );
    sfdt_check( 'cancelled' === Publications::read( $d['articleId'] )['state'], 'Stale cron cannot publish cancelled trash.' );
    sfdt_good( Mcp::execute( 'restore-draft', sfdt_input( $t ) ) );
    sfdt_check( 'cancelled' === Publications::read( $d['articleId'] )['state'], 'Restore never reschedules.' );
}
$d = sfdt_new( 'Already public' ); $published = sfdt_publish( $d, true );
sfdt_check( 'published' === $published['state'], 'Publication fixture is public.' );
$post = get_post( $published['postId'], ARRAY_A ); $job = Publications::read( $d['articleId'] );
$t = sfdt_good( Mcp::execute( 'trash-draft', sfdt_input( $d ) ) );
sfdt_check( $post === get_post( $published['postId'], ARRAY_A ) && $job === Publications::read( $d['articleId'] ), 'Trashing private working copy leaves public post and job unchanged.' );
sfdt_check( Publications::matches_post( $job, get_post( $published['postId'] ), true ), 'Published identity remains valid.' );
sfdt_check( null !== PublicStructure::read( $published['postId'] ), 'Public structure remains available when working draft is trashed.' );
sfdt_error( Mcp::execute( 'set-publication', array( 'articleId' => $d['articleId'], 'expectedVersion' => 1, 'expectedPublicationVersion' => 1, 'requestId' => wp_generate_uuid4(), 'timing' => 'now', 'publishAt' => '', 'timeZone' => '' ) ), 'sf_forbidden', 'Trashed working copy cannot publish.' );

sfdt_connect( $key );
sfdt_check( ! Connections::can_publish(), 'Draft key still has no publication authority.' );
sfdt_check( sfdt_good( Mcp::execute( 'context', array() ) )['draftLifecycle']['restore'], 'AI context describes recovery.' );
foreach ( array( 'trash-draft', 'restore-draft' ) as $name ) {
    $ability = wp_get_ability( 'structured-fields/' . $name ); $meta = $ability->get_meta();
    sfdt_check( ! $meta['show_in_rest'] && ! $meta['mcp']['public'] && ! $meta['annotations']['readonly'], 'Lifecycle tools are private mutations.' );
}
sfdt_check( wp_get_ability( 'structured-fields/trash-draft' )->get_meta()['annotations']['destructive'], 'Trash tool declares destructive effect.' );
sfdt_error( Mcp::execute( 'list-drafts', array( 'state' => 'all' ) ), 'sf_draft_state', 'Unknown list states are rejected.' );

// Use an existing media publication from the destination suite when available.
$media_job = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE state=%s AND files NOT IN (%s,%s) LIMIT 1', Publications::table(), 'published', '[]', '{}' ), ARRAY_A );
if ( $media_job ) {
    wp_set_current_user( (int) $media_job['owner_id'] );
    $original = PublicStructure::read( $media_job['post_id'] );
    $working = Store::response( Store::read( $media_job['article_id'] ) );
    $trash = sfdt_good( Drafts::change( sfdt_input( $working ), 'trash' ) );
    sfdt_check( $original === PublicStructure::read( $media_job['post_id'] ), 'Public structure and layout survive working-copy trash.' );
    foreach ( json_decode( $media_job['files'], true ) as $file_key => $file ) {
        sfdt_check( $file['sha256'] === hash( 'sha256', PublicationMedia::bytes( $media_job['publication_id'], $file_key )[1] ), 'Frozen public media bytes survive trash.' );
    }
    sfdt_good( Drafts::change( sfdt_input( $trash ), 'draft' ) );
}

sfdt_connect( $key );
// A second DB connection holding the article lock must block lifecycle mutation.
$lock_draft = sfdt_new( 'Concurrent operation' );
$lock_db = new mysqli( '127.0.0.1', 'root', '', DB_NAME, 3306 );
$lock_name = Store::lock_name( $lock_draft['articleId'] );
$lock_db->query( "SELECT GET_LOCK('" . $lock_db->real_escape_string( $lock_name ) . "',0)" );
try { sfdt_error( Drafts::change( sfdt_input( $lock_draft ), 'trash' ), 'sf_busy', 'Concurrent save/publication lock prevents trash.' ); }
finally { $lock_db->query( "SELECT RELEASE_LOCK('" . $lock_db->real_escape_string( $lock_name ) . "')" ); $lock_db->close(); }

// Keep a private fixture for browser checks only when explicitly requested by the runner.
$ui = sfdt_new( 'Browser trash test - long draft title that wraps without covering the icon' );
if ( getenv( 'SF_TRASH_FIXTURE' ) ) {
    file_put_contents( getenv( 'SF_TRASH_FIXTURE' ), wp_json_encode( array( 'login' => $login, 'password' => $password, 'draft' => $ui, 'key' => $key ) ) );
    chmod( getenv( 'SF_TRASH_FIXTURE' ), 0600 );
}
echo wp_json_encode( array( 'checks' => $checks, 'passed' => true ) ) . "\n";
