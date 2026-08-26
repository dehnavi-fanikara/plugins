<?php
/**
 * Plugin Name: Fanikara Pipe Opening Price Calculator
 * Description: قیمت‌گذاری لحظه‌ای خدمات لوله‌بازکنی با شورت‌کد وردپرس.
 * Version: 1.0.4
 * Author: Fanikara
 * Text Domain: fanikara-calc-pricing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FCP_VERSION', '1.0.4' );
define( 'FCP_FILE', __FILE__ );
define( 'FCP_DIR', plugin_dir_path( __FILE__ ) );
define( 'FCP_URL', plugin_dir_url( __FILE__ ) );

require_once FCP_DIR . 'includes/class-fcp-plugin.php';

FCP_Plugin::instance();
