<?php
/**
 * Plugin Name: Call number in content
 * Plugin URI: https://fanikara.com
 * Description: قرار گرفتن شماره در محتوا با استفاده از شورتکد های [tehran-tel] & [karaj-tel]
 * Version: 1.0
 * Author: Fanikara (FKC) Sajjad Rajabi
 * Author URI: https://fanikara.com
 * License: GPL2
 */

// جلوگیری از دسترسی مستقیم به فایل
if (!defined('ABSPATH')) {
    exit;
}

// ثبت شورت‌کد [tehran-tel]
function shortcode_tehran_tel() {
    $number = '09193349060'; //شماره علی عباسی - لوله بازکن تهران
    return '<a href="tel:' . esc_attr($number) . '">' . esc_html($number) . '</a>';
}
add_shortcode('tehran-tel', 'shortcode_tehran_tel');

// ثبت شورت‌کد [karaj-tel]
function shortcode_karaj_tel() {
    $number = '09108912990'; // شماره خسرو ناصری - لوله بازکن کرج
    return '<a href="tel:' . esc_attr($number) . '">' . esc_html($number) . '</a>';
}
add_shortcode('karaj-tel', 'shortcode_karaj_tel');

// هوک برای uninstall پلاگین
function shortcode_number_uninstall() {
    // الان هیچ داده‌ای نداریم، اما اگر گزینه‌ای اضافه شد، اینجا پاک کن
    // مثلاً: delete_option('some_option');
}
register_uninstall_hook(__FILE__, 'shortcode_number_uninstall');