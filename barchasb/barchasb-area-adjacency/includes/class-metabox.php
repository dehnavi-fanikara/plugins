<?php
/**
 * Admin relationship metabox and secure AJAX search.
 *
 * @package Barchasb\AreaAdjacency
 */

namespace Barchasb\AreaAdjacency;

defined('ABSPATH') || exit;

final class Metabox {
	private const META_KEY = '_baa_adjacent_post_ids';
	private Settings $settings;
	private Post_Types $post_types;

	public function __construct(Settings $settings, Post_Types $post_types) {
		$this->settings = $settings;
		$this->post_types = $post_types;
		add_action('add_meta_boxes', array($this, 'add_metabox'));
		add_action('save_post', array($this, 'save'), 10, 2);
		add_action('admin_enqueue_scripts', array($this, 'enqueue'));
		add_action('wp_ajax_baa_search_posts', array($this, 'ajax_search'));
		add_action('init', array($this, 'register_meta'), 20);
	}

	public function add_metabox(): void {
		foreach ($this->post_types->active() as $post_type) {
			add_meta_box('baa_adjacent_areas_metabox', 'مناطق نزدیک و مرتبط', array($this, 'render'), $post_type, 'normal', 'default');
		}
	}

	public function enqueue(string $hook): void {
		if (! in_array($hook, array('post.php', 'post-new.php'), true)) { return; }
		$screen = get_current_screen(); if (! $screen || ! $this->post_types->is_active($screen->post_type)) { return; }
		$post_id = isset($_GET['post']) ? absint($_GET['post']) : 0;
		if (! $post_id && isset($GLOBALS['post']->ID)) { $post_id = absint($GLOBALS['post']->ID); }
		$can_edit = $post_id ? current_user_can('edit_post', $post_id) : current_user_can('edit_posts');
		if (! $can_edit) { return; }
		wp_enqueue_style('baa-admin', BAA_URL . 'assets/css/admin.css', array(), BAA_VERSION);
		wp_enqueue_script('baa-admin', BAA_URL . 'assets/js/admin.js', array(), BAA_VERSION, true);
		wp_localize_script('baa-admin', 'BAAAdmin', array('ajaxUrl' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('baa_admin'), 'sourceId' => $post_id, 'canManage' => current_user_can($this->settings->get()['admin_capability']), 'i18n' => array('searching' => 'در حال جست‌وجو…', 'minChars' => 'حداقل ۲ کاراکتر وارد کنید.', 'noResults' => 'نتیجه‌ای پیدا نشد.', 'add' => 'افزودن', 'remove' => 'حذف', 'up' => 'انتقال به بالا', 'down' => 'انتقال به پایین')));
	}

	public function render(\WP_Post $post): void {
		$settings = $this->settings->get(); $can_manage = current_user_can('edit_post', $post->ID) && current_user_can((string) $settings['admin_capability']); $readonly = ! $can_manage && ! empty($settings['read_only_without_capability']);
		$ids = $this->get_ids($post->ID); $target_types = $this->post_types->active();
		wp_nonce_field('baa_save_adjacent', 'baa_adjacent_nonce');
		?><p class="description">مناطقی را انتخاب کنید که از نظر محتوایی یا جغرافیایی به صفحه جاری نزدیک هستند. این موارد در محل قرارگیری شورت‌کد، به‌صورت اسلایدی برای بازدیدکنندگان نمایش داده می‌شوند.</p>
		<?php if (! $can_manage && ! $readonly) : ?><p class="notice inline notice-warning">شما دسترسی مدیریت روابط این پست را ندارید.</p><?php return; endif; ?>
		<div class="baa-metabox" data-readonly="<?php echo esc_attr($readonly ? '1' : '0'); ?>" data-source-id="<?php echo esc_attr((string) $post->ID); ?>">
			<?php if ($readonly) : ?><p class="description">این فهرست فقط‌خواندنی است.</p><?php endif; ?>
			<div class="baa-search-row"><label for="baa-target-type-<?php echo esc_attr((string) $post->ID); ?>">Post Type مقصد</label><select class="baa-target-type" id="baa-target-type-<?php echo esc_attr((string) $post->ID); ?>" <?php disabled($readonly); ?>><?php foreach ($target_types as $type) { printf('<option value="%1$s">%2$s</option>', esc_attr($type), esc_html($this->target_label($type))); } ?></select><label class="screen-reader-text" for="baa-search-<?php echo esc_attr((string) $post->ID); ?>">جست‌وجوی مناطق</label><input class="baa-search-input" id="baa-search-<?php echo esc_attr((string) $post->ID); ?>" type="search" placeholder="جست‌وجو در عنوان یا ID…" <?php disabled($readonly); ?>></div>
			<div class="baa-search-status" aria-live="polite"></div><div class="baa-search-results" role="listbox"></div>
			<h4>موارد انتخاب‌شده</h4><p class="description">با کشیدن و رها کردن یا دکمه‌های دسترسی، ترتیب نمایش را تغییر دهید.</p><ol class="baa-selected-list" aria-label="مناطق انتخاب‌شده"><?php foreach ($ids as $id) { $this->render_selected($id, $readonly); } ?></ol>
			<input type="hidden" class="baa-source-id" value="<?php echo esc_attr((string) $post->ID); ?>"><input type="hidden" class="baa-selected-json" name="baa_adjacent_post_ids" value="<?php echo esc_attr(wp_json_encode($ids)); ?>">
		</div><?php
	}

