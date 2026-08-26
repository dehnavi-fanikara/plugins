<?php

defined( 'ABSPATH' ) || exit;

/**
 * Custom Product Image Shortcode
 */
add_shortcode( 'bjp_custom_product_image', 'bjp_custom_product_image_shortcode' );

function bjp_custom_product_image_shortcode()
{
    global $post;
    $custom_product_image = get_post_meta( $post->ID, '_bjp_custom_product_image', true );
    echo '<img src="' . $custom_product_image . '">';
}