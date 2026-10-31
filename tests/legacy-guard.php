<?php
define( 'ABSPATH', __DIR__ . '/' );
$active = array(); $network_active = array(); $multisite = false; $locale = 'en_US'; $allowed = true; $hooks = array(); $activation = null;
require __DIR__ . '/php-gettext-fixture.php';
function did_action( $hook ) { return 0; }
function get_option( $key, $default ) { global $active; return $active; }
function is_multisite() { global $multisite; return $multisite; }
function get_site_option( $key, $default ) { global $network_active; return $network_active; }
function get_user_locale() { global $locale; return $locale; }
function current_user_can( $cap ) { global $allowed; return $allowed; }
function esc_html( $value ) { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
function add_action( $hook, $callback ) { global $hooks; $hooks[$hook] = $callback; }
function register_activation_hook( $file, $callback ) { global $activation; $activation = $callback; }
function wp_die( $message, $title, $args ) { throw new RuntimeException( $message, $args['response'] ); }
require __DIR__ . '/../wordpress/atshift-deft/includes/legacy-guard.php';
$checks = 0;
function check_legacy( $pass ) { global $checks; if ( ! $pass ) throw new RuntimeException( 'Legacy guard failed: ' . ( $checks + 1 ) ); $checks++; }
check_legacy( AtshiftDeft\legacy_guard( __FILE__ ) && ! $hooks );
$active = array( 'structured-fields/structured-fields.php' );
check_legacy( ! AtshiftDeft\legacy_guard( __FILE__ ) );
check_legacy( isset( $hooks['admin_notices'], $hooks['network_admin_notices'] ) );
try { $activation(); check_legacy( false ); } catch ( RuntimeException $e ) { check_legacy( 409 === $e->getCode() ); }
ob_start(); AtshiftDeft\legacy_notice(); $notice = ob_get_clean();
check_legacy( str_contains( $notice, 'atshift DEFT' ) && str_contains( $notice, 'role="alert"' ) );
$locale = 'ja'; check_legacy( str_contains( AtshiftDeft\legacy_message(), '保存済みデータはそのまま引き継ぎます' ) );
$allowed = false; ob_start(); AtshiftDeft\legacy_notice(); check_legacy( '' === ob_get_clean() );
$active = array(); $multisite = true; $network_active = array( 'structured-fields/structured-fields.php' => time() );
check_legacy( AtshiftDeft\legacy_active() );
$network_active = array(); check_legacy( ! AtshiftDeft\legacy_active() );
define( 'StructuredFields\\VERSION', '0.1.0-alpha.14' ); check_legacy( AtshiftDeft\legacy_active() );
$bootstrap = file_get_contents( __DIR__ . '/../wordpress/atshift-deft/atshift-deft.php' );
check_legacy( strpos( $bootstrap, 'legacy_guard( __FILE__ )' ) < strpos( $bootstrap, '/includes/runtime.php' ) );
// Including DEFT after the old runtime must not declare any shared implementation.
require __DIR__ . '/../wordpress/atshift-deft/atshift-deft.php';
check_legacy( ! function_exists( 'StructuredFields\\permission' ) && ! class_exists( 'StructuredFields\\Store', false ) );
echo 'Legacy activation guard: ' . $checks . ' passed.', PHP_EOL;
