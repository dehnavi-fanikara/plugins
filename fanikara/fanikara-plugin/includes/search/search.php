<?php
/**
 * Hyper Search Engine for Fanikara Project
 * Version: 4.0.4 - FINAL STABLE
 *
 * CHANGELOG v4.0.4:
 * - FIX: Dropdown container with absolute positioning for header
 * - ADD: Support for fnk_content_sdfsr meta for search excerpts
 * - FIX: Code cleanup and optimization
 *
 * @package Fanikara
 * @version 4.0.4
 */

if (!defined('ABSPATH')) {
    exit;
}

class Fanikara_Hyper_Search {

    const MIN_CHARS = 2;
    const VERSION = '4.0.4';

    private static $instance = null;
    private $area_taxonomy = 'area';
    private $service_taxonomy = 'service';

    private function __construct() {
        $this->init_hooks();
    }

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function init_hooks() {
        add_shortcode('fanikar_hyper_search', array($this, 'render_shortcode'));
        add_action('wp_ajax_fanikar_hyper_search', array($this, 'handle_ajax_search'));
        add_action('wp_ajax_nopriv_fanikar_hyper_search', array($this, 'handle_ajax_search'));
        add_action('wp_ajax_fanikar_hyper_suggest', array($this, 'handle_ajax_suggest'));
        add_action('wp_ajax_nopriv_fanikar_hyper_suggest', array($this, 'handle_ajax_suggest'));
        add_action('wp_footer', array($this, 'debug_sibling_titles'));
        add_action('wp_footer', array($this, 'debug_search_process'));
    }

