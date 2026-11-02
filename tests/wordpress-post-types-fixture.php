<?php
// Loaded as a mu-plugin only in the disposable destination-test site.
if ( 'local' !== wp_get_environment_type() || ! preg_match( '/^sf_deft_(?:post_types|appearance)_[a-f0-9]+$/D', DB_NAME ) ) {
    throw new RuntimeException( 'Use a disposable DeFt test database.' );
}
function sf_test_register_post_types() {
    register_post_type( 'sf_test_event', array( 'label' => 'Events', 'description' => 'Public event announcements',
        'public' => true, 'show_in_rest' => false, 'supports' => array( 'title', 'editor', 'author' ),
        'capability_type' => array( 'sf_test_event', 'sf_test_events' ), 'map_meta_cap' => true ) );
    register_post_type( 'sf_test_story', array( 'label' => 'Stories', 'public' => true, 'supports' => array( 'title', 'editor' ) ) );
    register_post_type( 'sf_test_private', array( 'public' => false, 'publicly_queryable' => true, 'supports' => array( 'title', 'editor' ) ) );
    register_post_type( 'sf_test_hidden', array( 'public' => true, 'publicly_queryable' => false, 'supports' => array( 'title', 'editor' ) ) );
    register_post_type( 'sf_test_no_body', array( 'public' => true, 'supports' => array( 'title' ) ) );
    register_post_type( 'sf_test_no_create', array( 'public' => true, 'supports' => array( 'title', 'editor' ), 'capabilities' => array( 'create_posts' => 'do_not_allow' ) ) );
}
add_action( 'init', 'sf_test_register_post_types', 11 );
