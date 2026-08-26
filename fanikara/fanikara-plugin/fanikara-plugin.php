<?php
/*
* Plugin Name: ویرایشات اختصاصی فنی‌کارا
* Description: لطفا بدون هماهنگی با تیم فنی طراحی سایت آژانس دیجیتال مارکتینگ حافظ، افزونه را غیرفعال نکنید.
* Version: 2.0.0
* Author: Sina Boromand @ Hafez Agency
* Author URI: https://hafez.agency/
* Text Domain: fanikara-dev
*/

if (!defined('ABSPATH')) {
exit;
}

/**
* ==========================================
* PLUGIN DEFINES
* ==========================================
*/
define("FK_DIR",    plugin_dir_path(__FILE__));
define("FK_URI",    plugin_dir_url(__FILE__));
define("FK_INCS",   FK_DIR . 'includes/');
define("FK_ASSETS", FK_URI . 'assets/');

/**
* ==========================================
* CORE & LEGACY PATHS
* ==========================================
*/
define("FK_CORE_DIR",    FK_INCS . 'core/');
define("FK_LEGACY_DIR",  FK_INCS . 'legacy/');
define("FK_HELPERS_DIR", FK_INCS . 'helpers/');
define("FK_SEARCH_DIR",  FK_INCS . 'search/');
define("FK_VERSION",     '2.0.0');

/**
* ==========================================
* INCLUDE ASSETS
* ==========================================
*/
add_action('wp_enqueue_scripts', 'fanikara_assets');
function fanikara_assets() {

    // Main styles and scripts
    wp_enqueue_style('fanikara-main-styles', FK_ASSETS . 'css/styles.css');
    wp_enqueue_script('fanikara-main-scripts', FK_ASSETS . 'js/scripts.js', ['jquery']);

    // Quick Scroll
    wp_enqueue_script('fanikara-qs-scripts', FK_ASSETS . 'js/quick-scroll.js', ['jquery']);

    // Search styles and scripts
    wp_enqueue_style('fanikara-search-styles', FK_ASSETS . 'css/search.css');
    wp_enqueue_script('fanikara-search-scripts', FK_ASSETS . 'js/search.js', ['jquery']);
    wp_localize_script('fanikara-search-scripts', 'fanikarHyperSearch', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('fanikar_hyper_search_nonce'),
        'min_chars' => 2,
        'debug' => current_user_can('manage_options') && isset($_GET['debug_ajax']),
        'strings' => array(
            'search_placeholder' => __('جستجو...', 'fanikara'),
            'no_results' => __('نتیجه‌ای یافت نشد', 'fanikara'),
            'loading' => __('در حال جستجو...', 'fanikara'),
            'select_city' => __('انتخاب شهر...', 'fanikara'),
            'select_service' => __('انتخاب خدمات...', 'fanikara'),
            'select_city_required' => __('لطفاً ابتدا یک شهر انتخاب کنید', 'fanikara'),
        )
    ));
}

/**
* ==========================================
* INCLUDE HELPERS
* ==========================================
*/
include_once FK_HELPERS_DIR . 'helpers.php';
include_once FK_HELPERS_DIR . 'helpers-shortcodes.php';

/**
* ==========================================
* INCLUDE SEARCH SYSTEM
* ==========================================
*/
include_once FK_SEARCH_DIR . 'search.php';

/**
* ==========================================
* INITIALIZE NEW CORE STRUCTURE
* ==========================================
* Load new structure for all users
*/
add_action('init', 'fanikara_core_init', 5);
function fanikara_core_init() {
// Load main class
if (file_exists(FK_CORE_DIR . 'class-core.php')) {
    require_once FK_CORE_DIR . 'class-core.php';
    Fanikara_Core::get_instance();
}
}
