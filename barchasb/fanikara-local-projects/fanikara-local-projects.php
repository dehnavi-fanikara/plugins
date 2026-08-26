<?php

/**
 * Plugin Name: افزونه مدیریت پروژه‌های لوکال سئو شهرها و خدمات مختلف
 * Description: ثبت پروژه های اخیر برای شهرها و خدمت های مختلف با مانیتورینگ هوشمند سلامت محتوا، حذف مطلق تگ‌های تصویر و غیرفعال‌سازی سیستم تبدیل ایموجی وردپرس جهت استفاده انحصاری از ایموجی متنی، کرون‌جاب ساعت ۳ بامداد، ایمپورت/اکسپورت و بدون تولید هرگونه کد اسکیما (نسخه ۴.۰).
 * Version: 4.0
 * Author: Javad Absalan From FaniKara
 * License: GPL2
 */

if (! defined('ABSPATH')) {
    exit;
}

function fklp_get_reports_api_url()
{
    $default_url = 'https://fanikara-ai-project.liara.run';
    return untrailingslashit(esc_url_raw(get_option('fklp_reports_api_url', $default_url)));
}

function fklp_get_reports_api_key()
{
    return trim((string) get_option('fklp_reports_api_key', 'lulebazkoni_secure_api_key_2026'));
}

function fklp_is_valid_date($date)
{
    return is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
}

function fklp_get_retention_cutoff_date()
{
    $days = max(1, intval(get_option('fklp_retention_days', '45')));
    return date('Y-m-d', current_time('timestamp') - ($days * DAY_IN_SECONDS));
}

function fklp_get_display_cutoff_date()
{
    $cutoffs = array(fklp_get_retention_cutoff_date());
    $manual_cutoff = get_option('fklp_display_cutoff_date', '');
    if (fklp_is_valid_date($manual_cutoff)) {
        $cutoffs[] = $manual_cutoff;
    }
    return max($cutoffs);
}

add_filter('cron_schedules', 'fklp_register_sync_schedules');
function fklp_register_sync_schedules($schedules)
{
    $schedules['fklp_every_6_hours'] = array(
        'interval' => 6 * HOUR_IN_SECONDS,
        'display'  => 'هر ۶ ساعت',
    );
    $schedules['fklp_weekly'] = array(
        'interval' => WEEK_IN_SECONDS,
        'display'  => 'هفتگی',
    );
    return $schedules;
}

function fklp_get_sync_recurrence()
{
    $allowed = array('hourly', 'fklp_every_6_hours', 'twicedaily', 'daily', 'fklp_weekly');
    $recurrence = get_option('fklp_sync_interval', 'daily');
    return in_array($recurrence, $allowed, true) ? $recurrence : 'daily';
}

function fklp_schedule_sync_event($reschedule = false)
{
    $next = wp_next_scheduled('fklp_daily_sync_event');
    if ($reschedule && $next) {
        wp_clear_scheduled_hook('fklp_daily_sync_event');
        $next = false;
    }
    if (! $next) {
        wp_schedule_event(time() + MINUTE_IN_SECONDS, fklp_get_sync_recurrence(), 'fklp_daily_sync_event');
    }
}

function fklp_create_consumed_uids_table()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'fanikara_local_project_uids';
    $charset_collate = $wpdb->get_charset_collate();
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta("CREATE TABLE $table_name (
        source_uid varchar(191) NOT NULL,
        reason varchar(30) NOT NULL DEFAULT 'synced',
        recorded_at datetime NOT NULL,
        PRIMARY KEY (source_uid)
    ) $charset_collate;");
}

function fklp_record_consumed_uid($uid, $reason = 'synced')
{
    global $wpdb;
    $uid = sanitize_text_field($uid);
    if (empty($uid)) {
        return;
    }
    $wpdb->replace(
        $wpdb->prefix . 'fanikara_local_project_uids',
        array(
            'source_uid'  => $uid,
            'reason'      => sanitize_key($reason),
            'recorded_at' => current_time('mysql'),
        )
    );
}

function fklp_has_consumed_uid($uid)
{
    global $wpdb;
    return (bool) $wpdb->get_var($wpdb->prepare(
        "SELECT source_uid FROM {$wpdb->prefix}fanikara_local_project_uids WHERE source_uid = %s LIMIT 1",
        $uid
    ));
}

// غیرفعال کردن تبدیل ایموجی‌های متنی به تگ تصویر توسط هسته وردپرس
add_action('init', 'fklp_disable_wp_emojis');
function fklp_disable_wp_emojis()
{
    remove_action('wp_head', 'print_emoji_detection_script', 7);
    remove_action('admin_print_scripts', 'print_emoji_detection_script');
    remove_action('wp_print_styles', 'print_emoji_styles');
    remove_action('admin_print_styles', 'print_emoji_styles');
    remove_filter('the_content_feed', 'wp_staticize_emojis');
    remove_filter('comment_text_rss', 'wp_staticize_emojis');
    remove_filter('wp_mail', 'wp_staticize_emojis');
    add_filter('tiny_mce_plugins', 'fklp_disable_emojis_tinymce');
}

function fklp_disable_emojis_tinymce($plugins)
{
    if (is_array($plugins)) {
        return array_diff($plugins, array('wpemoji'));
    }
    return array();
}

register_activation_hook(__FILE__, 'fklp_create_db_table');
function fklp_create_db_table()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'fanikara_local_projects';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        post_id bigint(20) NOT NULL,
        post_title varchar(255) NOT NULL,
        brand_name varchar(150) NOT NULL,
        city varchar(100) NOT NULL,
        district varchar(100) NOT NULL,
        service_type varchar(100) NOT NULL,
        location_detail varchar(255) NOT NULL,
        short_desc varchar(255) NOT NULL,
        problem_cause varchar(255) NOT NULL,
        tools_used varchar(255) NOT NULL,
        duration varchar(50) NOT NULL,
        project_date date NOT NULL,
        tech_report text NOT NULL,
        source_uid varchar(191) NOT NULL DEFAULT '',
        source_status varchar(20) NOT NULL DEFAULT 'published',
        source_created_at datetime NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    fklp_create_consumed_uids_table();

    add_option('fklp_total_projects', '10');
    add_option('fklp_full_display_count', '3');
    add_option('fklp_home_recent_count', '10');
    add_option('fklp_display_cutoff_date', '');
    add_option('fklp_retention_days', '45');
    add_option('fklp_reports_api_url', 'https://fanikara-ai-project.liara.run');
    add_option('fklp_reports_api_key', 'lulebazkoni_secure_api_key_2026');
    add_option('fklp_sync_batch_limit', '100');
    add_option('fklp_sync_interval', 'daily');

    if (! wp_next_scheduled('fklp_daily_clean_cache_event')) {
        $timezone_offset = get_option('gmt_offset') * HOUR_IN_SECONDS;
        $target_time_iran = strtotime('today 03:00:00');
        $target_timestamp_utc = $target_time_iran - $timezone_offset;

        if ($target_timestamp_utc < time()) {
            $target_timestamp_utc += DAY_IN_SECONDS;
        }

        wp_schedule_event($target_timestamp_utc, 'daily', 'fklp_daily_clean_cache_event');
    }

    fklp_schedule_sync_event(true);
}

register_deactivation_hook(__FILE__, 'fklp_deactivate_plugin');
function fklp_deactivate_plugin()
{
    wp_clear_scheduled_hook('fklp_daily_clean_cache_event');
    wp_clear_scheduled_hook('fklp_daily_sync_event');
    delete_transient('fklp_health_report_data');
}

add_action('plugins_loaded', 'fklp_update_db_check');
function fklp_update_db_check()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'fanikara_local_projects';

    $query = $wpdb->prepare(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name = %s AND column_name = %s",
        $table_name,
        'brand_name'
    );
    $row = $wpdb->get_results($query);

    if (empty($row)) {
        $wpdb->query("ALTER TABLE $table_name ADD brand_name varchar(150) NOT NULL AFTER post_title");
    }

    $columns = array(
        'source_uid'        => "ALTER TABLE $table_name ADD source_uid varchar(191) NOT NULL DEFAULT ''",
        'source_status'     => "ALTER TABLE $table_name ADD source_status varchar(20) NOT NULL DEFAULT 'published'",
        'source_created_at' => "ALTER TABLE $table_name ADD source_created_at datetime NULL",
    );
    foreach ($columns as $column => $alter_sql) {
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name = %s AND column_name = %s",
            $table_name,
            $column
        ));
        if (! $exists) {
            $wpdb->query($alter_sql);
        }
    }

    fklp_create_consumed_uids_table();

    add_option('fklp_display_cutoff_date', '');
    add_option('fklp_retention_days', '45');
    add_option('fklp_reports_api_url', 'https://fanikara-ai-project.liara.run');
    add_option('fklp_reports_api_key', 'lulebazkoni_secure_api_key_2026');
    add_option('fklp_sync_batch_limit', '100');
    add_option('fklp_sync_interval', 'daily');
    add_option('fklp_home_recent_count', '10');
    fklp_schedule_sync_event();
}

