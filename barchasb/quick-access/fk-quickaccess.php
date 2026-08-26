<?php

/**
 * Plugin Name: FK-QuickAccess
 * Plugin URI: https://example.com/
 * Description: نمایش شبکه دسترسی سریع و قابل تنظیم با شورت‌کد.
 * Version: 1.0.40
 * Author: FK
 * Text Domain: fk-quickaccess
 */

defined('ABSPATH') || exit;

final class FK_Quick_Access_Plugin
{
	private const OPTION_KEY = 'fk_quick_access_options';
	private const SHORTCODE  = 'quick_access';
	private int $instance = 0;

	public function __construct()
	{
		add_action('admin_menu', [$this, 'admin_menu']);
		add_action('admin_init', [$this, 'register_settings']);
		add_shortcode(self::SHORTCODE, [$this, 'render_shortcode']);
	}

	public function admin_menu(): void
	{
		add_menu_page(
			'تنظیمات دسترسی سریع',
			'دسترسی سریع',
			'manage_options',
			'fk-quickaccess',
			[$this, 'settings_page'],
			'dashicons-admin-links',
			'58.7'
		);
	}

	public function register_settings(): void
	{
		register_setting('fk_quick_access_group', self::OPTION_KEY, [
			'type'              => 'array',
			'sanitize_callback' => [$this, 'sanitize_options'],
			'default'           => $this->defaults(),
		]);
	}

	private function defaults(): array
	{
		$icon = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 3 4 9v10a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9l-8-6Zm0 4.2L17 11v8h-3v-5h-4v5H7v-8l5-3.8Z"/></svg>';
		return ['items' => array_fill(0, 4, [
			'icon' => $icon,
			'title' => 'دسترسی سریع',
			'description' => 'توضیحات این بخش',
			'target_id' => '',
			'icon_color' => '#2563eb',
			'title_color' => '#111827',
			'description_color' => '#4b5563',
			'badge_background' => '#eff6ff',
		]), 'styles' => [
			'active_border' => '#bce8f0',
			'active_background' => '#eaf9fc',
			'hover_border' => '#cfeef4',
			'hover_background' => '#f2fbfd',
			'normal_border' => '#e5e7eb',
			'normal_background' => '#ffffff',
		]];
	}

