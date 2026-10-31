<?php
namespace StructuredFields;

defined( 'ABSPATH' ) || exit;

function runtime_supported( $version_id ) {
    return $version_id >= 80200;
}

function runtime_notice() {
    if ( ! current_user_can( 'activate_plugins' ) && ! current_user_can( 'manage_network_plugins' ) ) return;
    /* translators: %s: current PHP version. */
    $message = sprintf( __( 'atshift DEFT requires PHP 8.2 or newer. This site runs PHP %s. The plugin is not running until PHP is upgraded. Saved data is unchanged.', 'atshift-deft' ), PHP_VERSION );
    echo '<div class="notice notice-error" role="alert"><p>' . esc_html( $message ) . '</p></div>';
}

function runtime_guard( $version_id ) {
    if ( runtime_supported( $version_id ) ) return true;
    add_action( 'admin_notices', __NAMESPACE__ . '\\runtime_notice' );
    add_action( 'network_admin_notices', __NAMESPACE__ . '\\runtime_notice' );
    return false;
}
