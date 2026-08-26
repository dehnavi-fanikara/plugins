<?php
/**
 * Plugin Name: Fanikara Custom Actions
 * Plugin URI: https://fanikara.com
 * Description: نمایش لیست پست‌های یک کاستوم پست تایپ با شورتکد.
 * Version: 1.0.0
 * Author: Fanikara
 * License: GPL2+
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shortcode:
 * [fanikara_posts post_type="product"]
 */
function fanikara_custom_actions_posts_shortcode($atts)
{
    $atts = shortcode_atts(array(
        'post_type' => 'post',
    ), $atts);

    $post_type = sanitize_key($atts['post_type']);

    if (!post_type_exists($post_type)) {
        return '<p>Post Type وجود ندارد.</p>';
    }

    $posts = get_posts(array(
        'post_type'      => $post_type,
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'orderby'        => 'ID',
        'order'          => 'ASC',
    ));

    if (empty($posts)) {
        return '<p>هیچ پستی یافت نشد.</p>';
    }

    ob_start();
    ?>

    <table style="border-collapse:collapse;width:100%;max-width:900px;">
        <thead>
            <tr>
                <th style="border:1px solid #ccc;padding:8px;">ID</th>
                <th style="border:1px solid #ccc;padding:8px;">Title</th>
				<th style="border:1px solid #ccc;padding:8px;">Zone Name</th>
            </tr>
        </thead>
        <tbody>

        <?php foreach ($posts as $post): ?>

            <tr>
                <td style="border:1px solid #ccc;padding:8px;">
                    <?php echo esc_html($post->ID); ?>
                </td>

                <td style="border:1px solid #ccc;padding:8px;">
                    <?php echo esc_html($post->post_title); ?>
                </td>

				<td style="border:1px solid #ccc;padding:8px;">
                    <?php echo get_post_meta( $post->ID, 'zone_name', true ); ?>
                </td>
            </tr>

        <?php endforeach; ?>

        </tbody>
    </table>

    <?php

    return ob_get_clean();
}

add_shortcode('fanikara_posts', 'fanikara_custom_actions_posts_shortcode');