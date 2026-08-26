<?php
/**
 * Default settings and shared sanitizers.
 *
 * @package Barchasb\AreaAdjacency
 */

namespace Barchasb\AreaAdjacency;

defined('ABSPATH') || exit;

final class Defaults {
	/**
	 * Return all defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		return array(
			'version' => BAA_VERSION,
			'active_post_types' => array('karaj_state', 'tehran_state'),
			'relationship_mode' => 'one_way',
			'remove_reverse_relation' => true,
			'admin_capability' => 'edit_others_posts',
			'read_only_without_capability' => true,
			'default_title' => 'مناطق نزدیک و مرتبط',
			'empty_message' => '',
			'show_empty_message' => false,
			'slides_desktop' => 4,
			'slides_tablet' => 2,
			'slides_mobile' => 1,
			'space_between' => 24,
			'autoplay' => false,
			'autoplay_delay' => 5000,
			'pause_on_hover' => true,
			'loop' => false,
			'navigation' => true,
			'pagination' => true,
			'show_image' => true,
			'show_title' => true,
			'show_excerpt' => false,
			'show_cta' => true,
			'image_size' => 'medium_large',
			'fallback_image_id' => 0,
			'card_background_color' => '#ffffff',
			'card_text_color' => '#1f2937',
			'title_color' => '#111827',
			'cta_background_color' => '#2563eb',
			'cta_text_color' => '#ffffff',
			'cta_hover_background_color' => '#1d4ed8',
			'border_color' => '#e5e7eb',
			'navigation_background_color' => '#ffffff',
			'navigation_icon_color' => '#111827',
			'border_radius' => 16,
			'image_border_radius' => 12,
			'card_shadow' => 'medium',
			'custom_css' => '',
			'section_background_color' => '#f8fafc',
			'section_full_width' => false,
			'section_padding_top' => 32,
			'section_padding_right' => 16,
			'section_padding_bottom' => 32,
			'section_padding_left' => 16,
			'section_margin_top' => 32,
			'section_margin_bottom' => 32,
		);
	}

	/**
	 * Merge stored settings with defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		$stored = get_option('baa_settings', array());
		return wp_parse_args(is_array($stored) ? $stored : array(), self::all());
	}
}