add_action('admin_menu', 'fklp_admin_menu');
function fklp_admin_menu()
{
    add_menu_page('پروژه‌های لوکال سئو', 'پروژه‌های لوکال سئو', 'manage_options', 'fklp-projects', 'fklp_projects_page', 'dashicons-location-alt', 25);
    add_submenu_page('fklp-projects', 'سلامت و وضعیت پروژه‌ها', 'سلامت و وضعیت پروژه‌ها', 'manage_options', 'fklp-health', 'fklp_health_page');
    add_submenu_page('fklp-projects', 'تنظیمات افزونه', 'تنظیمات افزونه', 'manage_options', 'fklp-settings', 'fklp_settings_page');
}

add_action('fklp_daily_clean_cache_event', 'fklp_clear_health_transient');
function fklp_clear_health_transient()
{
    delete_transient('fklp_health_report_data');
}

add_action('fklp_daily_clean_cache_event', 'fklp_cleanup_expired_projects');
function fklp_cleanup_expired_projects()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'fanikara_local_projects';
    $cutoff_date = fklp_get_retention_cutoff_date();
    $expired_uids = $wpdb->get_col($wpdb->prepare(
        "SELECT source_uid FROM $table_name WHERE project_date < %s AND source_uid <> ''",
        $cutoff_date
    ));
    foreach ($expired_uids as $uid) {
        fklp_record_consumed_uid($uid, 'expired');
    }
    $wpdb->query($wpdb->prepare("DELETE FROM $table_name WHERE project_date < %s", $cutoff_date));
    fklp_clear_health_transient();
}

function fklp_get_health_report($force_refresh = false)
{
    if (! $force_refresh) {
        $cached_data = get_transient('fklp_health_report_data');
        if ($cached_data !== false) {
            return $cached_data;
        }
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'fanikara_local_projects';

    $posts_with_shortcode = $wpdb->get_results(
        "SELECT ID, post_title FROM {$wpdb->posts} 
         WHERE post_status = 'publish' 
         AND (post_content LIKE '%[local_recent_projects]%' OR post_content LIKE '%[local_recent_projects %]')"
    );

    $report = array(
        'no_projects' => array(),
        'expired_projects' => array()
    );

    $thirty_days_ago = date('Y-m-d', strtotime('-30 days'));

    if (! empty($posts_with_shortcode)) {
        foreach ($posts_with_shortcode as $p) {
            $last_project = $wpdb->get_row($wpdb->prepare(
                "SELECT project_date FROM $table_name WHERE post_id = %d ORDER BY project_date DESC LIMIT 1",
                $p->ID
            ));

            if (! $last_project) {
                $report['no_projects'][] = array(
                    'id'    => $p->ID,
                    'title' => $p->post_title
                );
            } elseif ($last_project->project_date < $thirty_days_ago) {
                $report['expired_projects'][] = array(
                    'id'         => $p->ID,
                    'title'      => $p->post_title,
                    'last_date'  => $last_project->project_date
                );
            }
        }
    }

    set_transient('fklp_health_report_data', $report, 12 * HOUR_IN_SECONDS);
    return $report;
}

add_action('admin_notices', 'fklp_admin_notices');
function fklp_admin_notices()
{
    if (! current_user_can('manage_options')) {
        return;
    }

    $user_id = get_current_user_id();
    if (get_user_meta($user_id, 'fklp_dismissed_notice_v3', true)) {
        return;
    }

    $report = fklp_get_health_report();
    $total_issues = count($report['no_projects']) + count($report['expired_projects']);

    if ($total_issues > 0) {
        $health_page_url = admin_url('admin.php?page=fklp-health');
        echo '<div class="notice notice-warning is-dismissible fklp-dismissible-notice" style="direction: rtl; text-align: right;">';
        echo '<p>⚠️ <strong>هشدار سلامت پروژه‌های لوکال سئو:</strong> تعداد <strong>' . intval($total_issues) . '</strong> صفحه دارای شورت‌کد، فاقد پروژه جدید بوده یا نیاز به به‌روزرسانی دارند. <a href="' . esc_url($health_page_url) . '">مشاهده جزئیات و ویرایش صفحات</a></p>';
        echo '</div>';
?>
        <script type="text/javascript">
            jQuery(document).on('click', '.fklp-dismissible-notice .notice-dismiss', function() {
                jQuery.ajax({
                    url: ajaxurl,
                    data: {
                        action: 'fklp_dismiss_notice'
                    }
                });
            });
        </script>
    <?php
    }
}

add_action('wp_ajax_fklp_dismiss_notice', 'fklp_dismiss_notice_callback');
function fklp_dismiss_notice_callback()
{
    $user_id = get_current_user_id();
    update_user_meta($user_id, 'fklp_dismissed_notice_v3', '1');
    wp_send_json_success();
}

add_action('fklp_project_saved_or_deleted', 'fklp_clear_health_transient');
function fklp_trigger_health_clear()
{
    do_action('fklp_project_saved_or_deleted');
}

add_action('fklp_daily_sync_event', 'fklp_daily_sync_reports');
function fklp_daily_sync_reports()
{
    fklp_sync_reports_from_api();
}

function fklp_sync_reports_from_api()
{
    global $wpdb;

    $api_url = fklp_get_reports_api_url();
    $api_key = fklp_get_reports_api_key();
    if (empty($api_url) || empty($api_key)) {
        return array('status' => 'error', 'message' => 'API URL or API key is not configured.');
    }

    $limit = max(1, min(500, intval(get_option('fklp_sync_batch_limit', '100'))));
    $reports_url = add_query_arg(
        array(
            'status'             => 'new',
            'publication_status' => 'published',
            'limit'              => $limit,
        ),
        $api_url . '/api/external/reports'
    );

    $response = wp_remote_get($reports_url, array(
        'timeout'   => 20,
        'sslverify' => true,
        'headers'   => array('X-API-Key' => $api_key, 'Accept' => 'application/json'),
    ));

    if (is_wp_error($response)) {
        return array('status' => 'error', 'message' => $response->get_error_message());
    }

    $http_code = wp_remote_retrieve_response_code($response);
    $payload = json_decode(wp_remote_retrieve_body($response), true);
    if ($http_code < 200 || $http_code >= 300 || ! is_array($payload) || ! is_array($payload['reports'] ?? null)) {
        return array('status' => 'error', 'message' => 'Invalid response from reports API.', 'http_code' => $http_code);
    }

    $table_name = $wpdb->prefix . 'fanikara_local_projects';
    $synced_uids = array();
    $inserted = 0;
    $skipped = 0;

    foreach ($payload['reports'] as $report) {
        if (! is_array($report) || ($report['status'] ?? 'draft') !== 'published') {
            continue;
        }

        $uid = sanitize_text_field($report['uid'] ?? '');
        $post_id = intval($report['post_id'] ?? 0);
        if (empty($uid) || $post_id <= 0) {
            continue;
        }

        if (fklp_has_consumed_uid($uid)) {
            $synced_uids[] = $uid;
            $skipped++;
            continue;
        }

        $existing_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_name WHERE source_uid = %s LIMIT 1",
            $uid
        ));

        if ($existing_id) {
            fklp_record_consumed_uid($uid, 'synced');
            $synced_uids[] = $uid;
            $skipped++;
            continue;
        }

        $project_date = sanitize_text_field($report['project_date'] ?? '');
        if (! fklp_is_valid_date($project_date)) {
            $project_date = current_time('Y-m-d');
        }

        $created_at = sanitize_text_field($report['created_at'] ?? '');
        $created_at = preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $created_at)
            ? str_replace('T', ' ', substr($created_at, 0, 19))
            : current_time('mysql');

        $inserted_ok = $wpdb->insert($table_name, array(
            'post_id'          => $post_id,
            'post_title'       => sanitize_text_field($report['post_title'] ?? ''),
            'brand_name'       => sanitize_text_field(!empty($report['brand_name']) ? $report['brand_name'] : 'برچسب'),
            'city'             => sanitize_text_field($report['city'] ?? ''),
            'district'         => sanitize_text_field($report['district'] ?? ''),
            'service_type'     => sanitize_text_field($report['service_type'] ?? ''),
            'location_detail'  => sanitize_text_field($report['location_detail'] ?? ''),
            'short_desc'       => sanitize_text_field($report['short_desc'] ?? ''),
            'problem_cause'    => sanitize_text_field($report['problem_cause'] ?? ''),
            'tools_used'       => sanitize_text_field($report['tools_used'] ?? ''),
            'duration'         => sanitize_text_field(trim(preg_replace('/[^\d]/', '', $report['duration'] ?? ''))),
            'project_date'     => $project_date,
            'tech_report'      => sanitize_textarea_field($report['tech_report'] ?? ''),
            'source_uid'       => $uid,
            'source_status'    => 'published',
            'source_created_at' => $created_at,
        ));

        if (false !== $inserted_ok) {
            fklp_record_consumed_uid($uid, 'synced');
            $synced_uids[] = $uid;
            $inserted++;
        }
    }

    if (! empty($synced_uids)) {
        $mark_response = wp_remote_post($api_url . '/api/external/reports/mark-used', array(
            'timeout'   => 20,
            'sslverify' => true,
            'headers'   => array(
                'X-API-Key'    => $api_key,
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ),
            'body' => wp_json_encode(array('uids' => array_values(array_unique($synced_uids)))),
        ));

        if (is_wp_error($mark_response) || wp_remote_retrieve_response_code($mark_response) < 200 || wp_remote_retrieve_response_code($mark_response) >= 300) {
            return array('status' => 'partial', 'inserted' => $inserted, 'skipped' => $skipped, 'message' => 'Reports were stored, but mark-used failed.');
        }
    }

    fklp_trigger_health_clear();
    return array('status' => 'success', 'inserted' => $inserted, 'skipped' => $skipped, 'count' => count($synced_uids));
}

