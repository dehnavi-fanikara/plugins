<?php
/**
 * Fanikara Core Class
 *
 * Main class that manages all core functionality.
 *
 * @package Fanikara
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Fanikara_Core {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        // Load all modules on init
        add_action('init', [$this, 'load_modules'], 10);

        // Admin only hooks
        if (is_admin()) {
            add_action('admin_init', [$this, 'admin_init']);
        }
    }

    public function load_modules() {

        // Load Debugger Module
        if (file_exists(FK_CORE_DIR . 'class-debugger.php')) {
            require_once FK_CORE_DIR . 'class-debugger.php';
            Fanikara_Debugger::get_instance();
        }

        // Load Permalinks Module
        if (file_exists(FK_CORE_DIR . 'class-permalinks.php')) {
            require_once FK_CORE_DIR . 'class-permalinks.php';
            Fanikara_Permalinks::get_instance();
        }

        // Load Meta Manager
        if (file_exists(FK_CORE_DIR . 'class-meta-manager.php')) {
            require_once FK_CORE_DIR . 'class-meta-manager.php';
            Fanikara_Meta_Manager::get_instance();
        }

        // Load Query Arg Generator
        if (file_exists(FK_CORE_DIR . 'class-query-arg-generator.php')) {
            require_once FK_CORE_DIR . 'class-query-arg-generator.php';
            Fanikara_Query_Arg_Generator::get_instance();
        }

        // Load Profile Image
        if (file_exists(FK_CORE_DIR . 'class-profile-image.php')) {
            require_once FK_CORE_DIR . 'class-profile-image.php';
            Fanikara_Profile_Image::get_instance();
        }

        // Load JetEngine Forms
        if (file_exists(FK_CORE_DIR . 'class-jet-forms.php')) {
            require_once FK_CORE_DIR . 'class-jet-forms.php';
            Fanikara_Jet_Forms::get_instance();
        }

        // Load Priority System
        if (file_exists(FK_CORE_DIR . 'class-priority-system.php')) {
            require_once FK_CORE_DIR . 'class-priority-system.php';
            Fanikara_Priority_System::get_instance();
        }

        // Load Suggested Fanikar
        if (file_exists(FK_CORE_DIR . 'class-suggested-fanikar.php')) {
            require_once FK_CORE_DIR . 'class-suggested-fanikar.php';
            Fanikara_Suggested_Fanikar::get_instance();
        }

        // Load Money Pages Queries & Filters
        if (file_exists(FK_CORE_DIR . 'class-jet-queries-and-filters.php')) {
           require_once FK_CORE_DIR . 'class-jet-queries-and-filters.php';
            Fanikara_Jet_Queries_And_Filters::get_instance();
        }

    } // endofloadmodules

    public function admin_init() {
        // Add admin notices or additional admin hooks here
    }

} //endofclass