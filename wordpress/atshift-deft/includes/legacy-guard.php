<?php
namespace AtshiftDeft;

defined( 'ABSPATH' ) || exit;

function legacy_active() {
    $legacy = 'structured-fields/structured-fields.php';
    return in_array( $legacy, (array) get_option( 'active_plugins', array() ), true ) ||
        ( is_multisite() && isset( get_site_option( 'active_sitewide_plugins', array() )[ $legacy ] ) ) ||
        defined( 'StructuredFields\\VERSION' );
}

function legacy_message() {
    return __( 'Deactivate Structured Fields Prototype before activating atshift DEFT. Saved data will be retained. You do not need to delete the old plugin.', 'atshift-deft' );
}

function legacy_notice() {
    if ( ! current_user_can( 'activate_plugins' ) && ! current_user_can( 'manage_network_plugins' ) ) return;
    echo '<div class="notice notice-error" role="alert"><p>' . esc_html( legacy_message() ) . '</p></div>';
}

function legacy_guard( $file ) {
    if ( ! legacy_active() ) return true;
    // Keep the old runtime in charge regardless of active-plugin load order.
    add_action( 'admin_notices', __NAMESPACE__ . '\\legacy_notice' );
    add_action( 'network_admin_notices', __NAMESPACE__ . '\\legacy_notice' );
    register_activation_hook( $file, static function() {
        wp_die( esc_html( legacy_message() ), 'atshift DEFT', array( 'response' => 409, 'back_link' => true ) );
    } );
    return false;
}
