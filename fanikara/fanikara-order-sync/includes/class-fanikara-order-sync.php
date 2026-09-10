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
    const FAILURE_RETENTION_DAYS = 4;

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
        add_action('admin_post_fnk_order_sync_retry_error', array($this, 'handle_retry_error'));
        add_action('admin_post_fnk_order_sync_delete_error', array($this, 'handle_delete_error'));
        add_action('admin_post_fnk_order_sync_delete_errors', array($this, 'handle_delete_errors'));
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
            $this->delete_expired_failures();
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

    /**
     * Keep visible failed history short. A queued CCT receives a dismissed
     * tombstone so discovery cannot silently enqueue it again after cleanup.
     */
    private function delete_expired_failures()
    {
        global $wpdb;
        $queue_table = $this->get_queue_table_name();
        $cutoff = gmdate('Y-m-d H:i:s', time() - (self::FAILURE_RETENTION_DAYS * DAY_IN_SECONDS));
        if (!$this->table_exists($queue_table)) {
            return;
        }
        $cct_table = $this->get_cct_table_name();
        if ($this->table_exists($cct_table)) {
            $wpdb->query($wpdb->prepare(
                "UPDATE `{$queue_table}` queue INNER JOIN `{$cct_table}` cct ON cct._ID = queue.cct_item_id SET queue.status = 'dismissed', queue.last_error = NULL, queue.next_attempt_at = NULL, queue.updated_at = %s WHERE queue.status = 'failed' AND queue.updated_at < %s AND cct.status = 'q'",
                current_time('mysql', true),
                $cutoff
            ));
            $wpdb->query($wpdb->prepare(
                "DELETE queue FROM `{$queue_table}` queue LEFT JOIN `{$cct_table}` cct ON cct._ID = queue.cct_item_id WHERE queue.status = 'failed' AND queue.updated_at < %s AND (cct._ID IS NULL OR cct.status <> 'q')",
                $cutoff
            ));
            return;
        }
        $wpdb->query($wpdb->prepare(
            "DELETE FROM `{$queue_table}` WHERE status = 'failed' AND updated_at < %s",
            $cutoff
        ));
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
        $period = $this->normalize_period(isset($_GET['fnk_health_period']) ? wp_unslash($_GET['fnk_health_period']) : 'week');
        $stats = $this->get_period_metrics($period);
        $health = $stats['failed'] > 5 || $stats['waiting'] > 50
            ? 'critical'
            : (($stats['failed'] > 0 || $stats['waiting'] > 10) ? 'warning' : 'healthy');
        $labels = array('healthy' => 'سالم', 'warning' => 'نیازمند بررسی', 'critical' => 'بحرانی');
        $colors = array('healthy' => '#16803c', 'warning' => '#b45309', 'critical' => '#b91c1c');
        echo '<div dir="rtl" style="border-right:5px solid ' . esc_attr($colors[$health]) . ';padding:8px 12px;background:#f8fafc;">';
        echo '<p style="margin:0 0 8px;font-weight:700;color:' . esc_attr($colors[$health]) . ';">وضعیت: ' . esc_html($labels[$health]) . ' <small style="font-weight:400;color:#475569;">(' . esc_html($this->get_period_label($period)) . ')</small></p>';
        echo '<p style="margin:0 0 10px;display:flex;gap:6px;flex-wrap:wrap;">';
        foreach (array('day' => 'روز', 'week' => 'هفته', 'month' => 'ماه') as $key => $label) {
            $url = add_query_arg('fnk_health_period', $key, admin_url('index.php'));
            $style = $period === $key ? 'background:#2271b1;color:#fff;border-color:#2271b1;' : 'background:#fff;color:#2271b1;';
            echo '<a href="' . esc_url($url) . '" style="padding:3px 8px;border:1px solid #cbd5e1;border-radius:4px;text-decoration:none;' . esc_attr($style) . '">' . esc_html($label) . '</a>';
        }
        echo '</p>';
        echo '<div style="display:flex;gap:8px;flex-wrap:wrap;">';
        foreach (array('synced' => 'موفق', 'waiting' => 'در انتظار فعلی', 'failed' => 'خطادار') as $key => $label) {
            echo '<div style="flex:1;min-width:90px;padding:10px;background:#fff;border:1px solid #e2e8f0;border-radius:6px;text-align:center;"><small>' . esc_html($label) . '</small><strong style="display:block;font-size:22px;margin-top:4px;">' . number_format((int) $stats[$key]) . '</strong></div>';
        }
        echo '</div>';
        echo '<p style="margin:12px 0 0;"><a href="' . esc_url(add_query_arg(array('page' => 'fnk-order-sync', 'fnk_period' => $period), admin_url('options-general.php'))) . '">تنظیمات و جزئیات صف</a></p></div>';
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
        $period = $this->normalize_period(isset($_GET['fnk_period']) ? wp_unslash($_GET['fnk_period']) : 'week');
        $settings = $this->get_settings();
        $this->delete_expired_failures();
        $metrics = $this->get_period_metrics($period);
        $growth = $this->get_growth_data($period);
        ?>
        <div class="wrap">
            <style>
                .fnk-sync-metrics{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin:18px 0}
                .fnk-sync-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
                .fnk-sync-card small{display:block;color:#646970;font-size:12px}.fnk-sync-card strong{display:block;margin-top:8px;color:#1d2327;font-size:26px;line-height:1}.fnk-sync-card em{display:block;margin-top:8px;color:#646970;font-style:normal;font-size:12px}
                .fnk-sync-toolbar{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:14px 0}.fnk-sync-toolbar a{border:1px solid #c3c4c7;border-radius:4px;padding:5px 12px;text-decoration:none;background:#fff}.fnk-sync-toolbar a.is-active{background:#2271b1;border-color:#2271b1;color:#fff}
                .fnk-sync-chart{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px;margin:18px 0;overflow:hidden}.fnk-sync-chart svg{display:block;width:100%;height:auto}.fnk-sync-chart-legend{display:flex;gap:16px;justify-content:center;color:#50575e;font-size:12px}.fnk-sync-dot{display:inline-block;width:9px;height:9px;border-radius:50%;margin-left:4px}.fnk-sync-dot.success{background:#16803c}.fnk-sync-dot.failure{background:#b91c1c}
                .fnk-sync-actions{display:flex;gap:6px;flex-wrap:wrap}.fnk-sync-actions form{display:inline}.fnk-sync-table td,.fnk-sync-table th{vertical-align:middle}.fnk-sync-error{max-width:320px;white-space:normal;word-break:break-word}
            </style>
            <h1>Fanikara Order Sync</h1>
            <p>آیتم‌های CCT با وضعیت <code>q</code> به مقصد فعال ارسال و تنها پس از تأیید ERP به <code>s</code> تبدیل می‌شوند.</p>
            <?php $this->render_period_filter($period); ?>
            <?php $this->render_metric_cards($metrics); ?>
            <?php $this->render_growth_chart($growth); ?>
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
            <p>خطاهای نهایی پس از <?php echo esc_html($this->to_persian_digits((string) self::FAILURE_RETENTION_DAYS)); ?> روز به‌صورت خودکار حذف می‌شوند. در صورت نیاز، حذف دستی نیز از جدول آخرین خطاها در دسترس است.</p>
            <p>
                <a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=fnk_order_sync_now'), 'fnk_order_sync_now')); ?>">ارسال صف اکنون</a>
                <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=fnk_order_sync_retry_failed'), 'fnk_order_sync_retry_failed')); ?>">ارسال مجدد خطاها</a>
                <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=fnk_order_sync_delete_errors'), 'fnk_order_sync_delete_errors')); ?>" onclick="return confirm('همه خطاهای نهایی حذف شوند؟');">حذف همه خطاها</a>
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
        $now = current_time('mysql', true);
        $wpdb->query($wpdb->prepare(
            "UPDATE `{$table}` SET status = 'queued', attempts = 0, last_error = NULL, next_attempt_at = %s, updated_at = %s WHERE status = 'failed'",
            $now,
            $now
        ));
        wp_schedule_single_event(time() + 5, self::CRON_HOOK);
        $this->redirect_with_notice('Failed queue items were requeued.');
    }

    public function handle_retry_error()
    {
        $queue_id = isset($_POST['queue_id']) ? absint($_POST['queue_id']) : 0;
        $this->require_admin_nonce('fnk_order_sync_retry_error_' . $queue_id);
        if ($queue_id < 1) {
            $this->redirect_with_notice('Invalid queue item.');
        }

        global $wpdb;
        $now = current_time('mysql', true);
        $table = $this->get_queue_table_name();
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE `{$table}` SET status = 'queued', attempts = 0, last_error = NULL, next_attempt_at = %s, updated_at = %s WHERE id = %d AND status = 'failed'",
            $now,
            $now,
            $queue_id
        ));
        if ($updated) {
            wp_schedule_single_event(time() + 5, self::CRON_HOOK);
        }
        $this->redirect_with_notice($updated ? 'خطا برای ارسال مجدد در صف قرار گرفت.' : 'خطای انتخاب‌شده پیدا نشد.');
    }

    public function handle_delete_error()
    {
        $queue_id = isset($_POST['queue_id']) ? absint($_POST['queue_id']) : 0;
        $this->require_admin_nonce('fnk_order_sync_delete_error_' . $queue_id);
        if ($queue_id < 1) {
            $this->redirect_with_notice('Invalid queue item.');
        }

        global $wpdb;
        $table = $this->get_queue_table_name();
        $item = $wpdb->get_row($wpdb->prepare("SELECT cct_item_id FROM `{$table}` WHERE id = %d AND status = 'failed'", $queue_id));
        if (!$item) {
            $this->redirect_with_notice('خطای انتخاب‌شده پیدا نشد.');
        }
        $cct_item = $this->get_cct_item((int) $item->cct_item_id);
        if (is_array($cct_item) && $this->is_queued_status($cct_item)) {
            $deleted = $wpdb->query($wpdb->prepare(
                "UPDATE `{$table}` SET status = 'dismissed', last_error = NULL, next_attempt_at = NULL, updated_at = %s WHERE id = %d AND status = 'failed'",
                current_time('mysql', true),
                $queue_id
            ));
        } else {
            $deleted = $wpdb->query($wpdb->prepare(
                "DELETE FROM `{$table}` WHERE id = %d AND status = 'failed'",
                $queue_id
            ));
        }
        $this->redirect_with_notice($deleted ? 'خطا حذف شد.' : 'خطای انتخاب‌شده پیدا نشد.');
    }

    public function handle_delete_errors()
    {
        $this->require_admin_nonce('fnk_order_sync_delete_errors');
        global $wpdb;
        $table = $this->get_queue_table_name();
        $deleted = 0;
        $dismissed = 0;
        $cct_table = $this->get_cct_table_name();
        if ($this->table_exists($cct_table)) {
            $now = current_time('mysql', true);
            $dismissed = $wpdb->query($wpdb->prepare(
                "UPDATE `{$table}` queue INNER JOIN `{$cct_table}` cct ON cct._ID = queue.cct_item_id SET queue.status = 'dismissed', queue.last_error = NULL, queue.next_attempt_at = NULL, queue.updated_at = %s WHERE queue.status = 'failed' AND cct.status = 'q'",
                $now
            ));
            $deleted = $wpdb->query("DELETE queue FROM `{$table}` queue LEFT JOIN `{$cct_table}` cct ON cct._ID = queue.cct_item_id WHERE queue.status = 'failed' AND (cct._ID IS NULL OR cct.status <> 'q')");
        } else {
            $deleted = $wpdb->query("DELETE FROM `{$table}` WHERE status = 'failed'");
        }
        $this->redirect_with_notice('تعداد ' . number_format((int) $deleted + (int) $dismissed) . ' خطا از فهرست حذف شد.');
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
        wp_safe_redirect(add_query_arg(array('page' => 'fnk-order-sync', 'fnk_order_sync_notice' => $message), admin_url('options-general.php')));
        exit;
    }

    public function render_admin_notice()
    {
        if (!isset($_GET['fnk_order_sync_notice']) || !current_user_can('manage_options')) {
            return;
        }
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(wp_unslash($_GET['fnk_order_sync_notice'])) . '</p></div>';
    }

    private function normalize_period($period)
    {
        $period = sanitize_key((string) $period);
        return in_array($period, array('day', 'week', 'month'), true) ? $period : 'week';
    }

    private function get_period_label($period)
    {
        $labels = array('day' => 'امروز', 'week' => '۷ روز اخیر', 'month' => '۳۰ روز اخیر');
        return $labels[$this->normalize_period($period)];
    }

    private function get_period_bounds($period)
    {
        $period = $this->normalize_period($period);
        $days = $period === 'day' ? 1 : ($period === 'month' ? 30 : 7);
        $timezone = wp_timezone();
        $today = new DateTimeImmutable('now', $timezone);
        $start = $today->setTime(0, 0, 0)->modify('-' . ($days - 1) . ' days');
        $end = $today->setTime(0, 0, 0)->modify('+1 day');
        $utc = new DateTimeZone('UTC');
        return array(
            'from' => $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            'to' => $end->setTimezone($utc)->format('Y-m-d H:i:s'),
        );
    }

    private function get_period_metrics($period)
    {
        global $wpdb;
        $table = $this->get_queue_table_name();
        $metrics = array(
            'synced' => 0,
            'failed' => 0,
            'waiting' => 0,
            'processed' => 0,
            'success_rate' => 0,
        );
        if (!$this->table_exists($table)) {
            return $metrics;
        }

        $bounds = $this->get_period_bounds($period);
        $metrics['synced'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE status = 'synced' AND synced_at >= %s AND synced_at < %s",
            $bounds['from'],
            $bounds['to']
        ));
        $metrics['failed'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE status = 'failed' AND updated_at >= %s AND updated_at < %s",
            $bounds['from'],
            $bounds['to']
        ));
        $metrics['waiting'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE status IN ('queued', 'retry')");
        $metrics['processed'] = $metrics['synced'] + $metrics['failed'];
        if ($metrics['processed'] > 0) {
            $metrics['success_rate'] = round(($metrics['synced'] / $metrics['processed']) * 100, 1);
        }
        return $metrics;
    }

    private function render_period_filter($period)
    {
        echo '<div class="fnk-sync-toolbar" dir="rtl"><strong>بازه گزارش:</strong>';
        foreach (array('day' => 'روز', 'week' => 'هفته', 'month' => 'ماه') as $key => $label) {
            $url = add_query_arg(array('page' => 'fnk-order-sync', 'fnk_period' => $key), admin_url('options-general.php'));
            echo '<a class="' . ($period === $key ? 'is-active' : '') . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        }
        echo '<span style="color:#646970;font-size:12px;">' . esc_html($this->get_period_label($period)) . '</span></div>';
    }

    private function render_metric_cards(array $metrics)
    {
        $cards = array(
            array('موفق', $metrics['synced'], 'سینک موفق در بازه انتخاب‌شده'),
            array('خطا', $metrics['failed'], 'خطای نهایی در بازه انتخاب‌شده'),
            array('در انتظار', $metrics['waiting'], 'صف فعلی برای پردازش'),
            array('نرخ موفقیت', number_format((float) $metrics['success_rate'], 1) . '%', 'از درخواست‌های پردازش‌شده'),
            array('پردازش‌شده', $metrics['processed'], 'موفق + خطا در بازه'),
        );
        echo '<div class="fnk-sync-metrics" dir="rtl">';
        foreach ($cards as $card) {
            $value = is_numeric($card[1]) ? $this->to_persian_digits(number_format((int) $card[1])) : $this->to_persian_digits((string) $card[1]);
            echo '<div class="fnk-sync-card"><small>' . esc_html($card[0]) . '</small><strong>' . esc_html($value) . '</strong><em>' . esc_html($card[2]) . '</em></div>';
        }
        echo '</div>';
    }

    private function get_growth_data($period)
    {
        global $wpdb;
        $table = $this->get_queue_table_name();
        $days = $this->normalize_period($period) === 'month' ? 30 : 7;
        $timezone = wp_timezone();
        $today = new DateTimeImmutable('now', $timezone);
        $start = $today->setTime(0, 0, 0)->modify('-' . ($days - 1) . ' days');
        $end = $today->setTime(0, 0, 0)->modify('+1 day');
        $utc = new DateTimeZone('UTC');
        $from = $start->setTimezone($utc)->format('Y-m-d H:i:s');
        $to = $end->setTimezone($utc)->format('Y-m-d H:i:s');
        $buckets = array();
        for ($index = 0; $index < $days; $index++) {
            $date = $start->modify('+' . $index . ' days');
            $key = $date->format('Y-m-d');
            $buckets[$key] = array('synced' => 0, 'failed' => 0, 'label' => $this->format_jalali_datetime($date, false));
        }
        if ($this->table_exists($table)) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT status, synced_at, updated_at FROM `{$table}` WHERE ((status = 'synced' AND synced_at >= %s AND synced_at < %s) OR (status = 'failed' AND updated_at >= %s AND updated_at < %s))",
                $from,
                $to,
                $from,
                $to
            ));
            foreach ($rows as $row) {
                $value = $row->status === 'synced' ? $row->synced_at : $row->updated_at;
                $date = $this->parse_datetime($value);
                if (!$date) {
                    continue;
                }
                $key = $date->format('Y-m-d');
                if (isset($buckets[$key])) {
                    $buckets[$key][$row->status === 'synced' ? 'synced' : 'failed']++;
                }
            }
        }
        return array_values($buckets);
    }

    private function render_growth_chart(array $growth)
    {
        $width = 820;
        $height = 280;
        $left = 42;
        $right = 18;
        $top = 18;
        $bottom = 42;
        $plot_width = $width - $left - $right;
        $plot_height = $height - $top - $bottom;
        $max = 1;
        foreach ($growth as $point) {
            $max = max($max, (int) $point['synced'], (int) $point['failed']);
        }
        $count = max(1, count($growth));
        $point = function ($index, $value) use ($count, $max, $left, $top, $plot_width, $plot_height) {
            $x = $count === 1 ? $left : $left + (($plot_width * $index) / ($count - 1));
            $y = $top + $plot_height - (($plot_height * (int) $value) / $max);
            return number_format($x, 2, '.', '') . ',' . number_format($y, 2, '.', '');
        };
        $synced_points = array();
        $failed_points = array();
        foreach ($growth as $index => $item) {
            $synced_points[] = $point($index, $item['synced']);
            $failed_points[] = $point($index, $item['failed']);
        }

        echo '<div class="fnk-sync-chart" dir="rtl"><h2 style="margin:0 0 10px;font-size:16px;">نمودار رشد و خطا</h2>';
        echo '<svg viewBox="0 0 ' . (int) $width . ' ' . (int) $height . '" role="img" aria-label="نمودار تعداد سینک موفق و خطا در روزهای اخیر">';
        for ($grid = 0; $grid <= 4; $grid++) {
            $y = $top + (($plot_height * $grid) / 4);
            $value = $max - (($max * $grid) / 4);
            echo '<line x1="' . (int) $left . '" y1="' . esc_attr(number_format($y, 2, '.', '')) . '" x2="' . (int) ($width - $right) . '" y2="' . esc_attr(number_format($y, 2, '.', '')) . '" stroke="#e2e8f0" />';
            echo '<text x="' . (int) ($left - 8) . '" y="' . esc_attr(number_format($y + 4, 2, '.', '')) . '" text-anchor="end" font-size="11" fill="#64748b">' . esc_html($this->to_persian_digits((string) round($value))) . '</text>';
        }
        echo '<polyline fill="none" stroke="#16803c" stroke-width="3" points="' . esc_attr(implode(' ', $synced_points)) . '" />';
        echo '<polyline fill="none" stroke="#b91c1c" stroke-width="3" points="' . esc_attr(implode(' ', $failed_points)) . '" />';
        foreach ($growth as $index => $item) {
            $synced = explode(',', $point($index, $item['synced']));
            $failed = explode(',', $point($index, $item['failed']));
            echo '<circle cx="' . esc_attr($synced[0]) . '" cy="' . esc_attr($synced[1]) . '" r="3" fill="#16803c" />';
            echo '<circle cx="' . esc_attr($failed[0]) . '" cy="' . esc_attr($failed[1]) . '" r="3" fill="#b91c1c" />';
            if ($index % max(1, (int) ceil(count($growth) / 7)) === 0 || $index === count($growth) - 1) {
                echo '<text x="' . esc_attr($synced[0]) . '" y="' . (int) ($height - 14) . '" text-anchor="middle" font-size="10" fill="#64748b">' . esc_html($item['label']) . '</text>';
            }
        }
        echo '</svg><div class="fnk-sync-chart-legend"><span><i class="fnk-sync-dot success"></i>سینک موفق</span><span><i class="fnk-sync-dot failure"></i>خطای نهایی</span></div></div>';
    }

    private function render_recent_failures()
    {
        global $wpdb;
        $table = $this->get_queue_table_name();
        $items = $this->table_exists($table) ? $wpdb->get_results("SELECT id, cct_item_id, payload, attempts, last_error, updated_at FROM `{$table}` WHERE status = 'failed' ORDER BY updated_at DESC LIMIT 50") : array();
        echo '<h2>آخرین خطاها</h2>';
        if (!$items) {
            echo '<div class="notice notice-success inline"><p>در حال حاضر خطای نهایی ثبت‌شده‌ای وجود ندارد.</p></div>';
            return;
        }
        echo '<p>تا ۵۰ خطای اخیر نمایش داده می‌شود. زمان‌ها به تقویم شمسی و منطقه زمانی وردپرس نمایش داده می‌شوند.</p>';
        echo '<table class="widefat striped fnk-sync-table" dir="rtl"><thead><tr><th>CCT ID (item_id)</th><th>خدمت ERP</th><th>شهر ERP</th><th>فنی‌کار ERP</th><th>تلاش</th><th>خطا</th><th>آخرین بروزرسانی</th><th>مدیریت</th></tr></thead><tbody>';
        foreach ($items as $item) {
            $payload = json_decode((string) $item->payload, true);
            $payload = is_array($payload) ? $payload : array();
            $service = $this->get_payload_value($payload, 'service_erp_id');
            $city = $this->get_payload_value($payload, 'city_erp_id');
            $technician = $this->get_payload_value($payload, 'fanikar_erp_id');
            $cct_url = add_query_arg(array('page' => 'jet-cct-' . self::CCT_SLUG, 'cct_action' => 'edit', 'item_id' => (int) $item->cct_item_id), admin_url('admin.php'));
            echo '<tr>';
            echo '<td><a href="' . esc_url($cct_url) . '" target="_blank" rel="noopener">' . esc_html($this->to_persian_digits((string) $item->cct_item_id)) . '</a></td>';
            echo '<td>' . esc_html($service !== '' ? $service : '—') . '</td><td>' . esc_html($city !== '' ? $city : '—') . '</td><td>' . esc_html($technician !== '' ? $technician : '—') . '</td>';
            echo '<td>' . esc_html($this->to_persian_digits((string) $item->attempts)) . '</td><td class="fnk-sync-error">' . esc_html((string) $item->last_error) . '</td><td>' . esc_html($this->format_jalali_datetime($item->updated_at)) . '</td><td><div class="fnk-sync-actions">';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="fnk_order_sync_retry_error"><input type="hidden" name="queue_id" value="' . (int) $item->id . '">';
            wp_nonce_field('fnk_order_sync_retry_error_' . (int) $item->id);
            echo '<button type="submit" class="button button-small">ارسال مجدد</button></form>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'این خطا حذف شود؟\');"><input type="hidden" name="action" value="fnk_order_sync_delete_error"><input type="hidden" name="queue_id" value="' . (int) $item->id . '">';
            wp_nonce_field('fnk_order_sync_delete_error_' . (int) $item->id);
            echo '<button type="submit" class="button button-small">حذف</button></form>';
            echo '</div></td></tr>';
        }
        echo '</tbody></table>';
    }

    private function get_payload_value(array $payload, $key)
    {
        return isset($payload[$key]) && is_scalar($payload[$key]) ? (string) $payload[$key] : '';
    }

    private function parse_datetime($value)
    {
        if ($value instanceof DateTimeInterface) {
            try {
                return (new DateTimeImmutable($value->format('c')))->setTimezone(wp_timezone());
            } catch (Exception $exception) {
                return null;
            }
        }
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone(wp_timezone());
        } catch (Exception $exception) {
            return null;
        }
    }

    private function format_jalali_datetime($value, $with_time = true)
    {
        $date = $this->parse_datetime($value);
        if (!$date) {
            return '—';
        }
        $jalali = $this->gregorian_to_jalali((int) $date->format('Y'), (int) $date->format('n'), (int) $date->format('j'));
        $formatted = sprintf('%04d/%02d/%02d', $jalali[0], $jalali[1], $jalali[2]);
        if ($with_time) {
            $formatted .= ' ' . $date->format('H:i');
        }
        return $this->to_persian_digits($formatted);
    }

    private function gregorian_to_jalali($year, $month, $day)
    {
        $g_days_in_month = array(31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31);
        $jy = 0;
        $gy = $year - 1600;
        $gm = $month - 1;
        $gd = $day - 1;
        $g_day_no = 365 * $gy + intdiv($gy + 3, 4) - intdiv($gy + 99, 100) + intdiv($gy + 399, 400);
        for ($index = 0; $index < $gm; $index++) {
            $g_day_no += $g_days_in_month[$index];
        }
        if ($gm > 1 && (($gy % 4 === 0 && $gy % 100 !== 0) || $gy % 400 === 0)) {
            $g_day_no++;
        }
        $g_day_no += $gd;
        $j_day_no = $g_day_no - 79;
        $j_np = intdiv($j_day_no, 12053);
        $j_day_no %= 12053;
        $jy = 979 + 33 * $j_np + 4 * intdiv($j_day_no, 1461);
        $j_day_no %= 1461;
        if ($j_day_no >= 366) {
            $jy += intdiv($j_day_no - 1, 365);
            $j_day_no = ($j_day_no - 1) % 365;
        }
        if ($j_day_no < 186) {
            $jm = 1 + intdiv($j_day_no, 31);
            $jd = 1 + ($j_day_no % 31);
        } else {
            $jm = 7 + intdiv($j_day_no - 186, 30);
            $jd = 1 + (($j_day_no - 186) % 30);
        }
        return array($jy, $jm, $jd);
    }

    private function to_persian_digits($value)
    {
        return strtr((string) $value, array('0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹'));
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
