<?php
define( 'ABSPATH', __DIR__ . '/' );
$hooks = array(); $locale = 'en_US'; $allowed = true;
require __DIR__ . '/php-gettext-fixture.php';
function add_action( $name, $callback ) { global $hooks; $hooks[$name][] = $callback; }
function current_user_can( $cap ) { global $allowed; return $allowed; }
function get_user_locale() { global $locale; return $locale; }
function esc_html( $text ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
require __DIR__ . '/../wordpress/atshift-deft/includes/runtime.php';
$checks = 0;
function runtime_check( $value ) { global $checks; if ( ! $value ) throw new RuntimeException( 'Runtime guard check failed: ' . ( $checks + 1 ) ); $checks++; }
foreach ( array( 70400, 80000, 80100, 80199 ) as $version ) runtime_check( ! Atshift\Deft\runtime_supported( $version ) );
foreach ( array( 80200, 80230, 80300, 80400, 80500 ) as $version ) runtime_check( Atshift\Deft\runtime_supported( $version ) );
runtime_check( Atshift\Deft\runtime_guard( 80200 ) && ! $hooks );
runtime_check( ! Atshift\Deft\runtime_guard( 80199 ) && isset( $hooks['admin_notices'], $hooks['network_admin_notices'] ) );
ob_start(); Atshift\Deft\runtime_notice(); $english = ob_get_clean();
runtime_check( str_contains( $english, 'role="alert"' ) && str_contains( $english, 'PHP 8.2' ) && str_contains( $english, PHP_VERSION ) );
$locale = 'ja'; ob_start(); Atshift\Deft\runtime_notice(); $japanese = ob_get_clean();
runtime_check( str_contains( $japanese, '保存済みデータは変更されません' ) );
$allowed = false; ob_start(); Atshift\Deft\runtime_notice(); runtime_check( '' === ob_get_clean() );
$bootstrap = file_get_contents( __DIR__ . '/../wordpress/atshift-deft/atshift-deft.php' );
runtime_check( strpos( $bootstrap, 'runtime_guard( PHP_VERSION_ID )' ) < strpos( $bootstrap, '/includes/plugin.php' ) );
runtime_check( str_contains( $bootstrap, '* Requires PHP: 8.2' ) );
echo 'Runtime compatibility: ' . $checks . ' passed.', PHP_EOL;
