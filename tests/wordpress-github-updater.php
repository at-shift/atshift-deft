<?php
// WP-CLI eval-file, after installing a disposable GitHub package with a 0.5.9 fixture header.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! str_starts_with( DB_NAME, 'sf_deft_appearance_' ) ) {
    throw new RuntimeException( 'Use a disposable local release-test database.' );
}
use AtshiftDeft\GitHubUpdater;

$artifact = getenv( 'SF_RELEASE_DIR' );
$manifest = json_decode( file_get_contents( $artifact . '/atshift-deft-update.json' ), true );
$zip = file_get_contents( $artifact . '/atshift-deft-' . $manifest['version'] . '.zip' );
$file = 'atshift-deft/atshift-deft.php';
$entry = WP_PLUGIN_DIR . '/' . $file;
$header = get_plugin_data( $entry, false, false );
if ( '0.5.9' !== $header['Version'] || ! class_exists( GitHubUpdater::class ) ) throw new RuntimeException( 'Install the guarded older-header fixture first.' );
$GLOBALS['deft_update_checks'] = 0;
function deft_update_check( $ok, $message ) {
    if ( ! $ok ) throw new RuntimeException( $message );
    ++$GLOBALS['deft_update_checks'];
}
function deft_update_state() {
    global $wpdb;
    return json_encode( array( get_theme_mods(),
        $wpdb->get_results( 'SELECT * FROM ' . StructuredFields\Store::table() . ' ORDER BY article_id', ARRAY_A ),
        $wpdb->get_results( 'SELECT * FROM ' . StructuredFields\Catalog::table() . ' ORDER BY component_id', ARRAY_A ) ) );
}
$base = GitHubUpdater::REPOSITORY . '/releases/download/v' . $manifest['version'] . '/';
$valid_release = array( 'draft' => false, 'prerelease' => false, 'tag_name' => 'v' . $manifest['version'],
    'html_url' => $manifest['url'], 'assets' => array(
        array( 'name' => basename( $manifest['package'] ), 'state' => 'uploaded', 'browser_download_url' => $manifest['package'],
            'size' => strlen( $zip ), 'digest' => 'sha256:' . hash( 'sha256', $zip ) ),
        array( 'name' => 'atshift-deft-update.json', 'state' => 'uploaded', 'browser_download_url' => $base . 'atshift-deft-update.json', 'size' => 1000 ),
    ) );
