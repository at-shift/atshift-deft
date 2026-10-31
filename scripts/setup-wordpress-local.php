<?php
// Setup only the isolated, git-ignored local WordPress. Never reset an existing account.
define( 'WP_INSTALLING', true );
require dirname( __DIR__ ) . '/.local/wordpress/wp-load.php';
if ( DB_NAME !== 'sf_prototype_local' || WP_HOME !== 'http://127.0.0.1:8128' ) throw new RuntimeException( 'Unexpected environment.' );
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
if ( ! is_blog_installed() ) {
    $password = wp_generate_password( 32, false );
    $installed = wp_install( 'atshift DEFT Local', 'sf_local_admin', 'sf-local@example.invalid', false, '', $password );
    if ( is_wp_error( $installed ) ) throw new RuntimeException( $installed->get_error_message() );
    $path = dirname( __DIR__ ) . '/.local/wp-credentials.json';
    file_put_contents( $path, json_encode( array( 'username' => 'sf_local_admin', 'password' => $password ) ) );
    chmod( $path, 0600 );
}
if ( is_plugin_active( 'structured-fields/structured-fields.php' ) ) {
    throw new RuntimeException( 'Deactivate the legacy Structured Fields plugin in a separate request before activating atshift DEFT. Do not delete saved data.' );
}
$result = activate_plugin( 'atshift-deft/atshift-deft.php' );
if ( is_wp_error( $result ) ) throw new RuntimeException( $result->get_error_message() );
echo "Isolated WordPress ready at http://127.0.0.1:8128/wp-admin/admin.php?page=structured-fields\n";
