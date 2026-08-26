<?php
/**
* Fanikara Custom Functions
* All custom shortcodes, hooks, and helper functions for the Fanikara project
*
* @package Fanikara
* @version 2.0.0
*/

// ==========================================
// SECTION 1: RELATIONSHIP & META MANAGEMENT
// ==========================================

/**
* Save City ID to Fanikar Post Meta
*
* When a fanikar (service provider) is saved, this function automatically
* retrieves the linked city from JetEngine relation (ID: 9) and stores it
* as post meta for faster querying.
*
* Dependencies:
* - JetEngine plugin with relation ID 9 (fanikar → city)
* - Custom post type 'fanikar'
*
* Hook: save_post_fanikar
* Runs when: A fanikar post is saved/updated
*
* @param int     $post_id The post ID being saved
* @param WP_Post $post    The post object
* @param bool    $update  Whether this is an update or new post
*/
add_action( 'save_post_fanikar', 'fanikara_save_fanikar_city_meta', 10, 3 );
function fanikara_save_fanikar_city_meta( $post_id, $post, $update ) {

// Prevent saving on wp autosaves
if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
    return;
}

// Get city ID from jet engine relations with ID 9
global $wpdb;
$table = $wpdb->prefix . 'jet_rel_default';

$city_id = $wpdb->get_var( $wpdb->prepare(
    "SELECT parent_object_id FROM {$table} 
WHERE rel_id = '9' AND child_object_id = %d 
LIMIT 1",
    $post_id
) );

if ( $city_id ) {
    update_post_meta( $post_id, 'fanikar_city_id', $city_id );
} else {
    delete_post_meta( $post_id, 'fanikar_city_id' );
}
}

/**
* Save Service Term ID to Content Post Meta
*
* When a content post (money page) is saved, this function gets the first
* assigned term from the 'service' taxonomy and stores both ID and name
* as post meta for fast access without taxonomy queries.
*
* Dependencies:
* - Taxonomy 'service' must be registered
* - Custom post type 'content' (money pages)
*
* Hook: save_post_content
* Runs when: A content post is saved/updated
*
* @param int     $post_id The post ID being saved
* @param WP_Post $post    The post object
* @param bool    $update  Whether this is an update or new post
*/
add_action( 'save_post_content', 'fanikara_save_content_service_meta', 10, 3 );
function fanikara_save_content_service_meta( $post_id, $post, $update ) {

// Prevent saving on wp autosaves
if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
    return;
}

// Prevent for revisions
if ( wp_is_post_revision( $post_id ) ) {
    return;
}

// Get the terms from 'service' taxonomy
$terms = wp_get_post_terms( $post_id, 'service', array(
    'fields' => 'all',
) );

if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
    // Take the first term
    $first_term = reset( $terms );

    if ( $first_term ) {
        update_post_meta( $post_id, 'content_service_id', $first_term->term_id );
        update_post_meta( $post_id, 'content_service_name', $first_term->name );
    }
} else {
    delete_post_meta( $post_id, 'content_service_id' );
    delete_post_meta( $post_id, 'content_service_name' );
}
}

/**
* Save Area Term ID to Content Post Meta
*
* When a content post (money page) is saved, this function gets the first
* assigned term from the 'area' taxonomy and stores both ID and name
* as post meta for fast access without taxonomy queries.
*
* Dependencies:
* - Taxonomy 'area' must be registered
* - Custom post type 'content' (money pages)
*
* Hook: save_post_content
* Runs when: A content post is saved/updated
*
* @param int     $post_id The post ID being saved
* @param WP_Post $post    The post object
* @param bool    $update  Whether this is an update or new post
*/
add_action( 'save_post_content', 'fanikara_save_content_area_meta', 10, 3 );
function fanikara_save_content_area_meta( $post_id, $post, $update ) {

// Prevent saving on wp autosaves
if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
    return;
}

// Prevent for revisions
if ( wp_is_post_revision( $post_id ) ) {
    return;
}

// Get the terms from 'area' taxonomy
$terms = wp_get_post_terms( $post_id, 'area', array(
    'fields' => 'all',
) );

if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
    // Take the first term
    $first_term = reset( $terms );

    if ( $first_term ) {
        update_post_meta( $post_id, 'content_area_id', $first_term->term_id );
        update_post_meta( $post_id, 'content_area_name', $first_term->name );
    }
} else {
    delete_post_meta( $post_id, 'content_area_id' );
    delete_post_meta( $post_id, 'content_area_name' );
}
}