$release = $valid_release; $metadata = $manifest; $tamper = false; $requests = array(); $fail = false;
$http = static function( $reply, $args, $url ) use ( &$release, &$metadata, &$tamper, &$requests, &$fail, $manifest, $zip, $base ) {
    $requests[] = array( $url, $args );
    if ( str_starts_with( $url, 'https://api.wordpress.org/plugins/update-check/' ) ) {
        return array( 'response' => array( 'code' => 200 ), 'body' => '{"plugins":{},"no_update":{},"translations":[]}', 'headers' => array() );
    }
    if ( str_starts_with( $url, 'https://api.wordpress.org/themes/update-check/' ) ) {
        return array( 'response' => array( 'code' => 200 ), 'body' => '{"themes":{},"no_update":{},"translations":[]}', 'headers' => array() );
    }
    if ( str_starts_with( $url, 'https://api.wordpress.org/core/version-check/' ) ) {
        return array( 'response' => array( 'code' => 200 ), 'body' => '{"offers":[]}', 'headers' => array() );
    }
    if ( $fail ) return new WP_Error( 'fixture_offline', 'Offline fixture' );
    if ( GitHubUpdater::API === $url ) $body = json_encode( $release );
    elseif ( $base . 'atshift-deft-update.json' === $url ) $body = json_encode( $metadata );
    elseif ( $manifest['package'] === $url && ! empty( $args['stream'] ) ) {
        $bytes = 'same-length' === $tamper ? 'X' . substr( $zip, 1 ) : $zip . ( $tamper ? 'altered' : '' );
        file_put_contents( $args['filename'], $bytes ); $body = '';
    } else return new WP_Error( 'fixture_external_http', 'Unexpected external request blocked by fixture' );
    return array( 'response' => array( 'code' => 200 ), 'body' => $body, 'headers' => array() );
};
add_filter( 'pre_http_request', $http, 10, 3 );
$before = deft_update_state(); $preferences = get_site_option( 'auto_update_plugins', array() );
try {
    delete_site_transient( GitHubUpdater::CACHE );
    $sentinel = array( 'keep' => true );
    deft_update_check( $sentinel === GitHubUpdater::update( $sentinel, $header, 'another/plugin.php', array() ) && ! $requests, 'Other plugin updates are not intercepted or fetched.' );
    $update = GitHubUpdater::update( false, $header, $file, array( 'ja' ) );
    deft_update_check( $update['version'] === $manifest['version'] && $update['package'] === $manifest['package'], 'New stable release is offered with exact installable ZIP.' );
    deft_update_check( ! array_key_exists( 'autoupdate', $update ), 'Updater does not override the auto-update preference.' );
    foreach ( array( '0.5.9', '0.5.8' ) as $older ) {
        $old = $manifest; $old['version'] = $older; set_site_transient( GitHubUpdater::CACHE, $old );
        deft_update_check( false === GitHubUpdater::update( false, $header, $file, array() ), 'Equal or older versions are not offered: ' . $older );
    }
    set_site_transient( GitHubUpdater::CACHE, $manifest );
    $count = count( $requests ); GitHubUpdater::update( false, $header, $file, array() );
    deft_update_check( count( $requests ) === $count, 'Verified metadata is cached.' );
    $info = GitHubUpdater::information( false, 'plugin_information', (object) array( 'slug' => 'atshift-deft' ) );
    deft_update_check( $info->download_link === $manifest['package'] && $info->version === $manifest['version'], 'Plugin details use the same release.' );
    foreach ( array( 'draft', 'prerelease', 'tag', 'page', 'url', 'missing', 'duplicate', 'digest', 'size' ) as $case ) {
        $release = $valid_release;
        if ( in_array( $case, array( 'draft', 'prerelease' ), true ) ) $release[$case] = true;
        elseif ( 'tag' === $case ) $release['tag_name'] .= '-beta.1';
        elseif ( 'page' === $case ) $release['html_url'] = 'https://example.invalid/release';
        elseif ( 'url' === $case ) $release['assets'][0]['browser_download_url'] .= '?redirect=elsewhere';
        elseif ( 'missing' === $case ) array_pop( $release['assets'] );
        elseif ( 'duplicate' === $case ) $release['assets'][] = $release['assets'][0];
        elseif ( 'digest' === $case ) $release['assets'][0]['digest'] = 'sha256:' . str_repeat( '0', 64 );
        elseif ( 'size' === $case ) $release['assets'][0]['size'] = 30000000;
        delete_site_transient( GitHubUpdater::CACHE );
        deft_update_check( false === GitHubUpdater::update( false, $header, $file, array() ), 'Reject invalid release: ' . $case );
    }
    $release = $valid_release;
    foreach ( array( 'channel' => 'wordpress-org', 'version' => '99.0.0', 'plugin' => 'other/other.php',
        'package' => 'https://example.invalid/plugin.zip', 'sha256' => 'invalid', 'requires_php' => '<script>', 'bytes' => 1 ) as $key => $bad ) {
        $metadata = $manifest; $metadata[$key] = $bad; delete_site_transient( GitHubUpdater::CACHE );
        deft_update_check( false === GitHubUpdater::update( false, $header, $file, array() ), 'Reject invalid manifest: ' . $key );
    }
    $metadata = $manifest; $fail = true; delete_site_transient( GitHubUpdater::CACHE );
    deft_update_check( false === GitHubUpdater::update( false, $header, $file, array() ), 'Offline update check fails closed.' );
    $count = count( $requests ); GitHubUpdater::update( false, $header, $file, array() );
    deft_update_check( count( $requests ) === $count, 'Failures are negatively cached.' );
    $fail = false; delete_site_transient( GitHubUpdater::CACHE );
    deft_update_check( false === GitHubUpdater::download( false, 'https://example.invalid/other.zip', null, array( 'plugin' => 'other/other.php' ) ), 'Other plugin downloads are untouched.' );
    deft_update_check( is_wp_error( GitHubUpdater::download( false, 'https://example.invalid/other.zip', null, array( 'plugin' => $file ) ) ), 'Unexpected download URL cannot bypass verification.' );
    $tamper = true;
    deft_update_check( is_wp_error( GitHubUpdater::download( false, $manifest['package'], null, array( 'plugin' => $file ) ) ), 'Tampered ZIP is rejected before extraction.' );
    $tamper = 'same-length';
    deft_update_check( is_wp_error( GitHubUpdater::download( false, $manifest['package'], null, array( 'plugin' => $file ) ) ), 'Same-length tampering fails the SHA-256 check.' );
    $tamper = false;
    $downloaded = GitHubUpdater::download( false, $manifest['package'], null, array( 'plugin' => $file ) );
    deft_update_check( is_string( $downloaded ) && hash_file( 'sha256', $downloaded ) === $manifest['sha256'], 'Valid ZIP bytes pass integrity verification.' );
    wp_delete_file( $downloaded );
    foreach ( $requests as list( $url, $args ) ) {
        if ( ! str_starts_with( $url, 'https://api.wordpress.org/' ) ) {
            deft_update_check( ! str_contains( $args['user-agent'], home_url() ) && empty( $args['cookies'] ) && empty( $args['headers']['Authorization'] ), 'GitHub requests omit site URL and credentials.' );
        }
    }
    delete_site_transient( 'update_plugins' ); wp_update_plugins();
    $available = get_site_transient( 'update_plugins' );
    deft_update_check( $manifest['version'] === ( $available->response[$file]->new_version ?? null ), 'Native WordPress update check exposes the new version.' );
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    $upgrader = new Plugin_Upgrader( new WP_Ajax_Upgrader_Skin() );
    $result = $upgrader->bulk_upgrade( array( $file ), array( 'clear_update_cache' => true ) );
    deft_update_check( is_array( $result[$file] ?? null ), 'Native plugin-screen upgrader installs the verified ZIP.' );
    wp_clean_plugins_cache();
    deft_update_check( get_plugin_data( $entry, false, false )['Version'] === $manifest['version'], 'Installed plugin version matches the release.' );
    deft_update_check( is_plugin_active( $file ), 'The installed plugin remains active.' );
    deft_update_check( $before === deft_update_state(), 'Drafts, shared components and theme settings survive the update.' );
    deft_update_check( $preferences === get_site_option( 'auto_update_plugins', array() ), 'Automatic-update preferences remain unchanged.' );
    deft_update_check( file_exists( dirname( $entry ) . '/languages/atshift-deft-ja.mo' ), 'Installed update includes Japanese translations.' );
    echo json_encode( array( 'updater_checks' => $GLOBALS['deft_update_checks'], 'installed' => $manifest['version'] ) ), PHP_EOL;
} finally {
    remove_filter( 'pre_http_request', $http, 10 ); delete_site_transient( GitHubUpdater::CACHE );
}
