<?php
/**
 * Plugin Name:       دکمه تماس استیکی سفارشی
 * Description:       این افزونه کاملا سفارشی برای ایجاد دکمه ی استیکی در پایین صفحه با متن، نام فنی کار و شماره تماس دلخواه است - ابتدا افزونه را فعال کنید، سپس در تنظیمات افزونه مشخص کنید: 1. متاباکس آن در چه تایپی از صفحات سایت فعال  باشد 2. سپس نام فنی کار و شماره تماس موردنظر را در جدول وارد کنین تا لیست فنی کارهای خودتان را داشته باشید 3. تنظیمات دلخواه خودتان را برای نمایش دکمه (رنگ، فاصله ها و...) تنظیم کنین -  حالا کافیه صفحه ی موردنظر را در حالت ویرایش باز کنین تا متا باکس دکمه استیکی را مشاهده کنین، تا زمانی که تیک فعالسازی دکمه ی استیکی را نزنید، دکمه ی استیکی در صفحه ی موردنظر شما نمایش داده نخواهد شد.
 * Version:           9.3.0
 * Author:            Sajjad Rajabi - FKC
 */

if (!defined('ABSPATH')) exit;

class Sticky_Contact_Ultimate {

    private $technicians = 'scu_technicians';
    private $settings    = 'scu_settings';

