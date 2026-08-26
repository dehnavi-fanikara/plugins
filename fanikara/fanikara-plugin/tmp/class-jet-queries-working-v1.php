<?php
/**
 * Fanikara JetEngine Queries Class
 *
 * Manages custom JetEngine queries for fanikar listings with priority system.
 * - Filters out busy fanikars (work_status = true)
 * - Applies priority-based sorting (70% score + 30% random)
 *
 * TEST MODE: Add ?fnk_test=empty to URL to force empty results
 * DEBUG MODE: Add ?debug_jet_queries=1 to URL to see debug info
 *
 * @package Fanikara
 * @version 3.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Fanikara_Jet_Queries {

    private static $instance = null;
    private $area_taxonomy    = 'area';
    private $service_taxonomy = 'service';

    const QUERY_ID = 'fnk_mp_listing';

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
     * Returns the detection method or false
     */
    private function detect_our_query($query) {
        // Method 1: JetEngine query ID
        $query_id = $query->get('jet_engine_query_id');
        if ($query_id === self::QUERY_ID) {
            return 'jet_engine_query_id ✅';
        }

        if (isset($query->query['jet_engine_query_id']) && $query->query['jet_engine_query_id'] === self::QUERY_ID) {
            return 'jet_engine_query_id (raw) ✅';
        }

        // Method 2: post_type is fanikar
        $post_type = $query->get('post_type');
        if ($post_type === 'fanikar' || (is_array($post_type) && in_array('fanikar', $post_type))) {
            return 'fanikar_post_type';
        }

        // Method 3: meta query with fnk_dev_ prefix
        $meta_query = $query->get('meta_query');
        if (is_array($meta_query)) {
            foreach ($meta_query as $mq) {
                if (isset($mq['key']) && strpos($mq['key'], 'fnk_dev_') !== false) {
                    return 'fnk_dev_meta';
                }
            }
        }

        return false;
    }

    private function extract_debug_vars($query) {
        return [
            'post_type'           => $query->get('post_type'),
            'jet_engine_query_id' => $query->get('jet_engine_query_id'),
            'posts_per_page'      => $query->get('posts_per_page'),
            'meta_query'          => $query->get('meta_query'),
            'tax_query'           => $query->get('tax_query'),
            'is_main_query'       => $query->is_main_query() ? 'YES' : 'NO',
            'orderby'             => $query->get('orderby'),
            'order'               => $query->get('order'),
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
     * MAIN: MODIFY QUERY CLAUSES
     * ==========================================
     * Handles:
     * - TEST MODE: Force empty results
     * - WORK STATUS: Exclude busy fanikars
     * - PRIORITY SORTING: Custom JOIN + ORDER BY
     *
     * @param array    $clauses The query clauses.
     * @param WP_Query $query   The WP_Query instance.
     * @return array Modified clauses.
     */
    public function modify_query_clauses($clauses, $query) {
        global $wpdb;

        if (is_admin()) {
            return $clauses;
        }

        // ==========================================
        // STEP 1: Detect
        // ==========================================
        $match_method = $this->detect_our_query($query);

        // Collect debug info for detected queries
        if ($this->is_debug_mode() && $match_method) {
            self::$debug_queries[] = [
                'status'       => 'DETECTED',
                'match_method' => $match_method,
                'query_vars'   => $this->extract_debug_vars($query),
                'sql_before'   => $this->build_sql($clauses),
            ];
        }

        if (!$match_method) {
            return $clauses;
        }

        // ==========================================
        // STEP 2: TEST MODE — Force empty results
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
        // STEP 3: Check money page context
        // ==========================================
        $current_post_id   = get_queried_object_id();
        $current_post_type = get_post_type($current_post_id);

        if (!$current_post_id || $current_post_type !== 'content') {
            if ($this->is_debug_mode()) {
                $last = count(self::$debug_queries) - 1;
                if ($last >= 0) {
                    self::$debug_queries[$last]['status']            = 'SKIPPED (not content page)';
                    self::$debug_queries[$last]['current_post_id']   = $current_post_id;
                    self::$debug_queries[$last]['current_post_type'] = $current_post_type;
                }
            }
            return $clauses;
        }

        // Get service term ID from money page
        $service_term_id = get_post_meta($current_post_id, 'fnk_dev_service_term_id', true);
        if (empty($service_term_id)) {
            if ($this->is_debug_mode()) {
                $last = count(self::$debug_queries) - 1;
                if ($last >= 0) {
                    self::$debug_queries[$last]['status']          = 'SKIPPED (no service_term_id)';
                    self::$debug_queries[$last]['current_post_id'] = $current_post_id;
                }
            }
            return $clauses;
        }

        // ==========================================
        // STEP 4: Add JOINs
        // ==========================================
        $score_meta_key = 'fnk_dev_service_score_' . $service_term_id;
        $work_meta_key  = 'fnk_usr_cpt_work_status';

        // JOIN for priority score
        if (strpos($clauses['join'], 'pm_priority') === false) {
            $clauses['join'] .= $wpdb->prepare(
                " LEFT JOIN {$wpdb->postmeta} pm_priority ON {$wpdb->posts}.ID = pm_priority.post_id AND pm_priority.meta_key = %s ",
                $score_meta_key
            );
        }

        // JOIN for work status
        if (strpos($clauses['join'], 'pm_work_status') === false) {
            $clauses['join'] .= $wpdb->prepare(
                " LEFT JOIN {$wpdb->postmeta} pm_work_status ON {$wpdb->posts}.ID = pm_work_status.post_id AND pm_work_status.meta_key = %s ",
                $work_meta_key
            );
        }

        // ==========================================
        // STEP 5: WHERE — Exclude busy fanikars
        // work_status = NULL or work_status = 'false' → OK
        // work_status = 'true' or '1' → EXCLUDE
        // ==========================================
        $clauses['where'] .= " AND (pm_work_status.meta_value IS NULL OR pm_work_status.meta_value = 'false' OR pm_work_status.meta_value = '0') ";

        // ==========================================
        // STEP 6: ORDER BY — Priority + Randomness
        // ==========================================
        $clauses['orderby'] = "(COALESCE(CAST(pm_priority.meta_value AS UNSIGNED), 0) * 0.7 + RAND() * 1000 * 0.3) DESC";

        // ==========================================
        // STEP 7: Update debug info
        // ==========================================
        if ($this->is_debug_mode()) {
            $last = count(self::$debug_queries) - 1;
            if ($last >= 0) {
                self::$debug_queries[$last]['status']          = 'MODIFIED ✅';
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

        // Header
        echo '<h2 style="color:#e94560;margin:0 0 16px;">🔍 دیباگ Jet Queries v3</h2>';
        if ($test_mode) {
            echo '<div style="background:#e94560;color:white;padding:10px 16px;border-radius:8px;margin-bottom:16px;font-weight:bold;">🔴 حالت تست فعال — نتایج باید خالی باشند (fnk_test=empty)</div>';
        }

        // Page Info
        echo '<h3 style="color:#0f3460;background:#eee;padding:8px 12px;border-radius:6px;">📄 اطلاعات صفحه</h3>';
        echo '<table style="width:100%;border-collapse:collapse;margin-bottom:20px;">';
        $this->debug_row('Current Post ID', $current_id ?: '—');
        $this->debug_row('Post Type', $post_type ?: '—');
        $this->debug_row('Query ID (Target)', self::QUERY_ID);
        $this->debug_row('Test Mode', $test_mode ? '🔴 فعال' : 'غیرفعال');
        $this->debug_row('URL', $_SERVER['REQUEST_URI']);
        echo '</table>';

        // Money Page Meta
        echo '<h3 style="color:#0f3460;background:#eee;padding:8px 12px;border-radius:6px;">📋 متاهای مانی‌پیج</h3>';
        $meta_keys = [
            'fnk_dev_area_term_id',
            'fnk_dev_area_term_name',
            'fnk_dev_service_term_id',
            'fnk_dev_service_term_name',
        ];
        echo '<table style="width:100%;border-collapse:collapse;margin-bottom:20px;">';
        foreach ($meta_keys as $key) {
            $value   = get_post_meta($current_id, $key, true);
            $display = !empty($value) ? $value : '❌ خالی';
            $color   = !empty($value) ? '#16c79a' : '#e94560';
            $this->debug_row($key, $display, $color);
        }
        echo '</table>';

        // Detected Queries
        echo '<h3 style="color:#0f3460;background:#eee;padding:8px 12px;border-radius:6px;">🛠️ کوئری‌های شناسایی‌شده (' . count(self::$debug_queries) . ')</h3>';

        if (empty(self::$debug_queries)) {
            echo '<div style="background:#e94560;color:white;padding:12px;border-radius:8px;">❌ هیچ کوئری fanikar شناسایی نشد!</div>';
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
                $this->debug_row('روش شناسایی', $dq['match_method']);
                if (isset($dq['current_post_id'])) {
                    $this->debug_row('Current Post ID', $dq['current_post_id'] ?: '—');
                }
                if (isset($dq['current_post_type'])) {
                    $this->debug_row('Current Post Type', $dq['current_post_type'] ?: '—');
                }
                if (isset($dq['service_term_id'])) {
                    $this->debug_row('Service Term ID', $dq['service_term_id']);
                }
                if (isset($dq['score_meta_key'])) {
                    $this->debug_row('Score Meta Key', $dq['score_meta_key']);
                }
                if (isset($dq['work_meta_key'])) {
                    $this->debug_row('Work Status Meta Key', $dq['work_meta_key']);
                }
                echo '</table>';

                // Query vars
                echo '<details style="margin-bottom:8px;">';
                echo '<summary style="cursor:pointer;color:#16c79a;font-weight:bold;">📋 Query Vars</summary>';
                echo '<pre style="background:#0f3460;padding:12px;border-radius:6px;overflow:auto;font-size:11px;color:#a8d8ea;">' . esc_html(print_r($dq['query_vars'], true)) . '</pre>';
                echo '</details>';

                // SQL before
                if (isset($dq['sql_before'])) {
                    echo '<details style="margin-bottom:8px;">';
                    echo '<summary style="cursor:pointer;color:#f5a623;font-weight:bold;">🔧 SQL Before</summary>';
                    echo '<pre style="background:#0f3460;padding:12px;border-radius:6px;overflow:auto;font-size:11px;color:#a8d8ea;white-space:pre-wrap;">' . esc_html($dq['sql_before']) . '</pre>';
                    echo '</details>';
                }

                // SQL after
                if (isset($dq['sql_after'])) {
                    echo '<details style="margin-bottom:8px;">';
                    echo '<summary style="cursor:pointer;color:#e94560;font-weight:bold;">🔧 SQL After</summary>';
                    echo '<pre style="background:#0f3460;padding:12px;border-radius:6px;overflow:auto;font-size:11px;color:#a8d8ea;white-space:pre-wrap;">' . esc_html($dq['sql_after']) . '</pre>';
                    echo '</details>';
                }

                echo '</div>';
            }
        }

        // Priority Scores
        if ($current_id && $post_type === 'content') {
            echo '<h3 style="color:#0f3460;background:#eee;padding:8px 12px;border-radius:6px;">📊 امتیازات اولویت (با فیلتر work_status)</h3>';
            $priority_data = $this->debug_priority_scores($current_id);
            if (!empty($priority_data)) {
                echo '<table style="width:100%;border-collapse:collapse;font-size:12px;">';
                echo '<thead><tr style="background:#16213e;">';
                echo '<th style="padding:8px;border:1px solid #333;text-align:right;">ID</th>';
                echo '<th style="padding:8px;border:1px solid #333;text-align:right;">Title</th>';
                echo '<th style="padding:8px;border:1px solid #333;text-align:right;">Score</th>';
                echo '<th style="padding:8px;border:1px solid #333;text-align:right;">Work Status</th>';
                echo '</tr></thead><tbody>';
                foreach ($priority_data as $row) {
                    $ws_color = empty($row->work_status) || $row->work_status === 'false' ? '#16c79a' : '#e94560';
                    $ws_label = empty($row->work_status) ? 'NULL (آزاد)' : $row->work_status;
                    echo '<tr>';
                    echo '<td style="padding:6px;border:1px solid #333;">' . $row->ID . '</td>';
                    echo '<td style="padding:6px;border:1px solid #333;">' . esc_html($row->post_title) . '</td>';
                    echo '<td style="padding:6px;border:1px solid #333;font-weight:bold;color:#16c79a;">' . $row->priority_score . '</td>';
                    echo '<td style="padding:6px;border:1px solid #333;color:' . $ws_color . ';">' . $ws_label . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
            } else {
                echo '<p style="color:#e94560;">❌ هیچ داده‌ای یافت نشد!</p>';
            }
        }

        // Instructions
        echo '<hr style="border-color:#333;margin:20px 0;">';
        echo '<div style="color:#888;font-size:12px;">';
        echo '<p>🔧 <strong>نحوه تست:</strong></p>';
        echo '<ul style="margin:0;padding-right:20px;">';
        echo '<li><code>?debug_jet_queries=1</code> — نمایش دیباگ</li>';
        echo '<li><code>?fnk_test=empty</code> — اجبار به نتایج خالی</li>';
        echo '<li><code>?debug_jet_queries=1&fnk_test=empty</code> — هر دو با هم</li>';
        echo '</ul>';
        echo '</div>';

        echo '</div>';
    }

    /**
     * Helper: Output a debug table row
     */
    private function debug_row($label, $value, $color = '#eee') {
        echo '<tr>';
        echo '<td style="padding:6px 10px;border:1px solid #333;font-weight:bold;width:220px;">' . $label . '</td>';
        echo '<td style="padding:6px 10px;border:1px solid #333;color:' . $color . ';">' . $value . '</td>';
        echo '</tr>';
    }

    /**
     * ==========================================
     * DEBUG: GET PRIORITY SCORES
     * Updated with work_status filter
     * ==========================================
     */
    private function debug_priority_scores($money_page_id) {
        global $wpdb;

        $sql = $wpdb->prepare("
        SELECT 
            p.ID,
            p.post_title,
            COALESCE(CAST(pm_score.meta_value AS UNSIGNED), 0) as priority_score,
            pm_work_status.meta_value as work_status
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->term_relationships} tr_area ON p.ID = tr_area.object_id
        INNER JOIN {$wpdb->term_taxonomy} tt_area ON tr_area.term_taxonomy_id = tt_area.term_taxonomy_id
        INNER JOIN {$wpdb->term_relationships} tr_service ON p.ID = tr_service.object_id
        INNER JOIN {$wpdb->term_taxonomy} tt_service ON tr_service.term_taxonomy_id = tt_service.term_taxonomy_id
        INNER JOIN {$wpdb->postmeta} pm_area ON pm_area.post_id = %d 
            AND pm_area.meta_key = 'fnk_dev_area_term_id'
        INNER JOIN {$wpdb->postmeta} pm_service ON pm_service.post_id = %d 
            AND pm_service.meta_key = 'fnk_dev_service_term_id'
        LEFT JOIN {$wpdb->postmeta} pm_score ON p.ID = pm_score.post_id 
            AND pm_score.meta_key = CONCAT('fnk_dev_service_score_', pm_service.meta_value)
        LEFT JOIN {$wpdb->postmeta} pm_work_status ON p.ID = pm_work_status.post_id 
            AND pm_work_status.meta_key = 'fnk_usr_cpt_work_status'
        WHERE p.post_type = 'fanikar'
        AND p.post_status = 'publish'
        AND tt_area.taxonomy = 'area'
        AND tt_area.term_id = pm_area.meta_value
        AND tt_service.taxonomy = 'service'
        AND tt_service.term_id = pm_service.meta_value
        AND (pm_work_status.meta_value IS NULL OR pm_work_status.meta_value = 'false' OR pm_work_status.meta_value = '0')
        ORDER BY priority_score DESC
    ", $money_page_id, $money_page_id);

        return $wpdb->get_results($sql);
    }
}