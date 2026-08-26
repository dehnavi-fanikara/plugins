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


/**
* ==========================================
* FANIKARA DISPLAY CITY SHORTCODE
* ==========================================
* Display the city (level 0 term) attached to a fanikar post
* Usage:
* [fanikar_display_city fallback="نامشخص"]
* [fanikar_display_city post_id="123"]
* [fanikar_display_city post_id="123" fallback="نامشخص"]
*
* @param array $atts Shortcode attributes
*
* @return string City name or fallback text
*/
add_shortcode('fanikar_display_city', 'fanikar_display_city_shortcode');
function fanikar_display_city_shortcode($atts) {
// Default attributes
$atts = shortcode_atts(array(
'post_id' => get_the_ID(),
'fallback' => '',
), $atts, 'fanikar_display_city');

$post_id = intval($atts['post_id']);
if (!$post_id) {
return $atts['fallback'];
}

// Get all area terms attached to this fanikar
$area_terms = wp_get_post_terms($post_id, 'area', array(
'hide_empty' => false,
));

if (empty($area_terms) || is_wp_error($area_terms)) {
return $atts['fallback'];
}

// Find the parent term (level 0)
$city_term = null;
foreach ($area_terms as $term) {
if ($term->parent == 0) {
    $city_term = $term;
    break;
}
}

if (!$city_term) {
return $atts['fallback'];
}

// Return city name
return esc_html($city_term->name);
}

/**
* ==========================================
* FANIKARA DISPLAY AREAS SHORTCODE
* ==========================================
* Display areas attached to a fanikar post
* Usage:
* [fanikar_display_areas]
* [fanikar_display_areas separator=" | " show_parent="true"]
* [fanikar_display_areas post_id="123" separator="، " show_parent="false"]
*
* @param array $atts Shortcode attributes
*
* @return string List of areas separated by comma
*/
add_shortcode('fanikar_display_areas', 'fanikar_display_areas_shortcode');
function fanikar_display_areas_shortcode($atts) {
    // Default attributes
    $atts = shortcode_atts(array(
    'post_id' => get_the_ID(),
    'separator' => '، ',
    ), $atts, 'fanikar_display_areas');

    $post_id = intval($atts['post_id']);
    if (!$post_id) {
    return '';
    }

    // Get all area terms attached to this fanikar
    $area_terms = wp_get_post_terms($post_id, 'area', array(
    'hide_empty' => false,
    ));

    if (empty($area_terms) || is_wp_error($area_terms)) {
    return '';
    }

    // Filter only child terms (level 1 or deeper) - exclude parent terms (level 0)
    $child_terms = array_filter($area_terms, function($term) {
    return ($term->parent != 0);
    });

    if (empty($child_terms)) {
    return '';
    }

    // Build HTML output
    $output = '';
    $total = count($child_terms);
    $index = 0;

    $output .= '<span class="fnk-users-card--row-title">مناطق:</span>';

    foreach ($child_terms as $term) {
    $index++;

    // Add term span
    $output .= '<span class="fnkcard_area_name">' . esc_html($term->name) . '</span>';

    // Add separator span if not last item
    if ($index < $total) {
        $output .= '<span class="fnkcard_area_separator">' . esc_html($atts['separator']) . '</span>';
    }
    }

    return $output;
}

// ==========================================
// SECTION 6: STAR RATING SHORTCODE
// ==========================================