function fklp_settings_page()
{
    $sync_message = '';
    if (isset($_POST['fklp_sync_now']) && check_admin_referer('fklp_sync_nonce')) {
        $sync_result = fklp_sync_reports_from_api();
        if ('success' === ($sync_result['status'] ?? '')) {
            $sync_message = 'دریافت گزارش‌ها با موفقیت انجام شد. تعداد ثبت‌شده: ' . intval($sync_result['inserted']) . '، تکراری یا ردشده: ' . intval($sync_result['skipped']);
        } else {
            $sync_message = 'دریافت گزارش‌ها کامل نشد: ' . sanitize_text_field($sync_result['message'] ?? 'خطای نامشخص.');
        }
    }

    if (isset($_POST['fklp_save_integration_settings']) && check_admin_referer('fklp_integration_settings_nonce')) {
        update_option('fklp_display_cutoff_date', fklp_is_valid_date($_POST['fklp_display_cutoff_date'] ?? '') ? sanitize_text_field($_POST['fklp_display_cutoff_date']) : '');
        update_option('fklp_retention_days', max(1, min(3650, intval($_POST['fklp_retention_days'] ?? 45))));
        update_option('fklp_reports_api_url', untrailingslashit(esc_url_raw(trim($_POST['fklp_reports_api_url'] ?? ''))));
        update_option('fklp_reports_api_key', sanitize_text_field($_POST['fklp_reports_api_key'] ?? ''));
        update_option('fklp_sync_batch_limit', max(1, min(500, intval($_POST['fklp_sync_batch_limit'] ?? 100))));
        $sync_interval = sanitize_key($_POST['fklp_sync_interval'] ?? 'daily');
        $allowed_intervals = array('hourly', 'fklp_every_6_hours', 'twicedaily', 'daily', 'fklp_weekly');
        update_option('fklp_sync_interval', in_array($sync_interval, $allowed_intervals, true) ? $sync_interval : 'daily');
        fklp_schedule_sync_event(true);
        fklp_cleanup_expired_projects();
        $sync_message = 'تنظیمات همگام‌سازی ذخیره شد.';
    }

    if (isset($_POST['fklp_save_settings']) && check_admin_referer('fklp_settings_nonce')) {
        update_option('fklp_total_projects', intval($_POST['fklp_total_projects']));
        update_option('fklp_full_display_count', intval($_POST['fklp_full_display_count']));
        update_option('fklp_home_recent_count', max(1, min(10, intval($_POST['fklp_home_recent_count'] ?? 10))));
        echo '<div class="updated"><p>تنظیمات با موفقیت ذخیره شد.</p></div>';
    }
    $total = get_option('fklp_total_projects', '10');
    $full = get_option('fklp_full_display_count', '3');
    $home_recent = get_option('fklp_home_recent_count', '10');
    $cutoff_date = get_option('fklp_display_cutoff_date', '');
    $retention_days = get_option('fklp_retention_days', '45');
    $api_url = get_option('fklp_reports_api_url', 'https://fanikara-ai-project.liara.run');
    $api_key = get_option('fklp_reports_api_key', '');
    $sync_batch_limit = get_option('fklp_sync_batch_limit', '100');
    $sync_interval = fklp_get_sync_recurrence();
    ?>
    <?php if ($sync_message) : ?><div class="notice notice-info">
            <p><?php echo esc_html($sync_message); ?></p>
        </div><?php endif; ?>
    <div class="wrap" style="direction: rtl; font-family: tahoma;">
        <h1>تنظیمات نمایش پروژه‌ها</h1>
        <form method="post">
            <?php wp_nonce_field('fklp_settings_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th>تعداد کل پروژه‌های قابل نمایش (N):</th>
                    <td><input type="text" name="fklp_total_projects" value="<?php echo esc_attr($total); ?>" /></td>
                </tr>
                <tr>
                    <th>تعداد پروژه‌ها با نمایش کامل (کارت + گزارش فنی):</th>
                    <td><input type="text" name="fklp_full_display_count" value="<?php echo esc_attr($full); ?>" /></td>
                </tr>
                <tr>
                    <th>تعداد گزارش‌های اخیر صفحه اول (حداکثر ۱۰):</th>
                    <td><input type="number" name="fklp_home_recent_count" min="1" max="10" value="<?php echo esc_attr($home_recent); ?>" /></td>
                </tr>
            </table>
            <p><input type="submit" name="fklp_save_settings" class="button button-primary" value="ذخیره تنظیمات"></p>
        </form>
        <div style="margin-top: 18px; padding: 14px 16px; max-width: 760px; background: #fff; border: 1px solid #dcdcde; border-radius: 6px;">
            <strong>شورت‌کد گزارش‌های اخیر صفحه اصلی</strong>
            <p style="margin: 8px 0 10px;">این شورت‌کد را در محتوای برگه صفحه اصلی قرار دهید:</p>
            <div style="display: flex; gap: 8px; align-items: center; direction: ltr;">
                <input id="fklp-home-recent-shortcode" type="text" readonly value="[local_home_recent_projects]" style="width: 300px; max-width: 100%; font-family: monospace;" />
                <button type="button" class="button" id="fklp-copy-home-recent-shortcode">کپی شورت‌کد</button>
                <span id="fklp-home-recent-copy-status" style="color: #008a20; display: none;">کپی شد</span>
            </div>
        </div>
        <script>
            (function () {
                var button = document.getElementById('fklp-copy-home-recent-shortcode');
                var input = document.getElementById('fklp-home-recent-shortcode');
                var status = document.getElementById('fklp-home-recent-copy-status');
                if (!button || !input || !status) return;
                var showCopied = function () {
                    status.style.display = 'inline';
                    window.setTimeout(function () { status.style.display = 'none'; }, 1800);
                };
                button.addEventListener('click', function () {
                    input.select();
                    input.setSelectionRange(0, 99999);
                    if (navigator.clipboard && window.isSecureContext) {
                        navigator.clipboard.writeText(input.value).then(showCopied);
                    } else {
                        if (document.execCommand('copy')) showCopied();
                    }
                });
            }());
        </script>
    </div>
    <div class="wrap" style="direction: rtl; font-family: tahoma;">
        <h2>همگام‌سازی خودکار گزارش‌ها</h2>
        <form method="post">
            <?php wp_nonce_field('fklp_integration_settings_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th>آدرس سرویس گزارش‌ها</th>
                    <td><input type="url" name="fklp_reports_api_url" style="width:100%; max-width:520px; direction:ltr;"
                            value="<?php echo esc_attr($api_url); ?>" /></td>
                </tr>
                <tr>
                    <th>کلید ارتباطی دو سیستم</th>
                    <td><input type="password" name="fklp_reports_api_key"
                            style="width:100%; max-width:520px; direction:ltr;" value="<?php echo esc_attr($api_key); ?>"
                            autocomplete="new-password" /></td>
                </tr>
                <tr>
                    <th>مدت نگهداری گزارش‌ها</th>
                    <td><input type="number" min="1" max="3650" name="fklp_retention_days"
                            value="<?php echo esc_attr($retention_days); ?>" /> روز<br><small>گزارش‌های قدیمی‌تر از این مدت،
                            هم از خروجی شورت‌کد حذف می‌شوند و هم در پاک‌سازی خودکار از جدول وردپرس حذف خواهند شد.</small>
                    </td>
                </tr>
                <tr>
                    <th>تاریخ شروع نمایش</th>
                    <td><input type="date" name="fklp_display_cutoff_date"
                            value="<?php echo esc_attr($cutoff_date); ?>" /><br><small>اگر این تاریخ تنظیم شود، گزارش‌های
                            قدیمی‌تر از آن نیز نمایش داده نمی‌شوند.</small></td>
                </tr>
                <tr>
                    <th>دوره دریافت گزارش‌های جدید</th>
                    <td><select name="fklp_sync_interval">
                            <option value="hourly" <?php selected($sync_interval, 'hourly'); ?>>هر ساعت</option>
                            <option value="fklp_every_6_hours" <?php selected($sync_interval, 'fklp_every_6_hours'); ?>>هر ۶
                                ساعت</option>
                            <option value="twicedaily" <?php selected($sync_interval, 'twicedaily'); ?>>دو بار در روز
                            </option>
                            <option value="daily" <?php selected($sync_interval, 'daily'); ?>>روزانه</option>
                            <option value="fklp_weekly" <?php selected($sync_interval, 'fklp_weekly'); ?>>هفتگی</option>
                        </select><br><small>وردپرس در این بازه، گزارش‌های منتشرشده و جدید را از سرویس اصلی دریافت
                            می‌کند.</small></td>
                </tr>
                <tr>
                    <th>تعداد گزارش در هر دریافت</th>
                    <td><input type="number" min="1" max="500" name="fklp_sync_batch_limit"
                            value="<?php echo esc_attr($sync_batch_limit); ?>" /></td>
                </tr>
            </table>
            <p><input type="submit" name="fklp_save_integration_settings" class="button button-primary"
                    value="ذخیره تنظیمات همگام‌سازی" /></p>
        </form>
        <form method="post">
            <?php wp_nonce_field('fklp_sync_nonce'); ?>
            <p><input type="submit" name="fklp_sync_now" class="button button-secondary"
                    value="دریافت گزارش‌های منتشرشده اکنون" /></p>
        </form>
    </div>
<?php
}

function fklp_health_page()
{
    if (isset($_GET['action']) && $_GET['action'] == 'refresh') {
        fklp_get_health_report(true);
        echo '<div class="updated"><p>لیست سلامت پروژه‌ها با موفقیت به صورت زنده بروزرسانی شد.</p></div>';
    }

    $report = fklp_get_health_report();
?>
    <div class="wrap" style="direction: rtl; font-family: tahoma;">
        <h1>🚦 گزارش سلامت و پایش وضعیت پروژه‌ها</h1>
        <p>این سیستم به صورت خودکار تمامی صفحات دارای شورت‌کد <code>[local_recent_projects]</code> را اسکن کرده و مواردی که
            فاقد محتوای تازه هستند را جهت جلوگیری از افت سئو محلی مشخص می‌کند. (بروزرسانی خودکار کش هر روز ساعت ۳:۰۰ بامداد
            به وقت ایران انجام می‌شود)</p>

        <div style="margin-bottom: 20px;">
            <a href="?page=fklp-health&action=refresh" class="button button-primary">🔄 بروزرسانی زنده و اسکن مجدد
                دیتابیس</a>
        </div>

        <h2 style="color: #d32f2f; margin-top: 30px;">❌ صفحاتی که شورت‌کد دارند اما هیچ پروژه‌ای برایشان ثبت نشده است
            (تعداد: <?php echo count($report['no_projects']); ?>)</h2>
        <table class="wp-list-table widefat fixed stripped">
            <thead>
                <tr>
                    <th style="width: 100px;">ID صفحه</th>
                    <th>عنوان صفحه هدف</th>
                    <th style="width: 150px;">وضعیت</th>
                    <th style="width: 150px;">عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($report['no_projects'])): ?>
                    <tr>
                        <td colspan="4">تبریک! هیچ صفحه‌ای بدون پروژه رها نشده است. 🎉</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($report['no_projects'] as $p): ?>
                        <tr>
                            <td><code>#<?php echo esc_html($p['id']); ?></code></td>
                            <td><a href="<?php echo get_permalink($p['id']); ?>"
                                    target="_blank"><strong><?php echo esc_html($p['title']); ?></strong></a></td>
                            <td><span style="color: #d32f2f; font-weight: bold;">🔴 بدون پروژه فعال</span></td>
                            <td>
                                <a href="?page=fklp-projects&target_post_id=<?php echo $p['id']; ?>"
                                    class="button button-small button-primary">ثبت پروژه جدید</a>
                                <a href="<?php echo get_edit_post_link($p['id']); ?>" class="button button-small"
                                    target="_blank">ویرایش صفحه</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <h2 style="color: #e65100; margin-top: 40px;">⚠️ صفحاتی که بیش از ۳۰ روز است آپدیت نشده‌اند (تعداد:
            <?php echo count($report['expired_projects']); ?>)</h2>
        <table class="wp-list-table widefat fixed stripped">
            <thead>
                <tr>
                    <th style="width: 100px;">ID صفحه</th>
                    <th>عنوان صفحه هدف</th>
                    <th style="width: 200px;">تاریخ آخرین پروژه ثبت شده</th>
                    <th style="width: 150px;">وضعیت سن محتوا</th>
                    <th style="width: 150px;">عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($report['expired_projects'])): ?>
                    <tr>
                        <td colspan="5">عالی است! تمامی صفحات در ۳۰ روز اخیر پروژه جدید دریافت کرده‌اند. 🚀</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($report['expired_projects'] as $p): ?>
                        <tr>
                            <td><code>#<?php echo esc_html($p['id']); ?></code></td>
                            <td><a href="<?php echo get_permalink($p['id']); ?>"
                                    target="_blank"><strong><?php echo esc_html($p['title']); ?></strong></a></td>
                            <td><strong
                                    style="color: #555;"><?php echo esc_html(fklp_gregorian_to_jalali_text($p['last_date'])); ?></strong>
                                (میلادی: <?php echo esc_html($p['last_date']); ?>)</td>
                            <td><span style="color: #e65100; font-weight: bold;">🟡 منقضی شده (> ۳۰ روز)</span></td>
                            <td>
                                <a href="?page=fklp-projects&target_post_id=<?php echo $p['id']; ?>"
                                    class="button button-small button-primary">ثبت پروژه جدید</a>
                                <a href="<?php echo get_edit_post_link($p['id']); ?>" class="button button-small"
                                    target="_blank">ویرایش صفحه</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php
}

