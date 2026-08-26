<?php
/**
 * Fanikara Profile Image Shortcode Class
 *
 * Fetches user profile image from an external API or uses a fallback image.
 * Optionally adds query arguments from the parent page to the image link.
 *
 * Usage Examples:
 * [fanikar_profile_image]
 * [fanikar_profile_image post_id="123" class="custom-img"]
 * [fanikar_profile_image link="false"]
 * [fanikar_profile_image add_query_args="true"]
 *
 * @package Fanikara
 * @version 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Fanikara_Profile_Image {

    private static $instance = null;

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
        add_shortcode('fanikar_profile_image', [$this, 'render_shortcode']);
    }

    /**
     * Render profile image shortcode
     */
    public function render_shortcode($atts) {
        $atts = shortcode_atts(array(
            'post_id'        => get_the_ID(),
            'class'          => 'fanikar-profile-img',
            'alt'            => 'Fanikar Profile Image',
            'link'           => 'true',
            'add_query_args' => 'true',
        ), $atts, 'fanikar_profile_image');

        $post_id = intval($atts['post_id']);
        $post = get_post($post_id);

        if (!$post) {
            return '<!-- Fanikar: Post not found -->';
        }

        $post_title = get_the_title($post_id);

        // Get base URL from options
        $base_url = $this->get_option('fu-base-profile-address');
        if (empty($base_url)) {
            return '<!-- Fanikar: Base URL not configured in options -->';
        }

        // Get image URL
        $fanikar_id = get_post_meta($post_id, 'fnk_user_erp_id', true);
        $image_url = $this->resolve_image_url($base_url, $fanikar_id);

        if (empty($image_url)) {
            return '<!-- Fanikar: No image available -->';
        }

        // Build image tag
        $img_tag = sprintf(
            '<img src="%s" class="%s" alt="%s" title="%s" loading="lazy" />',
            esc_url($image_url),
            esc_attr($atts['class']),
            esc_attr($atts['alt']),
            esc_attr($post_title)
        );

        // If link is disabled, return only image
        if ($atts['link'] === 'false' || $atts['link'] === false) {
            return $img_tag;
        }

        // Get permalink
        $permalink = get_permalink($post_id);
        if (empty($permalink)) {
            return $img_tag;
        }

        // Add query arguments
        if ($atts['add_query_args'] === 'true' || $atts['add_query_args'] === true) {
            $permalink = $this->add_query_args($permalink, $post_id);
        }

        return sprintf(
            '<a href="%s" title="%s">%s</a>',
            esc_url($permalink),
            esc_attr(sprintf(__('View %s profile', 'fanikara'), $post_title)),
            $img_tag
        );
    }

    /**
     * Add query arguments to permalink
     * Only city_id, city_name, service_id, service_name are supported.
     *
     * @param string $permalink Original permalink URL
     * @param int    $post_id   Post ID to get meta data from (the Money Page)
     * @return string Modified permalink with query arguments
     */
    private function add_query_args($permalink, $post_id) {
        $query_args = array();

        // ==========================================
        // IMPORTANT: We pass post_id to the shortcode
        // to ensure it reads from the correct Money Page
        // ==========================================

        // city_id - term ID from level 0 (city)
        $city_id = do_shortcode('[fanikar_query_arg_generator return="city_id" post_id="' . $post_id . '"]');
        if (!empty($city_id) && is_numeric($city_id)) {
            $query_args['city_id'] = $city_id;
        }

        // city_name - term name from level 0 (city)
        $city_name = do_shortcode('[fanikar_query_arg_generator return="city_name" post_id="' . $post_id . '"]');
        if (!empty($city_name)) {
            $query_args['city_name'] = $city_name;
        }

        // service_id - service term ID
        $service_id = do_shortcode('[fanikar_query_arg_generator return="service_id" post_id="' . $post_id . '"]');
        if (!empty($service_id) && is_numeric($service_id)) {
            $query_args['service_id'] = $service_id;
        }

        // service_name - service term name
        $service_name = do_shortcode('[fanikar_query_arg_generator return="service_name" post_id="' . $post_id . '"]');
        if (!empty($service_name)) {
            $query_args['service_name'] = $service_name;
        }

        if (!empty($query_args)) {
            return add_query_arg($query_args, $permalink);
        }

        return $permalink;
    }

    /**
     * Resolve profile image URL
     *
     * @param string $base_url   Base API URL from JetEngine options
     * @param string $fanikar_id User ERP ID from post meta
     * @return string Resolved image URL or empty string
     */
    private function resolve_image_url($base_url, $fanikar_id) {
        if (!empty($fanikar_id)) {
            return trailingslashit(esc_url($base_url)) . intval($fanikar_id);
        }

        $fallback_url = $this->get_option('fu-base-profile-fallback');
        return !empty($fallback_url) ? esc_url($fallback_url) : '';
    }

    /**
     * Get option from JetEngine options page
     *
     * Tries multiple storage methods to ensure compatibility with different
     * JetEngine option page configurations.
     *
     * @param string $option_name The field key within the options page
     * @return mixed The option value or empty string if not found
     */
    private function get_option($option_name) {
        $page_slug = 'fanikara-options';

        // Method 1: JetEngine's native method
        if (function_exists('jet_engine')) {
            $value = jet_engine()->listings->data->get_option($page_slug . '::' . $option_name);
            if (!empty($value)) {
                return $value;
            }
        }

        // Method 2: Default storage (serialized array)
        $all_options = get_option($page_slug, array());
        if (is_array($all_options) && isset($all_options[$option_name])) {
            return $all_options[$option_name];
        }

        // Method 3: Separate storage with prefix
        $prefixed_value = get_option($page_slug . '_' . $option_name);
        if (!empty($prefixed_value)) {
            return $prefixed_value;
        }

        // Method 4: Direct storage
        $direct_value = get_option($option_name);
        if (!empty($direct_value)) {
            return $direct_value;
        }

        return '';
    }
}