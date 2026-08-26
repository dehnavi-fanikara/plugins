<?php
/**
 * Plugin Name: Fanikara Content Sync
 * Description: Synchronizes published content posts with service terms and stores a normalized custom slug.
 * Version: 1.0.0
 * Author: Fanikara
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Fanikara_Content_Sync {
	const POST_TYPE          = 'content';
	const TAXONOMY           = 'service';
	const META_KEY           = 'fnk_dev_custom_slug';
	const REPORT_OPTION      = 'fnk_content_sync_last_report';
	const MAX_REPORT_ITEMS   = 1000;

	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ) );
		add_action( 'admin_post_fanikara_content_sync_run', array( __CLASS__, 'handle_run' ) );
	}

	public static function register_admin_page() {
		add_management_page(
			'همگام‌سازی محتوای فنیکارا',
			'همگام‌سازی محتوای فنیکارا',
			'manage_options',
			'fnk-content-sync',
			array( __CLASS__, 'render_admin_page' )
		);
	}

	public static function handle_run() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما اجازه انجام این عملیات را ندارید.', 'fanikara-content-sync' ) );
		}

		check_admin_referer( 'fnk_content_sync_run', 'fnk_content_sync_nonce' );

		$operation = isset( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : 'full';
		$report    = ( 'custom_slug' === $operation )
			? self::run_custom_slug_sync()
			: self::run_full_sync();

		update_option( self::REPORT_OPTION, $report, false );

		$url = add_query_arg(
			array(
				'page'    => 'fnk-content-sync',
				'fnk_run' => '1',
			), admin_url( 'tools.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}

	public static function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$report = get_option( self::REPORT_OPTION, array() );
		?>
		<div class="wrap">
			<h1>همگام‌سازی محتوای فنیکارا</h1>
			<p>
				این ابزار فقط روی پست‌های منتشرشده با نوع <code>content</code> کار می‌کند.
				برای هر پست، خدمت متناظر از تکسونومی <code>service</code> پیدا می‌شود و مقدار
				<code><?php echo esc_html( self::META_KEY ); ?></code> نیز ثبت یا بروزرسانی می‌گردد.
			</p>

			<?php if ( isset( $_GET['fnk_run'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>عملیات به پایان رسید. گزارش آخرین اجرا در پایین نمایش داده شده است.</p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin: 20px 0;">
				<input type="hidden" name="action" value="fanikara_content_sync_run">
				<input type="hidden" name="operation" value="full">
				<?php wp_nonce_field( 'fnk_content_sync_run', 'fnk_content_sync_nonce' ); ?>
				<?php submit_button( 'اجرای کامل سینک', 'primary', 'submit', false ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="fanikara_content_sync_run">
				<input type="hidden" name="operation" value="custom_slug">
				<?php wp_nonce_field( 'fnk_content_sync_run', 'fnk_content_sync_nonce' ); ?>
				<?php submit_button( 'فقط بروزرسانی فیلد اسلاگ سفارشی', 'secondary', 'submit', false ); ?>
			</form>

			<?php self::render_report( $report ); ?>
		</div>
		<?php
	}

	private static function run_full_sync() {
		$report = self::new_report( 'full' );

		if ( ! post_type_exists( self::POST_TYPE ) ) {
			$report['status'] = 'error';
			$report['errors'][] = 'نوع پست content در سایت ثبت نشده است.';
			return $report;
		}

		if ( ! taxonomy_exists( self::TAXONOMY ) ) {
			$report['status'] = 'error';
			$report['errors'][] = 'تکسونومی service در سایت ثبت نشده است.';
			return $report;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) ) {
			$report['status'] = 'error';
			$report['errors'][] = 'دریافت خدمات ناموفق بود: ' . $terms->get_error_message();
			return $report;
		}

		$posts = self::get_published_posts();
		$report['service']['total'] = count( $posts );
		$report['custom_slug']['total'] = count( $posts );

		$term_index = self::build_term_index( $terms );

		foreach ( $posts as $post ) {
			self::sync_post_service( $post, $term_index, $report );
			self::sync_post_custom_slug( $post, $report );
		}

		return $report;
	}

	private static function run_custom_slug_sync() {
		$report = self::new_report( 'custom_slug' );

		if ( ! post_type_exists( self::POST_TYPE ) ) {
			$report['status'] = 'error';
			$report['errors'][] = 'نوع پست content در سایت ثبت نشده است.';
			return $report;
		}

		$posts = self::get_published_posts();
		$report['custom_slug']['total'] = count( $posts );

		foreach ( $posts as $post ) {
			self::sync_post_custom_slug( $post, $report );
		}

		return $report;
	}

	private static function get_published_posts() {
		return get_posts(
			array(
				'post_type'              => self::POST_TYPE,
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'suppress_filters'       => false,
			)
		);
	}

	private static function build_term_index( $terms ) {
		$index = array();

		foreach ( $terms as $term ) {
			$compact_slug = self::compact_slug( $term->slug );

			if ( '' === $compact_slug ) {
				continue;
			}

			if ( ! isset( $index[ $compact_slug ] ) ) {
				$index[ $compact_slug ] = array();
			}

			$index[ $compact_slug ][] = $term;
		}

		return $index;
	}

	private static function sync_post_service( $post, $term_index, &$report ) {
		$base_slug = self::remove_numeric_suffix( $post->post_name );
		$post_slug = self::compact_slug( $base_slug );
		$candidates = array();

		if ( '' !== $post_slug ) {
			foreach ( $term_index as $compact_term_slug => $terms ) {
				if ( 0 !== strpos( $post_slug, $compact_term_slug ) ) {
					continue;
				}

				foreach ( $terms as $term ) {
					$candidates[] = array(
						'term'   => $term,
						'length' => strlen( $compact_term_slug ),
					);
				}
			}
		}

		if ( empty( $candidates ) ) {
			$report['service']['unmatched_count']++;
			self::append_report_item(
				$report['service']['unmatched'],
				array(
					'id'     => (int) $post->ID,
					'title'  => get_the_title( $post ),
					'slug'   => $post->post_name,
					'reason' => 'خدمت متناظر پیدا نشد.',
				)
			);
			return;
		}

		usort(
			$candidates,
			function ( $left, $right ) {
				return $right['length'] - $left['length'];
			}
		);

		$best_length = $candidates[0]['length'];
		$best = array_filter(
			$candidates,
			function ( $candidate ) use ( $best_length ) {
				return $candidate['length'] === $best_length;
			}
		);
		$unique_term_ids = array();

		foreach ( $best as $candidate ) {
			$unique_term_ids[ (int) $candidate['term']->term_id ] = $candidate['term'];
		}

		if ( count( $unique_term_ids ) > 1 ) {
			$report['service']['unmatched_count']++;
			$report['service']['ambiguous_count']++;
			self::append_report_item(
				$report['service']['unmatched'],
				array(
					'id'       => (int) $post->ID,
					'title'    => get_the_title( $post ),
					'slug'     => $post->post_name,
					'reason'   => 'چند خدمت با اولویت یکسان پیدا شد.',
					'candidates' => implode( ', ', wp_list_pluck( $unique_term_ids, 'slug' ) ),
				)
			);
			return;
		}

		$term = reset( $unique_term_ids );
		$report['service']['matched_count']++;

		if ( has_term( (int) $term->term_id, self::TAXONOMY, $post->ID ) ) {
			$report['service']['already_assigned']++;
			return;
		}

		$result = wp_set_object_terms( $post->ID, array( (int) $term->term_id ), self::TAXONOMY, true );

		if ( is_wp_error( $result ) ) {
			$report['service']['errors_count']++;
			self::append_report_item(
				$report['service']['errors'],
				array(
					'id'     => (int) $post->ID,
					'title'  => get_the_title( $post ),
					'slug'   => $post->post_name,
					'reason' => $result->get_error_message(),
				)
			);
			return;
		}

		$report['service']['assigned_count']++;
	}

	private static function sync_post_custom_slug( $post, &$report ) {
		if ( '' === $post->post_name ) {
			$report['custom_slug']['skipped_count']++;
			return;
		}

		$value = self::remove_numeric_suffix( $post->post_name );
		$old_value = get_post_meta( $post->ID, self::META_KEY, true );

		if ( $old_value === $value ) {
			$report['custom_slug']['unchanged_count']++;
			return;
		}

		if ( false === update_post_meta( $post->ID, self::META_KEY, $value ) ) {
			$report['custom_slug']['errors_count']++;
			self::append_report_item(
				$report['custom_slug']['errors'],
				array(
					'id'     => (int) $post->ID,
					'title'  => get_the_title( $post ),
					'slug'   => $post->post_name,
					'reason' => 'ثبت متافیلد ناموفق بود.',
				)
			);
			return;
		}

		$report['custom_slug']['updated_count']++;
	}

	private static function remove_numeric_suffix( $slug ) {
		if ( preg_match( '/^(.+)-(\d+)$/u', $slug, $matches ) ) {
			return $matches[1];
		}

		return $slug;
	}

	private static function compact_slug( $slug ) {
		$slug = rawurldecode( (string) $slug );
		$slug = strtolower( $slug );
		$slug = preg_replace( '/[^\p{L}\p{N}]+/u', '', $slug );

		return is_string( $slug ) ? $slug : '';
	}

	private static function new_report( $operation ) {
		return array(
			'operation'   => $operation,
			'status'      => 'success',
			'completed_at' => current_time( 'mysql' ),
			'errors'      => array(),
			'service'     => array(
				'total'            => 0,
				'matched_count'    => 0,
				'assigned_count'   => 0,
				'already_assigned' => 0,
				'unmatched_count'  => 0,
				'ambiguous_count'  => 0,
				'errors_count'     => 0,
				'unmatched'        => array(),
				'errors'           => array(),
			),
			'custom_slug' => array(
				'total'          => 0,
				'updated_count'  => 0,
				'unchanged_count'=> 0,
				'skipped_count'  => 0,
				'errors_count'   => 0,
				'errors'         => array(),
			),
		);
	}

	private static function append_report_item( &$items, $item ) {
		if ( count( $items ) < self::MAX_REPORT_ITEMS ) {
			$items[] = $item;
		}
	}

	private static function render_report( $report ) {
		if ( empty( $report ) ) {
			return;
		}
		?>
		<hr>
		<h2>گزارش آخرین اجرا</h2>
		<?php if ( 'error' === $report['status'] ) : ?>
			<div class="notice notice-error"><p>اجرای عملیات با خطا متوقف شد.</p></div>
		<?php endif; ?>
		<p>نوع اجرا: <code><?php echo esc_html( $report['operation'] ); ?></code> — زمان پایان: <?php echo esc_html( $report['completed_at'] ); ?></p>

		<?php if ( 'full' === $report['operation'] ) : ?>
			<h3>سینک دسته خدمات</h3>
			<ul>
				<li>کل پست‌های بررسی‌شده: <?php echo esc_html( $report['service']['total'] ); ?></li>
				<li>پست‌های دارای خدمت متناظر: <?php echo esc_html( $report['service']['matched_count'] ); ?></li>
				<li>ارتباط جدید ثبت‌شده: <?php echo esc_html( $report['service']['assigned_count'] ); ?></li>
				<li>ارتباطی که از قبل وجود داشت: <?php echo esc_html( $report['service']['already_assigned'] ); ?></li>
				<li>بدون خدمت یا مبهم: <?php echo esc_html( $report['service']['unmatched_count'] ); ?></li>
				<li>از این تعداد، موارد مبهم: <?php echo esc_html( $report['service']['ambiguous_count'] ); ?></li>
			</ul>
			<?php self::render_items( 'مواردی که خدمت آن‌ها اختصاص پیدا نکرد', $report['service']['unmatched'] ); ?>
			<?php self::render_items( 'خطاهای ثبت خدمت', $report['service']['errors'] ); ?>
		<?php endif; ?>

		<h3>فیلد <?php echo esc_html( self::META_KEY ); ?></h3>
		<ul>
			<li>کل پست‌های بررسی‌شده: <?php echo esc_html( $report['custom_slug']['total'] ); ?></li>
			<li>مقدار بروزرسانی‌شده: <?php echo esc_html( $report['custom_slug']['updated_count'] ); ?></li>
			<li>بدون نیاز به تغییر: <?php echo esc_html( $report['custom_slug']['unchanged_count'] ); ?></li>
			<li>خطا: <?php echo esc_html( $report['custom_slug']['errors_count'] ); ?></li>
		</ul>
		<?php self::render_items( 'خطاهای ثبت اسلاگ سفارشی', $report['custom_slug']['errors'] ); ?>

		<?php self::render_items( 'خطاهای عمومی', $report['errors'] ); ?>
		<?php
	}

	private static function render_items( $heading, $items ) {
		if ( empty( $items ) ) {
			return;
		}
		?>
		<h4><?php echo esc_html( $heading ); ?></h4>
		<table class="widefat striped" style="max-width: 1100px;">
			<thead><tr><th>شناسه</th><th>عنوان</th><th>اسلاگ</th><th>توضیح</th></tr></thead>
			<tbody>
			<?php foreach ( $items as $item ) : ?>
				<?php if ( ! is_array( $item ) ) : ?>
					<?php $item = array( 'reason' => (string) $item ); ?>
				<?php endif; ?>
				<tr>
					<td><?php echo isset( $item['id'] ) ? esc_html( $item['id'] ) : '—'; ?></td>
					<td><?php echo isset( $item['title'] ) ? esc_html( $item['title'] ) : '—'; ?></td>
					<td><code><?php echo isset( $item['slug'] ) ? esc_html( $item['slug'] ) : '—'; ?></code></td>
					<td>
						<?php echo isset( $item['reason'] ) ? esc_html( $item['reason'] ) : '—'; ?>
						<?php if ( isset( $item['candidates'] ) ) : ?>
							<br><small>گزینه‌ها: <?php echo esc_html( $item['candidates'] ); ?></small>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}

Fanikara_Content_Sync::boot();