// ==========================================
// SECTION 2: PAGE DATA CACHING (PERFORMANCE)
// ==========================================

/**
* Store Parent Page Data in Global Variable for Fast Access
*
* This function runs once per page load and stores city/service/area data
* in a global variable. All shortcodes can then read from this variable
* instead of running separate database queries for each listing card.
*
* Performance Impact: Reduces database queries by ~95% in listing pages
*
* Dependencies:
* - Meta fields 'content_city_id', 'content_service_id', 'content_area_id' on content posts
* - Global variable $fanikara_page_data (used by all shortcodes)
*
* Hook: wp
* Runs when: WordPress is fully loaded, before rendering the page
*/
add_action( 'wp', 'fanikara_store_main_page_data' );
function fanikara_store_main_page_data() {
global $fanikara_page_data;

$page_id = get_queried_object_id();

$fanikara_page_data = array(
    'page_id'       => $page_id,
    'city_id'       => '',
    'city_name'     => '',
    'service_id'    => '',
    'service_name'  => '',
    'area_id'       => '',
    'area_name'     => '',
);

if ( ! $page_id ) {
    return;
}

// Get city ID from parent page meta (only once)
$city_id = get_post_meta( $page_id, 'content_city_id', true );
if ( ! empty( $city_id ) ) {
    $fanikara_page_data['city_id'] = (string) $city_id;
    $fanikara_page_data['city_name'] = get_post_field( 'post_title', (int) $city_id );
}

// Get service ID from parent page meta (only once)
$service_id = get_post_meta( $page_id, 'content_service_id', true );
if ( ! empty( $service_id ) ) {
    $fanikara_page_data['service_id'] = (string) $service_id;

    $service_name = get_post_meta( $page_id, 'content_service_name', true );
    if ( empty( $service_name ) ) {
        $term = get_term( (int) $service_id, 'service' );
        if ( $term && ! is_wp_error( $term ) ) {
            $service_name = $term->name;
        }
    }
    $fanikara_page_data['service_name'] = $service_name;
}

// Get area ID from parent page meta (only once)
$area_id = get_post_meta( $page_id, 'content_area_id', true );
if ( ! empty( $area_id ) ) {
    $fanikara_page_data['area_id'] = (string) $area_id;

    $area_name = get_post_meta( $page_id, 'content_area_name', true );
    if ( empty( $area_name ) ) {
        $term = get_term( (int) $area_id, 'area' );
        if ( $term && ! is_wp_error( $term ) ) {
            $area_name = $term->name;
        }
    }
    $fanikara_page_data['area_name'] = $area_name;
}
}


// ==========================================
// SECTION 3: UNIVERSAL QUERY ARGUMENT GENERATOR SHORTCODE
// ==========================================

/**
 * Universal Query Argument Generator Shortcode
 *
 * Returns values from either parent page meta OR current post taxonomy.
 * The user must specify the 'return' parameter to get a specific value.
 * If 'return' is not set, the shortcode outputs nothing.
 *
 * Usage Examples:
 * // Get city from parent page meta (default behavior)
 * [fanikar_query_arg_generator return="city_id"]
 *   → Returns: 123
 *
 * // Get service from parent page meta
 * [fanikar_query_arg_generator return="service_id"]
 *   → Returns: 45
 *
 * // Get area from current post taxonomy
 * [fanikar_query_arg_generator return="area_id" source="taxonomy"]
 *   → Returns: 12
 *
 * // Get multiple areas from current post taxonomy
 * [fanikar_query_arg_generator return="area_id" source="taxonomy" multiple="true" separator="|"]
 *   → Returns: 12|34|56
 *
 * // Get area names from current post taxonomy
 * [fanikar_query_arg_generator return="area_name" source="taxonomy"]
 *   → Returns: مرکز
 *
 * Parameters:
 * - return: REQUIRED. "city_id" | "city_name" | "service_id" | "service_name" | "area_id" | "area_name"
 * - source: "meta" | "taxonomy" (default: "meta" for city/service, "taxonomy" for area)
 * - taxonomy: Taxonomy name (default: "area") - only used when source="taxonomy"
 * - multiple: "true" | "false" (default: "false")
 * - separator: String to separate multiple values (default: ", ")
 * - post_id: Specific post ID for taxonomy (auto-detected if not set)
 *
 * Dependencies:
 * - Global $fanikara_page_data (set by fanikara_store_main_page_data) for meta source
 * - WordPress taxonomy system for taxonomy source
 * - JetEngine listing context (auto-detects post ID in listings)
 * - Object cache for performance
 */
