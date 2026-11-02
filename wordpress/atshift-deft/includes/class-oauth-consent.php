<?php
namespace Atshift\Deft;

defined( 'ABSPATH' ) || exit;

final class OAuthConsent {
    private const PARAMETERS = array( 'response_type', 'client_id', 'redirect_uri', 'scope', 'state', 'code_challenge', 'code_challenge_method', 'resource' );

    private static function validate( $params ) {
        if ( ! is_array( $params ) || array_diff( array_keys( $params ), self::PARAMETERS ) ) throw new \InvalidArgumentException();
        foreach ( $params as $value ) if ( ! is_string( $value ) || strlen( $value ) > 4096 ) throw new \InvalidArgumentException();
        $client = OAuthStore::client( $params['client_id'] ?? '' );
        if ( ! $client || ! $client['active'] || ( $params['redirect_uri'] ?? '' ) !== $client['redirect_uri'] || ! OAuth::valid_redirect( $client['redirect_uri'] ) ) throw new \InvalidArgumentException();
        if ( ( $params['response_type'] ?? '' ) !== 'code' || ( $params['code_challenge_method'] ?? '' ) !== 'S256' ||
            ! preg_match( '/^[A-Za-z0-9_-]{43}$/D', $params['code_challenge'] ?? '' ) ||
            ! preg_match( '/^[\x20-\x7e]{1,512}$/D', $params['state'] ?? '' ) ||
            ! in_array( $params['resource'] ?? '', array( OAuth::resource(), OAuth::resource( true ) ), true ) ) throw new \InvalidArgumentException();
        $publishing = $params['resource'] === OAuth::resource( true );
        $params['scope'] = $params['scope'] ?? ( $publishing ? 'sf:drafts sf:publish' : 'sf:drafts' );
        $scopes = OAuth::validate_scope( $params['scope'], $publishing, $client );
        return array( $params, $client, $scopes, $publishing );
    }

