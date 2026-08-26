<?php

defined( 'ABSPATH' ) || exit;

/**
 * Tehran State Custom Posts
 */
function ajoronli_post_types()
{
// شروع تعریف پست تایپ تهران
    register_taxonomy('tehran_state_taxonomy', array('tehran_state'), array(
        "hierarchical" => true,
        "label" => "دسته مناطق",
        "singular_label" => "دسته مناطق",
        'public' => true,
        'show_ui' => true,
        'show_admin_column' => true,
        'show_in_rest' => true,
        'query_var' => true,
//         'rewrite'           => array('slug' => 'tehran-cat'),

    ));

    $supports = array(
        'title',
        'editor',
        'comments',
		'revisions',
		'thumbnail',
    );

    $labels = array(
        'name' => 'تهران',
        'singular_name' => 'منطقه',
        'menu_name' => 'تهران',
        'name_admin_bar' => 'تهران',
        'add_new' => 'منطقه جدید',
        'add_new_item' => 'اضافه کردن منطقه جدید',
        'new_item' => 'منطقه جدید',
        'edit_item' => 'ویرایش منطقه',
        'view_item' => 'مشاهده منطقه',
        'all_items' => 'مناطق',
        'search_items' => 'جستجوی منطقه',
        'not_found' => 'منطقه‌ای پیدا نشد.',
    );

    $args = array(
        'supports' => $supports,
        'labels' => $labels,
        'public' => true,
        'query_var' => true,
        'menu_icon' => 'dashicons-location-alt',
        'rewrite' => array('slug' => 'tehran', 'with_front' => false),
        'has_archive' => true,
        'taxonomies' => array('post_tag', 'tehran_state_taxonomy'),
        'hierarchical' => false,
        'show_in_rest' => true,

    );
    register_post_type('tehran_state', $args);

    // شروع تعریف پست تایپ کرج
    register_taxonomy('karaj_state_taxonomy', array('karaj_state'), array(
        "hierarchical" => true,
        "label" => "دسته مناطق",
        "singular_label" => "دسته مناطق",
        'public' => true,
        'show_ui' => true,
        'show_admin_column' => true,
        'show_in_rest' => true,
        'query_var' => true,
//         'rewrite'           => array('slug' => 'tehran-cat'),

    ));

    $supports = array(
        'title',
        'editor',
        'comments',
		'revisions',
		'thumbnail',
    );

    $labels = array(
        'name' => 'کرج',
        'singular_name' => 'منطقه',
        'menu_name' => 'کرج',
        'name_admin_bar' => 'کرج',
        'add_new' => 'منطقه جدید',
        'add_new_item' => 'اضافه کردن منطقه جدید',
        'new_item' => 'منطقه جدید',
        'edit_item' => 'ویرایش منطقه',
        'view_item' => 'مشاهده منطقه',
        'all_items' => 'مناطق',
        'search_items' => 'جستجوی منطقه',
        'not_found' => 'منطقه‌ای پیدا نشد.',
    );

    $args = array(
        'supports' => $supports,
        'labels' => $labels,
        'public' => true,
        'query_var' => true,
        'menu_icon' => 'dashicons-location-alt',
        'rewrite' => array('slug' => 'karaj', 'with_front' => false),
        'has_archive' => true,
        'taxonomies' => array('post_tag', 'karaj_state_taxonomy'),
        'hierarchical' => false,
        'show_in_rest' => true,

    );
    
    register_post_type('karaj_state', $args);
}

add_action('init', 'ajoronli_post_types');
