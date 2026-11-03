<?php
/**
 * Plugin Name: atshift DeFt
 * Description: Delegate execution. Focus on thinking. Create structured articles with your preferred AI.
 * Version: 0.7.4
 * Author: at-shift
 * Author URI: https://plugins.at-shift.net/
 * Requires at least: 6.9
 * Requires PHP: 8.2
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: atshift-deft
 * Domain Path: /languages
 */

namespace Atshift\Deft;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/i18n.php';
\Atshift\Deft\register_translations( __FILE__ );
require_once __DIR__ . '/includes/legacy-guard.php';
if ( ! \Atshift\Deft\legacy_guard( __FILE__ ) ) return;
require_once __DIR__ . '/includes/runtime.php';
if ( ! runtime_guard( PHP_VERSION_ID ) ) return;

const VERSION = '0.7.4';
const PLUGIN_FILE = __FILE__;
require_once __DIR__ . '/includes/class-prefix-migration.php';
function boot() {
    if ( ! PrefixMigration::run() ) {
        add_action( 'admin_notices', static function() {
            if ( ! current_user_can( 'manage_options' ) ) return;
            echo '<div class="notice notice-error"><p>' . esc_html__( 'atshift DeFt could not migrate its legacy data. Its tools are disabled to avoid mixing data. Keep your database backup and check for conflicting legacy/new DeFt tables or insufficient database permissions, then reload.', 'atshift-deft' ) . '</p></div>';
        } );
        return;
    }
    require_once __DIR__ . '/includes/plugin.php';
}
if ( did_action( 'plugins_loaded' ) ) boot();
else add_action( 'plugins_loaded', __NAMESPACE__ . '\\boot', 0 );
