<?php

if (!defined('ABSPATH')) {
    exit;
}

final class Fanikara_ERP_Sync
{
    private const OPTION_KEY = 'fnk_erp_sync_settings';
    private const LAST_RUN_OPTION = 'fnk_erp_sync_last_run';
    private const LAST_RUNS_OPTION = 'fnk_erp_sync_last_runs';
    private const SCHEDULE_SIGNATURE_OPTION = 'fnk_erp_sync_schedule_signature';
    private const LOCK_KEY = 'fnk_erp_sync_lock';
    private const LEGACY_SYNC_HOOK = 'fnk_erp_sync_cron';
    private const STATUS_SYNC_HOOK = 'fnk_erp_status_sync_cron';
    private const FULL_SYNC_HOOK = 'fnk_erp_full_sync_cron';
    private const WPCODE_SYNC_HOOK = 'fnk_erp_wpcode_sync_cron';
    private const SCHEDULE_UNITS = ['minutes', 'hours', 'days'];
    private const LEGACY_PHONE_META = 'tel';
    private const EXCLUDED_SERVICE_ERP_IDS = [102, 59];
    private const TECHNICAL_POST_TYPE = 'fanikar';
    private const AREA_TAXONOMY = 'area';
    private const SERVICE_TAXONOMY = 'service';
    private const TECHNICAL_ID_META = 'fnk_user_erp_id';
    private const WORK_STATUS_META = 'fnk_usr_cpt_work_status';
    private const SMS_PHONE_META = 'fnk_usr_cpt_direct_call_phone_number_for_sms';
    private const DIRECT_PHONE_META = 'fnk_usr_cpt_direct_call_phone_number';
    private const REGISTRATION_DATE_META = 'fnk_usr_reg_date';
    private const WORK_HOURS_START_META = 'fnk_usr_work_hours_start';
    private const WORK_HOURS_END_META = 'fnk_usr_work_hours_start_end';
    private const DONE_SERVICES_META = 'fnk_usr_done_services';
    private const SERVICE_SCORES_META = 'fnk_dev_service_scores';
    private const AREA_ID_META = 'fnk_area_city_erp_id';
    private const SERVICE_ID_META = 'fnk_service_erp_id';
    private const ARCHIVED_REASON_META = '_fnk_erp_archived_reason';
    private const SYNCED_AT_META = '_fnk_erp_synced_at';

    public static function init()
    {
        $plugin = new self();
        $plugin->registerHooks();
    }

    public static function activate()
    {
        $plugin = new self();
        $plugin->rescheduleAll();
    }

    public static function deactivate()
    {
        wp_clear_scheduled_hook(self::LEGACY_SYNC_HOOK);
        wp_clear_scheduled_hook(self::STATUS_SYNC_HOOK);
        wp_clear_scheduled_hook(self::FULL_SYNC_HOOK);
        wp_clear_scheduled_hook(self::WPCODE_SYNC_HOOK);
        delete_option(self::SCHEDULE_SIGNATURE_OPTION);
        delete_transient(self::LOCK_KEY);
    }