    /**
     * ==========================================
     * RENDER SHORTCODE
     * ==========================================
     */
    public function render_shortcode($atts) {
        $defaults = array(
                'mode' => 'text',
                'ajax' => 'true',
                'show_city' => 'true',
                'show_service' => 'false',
                'show_search' => 'true',
                'placeholder' => __('چه خدمتی نیاز دارید؟', 'fanikara'),
                'items_per_page' => 10,
                'container_class' => '',
        );

        $atts = shortcode_atts($defaults, $atts, 'fanikar_hyper_search');

        $ajax = filter_var($atts['ajax'], FILTER_VALIDATE_BOOLEAN);
        $show_city = filter_var($atts['show_city'], FILTER_VALIDATE_BOOLEAN);
        $show_service = filter_var($atts['show_service'], FILTER_VALIDATE_BOOLEAN);
        $show_search = filter_var($atts['show_search'], FILTER_VALIDATE_BOOLEAN);
        $mode = $atts['mode'];

        $instance_id = 'fnk_hyper_search_' . uniqid();

        ob_start();
        ?>
        <div id="<?php echo esc_attr($instance_id); ?>"
             class="fnk_hyper_search fnk_hyper_search--mode-<?php echo esc_attr($mode); ?> <?php echo esc_attr($atts['container_class']); ?>"
             data-ajax="<?php echo esc_attr($ajax ? 'true' : 'false'); ?>"
             data-items-per-page="<?php echo esc_attr($atts['items_per_page']); ?>"
             data-mode="<?php echo esc_attr($mode); ?>">

            <form class="fnk_hyper_search__form" method="get" action="#">

                <div class="fnk_hyper_search__fields">

                    <?php if ($show_city) : ?>
                        <div class="fnk_hyper_search__field fnk_hyper_search__field--city">
                            <select name="city_id"
                                    class="fnk_hyper_search__select fnk_hyper_search__select--city"
                                    data-type="city"
                                    required>
                                <option value=""><?php _e('شهر', 'fanikara'); ?></option>
                                <?php echo $this->get_city_options(); ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <?php if ($mode === 'select' && $show_service) : ?>
                        <div class="fnk_hyper_search__field fnk_hyper_search__field--service">
                            <select name="service_id"
                                    class="fnk_hyper_search__select fnk_hyper_search__select--service"
                                    data-type="service"
                                    required>
                                <option value=""><?php _e('انتخاب خدمات...', 'fanikara'); ?></option>
                                <?php echo $this->get_service_options(); ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <?php if ($mode === 'text' && $show_search) : ?>
                        <div class="fnk_hyper_search__field fnk_hyper_search__field--search">
                            <input type="text"
                                   name="search_term"
                                   class="fnk_hyper_search__input"
                                   placeholder="<?php echo esc_attr($atts['placeholder']); ?>"
                                   autocomplete="off"
                                   data-min-chars="<?php echo esc_attr(self::MIN_CHARS); ?>"
                                   required>
                            <button type="submit" class="fnk_hyper_search__submit" title="<?php _e('جستجو', 'fanikara'); ?>">
                                <span class="fnk_hyper_search__icon-search"></span>
                            </button>
                            <button type="button" class="fnk_hyper_search__reset" title="<?php _e('پاک کردن', 'fanikara'); ?>" style="display:none;">
                                <span class="fnk_hyper_search__icon-reset"></span>
                            </button>
                        </div>
                    <?php endif; ?>

                    <?php if ($mode === 'select') : ?>
                        <div class="fnk_hyper_search__field fnk_hyper_search__field--submit">
                            <button type="submit" class="fnk_hyper_search__submit-btn">
                                <?php _e('جستجو', 'fanikara'); ?>
                            </button>
                        </div>
                    <?php endif; ?>

                </div>

                <input type="hidden" name="action" value="fanikar_hyper_search">
                <?php wp_nonce_field('fanikar_hyper_search_nonce', 'fanikar_hyper_search_nonce'); ?>

            </form>

            <!-- ==========================================
                 DROPDOWN CONTAINER (Results + History)
                 با position: absolute برای جلوگیری از کشیدگی هدر
            ========================================== -->
            <div class="fnk_hyper_search__dropdown-container">
                <div class="fnk_hyper_search__dropdown-wrapper" style="display:none;">
                    <div class="fnk_hyper_search__suggestions"></div>
                    <div class="fnk_hyper_search__results">
                        <div class="fnk_hyper_search__results-inner"></div>
                        <div class="fnk_hyper_search__loader" style="display:none;"></div>
                    </div>
                </div>
            </div>

        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * ==========================================
     * GET CITY OPTIONS
     * ==========================================
     */
    private function get_city_options() {
        $cities = get_terms(array(
                'taxonomy' => $this->area_taxonomy,
                'parent' => 0,
                'hide_empty' => false,
                'orderby' => 'name',
                'order' => 'ASC',
        ));

        $html = '';
        if (!is_wp_error($cities) && !empty($cities)) {
            foreach ($cities as $city) {
                $html .= sprintf(
                        '<option value="%d">%s</option>',
                        $city->term_id,
                        esc_html($city->name)
                );
            }
        }

        return $html;
    }

    /**
     * ==========================================
     * GET SERVICE OPTIONS
     * ==========================================
     */
    private function get_service_options() {
        $services = get_terms(array(
                'taxonomy' => $this->service_taxonomy,
                'hide_empty' => true,
                'orderby' => 'name',
                'order' => 'ASC',
        ));

        $html = '';
        if (!is_wp_error($services)) {
            foreach ($services as $service) {
                $html .= sprintf(
                        '<option value="%d">%s</option>',
                        $service->term_id,
                        esc_html($service->name)
                );
            }
        }

        return $html;
    }

    /**
     * ==========================================
     * NORMALIZE PERSIAN TEXT
     * ==========================================
     */
    private function normalize_text($text) {
        $text = trim($text);
        $text = preg_replace('/[\s\x{00A0}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}\x{FEFF}]+/u', ' ', $text);
        $text = str_replace("\u{200C}", '', $text);
        $text = str_replace(array('ي', 'ى', 'ئ'), 'ی', $text);
        $text = str_replace('ك', 'ک', $text);

        $text_no_space = preg_replace('/\s+/u', '', $text);

        return array(
                'original' => $text,
                'no_space' => $text_no_space,
                'lower' => mb_strtolower($text, 'UTF-8'),
                'lower_no_space' => mb_strtolower($text_no_space, 'UTF-8'),
        );
    }

    /**
     * ==========================================
     * CHECK IF TEXT MATCHES
     * ==========================================
     */
    private function text_matches($search, $target) {
        if (empty($search) || empty($target)) {
            return false;
        }

        $search_norm = $this->normalize_text($search);
        $target_norm = $this->normalize_text($target);

        if (mb_strpos($target_norm['lower'], $search_norm['lower']) !== false) {
            return true;
        }

        if (mb_strpos($target_norm['lower_no_space'], $search_norm['lower_no_space']) !== false) {
            return true;
        }

        $search_parts = explode(' ', $search_norm['lower']);
        foreach ($search_parts as $part) {
            if (strlen($part) < 2) continue;
            if (mb_strpos($target_norm['lower'], $part) !== false) {
                return true;
            }
            if (mb_strpos($target_norm['lower_no_space'], $part) !== false) {
                return true;
            }
        }

        $target_parts = explode(' ', $target_norm['lower']);
        foreach ($target_parts as $part) {
            if (strlen($part) < 2) continue;
            if (mb_strpos($search_norm['lower'], $part) !== false) {
                return true;
            }
            if (mb_strpos($search_norm['lower_no_space'], $part) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * ==========================================
     * HANDLE AJAX SEARCH
     * ==========================================
     */
    public function handle_ajax_search() {
        if (!check_ajax_referer('fanikar_hyper_search_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Invalid nonce', 'fanikara')));
            wp_die();
        }

        $city_id = isset($_POST['city_id']) ? intval($_POST['city_id']) : 0;
        $service_id = isset($_POST['service_id']) ? intval($_POST['service_id']) : 0;
        $search_term = isset($_POST['search_term']) ? sanitize_text_field($_POST['search_term']) : '';
        $mode = isset($_POST['mode']) ? sanitize_text_field($_POST['mode']) : 'text';
        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $items_per_page = isset($_POST['items_per_page']) ? intval($_POST['items_per_page']) : 10;
        $without_city = isset($_POST['without_city']) && $_POST['without_city'] === 'true';

        $is_debug = isset($_POST['debug']) && $_POST['debug'] === 'true';
        if ($is_debug) {
            error_log('🔍 AJAX Search Debug (v' . self::VERSION . '):');
            error_log('  City ID (area term): ' . $city_id);
            error_log('  Without City: ' . ($without_city ? 'Yes' : 'No'));
            error_log('  Search Term: ' . $search_term);
            error_log('  Mode: ' . $mode);
            error_log('  Page: ' . $page);
        }

        if (empty($city_id)) {
            wp_send_json_error(array('message' => __('لطفاً یک شهر انتخاب کنید', 'fanikara')));
            wp_die();
        }

        if ($mode === 'select' && empty($service_id)) {
            wp_send_json_error(array('message' => __('لطفاً یک خدمت انتخاب کنید', 'fanikara')));
            wp_die();
        }

        if ($mode === 'text' && empty($search_term)) {
            wp_send_json_error(array('message' => __('لطفاً یک عبارت جستجو وارد کنید', 'fanikara')));
            wp_die();
        }

        $results = $this->perform_search($city_id, $service_id, $search_term, $mode, $page, $items_per_page, $without_city);

        if ($is_debug) {
            error_log('  Results count: ' . count($results['results']));
            error_log('  Total: ' . $results['total']);
        }

        nocache_headers();
        wp_send_json_success($results);
        wp_die();
    }

    /**
     * ==========================================
     * PERFORM SEARCH
     * ==========================================
     */
    /**
     * ==========================================
     * PERFORM SEARCH
     * ==========================================
     */
    private function perform_search($city_id, $service_id, $search_term, $mode, $page, $items_per_page, $without_city = false) {
        $args = array(
                'post_type'      => 'content',
                'post_status'    => 'publish',
                'posts_per_page' => $items_per_page,
                'paged'          => $page,
                'meta_query'     => array(),
                'tax_query'      => array('relation' => 'AND'),
                'orderby'        => 'title',
                'order'          => 'ASC',
        );

        // City filter - only if city_id is provided and not without_city
        if (!empty($city_id) && $city_id > 0 && !$without_city) {
            $args['tax_query'][] = array(
                    'taxonomy'         => $this->area_taxonomy,
                    'field'            => 'term_id',
                    'terms'            => $city_id,
                    'operator'         => 'IN',
                    'include_children' => true,
            );
        }

        // Select mode: Service filter
        if ($mode === 'select' && $service_id > 0) {
            $args['tax_query'][] = array(
                    'taxonomy' => $this->service_taxonomy,
                    'field'    => 'term_id',
                    'terms'    => $service_id,
                    'operator' => 'IN',
            );
        }

        // Text mode
        if ($mode === 'text' && !empty($search_term)) {
            $matched_content_ids = $this->find_content_by_text_search($search_term, $city_id, $without_city);
            if (!empty($matched_content_ids)) {
                $args['post__in'] = $matched_content_ids;
                $args['orderby']  = 'post__in';
            } else {
                return array(
                        'results'      => array(),
                        'total'        => 0,
                        'has_more'     => false,
                        'current_page' => $page,
                );
            }
        }

        $query    = new WP_Query($args);
        $results  = array();
        $has_more = false;

        // Get fallback image from JetEngine options
        $fallback_image = $this->get_fallback_image();

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();

                // Get thumbnail
                $thumbnail = get_the_post_thumbnail_url($post_id, 'thumbnail');

                // If no thumbnail, use fallback
                if (empty($thumbnail)) {
                    $thumbnail = $fallback_image;
                }

                // Get search excerpt from meta
                $search_excerpt = get_post_meta($post_id, 'fnk_content_sdfsr', true);

                $result = array(
                        'id'             => $post_id,
                        'title'          => get_the_title(),
                        'permalink'      => get_permalink(),
                        'thumbnail'      => $thumbnail,
                        'excerpt'        => wp_trim_words(get_the_excerpt(), 20, '...'),
                        'search_excerpt' => !empty($search_excerpt) ? wp_trim_words($search_excerpt, 25, '...') : '',
                        'city'           => $this->get_content_city_name($post_id),
                        'service'        => $this->get_content_service_name($post_id),
                        'areas'          => $this->get_content_areas($post_id),
                );

                $results[] = $result;
            }

            $has_more = ($query->max_num_pages > $page);
        }

        wp_reset_postdata();

        return array(
                'results'      => $results,
                'total'        => $query->found_posts,
                'has_more'     => $has_more,
                'current_page' => $page,
        );
    }