function fklp_projects_page()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'fanikara_local_projects';

    // حذف تکی
    if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
        check_admin_referer('fklp_delete_action', 'fklp_delete_nonce');
        $wpdb->delete($table_name, array('id' => intval($_GET['id'])));
        fklp_trigger_health_clear();
        echo '<div class="updated"><p>پروژه حذف شد.</p></div>';
    }

    // حذف گروهی (Bulk Action)
    if (isset($_POST['fklp_bulk_action']) && $_POST['fklp_bulk_action'] === 'delete' && !empty($_POST['bulk_ids']) && is_array($_POST['bulk_ids'])) {
        check_admin_referer('fklp_bulk_action_nonce', 'fklp_bulk_nonce');
        $ids_to_delete = array_map('intval', $_POST['bulk_ids']);
        $ids_to_delete = array_filter($ids_to_delete);

        if (!empty($ids_to_delete)) {
            $ids_placeholder = implode(',', array_fill(0, count($ids_to_delete), '%d'));
            $wpdb->query($wpdb->prepare("DELETE FROM $table_name WHERE id IN ($ids_placeholder)", $ids_to_delete));
            fklp_trigger_health_clear();
            echo '<div class="updated"><p>تعداد ' . count($ids_to_delete) . ' پروژه با موفقیت به صورت دسته‌جمعی حذف شدند.</p></div>';
        }
    }

    if (isset($_POST['fklp_save_project']) && check_admin_referer('fklp_project_nonce')) {
        $data = array(
            'post_id'         => intval($_POST['post_id']),
            'post_title'      => sanitize_text_field($_POST['post_title']),
            'brand_name'      => sanitize_text_field(!empty($_POST['brand_name']) ? $_POST['brand_name'] : 'برچسب'),
            'city'            => sanitize_text_field($_POST['city']),
            'district'        => sanitize_text_field($_POST['district']),
            'service_type'    => sanitize_text_field($_POST['service_type']),
            'location_detail' => sanitize_text_field($_POST['location_detail']),
            'short_desc'      => sanitize_text_field($_POST['short_desc']),
            'problem_cause'   => sanitize_text_field($_POST['problem_cause']),
            'tools_used'      => sanitize_text_field($_POST['tools_used']),
            'duration'        => sanitize_text_field(trim(preg_replace('/[^\d]/', '', $_POST['duration'] ?? ''))),
            'project_date'    => sanitize_text_field($_POST['project_date']),
            'tech_report'     => sanitize_textarea_field($_POST['tech_report']),
        );

        if (!empty($_POST['project_id'])) {
            $wpdb->update($table_name, $data, array('id' => intval($_POST['project_id'])));
            echo '<div class="updated"><p>پروژه با موفقیت بروزرسانی شد.</p></div>';
        } else {
            $wpdb->insert($table_name, $data);
            echo '<div class="updated"><p>پروژه جدید با موفقیت ثبت شد.</p></div>';
        }
        fklp_trigger_health_clear();
    }

    if (isset($_POST['fklp_export_json'])) {
        $results = $wpdb->get_results("SELECT * FROM $table_name", ARRAY_A);
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename=fanikara-projects-export.json');
        echo json_encode($results, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    if (isset($_POST['fklp_import_data']) && !empty($_FILES['import_file']['tmp_name'])) {
        $file_content = file_get_contents($_FILES['import_file']['tmp_name']);
        $decoded_data = json_decode($file_content, true);
        if (is_array($decoded_data)) {
            foreach ($decoded_data as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $source_uid = sanitize_text_field($row['source_uid'] ?? $row['uid'] ?? '');
                if ($source_uid && $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE source_uid = %s LIMIT 1", $source_uid))) {
                    fklp_record_consumed_uid($source_uid, 'imported');
                    continue;
                }
                $imported = $wpdb->insert($table_name, array(
                    'post_id'           => intval($row['post_id'] ?? 0),
                    'post_title'        => sanitize_text_field($row['post_title'] ?? ''),
                    'brand_name'        => sanitize_text_field($row['brand_name'] ?? ''),
                    'city'              => sanitize_text_field($row['city'] ?? ''),
                    'district'          => sanitize_text_field($row['district'] ?? ''),
                    'service_type'      => sanitize_text_field($row['service_type'] ?? ''),
                    'location_detail'   => sanitize_text_field($row['location_detail'] ?? ''),
                    'short_desc'        => sanitize_text_field($row['short_desc'] ?? ''),
                    'problem_cause'     => sanitize_text_field($row['problem_cause'] ?? ''),
                    'tools_used'        => sanitize_text_field($row['tools_used'] ?? ''),
                    'duration'          => sanitize_text_field($row['duration'] ?? ''),
                    'project_date'      => fklp_is_valid_date($row['project_date'] ?? '') ? sanitize_text_field($row['project_date']) : current_time('Y-m-d'),
                    'tech_report'       => sanitize_textarea_field($row['tech_report'] ?? ''),
                    'source_uid'        => $source_uid,
                    'source_status'     => sanitize_text_field($row['source_status'] ?? $row['status'] ?? 'published'),
                    'source_created_at' => sanitize_text_field($row['source_created_at'] ?? $row['created_at'] ?? ''),
                ));
                if (false !== $imported && $source_uid) {
                    fklp_record_consumed_uid($source_uid, 'imported');
                }
            }
            fklp_trigger_health_clear();
            echo '<div class="updated"><p>اطلاعات پروژه‌ها با موفقیت ایمپورت شد.</p></div>';
        } else {
            echo '<div class="error"><p>ساختار فایل جهت ایمپورت معتبر نمی‌باشد.</p></div>';
        }
    }

    $edit_project = null;
    if (isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['id'])) {
        $edit_project = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", intval($_GET['id'])));
    }

    $quick_post_id = isset($_GET['target_post_id']) ? intval($_GET['target_post_id']) : '';
    $quick_post_title = '';
    if (!empty($quick_post_id)) {
        $quick_post_title = get_the_title($quick_post_id);
    }

    $all_projects = $wpdb->get_results("SELECT * FROM $table_name ORDER BY id DESC");
?>
    <div class="wrap" style="direction: rtl; font-family:tahoma;">
        <h1>مدیریت پروژه‌های لوکال سئو (فنی‌کارا)</h1>
        <hr />

        <div style="background:#fff; padding:15px; border:1px solid #ccc; margin-bottom:20px;">
            <h3>ایمپورت و اکسپورت هوشمند داده‌ها (JSON)</h3>
            <form method="post" enctype="multipart/form-data" style="display:inline-block;">
                <input type="file" name="import_file" required />
                <input type="submit" name="fklp_import_data" class="button button-secondary"
                    value="ایمپورت فایل پروژه‌ها" />
            </form>
            <form method="post" style="display:inline-block; margin-right:15px;">
                <input type="submit" name="fklp_export_json" class="button button-secondary"
                    value="خروجی و اکسپورت کل داده‌ها" />
            </form>
        </div>

        <div style="background:#fff; padding:20px; border:1px solid #ccc; margin-bottom:20px;">
            <h3><?php echo $edit_project ? 'ویرایش پروژه شماره ' . $edit_project->id : 'ثبت مشخصات پروژه عملیاتی جدید'; ?>
            </h3>

            <div
                style="background: #f7f7f7; border-right: 4px solid #0073aa; padding: 12px 15px; margin: 15px 0 20px 0; border-radius: 0 4px 4px 0;">
                <span style="font-weight: bold; color: #0073aa; display: block; margin-bottom: 5px;">💡 راهنمای استفاده و
                    نمایش در فرانت‌اند:</span>
                <span style="font-size: 13px; color: #444; line-height: 1.6;">
                    برای نمایش لیست پروژه‌ها و کارت‌های گزارش فنی در صفحه هدف، کافیست شورت‌کد زیر را کپی کرده و در داخل
                    محتوای برگه یا نوشته موردنظر قرار دهید:
                </span>
                <div style="margin-top: 10px;">
                    <code
                        style="font-family: Consolas, Monaco, monospace; font-size: 14px; background: #fff; padding: 5px 12px; border: 1px solid #ccc; border-radius: 4px; display: inline-block; direction: ltr; font-weight: bold; color: #333; user-select: all;">[local_recent_projects]</code>
                </div>
            </div>

            <form method="post">
                <?php wp_nonce_field('fklp_project_nonce'); ?>
                <input type="hidden" name="project_id"
                    value="<?php echo $edit_project ? esc_attr($edit_project->id) : ''; ?>" />

                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px;">
                    <p><label><strong>ID پست هدف:</strong></label><input type="text" name="post_id" style="width:100%"
                            value="<?php echo $edit_project ? esc_attr($edit_project->post_id) : esc_attr($quick_post_id); ?>"
                            required /></p>
                    <p><label><strong>عنوان پست (صفحه هدف - مثلا: لوله بازکنی سعادت آباد):</strong></label><input
                            type="text" name="post_title" style="width:100%"
                            value="<?php echo $edit_project ? esc_attr($edit_project->post_title) : esc_attr($quick_post_title); ?>"
                            required /></p>
                    <p><label><strong>نام برند اصلی سایت (مثال: برچسب یا فنی‌کارا):</strong></label><input type="text"
                            name="brand_name" style="width:100%"
                            value="<?php echo $edit_project ? esc_attr($edit_project->brand_name) : 'برچسب'; ?>" required />
                    </p>
                    <p><label><strong>شهر:</strong></label><input type="text" name="city" style="width:100%"
                            value="<?php echo $edit_project ? esc_attr($edit_project->city) : 'تهران'; ?>" required /></p>
                    <p><label><strong>منطقه / محله (مثلا: سعادت آباد):</strong></label><input type="text" name="district"
                            style="width:100%" value="<?php echo $edit_project ? esc_attr($edit_project->district) : ''; ?>"
                            required /></p>
                    <p><label><strong>نوع خدمت (مثال: تخلیه چاه، لوله بازکنی یا …):</strong></label><input type="text"
                            name="service_type" style="width:100%"
                            value="<?php echo $edit_project ? esc_attr($edit_project->service_type) : ''; ?>" required />
                    </p>
                    <p><label><strong>محدوده (خیابان فرعی):</strong></label><input type="text" name="location_detail"
                            style="width:100%"
                            value="<?php echo $edit_project ? esc_attr($edit_project->location_detail) : ''; ?>" required />
                    </p>
                    <p><label><strong>عنوان کوتاه (مثال: درآوردن گردنبند طلا از کفشور آشپزخانه):</strong></label><input
                            type="text" name="short_desc" style="width:100%"
                            value="<?php echo $edit_project ? esc_attr($edit_project->short_desc) : ''; ?>" required /></p>
                    <p><label><strong>علت مشکل (مثلا: سر خوردن گردنبند به داخل کفشور آشپزخانه):</strong></label><input
                            type="text" name="problem_cause" style="width:100%"
                            value="<?php echo $edit_project ? esc_attr($edit_project->problem_cause) : ''; ?>" required />
                    </p>
                    <p><label><strong>ابزار و اقلام استفاده شده (مثلا: ابزار تخصصی پنجه‌ای و دوربین ویدئومتری
                                لوله):</strong></label><input type="text" name="tools_used" style="width:100%"
                            value="<?php echo $edit_project ? esc_attr($edit_project->tools_used) : ''; ?>" required /></p>
                    <p><label><strong>مدت زمان کار به دقیقه (مثلا: 120):</strong></label><input type="text" name="duration"
                            style="width:100%" value="<?php echo $edit_project ? esc_attr($edit_project->duration) : ''; ?>"
                            required /></p>
                    <p><label><strong>تاریخ سفارش (انتخاب از تقویم میلادی):</strong></label><input type="date"
                            name="project_date" style="width:100%; padding: 4px; box-sizing: border-box;"
                            value="<?php echo $edit_project ? esc_attr($edit_project->project_date) : ''; ?>" required />
                    </p>
                </div>
                <p><label><strong>گزارش فنی و تخصصی تکنسین (همراه با تصویر سازی ذهنی و ارائه سیگنال های
                            EEAT):</strong></label>
                    <textarea name="tech_report"
                        style="width:100%; height:120px;"><?php echo $edit_project ? esc_textarea($edit_project->tech_report) : ''; ?></textarea>
                </p>

                <p><input type="submit" name="fklp_save_project" class="button button-primary"
                        value="ذخیره نهایی اطلاعات" /></p>
            </form>
        </div>

        <h3>آرشیو پروژه‌های ثبت شده</h3>
        <form method="post" id="fklp-bulk-action-form"
            onsubmit="return confirm('آیا از انجام کارهای دسته‌جمعی روی گزینه‌های انتخاب شده مطمئن هستید؟');">
            <?php wp_nonce_field('fklp_bulk_action_nonce', 'fklp_bulk_nonce'); ?>

            <div class="alignleft actions bulkactions" style="margin-bottom: 10px; display: flex; gap: 5px;">
                <select name="fklp_bulk_action" id="bulk-action-selector-top">
                    <option value="-1">کارهای دسته‌جمعی</option>
                    <option value="delete">حذف</option>
                </select>
                <input type="submit" class="button action" value="اعمال">
            </div>

            <table class="wp-list-table widefat fixed stripped">
                <thead>
                    <tr>
                        <td id="cb" class="manage-column column-cb check-column" style="width: 2.2em;"><input
                                id="cb-select-all-1" type="checkbox"></td>
                        <th style="width: 80px;">ID پروژه</th>
                        <th style="width: 80px;">ID پست</th>
                        <th>نام برند</th>
                        <th>شهر و محله</th>
                        <th>نوع خدمت</th>
                        <th>عنوان کوتاه پروژه</th>
                        <th>تاریخ انجام (شمسی متنی)</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($all_projects)): ?>
                        <tr>
                            <td colspan="9">هیچ پروژه‌ای یافت نشد.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($all_projects as $p): ?>
                            <tr>
                                <th scope="row" class="check-column"><input type="checkbox" name="bulk_ids[]"
                                        value="<?php echo $p->id; ?>"></th>
                                <td><code>#<?php echo esc_html($p->id); ?></code></td>
                                <td><?php echo esc_html($p->post_id); ?></td>
                                <td><strong><?php echo esc_html($p->brand_name); ?></strong></td>
                                <td><?php echo esc_html($p->city) . ' / ' . esc_html($p->district); ?></td>
                                <td><strong><?php echo esc_html($p->service_type); ?></strong></td>
                                <td><?php echo esc_html($p->short_desc); ?></td>
                                <td><?php echo esc_html(fklp_gregorian_to_jalali_text($p->project_date)); ?></td>
                                <td>
                                    <a href="?page=fklp-projects&action=edit&id=<?php echo $p->id; ?>"
                                        class="button button-small">ویرایش</a>
                                    <?php
                                    $delete_url = wp_nonce_url('?page=fklp-projects&action=delete&id=' . $p->id, 'fklp_delete_action', 'fklp_delete_nonce');
                                    ?>
                                    <a href="<?php echo esc_url($delete_url); ?>" class="button button-small button-link-delete"
                                        onclick="return confirm('آیا از حذف آیتم مطمئن هستید؟')">حذف</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td class="manage-column column-cb check-column"><input id="cb-select-all-2" type="checkbox"></td>
                        <th>ID پروژه</th>
                        <th>ID پست</th>
                        <th>نام برند</th>
                        <th>شهر و محله</th>
                        <th>نوع خدمت</th>
                        <th>عنوان کوتاه پروژه</th>
                        <th>تاریخ انجام (شمسی متنی)</th>
                        <th>عملیات</th>
                    </tr>
                </tfoot>
            </table>
        </form>

        <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('#cb-select-all-1, #cb-select-all-2').on('change', function() {
                    var isChecked = $(this).prop('checked');
                    $('input[name="bulk_ids[]"]').prop('checked', isChecked);
                    $('#cb-select-all-1, #cb-select-all-2').prop('checked', isChecked);
                });
            });
        </script>
    </div>
