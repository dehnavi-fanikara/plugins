<?php

defined( 'ABSPATH' ) || exit;

/**
 * Hafez copyright shortcode
 *
 * Example: [hafez_copyright type="site"]
 */
add_shortcode( 'hafez_copyright', 'hafez_copyright_shortcode' );

function hafez_copyright_shortcode( $atts )
{
    $atts = shortcode_atts( array( 'type' => 'seosite' ), $atts );

    $type = esc_attr( $atts['type'] );

    $styles = array(
        'font-size' => '14px',
        'color'     => '#fff',
    );

    $texts = array(
        'home' => array(
            'seo'     => 'خدمات سئو',
            'site'    => 'طراحی سایت',
            'seosite' => 'طراحی سایت و خدمات سئو حافظ',
        ),
        'archive' => array(
            'seo'     => 'خدمات سئو سایت',
            'site'    => 'طراحی سایت تخصصی',
            'seosite' => 'طراحی و سئو سایت حافظ',
        ),
        'other' => array(
            'seo'     => 'خدمات سئو',
            'site'    => 'طراحی سایت',
            'seosite' => 'طراحی سایت و خدمات سئو توسط آژانس دیجیتال مارکتینگ حافظ',
        ),
    );

    $urls = array(
        'seo'     => 'https://hafez.agency/seo/',
        'site'    => 'https://hafez.agency/webdesign/',
        'seosite' => 'https://hafez.agency/',
    );

    if ( is_front_page() ) {
        $page_type = 'home';
    } elseif ( is_archive() && in_array( get_post_type(), ['post', 'product'] ) ) {
        $page_type = 'archive';
    } else {
	    $page_type = 'other';
    }

	$style = '';
	foreach ( $styles as $property => $value ) {
		$style .= $property . ':' . $value . ';';
	}

    $text = $texts[$page_type][$type];
    $url  = $urls[$type];
    $rel  = ( $page_type != 'other' ) ?'':'rel="nofollow"';
    $span = ( $type != 'seosite' ) ? '<span> توسط </span><a href="https://hafez.agency" style="' . $style . '">آژانس دیجیتال مارکتینگ حافظ</a>' : '';

	if( empty( $text ) || empty( $url ) ) {
		return;
	}

	if ( $page_type == 'other' ) {
		$span = strip_tags( $span );
		return '<span id="hafez_copyright" style="' . $style . '">' . $text . $span . '</span>';
	}

    return '<span id="hafez_copyright" style="' . $style . '"><a href="' . $url . '" style="' . $style . '" ' . $rel . '>' . $text . '</a>' . $span . '</span>';
}