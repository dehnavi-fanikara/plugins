<?php

    /* Prevent Direct Aaccess */
    if (!defined('ABSPATH')) { exit; }

    /**
     * CPT Content & CPT City
     * Save city ID into content post when setting a relation between city and content
     */

    add_action( 'save_post_content', 'fanikara_save_content_city_meta', 10, 3 );
    function fanikara_save_content_city_meta( $post_id, $post, $update ) {

        // Prevent saving on wp autosaves
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        // Check User Privilages
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'jet_rel_default';

        // Get city ID from jet engine relation (city_related_content Relation ID 11)
        $city_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT parent_object_id FROM {$table} 
         WHERE rel_id = '11' AND child_object_id = %d 
         LIMIT 1",
            $post_id
        ) );

        if ( $city_id && $city_id > 0 ) {
            update_post_meta( $post_id, 'content_city_id', $city_id );
        } else {
            delete_post_meta( $post_id, 'content_city_id' );
        }
    }