<?php
}

add_shortcode('local_recent_projects', 'fklp_render_shortcode');
function fklp_render_shortcode()
{
    global $wpdb, $post;
    if (!is_singular()) return '';

    $current_post_id = $post->ID;
    $table_name = $wpdb->prefix . 'fanikara_local_projects';

    $total_limit = intval(get_option('fklp_total_projects', '10'));
    $full_limit = intval(get_option('fklp_full_display_count', '3'));
    $cutoff_date = fklp_get_display_cutoff_date();

    $today = current_time('Y-m-d');
    $query = "SELECT * FROM $table_name WHERE post_id = %d AND source_status = 'published' AND project_date < %s";
    $query_args = array($current_post_id, $today);
    $query .= " AND project_date >= %s";
    $query_args[] = $cutoff_date;
    $query .= " ORDER BY project_date DESC LIMIT %d";
    $query_args[] = max(1, $total_limit);
    $projects = $wpdb->get_results($wpdb->prepare($query, $query_args));

    if (empty($projects)) {
        return '';
    }

    $full_projects = array_slice($projects, 0, $full_limit);
    $archive_projects = array_slice($projects, $full_limit);

    $district_name = !empty($projects[0]->district) ? esc_html($projects[0]->district) : 'این محدوده';

    ob_start();
?>
    <style>
        .fklp-container {
            direction: rtl;
            text-align: right;
            margin: 28px 0;
            font-family: inherit;
            color: #24324a;
        }

        .fklp-container * {
            box-sizing: border-box;
        }

        .fklp-card {
            position: relative;
            overflow: hidden;
            background: linear-gradient(145deg, #ffffff 0%, #f7fbff 100%);
            border: 1px solid #dce8f5;
            border-radius: 18px;
            padding: 22px;
            margin-bottom: 22px;
            box-shadow: 0 8px 26px rgba(30, 73, 120, 0.08);
            transition: transform .22s ease, box-shadow .22s ease, border-color .22s ease;
        }

        .fklp-card::before {
            content: "";
            position: absolute;
            inset: 0 0 auto 0;
            height: 4px;
            background: linear-gradient(90deg, #1976d2, #42a5f5, #26a69a);
        }

        .fklp-card:hover {
            transform: translateY(-3px);
            border-color: #b8d4ee;
            box-shadow: 0 14px 32px rgba(30, 73, 120, 0.14);
        }

        .fklp-title {
            font-size: clamp(17px, 2vw, 21px);
            color: #1559a6;
            margin-top: 0;
            margin-bottom: 16px;
            font-weight: 800;
            border: none;
            padding: 0;
            line-height: 1.65;
        }

        .fklp-figure {
            margin: 0;
            padding: 0;
        }

        .fklp-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin: 0 0 16px 0;
            padding: 0;
            list-style: none;
        }

        .fklp-grid-item {
            flex: 1 1 calc(25% - 10px);
            min-width: 150px;
            padding: 13px;
            border: 1px solid #e1ebf5;
            border-radius: 12px;
            background: rgba(255, 255, 255, .72);
        }

        .fklp-grid-item strong {
            display: block;
            color: #36506d;
            font-size: 13px;
            margin-bottom: 5px;
        }

        .fklp-grid-item span {
            color: #52657d;
            font-size: 13px;
            line-height: 1.8;
        }

        .fklp-report {
            background: #eef7ff;
            border-right: 4px solid #1976d2;
            padding: 15px 17px;
            margin: 0 0 16px 0;
            border-radius: 12px;
        }

        .fklp-report strong {
            display: block;
            color: #1559a6;
            margin-bottom: 7px;
            font-size: 13px;
        }

        .fklp-report p {
            margin: 0;
            color: #3f5065;
            font-size: 14px;
            line-height: 2;
        }

        .fklp-details {
            border: 1px solid #d8e7f4;
            border-radius: 12px;
            background: #fff;
            margin-bottom: 16px;
        }

        .fklp-details summary {
            cursor: pointer;
            padding: 12px 15px;
            color: #1559a6;
            font-size: 13px;
            font-weight: 700;
            list-style-position: inside;
        }

        .fklp-details[open] summary {
            border-bottom: 1px solid #e1ebf5;
            background: #f7fbff;
        }

        .fklp-details-content {
            padding: 13px 15px;
            color: #3f5065;
            font-size: 14px;
            line-height: 2;
        }

        .fklp-footer {
            border-top: 1px dashed #d5e1ed;
            padding-top: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            font-size: 12px;
            color: #718096;
        }

        .fklp-footer cite {
            font-style: normal;
            font-weight: 700;
            color: #36506d;
        }

        .fklp-archive-intro {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 16px;
            color: #36506d;
            margin-top: 35px;
            margin-bottom: 13px;
            font-weight: 800;
            border-bottom: 2px solid #dce8f5;
            padding-bottom: 10px;
        }

        .fklp-table-wrapper {
            overflow-x: auto;
            border: 1px solid #dce8f5;
            border-radius: 14px;
            box-shadow: 0 5px 18px rgba(30, 73, 120, .05);
        }

        .fklp-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            background: #fff;
        }

        .fklp-table th,
        .fklp-table td {
            border-bottom: 1px solid #e5edf5;
            padding: 12px;
            text-align: right;
            line-height: 1.8;
        }

        .fklp-table th {
            background: #f3f8fd;
            color: #36506d;
            font-weight: 800;
        }

        .fklp-table tbody tr {
            transition: background .18s ease;
        }

        .fklp-table tbody tr:hover {
            background: #f7fbff;
        }

        @media (max-width: 768px) {
            .fklp-card {
                padding: 17px;
                border-radius: 14px;
            }

            .fklp-grid-item {
                flex: 1 1 calc(50% - 10px);
            }

            .fklp-table {
                font-size: 11px;
            }

            .fklp-footer {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>

    <div class="fklp-container" id="fklp-container-box">
        <?php foreach ($full_projects as $index => $p): ?>
            <article class="fklp-card">
                <header>
                    <h3 class="fklp-title">🛠️ گزارش پروژه شماره <?php echo ($index + 1); ?>:
                        <?php echo esc_html($p->short_desc); ?></h3>
                </header>

                <figure class="fklp-figure">
                    <aside>
                        <section class="fklp-grid">
                            <div class="fklp-grid-item">
                                <strong>📍 محدوده:</strong>
                                <span><?php echo esc_html($p->city) . '، ' . esc_html($p->district) . ' (' . esc_html($p->location_detail) . ')'; ?></span>
                            </div>
                            <div class="fklp-grid-item">
                                <strong>⚠️ علت مشکل:</strong>
                                <span><?php echo esc_html($p->problem_cause); ?></span>
                            </div>
                            <div class="fklp-grid-item">
                                <strong>⚙️ ابزار و اقلام استفاده شده:</strong>
                                <span><?php echo esc_html($p->tools_used); ?></span>
                            </div>
                            <div class="fklp-grid-item">
                                <strong>⏱️ مدت زمان انجام:</strong>
                                <span><?php
                                        $raw_dur = trim(preg_replace('/[^\d]/', '', $p->duration));
                                        echo (!empty($raw_dur) ? esc_html($raw_dur) : esc_html($p->duration)) . ' دقیقه';
                                        ?></span>
                            </div>
                        </section>
                    </aside>
                </figure>

                <div class="fklp-report">
                    <strong>📝 گزارش فنی و جزئیات اجرای کار:</strong>
                    <p><?php echo esc_html($p->tech_report); ?></p>
                </div>

                <footer class="fklp-footer">
                    <div>
                        <span>برند و مجری: </span>
                        <cite><?php echo !empty($p->brand_name) ? esc_html($p->brand_name) : 'برچسب'; ?></cite>
                    </div>
                    <div>
                        <span>محل اجرای خدمت: </span>
                        <cite><?php echo esc_html($p->city) . '، ' . esc_html($p->district); ?></cite>
                    </div>
                    <div>
                        <span>تاریخ نهایی ثبت: </span>
                        <time
                            datetime="<?php echo esc_attr($p->project_date); ?>"><?php echo esc_html(fklp_gregorian_to_jalali_text($p->project_date)); ?></time>
                    </div>
                </footer>
            </article>
        <?php endforeach; ?>

        <?php if (!empty($archive_projects)): ?>
            <div class="fklp-archive-intro">📂 سایر خدماتی که اخیرا در <?php echo $district_name; ?> انجام داده‌ایم:</div>
            <div class="fklp-table-wrapper">
                <table class="fklp-table">
                    <thead>
                        <tr>
                            <th>نوع خدمت</th>
                            <th>محدوده</th>
                            <th>ابزار و اقلام استفاده شده</th>
                            <th>تاریخ نهایی ثبت</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($archive_projects as $ap): ?>
                            <tr>
                                <td><strong><?php echo esc_html($ap->service_type); ?></strong></td>
                                <td><?php echo esc_html($ap->city) . '، ' . esc_html($ap->location_detail); ?></td>
                                <td><?php echo esc_html($ap->tools_used); ?></td>
                                <td><?php echo esc_html(fklp_gregorian_to_jalali_text($ap->project_date)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php
    return ob_get_clean();
}

add_shortcode('local_home_recent_projects', 'fklp_render_home_recent_projects');
function fklp_render_home_recent_projects()
{
    if (! is_front_page()) {
        return '';
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'fanikara_local_projects';
    $limit = max(1, min(10, intval(get_option('fklp_home_recent_count', '10'))));
    $cutoff_date = fklp_get_display_cutoff_date();
    $today = current_time('Y-m-d');

    // این شورت‌کد فقط از دیتابیس محلی وردپرس می‌خواند و به API درخواست نمی‌فرستد.
    $projects = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table_name
         WHERE source_status = 'published'
           AND project_date >= %s
           AND project_date <= %s
         ORDER BY project_date DESC, source_created_at DESC, id DESC
         LIMIT %d",
        $cutoff_date,
        $today,
        $limit
    ));

    if (empty($projects)) {
        return '';
    }

    ob_start();
    ?>
    <style>
        .fklp-home-recent { direction: rtl; text-align: right; margin: 28px 0; font-family: inherit; color: #24324a; }
        .fklp-home-recent * { box-sizing: border-box; }
        .fklp-home-recent__title { margin: 0 0 18px; color: #1559a6; font-size: clamp(20px, 2.5vw, 28px); font-weight: 800; }
        .fklp-home-recent__carousel { position: relative; }
        .fklp-home-recent__viewport { overflow-x: auto; overflow-y: hidden; scroll-behavior: smooth; scroll-snap-type: x mandatory; scrollbar-width: thin; scrollbar-color: #9fc4e5 transparent; padding: 4px 2px 14px; }
        .fklp-home-recent__viewport::-webkit-scrollbar { height: 7px; }
        .fklp-home-recent__viewport::-webkit-scrollbar-thumb { background: #9fc4e5; border-radius: 999px; }
        .fklp-home-recent__grid { display: flex; flex-wrap: nowrap; gap: 16px; width: max-content; align-items: stretch; }
        .fklp-home-recent__card { position: relative; overflow: hidden; flex: 0 0 var(--fklp-home-card-width, 320px); width: var(--fklp-home-card-width, 320px); min-height: 100%; padding: 19px; border: 1px solid #dce8f5; border-radius: 16px; background: linear-gradient(145deg, #fff, #f7fbff); box-shadow: 0 8px 26px rgba(30, 73, 120, .08); scroll-snap-align: start; }
        .fklp-home-recent__card::before { content: ""; position: absolute; inset: 0 0 auto; height: 4px; background: linear-gradient(90deg, #1976d2, #42a5f5, #26a69a); }
        .fklp-home-recent__card h3 { margin: 0 0 10px; color: #1559a6; font-size: 16px; line-height: 1.7; }
        .fklp-home-recent__meta { display: flex; flex-wrap: wrap; gap: 7px; margin-bottom: 12px; color: #52657d; font-size: 12px; line-height: 1.8; }
        .fklp-home-recent__badge { padding: 2px 8px; border-radius: 999px; background: #eef7ff; color: #1559a6; }
        .fklp-home-recent__facts { display: grid; gap: 7px; margin: 0 0 14px; }
        .fklp-home-recent__fact { margin: 0; padding: 8px 10px; border: 1px solid #e1ebf5; border-radius: 10px; background: rgba(255, 255, 255, .72); color: #52657d; font-size: 12px; line-height: 1.8; }
        .fklp-home-recent__fact strong { display: block; color: #36506d; font-size: 11px; }
        .fklp-home-recent__report { margin: 0 0 14px; border-right: 4px solid #1976d2; border-radius: 10px; background: #eef7ff; overflow: hidden; }
        .fklp-home-recent__report summary { cursor: pointer; padding: 11px 13px; color: #1559a6; font-size: 12px; font-weight: 800; list-style-position: inside; }
        .fklp-home-recent__report[open] summary { border-bottom: 1px solid #d8e7f4; background: #e5f3ff; }
        .fklp-home-recent__desc { max-height: 10.5em; overflow-y: auto; margin: 0; padding: 0 13px 13px; color: #3f5065; font-size: 13px; line-height: 2; scrollbar-width: thin; }
        .fklp-home-recent__date { display: block; margin-top: 13px; padding-top: 10px; border-top: 1px dashed #d5e1ed; color: #718096; font-size: 11px; }
        .fklp-home-recent__controls { display: flex; align-items: center; justify-content: center; gap: 9px; margin-top: 4px; }
        .fklp-home-recent__control { display: inline-flex; align-items: center; justify-content: center; width: 36px; height: 32px; padding: 0; border: 1px solid #c9deef; border-radius: 9px; background: #f5faff; color: #1559a6; cursor: pointer; font: inherit; font-size: 18px; line-height: 1; }
        .fklp-home-recent__control:hover { background: #e5f3ff; border-color: #9fc4e5; }
        .fklp-home-recent__counter { min-width: 56px; color: #718096; font-size: 11px; text-align: center; }
        @media (max-width: 600px) { .fklp-home-recent__viewport { margin-inline: -4px; padding-inline: 4px; } .fklp-home-recent__grid { gap: 12px; } .fklp-home-recent__card { padding: 16px; } .fklp-home-recent__desc { max-height: 9.5em; } }
    </style>
    <section class="fklp-home-recent" aria-label="گزارش‌های اخیر پروژه‌ها">
        <h2 class="fklp-home-recent__title">گزارش‌های اخیر پروژه‌ها</h2>
        <div class="fklp-home-recent__carousel" data-fklp-home-carousel>
            <div class="fklp-home-recent__viewport" data-fklp-home-viewport tabindex="0" aria-label="Recent project reports">
                <div class="fklp-home-recent__grid">
            <?php foreach ($projects as $project): ?>
                <article class="fklp-home-recent__card">
                    <?php
                    $raw_home_duration = trim(preg_replace('/[^\d]/', '', $project->duration));
                    $home_duration = ! empty($raw_home_duration) ? $raw_home_duration . ' دقیقه' : $project->duration;
                    ?>
                    <h3><?php echo esc_html($project->short_desc); ?></h3>
                    <div class="fklp-home-recent__meta">
                        <span class="fklp-home-recent__badge"><?php echo esc_html($project->city); ?>، <?php echo esc_html($project->district); ?></span>
                        <span><?php echo esc_html($project->service_type); ?></span>
                    </div>
                    <div class="fklp-home-recent__facts">
                        <p class="fklp-home-recent__fact"><strong>📍 محدوده:</strong><?php echo esc_html($project->city) . '، ' . esc_html($project->district) . ' (' . esc_html($project->location_detail) . ')'; ?></p>
                        <p class="fklp-home-recent__fact"><strong>⚠ علت مشکل:</strong><?php echo esc_html($project->problem_cause); ?></p>
                        <p class="fklp-home-recent__fact"><strong>⚙ ابزار و اقلام استفاده شده:</strong><?php echo esc_html($project->tools_used); ?></p>
                        <p class="fklp-home-recent__fact"><strong>⏱ مدت زمان انجام:</strong><?php echo esc_html($home_duration); ?></p>
                    </div>
                    <details class="fklp-home-recent__report">
                        <summary>📖 توضیحات گزارش</summary>
                        <p class="fklp-home-recent__desc"><?php echo esc_html($project->tech_report); ?></p>
                    </details>
                    <time class="fklp-home-recent__date" datetime="<?php echo esc_attr($project->project_date); ?>">
                        تاریخ انجام: <?php echo esc_html(fklp_gregorian_to_jalali_text($project->project_date)); ?>
                    </time>
                </article>
            <?php endforeach; ?>
                </div>
            </div>
            <?php if (count($projects) > 1): ?>
                <div class="fklp-home-recent__controls" aria-label="Carousel controls">
                    <button type="button" class="fklp-home-recent__control" data-fklp-home-prev aria-label="Previous report">‹</button>
                    <span class="fklp-home-recent__counter" data-fklp-home-counter>1 / <?php echo count($projects); ?></span>
                    <button type="button" class="fklp-home-recent__control" data-fklp-home-next aria-label="Next report">›</button>
                </div>
            <?php endif; ?>
        </div>
    </section>
    <?php if (count($projects) > 1): ?>
        <script>
            (function () {
                var root = document.querySelector('[data-fklp-home-carousel]');
                if (!root) return;
                var viewport = root.querySelector('[data-fklp-home-viewport]');
                var cards = root.querySelectorAll('.fklp-home-recent__card');
                var counter = root.querySelector('[data-fklp-home-counter]');
                var current = 0;

                function getVisibleCards() {
                    if (window.matchMedia('(max-width: 600px)').matches) return 1;
                    if (window.matchMedia('(max-width: 900px)').matches) return 2;
                    return 3;
                }

                function resizeCards() {
                    var visibleCards = getVisibleCards();
                    var gap = parseFloat(window.getComputedStyle(root.querySelector('.fklp-home-recent__grid')).columnGap) || 0;
                    var availableWidth = Math.max(240, viewport.clientWidth - (gap * (visibleCards - 1)));
                    var cardWidth = Math.floor(availableWidth / visibleCards);
                    cards.forEach(function (card) {
                        card.style.setProperty('--fklp-home-card-width', cardWidth + 'px');
                    });
                }

                function moveTo(index) {
                    current = Math.max(0, Math.min(cards.length - 1, index));
                    if (cards[current]) {
                        cards[current].scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'nearest' });
                    }
                    if (counter) counter.textContent = (current + 1) + ' / ' + cards.length;
                }

                root.querySelector('[data-fklp-home-prev]').addEventListener('click', function () { moveTo(current - getVisibleCards()); });
                root.querySelector('[data-fklp-home-next]').addEventListener('click', function () { moveTo(current + getVisibleCards()); });
                resizeCards();
                window.addEventListener('resize', resizeCards, { passive: true });
            }());
        </script>
    <?php endif; ?>
    <?php
    return ob_get_clean();
}

function fklp_gregorian_to_jalali_text($g_date_str)
{
    if (empty($g_date_str) || $g_date_str == '0000-00-00') return '';

    $parts = explode('-', $g_date_str);
    if (count($parts) !== 3) return $g_date_str;

    $gy = intval($parts[0]);
    $gm = intval($parts[1]);
    $gd = intval($parts[2]);

    $g_d_m = array(0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334);
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = 355666 + (365 * $gy) + intval(($gy2 + 3) / 4) - intval(($gy2 + 99) / 100) + intval(($gy2 + 399) / 400) + $gd + $g_d_m[$gm - 1];
    $jy = -1595 + (33 * intval($days / 12053));
    $days %= 12053;
    $jy += 4 * intval($days / 1461);
    $days %= 1461;

    if ($days > 365) {
        $jy += intval(($days - 1) / 365);
        $days = ($days - 1) % 365;
    }

    if ($days < 186) {
        $jm = 1 + intval($days / 31);
        $jd = 1 + ($days % 31);
    } else {
        $jm = 7 + intval(($days - 186) / 30);
        $jd = 1 + (($days - 186) % 30);
    }

    $months = array(
        1 => 'فروردین',
        2 => 'اردیبهشت',
        3 => 'خرداد',
        4 => 'تیر',
        5 => 'مرداد',
        6 => 'شهریور',
        7 => 'مهر',
        8 => 'آبان',
        9 => 'آذر',
        10 => 'دی',
        11 => 'بهمن',
        12 => 'اسفند'
    );

    return $jd . ' ' . $months[$jm] . ' ' . $jy;
}
