<?php

/**
 * Get All Post Meta Shortcode
 *
 * Returns all meta keys and values for a given post ID.
 * Useful for debugging and seeing what meta data is available.
 *
 * Usage Examples:
 * [fnk_get_all_post_meta post_id="123"]
 *   → Returns all meta data for post ID 123
 *
 * [fnk_get_all_post_meta post_id="456" format="json"]
 *   → Returns all meta data as JSON string
 *
 * [fnk_get_all_post_meta post_id="789" format="list"]
 *   → Returns all meta data as HTML list
 *
 * Parameters:
 * - post_id: REQUIRED. The post ID to get meta data for
 * - format: "list" | "json" | "array" (default: "list")
 *
 * Dependencies:
 * - WordPress post meta functions
 *
 * Hook: add_shortcode('fnk_get_all_post_meta')
 */
add_shortcode( 'fnk_get_all_post_meta', 'fnk_get_all_post_meta_shortcode' );
function fnk_get_all_post_meta_shortcode( $atts ) {

    // Parse shortcode attributes
    $atts = shortcode_atts( array(
        'post_id' => 0,
        'format'  => 'list', // list | json | array
    ), $atts, 'fnk_get_all_post_meta' );

    $post_id = intval( $atts['post_id'] );

    // Check if post_id is provided and valid
    if ( empty( $post_id ) ) {
        return '<!-- fnk_get_all_post_meta: No post_id provided -->';
    }

    $post = get_post( $post_id );
    if ( ! $post ) {
        return '<!-- fnk_get_all_post_meta: Post not found (ID: ' . $post_id . ') -->';
    }

    // Get all post meta
    $meta_data = get_post_meta( $post_id, '', true );

    if ( empty( $meta_data ) ) {
        return '<!-- fnk_get_all_post_meta: No meta data found for post ID ' . $post_id . ' -->';
    }

    // ==========================================
    // FORMAT: ARRAY (for PHP/developer use)
    // ==========================================
    if ( $atts['format'] === 'array' ) {
        $output = 'array(';
        $items = array();
        foreach ( $meta_data as $key => $value ) {
            // Unserialize the value for display
            if ( is_serialized( $value[0] ) ) {
                $display_value = unserialize( $value[0] );
                $display_value = var_export( $display_value, true );
            } else {
                $display_value = "'" . addslashes( $value[0] ) . "'";
            }
            $items[] = "'$key' => " . $display_value;
        }
        $output .= implode( ', ', $items );
        $output .= ')';
        return $output;
    }

    // ==========================================
    // FORMAT: JSON
    // ==========================================
    if ( $atts['format'] === 'json' ) {
        $json_data = array();
        foreach ( $meta_data as $key => $value ) {
            if ( is_serialized( $value[0] ) ) {
                $json_data[ $key ] = unserialize( $value[0] );
            } else {
                $json_data[ $key ] = $value[0];
            }
        }
        return json_encode( $json_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
    }

    // ==========================================
    // FORMAT: LIST (default) - Human readable HTML
    // ==========================================
    $output = '<div class="fnk-post-meta-list" style="background:#f5f5f5;padding:15px;border-radius:5px;font-size:13px;font-family:monospace;overflow:auto;">';
    $output .= '<h4 style="margin:0 0 10px 0;">Meta Data for Post ID: ' . $post_id . ' (' . get_the_title( $post_id ) . ')</h4>';
    $output .= '<table style="width:100%;border-collapse:collapse;">';
    $output .= '<thead><tr style="background:#e0e0e0;"><th style="text-align:left;padding:8px;border:1px solid #ddd;">Meta Key</th><th style="text-align:left;padding:8px;border:1px solid #ddd;">Value</th></tr></thead>';
    $output .= '<tbody>';

    foreach ( $meta_data as $key => $value ) {
        $display_value = $value[0];

        // Check if serialized
        if ( is_serialized( $display_value ) ) {
            $unserialized = unserialize( $display_value );
            if ( is_array( $unserialized ) ) {
                $display_value = '<pre style="margin:0;white-space:pre-wrap;background:#f0f0f0;padding:5px;border-radius:3px;">' . print_r( $unserialized, true ) . '</pre>';
            } else {
                $display_value = esc_html( $display_value );
            }
        } else {
            $display_value = esc_html( $display_value );
        }

        $output .= '<tr style="border-bottom:1px solid #eee;">';
        $output .= '<td style="padding:8px;border:1px solid #ddd;font-weight:bold;color:#333;">' . esc_html( $key ) . '</td>';
        $output .= '<td style="padding:8px;border:1px solid #ddd;word-break:break-all;">' . $display_value . '</td>';
        $output .= '</tr>';
    }

    $output .= '</tbody></table>';
    $output .= '<p style="margin:5px 0 0 0;color:#666;font-size:12px;">Total meta keys: ' . count( $meta_data ) . '</p>';
    $output .= '</div>';

    return $output;
}