add_shortcode( 'fanikar_query_arg_generator', 'fanikar_query_arg_generator_shortcode' );
function fanikar_query_arg_generator_shortcode( $atts ) {

    // Parse shortcode attributes with defaults
    $atts = shortcode_atts( array(
        // REQUIRED: Which value to return
        'return'     => '', // city_id | city_name | service_id | service_name | area_id | area_name

        // Source: meta (parent page) or taxonomy (current post)
        'source'     => 'meta', // meta | taxonomy

        // Taxonomy settings (only used when source="taxonomy")
        'taxonomy'   => 'area',
        'multiple'   => 'false',
        'separator'  => ', ',
        'post_id'    => 0,

        // Meta settings (only used when source="meta")
        'city_multiple'      => 'false',
        'city_separator'     => ', ',
        'service_multiple'   => 'false',
        'service_separator'  => ', ',
    ), $atts, 'fanikar_query_arg_generator' );

    // ==========================================
    // REQUIRE 'return' PARAMETER
    // If not set, output nothing
    // ==========================================
    if ( empty( $atts['return'] ) ) {
        return '';
    }

    $return_key = $atts['return'];
    $allowed_returns = array( 'city_id', 'city_name', 'service_id', 'service_name', 'area_id', 'area_name' );

    if ( ! in_array( $return_key, $allowed_returns ) ) {
        return '';
    }

    // ==========================================
    // SOURCE: TAXONOMY (for area)
    // ==========================================
    if ( $atts['source'] === 'taxonomy' ) {

        // Auto-detect post ID
        $post_id = intval($atts['post_id']);

        if ( ! $post_id ) {
            if ( function_exists('jet_engine_get_listing_post_id') ) {
                $post_id = jet_engine_get_listing_post_id();
            }

            if ( ! $post_id ) {
                global $post;
                if ( $post && $post->ID ) {
                    $post_id = $post->ID;
                }
            }

            if ( ! $post_id ) {
                $post_id = get_the_ID();
            }
        }

        if ( ! $post_id || $post_id == 0 ) {
            return '';
        }

        $taxonomy = ! empty( $atts['taxonomy'] ) ? $atts['taxonomy'] : 'area';

        if ( ! taxonomy_exists( $taxonomy ) ) {
            return '';
        }

        // Check cache
        $cache_key = "fanikara_terms_{$post_id}_{$taxonomy}_{$return_key}_{$atts['multiple']}";
        $cached_value = wp_cache_get( $cache_key, 'fanikara' );

        if ( false !== $cached_value ) {
            return $cached_value;
        }

        // Get terms
        $terms = wp_get_post_terms( $post_id, $taxonomy, array(
            'fields' => 'all',
        ) );

        if ( empty( $terms ) || is_wp_error( $terms ) ) {
            return '';
        }

        // Determine which field to return
        $field = 'id';
        if ( strpos( $return_key, 'name' ) !== false ) {
            $field = 'name';
        } elseif ( strpos( $return_key, 'slug' ) !== false ) {
            $field = 'slug';
        }

        // Build result
        if ( $atts['multiple'] === 'true' ) {
            $values = array();
            foreach ( $terms as $term ) {
                $values[] = fanikar_term_get_field_value( $term, $field );
            }
            $result = implode( $atts['separator'], $values );
        } else {
            $first_term = reset( $terms );
            if ( ! $first_term ) {
                return '';
            }
            $result = fanikar_term_get_field_value( $first_term, $field );
        }

        // Store in cache
        wp_cache_set( $cache_key, $result, 'fanikara', HOUR_IN_SECONDS );

        return $result;
    }

    // ==========================================
    // SOURCE: META (parent page data) - for city and service
    // ==========================================

    // Get global page data
    global $fanikara_page_data;

    if ( empty( $fanikara_page_data ) ) {
        return '';
    }

    // Get the value from global data
    $value = isset( $fanikara_page_data[ $return_key ] ) ? $fanikara_page_data[ $return_key ] : '';

    if ( empty( $value ) ) {
        return '';
    }

    // Check if multiple values should be returned
    $multiple = false;
    $separator = ', ';

    // Determine if this field supports multiple and what separator to use
    if ( strpos( $return_key, 'service' ) !== false ) {
        $multiple = ( $atts['service_multiple'] === 'true' );
        $separator = $atts['service_separator'];
    } elseif ( strpos( $return_key, 'city' ) !== false ) {
        $multiple = ( $atts['city_multiple'] === 'true' );
        $separator = $atts['city_separator'];
    }

    // If multiple is enabled and value contains comma, replace with separator
    if ( $multiple && strpos( $value, ',' ) !== false ) {
        return str_replace( ',', $separator, $value );
    }

    return $value;
}

