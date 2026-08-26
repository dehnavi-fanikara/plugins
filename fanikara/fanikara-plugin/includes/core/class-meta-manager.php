<?php
    /**
     * Fanikara Meta Manager Class
     *
     * Manages custom meta fields for content and fanikar posts.
     * Stores area term ID and service term ID for fast access.
     *
     * @package Fanikara
     * @version 1.0.0
     */

    if (!defined('ABSPATH')) {
        exit;
    }

    class Fanikara_Meta_Manager {

        private static $instance = null;
        private $area_taxonomy = 'area';
        private $service_taxonomy = 'service';

        // Meta keys
        const META_AREA_TERM_ID = 'fnk_dev_area_term_id';
        const META_SERVICE_TERM_ID = 'fnk_dev_service_term_id';

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
            // Save meta when content is saved
            add_action('save_post_content', [$this, 'save_content_meta'], 10, 3);

            // Save meta when fanikar is saved
            add_action('save_post_fanikar', [$this, 'save_fanikar_meta'], 10, 3);
        }

        /**
         * Save content meta fields
         *
         * @param int $post_id
         * @param WP_Post $post
         * @param bool $update
         */
        public function save_content_meta($post_id, $post, $update) {
            // Prevent autosave
            if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
                return;
            }

            // Prevent revisions
            if (wp_is_post_revision($post_id)) {
                return;
            }

            // Check user permissions
            if (!current_user_can('edit_post', $post_id)) {
                return;
            }

            // ==========================================
            // 1. Save Area Term ID (City)
            // ==========================================
            $area_term_id = $this->get_first_top_level_term_id($post_id, $this->area_taxonomy);
            if ($area_term_id) {
                update_post_meta($post_id, self::META_AREA_TERM_ID, $area_term_id);

                // Also save term name and slug for quick access
                $term = get_term($area_term_id, $this->area_taxonomy);
                if ($term && !is_wp_error($term)) {
                    update_post_meta($post_id, 'fnk_dev_area_term_name', $term->name);
                    update_post_meta($post_id, 'fnk_dev_area_term_slug', $term->slug);
                }
            } else {
                // If no area term found, delete the meta
                delete_post_meta($post_id, self::META_AREA_TERM_ID);
                delete_post_meta($post_id, 'fnk_dev_area_term_name');
                delete_post_meta($post_id, 'fnk_dev_area_term_slug');
            }

            // ==========================================
            // 2. Save Service Term ID (Service)
            // ==========================================
            $service_term_id = $this->get_first_top_level_term_id($post_id, $this->service_taxonomy);
            if ($service_term_id) {
                update_post_meta($post_id, self::META_SERVICE_TERM_ID, $service_term_id);

                // Also save term name for quick access
                $term = get_term($service_term_id, $this->service_taxonomy);
                if ($term && !is_wp_error($term)) {
                    update_post_meta($post_id, 'fnk_dev_service_term_name', $term->name);
                }
            } else {
                // If no service term found, delete the meta
                delete_post_meta($post_id, self::META_SERVICE_TERM_ID);
                delete_post_meta($post_id, 'fnk_dev_service_term_name');
            }
        }

        /**
         * Save fanikar meta fields
         *
         * @param int $post_id
         * @param WP_Post $post
         * @param bool $update
         */
        public function save_fanikar_meta($post_id, $post, $update) {
            // Prevent autosave
            if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
                return;
            }

            // Prevent revisions
            if (wp_is_post_revision($post_id)) {
                return;
            }

            // Check user permissions
            if (!current_user_can('edit_post', $post_id)) {
                return;
            }

            // ==========================================
            // 1. Save Area Term ID (City)
            // ==========================================
            $area_term_id = $this->get_first_top_level_term_id($post_id, $this->area_taxonomy);
            if ($area_term_id) {
                // Save with fanikar prefix (for internal use)
                update_post_meta($post_id, 'fnk_dev_fanikar_area_term_id', $area_term_id);

                // Save without prefix (for compatibility with query_arg_generator)
                update_post_meta($post_id, self::META_AREA_TERM_ID, $area_term_id);

                // Also save term name and slug
                $term = get_term($area_term_id, $this->area_taxonomy);
                if ($term && !is_wp_error($term)) {
                    update_post_meta($post_id, 'fnk_dev_area_term_name', $term->name);
                    update_post_meta($post_id, 'fnk_dev_area_term_slug', $term->slug);
                }
            } else {
                delete_post_meta($post_id, 'fnk_dev_fanikar_area_term_id');
                delete_post_meta($post_id, self::META_AREA_TERM_ID);
                delete_post_meta($post_id, 'fnk_dev_area_term_name');
                delete_post_meta($post_id, 'fnk_dev_area_term_slug');
            }

            // ==========================================
            // 2. Save Service Term ID (Service)
            // ==========================================
            $service_term_id = $this->get_first_top_level_term_id($post_id, $this->service_taxonomy);
            if ($service_term_id) {
                // Save with fanikar prefix (for internal use)
                update_post_meta($post_id, 'fnk_dev_fanikar_service_term_id', $service_term_id);

                // Save without prefix (for compatibility with query_arg_generator)
                update_post_meta($post_id, self::META_SERVICE_TERM_ID, $service_term_id);

                // Also save term name
                $term = get_term($service_term_id, $this->service_taxonomy);
                if ($term && !is_wp_error($term)) {
                    update_post_meta($post_id, 'fnk_dev_service_term_name', $term->name);
                }
            } else {
                delete_post_meta($post_id, 'fnk_dev_fanikar_service_term_id');
                delete_post_meta($post_id, self::META_SERVICE_TERM_ID);
                delete_post_meta($post_id, 'fnk_dev_service_term_name');
            }
        }

        /**
         * Get first top-level term ID from a taxonomy
         *
         * @param int $post_id
         * @param string $taxonomy
         * @return int|false
         */
        private function get_first_top_level_term_id($post_id, $taxonomy) {
            $terms = wp_get_post_terms($post_id, $taxonomy, [
                'parent' => 0, // Only get top-level terms
                'fields' => 'ids',
                'hide_empty' => false,
            ]);

            if (!empty($terms) && !is_wp_error($terms)) {
                return intval($terms[0]);
            }

            return false;
        }

        /**
         * Get all top-level term IDs from a taxonomy
         *
         * @param int $post_id
         * @param string $taxonomy
         * @return array
         */
        private function get_top_level_term_ids($post_id, $taxonomy) {
            $terms = wp_get_post_terms($post_id, $taxonomy, [
                'parent' => 0,
                'fields' => 'ids',
                'hide_empty' => false,
            ]);

            if (!empty($terms) && !is_wp_error($terms)) {
                return array_map('intval', $terms);
            }

            return [];
        }

        /**
         * Get area term ID for a content post
         *
         * @param int $post_id
         * @return int|false
         */
        public static function get_content_area_term_id($post_id) {
            $term_id = get_post_meta($post_id, self::META_AREA_TERM_ID, true);
            return !empty($term_id) ? intval($term_id) : false;
        }

        /**
         * Get service term ID for a content post
         *
         * @param int $post_id
         * @return int|false
         */
        public static function get_content_service_term_id($post_id) {
            $term_id = get_post_meta($post_id, self::META_SERVICE_TERM_ID, true);
            return !empty($term_id) ? intval($term_id) : false;
        }

        /**
         * Get area term ID for a fanikar post
         *
         * @param int $post_id
         * @return int|false
         */
        public static function get_fanikar_area_term_id($post_id) {
            $term_id = get_post_meta($post_id, 'fnk_dev_fanikar_area_term_id', true);
            return !empty($term_id) ? intval($term_id) : false;
        }

        /**
         * Get service term ID for a fanikar post
         *
         * @param int $post_id
         * @return int|false
         */
        public static function get_fanikar_service_term_id($post_id) {
            $term_id = get_post_meta($post_id, 'fnk_dev_fanikar_service_term_id', true);
            return !empty($term_id) ? intval($term_id) : false;
        }

        /**
         * Get area term slug for a content post
         *
         * @param int $post_id
         * @return string|false
         */
        public static function get_content_area_slug($post_id) {
            $slug = get_post_meta($post_id, 'fnk_dev_area_term_slug', true);
            if (!empty($slug)) {
                return $slug;
            }

            // Fallback: get from term
            $term_id = self::get_content_area_term_id($post_id);
            if ($term_id) {
                $term = get_term($term_id, 'area');
                if ($term && !is_wp_error($term)) {
                    return $term->slug;
                }
            }

            return false;
        }
    }