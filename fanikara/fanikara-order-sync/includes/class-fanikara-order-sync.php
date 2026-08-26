<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Fanikara_Order_Sync
{
    const OPTION_KEY = 'fnk_order_sync_settings';
    const SITE_UUID_OPTION = 'fnk_order_sync_site_uuid';
    const CRON_HOOK = 'fnk_order_sync_process_queue';
    const CRON_SCHEDULE = 'fnk_order_sync_custom_interval';
    const LOCK_KEY = 'fnk_order_sync_lock';
    const CCT_SLUG = 'customers_service_orders';
    const QUEUE_TABLE_SUFFIX = 'fnk_order_sync_queue';
    const MAX_ATTEMPTS = 8;

    private static $instance;

    public static function init()
    {
        if (self::$instance instanceof self) {
            return self::$instance;
        }

        self::$instance = new self();
        self::$instance->register_hooks();
        return self::$instance;
    }

    public static function activate()
    {
        self::create_queue_table();
        self::ensure_site_uuid();
        self::schedule_cron();
    }

    public static function deactivate()
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        delete_transient(self::LOCK_KEY);
    }

    private function register_hooks()
    {
        add_filter('cron_schedules', array($this, 'register_schedule'));
        add_action(self::CRON_HOOK, array($this, 'process_queue'));
        add_action('jet-engine/custom-content-types/created-item/' . self::CCT_SLUG, array($this, 'queue_created_item'), 10, 3);
        add_action('jet-engine/custom-content-types/updated-item/' . self::CCT_SLUG, array($this, 'queue_requeued_item'), 10, 3);
        add_action('admin_menu', array($this, 'register_settings_page'));
        add_action('wp_dashboard_setup', array($this, 'register_dashboard_widget'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_post_fnk_order_sync_now', array($this, 'handle_sync_now'));
        add_action('admin_post_fnk_order_sync_retry_failed', array($this, 'handle_retry_failed'));
        add_action('admin_notices', array($this, 'render_admin_notice'));
        add_action('update_option_' . self::OPTION_KEY, array($this, 'reschedule_after_settings_update'), 10, 3);

        self::schedule_cron();
    }

    public function register_schedule($schedules)
    {
        $settings = $this->get_settings();
        $schedules[self::CRON_SCHEDULE] = array(
            'interval' => $this->get_interval_seconds($settings),
            'display' => __('Custom interval (Fanikara order sync)', 'fanikara-order-sync'),
        );
        return $schedules;
    }

    private static function schedule_cron()
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, self::CRON_SCHEDULE, self::CRON_HOOK);
        }
    }

    public function reschedule_after_settings_update($old_value, $value, $option)
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        $interval = $this->get_interval_seconds(is_array($value) ? $value : $this->get_settings());
        wp_schedule_event(time() + $interval, self::CRON_SCHEDULE, self::CRON_HOOK);
    }

    public function queue_created_item($item, $item_id, $item_handler)
    {
        if (!$this->is_queued_status($item)) {
            return;
        }

        $this->enqueue_item((int) $item_id, (array) $item, time());
    }

    public function queue_requeued_item($item, $previous_item, $item_handler)
    {
        if (!$this->is_queued_status($item)) {
            return;
        }

        if ((string) ($previous_item['status'] ?? '') === 'q') {
            return;
        }

        $item_id = !empty($item['_ID']) ? (int) $item['_ID'] : 0;
        if ($item_id > 0) {
            $this->enqueue_item($item_id, (array) $item, time());
        }
    }

    private function is_queued_status($item)
    {
        return isset($item['status']) && (string) $item['status'] === 'q';
    }

    private function enqueue_item($item_id, array $item, $submitted_at = null)
    {
        if ($item_id < 1) {
            return;
        }

        $payload = $this->build_payload($item_id, $item, $submitted_at);
        $validation = $this->validate_payload($payload);
        if (is_wp_error($validation)) {
            $this->upsert_queue($item_id, $payload, 'failed', $validation->get_error_message(), 0, null);
            return;
        }
        $payload = $validation;

        $this->upsert_queue($item_id, $payload, 'queued', '', 0, current_time('mysql', true));
        wp_schedule_single_event(time() + 10, self::CRON_HOOK);
    }

    public function process_queue($limit = 20)
    {
        if (get_transient(self::LOCK_KEY)) {
            return array('processed' => 0, 'message' => 'A sync process is already running.');
        }

        set_transient(self::LOCK_KEY, 1, 10 * MINUTE_IN_SECONDS);
        $processed = 0;
        $synced = 0;
        $failed = 0;

        try {
            $this->discover_untracked_queued_items($limit);
            foreach ($this->get_due_queue_items($limit) as $queue_item) {
                $processed++;
                $result = $this->process_queue_item($queue_item);
                if ($result === 'synced') {
                    $synced++;
                } elseif ($result === 'failed') {
                    $failed++;
                }
            }
        } finally {
            delete_transient(self::LOCK_KEY);
        }

        return array('processed' => $processed, 'synced' => $synced, 'failed' => $failed);
    }

    /**
     * Covers orders that were created before the plugin was activated or while
     * WordPress hooks were unavailable. Existing failed/synced rows are left
     * untouched; a user can deliberately requeue them from the CCT UI.
     */
    private function discover_untracked_queued_items($limit)
    {
        global $wpdb;
        $cct_table = $this->get_cct_table_name();
        $queue_table = $this->get_queue_table_name();
        if (!$this->table_exists($cct_table)) {
            return;
        }

        $limit = max(1, min(100, (int) $limit));
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT cct.* FROM `{$cct_table}` cct LEFT JOIN `{$queue_table}` queue ON queue.cct_item_id = cct._ID WHERE cct.status = 'q' AND queue.id IS NULL ORDER BY cct._ID ASC LIMIT %d",
            $limit
        ), ARRAY_A);
        foreach ($items as $item) {
            $this->enqueue_item((int) $item['_ID'], $item);
        }
    }

    private function process_queue_item($queue_item)
    {
        $item_id = (int) $queue_item->cct_item_id;
        $cct_item = $this->get_cct_item($item_id);
        if ($cct_item === null) {
            $this->update_queue($item_id, array('status' => 'failed', 'last_error' => 'CCT item no longer exists.', 'next_attempt_at' => null));
            return 'failed';
        }

        if (!$this->is_queued_status($cct_item)) {
            $this->update_queue($item_id, array('status' => 'failed', 'last_error' => 'CCT status is no longer q.', 'next_attempt_at' => null));
            return 'failed';
        }

        $queued_payload = json_decode((string) $queue_item->payload, true);
        $submitted_at = is_array($queued_payload) ? ($queued_payload['submitted_at'] ?? null) : null;
        $payload = $this->build_payload($item_id, $cct_item, $submitted_at);
        $validation = $this->validate_payload($payload);
        if (is_wp_error($validation)) {
            $this->update_queue($item_id, array('status' => 'failed', 'last_error' => $validation->get_error_message(), 'next_attempt_at' => null));
            return 'failed';
        }
        $payload = $validation;
        $target = $this->get_target_config();
        if (is_wp_error($target)) {
            $this->retry_or_fail($queue_item, $target->get_error_message(), false);
            return 'failed';
        }

        $response = $this->send_payload($payload, $target);
        if (!is_wp_error($response) && !empty($response['ok']) && !empty($response['erp_order_id'])) {
            $updated = $this->mark_cct_synced($item_id);
            if (is_wp_error($updated)) {
                $this->retry_or_fail($queue_item, $updated->get_error_message(), true);
                return 'failed';
            }

            $this->update_queue($item_id, array(
                'status' => 'synced',
                'payload' => wp_json_encode($payload),
                'target' => $target['label'],
                'erp_order_id' => (int) $response['erp_order_id'],
                'last_error' => null,
                'next_attempt_at' => null,
                'synced_at' => current_time('mysql', true),
            ));
            return 'synced';
        }

        $http_code = is_wp_error($response) ? 0 : (int) ($response['http_code'] ?? 0);
        $message = is_wp_error($response) ? $response->get_error_message() : (string) ($response['message'] ?? 'ERP did not accept the order.');
        $retryable = $http_code === 0 || $http_code === 408 || $http_code === 429 || $http_code >= 500;
        $this->retry_or_fail($queue_item, $message, $retryable, $http_code);
        return 'failed';
    }

    private function send_payload(array $payload, array $target)
    {
        $body = wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($body) || strlen($body) > 1048576) {
            return new WP_Error('fnk_order_sync_payload', 'The generated payload is invalid or too large.');
        }
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, $target['secret']);
        $response = wp_remote_post($target['endpoint'], array(
            'timeout' => 20,
            'headers' => array(
                'Accept' => 'application/json',
                'Content-Type' => 'application/json; charset=utf-8',
                'X-Fanikara-Timestamp' => $timestamp,
                'X-Fanikara-Signature' => $signature,
            ),
            'body' => $body,
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $http_code = (int) wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        if (strlen($response_body) > 1048576) {
            return array('ok' => false, 'message' => 'ERP returned an oversized response.', 'http_code' => $http_code);
        }
        $decoded = json_decode($response_body, true);
        if (!is_array($decoded)) {
            $decoded = array('ok' => false, 'message' => 'ERP returned an invalid JSON response.');
        }
        $decoded['http_code'] = $http_code;
        return $decoded;
    }

    private function retry_or_fail($queue_item, $message, $retryable, $http_code = 0)
    {
        $attempts = (int) $queue_item->attempts + 1;
        $message = sprintf('[HTTP %d] %s', (int) $http_code, sanitize_text_field((string) $message));
        $fields = array('attempts' => $attempts, 'last_error' => $message);

        if ($retryable && $attempts < self::MAX_ATTEMPTS) {
            $delay = min(12 * HOUR_IN_SECONDS, (int) pow(2, $attempts) * MINUTE_IN_SECONDS);
            $fields['status'] = 'retry';
            $fields['next_attempt_at'] = gmdate('Y-m-d H:i:s', time() + $delay);
        } else {
            $fields['status'] = 'failed';
            $fields['next_attempt_at'] = null;
        }

        $this->update_queue((int) $queue_item->cct_item_id, $fields);
    }

    private function build_payload($item_id, array $item, $submitted_at = null)
    {
        $value = function ($key) use ($item) {
            return isset($item[$key]) && is_scalar($item[$key]) ? (string) $item[$key] : '';
        };

        $submitted_at = $this->resolve_submission_timestamp($item, $submitted_at);

        return array(
            'schema_version' => 1,
            'external_id' => 'jet-cct:' . self::ensure_site_uuid() . ':' . (int) $item_id,
            'customer_phone' => $this->normalize_digits($value('customer_phone')),
            'fanikar_erp_id' => $this->normalize_digits(sanitize_text_field($value('fanikar_erp_id'))),
            'fanikar_wp_id' => $this->normalize_digits(sanitize_text_field($value('fanikar_wp_id'))),
            'service_erp_id' => $this->normalize_digits(sanitize_text_field($value('service_erp_id'))),
            'service_wp_id' => $this->normalize_digits(sanitize_text_field($value('service_wp_id'))),
            'service_wp_name' => sanitize_text_field($value('service_wp_name')),
            'city_erp_id' => $this->normalize_digits(sanitize_text_field($value('city_erp_id'))),
            'city_wp_id' => $this->normalize_digits(sanitize_text_field($value('city_wp_id'))),
            'city_wp_name' => sanitize_text_field($value('city_wp_name')),
            'area_name' => sanitize_text_field($value('area_name')),
            'referer_url' => trim($value('referer_url')),
            'date' => sanitize_text_field($value('date')),
            'time' => sanitize_text_field($value('time')),
            'date_time' => sanitize_text_field($value('date_time')),
            // This is captured when the CCT item is created and is the source
            // of truth for the ERP order time. date_time is retained verbatim.
            'submitted_at' => $submitted_at,
            'user_input' => sanitize_textarea_field($value('user_input')),
            'wordpress' => array(
                'site_url' => home_url('/'),
                'cct_slug' => self::CCT_SLUG,
                'cct_item_id' => (int) $item_id,
            ),
        );
    }

    private function validate_payload(array $payload)
    {
        foreach (array('customer_phone', 'fanikar_erp_id', 'service_erp_id', 'city_erp_id', 'date_time') as $field) {
            if (!isset($payload[$field]) || trim((string) $payload[$field]) === '') {
                return new WP_Error('fnk_order_sync_validation', 'Missing required CCT field: ' . $field);
            }
        }
        if (!preg_match('/^09[0-9]{9}$/', (string) $payload['customer_phone'])) {
            return new WP_Error('fnk_order_sync_validation', 'customer_phone must be a valid Iranian mobile number.');
        }
        foreach (array('fanikar_erp_id', 'service_erp_id', 'city_erp_id') as $field) {
            if (!preg_match('/^[1-9][0-9]{0,18}$/', (string) $payload[$field])) {
                return new WP_Error('fnk_order_sync_validation', $field . ' must be a positive numeric ID.');
            }
        }
        if (strlen((string) $payload['user_input']) > 10000) {
            return new WP_Error('fnk_order_sync_validation', 'user_input exceeds the maximum length.');
        }
        if ($payload['referer_url'] !== '' && (wp_http_validate_url($payload['referer_url']) === false || !preg_match('#^https?://#i', $payload['referer_url']))) {
            return new WP_Error('fnk_order_sync_validation', 'referer_url must be a valid HTTP(S) URL.');
        }
        if (strlen((string) $payload['referer_url']) > 2048) {
            return new WP_Error('fnk_order_sync_validation', 'referer_url exceeds the maximum length.');
        }
        foreach (array('fanikar_wp_id', 'service_wp_id', 'city_wp_id') as $field) {
            if ($payload[$field] !== '' && !preg_match('/^[0-9]{1,19}$/', (string) $payload[$field])) {
                return new WP_Error('fnk_order_sync_validation', $field . ' must be numeric when provided.');
            }
        }
        foreach (array('service_wp_name', 'city_wp_name', 'area_name', 'date', 'time', 'date_time') as $field) {
            if (strlen((string) $payload[$field]) > 255) {
                return new WP_Error('fnk_order_sync_validation', $field . ' exceeds the maximum length.');
            }
        }
        if ($payload['referer_url'] !== '') {
            $payload['referer_url'] = esc_url_raw($payload['referer_url']);
        }
        return $payload;
    }

    private function normalize_digits($value)
    {
        return strtr((string) $value, array('۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9'));
    }

    private function resolve_submission_timestamp(array $item, $submitted_at)
    {
        if (is_numeric($submitted_at) && (int) $submitted_at > 0) {
            return (int) $submitted_at;
        }

        $cct_created = trim((string) ($item['cct_created'] ?? ''));
        if ($cct_created !== '') {
            try {
                $date = new DateTimeImmutable($cct_created, wp_timezone());
                return $date->getTimestamp();
            } catch (Exception $exception) {
                // Fall through to the WordPress server time.
            }
        }

        return time();
    }

    private function get_target_config()
    {
        $settings = $this->get_settings();
        $active_target = $settings['active_target'] === 'mirror' ? 'mirror' : 'primary';
        $endpoint = trim((string) $settings[$active_target . '_endpoint']);
        $secret = trim((string) $settings[$active_target . '_secret']);
        $constant = $active_target === 'mirror' ? 'FNK_ORDER_SYNC_MIRROR_SECRET' : 'FNK_ORDER_SYNC_PRIMARY_SECRET';
        if (defined($constant) && constant($constant) !== '') {
            $secret = (string) constant($constant);
        }

        if ($endpoint === '' || $secret === '') {
            return new WP_Error('fnk_order_sync_config', 'Endpoint and shared secret for the active target must be configured.');
        }
        if (wp_http_validate_url($endpoint) === false || stripos($endpoint, 'https://') !== 0) {
            return new WP_Error('fnk_order_sync_endpoint', 'The active endpoint must be a valid HTTPS URL.');
        }

        return array(
            'label' => $active_target,
            'endpoint' => $endpoint,
            'secret' => $secret,
        );
    }

    private function get_cct_item($item_id)
    {
        global $wpdb;
        $table = $this->get_cct_table_name();
        if (!$this->table_exists($table)) {
            return null;
        }

        return $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$table}` WHERE `_ID` = %d LIMIT 1", $item_id), ARRAY_A);
    }

    private function mark_cct_synced($item_id)
    {
        global $wpdb;
        $table = $this->get_cct_table_name();
        if (!$this->table_exists($table)) {
            return new WP_Error('fnk_order_sync_cct_table', 'JetEngine CCT table was not found.');
        }

        $updated = $wpdb->update($table, array('status' => 's'), array('_ID' => $item_id), array('%s'), array('%d'));
        if ($updated === false) {
            return new WP_Error('fnk_order_sync_cct_update', 'Could not update CCT sync status.');
        }
        return true;
    }

    private function get_due_queue_items($limit)
    {
        global $wpdb;
        $table = $this->get_queue_table_name();
        $now = current_time('mysql', true);
        $limit = max(1, min(100, (int) $limit));
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE status IN ('queued', 'retry') AND (next_attempt_at IS NULL OR next_attempt_at <= %s) ORDER BY id ASC LIMIT %d",
            $now,
            $limit
        ));
    }

    private function upsert_queue($item_id, array $payload, $status, $last_error, $attempts, $next_attempt_at)
    {
        global $wpdb;
        $table = $this->get_queue_table_name();
        $now = current_time('mysql', true);
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM `{$table}` WHERE cct_item_id = %d", $item_id));
        $data = array(
            'payload' => wp_json_encode($payload),
            'status' => $status,
            'attempts' => (int) $attempts,
            'last_error' => $last_error ?: null,
            'next_attempt_at' => $next_attempt_at,
            'updated_at' => $now,
        );
        if ($existing) {
            $wpdb->update($table, $data, array('id' => (int) $existing));
            return;
        }

        $data['cct_item_id'] = $item_id;
        $data['created_at'] = $now;
        $wpdb->insert($table, $data);
    }

    private function update_queue($item_id, array $fields)
    {
        global $wpdb;
        $fields['updated_at'] = current_time('mysql', true);
        $wpdb->update($this->get_queue_table_name(), $fields, array('cct_item_id' => $item_id));
    }

    private static function create_queue_table()
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::get_queue_table_name();
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            cct_item_id bigint(20) unsigned NOT NULL,
            payload longtext NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'queued',
            target varchar(20) NULL,
            attempts smallint(5) unsigned NOT NULL DEFAULT 0,
            erp_order_id bigint(20) unsigned NULL,
            last_error text NULL,
            next_attempt_at datetime NULL,
            synced_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY cct_item_id (cct_item_id),
            KEY status_next_attempt (status, next_attempt_at)
        ) {$charset_collate};";
        dbDelta($sql);
    }

    private static function get_queue_table_name()
    {
        global $wpdb;
        return $wpdb->prefix . self::QUEUE_TABLE_SUFFIX;
    }

    private function get_cct_table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'jet_cct_' . self::CCT_SLUG;
    }

    private function table_exists($table)
    {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    public function register_settings_page()
    {
        add_options_page('Fanikara Order Sync', 'Fanikara Order Sync', 'manage_options', 'fnk-order-sync', array($this, 'render_settings_page'));
    }

    public function register_dashboard_widget()
    {
        wp_add_dashboard_widget(
            'fnk_order_sync_health',
            'سلامت سینک سفارش‌های فنی‌کارا',
            array($this, 'render_dashboard_widget')
        );
    }

    public function render_dashboard_widget()
    {
        $stats = $this->get_queue_stats();
        $health = $stats['failed'] > 5 || $stats['waiting'] > 50
            ? 'critical'
            : (($stats['failed'] > 0 || $stats['waiting'] > 10) ? 'warning' : 'healthy');
        $labels = array('healthy' => 'سالم', 'warning' => 'نیازمند بررسی', 'critical' => 'بحرانی');
        $colors = array('healthy' => '#16803c', 'warning' => '#b45309', 'critical' => '#b91c1c');
        echo '<div dir="rtl" style="border-right:5px solid ' . esc_attr($colors[$health]) . ';padding:8px 12px;background:#f8fafc;">';
        echo '<p style="margin:0 0 12px;font-weight:700;color:' . esc_attr($colors[$health]) . ';">وضعیت: ' . esc_html($labels[$health]) . '</p>';
        echo '<div style="display:flex;gap:8px;flex-wrap:wrap;">';
        foreach (array('synced' => 'موفق', 'waiting' => 'در انتظار', 'failed' => 'خطادار') as $key => $label) {
            echo '<div style="flex:1;min-width:90px;padding:10px;background:#fff;border:1px solid #e2e8f0;border-radius:6px;text-align:center;"><small>' . esc_html($label) . '</small><strong style="display:block;font-size:22px;margin-top:4px;">' . number_format((int) $stats[$key]) . '</strong></div>';
        }
        echo '</div>';
        echo '<p style="margin:12px 0 0;"><a href="' . esc_url(admin_url('options-general.php?page=fnk-order-sync')) . '">تنظیمات و جزئیات صف</a></p></div>';
    }

    public function register_settings()
    {
        register_setting('fnk_order_sync', self::OPTION_KEY, array($this, 'sanitize_settings'));
    }

    public function sanitize_settings($input)
    {
        $input = is_array($input) ? $input : array();
        return array(
            'active_target' => isset($input['active_target']) && $input['active_target'] === 'mirror' ? 'mirror' : 'primary',
            'primary_endpoint' => $this->sanitize_endpoint($input['primary_endpoint'] ?? ''),
            'primary_secret' => $this->sanitize_secret($input['primary_secret'] ?? ''),
            'mirror_endpoint' => $this->sanitize_endpoint($input['mirror_endpoint'] ?? ''),
            'mirror_secret' => $this->sanitize_secret($input['mirror_secret'] ?? ''),
            'sync_interval_value' => max(1, min(1440, absint($input['sync_interval_value'] ?? 5))),
            'sync_interval_unit' => isset($input['sync_interval_unit']) && $input['sync_interval_unit'] === 'hours' ? 'hours' : 'minutes',
        );
    }

    private function get_settings()
    {
        return wp_parse_args((array) get_option(self::OPTION_KEY, array()), array(
            'active_target' => 'primary',
            'primary_endpoint' => '',
            'primary_secret' => '',
            'mirror_endpoint' => '',
            'mirror_secret' => '',
            'sync_interval_value' => 5,
            'sync_interval_unit' => 'minutes',
        ));
    }

    private function sanitize_endpoint($value)
    {
        $endpoint = esc_url_raw(trim(is_scalar($value) ? (string) $value : ''));
        return ($endpoint !== '' && wp_http_validate_url($endpoint) !== false && stripos($endpoint, 'https://') === 0)
            ? $endpoint
            : '';
    }

    private function sanitize_secret($value)
    {
        $secret = trim(is_scalar($value) ? (string) $value : '');
        return strlen($secret) <= 255 ? $secret : substr($secret, 0, 255);
    }

    private function get_interval_seconds(array $settings)
    {
        $value = max(1, min(1440, absint($settings['sync_interval_value'] ?? 5)));
        $unit = ($settings['sync_interval_unit'] ?? 'minutes') === 'hours' ? 'hours' : 'minutes';
        return $unit === 'hours' ? $value * HOUR_IN_SECONDS : $value * MINUTE_IN_SECONDS;
    }

    public function render_settings_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $settings = $this->get_settings();
        $stats = $this->get_queue_stats();
        ?>
        <div class="wrap">
            <h1>Fanikara Order Sync</h1>
            <p>آیتم‌های CCT با وضعیت <code>q</code> به مقصد فعال ارسال و تنها پس از تأیید ERP به <code>s</code> تبدیل می‌شوند.</p>
            <form method="post" action="options.php">
                <?php settings_fields('fnk_order_sync'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">مقصد فعال</th>
                        <td>
                            <label><input type="radio" name="<?php echo esc_attr(self::OPTION_KEY); ?>[active_target]" value="primary" <?php checked($settings['active_target'], 'primary'); ?>> ERP اصلی</label><br>
                            <label><input type="radio" name="<?php echo esc_attr(self::OPTION_KEY); ?>[active_target]" value="mirror" <?php checked($settings['active_target'], 'mirror'); ?>> میرور تست</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">فاصله ارسال خودکار</th>
                        <td>
                            <input class="small-text" type="number" min="1" max="1440" name="<?php echo esc_attr(self::OPTION_KEY); ?>[sync_interval_value]" value="<?php echo (int) $settings['sync_interval_value']; ?>">
                            <select name="<?php echo esc_attr(self::OPTION_KEY); ?>[sync_interval_unit]">
                                <option value="minutes" <?php selected($settings['sync_interval_unit'], 'minutes'); ?>>دقیقه</option>
                                <option value="hours" <?php selected($settings['sync_interval_unit'], 'hours'); ?>>ساعت</option>
                            </select>
                            <p class="description">ارسال خودکار با WP-Cron انجام می‌شود. برای اجرای دقیق در سایت کم‌بازدید، WP-Cron را با cron سرور اجرا کنید.</p>
                        </td>
                    </tr>
                    <tr><th scope="row">Endpoint ERP اصلی</th><td><input class="regular-text code" type="url" name="<?php echo esc_attr(self::OPTION_KEY); ?>[primary_endpoint]" value="<?php echo esc_attr($settings['primary_endpoint']); ?>" placeholder="https://erp.example.com/integrations/wordpress/orders/v1/orders"></td></tr>
                    <tr><th scope="row">Secret ERP اصلی</th><td><input class="regular-text code" type="password" autocomplete="new-password" name="<?php echo esc_attr(self::OPTION_KEY); ?>[primary_secret]" value="<?php echo esc_attr($settings['primary_secret']); ?>"><p class="description">برای امنیت بیشتر می‌توانید ثابت <code>FNK_ORDER_SYNC_PRIMARY_SECRET</code> را در wp-config.php تعریف کنید.</p></td></tr>
                    <tr><th scope="row">Endpoint میرور تست</th><td><input class="regular-text code" type="url" name="<?php echo esc_attr(self::OPTION_KEY); ?>[mirror_endpoint]" value="<?php echo esc_attr($settings['mirror_endpoint']); ?>" placeholder="https://mirror.example.com/integrations/wordpress/orders/v1/orders"></td></tr>
                    <tr><th scope="row">Secret میرور تست</th><td><input class="regular-text code" type="password" autocomplete="new-password" name="<?php echo esc_attr(self::OPTION_KEY); ?>[mirror_secret]" value="<?php echo esc_attr($settings['mirror_secret']); ?>"><p class="description">یا ثابت <code>FNK_ORDER_SYNC_MIRROR_SECRET</code> را در wp-config.php تعریف کنید.</p></td></tr>
                </table>
                <?php submit_button('ذخیره تنظیمات'); ?>
            </form>

            <h2>صف ارسال</h2>
            <p>در انتظار: <?php echo (int) $stats['waiting']; ?> | موفق: <?php echo (int) $stats['synced']; ?> | خطادار: <?php echo (int) $stats['failed']; ?></p>
            <p>
                <a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=fnk_order_sync_now'), 'fnk_order_sync_now')); ?>">ارسال صف اکنون</a>
                <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=fnk_order_sync_retry_failed'), 'fnk_order_sync_retry_failed')); ?>">ارسال مجدد خطاها</a>
            </p>
            <?php $this->render_recent_failures(); ?>
        </div>
        <?php
    }

    public function handle_sync_now()
    {
        $this->require_admin_nonce('fnk_order_sync_now');
        $result = $this->process_queue(50);
        $this->redirect_with_notice('Sync finished. Processed: ' . $result['processed'] . ', synced: ' . $result['synced'] . ', failed: ' . $result['failed'] . '.');
    }

    public function handle_retry_failed()
    {
        $this->require_admin_nonce('fnk_order_sync_retry_failed');
        global $wpdb;
        $table = $this->get_queue_table_name();
        $wpdb->query("UPDATE `{$table}` SET status = 'queued', attempts = 0, last_error = NULL, next_attempt_at = '" . esc_sql(current_time('mysql', true)) . "', updated_at = '" . esc_sql(current_time('mysql', true)) . "' WHERE status = 'failed'");
        wp_schedule_single_event(time() + 5, self::CRON_HOOK);
        $this->redirect_with_notice('Failed queue items were requeued.');
    }

    private function require_admin_nonce($action)
    {
        if (!current_user_can('manage_options')) {
            wp_die('Access denied.');
        }
        check_admin_referer($action);
    }

    private function redirect_with_notice($message)
    {
        wp_safe_redirect(add_query_arg(array('page' => 'fnk-order-sync', 'fnk_order_sync_notice' => rawurlencode($message)), admin_url('options-general.php')));
        exit;
    }

    public function render_admin_notice()
    {
        if (!isset($_GET['fnk_order_sync_notice']) || !current_user_can('manage_options')) {
            return;
        }
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(wp_unslash($_GET['fnk_order_sync_notice'])) . '</p></div>';
    }

    private function get_queue_stats()
    {
        global $wpdb;
        $table = $this->get_queue_table_name();
        $rows = $wpdb->get_results("SELECT status, COUNT(*) AS count FROM `{$table}` GROUP BY status", ARRAY_A);
        $stats = array('waiting' => 0, 'synced' => 0, 'failed' => 0);
        foreach ($rows as $row) {
            if (in_array($row['status'], array('queued', 'retry'), true)) {
                $stats['waiting'] += (int) $row['count'];
            } elseif (isset($stats[$row['status']])) {
                $stats[$row['status']] += (int) $row['count'];
            }
        }
        return $stats;
    }

    private function render_recent_failures()
    {
        global $wpdb;
        $table = $this->get_queue_table_name();
        $items = $wpdb->get_results("SELECT cct_item_id, attempts, last_error, updated_at FROM `{$table}` WHERE status = 'failed' ORDER BY updated_at DESC LIMIT 10");
        if (!$items) {
            return;
        }
        echo '<h3>آخرین خطاها</h3><table class="widefat striped"><thead><tr><th>CCT ID</th><th>تلاش</th><th>خطا</th><th>زمان</th></tr></thead><tbody>';
        foreach ($items as $item) {
            echo '<tr><td>' . (int) $item->cct_item_id . '</td><td>' . (int) $item->attempts . '</td><td>' . esc_html($item->last_error) . '</td><td>' . esc_html($item->updated_at) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function ensure_site_uuid()
    {
        $uuid = get_option(self::SITE_UUID_OPTION, '');
        if ($uuid === '') {
            $uuid = wp_generate_uuid4();
            update_option(self::SITE_UUID_OPTION, $uuid, false);
        }
        return $uuid;
    }
}
