<?php
require __DIR__ . '/wordpress-media-fixture.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

$admin = get_current_user_id(); $users = array(); $checks = 0;
function sfn_notice_check( $ok, $message ) {
    global $checks;
    if ( ! $ok ) throw new RuntimeException( $message );
    echo 'PASS ' . ++$checks . ': ' . $message . PHP_EOL;
}
function sfn_notice_output( $callback ) { ob_start(); $callback(); return ob_get_clean(); }
try {
    foreach ( array( 'administrator', 'atshde_author', 'atshde_designer', 'atshde_publisher', 'subscriber' ) as $role ) {
        $id = wp_insert_user( array( 'user_login' => 'notice-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password( 32 ), 'role' => $role ) );
        if ( is_wp_error( $id ) ) throw new RuntimeException( $id->get_error_message() );
        $users[] = $id; wp_set_current_user( $id );
        foreach ( array( 'Atshift\Deft\\runtime_notice', 'Atshift\Deft\\legacy_notice' ) as $callback ) {
            $output = sfn_notice_output( $callback );
            sfn_notice_check( ( 'administrator' === $role ) === str_contains( $output, 'role="alert"' ), $callback . ' is restricted to a role able to resolve it: ' . $role );
        }
        if ( 'subscriber' === $role ) {
            sfn_notice_check( '' === sfn_notice_output( 'Atshift\Deft\\admin_page' ), 'Subscriber cannot receive the introductory dialog.' );
            continue;
        }
        $key = '_atshde_ai_welcome_seen_' . get_current_blog_id();
        foreach ( array( 'Atshift\Deft\\catalog_page', 'Atshift\Deft\\connections_page' ) as $callback ) {
            $output = sfn_notice_output( $callback );
            sfn_notice_check( ! str_contains( $output, 'id="sf-ai-welcome"' ) && ! metadata_exists( 'user', $id, $key ), $callback . ' neither displays nor consumes the introduction: ' . $role );
        }
        $first = sfn_notice_output( 'Atshift\Deft\\admin_page' );
        sfn_notice_check( str_contains( $first, '<dialog id="sf-ai-welcome"' ) && str_contains( $first, 'id="sf-ai-welcome-close"' ), 'First article visit has a close control: ' . $role );
        $seen = get_user_meta( $id, $key, true );
        sfn_notice_check( ctype_digit( (string) $seen ), 'First-visit state is recorded per user/site: ' . $role );
        $second = sfn_notice_output( 'Atshift\Deft\\admin_page' );
        sfn_notice_check( ! str_contains( $second, 'id="sf-ai-welcome"' ) && str_contains( $second, 'id="sf-ai-guide"' ) && $seen === get_user_meta( $id, $key, true ), 'Revisit omits the dialog, preserves download and does not rewrite state: ' . $role );
    }
    wp_set_current_user( 0 );
    sfn_notice_check( '' === sfn_notice_output( 'Atshift\Deft\\runtime_notice' ) && '' === sfn_notice_output( 'Atshift\Deft\\legacy_notice' ), 'Anonymous visitors see neither operational notice.' );
    sfn_notice_check( false === has_action( 'admin_notices', 'Atshift\Deft\\runtime_notice' ) && false === has_action( 'admin_notices', 'Atshift\Deft\\legacy_notice' ), 'Supported PHP without the legacy plugin registers neither site-wide notice.' );
    echo 'WordPress notices: ' . $checks . ' passed.' . PHP_EOL;
} finally {
    wp_set_current_user( $admin );
    foreach ( $users as $id ) wp_delete_user( $id );
}