    public function __construct() {
        add_action('init', [$this, 'init']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('add_meta_boxes', [$this, 'add_meta_box']);
        add_action('save_post', [$this, 'save_meta']);
        add_action('wp_footer', [$this, 'inject_button'], 20);
        add_action('wp_head', [$this, 'inject_fixes']);
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
    }

    public function activate() {
        $defaults = [
            'post_types'                   => ['post', 'page'],
            'sidebar_margin'               => 1,
            'sticky_height'                => 100,
            'button_height'                => 50,
            'text_font_size'               => 16,
            'text_font'                    => 'vazirmatn',
            'text_font_weight'             => '500',
            'text_font_style'              => 'normal',
            'text_text_decoration'         => 'none',
            'button_font_size'             => 16,
            'button_font'                  => 'vazirmatn',
            'button_font_weight'           => '600',
            'button_font_style'            => 'normal',
            'button_text_decoration'       => 'none',
            'phone_letter_spacing'         => '1.8px',
            'default_text'                 => 'برای مشاوره رایگان تماس بگیرید',
            'bg_color'                     => '#ffffff',
            'button_color'                 => '#01891f',
            'button_text_color'            => '#ffffff',
            'show_mobile'                  => 'yes',
            'show_desktop'                 => 'yes',
        ];
        update_option($this->settings, $defaults);
    }

    public function deactivate() {
        // اختیاری
    }

    public function init() {
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
    }

    public function admin_menu() {
        add_menu_page('دکمه استیکی', 'دکمه استیکی', 'manage_options', 'sticky-contact-ultimate', [$this, 'settings_page'], 'dashicons-phone', 58);
    }

    public function settings_page() {
        $techs = get_option($this->technicians, []);
        $s     = get_option($this->settings, []);

        if (isset($_POST['save_techs']) && wp_verify_nonce($_POST['nonce_tech'], 'scu_tech')) {
            $new = [];
            foreach ($_POST['tech_name'] ?? [] as $i => $name) {
                $name  = sanitize_text_field($name);
                $phone = sanitize_text_field($_POST['tech_phone'][$i] ?? '');
                if ($name && $phone) $new[] = ['name' => $name, 'phone' => $phone];
            }
            update_option($this->technicians, $new);
            echo '<div class="updated"><p>فنی‌کارها ذخیره شدند.</p></div>';
        }

        if (isset($_POST['save_settings']) && wp_verify_nonce($_POST['nonce_settings'], 'scu_settings')) {
            $post_types = is_array($_POST['post_types'] ?? []) ? $_POST['post_types'] : [];
            update_option($this->settings, [
                'post_types'                   => $post_types,
                'sidebar_margin'               => max(0, intval($_POST['sidebar_margin'] ?? 1)),
                'sticky_height'                => max(50, min(300, intval($_POST['sticky_height'] ?? 100))),
                'button_height'                => max(30, min(100, intval($_POST['button_height'] ?? 50))),
                'text_font_size'               => max(10, min(50, intval($_POST['text_font_size'] ?? 16))),
                'text_font'                    => sanitize_text_field($_POST['text_font'] ?? 'vazirmatn'),
                'text_font_weight'             => ($_POST['text_font_weight'] ?? '') === 'bold' ? 'bold' : '500',
                'text_font_style'              => ($_POST['text_font_style'] ?? '') === 'italic' ? 'italic' : 'normal',
                'text_text_decoration'         => in_array($_POST['text_text_decoration'] ?? '', ['underline', 'line-through']) ? $_POST['text_text_decoration'] : 'none',
                'button_font_size'             => max(10, min(50, intval($_POST['button_font_size'] ?? 16))),
                'button_font'                  => sanitize_text_field($_POST['button_font'] ?? 'vazirmatn'),
                'button_font_weight'           => ($_POST['button_font_weight'] ?? '') === 'bold' ? 'bold' : '600',
                'button_font_style'            => ($_POST['button_font_style'] ?? '') === 'italic' ? 'italic' : 'normal',
                'button_text_decoration'       => in_array($_POST['button_text_decoration'] ?? '', ['underline', 'line-through']) ? $_POST['button_text_decoration'] : 'none',
                'phone_letter_spacing'         => sanitize_text_field($_POST['phone_letter_spacing'] ?? '1.8px'),
                'default_text'                 => sanitize_textarea_field($_POST['default_text'] ?? 'برای مشاوره رایگان تماس بگیرید'),
                'bg_color'                     => sanitize_hex_color($_POST['bg_color'] ?? '#ffffff'),
                'button_color'                 => sanitize_hex_color($_POST['button_color'] ?? '#01891f'),
                'button_text_color'            => sanitize_hex_color($_POST['button_text_color'] ?? '#ffffff'),
                'show_mobile'                  => isset($_POST['show_mobile']) ? 'yes' : 'no',
                'show_desktop'                 => isset($_POST['show_desktop']) ? 'yes' : 'no',
            ]);
            echo '<div class="updated"><p>همه تنظیمات ذخیره شد.</p></div>';
        }

        $s = get_option($this->settings, []);
        $fonts = [
            'vazirmatn' => 'وزیرمتن (Vazirmatn)',
            'tahoma'    => 'تحوما (Tahoma)',
            'arial'     => 'آریال (Arial)',
            'georgia'   => 'جورجیا (Georgia)',
            'times'     => 'تایمز (Times New Roman)',
        ];
        ?>
        <div class="wrap">
            <h1>تنظیمات دکمه استیکی (کامل)</h1>

            <h2>فنی‌کارها</h2>
            <form method="post">
                <?php wp_nonce_field('scu_tech', 'nonce_tech'); ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead><tr><th>نام</th><th>شماره</th><th>حذف</th></tr></thead>
                    <tbody id="techs">
                        <?php foreach ($techs as $t): ?>
                        <tr>
                            <td><input type="text" name="tech_name[]" value="<?php echo esc_attr($t['name']); ?>" required></td>
                            <td><input type="text" name="tech_phone[]" value="<?php echo esc_attr($t['phone']); ?>" required></td>
                            <td><button type="button" class="button remove">حذف</button></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="empty" style="display:none;">
                            <td><input type="text" name="tech_name[]" placeholder="علی محمدی"></td>
                            <td><input type="text" name="tech_phone[]" placeholder="09123456789"></td>
                            <td><button type="button" class="button remove">حذف</button></td>
                        </tr>
                    </tbody>
                </table>
                <p>
                    <button type="button" id="add-tech" class="button button-primary">+ افزودن</button>
                    <input type="submit" name="save_techs" class="button-primary" value="ذخیره فنی‌کارها">
                </p>
            </form>

            <hr>

            <h2>تنظیمات ظاهری و عملکرد</h2>
            <form method="post">
                <?php wp_nonce_field('scu_settings', 'nonce_settings'); ?>
                <table class="form-table">
                    <tr><th>نمایش در کدام نوع صفحه؟</th><td>
                        <?php foreach (get_post_types(['public' => true], 'objects') as $pt): if ($pt->name === 'attachment') continue; ?>
                            <label><input type="checkbox" name="post_types[]" value="<?php echo $pt->name; ?>" <?php checked(in_array($pt->name, $s['post_types'] ?? [])); ?>> <?php echo $pt->labels->name; ?></label><br>
                        <?php endforeach; ?>
                    </td></tr>

                    <tr><th>فاصله آخرین ویجت از پایین (سایدبار)</th><td><input type="number" name="sidebar_margin" value="<?php echo esc_attr($s['sidebar_margin'] ?? 1); ?>" min="0"> پیکسل (0 یا 1 برای حداقل فاصله)</td></tr>

                    <tr><th>ارتفاع کل استیکی (کادر کلی)</th><td><input type="number" name="sticky_height" value="<?php echo esc_attr($s['sticky_height'] ?? 100); ?>" min="50" max="300"> پیکسل</td></tr>
                    <tr><th>ارتفاع دکمه سبز</th><td><input type="number" name="button_height" value="<?php echo esc_attr($s['button_height'] ?? 50); ?>" min="30" max="100"> پیکسل</td></tr>

                    <tr><th>اندازه فونت متن بالای دکمه</th><td>
                        <input type="number" name="text_font_size" value="<?php echo esc_attr($s['text_font_size'] ?? 16); ?>" min="10" max="50"> پیکسل
                        <button type="button" class="button toggle-style" data-target="text-style-options">استایل متن</button>
                        <div id="text-style-options" style="display:none;margin-top:10px;">
                            <label><input type="checkbox" name="text_font_weight" value="bold" <?php checked($s['text_font_weight'] ?? '500', 'bold'); ?>> بولد</label><br>
                            <label><input type="checkbox" name="text_font_style" value="italic" <?php checked($s['text_font_style'] ?? 'normal', 'italic'); ?>> ایتالیک</label><br>
                            <label><input type="checkbox" name="text_text_decoration" value="underline" <?php checked($s['text_text_decoration'] ?? 'none', 'underline'); ?>> زیرخط</label><br>
                            <label><input type="checkbox" name="text_text_decoration" value="line-through" <?php checked($s['text_text_decoration'] ?? 'none', 'line-through'); ?>> خط روی متن</label>
                        </div>
                    </td></tr>

                    <tr><th>فونت متن بالای دکمه</th><td>
                        <button type="button" class="button toggle-style" data-target="text-font-options">انتخاب فونت</button>
                        <div id="text-font-options" style="display:none;margin-top:10px;">
                            <?php foreach ($fonts as $key => $label): ?>
                                <label><input type="radio" name="text_font" value="<?php echo $key; ?>" <?php checked($s['text_font'] ?? 'vazirmatn', $key); ?>> <?php echo $label; ?></label><br>
                            <?php endforeach; ?>
                        </div>
                    </td></tr>

                    <tr><th>اندازه فونت داخل دکمه سبز</th><td>
                        <input type="number" name="button_font_size" value="<?php echo esc_attr($s['button_font_size'] ?? 16); ?>" min="10" max="50"> پیکسل
                        <button type="button" class="button toggle-style" data-target="button-style-options">استایل متن</button>
                        <div id="button-style-options" style="display:none;margin-top:10px;">
                            <label><input type="checkbox" name="button_font_weight" value="bold" <?php checked($s['button_font_weight'] ?? '600', 'bold'); ?>> بولد</label><br>
                            <label><input type="checkbox" name="button_font_style" value="italic" <?php checked($s['button_font_style'] ?? 'normal', 'italic'); ?>> ایتالیک</label><br>
                            <label><input type="checkbox" name="button_text_decoration" value="underline" <?php checked($s['button_text_decoration'] ?? 'none', 'underline'); ?>> زیرخط</label><br>
                            <label><input type="checkbox" name="button_text_decoration" value="line-through" <?php checked($s['button_text_decoration'] ?? 'none', 'line-through'); ?>> خط روی متن</label>
                        </div>
                    </td></tr>

                    <tr><th>فونت داخل دکمه سبز</th><td>
                        <button type="button" class="button toggle-style" data-target="button-font-options">انتخاب فونت</button>
                        <div id="button-font-options" style="display:none;margin-top:10px;">
                            <?php foreach ($fonts as $key => $label): ?>
                                <label><input type="radio" name="button_font" value="<?php echo $key; ?>" <?php checked($s['button_font'] ?? 'vazirmatn', $key); ?>> <?php echo $label; ?></label><br>
                            <?php endforeach; ?>
                        </div>
                    </td></tr>

                    <tr><th>فاصله حروف شماره تلفن</th><td><input type="text" name="phone_letter_spacing" value="<?php echo esc_attr($s['phone_letter_spacing'] ?? '1.8px'); ?>"> (مثلاً 1.8px)</td></tr>

                    <tr><th>متن پیش‌فرض بالای دکمه</th><td><textarea name="default_text" rows="3" style="width:100%"><?php echo esc_textarea($s['default_text'] ?? 'برای مشاوره رایگان تماس بگیرید'); ?></textarea></td></tr>

                    <tr><th>رنگ پس‌زمینه کادر</th><td><input type="text" name="bg_color" value="<?php echo esc_attr($s['bg_color'] ?? '#ffffff'); ?>" class="color-picker"></td></tr>
                    <tr><th>رنگ دکمه سبز</th><td><input type="text" name="button_color" value="<?php echo esc_attr($s['button_color'] ?? '#01891f'); ?>" class="color-picker"></td></tr>
                    <tr><th>رنگ متن دکمه</th><td><input type="text" name="button_text_color" value="<?php echo esc_attr($s['button_text_color'] ?? '#ffffff'); ?>" class="color-picker"></td></tr>

                    <tr><th>نمایش در موبایل</th><td><label><input type="checkbox" name="show_mobile" <?php checked($s['show_mobile'] ?? 'yes', 'yes'); ?>> فعال</label></td></tr>
                    <tr><th>نمایش در دسکتاپ</th><td><label><input type="checkbox" name="show_desktop" <?php checked($s['show_desktop'] ?? 'yes', 'yes'); ?>> فعال</label></td></tr>
                </table>
                <p><input type="submit" name="save_settings" class="button-primary" value="ذخیره همه تنظیمات"></p>
            </form>
        </div>

        <script>
        jQuery(function($){
            $('.color-picker').wpColorPicker();
            $('#add-tech').on('click', function(){ $('#techs .empty').first().clone().show().appendTo('#techs'); });
            $(document).on('click', '.remove', function(){ $(this).closest('tr').remove(); });

            // آکاردئون برای استایل و فونت
            $('.toggle-style').on('click', function(e){
                e.preventDefault();
                var target = $(this).data('target');
                $('#' + target).slideToggle();
            });
        });
        </script>
        <?php
    }

    public function add_meta_box() {
        $types = get_option($this->settings, [])['post_types'] ?? ['post', 'page'];
        foreach ($types as $type) {
            add_meta_box('scu_box', 'دکمه استیکی', [$this, 'meta_box'], $type, 'side', 'high');
        }
    }

    public function meta_box($post) {
        $enabled = get_post_meta($post->ID, '_scu_enabled', true);
        $tech_i = get_post_meta($post->ID, '_scu_tech', true);
        $text = get_post_meta($post->ID, '_scu_text', true);
        $techs = get_option($this->technicians, []);
        $default = get_option($this->settings, [])['default_text'] ?? 'برای مشاوره رایگان تماس بگیرید';
        ?>
        <p><label><input type="checkbox" name="scu_enabled" <?php checked($enabled, 'yes'); ?> value="yes"> <strong>فعال کردن دکمه در این صفحه</strong></label></p>
        <p><strong>فنی‌کار:</strong><br>
            <select name="scu_tech" style="width:100%">
                <option value="">— انتخاب کنید —</option>
                <?php foreach ($techs as $i => $t): ?>
                    <option value="<?php echo $i; ?>" <?php selected($tech_i, $i); ?>><?php echo esc_html($t['name'].' - '.$t['phone']); ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <p><strong>متن بالای دکمه:</strong><br>
            <textarea name="scu_text" rows="3" style="width:100%"><?php echo esc_textarea($text); ?></textarea>
            <small>اگر خالی باشد: «<?php echo esc_html($default); ?>»</small>
        </p>
        <?php
    }

    public function save_meta($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;
        update_post_meta($post_id, '_scu_enabled', isset($_POST['scu_enabled']) ? 'yes' : 'no');
        update_post_meta($post_id, '_scu_tech', sanitize_text_field($_POST['scu_tech'] ?? ''));
        update_post_meta($post_id, '_scu_text', sanitize_textarea_field($_POST['scu_text'] ?? ''));
    }

    public function inject_fixes() {
        if (!is_singular() || get_post_meta(get_the_ID(), '_scu_enabled', true) !== 'yes') return;
        $s = get_option($this->settings, []);
        $m = intval($s['sidebar_margin'] ?? 1);
        echo "<style>
            body.has-sticky-contact .widget:last-of-type,
            body.has-sticky-contact .sidebar .widget:last-child,
            body.has-sticky-contact .sidebar-inner .widget:last-child,
            body.has-sticky-contact .widget_recent_entries,
            body.has-sticky-contact .widget_search { margin-bottom: {$m}px !important; }

            .elementor-button[href='#comments'],
            a.elementor-button[href='#comments'],
            .support-float, .floating-contact, .ask-question-sticky,
            .chat-bubble, .livechat-btn, .support-widget,
            .ask-question-float { z-index: 9990 !important; }

            .back-to-top, #back-to-top, .to-top, .scroll-up, .go-top, .mk-go-top, .scrollToTop,
            .ast-arrow-svg { z-index: 1001 !important; }
        </style>";
    }

    public function inject_button() {
        if (!is_singular()) return;
        $id = get_the_ID();
        if (get_post_meta($id, '_scu_enabled', true) !== 'yes') return;

        $i = get_post_meta($id, '_scu_tech', true);
        $text = get_post_meta($id, '_scu_text', true);
        $techs = get_option($this->technicians, []);
        $s = get_option($this->settings, []);

        if (!isset($techs[$i])) return;
        $t = $techs[$i];
        $text = $text ?: ($s['default_text'] ?? 'برای مشاوره رایگان تماس بگیرید');

        if (($s['show_mobile'] ?? 'yes') !== 'yes' && wp_is_mobile()) return;
        if (($s['show_desktop'] ?? 'yes') !== 'yes' && !wp_is_mobile()) return;

        $tel = 'tel:' . preg_replace('/[^0-9+]/', '', $t['phone']);

        $sticky_height = ($s['sticky_height'] ?? 100) . 'px';
        $button_height = ($s['button_height'] ?? 50) . 'px';

        $font_map = [
            'vazirmatn' => 'Vazirmatn, Tahoma, Arial, sans-serif',
            'tahoma' => 'Tahoma, Arial, sans-serif',
            'arial' => 'Arial, sans-serif',
            'georgia' => 'Georgia, serif',
            'times' => 'Times New Roman, serif',
        ];
        $text_font = $font_map[$s['text_font'] ?? 'vazirmatn'] ?? 'Vazirmatn, Tahoma, Arial, sans-serif';
        $button_font = $font_map[$s['button_font'] ?? 'vazirmatn'] ?? 'Vazirmatn, Tahoma, Arial, sans-serif';

        $text_style = 'font-size: ' . ($s['text_font_size'] ?? 16) . 'px !important; ';
        $text_style .= 'font-family: ' . $text_font . ' !important; ';
        $text_style .= 'font-weight: ' . ($s['text_font_weight'] ?? '500') . ' !important; ';
        $text_style .= 'font-style: ' . ($s['text_font_style'] ?? 'normal') . ' !important; ';
        $text_style .= 'text-decoration: ' . ($s['text_text_decoration'] ?? 'none') . ' !important; ';

        $button_style = 'font-size: ' . ($s['button_font_size'] ?? 16) . 'px !important; ';
        $button_style .= 'font-family: ' . $button_font . ' !important; ';
        $button_style .= 'font-weight: ' . ($s['button_font_weight'] ?? '600') . ' !important; ';
        $button_style .= 'font-style: ' . ($s['button_font_style'] ?? 'normal') . ' !important; ';
        $button_style .= 'text-decoration: ' . ($s['button_text_decoration'] ?? 'none') . ' !important; ';
        ?>
        <div class="custom-sticky-contact">
            <div class="sticky-text" style="<?php echo $text_style; ?>"><?php echo nl2br(esc_html($text)); ?></div>
            <a href="<?php echo esc_url($tel); ?>" class="sticky-green-box" style="<?php echo $button_style; ?>">
                <?php echo esc_html($t['name']); ?> - <span class="phone-number"><?php echo esc_html($t['phone']); ?></span>
            </a>
        </div>
        <script>document.body.classList.add('has-sticky-contact');</script>
        <style>
        @import url('https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700&display=swap');
        .custom-sticky-contact {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: <?php echo esc_attr($s['bg_color'] ?? '#fff'); ?> !important;
            box-shadow: 0 -3px 15px rgba(0,0,0,0.12) !important;
            z-index: 10000 !important;
            direction: rtl;
            text-align: center;
            height: <?php echo $sticky_height; ?> !important;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 14px 16px !important;
            box-sizing: border-box;
            border-top: 1px solid #eee !important;
            border-radius: 18px 18px 0 0 !important;
            margin: 0 !important;
            display: none;
        }
        .custom-sticky-contact.active { display: flex; }
        .sticky-green-box {
            display: flex;
            align-items: center;
            justify-content: center;
            background: <?php echo esc_attr($s['button_color'] ?? '#01891f'); ?> !important;
            color: <?php echo esc_attr($s['button_text_color'] ?? '#fff'); ?> !important;
            height: <?php echo $button_height; ?> !important;
            padding: 0 22px !important;
            border-radius: 12px;
            text-decoration: none !important;
            min-width: 260px;
            box-shadow: 0 3px 10px rgba(1,137,31,0.3);
            animation: pulse 3s infinite;
        }
        @keyframes pulse { 0%,100%{transform:scale(1)} 50%{transform:scale(1.03)} }
        .sticky-green-box:hover { animation:none; }
        .phone-number {
            display: inline-block !important;
            letter-spacing: <?php echo esc_attr($s['phone_letter_spacing'] ?? '1.8px'); ?> !important;
            font-weight: 700;
        }
        @media (max-width: 767px) {
            .custom-sticky-contact { padding: 14px !important; }
            .sticky-green-box { min-width: calc(100% - 20px); font-size: 20px; padding: 0 20px !important; }
            .sticky-text { font-size: 16.5px; }
        }
        @media (min-width: 768px) {
            .custom-sticky-contact { left: 1px !important; right: auto !important; bottom: 5px !important; max-width: 360px !important; padding: 12px 18px !important; border-radius: 16px !important; z-index: 10001 !important; }
            .sticky-text { font-size: 15px !important; margin-bottom: 8px !important; }
            .sticky-green-box { min-width: auto !important; padding: 0 20px !important; font-size: 19px !important; }
        }
        </style>
        <script>
        document.addEventListener('DOMContentLoaded', function(){
            document.querySelector('.custom-sticky-contact')?.classList.add('active');
        });
        </script>
        <?php
    }
}

new Sticky_Contact_Ultimate();

// پاک کردن کامل داده‌ها موقع حذف افزونه
register_uninstall_hook(__FILE__, 'sticky_contact_ultimate_uninstall');

function sticky_contact_ultimate_uninstall() {
    delete_option('scu_technicians');
    delete_option('scu_settings');
    delete_metadata('post', 0, '_scu_enabled', '', true);  // true برای پاک کردن همه
    delete_metadata('post', 0, '_scu_tech', '', true);
    delete_metadata('post', 0, '_scu_text', '', true);
}