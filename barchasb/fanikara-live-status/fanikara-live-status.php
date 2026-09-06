<?php
/**
 * Plugin Name: Fanikara Live Status
 * Description: پلاگین مدیریت موقعیت زنده فنی‌کارها و نقشه تعاملی لوله بازکنی برای ارتقای سئو و تعامل کاربر.
 * Version: 1.2.0
 * Author: Javad Absalan from FaniKara.com
 * Text Domain: fanikara-live-status
 */

if (!defined('ABSPATH')) {
    exit;
}

define('FLS_PATH', plugin_dir_path(__FILE__));
define('FLS_URL', plugin_dir_url(__FILE__));

class FaniKara_Live_Status {
    private static $instance = null;

    public static function get_instance() {
        if (self::$instance == null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        register_activation_hook(__FILE__, array($this, 'create_db_tables'));
        register_deactivation_hook(__FILE__, array($this, 'plugin_deactivate'));
        
        add_action('plugins_loaded', array($this, 'maybe_upgrade_database'));
        add_action('admin_menu', array($this, 'register_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('wp_enqueue_scripts', array($this, 'register_frontend_assets'));
        add_action('admin_init', array($this, 'handle_json_import_export'));
        
        add_shortcode('fanikara_live_status', array($this, 'render_shortcode'));
    }

    public function create_db_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $table_maps = $wpdb->prefix . 'fls_default_maps';
        $sql_maps = "CREATE TABLE $table_maps (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            post_id bigint(20) NOT NULL,
            city varchar(100) NOT NULL,
            region varchar(100) NOT NULL,
            lat varchar(50) NOT NULL,
            lng varchar(50) NOT NULL,
            PRIMARY KEY  (id),
            KEY post_id (post_id)
        ) $charset_collate;";

        $table_techs = $wpdb->prefix . 'fls_technicians';
        $sql_techs = "CREATE TABLE $table_techs (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            name varchar(150) NOT NULL,
            phone varchar(50) NOT NULL,
            city varchar(100) NOT NULL,
            location_name varchar(150) NOT NULL,
            lat varchar(50) NOT NULL,
            lng varchar(50) NOT NULL,
            satisfaction int(3) NOT NULL,
            satisfaction_count bigint(20) unsigned NOT NULL DEFAULT 0,
            experience varchar(50) NOT NULL,
            clearance_img varchar(255) DEFAULT '',
            profile_img varchar(255) DEFAULT '',
            is_featured tinyint(1) DEFAULT 0,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_maps);
        dbDelta($sql_techs);
        update_option('fls_db_version', '1.2.0');
    }

    public function maybe_upgrade_database() {
        if (get_option('fls_db_version') !== '1.2.0') {
            $this->create_db_tables();
        }
    }

