<?php
/**
 * Plugin Name: atshift DEFT
 * Description: Delegate execution. Focus on thinking. Create structured articles with your preferred AI.
 * Version: 0.5.1
 * Requires at least: 6.9
 * Requires PHP: 8.2
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: atshift-deft
 * Domain Path: /languages
 */

namespace StructuredFields;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/i18n.php';
\AtshiftDeft\register_translations( __FILE__ );
require_once __DIR__ . '/includes/legacy-guard.php';
if ( ! \AtshiftDeft\legacy_guard( __FILE__ ) ) return;
require_once __DIR__ . '/includes/runtime.php';
if ( ! runtime_guard( PHP_VERSION_ID ) ) return;

const VERSION = '0.5.1';
const PLUGIN_FILE = __FILE__;
require_once __DIR__ . '/includes/plugin.php';
