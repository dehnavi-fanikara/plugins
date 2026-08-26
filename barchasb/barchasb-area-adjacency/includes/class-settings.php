<?php
/**
 * Settings API integration.
 *
 * @package Barchasb\AreaAdjacency
 */

namespace Barchasb\AreaAdjacency;

defined('ABSPATH') || exit;

final class Settings {
	private const PAGE = 'baa-settings';

	public function __construct() {
		add_action('admin_menu', array($this, 'menu'));
		add_action('admin_init', array($this, 'register'));
		add_action('admin_notices', array($this, 'missing_post_type_notice'));
	}

	/** @return array<string, mixed> */
	public function get(): array {
		return Defaults::get();
	}

	public function menu(): void {
		add_menu_page(
			__('همجواری مناطق', 'barchasb-area-adjacency'),
			__('همجواری مناطق', 'barchasb-area-adjacency'),
			'manage_options',
			self::PAGE,
			array($this, 'render_page'),
			'dashicons-location-alt',
			58
		);
	}

	public function register(): void {
		register_setting('baa_settings_group', 'baa_settings', array(
			'type' => 'array',
			'sanitize_callback' => array($this, 'sanitize'),
			'default' => Defaults::all(),
		));

		$sections = array(
			'general' => __('تنظیمات عمومی', 'barchasb-area-adjacency'),
			'post_types' => __('پست‌تایپ‌های مناطق', 'barchasb-area-adjacency'),
			'carousel' => __('نمایش Carousel', 'barchasb-area-adjacency'),
			'appearance' => __('رنگ‌ها و ظاهر', 'barchasb-area-adjacency'),
			'advanced' => __('تنظیمات پیشرفته', 'barchasb-area-adjacency'),
		);

		foreach ($sections as $id => $title) {
			add_settings_section('baa_' . $id, $title, '__return_false', self::PAGE);
		}

		$this->add_fields();
	}

	private function add_fields(): void {
		add_settings_field('default_title', 'عنوان پیش‌فرض بخش', array($this, 'text_field'), self::PAGE, 'baa_general', array('key' => 'default_title'));
		add_settings_field('show_empty_message', 'نمایش پیام در صورت خالی بودن', array($this, 'checkbox_field'), self::PAGE, 'baa_general', array('key' => 'show_empty_message'));
		add_settings_field('empty_message', 'پیام خالی بودن', array($this, 'text_field'), self::PAGE, 'baa_general', array('key' => 'empty_message'));

		add_settings_field('active_post_types', 'پست‌تایپ‌های فعال', array($this, 'post_types_field'), self::PAGE, 'baa_post_types');
		add_settings_field('relationship_mode', 'نوع ارتباط مناطق', array($this, 'relationship_field'), self::PAGE, 'baa_post_types');
		add_settings_field('remove_reverse_relation', 'حذف رابطه معکوس', array($this, 'checkbox_field'), self::PAGE, 'baa_post_types', array('key' => 'remove_reverse_relation'));

		foreach (array('slides_desktop' => 'کارت در دسکتاپ', 'slides_tablet' => 'کارت در تبلت', 'slides_mobile' => 'کارت در موبایل', 'space_between' => 'فاصله بین کارت‌ها', 'autoplay_delay' => 'تأخیر Autoplay (میلی‌ثانیه)') as $key => $label) {
			add_settings_field($key, $label, array($this, 'number_field'), self::PAGE, 'baa_carousel', array('key' => $key));
		}
		foreach (array('autoplay', 'pause_on_hover', 'loop', 'navigation', 'pagination', 'show_image', 'show_title', 'show_excerpt', 'show_cta') as $key) {
			$label = 'show_cta' === $key ? 'نمایش عنوان پست به شکل دکمه' : ucwords(str_replace('_', ' ', $key));
			add_settings_field($key, $label, array($this, 'checkbox_field'), self::PAGE, 'baa_carousel', array('key' => $key));
		}
		add_settings_field('image_size', 'اندازه تصویر', array($this, 'text_field'), self::PAGE, 'baa_carousel', array('key' => 'image_size'));

		foreach (array('card_background_color', 'card_text_color', 'title_color', 'cta_background_color', 'cta_text_color', 'cta_hover_background_color', 'border_color', 'navigation_background_color', 'navigation_icon_color') as $key) {
			add_settings_field($key, ucwords(str_replace('_', ' ', $key)), array($this, 'color_field'), self::PAGE, 'baa_appearance', array('key' => $key));
		}
		add_settings_field('section_background_color', 'رنگ پس‌زمینه بخش', array($this, 'color_field'), self::PAGE, 'baa_appearance', array('key' => 'section_background_color'));
		add_settings_field('section_full_width', 'عرض کامل بخش', array($this, 'checkbox_field'), self::PAGE, 'baa_appearance', array('key' => 'section_full_width'));
		foreach (array('section_padding_top' => 'Padding بالا', 'section_padding_right' => 'Padding راست', 'section_padding_bottom' => 'Padding پایین', 'section_padding_left' => 'Padding چپ', 'section_margin_top' => 'Margin بالا', 'section_margin_bottom' => 'Margin پایین') as $key => $label) {
			add_settings_field($key, $label, array($this, 'number_field'), self::PAGE, 'baa_appearance', array('key' => $key));
		}
		add_settings_field('border_radius', 'گردی گوشه کارت', array($this, 'number_field'), self::PAGE, 'baa_appearance', array('key' => 'border_radius'));
		add_settings_field('image_border_radius', 'گردی گوشه تصویر', array($this, 'number_field'), self::PAGE, 'baa_appearance', array('key' => 'image_border_radius'));
		add_settings_field('card_shadow', 'سایه کارت', array($this, 'shadow_field'), self::PAGE, 'baa_appearance');

		add_settings_field('admin_capability', 'Capability مدیریت روابط', array($this, 'capability_field'), self::PAGE, 'baa_advanced');
		add_settings_field('read_only_without_capability', 'نمایش فقط‌خواندنی برای کاربران فاقد Capability', array($this, 'checkbox_field'), self::PAGE, 'baa_advanced', array('key' => 'read_only_without_capability'));
		add_settings_field('fallback_image_id', 'شناسه تصویر پیش‌فرض', array($this, 'number_field'), self::PAGE, 'baa_advanced', array('key' => 'fallback_image_id'));
		add_settings_field('custom_css', 'CSS سفارشی', array($this, 'textarea_field'), self::PAGE, 'baa_advanced', array('key' => 'custom_css'));
	}

