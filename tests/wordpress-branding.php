<?php
require __DIR__ . '/wordpress-media-fixture.php';

$roles = wp_roles();
$original = $roles->roles;
$version = get_option( 'atshde_brand_version' );
$checks = 0;
function sf_brand_check( $condition, $message ) {
    global $checks;
    if ( ! $condition ) throw new RuntimeException( $message );
    echo 'PASS ' . ++$checks . ': ' . $message . PHP_EOL;
}

try {
    $legacy = $original;
    foreach ( array( 'author', 'designer', 'publisher' ) as $name ) {
        $key = 'atshde_' . $name;
        sf_brand_check( isset( $legacy[$key] ), 'Existing role key: ' . $key );
        $legacy[$key]['name'] = 'atshift DEFT ' . $name;
        $legacy[$key]['capabilities']['sf_test_custom_allow'] = true;
        $legacy[$key]['capabilities']['sf_test_custom_deny'] = false;
    }
    update_option( $roles->role_key, $legacy );
    update_option( 'atshde_brand_version', 1, false );
    $roles->for_site();
    Atshift\Deft\Access::update_brand_labels();
    $expected = $legacy;
    foreach ( array( 'author', 'designer', 'publisher' ) as $name ) {
        $key = 'atshde_' . $name;
        $expected[$key]['name'] = 'atshift DeFt ' . $name;
        sf_brand_check( $expected[$key]['name'] === $roles->role_names[$key], 'In-memory role label: ' . $key );
    }
    sf_brand_check( $expected === get_option( $roles->role_key ), 'Only display names change; all role keys and allow/deny capabilities remain exact.' );
    sf_brand_check( $expected === $roles->roles, 'Stored and in-memory role registries agree.' );
    sf_brand_check( 2 === (int) get_option( 'atshde_brand_version' ), 'Display-name migration advances once.' );
    Atshift\Deft\Access::update_brand_labels();
    sf_brand_check( $expected === get_option( $roles->role_key ), 'Repeating the migration is idempotent.' );
    echo 'WordPress branding: ' . $checks . ' passed.' . PHP_EOL;
} finally {
    update_option( $roles->role_key, $original );
    update_option( 'atshde_brand_version', $version, false );
    $roles->for_site();
}
