<?php
    /**
     * Fanikara Suggested Fanikar Class
     *
     * Displays a random suggested fanikar based on meta fields.
 * - Fetches fanikar IDs from content meta (fnk_content_section_suggested_fanikar_id)
 * - Shuffles the array randomly
 * - Finds an available fanikar: work_status = false AND direct_call = true
 * - Scales to many suggested IDs via a single query
 * - Debug mode to display all data
     *
     * @package Fanikara
     * @version 1.0.0
     */

    if (!defined('ABSPATH')) {
        exit;
    }

    class Fanikara_Suggested_Fanikar {

        private static $instance = null;

        // Meta keys
        const META_SUGGESTED_IDS = 'fnk_content_section_suggested_fanikar_id';
        const META_WORK_STATUS = 'fnk_usr_cpt_work_status';
        const META_DIRECT_CALL = 'fnk_usr_cpt_switcher_direct_call';
        const META_PHONE_NUMBER = 'fnk_usr_cpt_direct_call_phone_number';
        const META_WORK_HOURS_START = 'fnk_usr_work_hours_start';
        const META_WORK_HOURS_END = 'fnk_usr_work_hours_start_end';

        // Option keys
        const OPTION_PATTERN_IMAGE = 'fu-base-profile-call-section-pattern';

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
            add_shortcode('fanikar_suggested_fanikar', [$this, 'render_suggested_fanikar']);
        }

        /**
         * Render the suggested fanikar shortcode
         * Usage: [fanikar_suggested_fanikar]
         * Debug: [fanikar_suggested_fanikar debug="true"]
         *
         * @param array $atts Shortcode attributes
         * @return string HTML output
         */
        public function render_suggested_fanikar($atts) {
            $atts = shortcode_atts([
                    'post_id' => get_the_ID(),
                    'debug' => 'false',
            ], $atts, 'fanikar_suggested_fanikar');

            $post_id = intval($atts['post_id']);
            $debug = filter_var($atts['debug'], FILTER_VALIDATE_BOOLEAN);

            // Debug: Start output buffer
            if ($debug) {
                echo '<div style="direction:rtl;background:#f0f8ff;padding:20px;border:3px solid #1854CC;border-radius:10px;margin:20px 0;font-family:Vazir, sans-serif;font-size:13px;line-height:1.8;">';
                echo '<h2 style="color:#1854CC;">ديباگ Suggested Fanikar</h2>';
                echo '<hr>';
            }

            // Step 1: Get suggested fanikar IDs from meta
            $suggested_ids_raw = get_post_meta($post_id, self::META_SUGGESTED_IDS, true);

            if ($debug) {
                echo '<h3>مرحله 1: دريافت آيدي هاي پيشنهادي</h3>';
                echo '<p><strong>پست ID:</strong> ' . $post_id . '</p>';
                echo '<p><strong>متاي خام:</strong> <pre>' . print_r($suggested_ids_raw, true) . '</pre></p>';
            }

            if (empty($suggested_ids_raw)) {
                if ($debug) {
                    echo '<p style="color:red;">هيچ آيدي پيشنهادي يافت نشد!</p>';
                    echo '</div>';
                }
                return '<!-- Fanikar: No suggested fanikar found -->';
            }

            // Ensure it's an array (handle serialized data)
            if (is_serialized($suggested_ids_raw)) {
                $suggested_ids = maybe_unserialize($suggested_ids_raw);
            } else {
                $suggested_ids = $suggested_ids_raw;
            }

            // Ensure it's an array
            if (!is_array($suggested_ids)) {
                $suggested_ids = [$suggested_ids];
            }

            // Filter out empty values and convert to integers
            $suggested_ids = array_filter(array_map('intval', $suggested_ids));

            if ($debug) {
                echo '<p><strong>آيدي هاي پس از پردازش:</strong> <pre>' . print_r($suggested_ids, true) . '</pre></p>';
            }

            if (empty($suggested_ids)) {
                if ($debug) {
                    echo '<p style="color:red;">هيچ آيدي معتبري يافت نشد!</p>';
                    echo '</div>';
                }
                return '<!-- Fanikar: No valid fanikar IDs -->';
            }

            // Step 2: Shuffle the array for randomness
            shuffle($suggested_ids);

            if ($debug) {
                echo '<h3>مرحله 2: شافل کردن آرايه</h3>';
                echo '<p><strong>آيدي هاي شافل شده:</strong> <pre>' . print_r($suggested_ids, true) . '</pre></p>';
            }

            // Step 3: Select an AVAILABLE fanikar:
            //   - fnk_usr_cpt_switcher_direct_call = true  (direct call enabled)
            //   - fnk_usr_cpt_work_status          = false (currently free / not busy)
            //
            // A single get_posts() call (with post meta cache primed) is used so the
            // process scales to a large number of suggested IDs instead of running a
            // separate get_post_meta() query per ID.
            $candidates = get_posts([
                'post_type'              => 'fanikar',
                'post__in'               => $suggested_ids,
                'posts_per_page'         => -1,
                'orderby'                => 'post__in',
                'fields'                 => 'ids',
                'update_post_meta_cache' => true,
            ]);

            $found_ids = !empty($candidates) ? array_map('intval', $candidates) : [];

            $available_fanikars = [];
            $checked_fanikars   = [];

            foreach ($found_ids as $fanikar_id) {
                $work_status = get_post_meta($fanikar_id, self::META_WORK_STATUS, true);
                $direct_call = get_post_meta($fanikar_id, self::META_DIRECT_CALL, true);

                // Normalize so it works with 'true'/'false' strings, 1/0, or real booleans.
                $is_available = ($this->is_meta_false($work_status) && $this->is_meta_true($direct_call));

                $checked_fanikars[] = [
                        'id'          => $fanikar_id,
                        'work_status' => $work_status,
                        'direct_call' => $direct_call,
                        'available'   => $is_available,
                ];

                if ($is_available) {
                    $available_fanikars[] = $fanikar_id;
                }
            }

            if ($debug) {
                echo '<h3>مرحله 3: بررسي وضعيت فني کارها (شرط: direct_call=true و work_status=false)</h3>';
                echo '<table style="width:100%;border-collapse:collapse;font-size:12px;">';
                echo '<thead><tr style="background:#1854CC;color:#fff;">';
                echo '<th style="padding:8px;border:1px solid #ddd;text-align:right;">ID</th>';
                echo '<th style="padding:8px;border:1px solid #ddd;text-align:right;">work_status</th>';
                echo '<th style="padding:8px;border:1px solid #ddd;text-align:right;">direct_call</th>';
                echo '<th style="padding:8px;border:1px solid #ddd;text-align:right;">وضعيت</th>';
                echo '</tr></thead>';
                echo '<tbody>';

                foreach ($checked_fanikars as $item) {
                    $status = $item['available'] ? 'مناسب' : 'نامناسب';
                    $color = $item['available'] ? 'green' : 'red';
                    echo '<tr style="border-bottom:1px solid #eee;">';
                    echo '<td style="padding:8px;border:1px solid #ddd;">' . $item['id'] . '</td>';
                    echo '<td style="padding:8px;border:1px solid #ddd;">' . ($this->is_meta_false($item['work_status']) ? 'خالي' : 'مشغول') . '</td>';
                    echo '<td style="padding:8px;border:1px solid #ddd;">' . ($this->is_meta_true($item['direct_call']) ? 'فعال' : 'غيرفعال') . '</td>';
                    echo '<td style="padding:8px;border:1px solid #ddd;color:' . $color . ';font-weight:bold;">' . $status . '</td>';
                    echo '</tr>';
                }

                echo '</tbody></table>';
            }

            // Pick one randomly among the available fanikars
            $selected_fanikar_id = null;
            if (!empty($available_fanikars)) {
                $selected_fanikar_id = $available_fanikars[array_rand($available_fanikars)];
            }

            if ($debug && $selected_fanikar_id) {
                echo '<p style="color:green;">فني کار مناسب پيدا شد! ID: ' . $selected_fanikar_id . '</p>';
            }

            // If no suitable fanikar found
            if (!$selected_fanikar_id) {
                if ($debug) {
                    echo '<p style="color:red;">هيچ فني کار مناسبي (با direct_call=true و work_status=false) يافت نشد!</p>';
                    echo '</div>';
                }
                return '<!-- Fanikar: No available fanikar found -->';
            }

            // Step 4: Get fanikar data for display
            if ($debug) {
                echo '<h3>مرحله 4: دريافت اطلاعات فني کار انتخاب شده</h3>';
            }

            $fanikar_post = get_post($selected_fanikar_id);
            if (!$fanikar_post) {
                if ($debug) {
                    echo '<p style="color:red;">پست فني کار يافت نشد!</p>';
                    echo '</div>';
                }
                return '<!-- Fanikar: Fanikar post not found -->';
            }

            // Get all meta fields
            $fanikar_name = $fanikar_post->post_title;
            $fanikar_permalink = get_permalink($selected_fanikar_id);
            $phone_number = get_post_meta($selected_fanikar_id, self::META_PHONE_NUMBER, true);
            $work_hours_start = get_post_meta($selected_fanikar_id, self::META_WORK_HOURS_START, true);
            $work_hours_end = get_post_meta($selected_fanikar_id, self::META_WORK_HOURS_END, true);
            $work_status = get_post_meta($selected_fanikar_id, self::META_WORK_STATUS, true);
            $direct_call = get_post_meta($selected_fanikar_id, self::META_DIRECT_CALL, true);

            // Get profile image using existing shortcode
            $profile_image = do_shortcode('[fanikar_profile_image post_id="' . $selected_fanikar_id . '" link="false"]');

            // Get pattern image from options
            $pattern_image = $this->get_pattern_image();

            if ($debug) {
                echo '<h4>اطلاعات پايه</h4>';
                echo '<table style="width:100%;border-collapse:collapse;font-size:13px;">';
                echo '<tr><td style="padding:6px;border:1px solid #ddd;font-weight:bold;">ID</td><td style="padding:6px;border:1px solid #ddd;">' . $selected_fanikar_id . '</td></tr>';
                echo '<tr><td style="padding:6px;border:1px solid #ddd;font-weight:bold;">نام</td><td style="padding:6px;border:1px solid #ddd;">' . esc_html($fanikar_name) . '</td></tr>';
                echo '<tr><td style="padding:6px;border:1px solid #ddd;font-weight:bold;">لينک</td><td style="padding:6px;border:1px solid #ddd;"><a href="' . $fanikar_permalink . '" target="_blank">' . $fanikar_permalink . '</a></td></tr>';
                echo '<tr><td style="padding:6px;border:1px solid #ddd;font-weight:bold;">شماره تماس</td><td style="padding:6px;border:1px solid #ddd;">' . (empty($phone_number) ? 'خالي' : esc_html($phone_number)) . '</td></tr>';
                echo '<tr><td style="padding:6px;border:1px solid #ddd;font-weight:bold;">ساعت شروع</td><td style="padding:6px;border:1px solid #ddd;">' . (empty($work_hours_start) ? 'خالي' : esc_html($work_hours_start)) . '</td></tr>';
                echo '<tr><td style="padding:6px;border:1px solid #ddd;font-weight:bold;">ساعت پايان</td><td style="padding:6px;border:1px solid #ddd;">' . (empty($work_hours_end) ? 'خالي' : esc_html($work_hours_end)) . '</td></tr>';
                echo '<tr><td style="padding:6px;border:1px solid #ddd;font-weight:bold;">وضعيت کاري</td><td style="padding:6px;border:1px solid #ddd;">' . ($work_status === 'false' ? 'خالي' : 'مشغول') . '</td></tr>';
                echo '<tr><td style="padding:6px;border:1px solid #ddd;font-weight:bold;">تماس مستقيم</td><td style="padding:6px;border:1px solid #ddd;">' . ($direct_call === 'false' ? 'فعال' : 'غيرفعال') . '</td></tr>';
                echo '</table>';

                echo '<h4>تصاوير</h4>';
                echo '<p><strong>تصوير پروفايل:</strong></p>';
                echo '<div>' . $profile_image . '</div>';
                echo '<p><strong>تصوير پترن:</strong> <a href="' . esc_url($pattern_image) . '" target="_blank">' . esc_url($pattern_image) . '</a></p>';

                echo '<h4>داده هاي نهايي براي رندر</h4>';
                $final_data = [
                        'fanikar_id' => $selected_fanikar_id,
                        'name' => $fanikar_name,
                        'permalink' => $fanikar_permalink,
                        'phone_number' => $phone_number,
                        'work_hours_start' => $work_hours_start,
                        'work_hours_end' => $work_hours_end,
                        'profile_image' => $profile_image,
                        'pattern_image' => $pattern_image,
                ];
                echo '<pre>' . print_r($final_data, true) . '</pre>';

                echo '<hr>';
                echo '<p style="color:green;font-weight:bold;">فني کار انتخاب شده: ' . esc_html($fanikar_name) . ' (ID: ' . $selected_fanikar_id . ')</p>';
                echo '</div>';

                // Return debug info
                return ob_get_clean();
            }

            // Normal mode: Build the HTML output
            return $this->build_html($selected_fanikar_id);
        }

        /**
         * Build the HTML output for the suggested fanikar
         *
         * @param int $fanikar_id Fanikar post ID
         * @return string HTML output
         */
        /**
         * Build the HTML output for the suggested fanikar
         *
         * @param int $fanikar_id Fanikar post ID
         * @return string HTML output
         */
        private function build_html($fanikar_id) {
            // Get fanikar data
            $fanikar_post = get_post($fanikar_id);
            if (!$fanikar_post) {
                return '<!-- Fanikar: Fanikar post not found -->';
            }

            $fanikar_name = $fanikar_post->post_title;
            $fanikar_permalink = get_permalink($fanikar_id);

            // Get meta fields
            $phone_number = get_post_meta($fanikar_id, self::META_PHONE_NUMBER, true);
            $work_hours_start = get_post_meta($fanikar_id, self::META_WORK_HOURS_START, true);
            $work_hours_end = get_post_meta($fanikar_id, self::META_WORK_HOURS_END, true);

            // ==========================================
            // Build permalink with query arguments
            // Only city_id, city_name, service_id, service_name
            // ==========================================
            $query_args = array();

            // Get city_id from meta
            $city_id = do_shortcode('[fanikar_query_arg_generator return="city_id" post_id="' . $fanikar_id . '"]');
            if (!empty($city_id)) {
                $query_args['city_id'] = $city_id;
            }

            // Get city_name from meta
            $city_name = do_shortcode('[fanikar_query_arg_generator return="city_name" post_id="' . $fanikar_id . '"]');
            if (!empty($city_name)) {
                $query_args['city_name'] = $city_name;
            }

            // Get service_id from meta
            $service_id = do_shortcode('[fanikar_query_arg_generator return="service_id" post_id="' . $fanikar_id . '"]');
            if (!empty($service_id)) {
                $query_args['service_id'] = $service_id;
            }

            // Get service_name from meta
            $service_name = do_shortcode('[fanikar_query_arg_generator return="service_name" post_id="' . $fanikar_id . '"]');
            if (!empty($service_name)) {
                $query_args['service_name'] = $service_name;
            }

            // Build permalink with query arguments
            if (!empty($query_args)) {
                $fanikar_permalink = add_query_arg($query_args, $fanikar_permalink);
            }

            // ==========================================
            // Get profile image with link and query arguments
            // ==========================================
            // Use profile image shortcode with add_query_args="true"
            $profile_image = do_shortcode('[fanikar_profile_image post_id="' . $fanikar_id . '" link="true" add_query_args="true"]');

            // Get pattern image from options
            $pattern_image = $this->get_pattern_image();

            // Build phone link
            $phone_link = !empty($phone_number) ? 'tel:' . $phone_number : '#';

            // Format work hours
            $work_hours_display = $this->format_work_hours($work_hours_start, $work_hours_end);

            // Build HTML with prefixed classes
            ob_start();
            ?>
            <div class="fnk_suggested_all">
                <div class="fnk_suggested_content" style="background-image: url('<?php echo esc_url($pattern_image); ?>');">
                    <a href="<?php echo esc_url($fanikar_permalink); ?>" class="fnk_suggested_name">
                        <?php echo esc_html($fanikar_name); ?>
                    </a>

                    <div class="fnk_suggested_hours">
                        <?php if ($work_hours_display === '24 ساعته') : ?>
                            <span>ساعت کاری</span>
                            <span>۲۴ ساعته</span>
                        <?php else : ?>
                            <span>ساعت کاری</span>
                            <span><?php echo esc_html($work_hours_display); ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($phone_number)) : ?>
                        <a href="<?php echo esc_url($phone_link); ?>" class="fnk_suggested_call_btn">
                            <img src="https://fanikara.ir/wp-content/uploads/2026/07/Vector.svg" class="fnk_suggested_phone_icon" alt="Phone Icon">
                            <span><?php echo esc_html($phone_number); ?></span>
                        </a>
                    <?php endif; ?>
                </div>

                <div class="fnk_suggested_image_wrapper">
                    <?php echo $profile_image; ?>
                </div>
            </div>
            <?php
            return ob_get_clean();
        }

        /**
         * Get pattern image from JetEngine options
         *
         * @return string Pattern image URL
         */
        private function get_pattern_image() {
            $pattern_image = '';

            // Method 1: JetEngine native method
            if (function_exists('jet_engine')) {
                $option = jet_engine()->listings->data->get_option('fanikara-options::' . self::OPTION_PATTERN_IMAGE);
                if (!empty($option)) {
                    $pattern_image = $option;
                }
            }

            // Method 2: Direct option
            if (empty($pattern_image)) {
                $pattern_image = get_option(self::OPTION_PATTERN_IMAGE, '');
            }

            // Method 3: Fallback from fanikara-options array
            if (empty($pattern_image)) {
                $all_options = get_option('fanikara-options', []);
                if (is_array($all_options) && isset($all_options[self::OPTION_PATTERN_IMAGE])) {
                    $pattern_image = $all_options[self::OPTION_PATTERN_IMAGE];
                }
            }

            // Fallback to a default pattern if nothing found
            if (empty($pattern_image)) {
                $pattern_image = 'https://fanikara.ir/wp-content/uploads/2026/06/P3-ok.jpg';
            }

            return $pattern_image;
        }

        /**
         * Format work hours for display
         * Rules:
         * - If both start and end are empty or "00:00" -> "24 ساعته"
         * - If only one of them is set -> "24 ساعته"
         * - If both are set -> "05:00 صبح الي 16:00 شب"
         *
         * @param string $start Start time (e.g., "08:00")
         * @param string $end End time (e.g., "22:00")
         * @return string Formatted work hours
         */
        private function format_work_hours($start, $end) {
            // Check if start and end are valid
            $has_start = !empty($start) && $start !== '00:00';
            $has_end = !empty($end) && $end !== '00:00';

            // If both are empty or only one exists, show "24 ساعته"
            if (!$has_start || !$has_end) {
                return '24 ساعته';
            }

            // Format: "05:00 صبح الي 16:00 شب"
            $start_time = $this->format_time_persian($start);
            $end_time = $this->format_time_persian($end);

            return $start_time . ' صبح الي ' . $end_time . ' شب';
        }

        /**
         * Convert time to Persian numbers
         *
         * @param string $time Time in 24h format (e.g., "08:00")
         * @return string Formatted time with Persian numbers
         */
        private function format_time_persian($time) {
            if (empty($time) || $time === '00:00') {
                return '۰۰:۰۰';
            }

            $persian_numbers = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
            $english_numbers = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

            if (preg_match('/^([0-9]{1,2}):([0-9]{2})$/', $time, $matches)) {
                $hours = intval($matches[1]);
                $minutes = $matches[2];

                $hours_persian = str_replace($english_numbers, $persian_numbers, str_pad($hours, 2, '0', STR_PAD_LEFT));
                $minutes_persian = str_replace($english_numbers, $persian_numbers, $minutes);

                return $hours_persian . ':' . $minutes_persian;
            }

            return $time;
        }

        /**
         * Normalize a stored meta value to a boolean (true).
         *
         * Works with strings ('true'/'1'/'yes'/'on'), numbers and real booleans.
         *
         * @param mixed $value Raw meta value
         * @return bool
         */
        private function is_meta_true($value) {
            if (is_bool($value)) {
                return $value;
            }
            if (is_numeric($value)) {
                return (int) $value !== 0;
            }
            $v = strtolower(trim((string) $value));
            return in_array($v, array('true', '1', 'yes', 'on'), true);
        }

        /**
         * Normalize a stored meta value to a boolean (false).
         *
         * @param mixed $value Raw meta value
         * @return bool
         */
        private function is_meta_false($value) {
            if (is_bool($value)) {
                return !$value;
            }
            if (is_numeric($value)) {
                return (int) $value === 0;
            }
            $v = strtolower(trim((string) $value));
            return in_array($v, array('false', '0', '', 'no', 'off'), true);
        }
    }