	/** @param array<string, mixed> $args */
	public function text_field(array $args): void { $key = (string) $args['key']; $settings = $this->get(); printf('<input class="regular-text" type="text" name="baa_settings[%1$s]" value="%2$s">', esc_attr($key), esc_attr((string) $settings[$key])); }
	/** @param array<string, mixed> $args */
	public function number_field(array $args): void { $key = (string) $args['key']; $settings = $this->get(); printf('<input type="number" min="0" class="small-text" name="baa_settings[%1$s]" value="%2$s">', esc_attr($key), esc_attr((string) $settings[$key])); }
	/** @param array<string, mixed> $args */
	public function checkbox_field(array $args): void { $key = (string) $args['key']; $settings = $this->get(); printf('<label><input type="hidden" name="baa_settings[%1$s]" value="0"><input type="checkbox" name="baa_settings[%1$s]" value="1" %2$s> فعال</label>', esc_attr($key), checked(! empty($settings[$key]), true, false)); }
	/** @param array<string, mixed> $args */
	public function color_field(array $args): void { $key = (string) $args['key']; $settings = $this->get(); printf('<input class="baa-color-field" type="text" name="baa_settings[%1$s]" value="%2$s" data-default-color="%2$s">', esc_attr($key), esc_attr((string) $settings[$key])); }
	public function textarea_field(array $args): void { $settings = $this->get(); printf('<textarea class="large-text code" rows="8" name="baa_settings[%1$s]">%2$s</textarea>', esc_attr((string) $args['key']), esc_textarea((string) $settings[$args['key']])); }
	public function shadow_field(): void { $settings = $this->get(); echo '<select name="baa_settings[card_shadow]">'; foreach (array('none' => 'بدون سایه', 'small' => 'کم', 'medium' => 'متوسط', 'large' => 'زیاد') as $value => $label) { printf('<option value="%1$s" %2$s>%3$s</option>', esc_attr($value), selected($settings['card_shadow'], $value, false), esc_html($label)); } echo '</select>'; }
	public function relationship_field(): void { $settings = $this->get(); foreach (array('one_way' => 'یک‌طرفه', 'two_way' => 'دوطرفه خودکار') as $value => $label) { printf('<label style="margin-left:16px"><input type="radio" name="baa_settings[relationship_mode]" value="%1$s" %2$s> %3$s</label>', esc_attr($value), checked($settings['relationship_mode'], $value, false), esc_html($label)); } }
	public function capability_field(): void { $settings = $this->get(); printf('<input class="regular-text" type="text" name="baa_settings[admin_capability]" value="%s">', esc_attr((string) $settings['admin_capability'])); echo '<p class="description">مثلاً edit_others_posts</p>'; }
	public function post_types_field(): void { $settings = $this->get(); $selected = is_array($settings['active_post_types']) ? $settings['active_post_types'] : array(); foreach (get_post_types(array('public' => true, 'show_ui' => true), 'objects') as $type) { if (in_array($type->name, array('attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'wp_block', 'wp_template', 'wp_template_part'), true)) { continue; } printf('<label style="display:block"><input type="checkbox" name="baa_settings[active_post_types][]" value="%1$s" %2$s> %3$s <code>%1$s</code></label>', esc_attr($type->name), checked(in_array($type->name, $selected, true), true, false), esc_html($type->labels->singular_name)); } }

	public function sanitize($input): array {
		$input = is_array($input) ? $input : array(); $defaults = Defaults::all(); $out = array_merge($defaults, $this->get());
		$available = get_post_types(array('public' => true, 'show_ui' => true));
		if (array_key_exists('active_post_types', $input)) { $selected = is_array($input['active_post_types']) ? array_map('sanitize_key', $input['active_post_types']) : array(); $out['active_post_types'] = array_values(array_intersect($selected, array_diff($available, array('attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'wp_block', 'wp_template', 'wp_template_part')))); }
		if (array_key_exists('relationship_mode', $input)) { $out['relationship_mode'] = in_array($input['relationship_mode'], array('one_way', 'two_way'), true) ? $input['relationship_mode'] : $defaults['relationship_mode']; }
		if (array_key_exists('admin_capability', $input)) { $out['admin_capability'] = sanitize_key((string) $input['admin_capability']) ?: $defaults['admin_capability']; }
		foreach (array('default_title', 'empty_message', 'image_size') as $key) { if (array_key_exists($key, $input)) { $out[$key] = sanitize_text_field((string) $input[$key]); } }
		if (array_key_exists('custom_css', $input)) { $out['custom_css'] = wp_strip_all_tags((string) $input['custom_css']); }
		foreach (array('remove_reverse_relation', 'read_only_without_capability', 'autoplay', 'pause_on_hover', 'loop', 'navigation', 'pagination', 'show_image', 'show_title', 'show_excerpt', 'show_cta', 'show_empty_message', 'section_full_width') as $key) { if (array_key_exists($key, $input)) { $out[$key] = ! empty($input[$key]); } }
		foreach (array('slides_desktop' => array(1, 8), 'slides_tablet' => array(1, 6), 'slides_mobile' => array(1, 4), 'space_between' => array(0, 100), 'autoplay_delay' => array(1000, 60000), 'border_radius' => array(0, 60), 'image_border_radius' => array(0, 60), 'fallback_image_id' => array(0, PHP_INT_MAX), 'section_padding_top' => array(0, 200), 'section_padding_right' => array(0, 200), 'section_padding_bottom' => array(0, 200), 'section_padding_left' => array(0, 200), 'section_margin_top' => array(0, 200), 'section_margin_bottom' => array(0, 200)) as $key => $range) { if (array_key_exists($key, $input)) { $value = absint($input[$key]); $out[$key] = min($range[1], max($range[0], $value)); } }
		foreach (array('card_background_color', 'card_text_color', 'title_color', 'cta_background_color', 'cta_text_color', 'cta_hover_background_color', 'border_color', 'navigation_background_color', 'navigation_icon_color', 'section_background_color') as $key) { if (array_key_exists($key, $input)) { $color = sanitize_hex_color($input[$key]); $out[$key] = $color ?: $defaults[$key]; } }
		if (array_key_exists('card_shadow', $input)) { $out['card_shadow'] = in_array($input['card_shadow'], array('none', 'small', 'medium', 'large'), true) ? $input['card_shadow'] : $defaults['card_shadow']; } $out['version'] = BAA_VERSION;
		return $out;
	}

	public function render_page(): void {
		if (! current_user_can('manage_options')) { wp_die(esc_html__('شما اجازه دسترسی به این صفحه را ندارید.', 'barchasb-area-adjacency')); }
		$tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'general'; $tabs = array('general' => 'تنظیمات عمومی', 'post_types' => 'پست‌تایپ‌ها', 'carousel' => 'نمایش Carousel', 'appearance' => 'رنگ‌ها و ظاهر', 'advanced' => 'تنظیمات پیشرفته', 'guide' => 'راهنما');
		if (! isset($tabs[$tab])) { $tab = 'general'; }
		?><div class="wrap baa-settings-page"><h1><?php echo esc_html__('همجواری مناطق', 'barchasb-area-adjacency'); ?></h1><nav class="nav-tab-wrapper"><?php foreach ($tabs as $key => $label) { printf('<a class="nav-tab %1$s" href="%2$s">%3$s</a>', $tab === $key ? 'nav-tab-active' : '', esc_url(add_query_arg(array('page' => self::PAGE, 'tab' => $key), admin_url('admin.php'))), esc_html($label)); } ?></nav><?php if ('guide' === $tab) { $this->render_guide(); } else { ?><form method="post" action="options.php"><?php settings_fields('baa_settings_group'); echo '<table class="form-table">'; do_settings_fields(self::PAGE, 'baa_' . $tab); echo '</table>'; submit_button(); ?></form><?php } ?><hr><p><strong>Shortcode:</strong> <code>[baa_adjacent_areas]</code></p></div><?php
	}

	/**
	 * Render a complete shortcode example for administrators.
	 *
	 * @return void
	 */
	private function render_guide(): void {
		$example = "[baa_adjacent_areas\n"
			. "    post_id=\"123\"\n"
			. "    title=\"مناطق نزدیک و مرتبط\"\n"
			. "    limit=\"12\"\n"
			. "    orderby=\"manual\"\n"
			. "    show_image=\"yes\"\n"
			. "    show_title=\"yes\"\n"
			. "    show_excerpt=\"no\"\n"
			. "    show_cta=\"yes\"\n"
			. "    slides_desktop=\"4\"\n"
			. "    slides_tablet=\"2\"\n"
			. "    slides_mobile=\"1\"\n"
			. "    autoplay=\"no\"\n"
			. "    autoplay_delay=\"5000\"\n"
			. "    loop=\"no\"\n"
			. "    navigation=\"yes\"\n"
			. "    pagination=\"yes\"\n"
			. "    space_between=\"24\"\n"
			. "    image_size=\"medium_large\"\n"
			. "    class=\"my-area-carousel\"\n"
			. "]";
		?><div class="baa-guide"><h2>نمونه کامل شورت‌کد</h2><p>این نمونه را می‌توانید مستقیماً در محتوای نوشته یا برگه قرار دهید. مقدار <code>post_id</code> را با شناسه پست موردنظر جایگزین کنید؛ در صورت حذف آن، پست جاری استفاده می‌شود.</p><p><textarea id="baa-shortcode-example" class="large-text code" rows="23" readonly><?php echo esc_textarea($example); ?></textarea></p><button type="button" class="button button-primary" id="baa-copy-shortcode">کپی نمونه شورت‌کد</button><span id="baa-copy-status" aria-live="polite" style="margin-right:10px"></span><h3>حالت ساده</h3><p><code>[baa_adjacent_areas]</code></p><h3>نکات تنظیمات</h3><ul><li>عنوان هر پست به‌صورت دکمه نمایش داده می‌شود و با کلیک روی آن، کاربر به صفحه همان پست می‌رود.</li><li>تنظیم «نمایش عنوان پست به شکل دکمه» در صورت فعال بودن، عنوان را با رنگ‌های CTA نمایش می‌دهد.</li><li><code>orderby</code>: manual، title، date، rand — گزینه‌های بله/خیر مانند <code>show_image</code> و <code>navigation</code>: yes یا no</li><li>رنگ CTA، رنگ پس‌زمینه، عرض Full Width، Padding و Margin از پنل تنظیمات قابل تغییر هستند.</li></ul></div><script>document.addEventListener('DOMContentLoaded',function(){var b=document.getElementById('baa-copy-shortcode');if(!b){return;}b.addEventListener('click',function(){var f=document.getElementById('baa-shortcode-example'),s=document.getElementById('baa-copy-status');f.select();if(navigator.clipboard){navigator.clipboard.writeText(f.value).then(function(){s.textContent='کپی شد.';});}else{document.execCommand('copy');s.textContent='کپی شد.';}});});</script><?php
	}

	public function missing_post_type_notice(): void {
		if (! isset($_GET['page']) || self::PAGE !== sanitize_key(wp_unslash($_GET['page'])) || ! current_user_can('manage_options')) { return; }
		$missing = array_values(array_filter(array('karaj_state', 'tehran_state'), static fn (string $type): bool => ! post_type_exists($type))); if (empty($missing)) { return; }
		printf('<div class="notice notice-warning"><p>%s</p></div>', esc_html(sprintf('پست‌تایپ‌های پیش‌فرض ثبت نشده‌اند: %s', implode(', ', $missing))));
	}
}