    /**
     * ==========================================
     * FIND CONTENT BY TEXT SEARCH
     * ==========================================
     */
    private function find_content_by_text_search($search_term, $city_id, $without_city = false) {
        $found_ids = array();
        $meta_query = array();

        // Only add city filter if city_id is provided and not without_city
        if (!empty($city_id) && $city_id > 0 && !$without_city) {
            $meta_query[] = array(
                    'key' => 'fnk_dev_area_term_id',
                    'value' => $city_id,
                    'type' => 'NUMERIC',
                    'compare' => '=',
            );
        }

        // Step 1: Search in content titles
        $title_query = new WP_Query(array(
                'post_type' => 'content',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                's' => $search_term,
                'meta_query' => $meta_query,
                'fields' => 'ids',
        ));

        if ($title_query->have_posts()) {
            $found_ids = array_merge($found_ids, $title_query->posts);
        }

        // Step 2: Search in service taxonomy names
        $service_terms = get_terms(array(
                'taxonomy' => $this->service_taxonomy,
                'hide_empty' => false,
                'number' => 999,
        ));

        $matched_service_ids = array();
        if (!empty($service_terms) && !is_wp_error($service_terms)) {
            foreach ($service_terms as $term) {
                if ($this->text_matches($search_term, $term->name)) {
                    $matched_service_ids[] = $term->term_id;
                }
            }
        }

        if (!empty($matched_service_ids)) {
            $service_query = new WP_Query(array(
                    'post_type' => 'content',
                    'post_status' => 'publish',
                    'posts_per_page' => -1,
                    'meta_query' => $meta_query,
                    'tax_query' => array(
                            array(
                                    'taxonomy' => $this->service_taxonomy,
                                    'field' => 'term_id',
                                    'terms' => $matched_service_ids,
                                    'operator' => 'IN',
                            )
                    ),
                    'fields' => 'ids',
            ));

            if ($service_query->have_posts()) {
                $found_ids = array_merge($found_ids, $service_query->posts);
            }
        }

        // Step 3: Search in sibling titles
        $sibling_term_ids = $this->find_terms_by_sibling_title($search_term);

        if (!empty($sibling_term_ids)) {
            $sibling_query = new WP_Query(array(
                    'post_type' => 'content',
                    'post_status' => 'publish',
                    'posts_per_page' => -1,
                    'meta_query' => $meta_query,
                    'tax_query' => array(
                            array(
                                    'taxonomy' => $this->service_taxonomy,
                                    'field' => 'term_id',
                                    'terms' => $sibling_term_ids,
                                    'operator' => 'IN',
                            )
                    ),
                    'fields' => 'ids',
            ));

            if ($sibling_query->have_posts()) {
                $found_ids = array_merge($found_ids, $sibling_query->posts);
            }
        }

        return array_unique($found_ids);
    }

