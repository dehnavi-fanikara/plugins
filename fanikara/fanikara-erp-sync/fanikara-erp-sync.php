<?php
/**
 * Plugin Name: Fanikara ERP Sync
 * Description: Synchronizes Fanikar technical-person posts with Fanikara ERP cities and services.
 * Version: 1.0.7
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/includes/class-fanikara-erp-sync.php';

Fanikara_ERP_Sync::init();
register_activation_hook(__FILE__, ['Fanikara_ERP_Sync', 'activate']);
register_deactivation_hook(__FILE__, ['Fanikara_ERP_Sync', 'deactivate']);
