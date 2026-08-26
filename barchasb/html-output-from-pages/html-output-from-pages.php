<?php
/*
Plugin Name: HTML output from pages
Plugin URI:  https://fanikara.com
Description: برای گرفتن خروجی html از محتوای هر صفحه از سایت از این افزونه استفاده کنین؛ نحوه کار با افزونه: در ادمین بار روی HTML خروجی صفحات کلیک کنین، در پاپ آپ ایجاد شده، path صفحه ای موردنظر رو در فیلد مربوطه وارد کنید و دکمه خروجی HTML رو بزنین. در کادر پایین محتوای صفحه قابل مشاهده است، با کلیک روی دکمه «کپی خروجی» می تونین خروجی رو کپی کنین
Version:     1.0.5
Author:      FKC - Sajjad Rajabi
Text Domain: html-output-from-pages
*/

if (!defined('ABSPATH')) {
    exit;
}

define('HOFP_VERSION', '1.0.5');
define('HOFP_URL', plugin_dir_url(__FILE__));

// آرایه شورت‌کدهایی که نباید اجرا شوند (به صورت خام بمانند)
$hofp_blocked_shortcodes = array(
    'quick-call',
    'fanikar-list',
    // اینجا شورت‌کدهای دیگری که می‌خواهی خام بمانند اضافه کن
);

// اضافه کردن آیتم به ادمین بار (هم پیشخوان و هم فرانت‌اند)
add_action('admin_bar_menu', 'hofp_add_admin_bar_menu', 999);

function hofp_add_admin_bar_menu($wp_admin_bar) {
    $args = array(
        'id'    => 'html-output-from-pages',
        'title' => 'HTML خروجی صفحات',
        'href'  => '#',
        'meta'  => array(
            'class'   => 'hofp-menu-item',
            'onclick' => 'return false;',
        )
    );
    $wp_admin_bar->add_node($args);
}

// لود اسکریپت و استایل در پیشخوان
add_action('admin_enqueue_scripts', 'hofp_enqueue_assets');

// لود اسکریپت و استایل در فرانت‌اند (برای کاربران لاگین)
add_action('wp_enqueue_scripts', 'hofp_enqueue_assets_frontend');

function hofp_enqueue_assets_frontend() {
    if (!is_user_logged_in() || !is_admin_bar_showing()) {
        return;
    }
    hofp_enqueue_assets(); // استفاده از همان تابع برای جلوگیری از تکرار کد
}

