<?php
/**
 * Plugin Name: همجواری مناطق در برچسب
 * Plugin URI: https://example.com/
 * Description: مدیریت روابط دستی بین مناطق و نمایش آن‌ها در یک Carousel واکنش‌گرا.
 * Version: 0.0.2
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: Barchasb
 * Text Domain: barchasb-area-adjacency
 * Domain Path: /languages
 *
 * @package Barchasb\AreaAdjacency
 */

declare(strict_types=1);

namespace Barchasb\AreaAdjacency;

defined('ABSPATH') || exit;

define('BAA_VERSION', '0.0.2');
define('BAA_FILE', __FILE__);
define('BAA_DIR', plugin_dir_path(__FILE__));
define('BAA_URL', plugin_dir_url(__FILE__));

require_once BAA_DIR . 'includes/class-defaults.php';
require_once BAA_DIR . 'includes/class-post-types.php';
require_once BAA_DIR . 'includes/class-settings.php';
require_once BAA_DIR . 'includes/class-metabox.php';
require_once BAA_DIR . 'includes/class-shortcode.php';

/**
 * Bootstrap the plugin.
 *
 * @return void
 */
function baa_bootstrap(): void {
	$settings = new Settings();
	$post_types = new Post_Types($settings);

	new Metabox($settings, $post_types);
	new Shortcode($settings, $post_types);

	add_action('plugins_loaded', static function (): void {
		load_plugin_textdomain('barchasb-area-adjacency', false, dirname(plugin_basename(BAA_FILE)) . '/languages');
	});
}

baa_bootstrap();

register_activation_hook(BAA_FILE, __NAMESPACE__ . '\\baa_activate');

/**
 * Add defaults on activation without overwriting existing settings.
 *
 * @return void
 */
function baa_activate(): void {
	if (false === get_option('baa_settings', false)) {
		add_option('baa_settings', Defaults::all());
	}
}
