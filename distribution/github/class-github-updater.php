<?php
/** GitHub-only distribution adapter. Never included in WordPress.org packages. */
namespace Atshift\Deft;

defined( 'ABSPATH' ) || exit;

final class GitHubUpdater {
    const REPOSITORY = 'https://github.com/at-shift/atshift-deft';
    const API = 'https://api.github.com/repos/at-shift/atshift-deft/releases/latest';
    const CACHE = 'atshift_deft_github_release_v1';
    private static $file;
    private static $version;

    public static function register( $file ) {
        self::$file = plugin_basename( $file );
        self::$version = get_file_data( $file, array( 'Version' => 'Version' ) )['Version'];
        add_filter( 'update_plugins_github.com', array( __CLASS__, 'update' ), 10, 4 );
        add_filter( 'plugins_api', array( __CLASS__, 'information' ), 10, 3 );
        add_filter( 'upgrader_pre_download', array( __CLASS__, 'download' ), 10, 4 );
        add_action( 'upgrader_process_complete', array( __CLASS__, 'completed' ), 10, 2 );
    }

    private static function request( $url, $extra = array() ) {
        return wp_safe_remote_get( $url, $extra + array(
            'timeout' => 15, 'redirection' => 5, 'limit_response_size' => 524288,
            'user-agent' => 'atshift-deft/' . self::$version,
            'headers' => array( 'Accept' => 'application/vnd.github+json' ),
        ) );
    }