function hofp_enqueue_assets() {
    $style_handle = is_admin() ? 'wp-admin' : 'admin-bar';

    $js = "
    jQuery(document).ready(function($) {

        function toggleHofpPanel() {
            if ($('#hofp-panel').length) {
                $('#hofp-panel').remove();
                return;
            }

            var panel = $('<div id=\"hofp-panel\" class=\"hofp-panel\">' +
                '<h3>استخراج محتوای اصلی صفحه</h3>' +
                '<label for=\"hofp-path\">مسیر صفحه (مثال: /tehran/pipeopening-tehransar/)</label>' +
                '<input type=\"text\" id=\"hofp-path\" dir=\"ltr\" style=\"direction:ltr; text-align:left; font-family:monospace;\" placeholder=\"/sample-page/\" />' +
                '<button id=\"hofp-extract-btn\" class=\"button button-primary\">خروجی HTML</button>' +
                '<div class=\"hofp-result-wrap\">' +
                    '<textarea id=\"hofp-result\" readonly placeholder=\"خروجی اینجا ظاهر می‌شود...\"></textarea>' +
                    '<button id=\"hofp-copy-btn\" class=\"button\">کپی خروجی</button>' +
                '</div>' +
                '<div id=\"hofp-message\"></div>' +
            '</div>').appendTo('body');

            panel.draggable();
        }

        $('#wp-admin-bar-html-output-from-pages .ab-item').on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            toggleHofpPanel();
        });

        // بستن با کلیک خارج از پنل
        $(document).on('click', function(e) {
            if ($('#hofp-panel').length &&
                !$(e.target).closest('#hofp-panel').length &&
                !$(e.target).closest('#wp-admin-bar-html-output-from-pages').length) {
                $('#hofp-panel').remove();
            }
        });

        // بستن با Esc
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape' && $('#hofp-panel').length) {
                $('#hofp-panel').remove();
            }
        });

        // استخراج محتوا با AJAX
        $(document).on('click', '#hofp-extract-btn', function() {
            var path = $('#hofp-path').val().trim();
            if (!path) {
                $('#hofp-message').text('مسیر را وارد کنید').addClass('error');
                return;
            }

            $('#hofp-result').val('در حال بارگذاری...');
            $('#hofp-message').text('').removeClass('error success');

            $.ajax({
                url: '" . esc_js(admin_url('admin-ajax.php')) . "',
                type: 'POST',
                data: {
                    action: 'hofp_extract_content',
                    nonce: '" . wp_create_nonce('hofp_extract') . "',
                    path: path
                },
                success: function(res) {
                    if (res.success) {
                        $('#hofp-result').val(res.data);
                        $('#hofp-message').text('خروجی آماده شد').addClass('success');
                    } else {
                        $('#hofp-result').val('');
                        $('#hofp-message').text(res.data || 'خطایی رخ داد').addClass('error');
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    var msg = 'خطای ارتباط';
                    if (jqXHR.status === 403) msg += ' (403 - دسترسی غیرمجاز یا nonce نامعتبر)';
                    if (jqXHR.status === 500) msg += ' (500 - خطای سرور)';
                    $('#hofp-message').text(msg).addClass('error');
                    console.error('AJAX Error:', textStatus, errorThrown, jqXHR.responseText);
                }
            });
        });

        // کپی خروجی
        $(document).on('click', '#hofp-copy-btn', function() {
            var ta = $('#hofp-result')[0];
            ta.select();
            try {
                document.execCommand('copy');
                $('#hofp-message').text('کپی شد ✓').addClass('success');
            } catch (err) {
                $('#hofp-message').text('کپی نشد').addClass('error');
            }
        });
    });
    ";

    wp_add_inline_script('jquery', $js);

    $css = "
    #hofp-panel {
        position: fixed; top: 60px; right: 20px; width: 480px; background: #fff;
        border: 1px solid #c3c4c7; box-shadow: 0 3px 10px rgba(0,0,0,0.2);
        z-index: 100000; padding: 20px; border-radius: 6px;
    }
    .hofp-panel h3 { margin: 0 0 15px; font-size: 18px; }
    label { display: block; margin-bottom: 6px; font-weight: 600; }
    #hofp-path { width: 100%; padding: 8px; box-sizing: border-box; }
    #hofp-extract-btn { margin: 12px 0; }
    .hofp-result-wrap { margin-top: 15px; }
    #hofp-result {
        width: 100%; height: 220px; resize: vertical; font-family: monospace;
        font-size: 13px; padding: 8px; background: #f9f9f9;
    }
    #hofp-copy-btn { margin-top: 8px; }
    #hofp-message { margin-top: 10px; padding: 8px; border-radius: 4px; }
    #hofp-message.error { background: #f8d7da; color: #721c24; }
    #hofp-message.success { background: #d4edda; color: #155724; }
    ";
    wp_add_inline_style($style_handle, $css);
}

// هندل AJAX (بدون تغییر نسبت به نسخه قبلی شما)
add_action('wp_ajax_hofp_extract_content', 'hofp_extract_content_callback');

function hofp_extract_content_callback() {
    check_ajax_referer('hofp_extract', 'nonce');

    $path = isset($_POST['path']) ? trim($_POST['path']) : '';

    if (empty($path) || $path === '/') {
        wp_send_json_error('لطفاً یک مسیر معتبر وارد کنید.');
    }

    $post_id = url_to_postid(home_url($path));

    if (!$post_id || !is_numeric($post_id)) {
        wp_send_json_error('هیچ پستی با این مسیر پیدا نشد.');
    }

    $post_content = get_post_field('post_content', $post_id);

    global $hofp_blocked_shortcodes;
    foreach ($hofp_blocked_shortcodes as $shortcode) {
        remove_shortcode($shortcode);
    }

    $post_content = do_shortcode($post_content);

    $output = trim($post_content);

    wp_send_json_success($output);
}