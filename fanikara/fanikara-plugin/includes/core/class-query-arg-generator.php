<?php
    /**
     * Fanikara Query Argument Generator Class
     *
     * Generates query arguments for JetEngine Dynamic Field URL Query Arguments.
     * Automatically detects the Money Page context via URL path (most reliable).
     *
     * Usage Examples:
     * [fanikar_query_arg_generator return="city_id"]
     * [fanikar_query_arg_generator return="city_name"]
     * [fanikar_query_arg_generator return="service_id"]
     * [fanikar_query_arg_generator return="service_name"]
     *
     * @package Fanikara
     * @version 4.0.9
     */

    if (!defined('ABSPATH')) {
        exit;
    }

// Get current path dynamically
    $request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    $current_path = trim(parse_url($request_uri, PHP_URL_PATH), '/');
    if (empty($current_path) || $current_path === 'fanikar') {
        return;
    }


    class Fanikara_Query_Arg_Generator {


        private static $instance = null;
        private $area_taxonomy = 'area';
        private $service_taxonomy = 'service';

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
            add_shortcode('fanikar_query_arg_generator', [$this, 'generate_query_arg']);
            add_action('wp_footer', [$this, 'debug_output']);
        }

        /**
         * Generate query argument value based on return parameter.
         *
         * @param array $atts Shortcode attributes
         * @return string The generated value
         */
        public function generate_query_arg($atts) {
            $atts = shortcode_atts([
                'return' => '', // city_id | city_name | service_id | service_name
            ], $atts, 'fanikar_query_arg_generator');

            if (empty($atts['return'])) {
                return '';
            }

            $return_key = $atts['return'];
            $allowed_returns = ['city_id', 'city_name', 'service_id', 'service_name'];

            if (!in_array($return_key, $allowed_returns)) {
                return '';
            }

            // Find Money Page ID - ALWAYS detect fresh (no cache)
            $money_page_id = $this->find_money_page_id();

            if (!$money_page_id) {
                return '';
            }

            // Get the value based on return key
            if (strpos($return_key, 'city') !== false) {
                return $this->get_city_value($money_page_id, $return_key);
            } elseif (strpos($return_key, 'service') !== false) {
                return $this->get_service_value($money_page_id, $return_key);
            }

            return '';
        }

        /**
         * Find the Money Page ID from the current context.
         * Uses URL path as the most reliable method.
         *
         * @return int|false
         */
        private function find_money_page_id() {
            // Method 1: Parse URL path (MOST RELIABLE)
            $request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
            $path = trim(parse_url($request_uri, PHP_URL_PATH), '/');
            $path_parts = explode('/', $path);

            // URL structure: /{city}/{slug}/
            if (count($path_parts) === 2) {
                $city_slug = $path_parts[0]; // ✅ Extract city slug from URL
                $slug = $path_parts[1];      // Extract post slug from URL

                // ✅ Pass BOTH slugs to the database query
                $money_page_id = $this->find_money_page_by_slug($slug, $city_slug);
                if ($money_page_id) {
                    return $money_page_id;
                }
            }

            // Method 2: Check if current post is content
            $current_post_id = get_the_ID();
            if ($current_post_id && get_post_type($current_post_id) === 'content') {
                return $current_post_id;
            }

            // Method 3: Check the main queried object
            $queried_object = get_queried_object();
            if ($queried_object && isset($queried_object->ID) && get_post_type($queried_object->ID) === 'content') {
                return $queried_object->ID;
            }

            // Method 4: Check HTTP_REFERER
            if (isset($_SERVER['HTTP_REFERER'])) {
                $referer = $_SERVER['HTTP_REFERER'];
                $referer_path = trim(parse_url($referer, PHP_URL_PATH), '/');
                if (!empty($referer_path)) {
                    $path_parts = explode('/', $referer_path);
                    if (count($path_parts) === 2) {
                        $city_slug = $path_parts[0]; // ✅ Capture city from referer too
                        $slug = $path_parts[1];
                        $money_page_id = $this->find_money_page_by_slug($slug, $city_slug);
                        if ($money_page_id) {
                            return $money_page_id;
                        }
                    }
                }
            }

            return false;
        }

        /**
         * Find a money page by its slug AND city slug.
         *
         * @param string $slug The content post slug
         * @param string $city_slug The area taxonomy term slug
         * @return int|false
         */
        private function find_money_page_by_slug($slug, $city_slug = '') {
            global $wpdb;

            // ✅ If a city slug is provided, we must find its Term ID to match against post meta
            $city_term_id = 0;
            if (!empty($city_slug)) {
                $term = get_term_by('slug', $city_slug, $this->area_taxonomy);
                if ($term && !is_wp_error($term)) {
                    $city_term_id = (int) $term->term_id;
                }
            }

            // ✅ Build the SQL query dynamically to enforce city relation
            $sql = "SELECT p.ID 
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm_slug ON p.ID = pm_slug.post_id AND pm_slug.meta_key = 'fnk_dev_custom_slug'";

            // If we have a city term ID, JOIN the postmeta table again to check the city meta
            if ($city_term_id > 0) {
                $sql .= " INNER JOIN {$wpdb->postmeta} pm_city ON p.ID = pm_city.post_id AND pm_city.meta_key = 'fnk_dev_area_term_id'";
            }

            $sql .= " WHERE p.post_type = 'content' 
            AND p.post_status = 'publish'
            AND (p.post_name = %s OR pm_slug.meta_value = %s)";

            // Add the city condition to WHERE clause
            if ($city_term_id > 0) {
                $sql .= " AND pm_city.meta_value = %d";
            }

            $sql .= " LIMIT 1";

            // ✅ Prepare and execute query based on available parameters
            if ($city_term_id > 0) {
                $money_page = $wpdb->get_row(
                    $wpdb->prepare($sql, $slug, $slug, $city_term_id)
                );
            } else {
                // Fallback if city_slug was empty for some reason
                $money_page = $wpdb->get_row(
                    $wpdb->prepare($sql, $slug, $slug)
                );
            }

            return $money_page ? (int) $money_page->ID : false;
        }

        /**
         * Get city value from the Money Page.
         *
         * @param int $post_id Money Page ID
         * @param string $return_key city_id or city_name
         * @return string
         */
        private function get_city_value($post_id, $return_key) {
            // Try meta first
            if ($return_key === 'city_id') {
                $city_id = get_post_meta($post_id, 'fnk_dev_area_term_id', true);
                if (!empty($city_id)) {
                    return $city_id;
                }
            } elseif ($return_key === 'city_name') {
                $city_name = get_post_meta($post_id, 'fnk_dev_area_term_name', true);
                if (!empty($city_name)) {
                    return $city_name;
                }
            }

            // Fallback to taxonomy
            $terms = wp_get_post_terms($post_id, $this->area_taxonomy, [
                'fields' => 'all',
                'hide_empty' => false,
            ]);

            if (empty($terms) || is_wp_error($terms)) {
                return '';
            }

            foreach ($terms as $term) {
                if ($term->parent == 0) {
                    return ($return_key === 'city_id') ? $term->term_id : $term->name;
                }
            }

            return '';
        }

        /**
         * Get service value from the Money Page.
         *
         * @param int $post_id Money Page ID
         * @param string $return_key service_id or service_name
         * @return string
         */
        private function get_service_value($post_id, $return_key) {
            // Try meta first
            if ($return_key === 'service_id') {
                $service_id = get_post_meta($post_id, 'fnk_dev_service_term_id', true);
                if (!empty($service_id)) {
                    return $service_id;
                }
            } elseif ($return_key === 'service_name') {
                $service_name = get_post_meta($post_id, 'fnk_dev_service_term_name', true);
                if (!empty($service_name)) {
                    return $service_name;
                }
            }

            // Fallback to taxonomy
            $terms = wp_get_post_terms($post_id, $this->service_taxonomy, [
                'fields' => 'all',
                'hide_empty' => false,
            ]);

            if (empty($terms) || is_wp_error($terms)) {
                return '';
            }

            $term = $terms[0];
            if (!$term) {
                return '';
            }

            return ($return_key === 'service_id') ? $term->term_id : $term->name;
        }

        // ==========================================
        // DEBUGGER
        // ==========================================

        public function debug_output() {
            if (!isset($_GET['debug_query_args']) || !current_user_can('manage_options')) {
                return;
            }

            $post_id = get_the_ID();
            if (!$post_id) {
                return;
            }

            $post = get_post($post_id);
            $post_type = $post ? $post->post_type : 'unknown';
            $money_page_id = $this->find_money_page_id();
            $money_page_title = $money_page_id ? get_the_title($money_page_id) : '❌ پیدا نشد';

            echo '<div style="direction:rtl;background:#f0f8ff;padding:20px;border:3px solid #1854CC;border-radius:10px;margin:20px 0;font-family:Vazir, sans-serif;font-size:13px;line-height:1.8;max-height:800px;overflow:auto;">';
            echo '<h2 style="color:#1854CC;margin:0 0 15px 0;">🔍 دیباگ Query Arg Generator (v4.0.9)</h2>';
            echo '<hr>';
            echo '<p><strong>پست فعلی:</strong> ID: ' . $post_id . ', نوع: ' . $post_type . '</p>';
            echo '<p><strong>مانی‌پیج پیدا شده:</strong> ID: ' . ($money_page_id ?: '—') . ', عنوان: ' . esc_html($money_page_title) . '</p>';
            echo '<p><strong>روش تشخیص:</strong> ' . $this->get_detection_method() . '</p>';
            echo '<hr>';

            // Meta Fields
            echo '<h3>📋 متاهای کلیدی (از مانی‌پیج)</h3>';
            $meta_keys = [
                'fnk_dev_area_term_id',
                'fnk_dev_area_term_name',
                'fnk_dev_service_term_id',
                'fnk_dev_service_term_name',
            ];

            echo '<table style="width:100%;border-collapse:collapse;font-size:12px;">';
            echo '<thead><tr style="background:#f0f0f0;">';
            echo '<th style="padding:6px;border:1px solid #ddd;text-align:right;">Meta Key</th>';
            echo '<th style="padding:6px;border:1px solid #ddd;text-align:right;">Value</th>';
            echo '</tr></thead>';
            echo '<tbody>';

            foreach ($meta_keys as $key) {
                $value = $money_page_id ? get_post_meta($money_page_id, $key, true) : '❌ مانی‌پیج یافت نشد';
                $display = !empty($value) ? $value : '❌ خالی';
                $color = !empty($value) ? '#1854CC' : '#ff0000';
                echo '<tr style="border-bottom:1px solid #eee;">';
                echo '<td style="padding:6px;border:1px solid #ddd;font-weight:bold;">' . $key . '</td>';
                echo '<td style="padding:6px;border:1px solid #ddd;color:' . $color . ';">' . $display . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';

            // Shortcode Test
            echo '<h3>🔍 تست شرت‌کد query_arg_generator</h3>';
            echo '<table style="width:100%;border-collapse:collapse;font-size:12px;">';
            echo '<thead><tr style="background:#f0f0f0;">';
            echo '<th style="padding:6px;border:1px solid #ddd;text-align:right;">Shortcode</th>';
            echo '<th style="padding:6px;border:1px solid #ddd;text-align:right;">Output</th>';
            echo '</tr></thead>';
            echo '<tbody>';

            $test_codes = [
                'city_id' => '[fanikar_query_arg_generator return="city_id"]',
                'city_name' => '[fanikar_query_arg_generator return="city_name"]',
                'service_id' => '[fanikar_query_arg_generator return="service_id"]',
                'service_name' => '[fanikar_query_arg_generator return="service_name"]',
            ];

            foreach ($test_codes as $name => $code) {
                $result = do_shortcode($code);
                $display = !empty($result) ? $result : '❌ خالی';
                $color = !empty($result) ? '#1854CC' : '#ff0000';
                echo '<tr style="border-bottom:1px solid #eee;">';
                echo '<td style="padding:6px;border:1px solid #ddd;font-weight:bold;">' . $code . '</td>';
                echo '<td style="padding:6px;border:1px solid #ddd;color:' . $color . ';">' . $display . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';

            echo '<hr>';
            echo '<p style="color:#999;font-size:12px;">🔧 برای خروج از دیباگ، پارامتر <code>debug_query_args</code> را از URL حذف کنید.</p>';
            echo '</div>';
        }

        private function get_detection_method() {
            // Check URL path first
            $request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
            $path = trim(parse_url($request_uri, PHP_URL_PATH), '/');
            $path_parts = explode('/', $path);

            if (count($path_parts) === 2) {
                return 'URL Path: /' . $path_parts[0] . '/' . $path_parts[1] . '/ (City Slug: ' . $path_parts[0] . ')';
            }

            $current_post_id = get_the_ID();
            if ($current_post_id && get_post_type($current_post_id) === 'content') {
                return 'پست جاری (content)';
            }

            if (isset($_SERVER['HTTP_REFERER'])) {
                return 'HTTP_REFERER: ' . esc_html($_SERVER['HTTP_REFERER']);
            }

            return 'Unknown';
        }
    }