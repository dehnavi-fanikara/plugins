<?php
/**
 * Plugin Name: Fanikara Order Sync
 * Description: Sends JetEngine customer service order CCT items to Fanikara ERP or its test mirror.
 * Version: 1.2.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Text Domain: fanikara-order-sync
 */

if (!defined('ABSPATH')) {
    exit;
}

define('FNK_ORDER_SYNC_VERSION', '1.2.0');
define('FNK_ORDER_SYNC_FILE', __FILE__);

require_once __DIR__ . '/includes/class-fanikara-order-sync.php';

Fanikara_Order_Sync::init();
register_activation_hook(__FILE__, array('Fanikara_Order_Sync', 'activate'));
register_deactivation_hook(__FILE__, array('Fanikara_Order_Sync', 'deactivate'));
