<?php
/**
* Fanikara Rewrite Rules
*
* Remove prefix slug from "City" CPT
* Rewrite slug for contents base on parent related content site/{city}/{content}
*
* @package Fanikara
* @version 3.0
*/

if (!defined('ABSPATH')) {
exit;
}

class Fanikara_Rewrite_Rules
{
public function __construct()
{
    add_action('init', array($this, 'init_rewrite_rules'));
    add_filter('post_type_link', array($this, 'custom_post_permalink'), 10, 2);
    add_filter('query_vars', array($this, 'add_query_vars'));
    add_action('template_redirect', array($this, 'validate_content_url'));
    add_action('admin_notices', array($this, 'flush_rewrite_notice'));
}

private function get_city_slugs()
{
    global $wpdb;

    $city_slugs = wp_cache_get('fanikara_city_slugs', 'fanikara');

    if (false === $city_slugs) {
        $city_slugs = $wpdb->get_col(
                "SELECT post_name FROM {$wpdb->posts} 
         WHERE post_type = 'city' 
         AND post_status = 'publish'"
        );

        wp_cache_set('fanikara_city_slugs', $city_slugs, 'fanikara', HOUR_IN_SECONDS);
    }

    return $city_slugs;
}

private function get_city_pattern()
{
    $slugs = $this->get_city_slugs();

    if (empty($slugs)) {
        return '(tehran)'; // fallback
    }

    $slugs = array_map('preg_quote', $slugs);
    return '(' . implode('|', $slugs) . ')';
}

private function get_content_city($content_id)
{
    global $wpdb;

    $cache_key = "fanikara_content_city_{$content_id}";
    $city_id = wp_cache_get($cache_key, 'fanikara');

    if (false === $city_id) {
        $table = $wpdb->prefix . 'jet_rel_default';

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return false;
        }

        $city_id = $wpdb->get_var($wpdb->prepare(
                "SELECT parent_object_id FROM {$table} 
         WHERE rel_id = '11' AND child_object_id = %d 
         LIMIT 1",
                $content_id
        ));

        wp_cache_set($cache_key, $city_id, 'fanikara', HOUR_IN_SECONDS);
    }

    return $city_id ? (int)$city_id : false;
}

public function init_rewrite_rules()
{
    $city_pattern = $this->get_city_pattern();
    add_rewrite_rule(
            '^' . $city_pattern . '/?$',
            'index.php?post_type=city&name=$matches[1]',
            'top'
    );
    add_rewrite_rule(
            '^' . $city_pattern . '/([^/]+)/?$',
            'index.php?post_type=content&name=$matches[2]&city_name=$matches[1]',
            'top'
    );
    add_rewrite_rule(
            '^fanikar/([^/]+)/?$',
            'index.php?post_type=fanikar&name=$matches[1]',
            'top'
    );

    $this->check_flush_rewrite();
}

public function custom_post_permalink($url, $post)
{
    $post_type = get_post_type($post);

    switch ($post_type) {
        case 'city':
            $url = home_url('/' . $post->post_name . '/');
            break;

        case 'content':
            $city_id = $this->get_content_city($post->ID);
            if ($city_id) {
                $city_slug = get_post_field('post_name', $city_id);
                if (!empty($city_slug)) {
                    $url = home_url('/' . $city_slug . '/' . $post->post_name . '/');
                }
            }
            break;

        case 'fanikar':
            $url = home_url('/fanikar/' . $post->post_name . '/');
            break;
    }

    return $url;
}

public function add_query_vars($vars)
{
    $vars[] = 'city_name';
    return $vars;
}

public function validate_content_url()
{
    if (!is_singular('content')) {
        return;
    }

    global $wp_query;
    $url_city_slug = get_query_var('city_name');
    $current_post = get_queried_object();

    if (!$current_post || 'content' !== get_post_type($current_post)) {
        return;
    }

    $city_id = $this->get_content_city($current_post->ID);

    if (!$city_id) {
        $wp_query->set_404();
        status_header(404);
        return;
    }

    $actual_city_slug = get_post_field('post_name', $city_id);

    if (!empty($url_city_slug) && $url_city_slug !== $actual_city_slug) {
        $correct_url = home_url('/' . $actual_city_slug . '/' . $current_post->post_name . '/');
        wp_redirect($correct_url, 301);
        exit;
    }
}

/**
 * بررسی نیاز به فلش کردن rewrite rules
 */
private function check_flush_rewrite()
{
    $flushed = get_option('fanikara_rewrite_flushed', false);

    if (!$flushed) {
        flush_rewrite_rules();
        update_option('fanikara_rewrite_flushed', true);
    }
}

public function flush_rewrite_notice()
{
    if (isset($_GET['flush_fanikara_rewrite']) && current_user_can('manage_options')) {
        flush_rewrite_rules();
        update_option('fanikara_rewrite_flushed', time());
        echo '<div class="notice notice-success"><p>✅ قوانین بازنویسی فنی‌کارا با موفقیت به‌روزرسانی شد.</p></div>';
    }

    $last_flush = get_option('fanikara_rewrite_flushed', 0);
    $city_count = wp_count_posts('city')->publish;

    if ($city_count > 0 && (time() - $last_flush) > DAY_IN_SECONDS) {
        ?>
        <div class="notice notice-warning is-dismissible">
            <p>
                <strong>فنی‌کارا:</strong> برای اعمال تغییرات در آدرس‌ها،
                <a href="<?php echo admin_url('options-permalink.php'); ?>">روی اینجا کلیک کن</a>
                و فقط دکمه "ذخیره تنظیمات" را بزن.
                <br>
                <small>یا <a href="?flush_fanikara_rewrite=1">اینجا کلیک کن</a> تا به صورت خودکار انجام
                    شود.</small>
            </p>
        </div>
        <?php
    }
}
}

new Fanikara_Rewrite_Rules();

if (!function_exists('fanikara_get_content_city')) {
function fanikara_get_content_city($content_id)
{
    global $wpdb;

    $cache_key = "fanikara_content_city_{$content_id}";
    $city_id = wp_cache_get($cache_key, 'fanikara');

    if (false === $city_id) {
        $table = $wpdb->prefix . 'jet_rel_default';

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return false;
        }

        $city_id = $wpdb->get_var($wpdb->prepare(
                "SELECT parent_object_id FROM {$table} 
         WHERE rel_id = '11' AND child_object_id = %d 
         LIMIT 1",
                $content_id
        ));

        wp_cache_set($cache_key, $city_id, 'fanikara', HOUR_IN_SECONDS);
    }

    return $city_id ? (int)$city_id : false;
}
}

/**
 * Resave permalink on new post creation in our custom post types.
 */
function auto_flush_rewrite_for_custom_post_types($post_id) {


    if (wp_is_post_revision($post_id)) {
        return;
    }

    $post_type = get_post_type($post_id);

    $custom_post_types = array('city', 'content', 'fanikar');

    if (in_array($post_type, $custom_post_types)) {
        flush_rewrite_rules(false);
    }
}
add_action('save_post', 'auto_flush_rewrite_for_custom_post_types');

/**
 * Resave on any post deletation.
 */
function auto_flush_on_delete($post_id) {
    $post_type = get_post_type($post_id);
    $custom_post_types = array('city', 'content', 'fanikar');
    if (in_array($post_type, $custom_post_types)) {
        flush_rewrite_rules(false);
    }
}
add_action('before_delete_post', 'auto_flush_on_delete');