    public static function handle() {
        nocache_headers();
        header( 'Referrer-Policy: no-referrer' ); header( 'X-Content-Type-Options: nosniff' ); header( 'X-Frame-Options: DENY' );
        header( "Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'" );
        $lock = null; $transaction = false;
        try {
            if ( ! OAuth::ready() || ! Access::secure_transport() ) throw new \RuntimeException( __( 'Secure OAuth setup is unavailable.', 'atshift-deft' ) );
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Compared with exact GET/POST allowlist; never normalize an unsupported method into an allowed one.
            $method = is_string( $_SERVER['REQUEST_METHOD'] ?? null ) ? wp_unslash( $_SERVER['REQUEST_METHOD'] ) : '';
            if ( 'GET' === $method ) {
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Bounded form decoder rejects unknown/duplicate names; opaque OAuth values must not be rewritten by text sanitization.
                $params = OAuth::decode_form( is_string( $_SERVER['QUERY_STRING'] ?? null ) ? wp_unslash( $_SERVER['QUERY_STRING'] ) : '', array_merge( array( 'action' ), self::PARAMETERS ) );
                if ( ! in_array( $params['action'] ?? '', array( 'atshde_oauth_authorize', 'sf_oauth_authorize' ), true ) ) throw new \InvalidArgumentException();
                unset( $params['action'] );
                list( $params, $client, $scopes, $publishing ) = self::validate( $params );
                if ( ! is_user_logged_in() || ! wp_get_session_token() ) {
                    wp_safe_redirect( wp_login_url( add_query_arg( array_map( 'rawurlencode', $params ), OAuth::authorize_url() ) ) ); exit;
                }
                if ( ! Access::workspace() || ( $publishing && ! Access::publisher() ) ) throw new \InvalidArgumentException();
                $owner = get_current_user_id(); $lock = OAuthStore::lock( $owner ); global $wpdb;
                OAuthStore::cleanup();
                if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE owner_id=%d', OAuthStore::flows(), $owner ) ) >= 10 ) throw new \RuntimeException( __( 'Too many pending requests. Wait ten minutes and retry.', 'atshift-deft' ) );
                $flow = bin2hex( random_bytes( 32 ) );
                if ( false === $wpdb->insert( OAuthStore::flows(), array( 'id' => hash( 'sha256', $flow ), 'owner_id' => $owner,
                    'session_hash' => hash( 'sha256', wp_get_session_token() ), 'payload' => wp_json_encode( $params, JSON_THROW_ON_ERROR ), 'expires' => time() + 10 * MINUTE_IN_SECONDS ) ) ) throw new \RuntimeException();
                OAuthStore::unlock( $lock ); $lock = null;
                self::render( $flow, $client, $scopes, $publishing ); exit;
            }
            if ( 'POST' !== $method || ! is_user_logged_in() || ! wp_get_session_token() || ! Access::workspace() ) throw new \InvalidArgumentException();
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Exact MIME allowlist below; do not normalize an unsupported content type into a supported one.
            $content_type = is_string( $_SERVER['CONTENT_TYPE'] ?? null ) ? wp_unslash( $_SERVER['CONTENT_TYPE'] ) : '';
            if ( ! preg_match( '/^application\/x-www-form-urlencoded(?:\s*;|$)/i', $content_type ) ) throw new \InvalidArgumentException();
            $p = OAuth::decode_form( file_get_contents( 'php://input', false, null, 0, 32769 ), array( 'action', 'flow', '_wpnonce', 'decision' ) );
            if ( ! in_array( $p['action'] ?? '', array( 'atshde_oauth_authorize', 'sf_oauth_authorize' ), true ) || ! is_string( $p['flow'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $p['flow'] ) ||
                ! is_string( $p['_wpnonce'] ?? null ) || ! Access::verify_nonce( $p['_wpnonce'], 'sf-oauth-consent-' . $p['flow'] ) ||
                ! in_array( $p['decision'] ?? '', array( 'approve', 'deny' ), true ) ) throw new \InvalidArgumentException();
            $owner = get_current_user_id(); $lock = OAuthStore::lock( $owner ); global $wpdb;
            $flow = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%s AND owner_id=%d', OAuthStore::flows(), hash( 'sha256', $p['flow'] ), $owner ), ARRAY_A );
            if ( ! $flow || (int) $flow['expires'] <= time() || ! hash_equals( $flow['session_hash'], hash( 'sha256', wp_get_session_token() ) ) ) throw new \InvalidArgumentException();
            list( $params, $client, $scopes, $publishing ) = self::validate( json_decode( $flow['payload'], true, 8, JSON_THROW_ON_ERROR ) );
            if ( $publishing && ! Access::publisher() ) throw new \InvalidArgumentException();
            OAuthStore::begin(); $transaction = true;
            if ( 1 !== $wpdb->delete( OAuthStore::flows(), array( 'id' => $flow['id'], 'owner_id' => $owner ) ) ) throw new \RuntimeException();
            if ( 'deny' === $p['decision'] ) {
                OAuthStore::commit(); $transaction = false; OAuthStore::unlock( $lock ); $lock = null;
                self::redirect( $client['redirect_uri'], array( 'error' => 'access_denied', 'state' => $params['state'], 'iss' => OAuth::issuer() ) );
            }
            $connections = Connections::table(); $publications = Publications::table();
            $wpdb->query( $wpdb->prepare( "DELETE c FROM %i c WHERE c.owner_id=%d AND c.expires<=%d AND NOT EXISTS (SELECT 1 FROM %i p WHERE p.connection_id=c.id AND p.state IN ('scheduled','processing'))", $connections, $owner, time(), $publications ) );
            if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE owner_id=%d AND expires>%d', $connections, $owner, time() ) ) >= 10 ) throw new \RuntimeException( __( 'Revoke an existing connection first (maximum 10 active).', 'atshift-deft' ) );
            $grant = array( 'id' => wp_generate_uuid4(), 'owner_id' => $owner, 'name' => mb_substr( 'OAuth: ' . $client['name'], 0, 80 ),
                'secret_hash' => str_repeat( '0', 64 ), 'created' => time(), 'expires' => time() + 30 * DAY_IN_SECONDS,
                'authoring' => (int) in_array( 'sf:authoring', $scopes, true ), 'publishing' => (int) $publishing, 'auth_type' => 'oauth', 'oauth_client' => $client['id'] );
            if ( false === $wpdb->insert( $connections, $grant ) ) throw new \RuntimeException();
            $context = new OAuth\Context( $grant, $params['resource'], OAuth::issuer() );
            $server = OAuth::server( $context );
            $auth = $server->validateAuthorizationRequest( OAuth::psr_request( $params, 'GET' ) );
            // State is opaque. The grant parser trims strings; restore our already validated exact value.
            $auth->setState( $params['state'] );
            $user = new OAuth\User( (string) $owner );
            $auth->setUser( $user ); $auth->setAuthorizationApproved( true );
            $response = $server->completeAuthorizationRequest( $auth, new Vendor\Nyholm\Psr7\Response() );
            parse_str( wp_parse_url( $response->getHeaderLine( 'Location' ), PHP_URL_QUERY ) ?? '', $result );
            if ( empty( $result['code'] ) || ( $result['state'] ?? '' ) !== $params['state'] ) throw new \RuntimeException();
            OAuthStore::bind_credential( $context->issuedCodeId, 'code', $result['code'] );
            if ( ! OAuthStore::active_grant( $grant['id'] ) ) throw new \RuntimeException();
            OAuthStore::commit(); $transaction = false; OAuthStore::unlock( $lock ); $lock = null;
            self::redirect( $client['redirect_uri'], array( 'code' => $result['code'], 'state' => $params['state'], 'iss' => OAuth::issuer() ) );
        } catch ( \Throwable $e ) {
            if ( $transaction ) { OAuthStore::rollback(); $transaction = false; }
            OAuthStore::unlock( $lock ); $lock = null;
            wp_die( esc_html__( 'This authorization request is unavailable or invalid. Return to the AI connection settings and start again. No access was granted.', 'atshift-deft' ), esc_html__( 'atshift DeFt authorization', 'atshift-deft' ), array( 'response' => 400 ) );
        } finally { if ( $transaction ) OAuthStore::rollback(); OAuthStore::unlock( $lock ); }
    }

    private static function redirect( $uri, $params ) {
        // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Exact administrator-registered OAuth callback was validated before login and again before consent; external redirect is the OAuth protocol response.
        wp_redirect( add_query_arg( array_map( 'rawurlencode', $params ), $uri ), 302, 'atshift DeFt OAuth' ); exit;
    }

    private static function render( $flow, $client, $scopes, $publishing ) {
        wp_enqueue_style( 'atshift-deft-oauth-install', admin_url( 'css/install.css' ), array(), get_bloginfo( 'version' ) );
        wp_enqueue_style( 'atshift-deft-oauth-buttons', includes_url( 'css/buttons.css' ), array(), get_bloginfo( 'version' ) );
        wp_enqueue_style( 'atshift-deft-oauth-consent', plugins_url( 'oauth.css', dirname( __DIR__ ) . '/atshift-deft.php' ), array( 'atshift-deft-oauth-install', 'atshift-deft-oauth-buttons' ), VERSION );
        $labels = array(
            'sf:drafts' => __( 'Read components and read/write my private drafts', 'atshift-deft' ),
            'sf:authoring' => __( 'Create bounded structures, transfer my files and export private previews', 'atshift-deft' ),
            'sf:publish' => __( 'Publish or schedule my articles without another WordPress approval', 'atshift-deft' ),
        );
        ?>
        <!doctype html><html lang="<?php echo esc_attr( str_replace( '_', '-', determine_locale() ) ); ?>" dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
        <title><?php esc_html_e( 'Authorize AI connection', 'atshift-deft' ); ?></title>
        <?php wp_print_styles( array( 'atshift-deft-oauth-consent' ) ); ?></head><body class="wp-core-ui sf-oauth-consent">
        <h1><?php esc_html_e( 'Authorize AI connection', 'atshift-deft' ); ?></h1>
        <p><strong><?php echo esc_html( $client['name'] ); ?></strong></p>
        <p><?php /* translators: %s: WordPress account display name. */ echo esc_html( sprintf( __( 'WordPress account: %s', 'atshift-deft' ), wp_get_current_user()->display_name ) ); ?></p>
        <ul><?php foreach ( $scopes as $scope ) : ?><li><?php echo esc_html( $labels[$scope] ); ?></li><?php endforeach; ?></ul>
        <p><?php esc_html_e( 'Consent lasts 30 days and can be revoked in AI connections. Other staff members\' private data is not included.', 'atshift-deft' ); ?></p>
        <?php if ( $publishing ) : ?><p><strong><?php esc_html_e( 'Revoking this connection cancels pending reservations. Access-token expiry alone does not cancel them.', 'atshift-deft' ); ?></strong></p><?php endif; ?>
        <details><summary><?php esc_html_e( 'Connection details', 'atshift-deft' ); ?></summary><p><?php echo esc_html( $client['redirect_uri'] ); ?></p><p><?php echo esc_html( OAuth::resource( $publishing ) ); ?></p></details>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="atshde_oauth_authorize"><input type="hidden" name="flow" value="<?php echo esc_attr( $flow ); ?>">
            <?php wp_nonce_field( 'sf-oauth-consent-' . $flow, '_wpnonce', false ); ?>
            <p><button class="button button-primary" type="submit" name="decision" value="approve"><?php esc_html_e( 'Allow this access', 'atshift-deft' ); ?></button>
            <button class="button" type="submit" name="decision" value="deny"><?php esc_html_e( 'Deny', 'atshift-deft' ); ?></button></p>
        </form></body></html>
        <?php
    }
}
