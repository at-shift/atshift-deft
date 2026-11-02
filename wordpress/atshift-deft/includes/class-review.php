<?php
namespace Atshift\Deft;

defined( 'ABSPATH' ) || exit;

final class Review {
    public static function table() { global $wpdb; return $wpdb->prefix . 'atshde_reviews'; }
    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        dbDelta( "CREATE TABLE $table (
            review_id char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            article_id varchar(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            version bigint(20) unsigned NOT NULL,
            manifest_hash char(64) NOT NULL,
            manifest longtext NOT NULL,
            expires bigint(20) unsigned NOT NULL,
            decision varchar(16) NOT NULL DEFAULT 'pending',
            approver bigint(20) unsigned NOT NULL DEFAULT 0,
            decided_at bigint(20) unsigned NOT NULL DEFAULT 0,
            revoked_by bigint(20) unsigned NOT NULL DEFAULT 0,
            revoked_at bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (review_id),
            KEY article_id (article_id)
        ) ENGINE=InnoDB {$wpdb->get_charset_collate()};" );
        $columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) );
        if ( in_array( 'revoked_by', $columns, true ) && in_array( 'revoked_at', $columns, true ) ) update_option( 'atshde_reviews_schema_version', 2, false );
    }

    public static function permission() {
        return Transfer::secure_transport() && Access::reviewer() ? true : new \WP_Error( 'sf_forbidden', __( 'Secure transport and draft review access are required.', 'atshift-deft' ), array( 'status' => 403 ) );
    }

    public static function manifest( $row ) {
        $snapshot = json_decode( $row['payload'] );
        // Approval covers pinned content media, not mutable theme files or linked websites.
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
        if ( ! in_array( $snapshot->schemaVersion ?? null, array( 2, 3 ), true ) ) throw new \RuntimeException( __( 'Use a v2 or v3 snapshot for review.', 'atshift-deft' ) );
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
        if ( preg_match( '/url\s*\(|image(?:-set)?\s*\(|\\\\/i', $row['css'] ) ) throw new \RuntimeException( __( 'Move CSS image resources into pinned image fields before review.', 'atshift-deft' ) );
        $dom = new \DOMDocument(); $previous = libxml_use_internal_errors( true );
        $dom->loadHTML( '<?xml encoding="UTF-8"><html><body>' . $row['rendered'] . '</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
        libxml_clear_errors(); libxml_use_internal_errors( $previous );
        foreach ( $dom->getElementsByTagName( 'img' ) as $image ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; REST JSON-encodes messages and HTML callers escape at output.
            if ( ! $image->hasAttribute( 'data-sf-media' ) && ( $image->hasAttribute( 'src' ) || ! $image->hasAttribute( 'hidden' ) ) ) throw new \RuntimeException( __( 'Use pinned references for every article image before review.', 'atshift-deft' ) );
        }
        $files = array(); $preview = Media::preview( $row );
        foreach ( $preview['references'] as $key => $usage ) {
            $meta = $preview['media'][$key];
            $files[] = array( 'fieldIdentity' => $key, 'source' => $usage['ref'], 'filename' => $meta['filename'], 'mime' => $meta['mime'], 'bytes' => (int) $meta['bytes'] );
        }
        $manifest = array( 'articleId' => $row['article_id'], 'version' => (int) $row['version'], 'revision' => $snapshot->article->revision,
            'title' => $snapshot->article->title, 'digest' => $row['digest'], 'scope' => 'article-and-managed-assets', 'files' => $files );
        if ( 3 === $snapshot->schemaVersion ) {
            $manifest['scope'] = 'article-managed-assets-and-behaviors';
            $manifest['behaviorPolicyVersion'] = Behaviors::state()['version'];
            $manifest['behaviorWarnings'] = Behaviors::warnings( $snapshot );
        }
        return $manifest;
    }

    public static function read( $id ) {
        global $wpdb; $table = self::table();
        return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE review_id=%s', $table, $id ), ARRAY_A );
    }

    public static function state( $review ) {
        if ( ! Access::article( $review['article_id'] ) ) return Access::denied();
        $state = $review['decision'];
        try {
            $row = Store::read( $review['article_id'] );
            if ( ! $row || (int) $row['version'] !== (int) $review['version'] ||
                ! hash_equals( $review['manifest_hash'], hash( 'sha256', Snapshot::json( self::manifest( $row ) ) ) ) ) $state = 'invalidated';
        } catch ( \Throwable $e ) { $state = 'invalidated'; }
        if ( 'approved' === $state && ( ! Access::article( $review['article_id'], (int) $review['approver'] ) ||
            ! Access::reviewer( (int) $review['approver'] ) ) ) $state = 'invalidated';
        if ( 'pending' === $state && (int) $review['expires'] <= time() ) $state = 'expired';
        return array( 'reviewId' => $review['review_id'], 'state' => $state, 'manifest' => json_decode( $review['manifest'] ),
            'manifestHash' => $review['manifest_hash'], 'expiresAt' => gmdate( 'c', (int) $review['expires'] ),
            'approvedBy' => (int) $review['approver'], 'decidedAt' => (int) $review['decided_at'],
            'withdrawnBy' => (int) $review['revoked_by'], 'withdrawnAt' => (int) $review['revoked_at'],
            'approvalMethod' => 'wordpress-session', 'humanPresenceVerified' => false, 'publishAuthorized' => false,
            'reviewUrl' => add_query_arg( array( 'page' => 'structured-fields-review', 'review' => $review['review_id'] ), admin_url( 'admin.php' ) ) );
    }

    public static function create( $request ) {
        global $wpdb;
        if ( true !== self::permission() || ! Access::article( $request['id'] ) ) return Access::denied();
        if ( ! $request->is_json_content_type() || strlen( $request->get_body() ) > 1024 ) return new \WP_Error( 'sf_input', __( 'Use JSON expectedVersion.', 'atshift-deft' ), array( 'status' => 400 ) );
        $b = $request->get_json_params();
        if ( ! is_array( $b ) || array_keys( $b ) !== array( 'expectedVersion' ) || ! is_int( $b['expectedVersion'] ) ) return new \WP_Error( 'sf_input', __( 'Use integer expectedVersion only.', 'atshift-deft' ), array( 'status' => 400 ) );
        $lock = Store::lock_name( $request['id'] );
        if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,3)', $lock ) ) ) return new \WP_Error( 'sf_busy', __( 'Retry later.', 'atshift-deft' ), array( 'status' => 409 ) );
        try {
            if ( ! Access::article( $request['id'] ) ) return Access::denied();
            $row = Store::read( $request['id'] );
            if ( ! $row || (int) $row['version'] !== $b['expectedVersion'] ) return new \WP_Error( 'sf_conflict', __( 'The article changed.', 'atshift-deft' ), array( 'status' => 409 ) );
            $manifest = Snapshot::json( self::manifest( $row ) ); $hash = hash( 'sha256', $manifest ); $table = self::table();
            $existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE article_id=%s AND version=%d AND manifest_hash=%s AND (decision='approved' OR (decision='pending' AND expires>%d)) ORDER BY expires DESC LIMIT 1", $table, $request['id'], $row['version'], $hash, time() ), ARRAY_A );
            if ( $existing ) return self::state( $existing );
            if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE article_id=%s', $table, $request['id'] ) ) >= 100 ) return new \WP_Error( 'sf_limit', __( 'Review history limit reached.', 'atshift-deft' ), array( 'status' => 429 ) );
            $id = wp_generate_uuid4();
            if ( false === $wpdb->insert( $table, array( 'review_id' => $id, 'article_id' => $request['id'], 'version' => $row['version'],
                'manifest_hash' => $hash, 'manifest' => $manifest, 'expires' => time() + 30 * MINUTE_IN_SECONDS ) ) ) throw new \RuntimeException( __( 'Could not store review request.', 'atshift-deft' ) );
            return self::state( self::read( $id ) );
        } catch ( \Throwable $e ) { return new \WP_Error( 'sf_review', $e->getMessage(), array( 'status' => 400 ) ); }
        finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
    }

    public static function decide( $id, $decision, $nonce ) {
        global $wpdb;
        $review = is_string( $id ) && preg_match( '/^[a-f0-9-]{36}$/D', $id ) ? self::read( $id ) : null;
        $cookie_user = wp_validate_auth_cookie( '', 'logged_in' );
        if ( true !== self::permission() || ! $cookie_user || (int) $cookie_user !== get_current_user_id() ||
            ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) || ! empty( $_SERVER['PHP_AUTH_USER'] ) ||
            ! $review || ! Access::article( $review['article_id'] ) || ! is_string( $nonce ) || ! in_array( $decision, array( 'approved', 'rejected', 'revoked' ), true ) ||
            ! Access::verify_nonce( $nonce, 'sf-review-' . $id . '-' . $review['manifest_hash'] ) ) return new \WP_Error( 'sf_review_denied', __( 'Use the authenticated review screen.', 'atshift-deft' ), array( 'status' => 403 ) );
        $lock = Store::lock_name( $review['article_id'] );
        if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,3)', $lock ) ) ) return new \WP_Error( 'sf_busy', __( 'Retry later.', 'atshift-deft' ), array( 'status' => 409 ) );
        try {
            $review = self::read( $id ); $state = self::state( $review );
            if ( is_wp_error( $state ) ) return $state;
            $actor = 'revoked' === $decision ? $review['revoked_by'] : $review['approver'];
            if ( $state['state'] === $decision && (int) $actor === get_current_user_id() ) return $state;
            $from = 'revoked' === $decision ? 'approved' : 'pending';
            if ( $from !== $state['state'] ) return new \WP_Error( 'sf_review_stale', __( 'Review is no longer available for this exact content.', 'atshift-deft' ), array( 'status' => 409 ) );
            $changes = 'revoked' === $decision ? array( 'revoked_by' => get_current_user_id(), 'revoked_at' => time() ) : array( 'approver' => get_current_user_id(), 'decided_at' => time() );
            $changes['decision'] = $decision;
            if ( false === $wpdb->update( self::table(), $changes, array( 'review_id' => $id, 'decision' => $from ) ) ) return new \WP_Error( 'sf_storage', __( 'Could not record decision.', 'atshift-deft' ), array( 'status' => 503 ) );
            return self::state( self::read( $id ) );
        } finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
    }

    public static function submit() {
        if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) wp_die( esc_html__( 'POST required.', 'atshift-deft' ), esc_html__( 'Review', 'atshift-deft' ), array( 'response' => 405 ) );
        $id = is_string( $_POST['review'] ?? null ) ? wp_unslash( $_POST['review'] ) : '';
        $decision = is_string( $_POST['decision'] ?? null ) ? wp_unslash( $_POST['decision'] ) : '';
        $nonce = is_string( $_POST['_wpnonce'] ?? null ) ? wp_unslash( $_POST['_wpnonce'] ) : '';
        // decide() verifies the exact review nonce, cookie session and owner before mutation.
        $result = self::decide( $id, $decision, $nonce );
        if ( is_wp_error( $result ) ) wp_die( esc_html( $result->get_error_message() ), 'Review', array( 'response' => absint( $result->get_error_data()['status'] ) ) );
        wp_safe_redirect( $result['reviewUrl'] ); exit;
    }

    public static function authorize_page() {
        $id = is_string( $_GET['review'] ?? null ) ? wp_unslash( $_GET['review'] ) : '';
        // This read-only page checks owner/capability; its form has a separate decision nonce.
        $row = preg_match( '/^[a-f0-9-]{36}$/D', $id ) ? self::read( $id ) : null;
        if ( true !== self::permission() || ! $row || ! Access::article( $row['article_id'] ) ) wp_die( esc_html__( 'Review unavailable.', 'atshift-deft' ), esc_html__( 'Review', 'atshift-deft' ), array( 'response' => 403 ) );
        return $row;
    }

    public static function page() {
        $row = self::authorize_page(); $id = $row['review_id'];
        $state = self::state( $row ); $manifest = $state['manifest'];
        $states = array( 'pending' => __( 'Pending', 'atshift-deft' ), 'approved' => __( 'Approved', 'atshift-deft' ),
            'rejected' => __( 'Rejected', 'atshift-deft' ), 'revoked' => __( 'Revoked', 'atshift-deft' ),
            'invalidated' => __( 'Invalidated', 'atshift-deft' ), 'expired' => __( 'Expired', 'atshift-deft' ) );
        echo '<div class="wrap" id="sf-review-screen"><a href="' . esc_url( admin_url( 'admin.php?page=structured-fields' ) ) . '">' . esc_html__( 'Articles', 'atshift-deft' ) . '</a><h1>' . esc_html( $manifest->title ) . '</h1><p id="sf-review-state">' . esc_html( $states[$state['state']] ?? $state['state'] ) . ' · ' . esc_html__( 'Not published', 'atshift-deft' ) . '</p>';
        /* translators: 1: saved version number, 2: article revision number. */
        echo '<p>' . esc_html( sprintf( __( 'Version %1$s · Revision %2$s', 'atshift-deft' ), $manifest->version, $manifest->revision ) ) . '</p><code style="overflow-wrap:anywhere">' . esc_html( $row['manifest_hash'] ) . '</code><ul>';
        foreach ( $manifest->files as $file ) {
            /* translators: 1: filename, 2: file size in bytes, 3: media source type. */
            echo '<li>' . esc_html( sprintf( __( '%1$s · %2$s bytes · %3$s', 'atshift-deft' ), $file->filename, $file->bytes, $file->source->kind ) ) . '<br><code style="overflow-wrap:anywhere">' . esc_html( $file->source->sha256 ) . '</code></li>';
        }
        echo '</ul>';
        if ( in_array( $state['state'], array( 'pending', 'approved', 'rejected' ), true ) ) {
            echo '<iframe title="' . esc_attr__( 'Exact version preview', 'atshift-deft' ) . '" src="' . esc_url( Store::preview_url( Store::read( $row['article_id'], (int) $row['version'] ) ) ) . '" style="width:100%;height:65vh;border:1px solid #c3c4c7"></iframe>';
        }
        if ( in_array( $state['state'], array( 'pending', 'approved' ), true ) ) {
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:flex;gap:12px;flex-wrap:wrap;margin-top:16px"><input type="hidden" name="action" value="atshde_decide"><input type="hidden" name="review" value="' . esc_attr( $id ) . '">';
            wp_nonce_field( 'sf-review-' . $id . '-' . $row['manifest_hash'] );
            if ( 'pending' === $state['state'] ) echo '<button class="button button-primary" name="decision" value="approved">' . esc_html__( 'Approve this version', 'atshift-deft' ) . '</button><button class="button" name="decision" value="rejected">' . esc_html__( 'Request changes', 'atshift-deft' ) . '</button>';
            else echo '<button class="button" name="decision" value="revoked">' . esc_html__( 'Withdraw approval', 'atshift-deft' ) . '</button>';
            echo '</form>';
        }
        echo '</div>';
    }
}