    /**
     * ==========================================
     * FIND TERMS BY SIBLING TITLES
     * ==========================================
     */
    private function find_terms_by_sibling_title($search_term) {
        $matched_term_ids = array();

        $all_terms = get_terms(array(
                'taxonomy' => $this->service_taxonomy,
                'hide_empty' => false,
                'fields' => 'all',
                'number' => 999,
        ));

        if (empty($all_terms) || is_wp_error($all_terms)) {
            return array();
        }

        foreach ($all_terms as $term) {
            if ($this->text_matches($search_term, $term->name)) {
                $matched_term_ids[] = $term->term_id;
                continue;
            }

            $sibling_titles = get_term_meta($term->term_id, 'service_meta_sibling_titles', true);

            if (!empty($sibling_titles)) {
                $titles = preg_split('/[-–—]/u', $sibling_titles);
                $titles = array_map(function($title) {
                    return trim(preg_replace('/[\s\x{00A0}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}\x{FEFF}]+/u', ' ', $title));
                }, $titles);

                foreach ($titles as $title) {
                    if (empty($title) || strlen($title) < 2) {
                        continue;
                    }

                    if ($this->text_matches($search_term, $title)) {
                        $matched_term_ids[] = $term->term_id;
                        break;
                    }
                }
            }
        }

        return array_unique($matched_term_ids);
    }