/**
* Star Rating Shortcode for Comments
*
* Displays star ratings from comment meta as SVG stars.
* Works in both single comment pages and comment listing loops.
*
* Usage Examples:
* [fanikar_comments_rating]
*   → Shows rating with default settings
*
* [fanikar_comments_rating meta_key="rating" max_rating="5"]
*   → Shows rating with custom meta key and max rating
*
* [fanikar_comments_rating star_size="24" show_number="no"]
*   → Shows larger stars without the number display
*
* Parameters:
* - meta_key: Comment meta key for rating value (default: "rating")
* - max_rating: Maximum possible rating (default: 5)
* - show_number: "yes" | "no" - show (3/5) next to stars (default: "yes")
* - star_size: Size of stars in pixels (default: "16")
* - star_gap: Gap between stars in pixels (default: "2")
*
* Dependencies:
* - Comment meta with rating value (numeric)
* - Works in WordPress comments loop and JetEngine comment listings
*/
function fanikar_comments_rating_shortcode($atts) {
// Default settings
$atts = shortcode_atts(array(
    'meta_key' => 'rating',
    'max_rating' => 5,
    'show_number' => 'yes',
    'star_size' => '16',
    'star_gap' => '2',
), $atts);

// ==========================================
// Step 1: Get comment ID
// ==========================================
$comment_id = 0;

// Method 1: Get current post ID
$current_post_id = get_the_ID();

// Method 2: Check if current object is a comment
$comment = get_comment($current_post_id);
if ($comment) {
    $comment_id = $comment->comment_ID;
}

// Method 3: Check WordPress query vars
if (!$comment_id) {
    global $wp_query;
    if (isset($wp_query->query_vars['comment_id'])) {
        $comment_id = $wp_query->query_vars['comment_id'];
    }
}

// Method 4: Check JetEngine object
if (!$comment_id && function_exists('jet_engine')) {
    $current_object = jet_engine()->listings->data->get_current_object();
    if ($current_object && isset($current_object->comment_ID)) {
        $comment_id = $current_object->comment_ID;
    }
}

// Fallback to post ID if no comment ID found
if (!$comment_id) {
    $comment_id = $current_post_id;
}

// ==========================================
// Step 2: Get meta value
// ==========================================
$rating_value = get_comment_meta($comment_id, $atts['meta_key'], true);

if (!$rating_value || !is_numeric($rating_value)) {
    return '';
}

// Round to nearest integer (no halves)
$rating_value = round(floatval($rating_value));
$rating_value = max(1, min($atts['max_rating'], $rating_value));

// ==========================================
// Step 3: Build stars
// ==========================================
$full_stars = $rating_value;
$empty_stars = $atts['max_rating'] - $full_stars;

// Active star icon (blue - #1854CC)
$star_active = '<svg width="' . esc_attr($atts['star_size']) . '" height="' . esc_attr($atts['star_size']) . '" viewBox="0 0 16 13" fill="#1854CC" xmlns="http://www.w3.org/2000/svg">
<path d="M12.1363 7.87403C11.9388 8.04124 11.8481 8.28306 11.8931 8.52021L12.5708 11.7977C12.628 12.0755 12.4938 12.3566 12.2277 12.5172C11.967 12.6837 11.6201 12.7037 11.3358 12.5705L7.95917 11.0316C7.84177 10.977 7.7114 10.9477 7.57798 10.9444H7.37138C7.29971 10.9537 7.22957 10.9737 7.16553 11.0043L3.78818 12.5505C3.62122 12.6238 3.43215 12.6498 3.24689 12.6238C2.79556 12.5492 2.49442 12.1734 2.56837 11.7771L3.24689 8.49956C3.29187 8.26041 3.20115 8.01726 3.00369 7.84739L0.250729 5.51582C0.0204896 5.32064 -0.0595605 5.02752 0.0456482 4.76306C0.147807 4.49926 0.408542 4.30674 0.723406 4.26344L4.51244 3.78313C4.80062 3.75715 5.05374 3.60394 5.18334 3.37744L6.85296 0.386374C6.8926 0.319758 6.94368 0.258471 7.00543 0.20651L7.07405 0.159879C7.10988 0.125238 7.15105 0.0965935 7.19679 0.0732778L7.27989 0.0466313L7.4095 0H7.73046C8.01711 0.0259803 8.26946 0.175867 8.40135 0.399697L10.0931 3.37744C10.2151 3.59528 10.4522 3.7465 10.7259 3.78313L14.5149 4.26344C14.8351 4.30341 15.1027 4.49659 15.2087 4.76306C15.3085 5.03019 15.2224 5.3233 14.9876 5.51582L12.1363 7.87403Z"/>
</svg>';

// Inactive star icon (light blue - #003CB3 with 26% opacity)
$star_inactive = '<svg width="' . esc_attr($atts['star_size']) . '" height="' . esc_attr($atts['star_size']) . '" viewBox="0 0 16 13" fill="#003CB3" fill-opacity="0.26" xmlns="http://www.w3.org/2000/svg">
<path d="M12.1363 7.87403C11.9388 8.04124 11.8481 8.28306 11.8931 8.52021L12.5708 11.7977C12.628 12.0755 12.4938 12.3566 12.2277 12.5172C11.967 12.6837 11.6201 12.7037 11.3358 12.5705L7.95917 11.0316C7.84177 10.977 7.7114 10.9477 7.57798 10.9444H7.37138C7.29971 10.9537 7.22957 10.9737 7.16553 11.0043L3.78818 12.5505C3.62122 12.6238 3.43215 12.6498 3.24689 12.6238C2.79556 12.5492 2.49442 12.1734 2.56837 11.7771L3.24689 8.49956C3.29187 8.26041 3.20115 8.01726 3.00369 7.84739L0.250729 5.51582C0.0204896 5.32064 -0.0595605 5.02752 0.0456482 4.76306C0.147807 4.49926 0.408542 4.30674 0.723406 4.26344L4.51244 3.78313C4.80062 3.75715 5.05374 3.60394 5.18334 3.37744L6.85296 0.386374C6.8926 0.319758 6.94368 0.258471 7.00543 0.20651L7.07405 0.159879C7.10988 0.125238 7.15105 0.0965935 7.19679 0.0732778L7.27989 0.0466313L7.4095 0H7.73046C8.01711 0.0259803 8.26946 0.175867 8.40135 0.399697L10.0931 3.37744C10.2151 3.59528 10.4522 3.7465 10.7259 3.78313L14.5149 4.26344C14.8351 4.30341 15.1027 4.49659 15.2087 4.76306C15.3085 5.03019 15.2224 5.3233 14.9876 5.51582L12.1363 7.87403Z"/>
</svg>';

// Build stars HTML
$stars_html = '<span class="fnk_comments_rating__stars" style="display:inline-flex;align-items:center;gap:' . esc_attr($atts['star_gap']) . 'px;">';

// Active stars
for ($i = 0; $i < $full_stars; $i++) {
    $stars_html .= $star_active;
}

// Inactive stars
for ($i = 0; $i < $empty_stars; $i++) {
    $stars_html .= $star_inactive;
}

$stars_html .= '</span>';

// Show rating number in parentheses (e.g., (3/5))
if ($atts['show_number'] == 'yes') {
    $stars_html .= ' <span class="fnk_comments_rating__number" style="font-size:' . esc_attr($atts['star_size']) . 'px;font-weight:bold;margin-left:4px;">(' . $rating_value . '/' . $atts['max_rating'] . ')</span>';
}

return $stars_html;
}
add_shortcode('fanikar_comments_rating', 'fanikar_comments_rating_shortcode');