    // Convert the current Gregorian date to a Solar Hijri year, including Nowruz.
    private function current_solar_year() {
        $date = current_datetime();
        $gy = (int) $date->format('Y');
        $gm = (int) $date->format('n');
        $gd = (int) $date->format('j');
        $month_days = array(0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334);
        $gy2 = $gm > 2 ? $gy + 1 : $gy;
        $days = 355666 + 365 * $gy + intdiv($gy2 + 3, 4)
            - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400)
            + $gd + $month_days[$gm - 1];
        $jy = -1595 + 33 * intdiv($days, 12053);
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
        }
        return $jy;
    }

    private function activity_start_year($experience) {
        $year = (int) strtr((string) $experience, array_combine(
            preg_split('//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY),
            str_split('01234567890123456789')
        ));
        // Older records store a duration instead of a Solar Hijri start year.
        return $year >= 1000 ? $year : $this->current_solar_year() - max(0, $year);
    }

    // Shift the selected estimates together, preserving distance order and bounds.
    private function balance_arrival_times($technicians) {
        if (!$technicians) {
            return $technicians;
        }
        $times = array_column($technicians, '_fls_estimated_time');
        $low = 25 - max($times);
        $high = 38 - min($times);
        for ($step = 0; $step < 50; $step++) {
            $shift = ($low + $high) / 2;
            $sum = 0;
            foreach ($times as $time) {
                $sum += max(25, min(38, $time + $shift));
            }
            if ($sum < 30 * count($times)) {
                $low = $shift;
            } else {
                $high = $shift;
            }
        }
        foreach ($technicians as &$technician) {
            $technician['_fls_estimated_time'] = (int) round(max(25, min(38,
                $technician['_fls_estimated_time'] + ($low + $high) / 2)));
        }
        unset($technician);
        return $technicians;
    }

    public function plugin_deactivate() {
        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS " . $wpdb->prefix . "fls_default_maps");
        $wpdb->query("DROP TABLE IF EXISTS " . $wpdb->prefix . "fls_technicians");
    }

    public function register_admin_menu() {
        add_menu_page(
            'موقعیت زنده فنی کارها',
            'موقعیت زنده فنی کارها',
            'manage_options',
            'fls-main-menu',
            array($this, 'page_default_maps'),
            'dashicons-location-alt',
            30
        );

        add_submenu_page(
            'fls-main-menu',
            'نقشه‌های پیش‌فرض',
            'نقشه‌های پیش‌فرض',
            'manage_options',
            'fls-main-menu',
            array($this, 'page_default_maps')
        );

        add_submenu_page(
            'fls-main-menu',
            'فنی کارها',
            'فنی کارها',
            'manage_options',
            'fls-technicians',
            array($this, 'page_technicians')
        );
    }

    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'fls-') !== false) {
            wp_enqueue_media();
            wp_enqueue_style('fls-admin-style', FLS_URL . 'fls-style.css', array(), '1.0.4');
        }
    }

    public function register_frontend_assets() {
        wp_register_style('fls-style', FLS_URL . 'fls-style.css', array(), '1.0.4');
        wp_register_style(
            'leaflet-css',
            'https://static.neshan.org/sdk/leaflet/v1.9.4/neshan-sdk/v1.0.8/index.css',
            array(),
            '1.0.8'
        );
        wp_register_script(
            'leaflet-js',
            'https://static.neshan.org/sdk/leaflet/v1.9.4/neshan-sdk/v1.0.8/index.js',
            array(),
            '1.0.8',
            true
        );
    }

    /* --- بخش مدیریت نقشه‌های پیش‌فرض --- */
    public function page_default_maps() {
        global $wpdb;
        $table = $wpdb->prefix . 'fls_default_maps';

        // ذخیره یا ویرایش
        if (isset($_POST['action_save_map']) && check_admin_referer('fls_save_map_nonce')) {
            $map_id = isset($_POST['map_id']) ? intval($_POST['map_id']) : 0;
            $data = array(
                'post_id' => intval($_POST['post_id']),
                'city'    => sanitize_text_field($_POST['city']),
                'region'  => sanitize_text_field($_POST['region']),
                'lat'     => sanitize_text_field($_POST['lat']),
                'lng'     => sanitize_text_field($_POST['lng']),
            );

            if ($map_id > 0) {
                $wpdb->update($table, $data, array('id' => $map_id));
                echo '<div class="notice notice-success"><p>نقشه پیش‌فرض به‌روزرسانی شد.</p></div>';
            } else {
                $wpdb->insert($table, $data);
                echo '<div class="notice notice-success"><p>نقشه پیش‌فرض با موفقیت ثبت شد.</p></div>';
            }
        }

        // حذف
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
            $id = intval($_GET['id']);
            $wpdb->delete($table, array('id' => $id));
            echo '<div class="notice notice-success"><p>رکورد حذف شد.</p></div>';
        }

        // دریافت داده جهت ویرایش
        $edit_map = null;
        if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
            $edit_map = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", intval($_GET['id'])));
        }

        $records = $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC");
        ?>
        <div class="wrap">
            <h1>مدیریت نقشه‌های پیش‌فرض</h1>
            <div class="notice notice-info" style="padding: 10px; margin: 15px 0;">
                <strong>شورتکد استفاده در صفحات:</strong> <code>[fanikara_live_status]</code>
            </div>

            <form method="post" style="background:#fff; padding:20px; border:1px solid #ccc; margin-bottom:20px;">
                <?php wp_nonce_field('fls_save_map_nonce'); ?>
                <input type="hidden" name="map_id" value="<?php echo $edit_map ? $edit_map->id : 0; ?>">
                <h3><?php echo $edit_map ? 'ویرایش نقشه پیش‌فرض' : 'افزودن موقعیت پیش‌فرض جدید'; ?></h3>
                <table class="form-table">
                    <tr>
                        <th>شناسه پست (Post ID)</th>
                        <td><input type="number" name="post_id" value="<?php echo $edit_map ? $edit_map->post_id : ''; ?>" required placeholder="مثلاً: 1042" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th>نام شهر</th>
                        <td><input type="text" name="city" value="<?php echo $edit_map ? esc_attr($edit_map->city) : ''; ?>" required placeholder="مثلاً: تهران" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th>نام منطقه</th>
                        <td><input type="text" name="region" value="<?php echo $edit_map ? esc_attr($edit_map->region) : ''; ?>" required placeholder="مثلاً: ونک" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th>عرض جغرافیایی (Latitude)</th>
                        <td><input type="text" name="lat" value="<?php echo $edit_map ? esc_attr($edit_map->lat) : ''; ?>" required placeholder="مثلاً: 35.7754" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th>طول جغرافیایی (Longitude)</th>
                        <td><input type="text" name="lng" value="<?php echo $edit_map ? esc_attr($edit_map->lng) : ''; ?>" required placeholder="مثلاً: 51.3912" class="regular-text"></td>
                    </tr>
                </table>
                <p>
                    <input type="submit" name="action_save_map" class="button button-primary" value="<?php echo $edit_map ? 'ویرایش نقشه' : 'ثبت نقشه پیش‌فرض'; ?>">
                    <?php if ($edit_map): ?>
                        <a href="<?php echo admin_url('admin.php?page=fls-main-menu'); ?>" class="button button-secondary">انصراف</a>
                    <?php endif; ?>
                </p>
            </form>

            <div style="background:#fff; padding:15px; border:1px solid #ccc; margin-bottom:20px;">
                <h3>خروجی / ورودی اطلاعات (JSON)</h3>
                <a href="<?php echo admin_url('admin.php?page=fls-main-menu&export_maps=1'); ?>" class="button button-secondary">دانلود خروجی JSON نقشه‌ها</a>
                <form method="post" enctype="multipart/form-data" style="display:inline-block; margin-right:20px;">
                    <?php wp_nonce_field('fls_import_nonce'); ?>
                    <input type="file" name="import_json" required>
                    <input type="submit" name="import_maps_json" class="button button-secondary" value="بارگذاری ورودی JSON">
                </form>
            </div>

            <h2>لیست نقشه‌های پیش‌فرض ثبت شده</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>شناسه پست</th>
                        <th>شهر</th>
                        <th>منطقه</th>
                        <th>مختصات (Lat, Lng)</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($records as $r): ?>
                    <tr>
                        <td><?php echo $r->id; ?></td>
                        <td><?php echo $r->post_id; ?></td>
                        <td><?php echo esc_html($r->city); ?></td>
                        <td><?php echo esc_html($r->region); ?></td>
                        <td><?php echo esc_html($r->lat . ', ' . $r->lng); ?></td>
                        <td>
                            <a href="<?php echo admin_url('admin.php?page=fls-main-menu&action=edit&id=' . $r->id); ?>" class="button button-small">ویرایش</a>
                            <a href="<?php echo admin_url('admin.php?page=fls-main-menu&action=delete&id=' . $r->id); ?>" onclick="return confirm('آیا از حذف مطمئن هستید؟')" class="button button-small button-link-delete">حذف</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /* --- بخش مدیریت فنی‌کارها --- */
    public function page_technicians() {
        global $wpdb;
        $table = $wpdb->prefix . 'fls_technicians';

        // ذخیره یا ویرایش
        if (isset($_POST['action_save_tech']) && check_admin_referer('fls_save_tech_nonce')) {
            $tech_id = isset($_POST['tech_id']) ? intval($_POST['tech_id']) : 0;
            $data = array(
                'name'          => sanitize_text_field($_POST['name']),
                'phone'         => sanitize_text_field($_POST['phone']),
                'city'          => sanitize_text_field($_POST['city']),
                'location_name' => sanitize_text_field($_POST['location_name']),
                'lat'           => sanitize_text_field($_POST['lat']),
                'lng'           => sanitize_text_field($_POST['lng']),
                'satisfaction'  => intval($_POST['satisfaction']),
                'satisfaction_count' => max(0, (int) ($_POST['satisfaction_count'] ?? 0)),
                'experience'    => sanitize_text_field($_POST['experience']),
                'clearance_img' => esc_url_raw($_POST['clearance_img']),
                'profile_img'   => esc_url_raw($_POST['profile_img']),
                'is_featured'   => isset($_POST['is_featured']) ? 1 : 0,
            );

            if ($tech_id > 0) {
                $wpdb->update($table, $data, array('id' => $tech_id));
                echo '<div class="notice notice-success"><p>اطلاعات فنی‌کار به روزرسانی شد.</p></div>';
            } else {
                $wpdb->insert($table, $data);
                echo '<div class="notice notice-success"><p>فنی‌کار با موفقیت اضافه شد.</p></div>';
            }
        }

        // حذف
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
            $id = intval($_GET['id']);
            $wpdb->delete($table, array('id' => $id));
            echo '<div class="notice notice-success"><p>فنی‌کار حذف شد.</p></div>';
        }

        // دریافت جهت ویرایش
        $edit_tech = null;
        if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
            $edit_tech = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", intval($_GET['id'])));
        }

        $records = $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC");
        ?>
        <div class="wrap">
            <h1>مدیریت فنی‌کارها</h1>
            <form method="post" style="background:#fff; padding:20px; border:1px solid #ccc; margin-bottom:20px;">
                <?php wp_nonce_field('fls_save_tech_nonce'); ?>
                <input type="hidden" name="tech_id" value="<?php echo $edit_tech ? $edit_tech->id : 0; ?>">
                <h3><?php echo $edit_tech ? 'ویرایش اطلاعات فنی‌کار' : 'افزودن فنی‌کار جدید'; ?></h3>
                <table class="form-table">
                    <tr>
                        <th>نام و نام خانوادگی</th>
                        <td><input type="text" name="name" value="<?php echo $edit_tech ? esc_attr($edit_tech->name) : ''; ?>" required placeholder="مثلاً: علی محمدی" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th>شماره تماس</th>
                        <td><input type="text" name="phone" value="<?php echo $edit_tech ? esc_attr($edit_tech->phone) : ''; ?>" required placeholder="مثلاً: 09123456789" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th>شهر</th>
                        <td><input type="text" name="city" value="<?php echo $edit_tech ? esc_attr($edit_tech->city) : ''; ?>" required placeholder="مثلاً: تهران" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th>نام محل استقرار</th>
                        <td><input type="text" name="location_name" value="<?php echo $edit_tech ? esc_attr($edit_tech->location_name) : ''; ?>" required placeholder="مثلاً: میدان ونک" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th>مختصات جغرافیایی (Lat / Lng)</th>
                        <td>
                            <input type="text" name="lat" value="<?php echo $edit_tech ? esc_attr($edit_tech->lat) : ''; ?>" required placeholder="عرض جغرافیایی (Lat): مثلاً 35.7754" style="width: 48%;">
                            <input type="text" name="lng" value="<?php echo $edit_tech ? esc_attr($edit_tech->lng) : ''; ?>" required placeholder="طول جغرافیایی (Lng): مثلاً 51.3912" style="width: 48%;">
                        </td>
                    </tr>
                    <tr>
                        <th>درصد رضایت مشتریان</th>
                        <td><input type="number" min="0" max="100" name="satisfaction" value="<?php echo $edit_tech ? $edit_tech->satisfaction : ''; ?>" required placeholder="مثلاً: 98" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th>تعداد رضایت‌سنجی</th>
                        <td><input type="number" min="0" step="1" name="satisfaction_count" value="<?php echo esc_attr($edit_tech->satisfaction_count ?? 0); ?>" required class="regular-text"></td>
                    </tr>
                    <tr>
                        <th>سال شروع فعالیت (شمسی)</th>
                        <td><input type="text" name="experience" value="<?php echo $edit_tech ? esc_attr($this->activity_start_year($edit_tech->experience)) : ''; ?>" required placeholder="مثلاً: 1390" inputmode="numeric" pattern="[0-9۰-۹٠-٩]{4}" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th>تصویر پروفایل</th>
                        <td>
                            <input type="text" name="profile_img" id="profile_img" value="<?php echo $edit_tech ? esc_url($edit_tech->profile_img) : ''; ?>" class="regular-text">
                            <button type="button" class="button fls-upload-btn" data-target="#profile_img">انتخاب از گالری</button>
                        </td>
                    </tr>
                    <tr>
                        <th>تصویر گواهی عدم سوء پیشینه</th>
                        <td>
                            <input type="text" name="clearance_img" id="clearance_img" value="<?php echo $edit_tech ? esc_url($edit_tech->clearance_img) : ''; ?>" class="regular-text">
                            <button type="button" class="button fls-upload-btn" data-target="#clearance_img">انتخاب از گالری</button>
                        </td>
                    </tr>
                    <tr>
                        <th>فنی‌کار شاخص</th>
                        <td><label><input type="checkbox" name="is_featured" value="1" <?php checked($edit_tech ? $edit_tech->is_featured : 0, 1); ?>> این فنی‌کار شاخص (ویژه منطقه) باشد.</label></td>
                    </tr>
                </table>
                <p>
                    <input type="submit" name="action_save_tech" class="button button-primary" value="<?php echo $edit_tech ? 'ویرایش اطلاعات' : 'ذخیره فنی‌کار'; ?>">
                    <?php if ($edit_tech): ?>
                        <a href="<?php echo admin_url('admin.php?page=fls-technicians'); ?>" class="button button-secondary">انصراف</a>
                    <?php endif; ?>
                </p>
            </form>

            <div style="background:#fff; padding:15px; border:1px solid #ccc; margin-bottom:20px;">
                <h3>خروجی / ورودی اطلاعات فنی‌کارها (JSON)</h3>
                <a href="<?php echo admin_url('admin.php?page=fls-technicians&export_techs=1'); ?>" class="button button-secondary">دانلود خروجی JSON فنی‌کارها</a>
                <form method="post" enctype="multipart/form-data" style="display:inline-block; margin-right:20px;">
                    <?php wp_nonce_field('fls_import_nonce'); ?>
                    <input type="file" name="import_json" required>
                    <input type="submit" name="import_techs_json" class="button button-secondary" value="بارگذاری ورودی JSON">
                </form>
            </div>

            <h2>لیست فنی‌کارها</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>نام</th>
                        <th>تلفن</th>
                        <th>محل استقرار</th>
                        <th>شاخص؟</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($records as $r): ?>
                    <tr>
                        <td><?php echo $r->id; ?></td>
                        <td><?php echo esc_html($r->name); ?></td>
                        <td><?php echo esc_html($r->phone); ?></td>
                        <td><?php echo esc_html($r->location_name); ?></td>
                        <td><?php echo $r->is_featured ? 'بله' : 'خیر'; ?></td>
                        <td>
                            <a href="<?php echo admin_url('admin.php?page=fls-technicians&action=edit&id=' . $r->id); ?>" class="button button-small">ویرایش</a>
                            <a href="<?php echo admin_url('admin.php?page=fls-technicians&action=delete&id=' . $r->id); ?>" onclick="return confirm('آیا از حذف مطمئن هستید؟')" class="button button-small button-link-delete">حذف</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <script>
        jQuery(document).ready(function($){
            $('.fls-upload-btn').click(function(e) {
                e.preventDefault();
                var targetInput = $($(this).data('target'));
                var mediaUploader = wp.media({
                    title: 'انتخاب تصویر',
                    button: { text: 'استفاده از این تصویر' },
                    multiple: false
                }).on('select', function() {
                    var attachment = mediaUploader.state().get('selection').first().toJSON();
                    targetInput.val(attachment.url);
                }).open();
            });
        });
        </script>
        <?php
    }

    public function handle_json_import_export() {
        global $wpdb;

        if (isset($_GET['export_maps']) && current_user_can('manage_options')) {
            $data = $wpdb->get_results("SELECT * FROM " . $wpdb->prefix . "fls_default_maps", ARRAY_A);
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="default_maps.json"');
            echo json_encode($data);
            exit;
        }

        if (isset($_GET['export_techs']) && current_user_can('manage_options')) {
            $data = $wpdb->get_results("SELECT * FROM " . $wpdb->prefix . "fls_technicians", ARRAY_A);
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="technicians.json"');
            echo json_encode($data);
            exit;
        }

        if (isset($_POST['import_maps_json']) && check_admin_referer('fls_import_nonce')) {
            if (!empty($_FILES['import_json']['tmp_name'])) {
                $content = file_get_contents($_FILES['import_json']['tmp_name']);
                $rows = json_decode($content, true);
                if (is_array($rows)) {
                    $table = $wpdb->prefix . 'fls_default_maps';
                    foreach ($rows as $row) {
                        unset($row['id']);
                        $wpdb->insert($table, $row);
                    }
                }
            }
        }

        if (isset($_POST['import_techs_json']) && check_admin_referer('fls_import_nonce')) {
            if (!empty($_FILES['import_json']['tmp_name'])) {
                $content = file_get_contents($_FILES['import_json']['tmp_name']);
                $rows = json_decode($content, true);
                if (is_array($rows)) {
                    $table = $wpdb->prefix . 'fls_technicians';
                    foreach ($rows as $row) {
                        unset($row['id']);
                        $wpdb->insert($table, $row);
                    }
                }
            }
        }
    }

    /* --- رندر شورتکد فرانت‌اند --- */
    public function render_shortcode() {
        global $wpdb, $post;

        wp_enqueue_style('fls-style');
        wp_enqueue_style('leaflet-css');
        wp_enqueue_script('leaflet-js');

        $current_post_id = isset($post->ID) ? $post->ID : 0;
        $map_table = $wpdb->prefix . 'fls_default_maps';
        $tech_table = $wpdb->prefix . 'fls_technicians';

        $map_data = $wpdb->get_row($wpdb->prepare("SELECT * FROM $map_table WHERE post_id = %d", $current_post_id));
        $default_lat = $map_data ? $map_data->lat : '35.6892';
        $default_lng = $map_data ? $map_data->lng : '51.3890';

        $technicians = $wpdb->get_results("SELECT * FROM $tech_table", ARRAY_A);

        foreach ($technicians as &$technician) {
            $technician['activity_start_year'] = $this->activity_start_year($technician['experience']);
            $technician['satisfaction_count'] = max(0, (int) ($technician['satisfaction_count'] ?? 0));
        }
        unset($technician);

        // تولید کارت‌های اولیه در سمت سرور برای نمایش بدون وابستگی به JavaScript
        $ranked_technicians = array();
        $user_lat = (float) $default_lat;
        $user_lng = (float) $default_lng;
        $earth_radius = 6371;

        foreach ($technicians as $technician) {
            $lat_delta = ((float) $technician['lat'] - $user_lat) * M_PI / 180;
            $lng_delta = ((float) $technician['lng'] - $user_lng) * M_PI / 180;
            $a = sin($lat_delta / 2) * sin($lat_delta / 2)
                + cos($user_lat * M_PI / 180) * cos((float) $technician['lat'] * M_PI / 180)
                * sin($lng_delta / 2) * sin($lng_delta / 2);
            $distance = $earth_radius * 2 * atan2(sqrt($a), sqrt(1 - $a));

            $technician['_fls_distance'] = $distance;
            $technician['_fls_estimated_time'] = max(25, min(38, (int) round($distance * 3 + 18)));
            $ranked_technicians[] = $technician;
        }

        $featured_technicians = array_values(array_filter($ranked_technicians, function ($technician) {
            return (int) $technician['is_featured'] === 1;
        }));
        $normal_technicians = array_values(array_filter($ranked_technicians, function ($technician) {
            return (int) $technician['is_featured'] !== 1;
        }));

        $sort_by_distance = function ($first, $second) {
            return $first['_fls_distance'] <=> $second['_fls_distance'];
        };
        usort($featured_technicians, $sort_by_distance);
        usort($normal_technicians, $sort_by_distance);

        $initial_technicians = array();
        if (!empty($featured_technicians)) {
            $initial_technicians[] = $featured_technicians[0];
        }
        foreach ($normal_technicians as $technician) {
            if (count($initial_technicians) >= 4) {
                break;
            }
            $initial_technicians[] = $technician;
        }
        if (count($initial_technicians) < 4) {
            for ($index = 1; $index < count($featured_technicians); $index++) {
                if (count($initial_technicians) >= 4) {
                    break;
                }
                $initial_technicians[] = $featured_technicians[$index];
            }
        }

        $initial_technicians = $this->balance_arrival_times($initial_technicians);

        wp_enqueue_script('fls-script', FLS_URL . 'fls-style-script.js', array('jquery'), '1.2.5', true);
        wp_localize_script('fls-script', 'flsData', array(
            'defaultLat' => $default_lat,
            'defaultLng' => $default_lng,
            'mapKey'     => 'web.21cc611fd1d0494ab0b2a2d214a2e78d',
            'techs'      => $technicians
        ));

        ob_start();
        ?>
        <div id="fkc-live-status-wrap" class="fls-container">
            <!--
			<button id="fls-toggle-map-btn" class="fls-btn-toggle">کلیک کنید و موقعیت خود را روی نقشه مشخص کنید</button>
			-->
            <a id="fls-toggle-map-btn" href="#fls-toggle-map-btn" class="fls-btn-toggle"><svg class="location-icon" width="38" height="38" viewBox="0 0 24 24" fill="#f2392c" xmlns="http://www.w3.org/2000/svg"> <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5A2.5 2.5 0 1 1 12 6.5a2.5 2.5 0 0 1 0 5z"/> </svg> انتخاب موقعیت روی نقشه (کلیک کنید)</a>
            <div id="fls-map-wrapper" class="fls-map-hidden">
                <div id="fls-map"></div>
                <small class="fls-map-hint">مارکر روی نقشه را برای تعیین دقیق موقعیت مکان‌تان جابجا کنید.</small>
            </div>

            <div id="fls-techs-wrapper" class="fls-techs-container">
                <div id="fls-loading-overlay" class="fls-loading-hidden">
                    <div class="fls-spinner"></div>
					<p class="fls-txt-notification">در حال جستجوی نزدیکترین فنی کارها:<br><span class="fls-fanikar-result-count"></span> نفر پیدا شد</p>
                </div>
                <div id="fls-techs-list" class="fls-techs-grid">
                    <?php foreach ($initial_technicians as $technician):
                        $is_featured = (int) $technician['is_featured'] === 1;
                        $profile_image = !empty($technician['profile_img']) ? $technician['profile_img'] : 'https://via.placeholder.com/60';
                        $clearance_image = !empty($technician['clearance_img']) ? $technician['clearance_img'] : 'https://via.placeholder.com/60';
                        $location = $technician['location_name'];
                    ?>
                    <div class="fls-card <?php echo $is_featured ? 'fls-card-featured' : ''; ?>">
                        <?php if ($is_featured): ?><span class="fls-badge">ویژه منطقه</span><?php endif; ?>
                        <div class="fls-profile-header">
                            <div class="fls-profile-img-wrap">
                                <img src="<?php echo esc_url($profile_image); ?>" class="fls-profile-img fls-cert-trigger" data-img="<?php echo esc_url($profile_image); ?>" alt="<?php echo esc_attr($technician['name']); ?>">
                                <span class="fls-profile-loading"></span>
                            </div>
                            <div>
                                <h4 class="fls-tech-name"><?php echo esc_html($technician['name']); ?></h4>
                                <span style="font-size:12px; color:#777;">سابقه فعالیت: از سال <?php echo esc_html($technician['activity_start_year']); ?></span>
                            </div>
                        </div>
                        <div class="fls-info-item">📌 <strong>محل استقرار:</strong> <?php echo esc_html($location); ?></div>
                        <div class="fls-info-item">⏱ <strong>زمان رسیدن:</strong> حدود <?php echo esc_html($technician['_fls_estimated_time']); ?> دقیقه</div>
                        <div class="fls-info-item">⭐ <strong>رضایت مشتریان:</strong> <bdi><?php echo esc_html($technician['satisfaction']); ?>%</bdi> براساس <?php echo esc_html($technician['satisfaction_count']); ?> رضایت سنجی</div>
                        <button class="fls-btn-cert fls-cert-trigger" data-img="<?php echo esc_url($clearance_image); ?>">تصویر گواهی عدم سوء پیشینه</button>
                        <a href="<?php echo esc_attr('tel:' . $technician['phone']); ?>" class="fls-btn-call">تماس مستقیم 📞</a>
                    </div>
                    <?php endforeach; ?>
                    <!-- کارت‌ها با JS اضافه می‌شوند -->
                </div>
            </div>

            <!-- Lightbox Modal -->
            <div id="fls-modal" class="fls-modal-hidden">
                <span class="fls-modal-close">&times;</span>
                <div class="fls-modal-content">
                    <img id="fls-modal-img" src="" alt="گواهی عدم سوء پیشینه">
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}

FaniKara_Live_Status::get_instance();