    /**
     * ==========================================
     * GET CONTENT CITY NAME
     * ==========================================
     */
    private function get_content_city_name($post_id) {
        $terms = wp_get_post_terms($post_id, $this->area_taxonomy, array('fields' => 'all'));

        if (empty($terms) || is_wp_error($terms)) {
            return '';
        }

        foreach ($terms as $term) {
            if ($term->parent == 0) {
                return $term->name;
            }
        }

        $top_term = null;
        foreach ($terms as $term) {
            $parent_exists = false;
            foreach ($terms as $other) {
                if ($other->term_id == $term->parent) {
                    $parent_exists = true;
                    break;
                }
            }
            if (!$parent_exists) {
                $top_term = $term;
                break;
            }
        }

        return $top_term ? $top_term->name : '';
    }

    /**
     * ==========================================
     * GET CONTENT SERVICE NAME
     * ==========================================
     */
    private function get_content_service_name($post_id) {
        $terms = wp_get_post_terms($post_id, $this->service_taxonomy, array('fields' => 'names'));
        if (!empty($terms) && !is_wp_error($terms)) {
            return $terms[0];
        }

        $service_name = get_post_meta($post_id, 'content_service_name', true);
        if (!empty($service_name)) {
            return $service_name;
        }

        return '';
    }

    /**
     * ==========================================
     * GET CONTENT SERVICE IDS
     * ==========================================
     */
    private function get_content_service_ids($post_id) {
        $terms = wp_get_post_terms($post_id, $this->service_taxonomy, array('fields' => 'ids'));
        if (!empty($terms) && !is_wp_error($terms)) {
            return $terms;
        }
        return array();
    }

    /**
     * ==========================================
     * GET CONTENT AREAS
     * ==========================================
     */
    private function get_content_areas($post_id) {
        $terms = wp_get_post_terms($post_id, $this->area_taxonomy, array(
                'fields' => 'all',
        ));

        if (empty($terms) || is_wp_error($terms)) {
            return array();
        }

        $areas = array();
        foreach ($terms as $term) {
            if ($term->parent == 0) {
                continue;
            }

            $area_data = array(
                    'id' => $term->term_id,
                    'name' => $term->name,
            );

            if ($term->parent > 0) {
                $parent = get_term($term->parent, $this->area_taxonomy);
                if ($parent && !is_wp_error($parent)) {
                    $area_data['parent'] = $parent->name;
                }
            }

            $areas[] = $area_data;
        }

        return $areas;
    }

