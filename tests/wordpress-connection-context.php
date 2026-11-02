<?php
// CLI suites simulate multiple HTTP requests in one PHP process. Finish the exact request.
function sf_test_end_connection() {
    $active = $GLOBALS['sf_test_active_request'] ?? null;
    if ( $active ) Atshift\Deft\Connections::finish( new WP_REST_Response(), rest_get_server(), $active );
    $GLOBALS['sf_test_active_request'] = null;
}
function sf_test_begin_connection( $request ) {
    $actor = get_current_user_id();
    sf_test_end_connection();
    wp_set_current_user( $actor );
    $result = Atshift\Deft\Connections::guard( null, rest_get_server(), $request );
    if ( null === $result ) $GLOBALS['sf_test_active_request'] = $request;
    return $result;
}
