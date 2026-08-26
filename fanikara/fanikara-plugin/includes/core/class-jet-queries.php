<?php
/**
 * Fanikara JetEngine Queries Class
 *
 * Manages custom JetEngine queries for fanikar listings with priority system.
 * Uses behavior detection instead of IDs for maximum compatibility.
 * Supports context recovery during AJAX filtering via HTTP_REFERER.
 *
 * @package Fanikara
 * @version 3.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Fanikara_Jet_Queries {

    private static $instance = null;
    private $area_taxonomy    = 'area';
    private $service_taxonomy = 'service';

    /**
     * Store debug info for each query we process
     */
    private static $debug_queries = [];

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
        // ==========================================
        // MAIN QUERY MODIFICATION
        // ==========================================
        add_filter('posts_clauses', [$this, 'modify_query_clauses'], 10, 2);

        // ==========================================
        // HANDLE TAXONOMY ARCHIVES
        // ==========================================
        add_action('pre_get_posts', [$this, 'handle_taxonomy_archives'], 10);

        // ==========================================
        // ADD QUERY VARS
        // ==========================================
        add_filter('query_vars', [$this, 'add_query_vars']);

        // ==========================================
        // DEBUG: FOOTER OUTPUT
        // ==========================================
        add_action('wp_footer', [$this, 'debug_output']);
    }

    // ==========================================
    // HELPERS
    // ==========================================

    private function is_debug_mode() {
        return isset($_GET['debug_jet_queries']) && current_user_can('manage_options');
    }

    private function is_test_empty() {
        return isset($_GET['fnk_test']) && $_GET['fnk_test'] === 'empty' && current_user_can('manage_options');
    }

    /**
     * Detect if this is a query we should modify
     */
    private function detect_our_query($query, $clauses) {
        $post_type = $query->get('post_type');
        $is_fanikar = ($post_type === 'fanikar' || (is_array($post_type) && in_array('fanikar', $post_type)));

        if (!$is_fanikar) {
            return false;
        }

        $has_work_status_true = (strpos($clauses['where'], "fnk_usr_cpt_work_status") !== false && strpos($clauses['where'], "true") !== false);

        if (!$has_work_status_true) {
            return 'active_listing (needs modification) ✅';
        }

        return 'out_of_service_listing (skip) ⏭️';
    }

    private function extract_debug_vars($query) {
        return [
            'post_type'             => $query->get('post_type'),
            'posts_per_page'        => $query->get('posts_per_page'),
            'is_main_query'         => $query->is_main_query() ? 'YES' : 'NO',
        ];
    }

    private function build_sql($clauses) {
        $sql  = "SELECT {$clauses['distinct']} ";
        $sql .= "{$clauses['fields']} ";
        $sql .= "FROM {$clauses['from']} ";
        $sql .= "{$clauses['join']} ";
        $sql .= "WHERE 1=1 {$clauses['where']} ";
        if (!empty($clauses['groupby'])) {
            $sql .= "GROUP BY {$clauses['groupby']} ";
        }
        $sql .= "ORDER BY {$clauses['orderby']} ";
        if (!empty($clauses['limits'])) {
            $sql .= "LIMIT {$clauses['limits']} ";
        }
        return $sql;
    }

    /**
     * ==========================================
     * CONTEXT RECOVERY (AJAX Support)
     * ==========================================
     * Finds the Money Page service term ID even during AJAX calls (like JetSmartFilters)
     */
    private function get_current_service_term_id(&$debug_info) {
        // Method 1: Direct post context (Initial page load)
        $current_post_id   = get_queried_object_id();
        $current_post_type = get_post_type($current_post_id);

        if ($current_post_id && $current_post_type === 'content') {
            $service_term_id = get_post_meta($current_post_id, 'fnk_dev_service_term_id', true);
            if (!empty($service_term_id)) {
                $debug_info['context_method'] = 'Direct Queried Object (ID: ' . $current_post_id . ')';
                return $service_term_id;
            }
        }

        // Method 2: HTTP_REFERER Context (AJAX context from filters like JetSmartFilters)
        if (isset($_SERVER['HTTP_REFERER']) && !empty($_SERVER['HTTP_REFERER'])) {
            $referer_path = trim(parse_url($_SERVER['HTTP_REFERER'], PHP_URL_PATH), '/');
            $path_parts = explode('/', $referer_path);

            // URL structure: /{city}/{slug}/
            if (count($path_parts) === 2) {
                $city_slug = $path_parts[0];
                $slug = $path_parts[1];

                $money_page_id = $this->find_money_page_by_slug($slug, $city_slug);
                if ($money_page_id) {
                    $service_term_id = get_post_meta($money_page_id, 'fnk_dev_service_term_id', true);
                    if (!empty($service_term_id)) {
                        $debug_info['context_method'] = 'HTTP_REFERER Recovery (Referer: ' . esc_url($_SERVER['HTTP_REFERER']) . ')';
                        return $service_term_id;
                    }
                }
            }
        }

        $debug_info['context_method'] = 'Failed (No Context Found)';
        return false;
    }

    /**
     * Find a money page by its slug AND city slug (Required for AJAX context)
     */
    private function find_money_page_by_slug($slug, $city_slug = '') {
        global $wpdb;

        $city_term_id = 0;
        if (!empty($city_slug)) {
            $term = get_term_by('slug', $city_slug, $this->area_taxonomy);
            if ($term && !is_wp_error($term)) {
                $city_term_id = (int) $term->term_id;
            }
        }

        $sql = "SELECT p.ID 
        FROM {$wpdb->posts} p
        LEFT JOIN {$wpdb->postmeta} pm_slug ON p.ID = pm_slug.post_id AND pm_slug.meta_key = 'fnk_dev_custom_slug'";

        if ($city_term_id > 0) {
            $sql .= " INNER JOIN {$wpdb->postmeta} pm_city ON p.ID = pm_city.post_id AND pm_city.meta_key = 'fnk_dev_area_term_id'";
        }

        $sql .= " WHERE p.post_type = 'content' 
        AND p.post_status = 'publish'
        AND (p.post_name = %s OR pm_slug.meta_value = %s)";

        if ($city_term_id > 0) {
            $sql .= " AND pm_city.meta_value = %d";
        }

        $sql .= " LIMIT 1";

        if ($city_term_id > 0) {
            $money_page = $wpdb->get_row($wpdb->prepare($sql, $slug, $slug, $city_term_id));
        } else {
            $money_page = $wpdb->get_row($wpdb->prepare($sql, $slug, $slug));
        }

        return $money_page ? (int) $money_page->ID : false;
    }

    /**
     * ==========================================
     * MAIN: MODIFY QUERY CLAUSES
     * ==========================================
     */
    public function modify_query_clauses($clauses, $query) {
        global $wpdb;

        if (is_admin()) {
            return $clauses;
        }

        // ==========================================
        // STEP 1: Detect
        // ==========================================
        $match_method = $this->detect_our_query($query, $clauses);

        // Collect debug info for ALL fanikar queries
        if ($this->is_debug_mode() && $match_method) {
            self::$debug_queries[] = [
                'status'       => 'DETECTED',
                'match_method' => $match_method,
                'query_vars'   => $this->extract_debug_vars($query),
                'sql_before'   => $this->build_sql($clauses),
            ];
        }

        $should_modify = ($match_method === 'active_listing (needs modification) ✅');

        if (!$should_modify) {
            if ($this->is_debug_mode() && $match_method === 'out_of_service_listing (skip) ⏭️') {
                $last = count(self::$debug_queries) - 1;
                if ($last >= 0) {
                    self::$debug_queries[$last]['status'] = 'SKIPPED (out of service) ⏭️';
                }
            }
            return $clauses;
        }

        // ==========================================
        // STEP 2: TEST MODE
        // ==========================================
        if ($this->is_test_empty()) {
            $clauses['where'] .= " AND 1=0 ";

            if ($this->is_debug_mode()) {
                $last = count(self::$debug_queries) - 1;
                if ($last >= 0) {
                    self::$debug_queries[$last]['status']    = 'TEST EMPTY 🔴';
                    self::$debug_queries[$last]['sql_after'] = $this->build_sql($clauses);
                }
            }
            return $clauses;
        }

        // ==========================================
        // STEP 3: Check money page context (WITH AJAX SUPPORT)
        // ==========================================
        $debug_info = [];
        $service_term_id = $this->get_current_service_term_id($debug_info);

        if (empty($service_term_id)) {
            if ($this->is_debug_mode()) {
                $last = count(self::$debug_queries) - 1;
                if ($last >= 0) {
                    self::$debug_queries[$last]['status'] = 'SKIPPED (Context lost on AJAX & Referer failed)';
                    self::$debug_queries[$last]['debug_info'] = $debug_info;
                }
            }
            return $clauses;
        }

        // ==========================================
        // STEP 4: Add JOINs
        // ==========================================
        $score_meta_key = 'fnk_dev_service_score_' . $service_term_id;
        $work_meta_key  = 'fnk_usr_cpt_work_status';

        if (strpos($clauses['join'], 'pm_priority') === false) {
            $clauses['join'] .= $wpdb->prepare(
                " LEFT JOIN {$wpdb->postmeta} pm_priority ON {$wpdb->posts}.ID = pm_priority.post_id AND pm_priority.meta_key = %s ",
                $score_meta_key
            );
        }

        if (strpos($clauses['join'], 'pm_work_status') === false) {
            $clauses['join'] .= $wpdb->prepare(
                " LEFT JOIN {$wpdb->postmeta} pm_work_status ON {$wpdb->posts}.ID = pm_work_status.post_id AND pm_work_status.meta_key = %s ",
                $work_meta_key
            );
        }

        // ==========================================
        // STEP 5: WHERE — Filter work status
        // ==========================================
        $clauses['where'] .= " AND (pm_work_status.meta_value IS NULL OR pm_work_status.meta_value = 'false' OR pm_work_status.meta_value = '0') ";

        // ==========================================
        // STEP 6: ORDER BY
        // ==========================================
        $clauses['orderby'] = "(COALESCE(CAST(pm_priority.meta_value AS UNSIGNED), 0) * 0.7 + RAND() * 1000 * 0.3) DESC";

        // ==========================================
        // STEP 7: Update debug info
        // ==========================================
        if ($this->is_debug_mode()) {
            $last = count(self::$debug_queries) - 1;
            if ($last >= 0) {
                self::$debug_queries[$last]['status']          = 'MODIFIED ✅';
                self::$debug_queries[$last]['context_method']  = $debug_info['context_method'];
                self::$debug_queries[$last]['service_term_id'] = $service_term_id;
                self::$debug_queries[$last]['score_meta_key']  = $score_meta_key;
                self::$debug_queries[$last]['work_meta_key']   = $work_meta_key;
                self::$debug_queries[$last]['sql_after']       = $this->build_sql($clauses);
            }
        }

        return $clauses;
    }

    /**
     * ==========================================
     * HANDLE TAXONOMY ARCHIVES
     * ==========================================
     */
    public function handle_taxonomy_archives($query) {
        if (is_admin() || !$query->is_main_query()) {
            return;
        }

        if (is_tax($this->area_taxonomy)) {
            $query->set('post_type', 'fanikar');
            $query->set('post_status', 'publish');
        }

        if (is_tax($this->service_taxonomy)) {
            $query->set('post_type', 'fanikar');
            $query->set('post_status', 'publish');
        }
    }

    /**
     * ==========================================
     * ADD QUERY VARS
     * ==========================================
     */
    public function add_query_vars($vars) {
        $vars[] = 'fnk_priority_ids';
        return $vars;
    }

    /**
     * ==========================================
     * DEBUG: FOOTER OUTPUT
     * ==========================================
     */
    public function debug_output() {
        if (!$this->is_debug_mode()) {
            return;
        }

        $current_id = get_queried_object_id();
        $post_type  = get_post_type($current_id);
        $test_mode  = $this->is_test_empty();

        echo '<div style="direction:rtl;background:#1a1a2e;color:#eee;padding:24px;border:3px solid #e94560;border-radius:12px;margin:20px;font-family:Vazir,Tahoma,sans-serif;font-size:13px;line-height:1.8;max-height:90vh;overflow:auto;">';

        echo '<h2 style="color:#e94560;margin:0 0 16px;">🔍 دیباگ Jet Queries v3.1 (AJAX Context Support)</h2>';

        if ($test_mode) {
            echo '<div style="background:#e94560;color:white;padding:10px 16px;border-radius:8px;margin-bottom:16px;font-weight:bold;">🔴 حالت تست فعال</div>';
        }

        echo '<h3 style="color:#0f3460;background:#eee;padding:8px 12px;border-radius:6px;">📄 اطلاعات صفحه</h3>';
        echo '<table style="width:100%;border-collapse:collapse;margin-bottom:20px;">';
        $this->debug_row('Current Post ID', $current_id ?: '—');
        $this->debug_row('Post Type', $post_type ?: '—');
        $this->debug_row('HTTP_REFERER', isset($_SERVER['HTTP_REFERER']) ? esc_url($_SERVER['HTTP_REFERER']) : 'N/A');
        echo '</table>';

        echo '<h3 style="color:#0f3460;background:#eee;padding:8px 12px;border-radius:6px;">🛠️ کوئری‌های شناسایی‌شده (' . count(self::$debug_queries) . ')</h3>';

        if (empty(self::$debug_queries)) {
            echo '<div style="background:#e94560;color:white;padding:12px;border-radius:8px;">❌ هیچ کوئری فنی‌کار شناسایی نشد!</div>';
        } else {
            foreach (self::$debug_queries as $i => $dq) {
                $status_color = '#16c79a';
                if (strpos($dq['status'], 'SKIPPED') !== false) $status_color = '#f5a623';
                if (strpos($dq['status'], 'TEST') !== false)   $status_color = '#e94560';

                echo '<div style="background:#16213e;border:1px solid #333;border-radius:8px;padding:16px;margin-bottom:12px;">';
                echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">';
                echo '<strong style="font-size:15px;">کوئری #' . ($i + 1) . '</strong>';
                echo '<span style="background:' . $status_color . ';color:#000;padding:4px 12px;border-radius:20px;font-weight:bold;font-size:12px;">' . $dq['status'] . '</span>';
                echo '</div>';

                echo '<table style="width:100%;border-collapse:collapse;margin-bottom:10px;">';
                $this->debug_row('روش شناسایی کوئری', $dq['match_method']);
                if (isset($dq['context_method'])) {
                    $ctx_color = (strpos($dq['context_method'], 'Failed') !== false) ? '#e94560' : '#16c79a';
                    $this->debug_row('روش یافتن کانتکست', $dq['context_method'], $ctx_color);
                }
                if (isset($dq['service_term_id'])) {
                    $this->debug_row('Service Term ID', $dq['service_term_id']);
                }
                echo '</table>';

                if (isset($dq['sql_before'])) {
                    echo '<details style="margin-bottom:8px;">';
                    echo '<summary style="cursor:pointer;color:#f5a623;font-weight:bold;">🔧 SQL Before</summary>';
                    echo '<pre style="background:#0f3460;padding:12px;border-radius:6px;overflow:auto;font-size:11px;color:#a8d8ea;white-space:pre-wrap;">' . esc_html($dq['sql_before']) . '</pre>';
                    echo '</details>';
                }

                if (isset($dq['sql_after'])) {
                    echo '<details style="margin-bottom:8px;">';
                    echo '<summary style="cursor:pointer;color:#e94560;font-weight:bold;">🔧 SQL After</summary>';
                    echo '<pre style="background:#0f3460;padding:12px;border-radius:6px;overflow:auto;font-size:11px;color:#a8d8ea;white-space:pre-wrap;">' . esc_html($dq['sql_after']) . '</pre>';
                    echo '</details>';
                }

                echo '</div>';
            }
        }
        echo '</div>';
    }

    private function debug_row($label, $value, $color = '#eee') {
        echo '<tr>';
        echo '<td style="padding:6px 10px;border:1px solid #333;font-weight:bold;width:220px;">' . $label . '</td>';
        echo '<td style="padding:6px 10px;border:1px solid #333;color:' . $color . ';">' . $value . '</td>';
        echo '</tr>';
    }
}