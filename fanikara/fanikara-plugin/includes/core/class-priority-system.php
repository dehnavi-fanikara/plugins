<?php
/**
 * Fanikara Priority System Class
 *
 * Manages priority scores for fanikar posts based on services.
 * - Adds meta box to fanikar post edit screen
 * - Displays repeater fields for each service term
 * - Saves scores as array and individual meta keys
 * - Adds custom column to admin posts list
 *
 * @package Fanikara
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Fanikara_Priority_System {

    private static $instance = null;
    private $service_taxonomy = 'service';

    // Meta keys
    const META_SCORES_ARRAY = 'fnk_dev_service_scores';
    const META_SCORE_PREFIX = 'fnk_dev_service_score_';

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
        // Add meta box to fanikar post type
        add_action('add_meta_boxes', [$this, 'add_priority_meta_box']);

        // Save priority scores
        add_action('save_post_fanikar', [$this, 'save_priority_scores'], 10, 3);

        // Enqueue admin assets
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        // Admin column in all fanikara post lists
        add_filter('manage_fanikar_posts_columns', [$this, 'add_service_column']);
        add_action('manage_fanikar_posts_custom_column', [$this, 'render_service_column'], 10, 2);
    }

    // ==========================================
    // ADMIN COLUMN METHODS
    // ==========================================

    /**
     * Add 'Services' column to fanikar admin list
     *
     * @param array $columns Existing columns
     * @return array Modified columns
     */
    public function add_service_column($columns) {
        // Insert before the last column (usually 'date')
        $new_columns = [];
        $total = count($columns);
        $position = $total - 1; // One before last

        $i = 0;
        foreach ($columns as $key => $value) {
            if ($i === $position) {
                $new_columns['fnk_services'] = __('خدمات', 'fanikara');
            }
            $new_columns[$key] = $value;
            $i++;
        }

        return $new_columns;
    }

    /**
     * Render 'Services' column content
     *
     * @param string $column_name Column name
     * @param int    $post_id     Post ID
     */
    public function render_service_column($column_name, $post_id) {
        if ($column_name !== 'fnk_services') {
            return;
        }

        // Get service terms attached to this fanikar
        $service_terms = wp_get_post_terms($post_id, $this->service_taxonomy, [
                'hide_empty' => false,
        ]);

        if (empty($service_terms) || is_wp_error($service_terms)) {
            echo '<span style="color:#999;font-style:italic;">—</span>';
            return;
        }

        // Get scores for this fanikar
        $scores = get_post_meta($post_id, self::META_SCORES_ARRAY, true);
        if (!is_array($scores)) {
            $scores = [];
        }

        // Build output: Service Name (Score)
        $output = [];
        foreach ($service_terms as $term) {
            $score = isset($scores[$term->term_id]) ? intval($scores[$term->term_id]) : 0;

            // Color coding based on score
            $color = '#666';
            if ($score >= 800) {
                $color = '#46b450'; // Green - High priority
            } elseif ($score >= 500) {
                $color = '#ffb900'; // Orange - Medium priority
            } elseif ($score >= 1) {
                $color = '#dc3232'; // Red - Low priority
            }

            $output[] = sprintf(
                    '<span style="display:inline-block;margin:2px 4px 2px 0;padding:2px 8px;background:#f5f5f5;border-radius:3px;font-size:12px;white-space:nowrap;">
                <strong>%s</strong>
                <span style="color:%s;font-weight:bold;margin-right:2px;">(%d)</span>
            </span>',
                    esc_html($term->name),
                    $color,
                    $score
            );
        }

        echo implode(' ', $output);
    }

    // ==========================================
    // META BOX METHODS (Existing Code)
    // ==========================================

    /**
     * Enqueue admin styles and scripts
     */
    public function enqueue_admin_assets($hook) {
        global $post;

        // Only load on fanikar post edit screen
        if ($hook !== 'post.php' || !isset($post) || $post->post_type !== 'fanikar') {
            return;
        }

        // Add inline styles
        wp_add_inline_style('wp-admin', '
    .fnk-priority-meta-box .fnk-service-row {
        display: flex;
        align-items: center;
        padding: 8px 12px;
        border-bottom: 1px solid #f0f0f0;
        gap: 16px;
    }
    .fnk-priority-meta-box .fnk-service-row:last-child {
        border-bottom: none;
    }
    .fnk-priority-meta-box .fnk-service-name {
        flex: 2;
        font-weight: 600;
        color: #1a1a2e;
    }
    .fnk-priority-meta-box .fnk-service-score {
        flex: 1;
        min-width: 120px;
    }
    .fnk-priority-meta-box .fnk-service-score input {
        width: 100%;
        padding: 6px 10px;
        border: 1px solid #ddd;
        border-radius: 4px;
        font-size: 14px;
        text-align: center;
    }
    .fnk-priority-meta-box .fnk-service-score input:focus {
        border-color: #1854CC;
        box-shadow: 0 0 0 2px rgba(24, 84, 204, 0.1);
        outline: none;
    }
    .fnk-priority-meta-box .fnk-service-score input::-webkit-inner-spin-button {
        opacity: 1;
    }
    .fnk-priority-meta-box .fnk-score-range {
        font-size: 11px;
        color: #999;
        margin-top: 2px;
        text-align: center;
    }
    .fnk-priority-meta-box .fnk-no-services {
        padding: 20px;
        text-align: center;
        color: #999;
        background: #f9f9f9;
        border-radius: 4px;
    }
    .fnk-priority-meta-box .fnk-no-services .dashicons {
        font-size: 32px;
        width: 32px;
        height: 32px;
        display: block;
        margin: 0 auto 8px;
        color: #ccc;
    }
    ');

        // Add column styles
        wp_add_inline_style('wp-admin', '
    .column-fnk_services {
        width: 300px;
        min-width: 200px;
    }
    .column-fnk_services .fnk-service-badge {
        display: inline-block;
        margin: 2px 4px 2px 0;
        padding: 2px 8px;
        background: #f5f5f5;
        border-radius: 3px;
        font-size: 12px;
        white-space: nowrap;
    }
    ');
    }

    /**
     * Add priority meta box to fanikar post type
     */
    public function add_priority_meta_box() {
        add_meta_box(
                'fnk_priority_scores',
                '⭐ امتیازات اولویت‌بندی فنی‌کار',
                [$this, 'render_priority_meta_box'],
                'fanikar',
                'normal',
                'high'
        );
    }

    /**
     * Render priority meta box
     */
    public function render_priority_meta_box($post) {
        // Add nonce for security
        wp_nonce_field('fnk_priority_scores_nonce', 'fnk_priority_scores_nonce');

        // Get service terms attached to this fanikar
        $service_terms = wp_get_post_terms($post->ID, $this->service_taxonomy, [
                'hide_empty' => false,
        ]);

        // Get existing scores
        $scores = get_post_meta($post->ID, self::META_SCORES_ARRAY, true);
        if (!is_array($scores)) {
            $scores = [];
        }

        ?>
        <div class="fnk-priority-meta-box">
            <p style="color:#666;margin-bottom:16px;font-size:13px;">
                برای هر خدمتی که این فنی‌کار ارائه می‌دهد، یک امتیاز (۰ تا ۱۰۰۰) تعیین کنید.
                هرچه امتیاز بالاتر باشد، شانس نمایش فنی‌کار در نتایج جستجو بیشتر است.
            </p>

            <?php if (empty($service_terms) || is_wp_error($service_terms)) : ?>
                <div class="fnk-no-services">
                    <span class="dashicons dashicons-warning"></span>
                    <p>⚠️ هیچ خدمتی به این فنی‌کار متصل نیست.</p>
                    <p style="font-size:12px;color:#aaa;">
                        لطفاً ابتدا از طریق بخش <strong>"تکسونومی‌ها"</strong> در سمت راست،
                        حداقل یک خدمت را به این فنی‌کار متصل کنید.
                    </p>
                </div>
            <?php else : ?>
                <div class="fnk-service-list">
                    <?php foreach ($service_terms as $term) : ?>
                        <?php
                        $score_value = isset($scores[$term->term_id]) ? intval($scores[$term->term_id]) : 0;
                        $score_value = max(0, min(1000, $score_value));
                        ?>
                        <div class="fnk-service-row">
                            <div class="fnk-service-name">
                                <span><?php echo esc_html($term->name); ?></span>
                                <input type="hidden" name="fnk_service_term_ids[]" value="<?php echo esc_attr($term->term_id); ?>">
                            </div>
                            <div class="fnk-service-score">
                                <input type="number"
                                       name="fnk_service_scores[<?php echo esc_attr($term->term_id); ?>]"
                                       value="<?php echo esc_attr($score_value); ?>"
                                       min="0"
                                       max="1000"
                                       step="1"
                                       placeholder="۰"
                                       class="fnk-score-input">
                                <div class="fnk-score-range">۰ تا ۱۰۰۰</div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div style="margin-top:12px;padding:8px 12px;background:#f0f8ff;border-radius:4px;font-size:12px;color:#555;border-right:3px solid #1854CC;">
                    💡 <strong>نکته:</strong> امتیاز ۰ به معنای عدم اولویت است. امتیاز بالاتر = شانس نمایش بیشتر.
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Save priority scores
     */
    public function save_priority_scores($post_id, $post, $update) {
        // Verify nonce
        if (!isset($_POST['fnk_priority_scores_nonce']) ||
                !wp_verify_nonce($_POST['fnk_priority_scores_nonce'], 'fnk_priority_scores_nonce')) {
            return;
        }

        // Prevent autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Prevent revisions
        if (wp_is_post_revision($post_id)) {
            return;
        }

        // Check user permissions
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Check post type
        if ('fanikar' !== get_post_type($post_id)) {
            return;
        }

        // Get service term IDs
        if (!isset($_POST['fnk_service_term_ids']) || !is_array($_POST['fnk_service_term_ids'])) {
            return;
        }

        // Get scores
        $scores = isset($_POST['fnk_service_scores']) && is_array($_POST['fnk_service_scores'])
                ? $_POST['fnk_service_scores']
                : [];

        $service_term_ids = array_map('intval', $_POST['fnk_service_term_ids']);
        $scores_array = [];

        // Clear old individual score meta keys (cleanup)
        $this->clear_individual_scores($post_id);

        // Process each service term
        foreach ($service_term_ids as $term_id) {
            $term_id = intval($term_id);
            if ($term_id <= 0) {
                continue;
            }

            // Get score for this term
            $score = isset($scores[$term_id]) ? intval($scores[$term_id]) : 0;
            $score = max(0, min(1000, $score));

            // Only store if score is greater than 0
            if ($score > 0) {
                // Store in array
                $scores_array[$term_id] = $score;

                // Store individual meta key for fast querying
                $meta_key = self::META_SCORE_PREFIX . $term_id;
                update_post_meta($post_id, $meta_key, $score);
            }
        }

        // Save the array meta
        if (!empty($scores_array)) {
            update_post_meta($post_id, self::META_SCORES_ARRAY, $scores_array);
        } else {
            delete_post_meta($post_id, self::META_SCORES_ARRAY);
        }
    }

    /**
     * Clear all individual score meta keys for a post
     */
    private function clear_individual_scores($post_id) {
        global $wpdb;

        $prefix = self::META_SCORE_PREFIX;
        $wpdb->query(
                $wpdb->prepare(
                        "DELETE FROM {$wpdb->postmeta} 
        WHERE post_id = %d 
        AND meta_key LIKE %s",
                        $post_id,
                        $prefix . '%'
                )
        );
    }

    // ==========================================
    // HELPER METHODS (for frontend use)
    // ==========================================

    /**
     * Get priority score for a specific service term
     */
    public static function get_score_for_service($post_id, $term_id) {
        $meta_key = self::META_SCORE_PREFIX . $term_id;
        $score = get_post_meta($post_id, $meta_key, true);
        return !empty($score) ? intval($score) : 0;
    }

    /**
     * Get all priority scores for a fanikar
     */
    public static function get_all_scores($post_id) {
        $scores = get_post_meta($post_id, self::META_SCORES_ARRAY, true);
        return is_array($scores) ? $scores : [];
    }

    /**
     * Get the score for a specific service term (if the fanikar has that service)
     * This is useful for the weighted random sorting
     */
    public static function get_score_for_fanikar_service($fanikar_id, $service_term_id) {
        $scores = self::get_all_scores($fanikar_id);
        return isset($scores[$service_term_id]) ? intval($scores[$service_term_id]) : 0;
    }
}