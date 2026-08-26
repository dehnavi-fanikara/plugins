<?php
/**
 * Active post type management.
 *
 * @package Barchasb\AreaAdjacency
 */

namespace Barchasb\AreaAdjacency;

defined('ABSPATH') || exit;

final class Post_Types {
	private const EXCLUDED = array(
		'attachment', 'revision', 'nav_menu_item', 'custom_css',
		'customize_changeset', 'wp_block', 'wp_template', 'wp_template_part',
	);

	private Settings $settings;

	public function __construct(Settings $settings) {
		$this->settings = $settings;
		add_filter('baa_active_post_types', array($this, 'active'), 10, 1);
	}

	/**
	 * Get public post types with a management UI.
	 *
	 * @return array<string, WP_Post_Type>
	 */
	public function available(): array {
		$types = get_post_types(array('public' => true, 'show_ui' => true), 'objects');
		return array_filter(
			$types,
			static fn (\WP_Post_Type $type): bool => ! in_array($type->name, self::EXCLUDED, true)
		);
	}

	/**
	 * Get active and currently registered post types.
	 *
	 * @return string[]
	 */
	public function active(): array {
		$settings = $this->settings->get();
		$active = isset($settings['active_post_types']) && is_array($settings['active_post_types'])
			? $settings['active_post_types']
			: array();

		return array_values(array_filter($active, fn (string $type): bool => post_type_exists($type) && isset($this->available()[$type])));
	}

	public function is_active(string $post_type): bool {
		return in_array($post_type, $this->active(), true);
	}

	public function is_valid_target(int $post_id): bool {
		$post = get_post($post_id);
		return $post instanceof \WP_Post
			&& $this->is_active($post->post_type)
			&& ! in_array($post->post_status, array('trash', 'auto-draft'), true);
	}

	/**
	 * Missing default post types for the admin warning.
	 *
	 * @return string[]
	 */
	public function missing_defaults(): array {
		return array_values(array_filter(array('karaj_state', 'tehran_state'), static fn (string $type): bool => ! post_type_exists($type)));
	}
}