    /**
     * ==========================================
     * HANDLE AJAX SUGGESTIONS
     * ==========================================
     */
    public function handle_ajax_suggest() {
        if (!check_ajax_referer('fanikar_hyper_search_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Invalid nonce', 'fanikara')));
            wp_die();
        }

        $term = isset($_POST['term']) ? sanitize_text_field($_POST['term']) : '';
        $city_id = isset($_POST['city_id']) ? intval($_POST['city_id']) : 0;

        if (strlen($term) < self::MIN_CHARS || empty($city_id)) {
            wp_send_json_success(array('suggestions' => array()));
            wp_die();
        }

        $args = array(
                'post_type' => 'content',
                'post_status' => 'publish',
                'posts_per_page' => 10,
                's' => $term,
                'tax_query' => array('relation' => 'AND'),
                'orderby' => 'relevance',
        );

        $city_tax_query = array(
                'taxonomy' => $this->area_taxonomy,
                'field' => 'term_id',
                'terms' => $city_id,
                'operator' => 'IN',
                'include_children' => true,
        );
        $args['tax_query'][] = $city_tax_query;

        $query = new WP_Query($args);
        $suggestions = array();

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $suggestions[] = array(
                        'id' => get_the_ID(),
                        'title' => get_the_title(),
                        'permalink' => get_permalink(),
                        'thumbnail' => get_the_post_thumbnail_url(get_the_ID(), 'thumbnail'),
                );
            }
        }

        wp_reset_postdata();

        wp_send_json_success(array('suggestions' => $suggestions));
        wp_die();
    }

    /**
     * Get fallback image from JetEngine options
     * Key: fb-blog-single-default-picture
     * Option page: fanikara-options
     *
     * @return string Fallback image URL
     */
    private function get_fallback_image() {
        $default_image = '';

        // Method 1: JetEngine native method
        if (function_exists('jet_engine')) {
            $option = jet_engine()->listings->data->get_option('fanikara-options::fb-blog-single-default-picture');
            if (!empty($option)) {
                $default_image = $option;
            }
        }

        // Method 2: Direct option
        if (empty($default_image)) {
            $default_image = get_option('fb-blog-single-default-picture', '');
        }

        // Method 3: Fallback from fanikara-options array
        if (empty($default_image)) {
            $all_options = get_option('fanikara-options', array());
            if (is_array($all_options) && isset($all_options['fb-blog-single-default-picture'])) {
                $default_image = $all_options['fb-blog-single-default-picture'];
            }
        }

        return $default_image;
    }

    /**
     * ==========================================
     * DEBUG SIBLING TITLES
     * ==========================================
     */
    public function debug_sibling_titles() {
        if (!current_user_can('manage_options') || !isset($_GET['debug_sibling'])) {
            return;
        }

        $search_term = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
        $city_id = isset($_GET['city_id']) ? intval($_GET['city_id']) : 0;

        echo '<div style="direction:rtl;text-align:right;background:#f5f5f5;padding:20px;margin:20px;border:2px solid #1854CC;border-radius:8px;font-family:monospace;font-size:13px;max-height:800px;overflow:auto;">';
        echo '<h2 style="color:#1854CC;">🔍 دیباگ Sibling Titles (v' . self::VERSION . ')</h2>';
        echo '<hr>';

        echo '<h3>📋 لیست همه ترم‌های سرویس و Sibling Titles:</h3>';
        echo '<table style="width:100%;border-collapse:collapse;background:#fff;font-size:12px;">';
        echo '<thead><tr style="background:#1854CC;color:#fff;">';
        echo '<th style="padding:6px 8px;border:1px solid #ddd;text-align:right;">ID</th>';
        echo '<th style="padding:6px 8px;border:1px solid #ddd;text-align:right;">نام ترم</th>';
        echo '<th style="padding:6px 8px;border:1px solid #ddd;text-align:right;">اسلاگ</th>';
        echo '<th style="padding:6px 8px;border:1px solid #ddd;text-align:right;">Sibling Titles (متا)</th>';
        echo '<th style="padding:6px 8px;border:1px solid #ddd;text-align:right;">تعداد پست متصل (taxonomy)</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        $all_terms = get_terms(array(
                'taxonomy' => 'service',
                'hide_empty' => false,
                'fields' => 'all',
                'number' => 999,
        ));

        if (!empty($all_terms) && !is_wp_error($all_terms)) {
            foreach ($all_terms as $term) {
                $sibling_titles = get_term_meta($term->term_id, 'service_meta_sibling_titles', true);
                $count = $term->count;

                echo '<tr style="border-bottom:1px solid #eee;">';
                echo '<td style="padding:4px 8px;border:1px solid #ddd;">' . $term->term_id . '</td>';
                echo '<td style="padding:4px 8px;border:1px solid #ddd;font-weight:bold;">' . esc_html($term->name) . '</td>';
                echo '<td style="padding:4px 8px;border:1px solid #ddd;">' . esc_html($term->slug) . '</td>';
                echo '<td style="padding:4px 8px;border:1px solid #ddd;color:' . (!empty($sibling_titles) ? '#1854CC;font-weight:bold;' : '#999;') . '">' . (!empty($sibling_titles) ? esc_html($sibling_titles) : '❌ خالی') . '</td>';
                echo '<td style="padding:4px 8px;border:1px solid #ddd;text-align:center;">' . $count . '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';

        if (!empty($search_term)) {
            echo '<hr>';
            echo '<h3>🔎 تست جستجوی Sibling Titles برای عبارت: "' . esc_html($search_term) . '"</h3>';

            $matched_terms = $this->find_terms_by_sibling_title($search_term);

            echo '<p><strong>ترم‌های پیدا شده:</strong> ';
            if (!empty($matched_terms)) {
                echo implode(', ', $matched_terms);
                echo '<br><strong>نام ترم‌ها:</strong> ';
                $names = array();
                foreach ($matched_terms as $term_id) {
                    $term = get_term($term_id, 'service');
                    if ($term && !is_wp_error($term)) {
                        $names[] = $term->name . ' (ID: ' . $term_id . ')';
                    }
                }
                echo implode(' | ', $names);
            } else {
                echo '❌ هیچ ترمی پیدا نشد';
            }
            echo '</p>';

            if (!empty($city_id)) {
                echo '<hr>';
                $city_term = get_term($city_id, 'area');
                $city_name = (!is_wp_error($city_term) && $city_term) ? $city_term->name : '—';
                echo '<h3>📄 تست جستجوی محتوا با شهر (area term) ID: ' . $city_id . '</h3>';
                echo '<p><strong>نام شهر:</strong> ' . esc_html($city_name) . '</p>';
                $content_ids = $this->find_content_by_text_search($search_term, $city_id);
            } else {
                echo '<p style="color:#999;">💡 برای تست جستجوی محتوا، پارامتر <code>city_id</code> را نیز اضافه کنید: <code>&city_id=123</code></p>';
            }
        } else {
            echo '<hr>';
            echo '<p style="color:#999;">💡 برای تست جستجوی sibling titles، پارامتر <code>search</code> را اضافه کنید: <code>&search=پارگی لوله</code></p>';
            echo '<p style="color:#999;">💡 برای تست جستجوی محتوا، پارامتر <code>city_id</code> را نیز اضافه کنید: <code>&city_id=123</code></p>';
        }

        echo '<hr>';
        echo '<p style="color:#999;font-size:12px;">🔧 برای خروج از دیباگ، پارامتر <code>debug_sibling</code> را از URL حذف کنید.</p>';
        echo '</div>';
    }

    /**
     * ==========================================
     * DEBUG SEARCH PROCESS
     * ==========================================
     */
    public function debug_search_process() {
        if (!current_user_can('manage_options') || !isset($_GET['debug_search_process'])) {
            return;
        }

        $search_term = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : 'پارگی';
        $city_id = isset($_GET['city_id']) ? intval($_GET['city_id']) : 0;

        $city_term = get_term($city_id, 'area');
        $city_name = (!is_wp_error($city_term) && $city_term) ? $city_term->name : '—';

        echo '<div style="direction:rtl;text-align:right;background:#fff3cd;padding:20px;margin:20px;border:3px solid #ffc107;border-radius:8px;font-family:monospace;font-size:13px;max-height:1000px;overflow:auto;">';
        echo '<h2 style="color:#856404;">🔍 دیباگ فرآیند جستجو (v' . self::VERSION . ')</h2>';
        echo '<p><strong>عبارت جستجو:</strong> "' . esc_html($search_term) . '" | <strong>شهر (area term) ID:</strong> ' . $city_id . ' (' . esc_html($city_name) . ')</p>';
        echo '<hr>';

        echo '<h3>📄 ۱. همه پست‌های متصل به شهر (area term ID: ' . $city_id . ') از طریق tax_query</h3>';
        $all_posts = get_posts(array(
                'post_type' => 'content',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'tax_query' => array(
                        array(
                                'taxonomy' => 'area',
                                'field' => 'term_id',
                                'terms' => $city_id,
                                'operator' => 'IN',
                                'include_children' => true,
                        ),
                ),
        ));

        if (empty($all_posts)) {
            echo '<p style="color:red;font-size:16px;">❌ هیچ پستی برای این شهر پیدا نشد!</p>';
        } else {
            echo '<p style="color:green;">✅ ' . count($all_posts) . ' پست برای این شهر پیدا شد</p>';
            echo '<table style="width:100%;border-collapse:collapse;background:#fff;font-size:12px;margin-top:10px;">';
            echo '<thead><tr style="background:#856404;color:#fff;">';
            echo '<th style="padding:6px 8px;border:1px solid #ddd;">ID</th>';
            echo '<th style="padding:6px 8px;border:1px solid #ddd;">عنوان</th>';
            echo '<th style="padding:6px 8px;border:1px solid #ddd;">ترم‌های area متصل</th>';
            echo '<th style="padding:6px 8px;border:1px solid #ddd;">سرویس‌ها (taxonomy)</th>';
            echo '</tr></thead><tbody>';

            foreach ($all_posts as $post) {
                $area_terms = wp_get_post_terms($post->ID, 'area', array('fields' => 'all'));
                $area_names = !empty($area_terms) && !is_wp_error($area_terms)
                        ? implode(', ', array_map(function($t) {
                            $parent_info = '';
                            if ($t->parent > 0) {
                                $parent = get_term($t->parent, 'area');
                                if ($parent && !is_wp_error($parent)) {
                                    $parent_info = ' (parent: ' . $parent->name . ')';
                                }
                            }
                            return $t->name . $parent_info . ' [ID:' . $t->term_id . ']';
                        }, $area_terms))
                        : '❌ هیچ ترمی متصل نیست';

                $service_terms = wp_get_post_terms($post->ID, 'service', array('fields' => 'all'));
                $service_names = !empty($service_terms) && !is_wp_error($service_terms)
                        ? implode(', ', array_map(function($t) { return $t->name . ' (ID:' . $t->term_id . ')'; }, $service_terms))
                        : '❌ هیچ ترمی متصل نیست';

                echo '<tr style="border-bottom:1px solid #eee;">';
                echo '<td style="padding:4px 8px;border:1px solid #ddd;">' . $post->ID . '</td>';
                echo '<td style="padding:4px 8px;border:1px solid #ddd;font-weight:bold;">' . esc_html($post->post_title) . '</td>';
                echo '<td style="padding:4px 8px;border:1px solid #ddd;">' . $area_names . '</td>';
                echo '<td style="padding:4px 8px;border:1px solid #ddd;">' . $service_names . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        echo '<hr>';
        echo '<h3>🏷️ ۲. بررسی ترم‌های سرویس و تطابق با "' . esc_html($search_term) . '"</h3>';
        $all_terms = get_terms(array(
                'taxonomy' => 'service',
                'hide_empty' => false,
        ));

        echo '<table style="width:100%;border-collapse:collapse;background:#fff;font-size:12px;margin-top:10px;">';
        echo '<thead><tr style="background:#856404;color:#fff;">';
        echo '<th style="padding:6px 8px;border:1px solid #ddd;">Term ID</th>';
        echo '<th style="padding:6px 8px;border:1px solid #ddd;">نام ترم</th>';
        echo '<th style="padding:6px 8px;border:1px solid #ddd;">Sibling Titles</th>';
        echo '<th style="padding:6px 8px;border:1px solid #ddd;">تطابق</th>';
        echo '</tr></thead><tbody>';

        $matched_term_ids = array();
        foreach ($all_terms as $term) {
            $sibling_titles = get_term_meta($term->term_id, 'service_meta_sibling_titles', true);
            $matches = array();

            if ($this->text_matches($search_term, $term->name)) {
                $matches[] = '✅ نام ترم';
                $matched_term_ids[] = $term->term_id;
            }

            if (!empty($sibling_titles)) {
                $titles = preg_split('/[-–—]/u', $sibling_titles);
                $titles = array_map(function($title) {
                    return trim(preg_replace('/[\s\x{00A0}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}\x{FEFF}]+/u', ' ', $title));
                }, $titles);

                foreach ($titles as $title) {
                    if ($this->text_matches($search_term, $title)) {
                        $matches[] = '✅ sibling: "' . esc_html($title) . '"';
                        $matched_term_ids[] = $term->term_id;
                    }
                }
            }

            $match_status = !empty($matches) ? implode('<br>', $matches) : '❌ تطابق ندارد';
            $match_color = !empty($matches) ? 'green' : '#999';

            echo '<tr style="border-bottom:1px solid #eee;">';
            echo '<td style="padding:4px 8px;border:1px solid #ddd;">' . $term->term_id . '</td>';
            echo '<td style="padding:4px 8px;border:1px solid #ddd;font-weight:bold;">' . esc_html($term->name) . '</td>';
            echo '<td style="padding:4px 8px;border:1px solid #ddd;color:' . (!empty($sibling_titles) ? '#1854CC;font-weight:bold;' : '#999;') . '">' . (!empty($sibling_titles) ? esc_html($sibling_titles) : '❌ خالی') . '</td>';
            echo '<td style="padding:4px 8px;border:1px solid #ddd;color:' . $match_color . ';">' . $match_status . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        echo '<hr>';
        echo '<h3>🎯 ۳. نتیجه نهایی (با tax_query روی area و service)</h3>';
        $matched_term_ids = array_unique($matched_term_ids);

        if (empty($matched_term_ids)) {
            echo '<p style="color:red;font-size:16px;">❌ هیچ ترمی با عبارت "' . esc_html($search_term) . '" تطابق ندارد!</p>';
        } else {
            echo '<p style="color:green;font-size:16px;">✅ ترم‌های پیدا شده: ' . implode(', ', $matched_term_ids) . '</p>';
            echo '<h4>📄 پست‌های مرتبط با این ترم‌ها و شهر (از طریق tax_query):</h4>';

            $related_posts = get_posts(array(
                    'post_type' => 'content',
                    'post_status' => 'publish',
                    'posts_per_page' => -1,
                    'tax_query' => array(
                            'relation' => 'AND',
                            array(
                                    'taxonomy' => 'area',
                                    'field' => 'term_id',
                                    'terms' => $city_id,
                                    'operator' => 'IN',
                                    'include_children' => true,
                            ),
                            array(
                                    'taxonomy' => 'service',
                                    'field' => 'term_id',
                                    'terms' => $matched_term_ids,
                                    'operator' => 'IN',
                            ),
                    ),
            ));

            if (empty($related_posts)) {
                echo '<p style="color:red;font-size:16px;">❌ هیچ پستی با این ترم‌ها و شهر پیدا نشد!</p>';
            } else {
                echo '<p style="color:green;font-size:16px;">✅ ' . count($related_posts) . ' پست پیدا شد:</p>';
                echo '<ul>';
                foreach ($related_posts as $post) {
                    echo '<li><strong>' . esc_html($post->post_title) . '</strong> (ID: ' . $post->ID . ')</li>';
                }
                echo '</ul>';
                echo '<p style="color:green;font-size:14px;">✅ جستجو باید این پست‌ها را نشان دهد!</p>';
            }
        }

        echo '<hr>';
        echo '<p style="color:#999;font-size:12px;">🔧 برای خروج از دیباگ، پارامتر <code>debug_search_process</code> را از URL حذف کنید.</p>';
        echo '</div>';
    }
}

// Initialize
Fanikara_Hyper_Search::get_instance();