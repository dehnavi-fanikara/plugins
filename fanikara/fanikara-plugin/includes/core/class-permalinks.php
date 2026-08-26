<?php
/**
 * Fanikara Permalinks Class
 *
 * Manages custom permalink structure for content posts and area taxonomy.
 * URL Structure:
 *   - Content: /{city-slug}/{content-slug}/
 *   - Area Taxonomy: /area/{term-slug}/
 *
 * @package Fanikara
 * @version 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Fanikara_Permalinks {

    private static $instance = null;

    // Area taxonomy properties
    private $area_taxonomy = 'area';
    private $area_slug = 'area';

    // Service taxonomy properties
    private $service_taxonomy = 'service';
    private $service_slug = 'service';

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        // Modify permalink for content posts
        add_filter('post_type_link', [$this, 'custom_content_permalink'], 10, 2);

        // Modify permalink for area taxonomy
        add_filter('term_link', [$this, 'custom_area_term_permalink'], 10, 3);

        // Add rewrite rules
        add_action('init', [$this, 'add_rewrite_rules'], 15);

        // Add query vars
        add_filter('query_vars', [$this, 'add_query_vars']);

        // Validate content URL and redirect if needed
        add_action('template_redirect', [$this, 'validate_content_url']);

        // Flush rewrite rules when content is saved
        add_action('save_post_content', [$this, 'flush_rewrite_on_save'], 10, 3);

        // Flush rewrite rules when area term is updated
        add_action('edited_' . $this->area_taxonomy, [$this, 'flush_rewrite_on_term_update']);
        add_action('created_' . $this->area_taxonomy, [$this, 'flush_rewrite_on_term_update']);
        add_action('delete_' . $this->area_taxonomy, [$this, 'flush_rewrite_on_term_update']);

        // Flush rewrite rules when custom slug is updated
        add_action('updated_post_meta', [$this, 'flush_on_custom_slug_change'], 10, 4);
        add_action('added_post_meta', [$this, 'flush_on_custom_slug_change'], 10, 4);
        add_action('deleted_post_meta', [$this, 'flush_on_custom_slug_change'], 10, 4);

        // Flush rewrite rules on plugin activation
        add_action('init', [$this, 'maybe_flush_rewrite_rules'], 100);

        // Force Wordpress Taxonomy Base Archive
        add_action('parse_query', [$this, 'force_taxonomy_base_archive']);

        // Parse query for custom slug handling
        add_action('parse_query', [$this, 'parse_query_for_custom_slug'], 5);
    }

    /**
     * ==========================================
     * AREA TERM PERMALINK
     * ==========================================
     * Convert /?area=mashhad to /area/mashhad/
     */
    public function custom_area_term_permalink($url, $term, $taxonomy) {
        if ($taxonomy !== $this->area_taxonomy) {
            return $url;
        }

        return home_url('/' . $this->area_slug . '/' . $term->slug . '/');
    }

    /**
     * ==========================================
     * CONTENT PERMALINK
     * ==========================================
     * Convert /content/{slug}/ to /{city-slug}/{content-slug}/
     * Supports custom slug from meta (fnk_dev_custom_slug)
     */
    public function custom_content_permalink($url, $post) {
        if ('content' !== get_post_type($post)) {
            return $url;
        }

        if ('publish' !== $post->post_status) {
            return $url;
        }

        $term_id = $this->get_content_area_term_id($post->ID);
        if (!$term_id) {
            return $url;
        }

        $term = get_term($term_id, $this->area_taxonomy);
        if (!$term || is_wp_error($term)) {
            return $url;
        }

        // ✅ Use custom slug from meta if available, otherwise use post_name
        $custom_slug = get_post_meta($post->ID, 'fnk_dev_custom_slug', true);
        $slug = !empty($custom_slug) ? $custom_slug : $post->post_name;

        return home_url('/' . $term->slug . '/' . $slug . '/');
    }

    /**
     * ==========================================
     * GET CONTENT AREA TERM ID
     * ==========================================
     */
    private function get_content_area_term_id($post_id) {
        $term_id = get_post_meta($post_id, 'fnk_dev_area_term_id', true);
        if (!empty($term_id)) {
            return intval($term_id);
        }

        $terms = wp_get_post_terms($post_id, $this->area_taxonomy, [
            'fields' => 'ids',
            'parent' => 0,
        ]);

        if (!empty($terms) && !is_wp_error($terms)) {
            $term_id = intval($terms[0]);
            update_post_meta($post_id, 'fnk_dev_area_term_id', $term_id);
            return $term_id;
        }

        return false;
    }

    /**
     * ==========================================
     * ADD REWRITE RULES
     * ==========================================
     */
    public function add_rewrite_rules() {
        // ==========================================
        // 1. CONTENT RULES (HIGHEST PRIORITY)
        // ==========================================
        $cities = get_terms([
            'taxonomy' => $this->area_taxonomy,
            'parent' => 0,
            'hide_empty' => false,
            'fields' => 'slugs',
        ]);

        if (!empty($cities) && !is_wp_error($cities)) {
            $city_slugs = array_map('preg_quote', $cities);
            $city_pattern = '(' . implode('|', $city_slugs) . ')';

            // Rule: /{city-slug}/{content-slug}/ (HIGHEST PRIORITY)
            add_rewrite_rule(
                '^' . $city_pattern . '/([^/]+)/?$',
                'index.php?post_type=content&name=$matches[2]&area_city_slug=$matches[1]',
                'top'
            );
        }

        // ==========================================
        // 2. CITY REDIRECT RULES
        // ==========================================
        if (!empty($cities) && !is_wp_error($cities)) {
            // Rule: /{city-slug}/ (redirect to area taxonomy)
            add_rewrite_rule(
                '^' . $city_pattern . '/?$',
                'index.php?taxonomy=' . $this->area_taxonomy . '&term=$matches[1]',
                'top'
            );
        }


        // ==========================================
        // 3. AREA TAXONOMY RULES
        // ==========================================
        // Rule: /area/ (archive page for all areas)
        add_rewrite_rule(
            '^' . $this->area_slug . '/?$',
            'index.php?taxonomy=' . $this->area_taxonomy,
            'top'
        );

        // Rule: /area/{term-slug}/ (single area term archive)
        add_rewrite_rule(
            '^' . $this->area_slug . '/([^/]+)/?$',
            'index.php?taxonomy=' . $this->area_taxonomy . '&term=$matches[1]',
            'top'
        );

        // Rule: /area/{parent-slug}/{child-slug}/ (child area term archive)
        add_rewrite_rule(
            '^' . $this->area_slug . '/([^/]+)/([^/]+)/?$',
            'index.php?taxonomy=' . $this->area_taxonomy . '&term=$matches[2]',
            'top'
        );

        // ==========================================
        // 4. SERVICE TAXONOMY RULES
        // ==========================================
        // Rule: /service/ (archive page for all services)
        add_rewrite_rule(
            '^' . $this->service_slug . '/?$',
            'index.php?taxonomy=' . $this->service_taxonomy,
            'top'
        );

        // Rule: /service/{term-slug}/ (single service term archive)
        add_rewrite_rule(
            '^' . $this->service_slug . '/([^/]+)/?$',
            'index.php?taxonomy=' . $this->service_taxonomy . '&term=$matches[1]',
            'top'
        );
    }

    /**
     * ==========================================
     * ADD QUERY VARS
     * ==========================================
     */
    public function add_query_vars($vars) {
        $vars[] = 'area_city_slug';
        return $vars;
    }

    /**
     * ==========================================
     * VALIDATE CONTENT URL
     * ==========================================
     * Validates that the URL contains the correct city slug
     */
    public function validate_content_url() {
        if (!is_singular('content')) {
            return;
        }

        $current_post = get_queried_object();
        if (!$current_post || 'content' !== get_post_type($current_post)) {
            return;
        }

        $url_city_slug = get_query_var('area_city_slug');

        $term_id = $this->get_content_area_term_id($current_post->ID);
        if (!$term_id) {
            return;
        }

        $term = get_term($term_id, $this->area_taxonomy);
        if (!$term || is_wp_error($term)) {
            return;
        }

        $actual_city_slug = $term->slug;

        // Use custom slug from meta if available, otherwise use post_name
        $custom_slug = get_post_meta($current_post->ID, 'fnk_dev_custom_slug', true);
        $slug = !empty($custom_slug) ? $custom_slug : $current_post->post_name;

        if (empty($url_city_slug) || $url_city_slug !== $actual_city_slug) {
            $correct_url = home_url('/' . $actual_city_slug . '/' . $slug . '/');
            wp_redirect($correct_url, 301);
            exit;
        }
    }

    /**
     * ==========================================
     * FLUSH REWRITE RULES
     * ==========================================
     */
    public function flush_rewrite_on_save($post_id, $post, $update) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if ('content' !== get_post_type($post)) {
            return;
        }

        $this->flush_rewrite_rules();
    }

    public function flush_on_custom_slug_change($meta_id, $post_id, $meta_key, $meta_value) {
        // Only trigger if the meta key is fnk_dev_custom_slug
        if ($meta_key === 'fnk_dev_custom_slug') {
            $this->flush_rewrite_rules();
        }
    }

    public function flush_rewrite_on_term_update() {
        $this->flush_rewrite_rules();
    }

    private function flush_rewrite_rules() {
        static $flushed = false;

        if ($flushed) {
            return;
        }

        flush_rewrite_rules();
        $flushed = true;
        update_option('fanikara_rewrite_flushed', time());
    }

    /**
     * ==========================================
     * MAYBE FLUSH REWRITE RULES ON INIT
     * ==========================================
     */
    public function maybe_flush_rewrite_rules() {
        $last_flush = get_option('fanikara_rewrite_flushed', 0);

        if ((time() - $last_flush) > DAY_IN_SECONDS) {
            $this->flush_rewrite_rules();
        }
    }

    // ==========================================
    // HELPER METHODS
    // ==========================================

    public static function get_content_city_slug($post_id) {
        $term_id = get_post_meta($post_id, 'fnk_dev_area_term_id', true);
        if (empty($term_id)) {
            return false;
        }

        $term = get_term($term_id, 'area');
        if (!$term || is_wp_error($term)) {
            return false;
        }

        return $term->slug;
    }

    public static function get_content_city_name($post_id) {
        $term_id = get_post_meta($post_id, 'fnk_dev_area_term_id', true);
        if (empty($term_id)) {
            return false;
        }

        $term = get_term($term_id, 'area');
        if (!$term || is_wp_error($term)) {
            return false;
        }

        return $term->name;
    }

    /**
     * ==========================================
     * FORCE TAXONOMY BASE ARCHIVE RECOGNITION
     * ==========================================
     *
     * PROBLEM:
     * WordPress does not natively support "Base Archives" for taxonomies.
     * URLs like /area/ or /service/ (without a specific term slug) are not
     * recognized as taxonomy archives by WordPress core. As a result, the
     * conditional tag is_tax() returns false.
     *
     * Since Elementor Theme Builder and JetEngine rely heavily on is_tax()
     * to apply "Taxonomy Archive" templates, they fail to recognize these
     * base URLs and do not apply the assigned templates, causing layout issues.
     *
     * SOLUTION:
     * This function intercepts the main query when a base archive URL is loaded
     * (triggered by our custom rewrite rules) and manually forces WordPress to
     * treat the request as a taxonomy archive. It also sets a dummy queried
     * object so Elementor/JetEngine can correctly identify the taxonomy context.
     *
     * FUNCTIONALITY:
     * - Checks if the current query matches one of our target taxonomies (area, service).
     * - Verifies that no specific term is being queried (i.e., it's the base archive).
     * - Forces is_tax and is_archive to true, and disables is_home and is_404.
     * - Injects a dummy queried object with the taxonomy name for template matching.
     *
     * SUPPORTED TAXONOMIES:
     * - area
     * - service
     *
     * NOTE: If you add more taxonomies in the future, simply add their names
     * to the $supported_taxonomies array below.
     */
    public function force_taxonomy_base_archive($query) {
        // Only run on the front-end main query
        if (is_admin() || !$query->is_main_query()) {
            return;
        }

        // List of taxonomies that need base archive support
        $supported_taxonomies = ['area', 'service'];

        // Check if the query is for one of our target taxonomies
        if (isset($query->query_vars['taxonomy']) && in_array($query->query_vars['taxonomy'], $supported_taxonomies)) {

            // Verify this is the base archive (no specific term is queried)
            if (empty($query->query_vars['term'])) {

                $current_taxonomy = $query->query_vars['taxonomy'];

                // Force WordPress to treat this as a taxonomy archive
                $query->is_tax     = true;
                $query->is_archive = true;
                $query->is_home    = false;
                $query->is_404     = false;
                $query->is_page    = false;
                $query->is_single  = false;

                // Set a dummy queried object so Elementor/JetEngine recognizes the taxonomy context
                $query->queried_object = (object) [
                    'term_id'  => 0,
                    'taxonomy' => $current_taxonomy,
                    'name'     => 'All ' . ucfirst($current_taxonomy),
                    'slug'     => 'all',
                ];
                $query->queried_object_id = 0;
            }
        }
    }

    /**
     * ==========================================
     * PARSE QUERY - CUSTOM SLUG HANDLING
     * ==========================================
     * Detects if the URL contains a city slug and custom slug,
     * then finds the correct content post based on both.
     */
    public function parse_query_for_custom_slug($query) {
        // Only run on front-end main query
        if (is_admin() || !$query->is_main_query()) {
            return;
        }

        // Only run for content post type
        if (!isset($query->query_vars['post_type']) || $query->query_vars['post_type'] !== 'content') {
            return;
        }

        // Get the URL path
        $request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        $path = trim(parse_url($request_uri, PHP_URL_PATH), '/');
        $path_parts = explode('/', $path);

        // If URL has exactly 2 parts: /{city}/{slug}/
        if (count($path_parts) === 2) {
            $city_slug = $path_parts[0];
            $custom_slug = $path_parts[1];

            // Check if the first part is a valid city (area term)
            $city_term = get_term_by('slug', $city_slug, $this->area_taxonomy);
            if (!$city_term || is_wp_error($city_term)) {
                return;
            }

            // Look for a content post with this custom slug and city ID
            $posts = get_posts([
                'post_type' => 'content',
                'post_status' => 'publish',
                'meta_query' => [
                    'relation' => 'AND',
                    [
                        'key' => 'fnk_dev_area_term_id',
                        'value' => $city_term->term_id,
                    ],
                    [
                        'key' => 'fnk_dev_custom_slug',
                        'value' => $custom_slug,
                    ]
                ],
                'posts_per_page' => 1,
            ]);

            if (!empty($posts)) {
                // Set the found post as the queried object
                $query->set('p', $posts[0]->ID);
                $query->set('post_type', 'content');
                $query->set('name', $posts[0]->post_name);
                $query->is_singular = true;
                $query->is_404 = false;
                $query->is_archive = false;
                $query->is_tax = false;

                // Set queried object
                $query->queried_object = $posts[0];
                $query->queried_object_id = $posts[0]->ID;
            } else {
                // No post found with this custom slug and city
                // Try to find by regular slug as fallback
                $post = get_page_by_path($custom_slug, OBJECT, 'content');
                if ($post) {
                    $post_city_id = get_post_meta($post->ID, 'fnk_dev_area_term_id', true);
                    $post_city = get_term($post_city_id, $this->area_taxonomy);
                    if ($post_city && !is_wp_error($post_city) && $post_city->slug !== $city_slug) {
                        // The city doesn't match, so it's a 404
                        $query->set_404();
                        status_header(404);
                    } else {
                        $query->set('p', $post->ID);
                        $query->is_singular = true;
                        $query->is_404 = false;
                    }
                } else {
                    $query->set_404();
                    status_header(404);
                }
            }
        }
    }
}