/**
 * User Single Page
 * [FNK_USERS_ACTIVITY_IN_MONTH]
 */
function fnk_users_activity_in_month_shortcode() {
    $post_id = get_the_ID();
    if ( ! $post_id ) {
        return '—';
    }

    $stored_date_string = get_post_meta( $post_id, 'fnk_usr_reg_date', true );

    // Check if value exists and matches YYYY-MM-DD format
    if ( ! $stored_date_string || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $stored_date_string ) ) {
        return '—';
    }

    // Create DateTime object from Y-m-d string
    $stored_date = DateTime::createFromFormat( 'Y-m-d', $stored_date_string );
    if ( ! $stored_date ) {
        return '—';
    }

    $now = new DateTime();
    $now->setTime( 0, 0, 0 ); // Normalize time to avoid day issues

    $current_year  = (int) $now->format('Y');
    $current_month = (int) $now->format('m');
    $current_day   = (int) $now->format('d');

    $stored_year   = (int) $stored_date->format('Y');
    $stored_month  = (int) $stored_date->format('m');
    $stored_day    = (int) $stored_date->format('d');

    $total_months = ( $current_year - $stored_year ) * 12 + ( $current_month - $stored_month );

    // If current day is less than stored day, one full month hasn't passed
    if ( $current_day < $stored_day ) {
        $total_months--;
    }

    // Avoid negative results (e.g., future dates)
    if ( $total_months < 0 ) {
        $total_months = 0;
    }

    return (string) $total_months;
}
add_shortcode( 'FNK_USERS_ACTIVITY_IN_MONTH', 'fnk_users_activity_in_month_shortcode' ); 