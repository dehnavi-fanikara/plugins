<?php
/**
 * Fanikara JetEngine Forms Class
 *
 * Manages hidden fields for JetEngine forms on single fanikar pages.
 * Extracts ERP IDs from term meta and injects them into form fields.
 *
 * @package Fanikara
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Fanikara_Jet_Forms {

    private static $instance = null;
    private $area_taxonomy = 'area';
    private $service_taxonomy = 'service';

    // Term meta keys for ERP IDs
    const META_AREA_ERP_ID = 'fnk_area_city_erp_id';
    const META_SERVICE_ERP_ID = 'fnk_service_erp_id';

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
        // Start the PHP session early (must run before any output) so
        // $_SESSION is available when injecting the hidden fields.
        add_action('init', [$this, 'start_session']);

        // Inject hidden fields into footer on single fanikar pages
        add_action('wp_footer', [$this, 'inject_hidden_fields'], 20);
    }

    /**
     * Start a PHP session if one isn't already active.
     */
    public function start_session() {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }
    }

    /**
     * Inject hidden fields into footer for single fanikar pages
     */
    public function inject_hidden_fields() {
        // Only run on single fanikar pages
        if (!is_singular('fanikar')) {
            return;
        }

        $post_id = get_the_ID();
        if (!$post_id) {
            return;
        }

        // Get service ERP ID from term meta via URL query argument
        $service_erp_id = $this->get_service_erp_id($post_id);

        // Get city ERP ID from term meta via URL query argument
        $city_erp_id = $this->get_city_erp_id($post_id);

        // Get user_input value from the PHP session (if set)
        $user_input = isset($_SESSION['user_input'])
            ? sanitize_text_field(wp_unslash($_SESSION['user_input']))
            : '';

        // ==========================================
        // Step 1: Output hidden fields with tmp_ prefix
        // ==========================================
        ?>
        <div id="fnk_jet_form_hidden_fields" style="display:none;">
            <input type="hidden" id="tmp_fnk_service_erp_id" value="<?php echo esc_attr($service_erp_id); ?>" />
            <input type="hidden" id="tmp_fnk_area_city_erp_id" value="<?php echo esc_attr($city_erp_id); ?>" />
            <?php if ('' !== $user_input) : ?>
            <input type="hidden" id="tmp_user_input" value="<?php echo esc_attr($user_input); ?>" />
            <?php endif; ?>
        </div>

        <script>
            (function() {
                'use strict';

                /**
                 * Copy values from tmp fields to JetEngine form hidden fields
                 */
                function copyHiddenFieldValues() {
                    // Get temporary field values
                    var tmpService = document.getElementById('tmp_fnk_service_erp_id');
                    var tmpCity = document.getElementById('tmp_fnk_area_city_erp_id');

                    if (!tmpService || !tmpCity) {
                        return;
                    }

                    var serviceValue = tmpService.value;
                    var cityValue = tmpCity.value;

                    // Find JetEngine form hidden fields by name attribute
                    var serviceField = document.querySelector('input[name="service_erp_id"]');
                    var cityField = document.querySelector('input[name="city_erp_id"]');

                    // Copy values if fields exist
                    if (serviceField && serviceValue) {
                        serviceField.value = serviceValue;
                    }

                    if (cityField && cityValue) {
                        cityField.value = cityValue;
                    }

                    // Copy the user_input value from the PHP session.
                    // If there is no session value with this name, the JetEngine
                    // field is left empty (nothing is added to its value).
                    var tmpUserInput = document.getElementById('tmp_user_input');
                    var userInputField = document.querySelector('input[name="user_input"]');
                    if (userInputField) {
                        var userInputValue = tmpUserInput ? tmpUserInput.value : '';
                        userInputField.value = userInputValue ? userInputValue : 'no_session_founded';
                    }
                }

                // Run after 3 seconds
                setTimeout(copyHiddenFieldValues, 3000);

                // Also run when JetEngine forms are loaded dynamically (AJAX)
                if (typeof jQuery !== 'undefined') {
                    jQuery(document).on('jet-engine-form/loaded', function() {
                        setTimeout(copyHiddenFieldValues, 500);
                    });
                }

            })();
        </script>
        <?php
    }

    /**
     * Get service ERP ID from URL query argument
     *
     * @param int $post_id Post ID (unused)
     * @return string ERP ID or empty string
     */
    private function get_service_erp_id($post_id) {
        // Get service_id from URL query arguments
        $service_id = isset($_GET['service_id']) ? intval($_GET['service_id']) : '';
        
        if (empty($service_id)) {
            return '';
        }

        $erp_id = get_term_meta($service_id, self::META_SERVICE_ERP_ID, true);
        return !empty($erp_id) ? $erp_id : '';
    }

    /**
     * Get city ERP ID from URL query argument
     *
     * @param int $post_id Post ID (unused)
     * @return string ERP ID or empty string
     */
    private function get_city_erp_id($post_id) {
        // Get city_id from URL query arguments
        $city_id = isset($_GET['city_id']) ? intval($_GET['city_id']) : '';
        
        if (empty($city_id)) {
            return '';
        }
        
        $erp_id = get_term_meta($city_id, self::META_AREA_ERP_ID, true);
        return !empty($erp_id) ? $erp_id : '';
    }
}