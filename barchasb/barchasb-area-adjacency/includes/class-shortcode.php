<?php
/**
 * Front-end shortcode and rendering.
 *
 * @package Barchasb\AreaAdjacency
 */

namespace Barchasb\AreaAdjacency;

defined('ABSPATH') || exit;

final class Shortcode {
	private Settings $settings;
	private Post_Types $post_types;
	private int $instance = 0;

	public function __construct(Settings $settings, Post_Types $post_types) {
		$this->settings = $settings;
		$this->post_types = $post_types;
		add_shortcode('baa_adjacent_areas', array($this, 'render'));
		add_action('wp_enqueue_scripts', array($this, 'register_assets'));
	}

	public function register_assets(): void {
		wp_register_style('baa-frontend', BAA_URL . 'assets/css/frontend.css', array(), BAA_VERSION);
		wp_register_script('baa-frontend', BAA_URL . 'assets/js/frontend.js', array(), BAA_VERSION, true);
	}

	/** @param array<string, mixed> $atts */
	public function render(array $atts = array()): string {
		$defaults = $this->settings->get(); $atts = shortcode_atts(array(
			'post_id' => 0, 'title' => '', 'limit' => 12, 'orderby' => 'manual', 'show_image' => null, 'show_title' => null, 'show_excerpt' => null, 'show_cta' => null, 'slides_desktop' => 0, 'slides_tablet' => 0, 'slides_mobile' => 0, 'autoplay' => null, 'autoplay_delay' => 0, 'loop' => null, 'navigation' => null, 'pagination' => null, 'space_between' => 0, 'image_size' => '', 'class' => '',
		), $atts, 'baa_adjacent_areas');
		$post_id = absint($atts['post_id']) ?: (int) get_the_ID(); $source = get_post($post_id); if (! $source || ! $this->post_types->is_active($source->post_type)) { return ''; }
		$ids = get_post_meta($post_id, '_baa_adjacent_post_ids', true); $ids = is_array($ids) ? array_values(array_unique(array_filter(array_map('absint', $ids)))) : array(); $ids = array_values(array_filter($ids, fn (int $id): bool => $id !== $post_id && $this->post_types->is_valid_target($id)));
		$limit = min(100, max(1, absint($atts['limit']))); if (count($ids) > $limit) { $ids = array_slice($ids, 0, $limit); }
		$orderby = in_array($atts['orderby'], array('manual', 'title', 'date', 'rand'), true) ? $atts['orderby'] : 'manual'; if (empty($ids)) { return ! empty($defaults['show_empty_message']) ? '<div class="baa-empty-message">' . esc_html((string) $defaults['empty_message']) . '</div>' : ''; }
		$query_args = array('post_type' => $this->post_types->active(), 'post_status' => 'publish', 'post__in' => $ids, 'posts_per_page' => $limit, 'ignore_sticky_posts' => true, 'no_found_rows' => true, 'orderby' => 'post__in'); if ('title' === $orderby) { $query_args['orderby'] = 'title'; $query_args['order'] = 'ASC'; } elseif ('date' === $orderby) { $query_args['orderby'] = 'date'; $query_args['order'] = 'DESC'; } elseif ('rand' === $orderby) { $query_args['orderby'] = 'rand'; }
		$query = new \WP_Query($query_args); if (! $query->have_posts()) { wp_reset_postdata(); return ''; }
		$values = $defaults; $values['title'] = '' !== trim((string) $atts['title']) ? sanitize_text_field((string) $atts['title']) : $defaults['default_title'];
		foreach (array('show_image', 'show_title', 'show_excerpt', 'show_cta', 'autoplay', 'loop', 'navigation', 'pagination') as $key) { if (null !== $atts[$key] && '' !== $atts[$key]) { $values[$key] = in_array(strtolower((string) $atts[$key]), array('yes', '1', 'true'), true); } }
		foreach (array('slides_desktop', 'slides_tablet', 'slides_mobile', 'space_between', 'autoplay_delay') as $key) { if (absint($atts[$key]) > 0) { $values[$key] = absint($atts[$key]); } }
		if ('' !== $atts['image_size']) { $values['image_size'] = sanitize_key((string) $atts['image_size']); }
		$this->instance++; $id = 'baa-carousel-' . $this->instance . '-' . wp_rand(1000, 9999); wp_enqueue_style('baa-frontend'); wp_enqueue_script('baa-frontend');
		$config = array('slidesDesktop' => min(8, max(1, absint($values['slides_desktop']))), 'slidesTablet' => min(6, max(1, absint($values['slides_tablet']))), 'slidesMobile' => min(4, max(1, absint($values['slides_mobile']))), 'spaceBetween' => absint($values['space_between']), 'autoplay' => (bool) $values['autoplay'], 'autoplayDelay' => absint($values['autoplay_delay']), 'pauseOnHover' => (bool) $values['pause_on_hover'], 'loop' => (bool) $values['loop'], 'navigation' => (bool) $values['navigation'], 'pagination' => (bool) $values['pagination']);
		$shadow = array('none' => 'none', 'small' => '0 4px 12px rgba(15, 23, 42, .06)', 'medium' => '0 8px 24px rgba(15, 23, 42, .08)', 'large' => '0 14px 36px rgba(15, 23, 42, .14)'); $width = ! empty($values['section_full_width']) ? '100vw' : 'auto'; $horizontal_margin = ! empty($values['section_full_width']) ? 'calc(50% - 50vw)' : '0'; $class = trim('baa-adjacent-areas ' . sanitize_html_class((string) $atts['class'])); $style = sprintf('--baa-space:%dpx;--baa-radius:%dpx;--baa-image-radius:%dpx;--baa-shadow:%s;--baa-card-bg:%s;--baa-card-text:%s;--baa-title:%s;--baa-cta-bg:%s;--baa-cta-text:%s;--baa-cta-hover:%s;--baa-border:%s;--baa-nav-bg:%s;--baa-nav-icon:%s;background-color:%s;width:%s;margin-top:%dpx;margin-right:%s;margin-bottom:%dpx;margin-left:%s;padding:%dpx %dpx %dpx %dpx;box-sizing:border-box;', absint($values['space_between']), absint($values['border_radius']), absint($values['image_border_radius']), $shadow[$values['card_shadow']] ?? $shadow['medium'], $values['card_background_color'], $values['card_text_color'], $values['title_color'], $values['cta_background_color'], $values['cta_text_color'], $values['cta_hover_background_color'], $values['border_color'], $values['navigation_background_color'], $values['navigation_icon_color'], $values['section_background_color'], $width, absint($values['section_margin_top']), $horizontal_margin, absint($values['section_margin_bottom']), $horizontal_margin, absint($values['section_padding_top']), absint($values['section_padding_right']), absint($values['section_padding_bottom']), absint($values['section_padding_left'])); if (! empty($values['custom_css'])) { wp_add_inline_style('baa-frontend', (string) $values['custom_css']); } ob_start(); ?><section class="<?php echo esc_attr($class); ?>" style="<?php echo esc_attr($style); ?>" aria-labelledby="<?php echo esc_attr($id); ?>-title"><h2 id="<?php echo esc_attr($id); ?>-title" class="baa-section-title"><?php echo esc_html((string) $values['title']); ?></h2><div class="baa-carousel" id="<?php echo esc_attr($id); ?>" data-baa-config="<?php echo esc_attr(wp_json_encode($config)); ?>"><div class="baa-carousel__viewport"><div class="baa-carousel__track"><?php while ($query->have_posts()) : $query->the_post(); $this->render_card(get_post(), $values); endwhile; ?></div></div><?php if ($values['navigation']) : ?><button type="button" class="baa-carousel__prev" aria-label="اسلاید قبلی">‹</button><button type="button" class="baa-carousel__next" aria-label="اسلاید بعدی">›</button><?php endif; ?><?php if ($values['pagination']) : ?><div class="baa-carousel__pagination" aria-label="صفحات Carousel"></div><?php endif; ?></div></section><?php wp_reset_postdata(); return (string) ob_get_clean();
	}

