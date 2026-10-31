<?php
// Run with WP-CLI eval-file in a disposable local DEFT database.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! str_starts_with( DB_NAME, 'sf_deft_' ) ) {
    throw new RuntimeException( 'Use a disposable local DEFT database.' );
}

use StructuredFields\Onboarding;

$GLOBALS['sf_guidance_checks'] = 0;
function sf_guidance_check( $condition, $message ) {
    if ( ! $condition ) throw new RuntimeException( $message );
    ++$GLOBALS['sf_guidance_checks'];
}
function sf_guidance_render( $callback ) {
    ob_start(); $callback(); return ob_get_clean();
}

$original_user = get_current_user_id();
$author = wp_insert_user( array( 'user_login' => 'guidance-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password( 32 ), 'role' => 'sf_author', 'display_name' => 'Owner & QA' ) );
if ( is_wp_error( $author ) ) throw new RuntimeException( 'Could not create guidance fixture.' );
try {
    wp_set_current_user( $author );
    $before = get_userdata( $author )->allcaps;
    $html = sf_guidance_render( 'StructuredFields\\admin_page' );
    $dom = new DOMDocument(); @$dom->loadHTML( $html ); $xpath = new DOMXPath( $dom );
    sf_guidance_check( 1 === $xpath->query( '//div[@id="sf-draft-owner"]' )->length && str_contains( $html, 'Draft owner: Owner &amp; QA' ), 'The current draft owner is visible and escaped.' );
    sf_guidance_check( str_contains( $html, 'WordPress role: atshift DEFT author' ), 'The current owner role is shown without changing capabilities.' );
    sf_guidance_check( 1 === $xpath->query( '//p[@id="sf-drafts-help"]' )->length && str_contains( $html, 'Once published or scheduled' ) && str_contains( $html, 'Saved content and files are retained.' ), 'Persistent guidance explains destinations and data retention.' );
    sf_guidance_check( 1 === $xpath->query( '//details[@id="sf-publication-help"]/summary' )->length, 'Articles exposes a collapsed publication guide.' );
    sf_guidance_check( 4 === $xpath->query( '//details[@id="sf-publication-help"]/ol/li' )->length, 'The guide gives four ordered steps.' );
    sf_guidance_check( 1 === $xpath->query( '//details[@id="sf-publication-help"]//a[contains(@href,"page=structured-fields-connections")]' )->length, 'Articles links directly to connection setup.' );
    sf_guidance_check( str_contains( $html, 'Your account cannot grant publication access.' ), 'Authors get a same-account permission explanation.' );
    sf_guidance_check( str_contains( $html, 'Save draft and Review do not publish.' ) && str_contains( $html, 'without another WordPress approval' ), 'The guide distinguishes review and delegated publication.' );
    sf_guidance_check( str_contains( $html, 'Initial publication only is supported' ) && str_contains( $html, 'time zone' ), 'Publication limits and scheduling details are visible.' );
    $connections = sf_guidance_render( 'StructuredFields\\connections_page' );
    sf_guidance_check( str_contains( $connections, 'id="sf-publication-help"' ) && ! str_contains( $connections, 'id="sf-connection-publishing"' ), 'Authors can read guidance but cannot grant publication.' );
    sf_guidance_check( get_userdata( $author )->allcaps === $before, 'Reading the guide does not grant capabilities.' );
    $doc = Onboarding::document();
    foreach ( array( '### From Saved Draft to Publication', 'capabilities.publish=true', 'Explaining publication is not permission to perform it.', 'retain the draft connection', 'same WordPress owner', 'OAuth-only client', 'second WordPress approval', 'do not turn sample approval into a publication offer' ) as $text ) {
        sf_guidance_check( str_contains( $doc, $text ) || str_contains( $doc, lcfirst( $text ) ), 'Downloaded guide includes: ' . $text );
    }
    ( new WP_User( $author ) )->set_role( 'sf_publisher' );
    wp_set_current_user( 0 ); wp_set_current_user( $author );
    $connections = sf_guidance_render( 'StructuredFields\\connections_page' );
    sf_guidance_check( ! str_contains( $connections, 'Your account cannot grant publication access.' ), 'Publisher does not see the author-only warning.' );
    $dom = new DOMDocument(); @$dom->loadHTML( $connections ); $xpath = new DOMXPath( $dom );
    sf_guidance_check( 1 === $xpath->query( '//input[@id="sf-connection-publishing" and not(@checked)]' )->length, 'Publication stays unchecked by default.' );
    load_textdomain( 'atshift-deft', WP_PLUGIN_DIR . '/atshift-deft/languages/atshift-deft-ja.mo' );
    $japanese = sf_guidance_render( array( Onboarding::class, 'publication_help' ) );
    sf_guidance_check( str_contains( $japanese, '公開・予約の手順' ) && str_contains( $japanese, '自分の下書きの即時公開・予約公開を許可' ), 'Japanese guide and option label match.' );
    sf_guidance_check( str_contains( $japanese, '公開後の下書き編集は公開記事に反映されません' ), 'Japanese copy includes the publication limit.' );
    $japanese_workspace = sf_guidance_render( 'StructuredFields\\admin_page' );
    sf_guidance_check( str_contains( $japanese_workspace, '下書きの所有者：Owner &amp; QA' ) && str_contains( $japanese_workspace, 'WordPressの権限グループ：' ), 'Japanese owner and role labels are translated and escaped.' );
    sf_guidance_check( str_contains( $japanese_workspace, '公開または予約が確定すると' ) && str_contains( $japanese_workspace, '保存した本文やファイルは削除されません。' ), 'Japanese lifecycle guidance explains reservations and retention.' );
    echo wp_json_encode( array( 'guidance_checks' => $GLOBALS['sf_guidance_checks'] ) );
} finally {
    unload_textdomain( 'atshift-deft', true );
    wp_set_current_user( $original_user );
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user( $author );
}