/**
 * Get Term Field Value Helper
 *
 * Extracts the requested field from a term object.
 *
 * @param object $term  The term object
 * @param string $field The field to extract (id, name, slug)
 * @return string The field value
 */
function fanikar_term_get_field_value($term, $field) {
    switch ( $field ) {
        case 'name':
            return $term->name;
        case 'slug':
            return $term->slug;
        case 'id':
        default:
            return $term->term_id;
    }
}


// ==========================================
// SECTION 4: PROFILE IMAGE SHORTCODE
// ==========================================

/**
 * Fanikar Profile Image Shortcode
 *
 * Fetches user profile image from an external API or uses a fallback image.
 * Optionally adds query arguments from the parent page to the image link.
 *
 * Usage Examples:
 * [fanikar_profile_image]
 *   → Shows profile image linked to the fanikar's page
 *
 * [fanikar_profile_image post_id="123" class="custom-img"]
 *   → Shows profile image for a specific fanikar with custom class
 *
 * [fanikar_profile_image link="false"]
 *   → Shows image without a link
 *
 * [fanikar_profile_image add_query_args="true"]
 *   → Adds query arguments to the link URL
 *
 * Parameters:
 * - post_id: Fanikar post ID (auto-detected if not set)
 * - class: CSS class for the image (default: "fanikar-profile-img")
 * - alt: Alt text for the image (default: "Fanikar Profile Image")
 * - link: "true" | "false" - whether to wrap image in a link (default: "true")
 * - add_query_args: "true" | "false" - add parent page data to URL (default: "true")
 *
 * Dependencies:
 * - JetEngine options page 'fanikara-options' for API URL and fallback image
 * - Post meta 'fnk_user_erp_id' on fanikar posts
 * - Global $fanikara_page_data for query arguments
 * - fanikar_query_arg_generator shortcode for generating query arguments
 *
 * Hook: add_shortcode('fanikar_profile_image')
 */
function fanikar_profile_image_shortcode($atts) {

    $atts = shortcode_atts(array(
        'post_id'        => get_the_ID(),
        'class'          => 'fanikar-profile-img',
        'alt'            => 'Fanikar Profile Image',
        'link'           => true,
        'add_query_args' => 'true',
    ), $atts, 'fanikar_profile_image');

    $post_id = intval($atts['post_id']);
    $post = get_post($post_id);

    if ( ! $post ) {
        return '<!-- Fanikar: Post not found -->';
    }

    $post_title = get_the_title($post_id);

    $base_url = fanikar_profile_image_get_option('fu-base-profile-address');

    if (empty($base_url)) {
        return '<!-- Fanikar: Base URL not configured in options -->';
    }

    $fanikar_id = get_post_meta($post_id, 'fnk_user_erp_id', true);
    $image_url = fanikar_profile_image_resolve_url($base_url, $fanikar_id);

    if (empty($image_url)) {
        return '<!-- Fanikar: No image available -->';
    }

    $img_tag = sprintf(
        '<img src="%s" class="%s" alt="%s" title="%s" loading="lazy" />',
        esc_url($image_url),
        esc_attr($atts['class']),
        esc_attr($atts['alt']),
        esc_attr($post_title)
    );

    if ( $atts['link'] === false || $atts['link'] === 'false' ) {
        return $img_tag;
    }

    $permalink = get_permalink($post_id);

    if ( empty($permalink) ) {
        return $img_tag;
    }

    // Add query arguments from parent page using the universal generator
    if ( $atts['add_query_args'] === 'true' || $atts['add_query_args'] === true ) {

        $query_args = array();

        // City and Service from parent page meta (source="meta" is default)
        $meta_params = array( 'city_id', 'city_name', 'service_id', 'service_name' );
        foreach ( $meta_params as $param ) {
            $value = do_shortcode( '[fanikar_query_arg_generator return="' . $param . '"]' );
            if ( ! empty( $value ) ) {
                $query_args[ $param ] = $value;
            }
        }

        // Area from current post taxonomy (must specify source="taxonomy")
        $area_id = do_shortcode( '[fanikar_query_arg_generator return="area_id" source="taxonomy"]' );
        if ( ! empty( $area_id ) ) {
            $query_args['area_id'] = $area_id;
        }

        $area_name = do_shortcode( '[fanikar_query_arg_generator return="area_name" source="taxonomy"]' );
        if ( ! empty( $area_name ) ) {
            $query_args['area_name'] = $area_name;
        }

        if ( ! empty( $query_args ) ) {
            $permalink = add_query_arg( $query_args, $permalink );
        }
    }

    return sprintf(
        '<a href="%s" title="%s">%s</a>',
        esc_url($permalink),
        esc_attr(sprintf(__('View %s profile', 'fanikara'), $post_title)),
        $img_tag
    );
}
add_shortcode('fanikar_profile_image', 'fanikar_profile_image_shortcode');

