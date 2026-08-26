<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FCP_Plugin {
	const OPTION_KEY = 'fcp_settings';
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_shortcode( 'fanikara_price_calculator', array( $this, 'render_shortcode' ) );
		add_shortcode( 'fcp_price_calculator', array( $this, 'render_shortcode' ) );
	}

	public static function activate() {
		if ( false === get_option( self::OPTION_KEY ) ) {
			add_option( self::OPTION_KEY, self::defaults() );
		}
	}

	public static function defaults() {
		return array(
			'masonry_cost' => 'هزینه بنایی به عوامل زیادی بستگی دارد و با توجه به اجرت استاد بنا، کارگر، مصالح و ... حدودا بین 17 تا 80 میلیون تومان است.',
			'negotiable_price' => 'در شرایط بالا، هزینه کار توافقی است.',
			'spring_cost_per_meter' => 100000,
			'service_cost_per_minute_per_person' => 10000,
			'weekday_normal_hours_multiplier' => 1,
			'weekday_night_service_multiplier' => 1.4,
			'holiday_normal_hours_multiplier' => 1.3,
			'holiday_night_service_multiplier' => 1.82,
			'vat_rate' => 10,
			'colors' => array(
				'card_background' => '#ffffff',
				'base_color' => '#f9fafb',
				'primary_color' => '#171717',
				'text_color' => '#111827',
				'label_color' => '#374151',
				'muted_color' => '#6b7280',
				'placeholder_color' => '#9ca3af',
				'border_color' => '#e5e7eb',
				'input_border_color' => '#d1d5db',
				'hover_color' => '#FE7F2E',
				'price_color' => '#166534',
				'warning_background' => '#fffbeb',
				'warning_border' => '#fde68a',
				'warning_text' => '#92400e',
				'success_background' => '#f0fdf4',
				'success_border' => '#bbf7d0',
				'success_text' => '#166534',
			),
		);
	}

	public function get_settings() {
		$saved = get_option( self::OPTION_KEY, array() );
		$settings = wp_parse_args( $saved, self::defaults() );
		$settings['colors'] = wp_parse_args( isset( $saved['colors'] ) && is_array( $saved['colors'] ) ? $saved['colors'] : array(), self::defaults()['colors'] );
		return $settings;
	}

	public function admin_menu() {
		add_options_page( 'قیمت‌گذاری لوله‌بازکنی', 'قیمت‌گذاری لوله‌بازکنی', 'manage_options', 'fcp-settings', array( $this, 'settings_page' ) );
	}

	public function register_settings() {
		register_setting( 'fcp_settings_group', self::OPTION_KEY, array( 'type' => 'array', 'sanitize_callback' => array( $this, 'sanitize_settings' ), 'default' => self::defaults() ) );
	}

	public function sanitize_settings( $input ) {
		$defaults = self::defaults();
		$clean = $defaults;
		$clean['masonry_cost'] = isset( $input['masonry_cost'] ) ? sanitize_textarea_field( $input['masonry_cost'] ) : $defaults['masonry_cost'];
		$clean['negotiable_price'] = isset( $input['negotiable_price'] ) ? sanitize_textarea_field( $input['negotiable_price'] ) : $defaults['negotiable_price'];
		foreach ( array( 'spring_cost_per_meter', 'service_cost_per_minute_per_person', 'weekday_normal_hours_multiplier', 'weekday_night_service_multiplier', 'holiday_normal_hours_multiplier', 'holiday_night_service_multiplier', 'vat_rate' ) as $key ) {
			$clean[ $key ] = isset( $input[ $key ] ) ? $this->decimal_value( $input[ $key ] ) : $defaults[ $key ];
		}
		foreach ( $defaults['colors'] as $key => $default ) {
			$color = isset( $input['colors'][ $key ] ) ? sanitize_hex_color( $input['colors'][ $key ] ) : false;
			$clean['colors'][ $key ] = $color ? $color : $default;
		}
		return $clean;
	}

	private function decimal_value( $value ) {
		$value = strtr( (string) $value, array( '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٫' => '.', ',' => '' ) );
		return max( 0, (float) preg_replace( '/[^0-9.]/', '', $value ) );
	}

	public function settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = $this->get_settings();
		?>
		<div class="wrap" dir="rtl">
			<h1>تنظیمات فرمول قیمت‌گذاری لوله‌بازکنی</h1>
			<p>مقادیر این صفحه مستقیماً در فرمول فایل نمونه استفاده می‌شوند. مبالغ به تومان هستند.</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'fcp_settings_group' ); ?>
				<h2>متن خروجی‌های ویژه</h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="fcp-masonry-cost">هزینه بنایی</label></th><td><textarea class="large-text" rows="3" id="fcp-masonry-cost" name="fcp_settings[masonry_cost]"><?php echo esc_textarea( $settings['masonry_cost'] ); ?></textarea></td></tr>
					<tr><th scope="row"><label for="fcp-negotiable-price">هزینه توافقی</label></th><td><textarea class="large-text" rows="2" id="fcp-negotiable-price" name="fcp_settings[negotiable_price]"><?php echo esc_textarea( $settings['negotiable_price'] ); ?></textarea></td></tr>
				</table>
				<h2>ثابت‌های فرمول</h2>
				<table class="form-table" role="presentation">
					<?php $this->settings_number_row( 'spring_cost_per_meter', 'هزینه فنر به ازای هر متر', $settings['spring_cost_per_meter'], 'تومان' ); ?>
					<?php $this->settings_number_row( 'service_cost_per_minute_per_person', 'هزینه هر دقیقه خدمت برای هر نفر', $settings['service_cost_per_minute_per_person'], 'تومان' ); ?>
					<?php $this->settings_number_row( 'weekday_normal_hours_multiplier', 'ضریب روز عادی / ساعات عادی', $settings['weekday_normal_hours_multiplier'], '' ); ?>
					<?php $this->settings_number_row( 'weekday_night_service_multiplier', 'ضریب روز عادی / سرویس شبانه', $settings['weekday_night_service_multiplier'], '' ); ?>
					<?php $this->settings_number_row( 'holiday_normal_hours_multiplier', 'ضریب روز تعطیل / ساعات عادی', $settings['holiday_normal_hours_multiplier'], '' ); ?>
					<?php $this->settings_number_row( 'holiday_night_service_multiplier', 'ضریب روز تعطیل / سرویس شبانه', $settings['holiday_night_service_multiplier'], '' ); ?>
					<?php $this->settings_number_row( 'vat_rate', 'نرخ مالیات بر ارزش افزوده', $settings['vat_rate'], 'درصد' ); ?>
				</table>
				<h2>رنگ‌بندی ابزار</h2>
				<p>رنگ‌ها فقط روی محاسبه‌گر اثر می‌گذارند و رنگ قالب وردپرس را تغییر نمی‌دهند.</p>
				<table class="form-table" role="presentation">
					<?php
					$color_labels = array(
						'card_background' => 'پس‌زمینه کارت ابزار',
						'base_color' => 'رنگ پایه بخش‌ها',
						'primary_color' => 'رنگ اصلی آیکون‌ها',
						'text_color' => 'رنگ متن اصلی',
						'label_color' => 'رنگ برچسب فیلدها',
						'muted_color' => 'رنگ متن توضیحات',
						'placeholder_color' => 'رنگ placeholder',
						'border_color' => 'رنگ بوردرها',
						'input_border_color' => 'رنگ بوردر ورودی‌ها',
						'hover_color' => 'رنگ hover و حالت فعال',
						'price_color' => 'رنگ بخش قیمت',
						'warning_background' => 'پس‌زمینه هشدار',
						'warning_border' => 'بوردر هشدار',
						'warning_text' => 'متن هشدار',
						'success_background' => 'پس‌زمینه موفقیت',
						'success_border' => 'بوردر موفقیت',
						'success_text' => 'متن موفقیت',
					);
					foreach ( $color_labels as $key => $label ) {
						$this->settings_color_row( $key, $label, $settings['colors'][ $key ] );
					}
					?>
				</table>
				<?php submit_button( 'ذخیره تنظیمات' ); ?>
			</form>
			<hr><p><strong>شورت‌کد:</strong> <code>[fanikara_price_calculator]</code></p>
		</div>
		<?php
	}

	private function settings_number_row( $key, $label, $value, $unit ) {
		printf( '<tr><th scope="row"><label for="fcp-%1$s">%2$s</label></th><td><input type="number" min="0" step="any" class="regular-text" id="fcp-%1$s" name="fcp_settings[%1$s]" value="%3$s"> %4$s</td></tr>', esc_attr( $key ), esc_html( $label ), esc_attr( $value ), esc_html( $unit ) );
	}

	private function settings_color_row( $key, $label, $value ) {
		printf( '<tr><th scope="row"><label for="fcp-color-%1$s">%2$s</label></th><td><input type="color" id="fcp-color-%1$s" name="fcp_settings[colors][%1$s]" value="%3$s"> <code>%3$s</code></td></tr>', esc_attr( $key ), esc_html( $label ), esc_attr( $value ) );
	}

	public function render_shortcode() {
		$settings = $this->get_settings();
		$this->enqueue_assets( $settings );
		$instance = wp_unique_id( 'fcp-' );
		ob_start();
		?>
		<section class="fcp-calculator" dir="rtl" aria-labelledby="<?php echo esc_attr( $instance . 'title' ); ?>">
			<header class="fcp-calculator-header"><div class="fcp-header-icon" aria-hidden="true"><?php echo $this->icon_svg( 'calculator' ); ?></div><div><h2 id="<?php echo esc_attr( $instance . 'title' ); ?>">محاسبه حدود قیمت لوله بازکنی</h2><p>مقادیر اولیه فرم بر اساس رایج ترین حالت های پایه در نظر گرفته شده اند.</p></div></header>
			<form class="fcp-price-form" novalidate>
				<div class="fcp-fields-grid">
					<?php $this->select_field( $instance . 'city', 'شهر', 'tehran', array( 'tehran' => 'تهران', 'karaj' => 'کرج', 'mashhad' => 'مشهد', 'isfahan' => 'اصفهان', 'qom' => 'قم', 'tabriz' => 'تبریز', 'other' => 'سایر' ), 'city', 'location', true, 'لطفاً یکی از گزینه‌های شهر خود را انتخاب کنید؛ مثلاً تهران.' ); ?>
					<?php $this->select_field( $instance . 'service-type', 'نوع خدمت', 'iranian_toilet', array( 'iranian_toilet' => 'لوله بازکنی توالت ایرانی', 'western_toilet' => 'لوله بازکنی توالت فرنگی', 'bathroom_floor_drain' => 'لوله بازکنی کفشور حمام', 'kitchen_floor_drain' => 'لوله بازکنی کفشور آشپزخانه', 'roof' => 'لوله بازکنی پشت بام', 'kitchen_sink' => 'لوله بازکنی سینک آشپزخانه', 'construction_excavation' => 'بنایی و کنده‌کاری', 'other' => 'سایر' ), 'service_type', 'tool', true, 'لطفاً خدمت موردنظر را انتخاب کنید؛ مثلاً لوله بازکنی توالت ایرانی.' ); ?>
					<?php $this->numeric_field( $instance . 'spring-length', 'متراژ فنر', 'spring_length', '2', 'متراژ فنر را فقط به‌صورت عدد وارد کنید؛ مثلاً ۲ متر.', 'ruler' ); ?>
					<?php $this->select_field( $instance . 'spring-diameter', 'قطر فنر', '12', array( '12' => '12', '14' => '14', '16' => '16', '18' => '18', '22' => '22' ), 'spring_diameter', 'pipe', false, 'بر اساس اندازه های استاندارد انتخاب کنید' ); ?>
					<?php $this->numeric_field( $instance . 'service-duration', 'مدت زمان ارائه خدمات بر اساس دقیقه', 'service_duration', '20', 'مدت زمان را فقط به‌صورت عدد وارد کنید؛ مثلاً ۳۰.', 'clock' ); ?>
					<?php $this->select_field( $instance . 'problem-cause', 'علت مشکل', 'fecal_blockage', array( 'fecal_blockage' => 'گرفتگی با مدفوع', 'soft_spongy_objects' => 'گرفتگی به دلیل افتادن اجسام غیر سخت', 'valuable_object_retrieval' => 'درآوردن اجسام ارزشمند از چاه (مثل طلا، موبایل، اسناد و مدارک و …)', 'severe_sediment' => 'رسوب گرفتگی شدید', 'hard_object' => 'افتادن اجسام سخت در لوله', 'construction_materials' => 'ریختن مصالح ساختمانی مثل قیر و سیمان و …' ), 'problem_cause', 'warning', false, 'تنها در صورتی که علت بروز مشکل را می‌دانید، انتخاب کنید.' ); ?>
					<?php $this->select_field( $instance . 'service-time', 'زمان ارائه خدمات', '1', array( '1' => 'روزهای عادی - ساعات عادی', '1.4' => 'روز عادی - سرویس‌دهی شبانه', '1.3' => 'روز تعطیل - ساعات عادی', '1.82' => 'روز تعطیل - سرویس‌دهی شبانه' ), 'service_time', 'calendar', false, 'بر اساس تقویم رسمی' ); ?>
					<?php $this->numeric_field( $instance . 'technician-count', 'تعداد فنی‌کار', 'technician_count', '1', 'تعداد فنی‌کار را فقط به‌صورت عدد وارد کنید؛ مثلاً ۲.', 'users' ); ?>
					<div class="fcp-field fcp-field-full"><?php $this->checkbox_field( $instance . 'valid-invoice', 'valid_invoice', 'ارائه فاکتور معتبر', 'این مورد رایگان است و فنی‌کارها موظف به ارائه فاکتور با نام، شماره و امضای خود هستند.', true, 'receipt' ); ?></div>
				</div>

				<section class="fcp-special-toggle"><button class="fcp-special-toggle-button" type="button" aria-expanded="false"><span>موارد خاص</span><span class="fcp-special-toggle-icon" aria-hidden="true">⌄</span></button><div class="fcp-special-toggle-content" hidden><div class="fcp-special-warning">این موارد فقط در بعضی خدمات و شرایط خاص روی هزینه نهایی اثر می‌گذارند.</div><div class="fcp-fields-grid">
					<div class="fcp-field fcp-field-full"><?php $this->checkbox_field( $instance . 'official-invoice', 'official_invoice_with_vat', 'ارائه فاکتور شرکتی رسمی با مهر و ارزش افزوده', 'در صورت نیاز به فاکتور شرکتی، حتماً قبل از اعزام سرویس‌کار به ما اطلاع دهید. (تلفنی)', false, 'receipt' ); ?></div>
					<div class="fcp-field fcp-field-full"><?php $this->checkbox_field( $instance . 'out-of-city', 'out_of_city', 'خارج از شهر', 'فقط اگر خارج از شهر هستید، این گزینه را فعال کنید.', false, 'location' ); ?></div>
					<?php $this->money_field( $instance . 'transportation-cost', 'هزینه ایاب و ذهاب (برای خارج از شهر)', 'transportation_cost', 'هزینه ایاب و ذهاب را فقط به‌صورت عدد وارد کنید؛ مثلاً ۳۰۰,۰۰۰.', 'حمل‌ونقل', 'car' ); ?>
					<?php $this->money_field( $instance . 'tip', 'انعام', 'tip', 'مبلغ انعام را فقط به‌صورت عدد وارد کنید؛ مثلاً ۳۰۰,۰۰۰.', 'مبلغ به تومان', 'gift' ); ?>
					<?php $this->select_field( $instance . 'equipment-used', 'تجهیزات استفاده‌شده', 'normal_spring', array( 'normal_spring' => 'فنر معمولی', 'high_pressure_spring' => 'دستگاه فنر برقی فشار قوی', 'air_compression_pump' => 'پمپ تراکم هوا معمولی', 'advanced_air_compression_pump' => 'پمپ تراکم هوا پیشرفته', 'waterjet' => 'واترجت' ), 'equipment_used', 'tool', false, 'تجهیزات استفاده‌شده را انتخاب کنید؛ مثلاً فنر معمولی.' ); ?>
					<?php $this->money_field( $instance . 'equipment-rental-cost', 'مبلغ اجاره تجهیزات', 'equipment_rental_cost', '', 'مبلغ به تومان', 'wrench' ); ?>
					<?php $this->money_field( $instance . 'equipment-transport-cost', 'کرایه حمل تجهیزات', 'equipment_transport_cost', '', 'مبلغ به تومان', 'car' ); ?>
				</div></div></section>
				<div class="fcp-price-preview" aria-live="polite" aria-atomic="true"><span class="fcp-price-preview-label">هزینه تخمینی</span><strong class="fcp-price-preview-value">حدودا بین a تا b تومان</strong></div>
			</form>
		</section>
		<?php
		return ob_get_clean();
	}

	private function select_field( $id, $label, $selected, $options, $field_name, $icon, $required, $description ) {
		?>
		<div class="fcp-field"><label class="fcp-field-label" for="<?php echo esc_attr( $id ); ?>"><span class="fcp-label-icon" aria-hidden="true"><?php echo $this->icon_svg( $icon ); ?></span><?php echo esc_html( $label ); ?><?php if ( $required ) : ?><span class="fcp-required" aria-hidden="true">*</span><?php endif; ?></label><span class="fcp-field-description"><?php echo esc_html( $description ); ?></span><div class="fcp-select-wrap"><select id="<?php echo esc_attr( $id ); ?>" data-fcp-field="<?php echo esc_attr( $field_name ); ?>" <?php echo $required ? 'required' : ''; ?>><?php foreach ( $options as $value => $text ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $selected, $value ); ?>><?php echo esc_html( $text ); ?></option><?php endforeach; ?></select><span class="fcp-chevron" aria-hidden="true">⌄</span></div></div>
		<?php
	}

	private function numeric_field( $id, $label, $field_name, $value, $description, $icon ) {
		?>
		<div class="fcp-field"><label class="fcp-field-label" for="<?php echo esc_attr( $id ); ?>"><span class="fcp-label-icon" aria-hidden="true"><?php echo $this->icon_svg( $icon ); ?></span><?php echo esc_html( $label ); ?></label><span class="fcp-field-description"><?php echo esc_html( $description ); ?></span><input class="fcp-text-input" id="<?php echo esc_attr( $id ); ?>" data-fcp-field="<?php echo esc_attr( $field_name ); ?>" data-numeric-input type="text" inputmode="numeric" autocomplete="off" value="<?php echo esc_attr( $value ); ?>"></div>
		<?php
	}

	private function money_field( $id, $label, $field_name, $description, $placeholder, $icon ) {
		?>
		<div class="fcp-field"><label class="fcp-field-label" for="<?php echo esc_attr( $id ); ?>"><span class="fcp-label-icon" aria-hidden="true"><?php echo $this->icon_svg( $icon ); ?></span><?php echo esc_html( $label ); ?></label><?php if ( $description ) : ?><span class="fcp-field-description"><?php echo esc_html( $description ); ?></span><?php endif; ?><input class="fcp-text-input" id="<?php echo esc_attr( $id ); ?>" data-fcp-field="<?php echo esc_attr( $field_name ); ?>" data-numeric-input data-money-input type="text" inputmode="numeric" autocomplete="off" placeholder="<?php echo esc_attr( $placeholder ); ?>"></div>
		<?php
	}

	private function checkbox_field( $id, $field_name, $label, $description, $checked, $icon ) {
		?>
		<div class="fcp-checkbox-card"><input id="<?php echo esc_attr( $id ); ?>" data-fcp-field="<?php echo esc_attr( $field_name ); ?>" type="checkbox" value="1" <?php checked( $checked, true ); ?>><div class="fcp-checkbox-content"><label class="fcp-checkbox-title" for="<?php echo esc_attr( $id ); ?>"><span class="fcp-label-icon" aria-hidden="true"><?php echo $this->icon_svg( $icon ); ?></span><?php echo esc_html( $label ); ?></label><label class="fcp-checkbox-description" for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $description ); ?></label></div></div>
		<?php
	}

	private function icon_svg( $name ) {
		$paths = array(
			'calculator' => '<rect x="11" y="7" width="26" height="34" rx="3"/><rect x="16" y="12" width="16" height="7" rx="1"/><path d="M17 26h3M24 26h3M31 26h0M17 32h3M24 32h3M31 32h0M17 38h3M24 38h3M31 38h0"/>',
			'location' => '<path d="M24 40s10-9.2 10-18A10 10 0 1 0 14 22c0 8.8 10 18 10 18Z"/><circle cx="24" cy="22" r="3"/>',
			'tool' => '<path d="m28 10 10 10-5 5-10-10M13 35l11-11M9 39l7-7M9 13h8l4 4-4 4H9z"/>',
			'ruler' => '<path d="m12 34 22-22 4 4-22 22-4-4Z"/><path d="m18 28 3 3M22 24l3 3M26 20l3 3M30 16l3 3"/>',
			'pipe' => '<path d="M12 11v9a5 5 0 0 0 5 5h14a5 5 0 0 1 5 5v7M12 11h8M36 37h-8"/>',
			'clock' => '<circle cx="24" cy="24" r="15"/><path d="M24 15v10l7 4"/>',
			'warning' => '<path d="M24 8 40 38H8L24 8Z"/><path d="M24 18v9M24 32h.01"/>',
			'calendar' => '<rect x="10" y="12" width="28" height="27" rx="3"/><path d="M16 8v8M32 8v8M10 20h28M17 27h.01M24 27h.01M31 27h.01M17 33h.01M24 33h.01"/>',
			'users' => '<circle cx="19" cy="18" r="5"/><path d="M10 37c0-6 4-9 9-9s9 3 9 9M31 16a4 4 0 0 1 0 8M31 28c4 0 7 3 7 8"/>',
			'receipt' => '<path d="M14 8h20v32l-4-3-4 3-4-3-4 3-4-3V8Z"/><path d="M19 16h10M19 22h10M19 28h6"/>',
			'car' => '<path d="m10 29 3-10h22l3 10v8H10v-8Z"/><path d="M14 19 17 12h14l3 7M15 33h.01M33 33h.01M10 27h28"/>',
			'gift' => '<path d="M9 20h30v20H9zM7 14h34v6H7zM24 14v26M24 14H16a4 4 0 1 1 4-4c0 4 4 4 4 4ZM24 14h8a4 4 0 1 0-4-4c0 4-4 4-4 4Z"/>',
			'wrench' => '<path d="m29 11 8 8-5 5-8-8a9 9 0 1 1-5 5l8 8-5 5-8-8 5-5a9 9 0 0 1 10-10Z"/>',
		);
		return '<svg class="fcp-icon" viewBox="0 0 48 48" focusable="false">' . ( isset( $paths[ $name ] ) ? $paths[ $name ] : '' ) . '</svg>';
	}

	private function enqueue_assets( $settings ) {
		static $enqueued = false;
		if ( $enqueued ) {
			return;
		}
		$enqueued = true;
		$css = FCP_DIR . 'assets/css/calculator.css';
		$js = FCP_DIR . 'assets/js/calculator.js';
		$css_version = FCP_VERSION . '-' . ( file_exists( $css ) ? filemtime( $css ) : '0' );
		$js_version  = FCP_VERSION . '-' . ( file_exists( $js ) ? filemtime( $js ) : '0' );
		wp_enqueue_style( 'fcp-calculator', FCP_URL . 'assets/css/calculator.css', array(), $css_version );
		wp_enqueue_script( 'fcp-calculator', FCP_URL . 'assets/js/calculator.js', array(), $js_version, true );
		$color_map = array(
			'card_background' => 'card-bg',
			'base_color' => 'base',
			'primary_color' => 'primary',
			'text_color' => 'text',
			'label_color' => 'label',
			'muted_color' => 'muted',
			'placeholder_color' => 'placeholder',
			'border_color' => 'border',
			'input_border_color' => 'input-border',
			'hover_color' => 'hover',
			'price_color' => 'price',
			'warning_background' => 'warning-bg',
			'warning_border' => 'warning-border',
			'warning_text' => 'warning-text',
			'success_background' => 'success-bg',
			'success_border' => 'success-border',
			'success_text' => 'success-text',
		);
		$inline_colors = '.fcp-calculator{';
		foreach ( $color_map as $setting_key => $css_key ) {
			$color = sanitize_hex_color( $settings['colors'][ $setting_key ] );
			$inline_colors .= '--fcp-' . $css_key . ':' . ( $color ? $color : self::defaults()['colors'][ $setting_key ] ) . ';';
		}
		$inline_colors .= '}';
		wp_add_inline_style( 'fcp-calculator', $inline_colors );
		wp_localize_script( 'fcp-calculator', 'FCP_DATA', array(
			'masonryCost' => $settings['masonry_cost'],
			'negotiablePrice' => $settings['negotiable_price'],
			'springCostPerMeter' => (float) $settings['spring_cost_per_meter'],
			'serviceCostPerMinutePerPerson' => (float) $settings['service_cost_per_minute_per_person'],
			'serviceTimeValues' => array( (float) $settings['weekday_normal_hours_multiplier'], (float) $settings['weekday_night_service_multiplier'], (float) $settings['holiday_normal_hours_multiplier'], (float) $settings['holiday_night_service_multiplier'] ),
			'vatRate' => (float) $settings['vat_rate'],
		) );
	}
}

register_activation_hook( FCP_FILE, array( 'FCP_Plugin', 'activate' ) );