	private function render_selected(int $id, bool $readonly): void {
		$item = get_post($id); if (! $item) { echo '<li class="baa-selected-item baa-item-invalid">آیتم حذف‌شده (ID: ' . esc_html((string) $id) . ') <button type="button" class="button-link baa-remove-item">حذف</button></li>'; return; }
		$title = get_the_title($item) ?: '(بدون عنوان)'; $thumb = get_the_post_thumbnail($item, array(40, 40), array('class' => 'baa-item-thumb', 'loading' => 'lazy', 'alt' => $title)); $status = get_post_status_object($item->post_status); $status_label = $status ? $status->label : $item->post_status;
		?><li class="baa-selected-item" draggable="<?php echo esc_attr($readonly ? 'false' : 'true'); ?>" data-id="<?php echo esc_attr((string) $item->ID); ?>"><span class="baa-drag-handle" aria-hidden="true">⠿</span><?php echo $thumb ? wp_kses_post($thumb) : ''; ?><span class="baa-item-name"><?php echo esc_html($title); ?> <small>(<?php echo esc_html($this->target_label($item->post_type) . ' · ' . $status_label); ?>)</small></span><?php if (current_user_can('edit_post', $item->ID)) : ?><a href="<?php echo esc_url(get_edit_post_link($item->ID, '')); ?>" target="_blank" rel="noopener">ویرایش</a><?php endif; ?><button type="button" class="button-link baa-move-up" <?php disabled($readonly); ?>>بالا</button><button type="button" class="button-link baa-move-down" <?php disabled($readonly); ?>>پایین</button><button type="button" class="button-link-delete baa-remove-item" <?php disabled($readonly); ?>>حذف</button></li><?php
	}

	/**
	 * Return a friendly label for the built-in area post types.
	 *
	 * @param string $post_type Post type name.
	 * @return string
	 */
	private function target_label(string $post_type): string {
		$labels = array(
			'tehran_state' => 'تهران',
			'karaj_state' => 'کرج',
		);

		if (isset($labels[$post_type])) {
			return $labels[$post_type];
		}

		$object = get_post_type_object($post_type);
		return $object ? $object->labels->singular_name : $post_type;
	}