	/** @param array<string, mixed> $settings */
	private function render_card(\WP_Post $post, array $settings): void {
		$url = get_permalink($post); $title = get_the_title($post) ?: '(بدون عنوان)'; $image = '';
		if (! empty($settings['show_image'])) { if (has_post_thumbnail($post)) { $image = get_the_post_thumbnail($post, (string) $settings['image_size'], array('class' => 'baa-card__image', 'loading' => 'lazy', 'alt' => $title)); } elseif (! empty($settings['fallback_image_id'])) { $image = wp_get_attachment_image(absint($settings['fallback_image_id']), (string) $settings['image_size'], false, array('class' => 'baa-card__image', 'loading' => 'lazy', 'alt' => $title)); } }
		?><article class="baa-card"><a class="baa-card__link" href="<?php echo esc_url($url); ?>" aria-label="<?php echo esc_attr($title); ?>"><?php if ($image) : ?><span class="baa-card__image-link" aria-hidden="true"><?php echo wp_kses_post($image); ?></span><?php endif; ?><span class="baa-card__content"><?php if (! empty($settings['show_cta'])) : ?><h3 class="baa-card__title"><span class="baa-card__cta"><?php echo esc_html($title); ?></span></h3><?php elseif (! empty($settings['show_title'])) : ?><h3 class="baa-card__title"><?php echo esc_html($title); ?></h3><?php endif; ?><?php if (! empty($settings['show_excerpt'])) : ?><span class="baa-card__excerpt"><?php echo wp_kses_post(wp_trim_words(get_the_excerpt($post), 24)); ?></span><?php endif; ?></span></a></article><?php
	}
}