    private static function json( $url, $limit, $redirects ) {
        $response = self::request( $url, array( 'limit_response_size' => $limit, 'redirection' => $redirects ) );
        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) return false;
        $data = json_decode( wp_remote_retrieve_body( $response ), true, 16 );
        return is_array( $data ) ? $data : false;
    }

    private static function release() {
        $cached = get_site_transient( self::CACHE );
        if ( is_array( $cached ) ) return $cached ?: false;
        // Negative caching also bounds requests during rate limits and malformed releases.
        set_site_transient( self::CACHE, array(), 15 * MINUTE_IN_SECONDS );
        $release = self::json( self::API, 524288, 0 );
        if ( ! $release || false !== ( $release['draft'] ?? null ) || false !== ( $release['prerelease'] ?? null ) ||
            ! is_string( $release['tag_name'] ?? null ) || ! preg_match( '/^v(\d+\.\d+\.\d+)$/D', $release['tag_name'], $match ) ) return false;
        $version = $match[1];
        $page = self::REPOSITORY . '/releases/tag/v' . $version;
        if ( ( $release['html_url'] ?? null ) !== $page || ! is_array( $release['assets'] ?? null ) ) return false;
        $filename = 'atshift-deft-' . $version . '.zip';
        $base = self::REPOSITORY . '/releases/download/v' . $version . '/';
        $assets = array();
        foreach ( $release['assets'] as $asset ) {
            if ( ! is_array( $asset ) || ! in_array( $asset['name'] ?? null, array( $filename, 'atshift-deft-update.json' ), true ) ) continue;
            $name = $asset['name'];
            if ( isset( $assets[$name] ) || ( $asset['state'] ?? null ) !== 'uploaded' ||
                ( $asset['browser_download_url'] ?? null ) !== $base . $name ||
                ! is_int( $asset['size'] ?? null ) || $asset['size'] < 1 || $asset['size'] > 26214400 ) return false;
            $assets[$name] = $asset;
        }
        if ( ! isset( $assets[$filename], $assets['atshift-deft-update.json'] ) || $assets['atshift-deft-update.json']['size'] > 16384 ) return false;
        $data = self::json( $base . 'atshift-deft-update.json', 16384, 5 );
        if ( ! $data || 1 !== ( $data['schemaVersion'] ?? null ) || 'github' !== ( $data['channel'] ?? null ) ||
            'atshift-deft/atshift-deft.php' !== ( $data['plugin'] ?? null ) || $version !== ( $data['version'] ?? null ) ||
            $page !== ( $data['url'] ?? null ) || $base . $filename !== ( $data['package'] ?? null ) ||
            $assets[$filename]['size'] !== ( $data['bytes'] ?? null ) ||
            ! is_string( $data['sha256'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $data['sha256'] ) ) return false;
        foreach ( array( 'requires', 'requires_php', 'tested' ) as $key ) {
            if ( ! is_string( $data[$key] ?? null ) || ! preg_match( '/^\d+\.\d+(?:\.\d+)?$/D', $data[$key] ) ) return false;
        }
        $digest = $assets[$filename]['digest'] ?? null;
        if ( null !== $digest && 'sha256:' . $data['sha256'] !== $digest ) return false;
        set_site_transient( self::CACHE, $data, 6 * HOUR_IN_SECONDS );
        return $data;
    }

    public static function update( $update, $plugin_data, $plugin_file, $locales ) {
        if ( self::$file !== $plugin_file || self::REPOSITORY !== ( $plugin_data['UpdateURI'] ?? null ) ) return $update;
        $release = self::release();
        if ( ! $release || ! version_compare( $release['version'], self::$version, '>' ) ) return false;
        // Do not set autoupdate: the site owner's WordPress preference remains authoritative.
        return array( 'id' => self::REPOSITORY, 'slug' => 'atshift-deft', 'version' => $release['version'],
            'url' => $release['url'], 'package' => $release['package'], 'requires' => $release['requires'],
            'requires_php' => $release['requires_php'], 'tested' => $release['tested'] );
    }

    public static function information( $result, $action, $args ) {
        if ( 'plugin_information' !== $action || 'atshift-deft' !== ( $args->slug ?? '' ) ) return $result;
        $release = self::release();
        if ( ! $release ) return $result;
        return (object) array( 'name' => 'atshift DeFt', 'slug' => 'atshift-deft', 'version' => $release['version'],
            'author' => 'at-shift', 'homepage' => self::REPOSITORY, 'download_link' => $release['package'],
            'requires' => $release['requires'], 'requires_php' => $release['requires_php'], 'tested' => $release['tested'],
            'sections' => array( 'description' => esc_html__( 'Create structured articles with your preferred AI.', 'atshift-deft' ),
                'changelog' => '<a href="' . esc_url( $release['url'] ) . '">' . esc_html__( 'View release notes on GitHub', 'atshift-deft' ) . '</a>' ) );
    }

    public static function download( $reply, $package, $upgrader, $hook_extra ) {
        if ( false !== $reply || self::$file !== ( $hook_extra['plugin'] ?? null ) ) return $reply;
        // This hook is an update integrity boundary; an unavailable/stale release must fail closed.
        $release = self::release();
        if ( ! $release || ! is_string( $package ) || ! hash_equals( $release['package'], $package ) ) {
            return new \WP_Error( 'atshift_deft_update_unverified', __( 'The GitHub update could not be verified. Check for updates again.', 'atshift-deft' ) );
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $file = wp_tempnam( 'atshift-deft-update.zip' );
        if ( ! $file ) return new \WP_Error( 'atshift_deft_update_temp', __( 'Could not create a temporary update file.', 'atshift-deft' ) );
        $response = self::request( $package, array( 'timeout' => 300, 'stream' => true, 'filename' => $file,
            'limit_response_size' => $release['bytes'] + 1, 'headers' => array( 'Accept' => 'application/octet-stream' ) ) );
        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            wp_delete_file( $file );
            return is_wp_error( $response ) ? $response : new \WP_Error( 'atshift_deft_update_download', __( 'The GitHub update download failed.', 'atshift-deft' ) );
        }
        $actual = hash_file( 'sha256', $file );
        if ( filesize( $file ) !== $release['bytes'] || ! is_string( $actual ) || ! hash_equals( $release['sha256'], $actual ) ) {
            wp_delete_file( $file );
            return new \WP_Error( 'atshift_deft_update_checksum', __( 'The update ZIP failed its SHA-256 integrity check.', 'atshift-deft' ) );
        }
        return $file;
    }

    public static function completed( $upgrader, $options ) {
        $plugins = $options['plugins'] ?? array( $options['plugin'] ?? '' );
        if ( 'plugin' === ( $options['type'] ?? null ) && in_array( self::$file, $plugins, true ) ) delete_site_transient( self::CACHE );
    }
}