    private function registerHooks()
    {
        add_action('admin_menu', [$this, 'registerAdminMenu']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_post_fnk_erp_sync', [$this, 'handleManualSync']);
        add_action('admin_post_fnk_erp_test_connection', [$this, 'handleConnectionTest']);
        add_action('init', [$this, 'ensureSchedules']);
        add_filter('cron_schedules', [$this, 'registerCronSchedules']);
        add_action(self::STATUS_SYNC_HOOK, [$this, 'runStatusSync']);
        add_action(self::FULL_SYNC_HOOK, [$this, 'runFullSync']);
        add_action(self::WPCODE_SYNC_HOOK, [$this, 'runWpCodeSync']);
    }

    public function registerAdminMenu()
    {
        add_options_page(
            'Fanikara ERP Sync',
            'Fanikara ERP Sync',
            'manage_options',
            'fnk-erp-sync',
            [$this, 'renderSettingsPage']
        );
    }

    public function registerSettings()
    {
        register_setting(
            'fnk_erp_sync',
            self::OPTION_KEY,
            [
                'type' => 'array',
                'sanitize_callback' => [$this, 'sanitizeSettings'],
                'default' => $this->defaultSettings(),
            ]
        );

        add_settings_section(
            'fnk_erp_sync_api',
            'اتصال به ERP',
            function () {
                echo '<p>endpoint پیش‌فرض سایت ERP تنظیم شده است؛ در صورت نیاز می‌توانید آن را تغییر دهید.</p>';
            },
            'fnk-erp-sync'
        );

        add_settings_field(
            'fnk_erp_endpoint',
            'آدرس API',
            [$this, 'renderTextField'],
            'fnk-erp-sync',
            'fnk_erp_sync_api',
            ['key' => 'endpoint', 'placeholder' => 'https://erp.example.com/site/api-wordpress-integration']
        );

        add_settings_field(
            'fnk_erp_token',
            'توکن API',
            [$this, 'renderTextField'],
            'fnk-erp-sync',
            'fnk_erp_sync_api',
            ['key' => 'token', 'type' => 'password']
        );

        add_settings_section(
            'fnk_erp_sync_schedule',
            'زمان‌بندی همگام‌سازی',
            function () {
                echo '<p>وضعیت فنی‌کارها به‌صورت جداگانه و اطلاعات کامل و wpCode منفی با زمان‌بندی مستقل اجرا می‌شوند.</p>';
            },
            'fnk-erp-sync'
        );

        add_settings_field(
            'fnk_erp_status_interval',
            'فاصله وضعیت فنی‌کار',
            [$this, 'renderScheduleIntervalField'],
            'fnk-erp-sync',
            'fnk_erp_sync_schedule',
            ['key' => 'status_interval']
        );

        add_settings_field(
            'fnk_erp_full_interval',
            'فاصله اطلاعات کامل',
            [$this, 'renderScheduleIntervalField'],
            'fnk-erp-sync',
            'fnk_erp_sync_schedule',
            ['key' => 'full_interval']
        );

        add_settings_field(
            'fnk_erp_wpcode_interval',
            'فاصله بررسی wpCode منفی',
            [$this, 'renderScheduleIntervalField'],
            'fnk-erp-sync',
            'fnk_erp_sync_schedule',
            ['key' => 'wpcode_interval']
        );
    }

    public function sanitizeSettings($input)
    {
        $input = is_array($input) ? $input : [];

        return [
            'endpoint' => isset($input['endpoint']) ? esc_url_raw(trim($input['endpoint'])) : '',
            'token' => isset($input['token']) ? sanitize_text_field($input['token']) : '',
            'status_interval_value' => $this->sanitizeInterval($input['status_interval_value'] ?? 1, 1, 525600),
            'status_interval_unit' => $this->sanitizeIntervalUnit($input['status_interval_unit'] ?? 'minutes'),
            'full_interval_value' => $this->sanitizeInterval($input['full_interval_value'] ?? 7, 1, 525600),
            'full_interval_unit' => $this->sanitizeIntervalUnit($input['full_interval_unit'] ?? 'days'),
            'wpcode_interval_value' => $this->sanitizeInterval($input['wpcode_interval_value'] ?? 7, 1, 525600),
            'wpcode_interval_unit' => $this->sanitizeIntervalUnit($input['wpcode_interval_unit'] ?? 'days'),
        ];
    }

    public function renderTextField($args)
    {
        $settings = $this->getSettings();
        $key = $args['key'];
        $type = $args['type'] ?? 'text';
        $placeholder = $args['placeholder'] ?? '';

        printf(
            '<input class="regular-text" type="%1$s" name="%2$s[%3$s]" value="%4$s" placeholder="%5$s">',
            esc_attr($type),
            esc_attr(self::OPTION_KEY),
            esc_attr($key),
            esc_attr($settings[$key] ?? ''),
            esc_attr($placeholder)
        );
    }

    public function renderNumberField($args)
    {
        $settings = $this->getSettings();
        $key = $args['key'];

        printf(
            '<input class="small-text" type="number" min="%1$d" max="%2$d" name="%3$s[%4$s]" value="%5$d">',
            (int)$args['min'],
            (int)$args['max'],
            esc_attr(self::OPTION_KEY),
            esc_attr($key),
            (int)($settings[$key] ?? 1)
        );
    }

    public function renderScheduleIntervalField($args)
    {
        $settings = $this->getSettings();
        $key = $args['key'];
        $valueKey = $key . '_value';
        $unitKey = $key . '_unit';
        $units = [
            'minutes' => 'دقیقه',
            'hours' => 'ساعت',
            'days' => 'روز',
        ];

        printf(
            '<input class="small-text" type="number" min="1" max="525600" name="%1$s[%2$s]" value="%3$d"> ',
            esc_attr(self::OPTION_KEY),
            esc_attr($valueKey),
            (int)($settings[$valueKey] ?? 1)
        );
        printf(
            '<select name="%1$s[%2$s]">',
            esc_attr(self::OPTION_KEY),
            esc_attr($unitKey)
        );
        foreach ($units as $unit => $label) {
            printf(
                '<option value="%1$s" %2$s>%3$s</option>',
                esc_attr($unit),
                selected($settings[$unitKey] ?? 'minutes', $unit, false),
                esc_html($label)
            );
        }
        echo '</select>';
    }

    public function renderSettingsPage()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $this->renderLastRunReport();
        ?>
        <div class="wrap">
            <h1>Fanikara ERP Sync</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('fnk_erp_sync');
                do_settings_sections('fnk-erp-sync');
                submit_button('ذخیره تنظیمات');
                ?>
            </form>
            <hr>
            <h2>همگام‌سازی دستی</h2>
            <p>فنی‌کارهای لیست سیاه import نمی‌شوند و پست‌های قبلی آن‌ها به حالت پیش‌نویس منتقل می‌شوند.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="fnk_erp_sync">
                <input type="hidden" name="sync_type" value="full">
                <?php wp_nonce_field('fnk_erp_sync_now'); ?>
                <?php submit_button('اجرای اطلاعات کامل', 'secondary', 'submit', false); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-left:8px;">
                <input type="hidden" name="action" value="fnk_erp_sync">
                <input type="hidden" name="sync_type" value="status">
                <?php wp_nonce_field('fnk_erp_sync_now'); ?>
                <?php submit_button('اجرای وضعیت فنی‌کار', 'secondary', 'submit', false); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-left:8px;">
                <input type="hidden" name="action" value="fnk_erp_sync">
                <input type="hidden" name="sync_type" value="wpcode">
                <?php wp_nonce_field('fnk_erp_sync_now'); ?>
                <?php submit_button('بررسی wpCode منفی', 'secondary', 'submit', false); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-left:8px;">
                <input type="hidden" name="action" value="fnk_erp_test_connection">
                <?php wp_nonce_field('fnk_erp_test_connection'); ?>
                <?php submit_button('تست اتصال بدون تغییر', 'secondary', 'submit', false); ?>
            </form>
            <p class="description">آخرین گزارش اجرا در همین صفحه ذخیره می‌شود و علت خطا یا صفر بودن تغییرات را نشان می‌دهد.</p>
            <p class="description">نسخهٔ پلاگین: 1.0.7</p>
            <?php $this->renderScheduleSummary(); ?>
        </div>
        <?php
    }

