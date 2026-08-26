<?php
/**
 * Fanikara Debugger Class
 *
 * Centralized debug system for all Fanikara modules.
 * Only accessible for users with manage_options capability.
 *
 * ?d=true&type=meta&pid=123
 * ?d=true&type=content&pid=123
 * ?d=true&type=fanikar&pid=123
 * ?d=true&type=term&tid=123
 * ?d=true&type=permalinks&pid=123
 * ?d=true&type=all&pid=123
 *
 * @package Fanikara
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Fanikara_Debugger {

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
        add_action('wp', [$this, 'handle_debug_requests']);
        add_action('admin_init', [$this, 'handle_admin_debug_requests']);
    }

    /**
     * Handle debug requests on frontend
     */
    public function handle_debug_requests() {
        if (!isset($_GET['d']) || $_GET['d'] !== 'true') {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die('شما دسترسی به دیباگ ندارید.');
        }

        $type = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : 'meta';
        $post_id = isset($_GET['pid']) ? intval($_GET['pid']) : 0;
        $term_id = isset($_GET['tid']) ? intval($_GET['tid']) : 0;

        switch ($type) {
            case 'meta':
                $this->debug_post_meta($post_id);
                break;

            case 'content':
                $this->debug_content_post($post_id);
                break;

            case 'fanikar':
                $this->debug_fanikar_post($post_id);
                break;

            case 'term':
                $this->debug_term($term_id);
                break;

            case 'permalinks':
                $this->debug_permalinks($post_id);
                break;

            case 'jetengine':
                $this->debug_jetengine_hooks();
                break;

            case 'all':
                $this->debug_all($post_id);
                break;

            default:
                echo '<div style="direction:rtl;background:#fff3cd;padding:20px;border:2px solid #ffc107;border-radius:8px;">';
                echo '<h3 style="color:#856404;">⚠️ نوع دیباگ نامعتبر</h3>';
                echo '<p>انواع معتبر: <code>meta</code>, <code>content</code>, <code>fanikar</code>, <code>term</code>, <code>permalinks</code>, <code>all</code></p>';
                echo '<p><strong>مثال:</strong> <code>?d=true&type=content&pid=123</code></p>';
                echo '</div>';
                break;
        }

        wp_die();
    }

    /**
     * Handle debug requests in admin
     */
    public function handle_admin_debug_requests() {
        if (!isset($_GET['fnk_debug']) || $_GET['fnk_debug'] !== 'true') {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        add_action('admin_notices', [$this, 'show_admin_debug_notice']);
    }

    /**
     * Show admin debug notice
     */
    public function show_admin_debug_notice() {
        echo '<div class="notice notice-info is-dismissible">';
        echo '<p><strong>🔍 دیباگ فنی‌کارا فعال است</strong></p>';
        echo '<p>برای مشاهده دیباگ، از پارامترهای زیر در URL استفاده کنید:</p>';
        echo '<ul style="direction:rtl;text-align:right;padding-right:20px;">';
        echo '<li><code>?d=true&type=meta&pid=123</code> - نمایش متاهای یک پست</li>';
        echo '<li><code>?d=true&type=content&pid=123</code> - دیباگ کامل مانی‌پیج</li>';
        echo '<li><code>?d=true&type=fanikar&pid=123</code> - دیباگ کامل فنی‌کار</li>';
        echo '<li><code>?d=true&type=term&tid=123</code> - دیباگ ترم</li>';
        echo '<li><code>?d=true&type=permalinks&pid=123</code> - دیباگ پرmalink</li>';
        echo '<li><code>?d=true&type=all&pid=123</code> - دیباگ همه چیز</li>';
        echo '</ul>';
        echo '</div>';
    }

    // ==========================================
    // DEBUG: POST META
    // ==========================================

    /**
     * Debug post meta data
     */
    private function debug_post_meta($post_id) {
        if (!$post_id) {
            $this->show_error('لطفاً یک post_id وارد کنید: <code>?d=true&type=meta&pid=123</code>');
            return;
        }

        $post = get_post($post_id);
        if (!$post) {
            $this->show_error('پستی با این ID پیدا نشد: ' . $post_id);
            return;
        }

        echo $this->render_header('دیباگ متاهای پست', $post);

        // Get all meta
        $meta_data = get_post_meta($post_id, '', true);

        echo '<h3>📋 لیست متاها (' . count($meta_data) . ' مورد)</h3>';
        echo '<table style="width:100%;border-collapse:collapse;font-size:12px;">';
        echo '<thead><tr style="background:#1854CC;color:#fff;">';
        echo '<th style="padding:8px;text-align:right;border:1px solid #ddd;">کلید متا</th>';
        echo '<th style="padding:8px;text-align:right;border:1px solid #ddd;">مقدار</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($meta_data as $key => $value) {
            $display = $this->format_meta_value($value[0]);
            $color = $this->get_meta_color($key);

            echo '<tr style="border-bottom:1px solid #eee;">';
            echo '<td style="padding:8px;border:1px solid #ddd;font-weight:bold;color:' . $color . ';">' . esc_html($key) . '</td>';
            echo '<td style="padding:8px;border:1px solid #ddd;word-break:break-all;">' . $display . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        echo $this->render_footer();
    }

    // ==========================================
    // DEBUG: CONTENT POST
    // ==========================================

    /**
     * Debug content post (money page)
     */
    private function debug_content_post($post_id) {
        if (!$post_id) {
            $this->show_error('لطفاً یک post_id وارد کنید: <code>?d=true&type=content&pid=123</code>');
            return;
        }

        $post = get_post($post_id);
        if (!$post || 'content' !== get_post_type($post)) {
            $this->show_error('پست با این ID پیدا نشد یا از نوع مانی‌پیج نیست.');
            return;
        }

        echo $this->render_header('🔍 دیباگ مانی‌پیج', $post);

        // ==========================================
        // 1. Basic Info
        // ==========================================
        echo '<h3>📄 اطلاعات پایه</h3>';
        echo '<table style="width:100%;border-collapse:collapse;font-size:13px;">';
        echo '<tr><td style="padding:6px;border:1px solid #ddd;font-weight:bold;">ID</td><td style="padding:6px;border:1px solid #ddd;">' . $post->ID . '</td></tr>';
        echo '<tr><td style="padding:6px;border:1px solid #ddd;font-weight:bold;">عنوان</td><td style="padding:6px;border:1px solid #ddd;">' . esc_html($post->post_title) . '</td></tr>';
        echo '<tr><td style="padding:6px;border:1px solid #ddd;font-weight:bold;">اسلاگ</td><td style="padding:6px;border:1px solid #ddd;">' . $post->post_name . '</td></tr>';
        echo '<tr><td style="padding:6px;border:1px solid #ddd;font-weight:bold;">وضعیت</td><td style="padding:6px;border:1px solid #ddd;">' . $post->post_status . '</td></tr>';
        echo '</table>';

        // ==========================================
        // 2. Area Meta
        // ==========================================
        echo '<h3>🏙️ متاهای منطقه (شهر)</h3>';
        $this->render_meta_table($post_id, [
            'fnk_dev_area_term_id',
            'fnk_dev_area_term_name',
            'fnk_dev_area_term_slug',
        ]);

        // ==========================================
        // 3. Service Meta
        // ==========================================
        echo '<h3>🛠️ متاهای خدمت</h3>';
        $this->render_meta_table($post_id, [
            'fnk_dev_service_term_id',
            'fnk_dev_service_term_name',
        ]);

        // ==========================================
        // 4. Taxonomies
        // ==========================================
        echo '<h3>🏷️ تکسونومی‌ها</h3>';
        $this->render_taxonomies($post_id, ['area', 'service']);

        // ==========================================
        // 5. Permalink
        // ==========================================
        echo '<h3>🔗 پرmalink</h3>';
        $permalink = get_permalink($post_id);
        echo '<p><strong>لینک فعلی:</strong> <a href="' . $permalink . '" target="_blank">' . $permalink . '</a></p>';

        // ==========================================
        // 6. Relations
        // ==========================================
        echo '<h3>🔗 روابط جت‌انجین</h3>';
        $this->render_jet_relations($post_id);

        echo $this->render_footer();
    }

    // ==========================================
    // DEBUG: FANIKAR POST
    // ==========================================

    /**
     * Debug fanikar post
     */
    private function debug_fanikar_post($post_id) {
        if (!$post_id) {
            $this->show_error('لطفاً یک post_id وارد کنید: <code>?d=true&type=fanikar&pid=123</code>');
            return;
        }

        $post = get_post($post_id);
        if (!$post || 'fanikar' !== get_post_type($post)) {
            $this->show_error('پست با این ID پیدا نشد یا از نوع فنی‌کار نیست.');
            return;
        }

        echo $this->render_header('🔍 دیباگ فنی‌کار', $post);

        // ==========================================
        // 1. Basic Info
        // ==========================================
        echo '<h3>📄 اطلاعات پایه</h3>';
        echo '<table style="width:100%;border-collapse:collapse;font-size:13px;">';
        echo '<tr><td style="padding:6px;border:1px solid #ddd;font-weight:bold;">ID</td><td style="padding:6px;border:1px solid #ddd;">' . $post->ID . '</td></tr>';
        echo '<tr><td style="padding:6px;border:1px solid #ddd;font-weight:bold;">عنوان</td><td style="padding:6px;border:1px solid #ddd;">' . esc_html($post->post_title) . '</td></tr>';
        echo '<tr><td style="padding:6px;border:1px solid #ddd;font-weight:bold;">اسلاگ</td><td style="padding:6px;border:1px solid #ddd;">' . $post->post_name . '</td></tr>';
        echo '<tr><td style="padding:6px;border:1px solid #ddd;font-weight:bold;">وضعیت</td><td style="padding:6px;border:1px solid #ddd;">' . $post->post_status . '</td></tr>';
        echo '</table>';

        // ==========================================
        // 2. Area Meta
        // ==========================================
        echo '<h3>🏙️ متاهای منطقه (شهر)</h3>';
        $this->render_meta_table($post_id, [
            'fnk_dev_fanikar_area_term_id',
        ]);

        // ==========================================
        // 3. Service Scores (if exists)
        // ==========================================
        echo '<h3>⭐ امتیازات خدمات</h3>';
        $scores = get_post_meta($post_id, 'fnk_dev_service_scores', true);
        if (!empty($scores) && is_array($scores)) {
            echo '<table style="width:100%;border-collapse:collapse;font-size:13px;">';
            echo '<thead><tr style="background:#1854CC;color:#fff;">';
            echo '<th style="padding:8px;text-align:right;border:1px solid #ddd;">خدمت (ID)</th>';
            echo '<th style="padding:8px;text-align:right;border:1px solid #ddd;">امتیاز</th>';
            echo '</tr></thead>';
            echo '<tbody>';

            foreach ($scores as $term_id => $score) {
                $term = get_term($term_id, 'service');
                $term_name = ($term && !is_wp_error($term)) ? $term->name : 'نامشخص';
                echo '<tr style="border-bottom:1px solid #eee;">';
                echo '<td style="padding:8px;border:1px solid #ddd;">' . esc_html($term_name) . ' (ID: ' . $term_id . ')</td>';
                echo '<td style="padding:8px;border:1px solid #ddd;font-weight:bold;color:#1854CC;">' . $score . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        } else {
            echo '<p style="color:#999;">⚠️ هنوز امتیازی برای این فنی‌کار ثبت نشده است.</p>';
        }

        // ==========================================
        // 4. Taxonomies
        // ==========================================
        echo '<h3>🏷️ تکسونومی‌ها</h3>';
        $this->render_taxonomies($post_id, ['area', 'service', 'fnk-features']);

        // ==========================================
        // 5. Relations
        // ==========================================
        echo '<h3>🔗 روابط جت‌انجین</h3>';
        $this->render_jet_relations($post_id);

        echo $this->render_footer();
    }

    // ==========================================
    // DEBUG: TERM
    // ==========================================

    /**
     * Debug term
     */
    private function debug_term($term_id) {
        if (!$term_id) {
            $this->show_error('لطفاً یک term_id وارد کنید: <code>?d=true&type=term&tid=123</code>');
            return;
        }

        $term = get_term($term_id);
        if (!$term || is_wp_error($term)) {
            $this->show_error('ترمی با این ID پیدا نشد.');
            return;
        }

        echo $this->render_header('🔍 دیباگ ترم', null, $term);

        // ==========================================
        // Basic Info
        // ==========================================
        echo '<h3>📄 اطلاعات پایه</h3>';
        echo '<table style="width:100%;border-collapse:collapse;font-size:13px;">';
        echo '<tr><td style="padding:6px;border:1px solid #ddd;font-weight:bold;">ID</td><td style="padding:6px;border:1px solid #ddd;">' . $term->term_id . '</td></tr>';
        echo '<tr><td style="padding:6px;border:1px solid #ddd;font-weight:bold;">نام</td><td style="padding:6px;border:1px solid #ddd;">' . esc_html($term->name) . '</td></tr>';
        echo '<tr><td style="padding:6px;border:1px solid #ddd;font-weight:bold;">اسلاگ</td><td style="padding:6px;border:1px solid #ddd;">' . $term->slug . '</td></tr>';
        echo '<tr><td style="padding:6px;border:1px solid #ddd;font-weight:bold;">تکسونومی</td><td style="padding:6px;border:1px solid #ddd;">' . $term->taxonomy . '</td></tr>';
        echo '<tr><td style="padding:6px;border:1px solid #ddd;font-weight:bold;">والد</td><td style="padding:6px;border:1px solid #ddd;">' . ($term->parent ? get_term($term->parent)->name . ' (ID: ' . $term->parent . ')' : '❌ بدون والد (سطح 0)') . '</td></tr>';
        echo '<tr><td style="padding:6px;border:1px solid #ddd;font-weight:bold;">تعداد پست‌ها</td><td style="padding:6px;border:1px solid #ddd;">' . $term->count . '</td></tr>';
        echo '</table>';

        // ==========================================
        // Term Meta
        // ==========================================
        echo '<h3>📋 متاهای ترم</h3>';
        $term_meta = get_term_meta($term_id, '', true);
        if (!empty($term_meta)) {
            echo '<table style="width:100%;border-collapse:collapse;font-size:12px;">';
            echo '<thead><tr style="background:#1854CC;color:#fff;">';
            echo '<th style="padding:8px;text-align:right;border:1px solid #ddd;">کلید</th>';
            echo '<th style="padding:8px;text-align:right;border:1px solid #ddd;">مقدار</th>';
            echo '</tr></thead>';
            echo '<tbody>';

            foreach ($term_meta as $key => $value) {
                echo '<tr style="border-bottom:1px solid #eee;">';
                echo '<td style="padding:8px;border:1px solid #ddd;font-weight:bold;">' . esc_html($key) . '</td>';
                echo '<td style="padding:8px;border:1px solid #ddd;">' . $this->format_meta_value($value[0]) . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        } else {
            echo '<p style="color:#999;">⚠️ این ترم هیچ متایی ندارد.</p>';
        }

        echo $this->render_footer();
    }

    // ==========================================
    // DEBUG: PERMALINKS
    // ==========================================

    /**
     * Debug permalinks
     */
    private function debug_permalinks($post_id) {
        if (!$post_id) {
            $this->show_error('لطفاً یک post_id وارد کنید: <code>?d=true&type=permalinks&pid=123</code>');
            return;
        }

        $post = get_post($post_id);
        if (!$post) {
            $this->show_error('پستی با این ID پیدا نشد.');
            return;
        }

        echo $this->render_header('🔍 دیباگ پرmalink', $post);

        // ==========================================
        // URL Info
        // ==========================================
        $permalink = get_permalink($post_id);
        $home_url = home_url('/');

        echo '<h3>🔗 اطلاعات لینک</h3>';
        echo '<table style="width:100%;border-collapse:collapse;font-size:13px;">';
        echo '<tr><td style="padding:6px;border:1px solid #ddd;font-weight:bold;">آدرس سایت</td><td style="padding:6px;border:1px solid #ddd;">' . $home_url . '</td></tr>';
        echo '<tr><td style="padding:6px;border:1px solid #ddd;font-weight:bold;">لینک فعلی</td><td style="padding:6px;border:1px solid #ddd;"><a href="' . $permalink . '" target="_blank">' . $permalink . '</a></td></tr>';
        echo '<tr><td style="padding:6px;border:1px solid #ddd;font-weight:bold;">نوع پست</td><td style="padding:6px;border:1px solid #ddd;">' . get_post_type($post_id) . '</td></tr>';
        echo '</table>';

        // ==========================================
        // Area Meta for Permalink
        // ==========================================
        if ('content' === get_post_type($post_id)) {
            echo '<h3>🏙️ متاهای مؤثر در پرmalink</h3>';
            $this->render_meta_table($post_id, [
                'fnk_dev_area_term_id',
                'fnk_dev_area_term_slug',
            ]);

            // Get term info
            $term_id = get_post_meta($post_id, 'fnk_dev_area_term_id', true);
            if ($term_id) {
                $term = get_term($term_id, 'area');
                if ($term && !is_wp_error($term)) {
                    echo '<h4>📌 اطلاعات ترم انتخاب شده</h4>';
                    echo '<table style="width:100%;border-collapse:collapse;font-size:13px;">';
                    echo '<tr><td style="padding:6px;border:1px solid #ddd;font-weight:bold;">ID</td><td style="padding:6px;border:1px solid #ddd;">' . $term->term_id . '</td></tr>';
                    echo '<tr><td style="padding:6px;border:1px solid #ddd;font-weight:bold;">نام</td><td style="padding:6px;border:1px solid #ddd;">' . esc_html($term->name) . '</td></tr>';
                    echo '<tr><td style="padding:6px;border:1px solid #ddd;font-weight:bold;">اسلاگ</td><td style="padding:6px;border:1px solid #ddd;font-weight:bold;color:#1854CC;">' . $term->slug . '</td></tr>';
                    echo '</table>';

                    echo '<h4>✅ ساختار لینک پیش‌بینی شده</h4>';
                    echo '<p style="background:#f0f8ff;padding:10px;border-radius:6px;font-size:16px;direction:ltr;text-align:left;">';
                    echo $home_url . '<strong style="color:#1854CC;">' . $term->slug . '</strong>/<strong style="color:#ff8800;">' . $post->post_name . '</strong>/';
                    echo '</p>';
                }
            }
        }

        // ==========================================
        // Rewrite Rules
        // ==========================================
        echo '<h3>📝 قوانین Rewrite فعال</h3>';
        global $wp_rewrite;

        $rules = get_option('rewrite_rules');
        $count = 0;

        echo '<div style="max-height:300px;overflow:auto;background:#1a1a2e;color:#00ff9d;padding:10px;border-radius:6px;font-family:monospace;font-size:11px;direction:ltr;text-align:left;">';
        foreach ($rules as $pattern => $query) {
            if (strpos($pattern, 'area') !== false || strpos($query, 'content') !== false || strpos($pattern, '/') === 0) {
                echo '<div style="padding:2px 0;border-bottom:1px solid #333;">';
                echo '<span style="color:#ff6b6b;">' . esc_html($pattern) . '</span> → ';
                echo '<span style="color:#ffd93d;">' . esc_html($query) . '</span>';
                echo '</div>';
                $count++;
            }
        }
        if ($count === 0) {
            echo '<p style="color:#999;">هیچ قانون مرتبطی پیدا نشد.</p>';
        } else {
            echo '<p style="color:#999;margin-top:10px;">تعداد قوانین مرتبط: ' . $count . '</p>';
        }
        echo '</div>';

        echo $this->render_footer();
    }

    // ==========================================
    // DEBUG: Jet Engine Tools

    // ==========================================
    private function debug_jetengine_hooks() {
        echo $this->render_header('🔍 دیباگ هوک‌های جت‌انجین');

        echo '<h3>📋 هوک‌های فعال</h3>';
        echo '<table style="width:100%;border-collapse:collapse;font-size:12px;">';
        echo '<thead><tr style="background:#1854CC;color:#fff;">';
        echo '<th style="padding:8px;text-align:right;border:1px solid #ddd;">هوک</th>';
        echo '<th style="padding:8px;text-align:right;border:1px solid #ddd;">وضعیت</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        $hooks = [
            'jet-engine/listing/dynamic-terms/classes' => has_filter('jet-engine/listing/dynamic-terms/classes'),
            'jet-smart-filters/filters/localized-data' => has_filter('jet-smart-filters/filters/localized-data'),
            'jet-smart-filters/filters/dropdown-item-html' => has_filter('jet-smart-filters/filters/dropdown-item-html'),
            'jet-smart-filters/filters/checkbox-item-html' => has_filter('jet-smart-filters/filters/checkbox-item-html'),
            'jet-smart-filters/filters/radio-item-html' => has_filter('jet-smart-filters/filters/radio-item-html'),
        ];

        foreach ($hooks as $hook => $active) {
            echo '<tr style="border-bottom:1px solid #eee;">';
            echo '<td style="padding:8px;border:1px solid #ddd;font-family:monospace;">' . $hook . '</td>';
            echo '<td style="padding:8px;border:1px solid #ddd;color:' . ($active ? 'green' : 'red') . ';">' . ($active ? '✅ فعال' : '❌ غیرفعال') . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo $this->render_footer();
    }

    // ==========================================
    // DEBUG: ALL
    // ==========================================

    /**
     * Debug everything
     */
    private function debug_all($post_id) {
        if (!$post_id) {
            $this->show_error('لطفاً یک post_id وارد کنید: <code>?d=true&type=all&pid=123</code>');
            return;
        }

        $post = get_post($post_id);
        if (!$post) {
            $this->show_error('پستی با این ID پیدا نشد.');
            return;
        }

        echo $this->render_header('🔍 دیباگ کامل', $post);

        // ==========================================
        // 1. Post Info
        // ==========================================
        echo '<h3>📄 اطلاعات پست</h3>';
        echo '<table style="width:100%;border-collapse:collapse;font-size:13px;">';
        echo '<tr><td style="padding:6px;border:1px solid #ddd;font-weight:bold;">ID</td><td style="padding:6px;border:1px solid #ddd;">' . $post->ID . '</td></tr>';
        echo '<tr><td style="padding:6px;border:1px solid #ddd;font-weight:bold;">عنوان</td><td style="padding:6px;border:1px solid #ddd;">' . esc_html($post->post_title) . '</td></tr>';
        echo '<tr><td style="padding:6px;border:1px solid #ddd;font-weight:bold;">نوع پست</td><td style="padding:6px;border:1px solid #ddd;">' . get_post_type($post_id) . '</td></tr>';
        echo '<tr><td style="padding:6px;border:1px solid #ddd;font-weight:bold;">وضعیت</td><td style="padding:6px;border:1px solid #ddd;">' . $post->post_status . '</td></tr>';
        echo '<tr><td style="padding:6px;border:1px solid #ddd;font-weight:bold;">اسلاگ</td><td style="padding:6px;border:1px solid #ddd;">' . $post->post_name . '</td></tr>';
        echo '<tr><td style="padding:6px;border:1px solid #ddd;font-weight:bold;">پرmalink</td><td style="padding:6px;border:1px solid #ddd;"><a href="' . get_permalink($post_id) . '" target="_blank">' . get_permalink($post_id) . '</a></td></tr>';
        echo '</table>';

        // ==========================================
        // 2. All Meta
        // ==========================================
        echo '<h3>📋 همه متاها</h3>';
        $meta_data = get_post_meta($post_id, '', true);
        echo '<table style="width:100%;border-collapse:collapse;font-size:12px;">';
        echo '<thead><tr style="background:#1854CC;color:#fff;">';
        echo '<th style="padding:8px;text-align:right;border:1px solid #ddd;">کلید متا</th>';
        echo '<th style="padding:8px;text-align:right;border:1px solid #ddd;">مقدار</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($meta_data as $key => $value) {
            $color = $this->get_meta_color($key);
            echo '<tr style="border-bottom:1px solid #eee;">';
            echo '<td style="padding:8px;border:1px solid #ddd;font-weight:bold;color:' . $color . ';">' . esc_html($key) . '</td>';
            echo '<td style="padding:8px;border:1px solid #ddd;word-break:break-all;">' . $this->format_meta_value($value[0]) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        // ==========================================
        // 3. Taxonomies
        // ==========================================
        echo '<h3>🏷️ تکسونومی‌ها</h3>';
        $this->render_taxonomies($post_id, ['area', 'service', 'fnk-features']);

        // ==========================================
        // 4. Relations
        // ==========================================
        echo '<h3>🔗 روابط جت‌انجین</h3>';
        $this->render_jet_relations($post_id);

        // ==========================================
        // 5. Fanikar Specific (if applicable)
        // ==========================================
        if ('fanikar' === get_post_type($post_id)) {
            echo '<h3>⭐ امتیازات خدمات</h3>';
            $scores = get_post_meta($post_id, 'fnk_dev_service_scores', true);
            if (!empty($scores) && is_array($scores)) {
                echo '<table style="width:100%;border-collapse:collapse;font-size:13px;">';
                echo '<thead><tr style="background:#1854CC;color:#fff;">';
                echo '<th style="padding:8px;text-align:right;border:1px solid #ddd;">خدمت</th>';
                echo '<th style="padding:8px;text-align:right;border:1px solid #ddd;">امتیاز</th>';
                echo '</tr></thead>';
                echo '<tbody>';

                foreach ($scores as $term_id => $score) {
                    $term = get_term($term_id, 'service');
                    $term_name = ($term && !is_wp_error($term)) ? $term->name : 'نامشخص';
                    echo '<tr>';
                    echo '<td style="padding:8px;border:1px solid #ddd;">' . esc_html($term_name) . ' (ID: ' . $term_id . ')</td>';
                    echo '<td style="padding:8px;border:1px solid #ddd;font-weight:bold;color:#1854CC;">' . $score . '</td>';
                    echo '</tr>';
                }

                echo '</tbody></table>';
            } else {
                echo '<p style="color:#999;">⚠️ هنوز امتیازی ثبت نشده است.</p>';
            }
        }

        echo $this->render_footer();
    }

    // ==========================================
    // RENDER HELPERS
    // ==========================================

    /**
     * Render header
     */
    private function render_header($title, $post = null, $term = null) {
        $html = '<div style="direction:rtl;background:#f0f8ff;padding:20px;border:3px solid #1854CC;border-radius:10px;margin:20px 0;font-family:Vazir, sans-serif;font-size:13px;line-height:1.8;">';
        $html .= '<h1 style="color:#1854CC;margin:0 0 15px 0;font-size:22px;">' . $title . '</h1>';

        if ($post) {
            $html .= '<p style="color:#666;margin:0 0 15px 0;">';
            $html .= '<strong>عنوان:</strong> ' . esc_html($post->post_title) . ' | ';
            $html .= '<strong>ID:</strong> ' . $post->ID . ' | ';
            $html .= '<strong>نوع:</strong> ' . get_post_type($post->ID);
            $html .= '</p>';
        }

        if ($term) {
            $html .= '<p style="color:#666;margin:0 0 15px 0;">';
            $html .= '<strong>نام:</strong> ' . esc_html($term->name) . ' | ';
            $html .= '<strong>ID:</strong> ' . $term->term_id . ' | ';
            $html .= '<strong>تکسونومی:</strong> ' . $term->taxonomy;
            $html .= '</p>';
        }

        $html .= '<hr style="border:1px solid #1854CC;opacity:0.3;">';

        return $html;
    }

    /**
     * Render footer
     */
    private function render_footer() {
        return '<hr style="border:1px solid #1854CC;opacity:0.3;margin-top:20px;">
            <p style="color:#999;font-size:12px;text-align:center;">🔍 دیباگ فنی‌کارا | نسخه ' . FK_VERSION . '</p>
            </div>';
    }

    /**
     * Show error message
     */
    private function show_error($message) {
        echo '<div style="direction:rtl;background:#fff3cd;padding:20px;border:2px solid #ffc107;border-radius:8px;margin:20px;font-family:Vazir, sans-serif;">';
        echo '<h3 style="color:#856404;">⚠️ خطا</h3>';
        echo '<p>' . $message . '</p>';
        echo '</div>';
    }

    /**
     * Render meta table
     */
    private function render_meta_table($post_id, $meta_keys) {
        echo '<table style="width:100%;border-collapse:collapse;font-size:13px;">';
        echo '<thead><tr style="background:#f5f5f5;">';
        echo '<th style="padding:6px;text-align:right;border:1px solid #ddd;">کلید</th>';
        echo '<th style="padding:6px;text-align:right;border:1px solid #ddd;">مقدار</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($meta_keys as $key) {
            $value = get_post_meta($post_id, $key, true);
            $display = !empty($value) ? $value : '❌ خالی';
            $color = !empty($value) ? '#1854CC' : '#ff0000';

            echo '<tr style="border-bottom:1px solid #eee;">';
            echo '<td style="padding:6px;border:1px solid #ddd;font-weight:bold;">' . esc_html($key) . '</td>';
            echo '<td style="padding:6px;border:1px solid #ddd;color:' . $color . ';font-weight:' . (!empty($value) ? 'bold' : 'normal') . ';">' . $display . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * Render taxonomies
     */
    private function render_taxonomies($post_id, $taxonomies) {
        foreach ($taxonomies as $tax) {
            $terms = wp_get_post_terms($post_id, $tax, ['hide_empty' => false]);

            echo '<h4 style="margin-top:10px;">' . $tax . '</h4>';

            if (empty($terms) || is_wp_error($terms)) {
                echo '<p style="color:#999;">⚠️ هیچ ترمی در این تکسونومی متصل نیست.</p>';
                continue;
            }

            echo '<ul style="list-style:none;padding:0;margin:0;">';
            foreach ($terms as $term) {
                $level = ($term->parent == 0) ? 'سطح 0 (شهر)' : 'سطح 1 (منطقه)';
                echo '<li style="padding:4px 8px;border-bottom:1px solid #eee;">';
                echo '<strong>' . esc_html($term->name) . '</strong> ';
                echo '(ID: ' . $term->term_id . ') ';
                echo '<span style="color:#666;font-size:12px;">- ' . $level . '</span>';
                echo '</li>';
            }
            echo '</ul>';
        }
    }

    /**
     * Render JetEngine relations
     */
    private function render_jet_relations($post_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'jet_rel_default';
        $exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");

        if ($exists !== $table) {
            echo '<p style="color:#999;">⚠️ جدول روابط جت‌انجین وجود ندارد.</p>';
            return;
        }

        // Get relations where this post is child
        $child_results = $wpdb->get_results($wpdb->prepare(
            "SELECT rel_id, parent_object_id FROM {$table} WHERE child_object_id = %d",
            $post_id
        ));

        // Get relations where this post is parent
        $parent_results = $wpdb->get_results($wpdb->prepare(
            "SELECT rel_id, child_object_id FROM {$table} WHERE parent_object_id = %d",
            $post_id
        ));

        if (empty($child_results) && empty($parent_results)) {
            echo '<p style="color:#999;">⚠️ این پست در هیچ رابطه‌ای شرکت ندارد.</p>';
            return;
        }

        // Show child relations (this post is child)
        if (!empty($child_results)) {
            echo '<h4 style="margin-top:10px;">📌 این پست به عنوان فرزند (Child)</h4>';
            echo '<table style="width:100%;border-collapse:collapse;font-size:12px;">';
            echo '<thead><tr style="background:#f0f0f0;">';
            echo '<th style="padding:4px 8px;border:1px solid #ddd;">Relation ID</th>';
            echo '<th style="padding:4px 8px;border:1px solid #ddd;">والد (Parent)</th>';
            echo '</tr></thead>';
            echo '<tbody>';

            foreach ($child_results as $row) {
                $parent_title = get_the_title($row->parent_object_id);
                $parent_type = get_post_type($row->parent_object_id);
                echo '<tr>';
                echo '<td style="padding:4px 8px;border:1px solid #ddd;">' . $row->rel_id . '</td>';
                echo '<td style="padding:4px 8px;border:1px solid #ddd;">' . esc_html($parent_title) . ' (ID: ' . $row->parent_object_id . ', نوع: ' . $parent_type . ')</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        // Show parent relations (this post is parent)
        if (!empty($parent_results)) {
            echo '<h4 style="margin-top:15px;">📌 این پست به عنوان والد (Parent)</h4>';
            echo '<table style="width:100%;border-collapse:collapse;font-size:12px;">';
            echo '<thead><tr style="background:#f0f0f0;">';
            echo '<th style="padding:4px 8px;border:1px solid #ddd;">Relation ID</th>';
            echo '<th style="padding:4px 8px;border:1px solid #ddd;">فرزند (Child)</th>';
            echo '</tr></thead>';
            echo '<tbody>';

            foreach ($parent_results as $row) {
                $child_title = get_the_title($row->child_object_id);
                $child_type = get_post_type($row->child_object_id);
                echo '<tr>';
                echo '<td style="padding:4px 8px;border:1px solid #ddd;">' . $row->rel_id . '</td>';
                echo '<td style="padding:4px 8px;border:1px solid #ddd;">' . esc_html($child_title) . ' (ID: ' . $row->child_object_id . ', نوع: ' . $child_type . ')</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }
    }

    /**
     * Format meta value for display
     */
    private function format_meta_value($value) {
        if (is_serialized($value)) {
            $unserialized = maybe_unserialize($value);
            if (is_array($unserialized)) {
                return '<pre style="margin:0;background:#f0f0f0;padding:5px;border-radius:3px;font-size:11px;">' . print_r($unserialized, true) . '</pre>';
            }
            return esc_html($value);
        }

        if (strlen($value) > 200) {
            return esc_html(substr($value, 0, 200)) . '... <span style="color:#999;">(' . strlen($value) . ' کاراکتر)</span>';
        }

        return esc_html($value);
    }

    /**
     * Get color for meta key
     */
    private function get_meta_color($key) {
        if (strpos($key, 'fnk_dev_') === 0) {
            return '#1854CC'; // New meta keys
        }
        if (strpos($key, 'content_') === 0) {
            return '#28a745'; // Content meta keys
        }
        if (strpos($key, 'fanikar_') === 0) {
            return '#ff8800'; // Fanikar meta keys
        }
        if (strpos($key, '_jet_') === 0) {
            return '#6c757d'; // JetEngine meta keys
        }
        if (strpos($key, '_') === 0) {
            return '#6c757d'; // WordPress meta keys
        }
        return '#333'; // Default
    }
}