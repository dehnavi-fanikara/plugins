<?php
// Change Post Type to Post
function change_custom_post_type_to_default() {
    global $wpdb;

    // Define the custom post type and the default post type
    $custom_post_type = 'blog_post'; // Change this to your custom post type
    $default_post_type = 'post';

    // Update the post type in the database
    $wpdb->query(
        $wpdb->prepare(
            "
            UPDATE {$wpdb->prefix}posts 
            SET post_type = %s 
            WHERE post_type = %s
            ",
            $default_post_type,
            $custom_post_type
        )
    );
}
// Hook to run on plugin activation or any trigger
add_action('init', 'change_custom_post_type_to_default');

// ACF

// add default image setting to ACF image fields
  // let's you select a defualt image
  // this is simply taking advantage of a field setting that already exists
  
	add_action('acf/render_field_settings/type=image', 'add_default_value_to_image_field');
	function add_default_value_to_image_field($field) {
		acf_render_field_setting( $field, array(
			'label'			=> 'Default Image',
			'instructions'		=> 'Appears when creating a new post',
			'type'			=> 'image',
			'name'			=> 'default_value',
		));
	}

  add_action('acf/render_field_settings/type=file', 'add_default_value_to_file_field');
  function add_default_value_to_file_field($field) {
      acf_render_field_setting( $field, array(
          'label'			=> 'Default File',
          'instructions'		=> 'Appears when creating a new post',
          'type'			=> 'file',
          'name'			=> 'default_value',
      ));
  }




// // No Index and No Follow archieve custom post type:
// function add_noindex_nofollow_to_custom_post_type_archives() {
//     if (is_post_type_archive('tehran_state_taxonomy')) {
//         // Add noindex and nofollow meta tags to the archive of 'your_custom_post_type'
//         echo '<meta name="robots" content="noindex, nofollow" />' . "\n";
//     }
// }
// add_action('wp_head', 'add_noindex_nofollow_to_custom_post_type_archives');



// Change shortcode name
function custom_elementor_template_shortcode($atts) {
    // Define default attributes (e.g., default template ID)
    $atts = shortcode_atts(
        array(
            'id' => '15467', // Default template ID
        ), 
        $atts
    );
    
    // Run the Elementor template shortcode using the ID from the attributes
    return do_shortcode('[elementor-template id="' . esc_attr($atts['id']) . '"]');
}

// Register the new shortcode
add_shortcode('quick-call', 'custom_elementor_template_shortcode');


// Change breadcrumb home name
add_filter( 'astra_breadcrumb_trail_labels', function( $args ) {
	$args['home'] = __( 'لوله بازکنی برچسب', 'astra' );
	return $args;
});


// Remove the author URL in post meta
// add_filter('astra_post_meta', 'remove_author_link_from_post_meta');

// function remove_author_link_from_post_meta($meta) {
//     if (is_single()) {
//         // Get the plain author name (without the link)
//         $author_name = get_the_author();
        
//         // Replace the linked author name with just the plain text author name
//         $meta = str_replace(get_the_author_posts_link(), $author_name, $meta);
//     }
//     return $meta;
// }

function remove_author_link($link) {
    // Only apply to single posts
    if (is_single()) {
        // Remove the anchor tags from the author link
        $author_name = get_the_author();
        return '<span class="author-name">' . $author_name . '</span>';
    }
    return $link;
}
add_filter('the_author_posts_link', 'remove_author_link');


add_action('wp_enqueue_scripts', function() {
    if (is_page() || is_singular()) {
        \Elementor\Plugin::$instance->frontend->enqueue_styles();
    }
});


function acf_fixed_html_field_shortcode() {
    $video_iframe = get_field('video_iframe');

    // نمایش ویدیو در صورت وجود
    if (!empty($video_iframe)) {
        return $video_iframe;
    }

    // نمایش عکس شاخص به‌صورت Responsive
    if (has_post_thumbnail()) {
        return get_the_post_thumbnail(
            get_the_ID(),
            'full',
            [
                'style'   => 'display:block; width:100%; max-width:100%; height:auto;',
                'loading' => 'lazy',
                'alt'     => esc_attr(get_the_title()),
            ]
        );
    }

    return '';
}

add_shortcode('acf_fixed_html', 'acf_fixed_html_field_shortcode');

define('WP_POST_REVISIONS', 1); // keeps only last 1 revisions