	public function ajax_search(): void {
		check_ajax_referer('baa_admin', 'nonce'); if (! is_user_logged_in()) { wp_send_json_error(array('message' => 'احراز هویت نامعتبر است.'), 401); }
		$source_id = isset($_POST['source_id']) ? absint($_POST['source_id']) : 0;
		if (! $source_id && isset($_POST['post_id'])) { $source_id = absint($_POST['post_id']); }
		$source = get_post($source_id);
		if (! $source || ! $this->post_types->is_active($source->post_type) || in_array($source->post_status, array('trash', 'auto-draft'), true) || ! current_user_can('edit_post', $source_id)) { wp_send_json_error(array('message' => 'دسترسی یا پست مبدأ نامعتبر است.'), 403); }
		$settings = $this->settings->get(); if (! current_user_can((string) $settings['admin_capability'])) { wp_send_json_error(array('message' => 'دسترسی مدیریت روابط را ندارید.'), 403); }
		$type = isset($_POST['post_type']) ? sanitize_key(wp_unslash($_POST['post_type'])) : ''; if (! $this->post_types->is_active($type)) { wp_send_json_error(array('message' => 'Post Type نامعتبر است.'), 400); }
		$term = isset($_POST['term']) ? sanitize_text_field(wp_unslash($_POST['term'])) : ''; $term_length = function_exists('mb_strlen') ? mb_strlen($term) : strlen($term); if ($term_length < 2) { wp_send_json_success(array('items' => array(), 'totalPages' => 0)); }
		$page = max(1, isset($_POST['page']) ? absint($_POST['page']) : 1); $args = array('post_type' => $type, 'post_status' => array('publish', 'future', 'draft', 'pending', 'private'), 'posts_per_page' => 20, 'paged' => $page, 's' => $term, 'post__not_in' => array($source_id), 'orderby' => 'title', 'order' => 'ASC', 'no_found_rows' => false);
		if (ctype_digit($term)) { $args['post__in'] = array_filter(array(absint($term), $source_id)); unset($args['s']); }
		$query = new \WP_Query($args); $items = array(); foreach ($query->posts as $item) { if (! current_user_can('read_post', $item->ID) && 'publish' !== $item->post_status) { continue; } $status_object = get_post_status_object($item->post_status); $items[] = array('id' => $item->ID, 'title' => get_the_title($item) ?: '(بدون عنوان)', 'postType' => $this->target_label($item->post_type), 'status' => $status_object ? $status_object->label : $item->post_status, 'editUrl' => current_user_can('edit_post', $item->ID) ? get_edit_post_link($item->ID, '') : '', 'thumbnail' => get_the_post_thumbnail_url($item, array(40, 40)) ?: ''); } wp_reset_postdata(); wp_send_json_success(array('items' => $items, 'page' => $page, 'totalPages' => (int) $query->max_num_pages));
	}

	public function save(int $post_id, \WP_Post $post): void {
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) { return; } if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) { return; }
		if (! $this->post_types->is_active($post->post_type) || ! current_user_can('edit_post', $post_id)) { return; } $settings = $this->settings->get(); if (! current_user_can((string) $settings['admin_capability'])) { return; }
		if (! isset($_POST['baa_adjacent_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['baa_adjacent_nonce'])), 'baa_save_adjacent')) { return; }
		$raw = isset($_POST['baa_adjacent_post_ids']) ? json_decode(wp_unslash((string) $_POST['baa_adjacent_post_ids']), true) : array(); $new_ids = $this->sanitize_ids(is_array($raw) ? $raw : array(), $post_id); $old_ids = $this->get_ids($post_id);
		if (empty($new_ids)) { delete_post_meta($post_id, self::META_KEY); } else { update_post_meta($post_id, self::META_KEY, $new_ids); }
		if ('two_way' === $settings['relationship_mode']) { $this->sync_reverse($post_id, $old_ids, $new_ids, ! empty($settings['remove_reverse_relation'])); }
	}

	private function sync_reverse(int $source_id, array $old_ids, array $new_ids, bool $remove): void {
		foreach (array_diff($new_ids, $old_ids) as $target_id) { $ids = $this->get_ids($target_id); if (! in_array($source_id, $ids, true)) { $ids[] = $source_id; update_post_meta($target_id, self::META_KEY, $ids); } }
		if ($remove) { foreach (array_diff($old_ids, $new_ids) as $target_id) { $ids = array_values(array_filter($this->get_ids($target_id), static fn (int $id): bool => $id !== $source_id)); if (empty($ids)) { delete_post_meta($target_id, self::META_KEY); } else { update_post_meta($target_id, self::META_KEY, $ids); } } }
	}

	private function get_ids(int $post_id): array { $ids = get_post_meta($post_id, self::META_KEY, true); return $this->sanitize_ids(is_array($ids) ? $ids : array(), $post_id, false); }

	/** @param array<int, mixed> $ids */
	private function sanitize_ids(array $ids, int $source_id, bool $validate = true): array { $clean = array(); foreach ($ids as $id) { $id = absint($id); if (! $id || $id === $source_id || in_array($id, $clean, true)) { continue; } if (! $validate) { $clean[] = $id; continue; } if ($this->post_types->is_valid_target($id)) { $clean[] = $id; } } return $clean; }

	public function register_meta(): void {
		foreach ($this->post_types->active() as $post_type) { register_post_meta($post_type, self::META_KEY, array('type' => 'array', 'single' => true, 'show_in_rest' => true, 'sanitize_callback' => array($this, 'sanitize_meta'), 'auth_callback' => static fn (bool $allowed, string $meta_key, int $post_id): bool => current_user_can('edit_post', $post_id))); }
	}

	/** @param mixed $value */
	public function sanitize_meta($value): array { if (! is_array($value)) { return array(); } return array_values(array_unique(array_map('absint', $value))); }
}
