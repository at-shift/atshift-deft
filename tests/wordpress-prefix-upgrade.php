<?php
// Run seed with the previous ZIP, then verify with the replacement ZIP, in a disposable site.
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'GET';
require dirname( __DIR__ ) . '/.local/wordpress/wp-load.php';
if ( ! preg_match( '/^sf_deft_prefix_[a-f0-9]+$/D', DB_NAME ) ||
    home_url() !== 'http://127.0.0.1:8128' || wp_get_environment_type() !== 'local' ) {
    throw new RuntimeException( 'Only a disposable prefix-upgrade database is allowed.' );
}
$phase = $argv[1] ?? '';
$state_file = dirname( __DIR__ ) . '/.local/prefix-upgrade-state.json';
$ns = 'seed' === $phase ? 'StructuredFields\\' : 'Atshift\Deft\\';
$store = $ns . 'Store'; $snapshot = $ns . 'Snapshot'; $assets = $ns . 'Assets';
$access = $ns . 'Access'; $connections = $ns . 'Connections'; $oauth = $ns . 'OAuth';
$publications = $ns . 'Publications'; $reservations = $ns . 'Reservations';
$checks = 0;
function prefix_check( $ok, $label ) {
    global $checks;
    if ( ! $ok ) throw new RuntimeException( $label );
    echo 'PASS ' . ++$checks . ': ' . $label . PHP_EOL;
}
function prefix_request( $body ) {
    $r = new WP_REST_Request( 'POST' );
    $r->set_header( 'content-type', 'application/json' );
    $r->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
    $r->set_body( wp_json_encode( $body ) );
    return $r;
}
function prefix_old_names( $value ) {
    if ( ! is_array( $value ) ) return $value;
    $out = array();
    foreach ( $value as $key => $item ) {
        if ( is_string( $key ) && str_starts_with( $key, 'atshde_' ) ) $key = 'sf_' . substr( $key, 7 );
        $out[$key] = prefix_old_names( $item );
    }
    return $out;
}
function prefix_state() {
    global $wpdb;
    $prefix = class_exists( 'Atshift\Deft\\PrefixMigration' ) ? 'atshde_' : 'sf_';
    $tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . $prefix ) . '%' ) );
    sort( $tables ); $hashes = array();
    foreach ( $tables as $table ) {
        if ( str_ends_with( $table, 'atshde_blob_chunks' ) ) continue;
        $rows = array_map( 'serialize', $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', $table ), ARRAY_A ) );
        sort( $rows ); $hashes[str_replace( $wpdb->prefix . $prefix, $wpdb->prefix . 'sf_', $table )] = hash( 'sha256', serialize( $rows ) );
    }
    $options = $wpdb->get_results( $wpdb->prepare(
        'SELECT option_name,option_value FROM %i WHERE option_name LIKE %s ORDER BY option_name',
        $wpdb->options, $wpdb->esc_like( $prefix ) . '%' ), ARRAY_A );
    $options = array_values( array_filter( $options, static fn( $row ) => ! in_array( $row['option_name'], array( 'atshde_prefix_migration', 'atshde_blob_schema_version' ), true ) ) );
    foreach ( $options as &$row ) $row['option_name'] = 'sf_' . substr( $row['option_name'], strlen( $prefix ) );
    unset( $row );
    return array( 'tables' => $hashes, 'options' => $options, 'roles' => prefix_old_names( wp_roles()->roles ) );
}
$admin = get_user_by( 'login', 'sf_local_admin' );
prefix_check( $admin && class_exists( $store ), 'Expected old/new runtime is loaded' );
wp_set_current_user( $admin->ID );
if ( 'seed' === $phase ) {
    prefix_check( ! file_exists( $state_file ), 'No previous seed is overwritten' );
    $_COOKIE[LOGGED_IN_COOKIE] = wp_generate_auth_cookie( $admin->ID, time() + HOUR_IN_SECONDS, 'logged_in' );
    $sample = json_decode( file_get_contents( dirname( __DIR__ ) . '/wordpress/atshift-deft/sample.json' ) );
    $sample->article->id = 'prefix-upgrade-' . wp_generate_uuid4();
    $saved = $store::save( $snapshot::validate( $snapshot::json( $sample ) ), 0 );
    prefix_check( is_array( $saved ) && 1 === $saved['version'], 'Old runtime saves a real immutable draft' );
    $connection = $connections::create( prefix_request( array( 'name' => 'Disposable upgrade key', 'authoring' => true ) ) );
    prefix_check( is_array( $connection ) && isset( $connection['token'] ), 'Old runtime issues a real scoped connection key' );
    $client = $oauth::register_client( prefix_request( array( 'name' => 'Disposable upgrade OAuth', 'provider' => 'other',
        'redirectUri' => 'http://127.0.0.1:8128/fixture-callback', 'authoring' => true, 'publishing' => false ) ) );
    prefix_check( is_array( $client ) && isset( $client['id'] ), 'Old runtime configures OAuth and encrypts server keys' );
    $keys_method = new ReflectionMethod( $oauth, 'keys' );
    $key_hash = hash( 'sha256', $keys_method->invoke( null )['private'] );
    $user = wp_insert_user( array( 'user_login' => 'prefix-author', 'user_pass' => wp_generate_password( 40 ), 'role' => 'sf_author' ) );
    prefix_check( is_int( $user ), 'Disposable author created' );
    $account = get_user_by( 'id', $user );
    $account->add_cap( 'sf_review_drafts', false );
    $account->add_cap( 'prefix_unrelated_capability', true );
    update_user_meta( $user, '_sf_ai_welcome_seen_' . get_current_blog_id(), 123456 );
    $path = $assets::directory();
    $sentinel = $path . '/prefix-upgrade-sentinel';
    file_put_contents( $sentinel, 'Existing private-file path must not move.' );
    $media_sample = json_decode( file_get_contents( dirname( __DIR__ ) . '/wordpress/atshift-deft/media-sample.json' ) );
    $media_sample->article->id = 'legacy-media-' . wp_generate_uuid4();
    prefix_check( is_array( $store::save( $snapshot::validate( $snapshot::json( $media_sample ) ), 0 ) ), 'Old runtime saves the draft before attaching files' );
    $legacy_assets = array();
    global $wpdb;
    foreach ( array( 'image' => array( 'old.png', file_get_contents( get_theme_file_path( 'screenshot.png' ) ), 'image/png' ),
        'document' => array( 'old.pdf', "%PDF-1.4\n%%EOF\n", 'application/pdf' ) ) as $field => $file ) {
        $id = wp_generate_uuid4();
        $meta = array( 'asset_id' => $id, 'article_id' => $media_sample->article->id, 'purpose' => 'publication',
            'filename' => $file[0], 'sha256' => hash( 'sha256', $file[1] ), 'mime' => $file[2], 'bytes' => strlen( $file[1] ),
            'created_by' => $admin->ID, 'created_at' => current_time( 'mysql', true ) );
        file_put_contents( $path . '/' . $id . '.bin', $file[1] ); chmod( $path . '/' . $id . '.bin', 0600 );
        prefix_check( 1 === $wpdb->insert( $assets::table(), $meta ), 'Legacy private media fixture saved' );
        $assets::verified_path( $meta ); $legacy_assets[] = $meta;
        $media_sample->article->blocks[0]->values->$field->assetRef = (object) array( 'kind' => 'staged', 'id' => $id, 'sha256' => $meta['sha256'] );
        $media_sample->article->blocks[0]->values->$field->alt = 'Legacy fixture';
    }
    $media_sample->article->revision++;
    $media_saved = $store::save( $snapshot::validate( $snapshot::json( $media_sample ) ), 1 );
    prefix_check( is_array( $media_saved ), 'Old runtime saves a media-bound article' );
    $published = $publications::set( prefix_request( array( 'articleId' => $media_sample->article->id,
        'expectedVersion' => 2, 'expectedPublicationVersion' => 0, 'requestId' => wp_generate_uuid4(),
        'postType' => 'post', 'timing' => 'now', 'publishAt' => '', 'timeZone' => '' ) ) );
    prefix_check( is_array( $published ) && 'published' === $published['state'], 'Old runtime freezes and publishes legacy image/PDF files' );
    $legacy_publication = $publications::read( $media_sample->article->id );
    $reservation = wp_insert_post( array( 'post_type' => 'post', 'post_status' => $reservations::STATUS,
        'post_author' => $admin->ID, 'post_title' => 'Disposable reservation',
        'meta_input' => array( '_sf_publication' => 'prefix-upgrade-fixture' ) ), true );
    prefix_check( is_int( $reservation ), 'Old reservation and publication marker created' );
    $when = time() + DAY_IN_SECONDS;
    wp_schedule_single_event( $when, $publications::HOOK, array( $sample->article->id, 1 ) );
    $row = $store::read( $sample->article->id );
    $state = array( 'database' => prefix_state(), 'row' => $row, 'sample' => $sample, 'saved' => $saved,
        'connection' => $connection, 'client' => $client, 'keyHash' => $key_hash, 'user' => $user, 'cookie' => $_COOKIE[LOGGED_IN_COOKIE],
        'caps' => $account->caps, 'privateDirectory' => $path, 'sentinelHash' => hash_file( 'sha256', $sentinel ),
        'legacyAssets' => $legacy_assets, 'legacyPublication' => $legacy_publication,
        'reservation' => $reservation, 'when' => $when, 'preview' => $store::preview_url( $row ),
        'previewNonce' => wp_create_nonce( 'sf-preview-' . $row['post_id'] . '-' . $row['digest'] ) );
    file_put_contents( $state_file, wp_json_encode( $state, JSON_THROW_ON_ERROR ) );
    chmod( $state_file, 0600 );
} elseif ( 'verify' === $phase ) {
    $state = json_decode( file_get_contents( $state_file ), true, 512, JSON_THROW_ON_ERROR );
    $_COOKIE[LOGGED_IN_COOKIE] = $state['cookie'];
    $expected_database = $state['database'];
    // Released packages also receive the separately tested display-label migration.
    foreach ( $expected_database['options'] as &$option ) {
        if ( 'sf_brand_version' === $option['option_name'] && '1' === $option['option_value'] ) $option['option_value'] = '2';
    }
    unset( $option );
    foreach ( array( 'author', 'designer', 'publisher' ) as $role ) {
        if ( ( $expected_database['roles']['sf_' . $role]['name'] ?? '' ) === 'atshift DEFT ' . $role ) {
            $expected_database['roles']['sf_' . $role]['name'] = 'atshift DeFt ' . $role;
        }
    }
    prefix_check( prefix_state() == $expected_database, 'All migrated data and capabilities are preserved, with only the defined branding-label/version update' );
    prefix_check( $store::read( $state['sample']['article']['id'] ) === $state['row'], 'Old saved payload, digest, owner and preview post remain identical' );
    prefix_check( $store::preview_url( $state['row'] ) === str_replace( 'sf_article', 'atshde_article', $state['preview'] ), 'Only the post-type identifier changes in new preview links' );
    prefix_check( apply_filters( 'request', array( 'post_type' => 'sf_article' ) )['post_type'] === 'atshde_article', 'Old preview URL type resolves to the migrated private post type' );
    prefix_check( (bool) $access::verify_nonce( $state['previewNonce'], 'sf-preview-' . $state['row']['post_id'] . '-' . $state['row']['digest'] ), 'Old signed preview nonce remains valid' );
    prefix_check( $assets::directory() === $state['privateDirectory'] &&
        hash_file( 'sha256', $assets::directory() . '/prefix-upgrade-sentinel' ) === $state['sentinelHash'], 'Existing private-file location and bytes are unchanged' );
    foreach ( $state['legacyAssets'] as $meta ) {
        prefix_check( hash( 'sha256', $assets::verified_bytes( $meta ) ) === $meta['sha256'], 'Legacy staged media remains readable after migration' );
    }
    $publication_media = $ns . 'PublicationMedia';
    foreach ( json_decode( $state['legacyPublication']['files'], true ) as $key => $meta ) {
        list( $after_meta, $bytes ) = $publication_media::bytes( $state['legacyPublication']['publication_id'], $key );
        prefix_check( $after_meta === $meta && hash( 'sha256', $bytes ) === $meta['sha256'], 'Existing public image/PDF still resolves to unchanged legacy frozen bytes' );
    }
    prefix_check( $oauth::libraries(), 'New isolated OAuth dependencies load' );
    $keys_method = new ReflectionMethod( $oauth, 'keys' );
    prefix_check( hash( 'sha256', $keys_method->invoke( null )['private'] ) === $state['keyHash'], 'New vendor namespace decrypts the old OAuth signing keys' );
    prefix_check( wp_next_scheduled( 'sf_publish_article', array( $state['sample']['article']['id'], 1 ) ) === $state['when'] &&
        false !== has_action( 'sf_publish_article', array( $publications, 'run' ) ), 'Existing scheduled hook alias targets the new runtime without duplicating jobs' );
    prefix_check( $reservations::managed( get_post( $state['reservation'] ) ), 'Existing reservation remains protected against ordinary post edits' );
    $account = get_user_by( 'id', $state['user'] );
    prefix_check( prefix_old_names( $account->caps ) == $state['caps'] && $access::workspace( $account->ID ) && ! $access::reviewer( $account->ID ), 'Migrated author and explicit capability denial are preserved' );
    prefix_check( '123456' === get_user_meta( $account->ID, '_atshde_ai_welcome_seen_' . get_current_blog_id(), true ), 'Existing onboarding value is preserved under the new key' );
    prefix_check( ! $access::article( $state['sample']['article']['id'], $account->ID ), 'Other account still cannot read the old private draft' );
    wp_set_current_user( 0 );
    unset( $_COOKIE[LOGGED_IN_COOKIE] );
    $request = new WP_REST_Request( 'POST', $connections::ROUTE );
    $request->set_header( 'authorization', 'Bearer ' . $state['connection']['token'] );
    $result = $connections::guard( null, rest_get_server(), $request );
    prefix_check( null === $result && get_current_user_id() === (int) $admin->ID, 'Pre-upgrade connection key authenticates with its original owner' );
    $connections::finish( new WP_REST_Response(), rest_get_server(), $request );
    prefix_check( 0 === get_current_user_id(), 'Connection request restores the previous WordPress account' );
    wp_set_current_user( $admin->ID );
    $sample = json_decode( wp_json_encode( $state['sample'] ) );
    $sample->article->revision++;
    $sample->article->title = 'Updated after namespace isolation';
    $saved = $store::save( $snapshot::validate( $snapshot::json( $sample ) ), 1 );
    prefix_check( is_array( $saved ) && 2 === $saved['version'], 'Old draft can be updated with optimistic concurrency' );
    prefix_check( $store::read( $sample->article->id, 1 ) === $state['row'], 'Updating the old draft keeps its historical version intact' );
    prefix_check( ! class_exists( 'StructuredFields\\Store', false ) && ! defined( 'StructuredFields\\VERSION' ), 'No broad legacy PHP namespace is re-exported' );
} else {
    throw new RuntimeException( 'Expected seed or verify.' );
}
echo "Prefix upgrade $phase: $checks checks passed.\n";