    public function handleManualSync()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized.');
        }

        check_admin_referer('fnk_erp_sync_now');
        $syncType = sanitize_key($_POST['sync_type'] ?? 'full');
        if (!in_array($syncType, ['full', 'status', 'wpcode'], true)) {
            $syncType = 'full';
        }

        if ($syncType === 'status') {
            $this->runStatusSync(true);
        } elseif ($syncType === 'wpcode') {
            $this->runWpCodeSync(true);
        } else {
            $this->runFullSync(true);
        }

        wp_safe_redirect(admin_url('options-general.php?page=fnk-erp-sync'));
        exit;
    }

    public function handleConnectionTest()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized.');
        }

        check_admin_referer('fnk_erp_test_connection');
        $startedAt = microtime(true);
        $payload = $this->fetchPayload('full', true);

        if (is_wp_error($payload)) {
            $this->finishRun($payload, $startedAt, 'connection');
        } else {
            $this->finishRun(
                [
                    'connection_test' => true,
                    'endpoint' => $payload['_transport']['endpoint'] ?? null,
                    'http_code' => $payload['_transport']['http_code'] ?? null,
                    'cities_received' => count($payload['cities'] ?? []),
                    'services_received' => count($payload['services'] ?? []),
                    'technical_persons_received' => count($payload['technical_persons'] ?? []),
                    'blacklisted_ids_received' => count($payload['blacklisted_technical_person_ids'] ?? []),
                    'schema_version' => $payload['schema_version'] ?? null,
                ],
                $startedAt,
                'connection'
            );
        }

        wp_safe_redirect(admin_url('options-general.php?page=fnk-erp-sync'));
        exit;
    }

    public function runStatusSync($forceRefresh = false)
    {
        $this->syncStatus($forceRefresh);
    }

    public function runFullSync($forceRefresh = false)
    {
        $this->sync($forceRefresh);
    }

    public function runWpCodeSync($forceRefresh = false)
    {
        $this->syncWpCodeArchive($forceRefresh);
    }

    public function registerCronSchedules($schedules)
    {
        $statusSeconds = $this->getIntervalSeconds('status');
        $fullSeconds = $this->getIntervalSeconds('full');
        $wpCodeSeconds = $this->getIntervalSeconds('wpcode');

        $schedules['fnk_erp_status_interval'] = [
            'interval' => $statusSeconds,
            'display' => 'Fanikara ERP technical status interval',
        ];
        $schedules['fnk_erp_full_interval'] = [
            'interval' => $fullSeconds,
            'display' => 'Fanikara ERP full sync interval',
        ];
        $schedules['fnk_erp_wpcode_interval'] = [
            'interval' => $wpCodeSeconds,
            'display' => 'Fanikara ERP wpCode archive interval',
        ];

        return $schedules;
    }

    public function ensureSchedules()
    {
        $signature = $this->scheduleSignature();
        if (get_option(self::SCHEDULE_SIGNATURE_OPTION) !== $signature) {
            $this->rescheduleAll();
            return;
        }

        $this->scheduleIfMissing(self::STATUS_SYNC_HOOK, 'fnk_erp_status_interval');
        $this->scheduleIfMissing(self::FULL_SYNC_HOOK, 'fnk_erp_full_interval');
        $this->scheduleIfMissing(self::WPCODE_SYNC_HOOK, 'fnk_erp_wpcode_interval');
    }

    private function rescheduleAll()
    {
        wp_clear_scheduled_hook(self::LEGACY_SYNC_HOOK);
        wp_clear_scheduled_hook(self::STATUS_SYNC_HOOK);
        wp_clear_scheduled_hook(self::FULL_SYNC_HOOK);
        wp_clear_scheduled_hook(self::WPCODE_SYNC_HOOK);

        $now = time() + 60;
        wp_schedule_event($now, 'fnk_erp_status_interval', self::STATUS_SYNC_HOOK);
        wp_schedule_event($now + 30, 'fnk_erp_full_interval', self::FULL_SYNC_HOOK);
        wp_schedule_event($now + 60, 'fnk_erp_wpcode_interval', self::WPCODE_SYNC_HOOK);
        update_option(self::SCHEDULE_SIGNATURE_OPTION, $this->scheduleSignature(), false);
    }

    private function scheduleIfMissing($hook, $recurrence)
    {
        if (!wp_next_scheduled($hook)) {
            wp_schedule_event(time() + 60, $recurrence, $hook);
        }
    }

    private function scheduleSignature()
    {
        $settings = $this->getSettings();

        return implode('|', [
            $this->getIntervalSeconds('status'),
            $this->getIntervalSeconds('full'),
            $this->getIntervalSeconds('wpcode'),
        ]);
    }

    private function getIntervalSeconds($type)
    {
        $settings = $this->getSettings();
        $value = max(1, min(525600, (int)($settings[$type . '_interval_value'] ?? 1)));
        $unit = $this->sanitizeIntervalUnit($settings[$type . '_interval_unit'] ?? 'minutes');

        if ($unit === 'hours') {
            return max(MINUTE_IN_SECONDS, $value * HOUR_IN_SECONDS);
        }

        if ($unit === 'days') {
            return max(MINUTE_IN_SECONDS, $value * DAY_IN_SECONDS);
        }

        return max(MINUTE_IN_SECONDS, $value * MINUTE_IN_SECONDS);
    }

    private function sanitizeInterval($value, $min, $max)
    {
        return max($min, min($max, (int)$value));
    }

    private function sanitizeIntervalUnit($unit)
    {
        $unit = sanitize_key((string)$unit);
        return in_array($unit, self::SCHEDULE_UNITS, true) ? $unit : 'minutes';
    }

    private function renderScheduleSummary()
    {
        $items = [
            ['label' => 'وضعیت فنی‌کار', 'hook' => self::STATUS_SYNC_HOOK],
            ['label' => 'اطلاعات کامل', 'hook' => self::FULL_SYNC_HOOK],
            ['label' => 'wpCode منفی', 'hook' => self::WPCODE_SYNC_HOOK],
        ];

        echo '<p class="description"><strong>اجرای بعدی زمان‌بندی‌ها:</strong> ';
        $summary = [];
        foreach ($items as $item) {
            $next = wp_next_scheduled($item['hook']);
            $summary[] = esc_html($item['label']) . ': ' . esc_html($next ? wp_date('Y-m-d H:i:s', $next) : 'ثبت نشده');
        }
        echo implode(' | ', $summary) . '</p>';
    }

    public function sync($forceRefresh = false)
    {
        $startedAt = microtime(true);
        if (get_transient(self::LOCK_KEY)) {
            return $this->finishRun(
                new WP_Error('fnk_sync_locked', 'همگام‌سازی دیگری در حال اجراست.'),
                $startedAt
            );
        }

        set_transient(self::LOCK_KEY, 1, 15 * MINUTE_IN_SECONDS);

        try {
            $payload = $this->fetchPayload('full', $forceRefresh);
            if (is_wp_error($payload)) {
                return $this->finishRun($payload, $startedAt);
            }

            $transport = $payload['_transport'] ?? [];
            unset($payload['_transport']);

            $areaMap = $this->syncTerms($payload['cities'] ?? [], self::AREA_TAXONOMY, self::AREA_ID_META, true);
            if (is_wp_error($areaMap)) {
                return $this->finishRun($areaMap, $startedAt);
            }

            $serviceMap = $this->syncTerms($payload['services'] ?? [], self::SERVICE_TAXONOMY, self::SERVICE_ID_META);
            if (is_wp_error($serviceMap)) {
                return $this->finishRun($serviceMap, $startedAt);
            }

            $excludedServicesRemoved = $this->removeExcludedServiceTerms();
            if (is_wp_error($excludedServicesRemoved)) {
                return $this->finishRun($excludedServicesRemoved, $startedAt);
            }

            $result = $this->syncTechnicalPersons(
                $payload['technical_persons'] ?? [],
                $payload['blacklisted_technical_person_ids'] ?? [],
                $areaMap,
                $serviceMap
            );

            if (is_wp_error($result)) {
                return $this->finishRun($result, $startedAt);
            }

            $result['cities'] = count($areaMap);
            $result['services'] = count($serviceMap);
            $result['excluded_services_removed'] = $excludedServicesRemoved;
            $result['endpoint'] = $transport['endpoint'] ?? null;
            $result['http_code'] = $transport['http_code'] ?? null;
            $result['technical_persons_received'] = count($payload['technical_persons'] ?? []);
            $result['blacklisted_ids_received'] = count($payload['blacklisted_technical_person_ids'] ?? []);
            $result['schema_version'] = $payload['schema_version'] ?? null;

            return $this->finishRun($result, $startedAt);
        } catch (\Throwable $exception) {
            return $this->finishRun(
                new WP_Error(
                    'fnk_sync_exception',
                    'خطای PHP در اجرای sync: ' . $exception->getMessage(),
                    [
                        'file' => $exception->getFile(),
                        'line' => $exception->getLine(),
                    ]
                ),
                $startedAt
            );
        } finally {
            delete_transient(self::LOCK_KEY);
        }
    }

    private function syncStatus($forceRefresh = false)
    {
        $startedAt = microtime(true);
        if (get_transient(self::LOCK_KEY)) {
            return $this->finishRun(
                new WP_Error('fnk_sync_locked', 'همگام‌سازی دیگری در حال اجراست.'),
                $startedAt,
                'status'
            );
        }

        set_transient(self::LOCK_KEY, 1, 15 * MINUTE_IN_SECONDS);

        try {
            $payload = $this->fetchPayload('status', $forceRefresh);
            if (is_wp_error($payload)) {
                return $this->finishRun($payload, $startedAt, 'status');
            }

            $result = $this->syncTechnicalStatuses($payload['technical_persons'] ?? []);
            if (is_wp_error($result)) {
                return $this->finishRun($result, $startedAt, 'status');
            }

            $result['endpoint'] = $payload['_transport']['endpoint'] ?? null;
            $result['http_code'] = $payload['_transport']['http_code'] ?? null;
            $result['technical_persons_received'] = count($payload['technical_persons'] ?? []);
            $result['schema_version'] = $payload['schema_version'] ?? null;

            return $this->finishRun($result, $startedAt, 'status');
        } catch (\Throwable $exception) {
            return $this->finishRun(
                new WP_Error(
                    'fnk_sync_exception',
                    'خطای PHP در اجرای sync وضعیت: ' . $exception->getMessage(),
                    ['file' => $exception->getFile(), 'line' => $exception->getLine()]
                ),
                $startedAt,
                'status'
            );
        } finally {
            delete_transient(self::LOCK_KEY);
        }
    }

    private function syncWpCodeArchive($forceRefresh = false)
    {
        $startedAt = microtime(true);
        if (get_transient(self::LOCK_KEY)) {
            return $this->finishRun(
                new WP_Error('fnk_sync_locked', 'همگام‌سازی دیگری در حال اجراست.'),
                $startedAt,
                'wpcode'
            );
        }

        set_transient(self::LOCK_KEY, 1, 15 * MINUTE_IN_SECONDS);

        try {
            $payload = $this->fetchPayload('wpcode', $forceRefresh);
            if (is_wp_error($payload)) {
                return $this->finishRun($payload, $startedAt, 'wpcode');
            }

            $result = $this->archiveNegativeWpCode($payload['technical_persons'] ?? []);
            if (is_wp_error($result)) {
                return $this->finishRun($result, $startedAt, 'wpcode');
            }

            $result['endpoint'] = $payload['_transport']['endpoint'] ?? null;
            $result['http_code'] = $payload['_transport']['http_code'] ?? null;
            $result['technical_persons_received'] = count($payload['technical_persons'] ?? []);
            $result['schema_version'] = $payload['schema_version'] ?? null;

            return $this->finishRun($result, $startedAt, 'wpcode');
        } catch (\Throwable $exception) {
            return $this->finishRun(
                new WP_Error(
                    'fnk_sync_exception',
                    'خطای PHP در اجرای sync wpCode: ' . $exception->getMessage(),
                    ['file' => $exception->getFile(), 'line' => $exception->getLine()]
                ),
                $startedAt,
                'wpcode'
            );
        } finally {
            delete_transient(self::LOCK_KEY);
        }
    }

    private function fetchPayload($scope = 'full', $forceRefresh = false)
    {
        $settings = $this->getSettings();
        if (empty($settings['endpoint']) || empty($settings['token'])) {
            return new WP_Error(
                'fnk_sync_config',
                'آدرس API و توکن باید تنظیم شوند.',
                ['endpoint' => $settings['endpoint'], 'token_configured' => !empty($settings['token'])]
            );
        }

        $endpoint = add_query_arg('scope', $scope, $settings['endpoint']);
        if ($forceRefresh) {
            $endpoint = add_query_arg('refresh', '1', $endpoint);
        }
        $response = wp_remote_get(
            $endpoint,
            [
                'timeout' => 30,
                'headers' => [
                    'Accept' => 'application/json',
                    'X-Fanikara-Integration-Token' => $settings['token'],
                ],
            ]
        );

        if (is_wp_error($response)) {
            return new WP_Error(
                'fnk_sync_http_request',
                'اتصال به ERP برقرار نشد: ' . $response->get_error_message(),
                ['endpoint' => $endpoint, 'source_error' => $response->get_error_code()]
            );
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        if ($statusCode < 200 || $statusCode >= 300) {
            return new WP_Error(
                'fnk_sync_http',
                'ERP با کد HTTP ' . $statusCode . ' پاسخ داد.',
                [
                    'endpoint' => $endpoint,
                    'http_code' => $statusCode,
                    'response_body' => $this->truncate(wp_remote_retrieve_body($response)),
                ]
            );
        }

        $responseBody = wp_remote_retrieve_body($response);
        $payload = json_decode($responseBody, true);
        if (!is_array($payload) || ($payload['status'] ?? false) !== true) {
            return new WP_Error(
                'fnk_sync_payload',
                'ساختار پاسخ ERP معتبر نیست: ' . json_last_error_msg(),
                [
                    'endpoint' => $endpoint,
                    'http_code' => $statusCode,
                    'response_body' => $this->truncate($responseBody),
                ]
            );
        }

        $payload['_transport'] = [
            'endpoint' => $endpoint,
            'http_code' => $statusCode,
        ];

        return $payload;
    }

    private function syncTerms($items, $taxonomy, $metaKey, $preferExactName = false)
    {
        if (!taxonomy_exists($taxonomy)) {
            return new WP_Error('fnk_sync_taxonomy', 'تکسونومی پیدا نشد: ' . $taxonomy);
        }

        $map = [];
        foreach ($items as $item) {
            $erpId = isset($item['erp_id']) ? (string)(int)$item['erp_id'] : '';
            $name = isset($item['name']) ? sanitize_text_field($item['name']) : '';
            if ($erpId === '' || $name === '') {
                continue;
            }

            if ($taxonomy === self::SERVICE_TAXONOMY && in_array((int)$erpId, self::EXCLUDED_SERVICE_ERP_IDS, true)) {
                continue;
            }

            $terms = get_terms([
                'taxonomy' => $taxonomy,
                'hide_empty' => false,
                'number' => 2,
                'meta_query' => [
                    [
                        'key' => $metaKey,
                        'value' => $erpId,
                        'compare' => '=',
                    ],
                ],
            ]);

            if (is_wp_error($terms)) {
                return $terms;
            }

            if (count($terms) > 1) {
                return new WP_Error('fnk_sync_duplicate_term', 'برای ERP ID تکراری است: ' . $erpId);
            }

            if (empty($terms) && $preferExactName) {
                $sameNameTerms = get_terms([
                    'taxonomy' => $taxonomy,
                    'hide_empty' => false,
                    'number' => 2,
                    'name' => $name,
                ]);

                if (is_wp_error($sameNameTerms)) {
                    return $sameNameTerms;
                }

                if (count($sameNameTerms) > 1) {
                    return new WP_Error('fnk_sync_duplicate_name', 'برای شهر نام تکراری وجود دارد: ' . $name);
                }

                if (!empty($sameNameTerms)) {
                    $terms = $sameNameTerms;
                    update_term_meta((int)$terms[0]->term_id, $metaKey, $erpId);
                }
            }

            if (empty($terms)) {
                $existing = term_exists($name, $taxonomy);
                if ($existing) {
                    $termId = (int)(is_array($existing) ? $existing['term_id'] : $existing);
                    $existingErpId = (string)get_term_meta($termId, $metaKey, true);
                    if ($existingErpId !== '' && $existingErpId !== $erpId) {
                        return new WP_Error('fnk_sync_term_mapping_conflict', 'نام term با ERP ID دیگری متصل است: ' . $name);
                    }
                } else {
                    $created = wp_insert_term($name, $taxonomy);
                    if (is_wp_error($created)) {
                        return $created;
                    }
                    $termId = (int)$created['term_id'];
                }
            } else {
                $termId = (int)$terms[0]->term_id;
                $updatedTerm = wp_update_term($termId, $taxonomy, ['name' => $name]);
                if (is_wp_error($updatedTerm)) {
                    return $updatedTerm;
                }
            }

            update_term_meta($termId, $metaKey, $erpId);
            $map[$erpId] = $termId;
        }

        return $map;
    }

    private function syncTechnicalPersons($items, $blacklistedIds, $areaMap, $serviceMap)
    {
        if (!post_type_exists(self::TECHNICAL_POST_TYPE)) {
            return new WP_Error('fnk_sync_post_type', 'پست تایپ fanikar پیدا نشد.');
        }

        $index = $this->indexTechnicalPosts();
        $created = 0;
        $updated = 0;
        $archived = 0;
        $conflicts = [];
        $missingMobile = 0;

        foreach ($items as $item) {
            $erpId = isset($item['erp_id']) ? (string)(int)$item['erp_id'] : '';
            $mobile = $this->normalizePhone($item['mobile'] ?? '');
            if ($erpId === '') {
                continue;
            }

            $postId = $index['by_erp_id'][$erpId] ?? 0;
            if (!$postId) {
                if ($mobile === '') {
                    $missingMobile++;
                    continue;
                }

                $phoneMatches = $index['by_phone'][$mobile] ?? [];
                if (count($phoneMatches) > 1) {
                    $conflicts[] = $mobile;
                    continue;
                }
                $postId = (int)($phoneMatches[0] ?? 0);
            }

            $title = sanitize_text_field($item['title'] ?? '');
            if ($title === '') {
                $title = trim(sanitize_text_field($item['first_name'] ?? '') . ' ' . sanitize_text_field($item['last_name'] ?? ''));
            }

            if (!$postId) {
                $postId = wp_insert_post(
                    [
                        'post_type' => self::TECHNICAL_POST_TYPE,
                        'post_title' => $title,
                        'post_status' => 'publish',
                    ],
                    true
                );
                if (is_wp_error($postId)) {
                    return $postId;
                }
                $created++;
            } else {
                $post = get_post($postId);
                if ($post && get_post_meta($postId, self::ARCHIVED_REASON_META, true) === 'blacklisted') {
                    wp_update_post(['ID' => $postId, 'post_status' => 'publish']);
                    delete_post_meta($postId, self::ARCHIVED_REASON_META);
                }
                wp_update_post(['ID' => $postId, 'post_title' => $title]);
                $updated++;
            }

            update_post_meta($postId, self::TECHNICAL_ID_META, $erpId);
            update_post_meta($postId, self::WORK_STATUS_META, !empty($item['work_status']) ? 1 : 0);
            if ($mobile !== '') {
                update_post_meta($postId, self::SMS_PHONE_META, $mobile);
                update_post_meta($postId, self::DIRECT_PHONE_META, $mobile);
            }
            $registrationDate = sanitize_text_field($item['registration_date'] ?? '');
            if ($registrationDate !== '') {
                update_post_meta($postId, self::REGISTRATION_DATE_META, $registrationDate);
            } else {
                delete_post_meta($postId, self::REGISTRATION_DATE_META);
            }
            update_post_meta($postId, self::WORK_HOURS_START_META, sanitize_text_field($item['work_hours_start'] ?? ''));
            update_post_meta($postId, self::WORK_HOURS_END_META, sanitize_text_field($item['work_hours_end'] ?? ''));
            update_post_meta($postId, self::DONE_SERVICES_META, max(0, (int)($item['done_services_count'] ?? 0)));
            update_post_meta($postId, self::SYNCED_AT_META, current_time('mysql', true));

            $areaTerms = $this->resolveTerms($item['area_erp_ids'] ?? [], $areaMap);
            $serviceTerms = $this->resolveTerms($item['service_erp_ids'] ?? [], $serviceMap);

            $areaResult = wp_set_object_terms($postId, $areaTerms, self::AREA_TAXONOMY, false);
            if (is_wp_error($areaResult)) {
                return $areaResult;
            }

            $serviceResult = wp_set_object_terms($postId, $serviceTerms, self::SERVICE_TAXONOMY, false);
            if (is_wp_error($serviceResult)) {
                return $serviceResult;
            }

            update_post_meta(
                $postId,
                self::SERVICE_SCORES_META,
                $this->resolveServiceScores($item['service_scores'] ?? [], $serviceMap)
            );
        }

        foreach ($blacklistedIds as $blacklistedId) {
            $postId = $index['by_erp_id'][(string)(int)$blacklistedId] ?? 0;
            if (!$postId) {
                continue;
            }

            wp_update_post(['ID' => $postId, 'post_status' => 'draft']);
            update_post_meta($postId, self::ARCHIVED_REASON_META, 'blacklisted');
            wp_set_object_terms($postId, [], self::AREA_TAXONOMY, false);
            wp_set_object_terms($postId, [], self::SERVICE_TAXONOMY, false);
            $archived++;
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'archived_blacklisted' => $archived,
            'phone_conflicts' => $conflicts,
            'missing_mobile_without_erp_match' => $missingMobile,
        ];
    }

    private function syncTechnicalStatuses($items)
    {
        if (!post_type_exists(self::TECHNICAL_POST_TYPE)) {
            return new WP_Error('fnk_sync_post_type', 'پست تایپ fanikar پیدا نشد.');
        }

        $index = $this->indexTechnicalPosts();
        $updated = 0;
        $missing = 0;

        foreach ($items as $item) {
            $erpId = isset($item['erp_id']) ? (string)(int)$item['erp_id'] : '';
            if ($erpId === '') {
                continue;
            }

            $postId = $index['by_erp_id'][$erpId] ?? 0;
            if (!$postId) {
                $missing++;
                continue;
            }

            update_post_meta($postId, self::WORK_STATUS_META, !empty($item['work_status']) ? 1 : 0);
            update_post_meta($postId, self::SYNCED_AT_META, current_time('mysql', true));
            $updated++;
        }

        return [
            'status_updated' => $updated,
            'status_posts_missing' => $missing,
        ];
    }

    private function archiveNegativeWpCode($items)
    {
        if (!post_type_exists(self::TECHNICAL_POST_TYPE)) {
            return new WP_Error('fnk_sync_post_type', 'پست تایپ fanikar پیدا نشد.');
        }

        $index = $this->indexTechnicalPosts();
        $archived = 0;
        $restored = 0;
        $missing = 0;

        foreach ($items as $item) {
            $erpId = isset($item['erp_id']) ? (string)(int)$item['erp_id'] : '';
            if ($erpId === '') {
                continue;
            }

            $postId = $index['by_erp_id'][$erpId] ?? 0;
            if (!$postId) {
                $missing++;
                continue;
            }

            $wpCode = trim((string)($item['wp_code'] ?? ''));
            if ($wpCode !== '' && is_numeric($wpCode) && (int)$wpCode < 0) {
                wp_update_post(['ID' => $postId, 'post_status' => 'draft']);
                update_post_meta($postId, self::WORK_STATUS_META, 1);
                update_post_meta($postId, self::ARCHIVED_REASON_META, 'wpcode_negative');
                update_post_meta($postId, self::SYNCED_AT_META, current_time('mysql', true));
                $archived++;
                continue;
            }

            if (get_post_meta($postId, self::ARCHIVED_REASON_META, true) === 'wpcode_negative') {
                wp_update_post(['ID' => $postId, 'post_status' => 'publish']);
                delete_post_meta($postId, self::ARCHIVED_REASON_META);
                $restored++;
            }
        }

        return [
            'wpcode_archived' => $archived,
            'wpcode_restored' => $restored,
            'wpcode_posts_missing' => $missing,
        ];
    }

    private function removeExcludedServiceTerms()
    {
        if (!taxonomy_exists(self::SERVICE_TAXONOMY)) {
            return new WP_Error('fnk_sync_taxonomy', 'تکسونومی پیدا نشد: ' . self::SERVICE_TAXONOMY);
        }

        $terms = get_terms([
            'taxonomy' => self::SERVICE_TAXONOMY,
            'hide_empty' => false,
            'number' => 0,
            'meta_query' => [
                [
                    'key' => self::SERVICE_ID_META,
                    'value' => self::EXCLUDED_SERVICE_ERP_IDS,
                    'compare' => 'IN',
                ],
            ],
        ]);

        if (is_wp_error($terms)) {
            return $terms;
        }

        $removed = 0;
        foreach ($terms as $term) {
            $deleted = wp_delete_term((int)$term->term_id, self::SERVICE_TAXONOMY);
            if (is_wp_error($deleted)) {
                return $deleted;
            }
            if ($deleted) {
                $removed++;
            }
        }

        return $removed;
    }

    private function indexTechnicalPosts()
    {
        $index = ['by_erp_id' => [], 'by_phone' => []];
        $postIds = get_posts([
            'post_type' => self::TECHNICAL_POST_TYPE,
            'post_status' => 'any',
            'numberposts' => -1,
            'fields' => 'ids',
        ]);

        foreach ($postIds as $postId) {
            $erpId = (string)(int)get_post_meta($postId, self::TECHNICAL_ID_META, true);
            if ($erpId !== '0') {
                $index['by_erp_id'][$erpId] = (int)$postId;
            }

            foreach ([self::SMS_PHONE_META, self::DIRECT_PHONE_META, self::LEGACY_PHONE_META] as $metaKey) {
                $phone = $this->normalizePhone(get_post_meta($postId, $metaKey, true));
                if ($phone !== '') {
                    $index['by_phone'][$phone][] = (int)$postId;
                }
            }
        }

        foreach ($index['by_phone'] as $phone => $postIdsForPhone) {
            $index['by_phone'][$phone] = array_values(array_unique($postIdsForPhone));
        }

        return $index;
    }

    private function resolveTerms($erpIds, $termMap)
    {
        $termIds = [];
        foreach ((array)$erpIds as $erpId) {
            $key = (string)(int)$erpId;
            if (isset($termMap[$key])) {
                $termIds[] = (int)$termMap[$key];
            }
        }

        return array_values(array_unique($termIds));
    }

    private function resolveServiceScores($scores, $termMap)
    {
        $resolved = [];
        foreach ((array)$scores as $score) {
            $erpId = isset($score['service_erp_id']) ? (string)(int)$score['service_erp_id'] : '';
            if ($erpId === '' || !isset($termMap[$erpId]) || !isset($score['priority']) || !is_numeric($score['priority'])) {
                continue;
            }

            $termId = (int)$termMap[$erpId];
            $resolved[$termId] = (int)$score['priority'];
        }

        return $resolved;
    }

    private function normalizePhone($phone)
    {
        $phone = strtr((string)$phone, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        $phone = preg_replace('/\D+/', '', $phone);

        if (strpos($phone, '0098') === 0) {
            $phone = '0' . substr($phone, 4);
        } elseif (strpos($phone, '98') === 0) {
            $phone = '0' . substr($phone, 2);
        } elseif (strpos($phone, '9') === 0 && strlen($phone) === 10) {
            $phone = '0' . $phone;
        }

        return strlen($phone) === 11 && strpos($phone, '09') === 0 ? $phone : '';
    }

    private function getSettings()
    {
        $defaults = $this->defaultSettings();
        $stored = (array)get_option(self::OPTION_KEY, []);
        $settings = wp_parse_args($stored, $defaults);

        if (!array_key_exists('status_interval_value', $stored) && isset($stored['status_interval_minutes'])) {
            $settings['status_interval_value'] = $stored['status_interval_minutes'];
            $settings['status_interval_unit'] = 'minutes';
        }
        if (!array_key_exists('full_interval_value', $stored) && isset($stored['full_interval_days'])) {
            $settings['full_interval_value'] = $stored['full_interval_days'];
            $settings['full_interval_unit'] = 'days';
        }
        if (!array_key_exists('wpcode_interval_value', $stored) && isset($stored['wpcode_interval_days'])) {
            $settings['wpcode_interval_value'] = $stored['wpcode_interval_days'];
            $settings['wpcode_interval_unit'] = 'days';
        }

        foreach ($defaults as $key => $defaultValue) {
            if (!isset($settings[$key]) || $settings[$key] === '') {
                $settings[$key] = $defaultValue;
            }
        }

        return $settings;
    }

    private function defaultSettings()
    {
        return [
            'endpoint' => 'https://fkcerp.ir/web/site/api-wordpress-integration',
            'token' => 'fkc7777FaniKara4545545sg5v45fers23r34cxxc45df45f5sds3',
            'status_interval_value' => 1,
            'status_interval_unit' => 'minutes',
            'full_interval_value' => 7,
            'full_interval_unit' => 'days',
            'wpcode_interval_value' => 7,
            'wpcode_interval_unit' => 'days',
        ];
    }

    private function finishRun($result, $startedAt, $syncType = 'full')
    {
        $report = [
            'success' => !is_wp_error($result),
            'sync_type' => $syncType,
            'finished_at' => current_time('mysql'),
            'duration_ms' => (int)round((microtime(true) - $startedAt) * 1000),
        ];

        if (is_wp_error($result)) {
            $report['message'] = $result->get_error_message();
            $report['error_code'] = $result->get_error_code();
            $report['details'] = $result->get_error_data();
        } else {
            $report['message'] = $this->formatResult($result);
            $report['details'] = $result;
        }

        update_option(self::LAST_RUN_OPTION, $report, false);
        $reports = get_option(self::LAST_RUNS_OPTION, []);
        if (!is_array($reports)) {
            $reports = [];
        }
        $reports[$syncType] = $report;
        update_option(self::LAST_RUNS_OPTION, $reports, false);

        if (!$report['success']) {
            error_log('[Fanikara ERP Sync] ' . $report['message']);
        }

        return $result;
    }

    private function renderLastRunReport()
    {
        $reports = get_option(self::LAST_RUNS_OPTION, []);
        if (!is_array($reports) || empty($reports)) {
            $legacyReport = get_option(self::LAST_RUN_OPTION, null);
            $reports = is_array($legacyReport) ? ['full' => $legacyReport] : [];
        }

        if (empty($reports)) {
            echo '<div class="notice notice-warning"><p>هنوز هیچ اجرای sync در این نصب ثبت نشده است. اگر بعد از اجرای دستی این پیام باقی ماند، نسخهٔ نصب‌شدهٔ پلاگین به‌روز نیست یا فرم اجرای sync به این پلاگین متصل نیست.</p></div>';
            return;
        }

        $labels = [
            'full' => 'اطلاعات کامل',
            'status' => 'وضعیت فنی‌کار',
            'wpcode' => 'wpCode منفی',
            'connection' => 'تست اتصال',
        ];
        foreach (['full', 'status', 'wpcode', 'connection'] as $type) {
            if (isset($reports[$type]) && is_array($reports[$type])) {
                $this->renderSingleReport($labels[$type] ?? $type, $reports[$type]);
            }
        }
    }

    private function renderSingleReport($label, $report)
    {
        $noticeClass = !empty($report['success']) ? 'notice-success' : 'notice-error';
        echo '<div class="notice ' . esc_attr($noticeClass) . '"><p><strong>آخرین اجرای ' . esc_html($label) . ':</strong> ' . esc_html($report['message'] ?? '') . '</p></div>';
        echo '<div class="notice notice-info"><p>';
        echo 'زمان: ' . esc_html($report['finished_at'] ?? '-') . ' | ';
        echo 'مدت: ' . esc_html((string)($report['duration_ms'] ?? 0)) . ' ms';
        if (!empty($report['error_code'])) {
            echo ' | کد خطا: ' . esc_html($report['error_code']);
        }
        echo '</p>';

        if (!empty($report['details'])) {
            echo '<details><summary>جزئیات فنی گزارش</summary><pre style="white-space:pre-wrap;max-height:360px;overflow:auto;">';
            echo esc_html(wp_json_encode($report['details'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            echo '</pre></details>';
        }
        echo '</div>';
    }

    private function truncate($value, $length = 1000)
    {
        $value = (string)$value;
        return strlen($value) > $length ? substr($value, 0, $length) . '...' : $value;
    }

    private function formatResult($result)
    {
        if (!empty($result['connection_test'])) {
            return sprintf(
                'تست اتصال موفق بود: HTTP %s، شهر %d، خدمت %d، فنی‌کار %d، شناسه blacklist %d.',
                (string)($result['http_code'] ?? '-'),
                (int)($result['cities_received'] ?? 0),
                (int)($result['services_received'] ?? 0),
                (int)($result['technical_persons_received'] ?? 0),
                (int)($result['blacklisted_ids_received'] ?? 0)
            );
        }

        if (isset($result['status_updated'])) {
            return sprintf(
                'همگام‌سازی وضعیت انجام شد: دریافتی %d، به‌روزرسانی‌شده %d، پست پیدا‌نشده %d.',
                (int)($result['technical_persons_received'] ?? 0),
                (int)$result['status_updated'],
                (int)($result['status_posts_missing'] ?? 0)
            );
        }

        if (isset($result['wpcode_archived'])) {
            return sprintf(
                'بررسی wpCode انجام شد: آرشیو پیش‌نویس %d، بازگردانی %d، پست پیدا‌نشده %d.',
                (int)$result['wpcode_archived'],
                (int)($result['wpcode_restored'] ?? 0),
                (int)($result['wpcode_posts_missing'] ?? 0)
            );
        }

        $message = sprintf(
            'همگام‌سازی انجام شد: شهر %d، خدمت %d، فنی‌کار دریافتی %d، ایجاد %d، به‌روزرسانی %d، آرشیو لیست سیاه %d.',
            (int)($result['cities'] ?? 0),
            (int)($result['services'] ?? 0),
            (int)($result['technical_persons_received'] ?? 0),
            (int)($result['created'] ?? 0),
            (int)($result['updated'] ?? 0),
            (int)($result['archived_blacklisted'] ?? 0)
        );

        if ((int)($result['excluded_services_removed'] ?? 0) > 0) {
            $message .= sprintf(' %d دسته‌فعالیت مستثنا از وردپرس حذف شد.', (int)$result['excluded_services_removed']);
        }

        if ((int)($result['created'] ?? 0) === 0 && (int)($result['updated'] ?? 0) === 0 && (int)($result['archived_blacklisted'] ?? 0) === 0) {
            $message .= ' هیچ پست فنی‌کاری تغییر نکرد؛ جزئیات mapping و conflict را بررسی کنید.';
        }

        return $message;
    }
}