	private function svg_allowed(): array
	{
		return [
			'svg' => ['xmlns' => true, 'viewbox' => true, 'width' => true, 'height' => true, 'fill' => true, 'role' => true, 'aria-hidden' => true, 'focusable' => true],
			'path' => ['d' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true],
			'g' => ['fill' => true, 'stroke' => true, 'stroke-width' => true],
			'circle' => ['cx' => true, 'cy' => true, 'r' => true, 'fill' => true, 'stroke' => true],
			'rect' => ['x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true, 'fill' => true],
			'line' => ['x1' => true, 'x2' => true, 'y1' => true, 'y2' => true, 'stroke' => true, 'stroke-width' => true],
		];
	}

	public function sanitize_options($input): array
	{
		$defaults = $this->defaults();
		$items = is_array($input['items'] ?? null) ? $input['items'] : [];
		$clean = [];
		$svg_allowed = $this->svg_allowed();
		for ($i = 0; $i < 4; $i++) {
			$item = is_array($items[$i] ?? null) ? $items[$i] : [];
			$default = $defaults['items'][$i];
			$clean[] = [
				'icon' => wp_kses((string) ($item['icon'] ?? $default['icon']), $svg_allowed),
				'title' => sanitize_text_field($item['title'] ?? ''),
				'description' => sanitize_text_field($item['description'] ?? ''),
				'target_id' => sanitize_title(ltrim((string) ($item['target_id'] ?? ''), '#')),
				'icon_color' => sanitize_hex_color($item['icon_color'] ?? '') ?: $default['icon_color'],
				'title_color' => sanitize_hex_color($item['title_color'] ?? '') ?: $default['title_color'],
				'description_color' => sanitize_hex_color($item['description_color'] ?? '') ?: $default['description_color'],
				'badge_background' => sanitize_hex_color($item['badge_background'] ?? '') ?: $default['badge_background'],
			];
		}
		$style_defaults = $defaults['styles'];
		$styles = is_array($input['styles'] ?? null) ? $input['styles'] : [];
		$clean_styles = [];
		foreach ($style_defaults as $key => $default) {
			$clean_styles[$key] = sanitize_hex_color($styles[$key] ?? '') ?: $default;
		}
		return ['items' => $clean, 'styles' => $clean_styles];
	}

	public function settings_page(): void
	{
		if (! current_user_can('manage_options')) {
			return;
		}
		$options = wp_parse_args(get_option(self::OPTION_KEY, []), $this->defaults());
		$items = $options['items'];
?>
		<div class="wrap" dir="rtl">
			<h1>دسترسی سریع</h1>
			<p>چهار گزینه زیر را تنظیم کنید و سپس این شورت‌کد را در برگه یا نوشته قرار دهید:</p>
			<div style="display:flex;gap:8px;align-items:center;margin:14px 0 20px"><code
					id="fk-quickaccess-shortcode">[quick_access sticky="true"]</code><button type="button"
					class="button" id="fk-copy-shortcode">کپی شورت‌کد</button><span id="fk-copy-status"
					aria-live="polite"></span></div>
			<form method="post" action="options.php">
				<?php settings_fields('fk_quick_access_group'); ?>
				<?php for ($i = 0; $i < 4; $i++) : $item = wp_parse_args($items[$i] ?? [], $this->defaults()['items'][$i]); ?>
					<fieldset style="border:1px solid #ccd0d4;padding:16px;margin:0 0 16px;max-width:850px;background:#fff">
						<legend style="font-weight:600;padding:0 8px">گزینه <?php echo esc_html($i + 1); ?></legend>
						<table class="form-table" role="presentation">
							<tbody>
								<tr>
									<th><label for="fk-item-<?php echo esc_attr($i); ?>-icon">آیکن SVG</label></th>
									<td><textarea class="large-text code" rows="4" id="fk-item-<?php echo esc_attr($i); ?>-icon"
											name="<?php echo esc_attr(self::OPTION_KEY); ?>[items][<?php echo esc_attr($i); ?>][icon]"
											dir="ltr"><?php echo esc_textarea($item['icon']); ?></textarea>
										<p class="description">کد خام SVG را وارد کنید.</p>
									</td>
								</tr>
								<tr>
									<th><label for="fk-item-<?php echo esc_attr($i); ?>-title">عنوان</label></th>
									<td><input class="regular-text" id="fk-item-<?php echo esc_attr($i); ?>-title"
											name="<?php echo esc_attr(self::OPTION_KEY); ?>[items][<?php echo esc_attr($i); ?>][title]"
											value="<?php echo esc_attr($item['title']); ?>"></td>
								</tr>
								<tr>
									<th><label for="fk-item-<?php echo esc_attr($i); ?>-description">توضیحات</label></th>
									<td><input class="regular-text" id="fk-item-<?php echo esc_attr($i); ?>-description"
											name="<?php echo esc_attr(self::OPTION_KEY); ?>[items][<?php echo esc_attr($i); ?>][description]"
											value="<?php echo esc_attr($item['description']); ?>"></td>
								</tr>
								<tr>
									<th><label for="fk-item-<?php echo esc_attr($i); ?>-target">شناسه مقصد</label></th>
									<td><input class="regular-text" dir="ltr" id="fk-item-<?php echo esc_attr($i); ?>-target"
											name="<?php echo esc_attr(self::OPTION_KEY); ?>[items][<?php echo esc_attr($i); ?>][target_id]"
											value="<?php echo esc_attr($item['target_id']); ?>" placeholder="section-id">
										<p class="description">شناسه بخش مقصد، بدون علامت #.</p>
									</td>
								</tr>
								<tr>
									<th>رنگ‌ها</th>
									<td style="display:flex;gap:16px;flex-wrap:wrap">
					<?php foreach (['icon_color' => 'رنگ آیکن', 'badge_background' => 'پس‌زمینه badge', 'title_color' => 'رنگ عنوان', 'description_color' => 'رنگ توضیحات'] as $key => $label) : ?><label><?php echo esc_html($label); ?><br><input
													type="color"
													name="<?php echo esc_attr(self::OPTION_KEY); ?>[items][<?php echo esc_attr($i); ?>][<?php echo esc_attr($key); ?>]"
													value="<?php echo esc_attr($item[$key]); ?>"></label><?php endforeach; ?></td>
								</tr>
							</tbody>
						</table>
					</fieldset>
				<?php endfor; ?>
			<?php $styles = wp_parse_args($options['styles'] ?? [], $this->defaults()['styles']); ?>
			<fieldset style="border:1px solid #ccd0d4;padding:16px;margin:0 0 16px;max-width:850px;background:#fff">
				<legend style="font-weight:600;padding:0 8px">رنگ‌های وضعیت</legend>
				<table class="form-table" role="presentation"><tbody><tr><th>رنگ‌های وضعیت و badge</th><td style="display:flex;gap:16px;flex-wrap:wrap">
					<?php foreach (['normal_border' => 'حاشیه عادی', 'normal_background' => 'پس‌زمینه عادی', 'active_border' => 'حاشیه active', 'active_background' => 'پس‌زمینه active', 'hover_border' => 'حاشیه hover', 'hover_background' => 'پس‌زمینه hover'] as $key => $label) : ?><label><?php echo esc_html($label); ?><br><input type="color" name="<?php echo esc_attr(self::OPTION_KEY); ?>[styles][<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($styles[$key]); ?>"></label><?php endforeach; ?>
				</td></tr></tbody></table>
			</fieldset>
				<?php submit_button('ذخیره تنظیمات'); ?>
			</form>
		</div>
		<script>
			document.getElementById('fk-copy-shortcode')?.addEventListener('click', function() {
				navigator.clipboard.writeText(document.getElementById('fk-quickaccess-shortcode').textContent).then(
					function() {
						document.getElementById('fk-copy-status').textContent = 'کپی شد.';
					});
			});
		</script>
<?php
	}

	public function render_shortcode($atts): string
	{
		$this->enqueue_assets();
		$this->instance++;
		$id = 'fk-quickaccess-' . $this->instance;
		$raw_atts = is_array($atts) ? $atts : [];
		$atts = shortcode_atts(['sticky' => 'false'], $atts, self::SHORTCODE);
		$options = wp_parse_args(get_option(self::OPTION_KEY, []), $this->defaults());
		$styles = wp_parse_args($options['styles'] ?? [], $this->defaults()['styles']);
		$sticky = filter_var($atts['sticky'], FILTER_VALIDATE_BOOLEAN);
		foreach ($options['items'] as $index => &$item) {
			$item = wp_parse_args($item, $this->defaults()['items'][$index]);
			$number = $index + 1;
			$icon_attribute = 'item_' . $number . '_icon';
			if (isset($raw_atts[$icon_attribute])) {
				$item['icon'] = wp_kses((string) $raw_atts[$icon_attribute], $this->svg_allowed());
			}
			foreach (['title', 'description', 'target_id'] as $key) {
				$attribute = 'item_' . $number . '_' . $key;
				if (isset($raw_atts[$attribute])) {
					$item[$key] = 'target_id' === $key ? sanitize_title(ltrim((string) $raw_atts[$attribute], '#')) : sanitize_text_field($raw_atts[$attribute]);
				}
			}
			foreach (['icon_color', 'title_color', 'description_color'] as $key) {
				$attribute = 'item_' . $number . '_' . $key;
				if (isset($raw_atts[$attribute])) {
					$item[$key] = sanitize_hex_color($raw_atts[$attribute]) ?: $item[$key];
				}
			}
		}
		unset($item);
		$style_attribute = sprintf('--fk-normal-border:%s;--fk-normal-bg:%s;--fk-active-border:%s;--fk-active-bg:%s;--fk-hover-border:%s;--fk-hover-bg:%s;', esc_attr($styles['normal_border']), esc_attr($styles['normal_background']), esc_attr($styles['active_border']), esc_attr($styles['active_background']), esc_attr($styles['hover_border']), esc_attr($styles['hover_background']));
		$html = '<div id="' . esc_attr($id) . '" dir="rtl" style="' . $style_attribute . '" class="fk-quickaccess-wrap' . ($sticky ? ' fk-quickaccess-wrap--sticky' : '') . '"><nav class="fk-quickaccess" aria-label="دسترسی سریع"><div class="fk-quickaccess__grid">';
		foreach ($options['items'] as $item) {
			$target = sanitize_title($item['target_id'] ?? '');
			$tag = $target ? 'a' : 'div';
			$attrs = $target ? ' href="#' . esc_attr($target) . '" data-fk-target="' . esc_attr($target) . '"' : '';
			$html .= '<' . $tag . $attrs . ' class="fk-quickaccess__item">';
			$html .= '<span class="fk-quickaccess__badge" style="color:' . esc_attr($item['icon_color']) . ';--fk-badge-bg:' . esc_attr($item['badge_background']) . '"><span class="fk-quickaccess__icon">' . wp_kses($item['icon'], $this->svg_allowed()) . '</span></span>';
			$html .= '<span class="fk-quickaccess__copy"><span class="fk-quickaccess__title" style="color:' . esc_attr($item['title_color']) . '">' . esc_html($item['title']) . '</span>';
			$html .= '<span class="fk-quickaccess__description" style="color:' . esc_attr($item['description_color']) . '">' . esc_html($item['description']) . '</span></span>';
			$html .= '</' . $tag . '>';
		}
		$html .= '</div></nav>';
		$html .= '</div>';
		$html .= '<script src="' . esc_url(plugin_dir_url(__FILE__) . 'assets/quickaccess.js?ver=1.0.40') . '"></script>';
		return $html;
	}

	public function enqueue_assets(): void
	{
		wp_enqueue_style('fk-quickaccess', plugin_dir_url(__FILE__) . 'assets/quickaccess.css', [], '1.0.40');
		wp_enqueue_style('fk-quickaccess-zindex', plugin_dir_url(__FILE__) . 'assets/quickaccess-zindex.css', ['fk-quickaccess'], '1.0.40');
		wp_enqueue_script('fk-quickaccess', plugin_dir_url(__FILE__) . 'assets/quickaccess.js', [], '1.0.40', true);
	}
}

new FK_Quick_Access_Plugin();
