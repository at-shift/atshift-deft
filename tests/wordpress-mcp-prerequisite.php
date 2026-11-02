<?php
// WP-CLI only: verify the installed release in a disposable database.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! preg_match( '/^sf_deft_(?:post_types_[a-f0-9]{8}|prefix_[a-f0-9]{10})$/D', DB_NAME ) ) {
    throw new RuntimeException( 'Use a disposable local release-test database.' );
}
require_once ABSPATH . 'wp-admin/includes/user.php';
use Atshift\Deft\Onboarding;
use Atshift\Deft\Connections;

$GLOBALS['deft_mp_checks'] = 0;
function deft_mp_check( $value, $message ) {
    if ( ! $value ) throw new RuntimeException( $message );
    ++$GLOBALS['deft_mp_checks'];
}
function deft_mp_render() {
    ob_start(); Atshift\Deft\connections_page(); return ob_get_clean();
}
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]->ID;
$author = wp_insert_user( array( 'user_login' => 'deft-prerequisite-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password( 32 ), 'role' => 'atshde_author' ) );
if ( is_wp_error( $author ) ) throw new RuntimeException( $author->get_error_message() );
$locale = 'en_US';
$filter = static function() use ( &$locale ) { return $locale; };
add_filter( 'pre_determine_locale', $filter );
try {
    wp_set_current_user( 0 );
    deft_mp_check( '' === deft_mp_render(), 'Anonymous visitors cannot access the connection page.' );
    try { Onboarding::document(); $denied = false; } catch ( Throwable $e ) { $denied = true; }
    deft_mp_check( $denied, 'Anonymous visitors cannot download the guide.' );
    foreach ( array( $admin, $author ) as $owner ) {
        wp_set_current_user( $owner );
        unload_textdomain( 'atshift-deft', true );
        $html = deft_mp_render();
        deft_mp_check( str_contains( $html, 'Before connecting: install MCP Adapter' ), 'Installed page contains the prerequisite heading.' );
        deft_mp_check( strpos( $html, 'id="sf-mcp-prerequisite"' ) < strpos( $html, 'id="sf-connection-client"' ), 'Installation is presented before client selection.' );
        deft_mp_check( str_contains( $html, 'https://wordpress.org/plugins/mcp-adapter/' ) && str_contains( $html, 'MCP Adapter 0.7.1 or newer' ), 'Official link and minimum version are present.' );
        deft_mp_check( current_user_can( 'install_plugins' ) === str_contains( $html, 'plugin-install.php?tab=search' ), 'Plugin search link respects the current account capability.' );
        deft_mp_check( str_contains( $html, 'ask your site administrator' ), 'Users have a path to administrator assistance.' );
        $guide = Onboarding::document();
        deft_mp_check( str_contains( $guide, '## Before Connecting: Install MCP Adapter' ) && str_contains( $guide, 'Install Now > Activate' ), 'Actual downloaded guide includes installation and activation.' );
        deft_mp_check( strpos( $guide, '## Before Connecting: Install MCP Adapter' ) < strpos( $guide, '## Connection Details' ), 'Generated prerequisite precedes connection details.' );
        deft_mp_check( str_contains( $guide, 'https://wordpress.org/plugins/mcp-adapter/' ) && str_contains( $guide, 'Manual JSON import does not require it' ), 'Guide links the official dependency and preserves manual use.' );
        deft_mp_check( str_contains( $guide, 'Do not install, update or activate plugins without explicit approval' ) && str_contains( $guide, 'ask for confirmation from the administrator' ), 'Guide does not assume installation authority or live verification.' );
        deft_mp_check( str_contains( $guide, 'Skip this prerequisite when a successful current-session live context response' ), 'Existing verified connections skip setup.' );
        deft_mp_check( str_contains( $guide, '"pluginVersion": "' . Atshift\Deft\VERSION . '"' ), 'Site contract reports the installed release.' );
    }
    $locale = 'ja';
    unload_textdomain( 'atshift-deft', true );
    $html = deft_mp_render();
    if ( 'wordpress-org' !== getenv( 'SF_DISTRIBUTION' ) ) {
    deft_mp_check( str_contains( $html, 'AI接続の前に：MCP Adapterをインストール' ), 'Bundled Japanese MO translates the new heading.' );
    deft_mp_check( str_contains( $html, '作者：WordPress.org' ) && str_contains( $html, 'サイト管理者へこの操作を依頼' ), 'Japanese instructions retain author and permission guidance.' );
    } else {
        deft_mp_check( str_contains( $html, 'Before connecting: install MCP Adapter' ), 'English-only prerequisite heading remains available in a Japanese account.' );
        deft_mp_check( str_contains( $html, 'WordPress.org' ) && str_contains( $html, 'ask your site administrator' ), 'English-only prerequisite retains author and permission guidance.' );
    }
    deft_mp_check( Connections::ready() === ( '1' === getenv( 'SF_EXPECT_MCP_READY' ) ), 'Guide generation works with the expected adapter activation state.' );
    echo 'MCP prerequisite: ' . $GLOBALS['deft_mp_checks'] . ' passed; adapter ready=' . ( Connections::ready() ? 'yes' : 'no' ) . PHP_EOL;
} finally {
    remove_filter( 'pre_determine_locale', $filter );
    unload_textdomain( 'atshift-deft', true );
    wp_set_current_user( $admin );
    wp_delete_user( $author );
}
