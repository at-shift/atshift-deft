<?php
// Only install in the disposable local destination-test site.
if ( 'local' !== wp_get_environment_type() || ! preg_match( '/^sf_deft_(?:post_types|appearance)_[a-f0-9]+$/D', DB_NAME ) ) throw new RuntimeException( 'Use a disposable DEFT test database.' );
function sf_test_register_taxonomies() {
    register_taxonomy( 'sf_event_topic', 'sf_test_event', array( 'label' => 'Event topics', 'public' => true, 'show_in_rest' => false,
        'hierarchical' => true, 'capabilities' => array( 'assign_terms' => 'assign_sf_event_terms' ) ) );
    register_taxonomy( 'sf_private_topic', 'sf_test_event', array( 'public' => false, 'publicly_queryable' => true ) );
    register_taxonomy( 'sf_hidden_topic', 'sf_test_event', array( 'public' => true, 'publicly_queryable' => false ) );
    register_taxonomy( 'sf_story_topic', 'sf_test_story', array( 'public' => true ) );
    register_taxonomy( 'sf_default_topic', 'sf_test_event', array( 'public' => true, 'default_term' => array( 'name' => 'Default event topic', 'slug' => 'default-topic' ) ) );
}
add_action( 'init', 'sf_test_register_taxonomies', 12 );