/**
* Resolve Profile Image URL
*
* Builds the API URL using the fanikar ERP ID, or returns the fallback image.
*
* @param string $base_url   The base API URL from JetEngine options
* @param string $fanikar_id The user ERP ID from post meta
* @return string The resolved image URL or empty string
*/
function fanikar_profile_image_resolve_url($base_url, $fanikar_id) {

if ( ! empty($fanikar_id) ) {
    return trailingslashit(esc_url($base_url)) . intval($fanikar_id);
}

$fallback_url = fanikar_profile_image_get_option('fu-base-profile-fallback');

return ! empty($fallback_url) ? esc_url($fallback_url) : '';
}

/**
* Get Option from JetEngine Options Page
*
* Tries multiple storage methods to ensure compatibility with different
* JetEngine option page configurations.
*
* @param string $option_name The field key within the options page
* @return mixed The option value or empty string if not found
*/
function fanikar_profile_image_get_option($option_name) {

$page_slug = 'fanikara-options';

// Method 1: JetEngine's native method
if ( function_exists('jet_engine') ) {
    $value = jet_engine()->listings->data->get_option($page_slug . '::' . $option_name);
    if ( ! empty($value) ) {
        return $value;
    }
}

// Method 2: Default storage (serialized array)
$all_options = get_option($page_slug, array());
if ( is_array($all_options) && isset($all_options[$option_name]) ) {
    return $all_options[$option_name];
}

// Method 3: Separate storage with prefix
$prefixed_value = get_option($page_slug . '_' . $option_name);
if ( ! empty($prefixed_value) ) {
    return $prefixed_value;
}

// Method 4: Direct storage
$direct_value = get_option($option_name);
if ( ! empty($direct_value) ) {
    return $direct_value;
}

return '';
}


// ==========================================
// SECTION 5: MEMBERSHIP DURATION SHORTCODE
// ==========================================

/**
* Months Since Registration Shortcode
*
* Calculates and displays the number of months since a fanikar registered.
*
* Usage Examples:
* [fanikar_months_since_registration]
*   → Shows months for the current fanikar in the loop
*
* [fanikar_months_since_registration post_id="123"]
*   → Shows months for a specific fanikar
*
* Parameters:
* - post_id: Fanikar post ID (auto-detected if not set)
*
* Dependencies:
* - Post meta 'fnk_usr_reg_date' in Y-m-d format on fanikar posts
* - DateTime class for date calculations
*/
function fanikar_months_since_registration($atts) {

$atts = shortcode_atts( array(
    'post_id' => get_the_ID(),
), $atts, 'fanikar_months_since_registration' );

$reg_date = get_post_meta( $atts['post_id'], 'fnk_usr_reg_date', true );

if ( empty( $reg_date ) ) {
    return '';
}

$months = fanikar_months_since_registration_calc( $reg_date );

return $months;
}
add_shortcode('fanikar_months_since_registration', 'fanikar_months_since_registration');

/**
* Calculate Months Between Dates
*
* Calculates the total months between registration date and current date.
*
* @param string $reg_date Registration date in Y-m-d format
* @return int Number of months since registration
*/
function fanikar_months_since_registration_calc($reg_date) {

try {
    $from = new DateTime( $reg_date );
    $to   = new DateTime();

    $diff = $from->diff( $to );
    $months = ( $diff->y * 12 ) + $diff->m;

    return $months;

} catch ( Exception $e ) {
    return 0;
}
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