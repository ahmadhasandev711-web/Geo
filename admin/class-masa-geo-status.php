<?php
defined( 'ABSPATH' ) || exit;

/**
 * Masa_Geo_Status
 * صفحة "الحالة" — تشخيص شامل لكل مكونات البلجن في مكان واحد:
 * البيئة، تحديد الموقع، أسعار الصرف، الكرون، الكاش، والإعدادات.
 *
 * الهدف التجاري: العميل يبص على الصفحة دي قبل ما يفتح تذكرة دعم —
 * وكل تحذير معاه خطوة الحل المباشرة.
 */
final class Masa_Geo_Status {

	private static ?Masa_Geo_Status $instance = null;

	public static function instance(): Masa_Geo_Status {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ], 30 );
	}

	public function register_menu(): void {
		add_submenu_page(
			'masa-geo-pricing',
			__( 'الحالة والتشخيص', 'masa-geo-pricing' ),
			__( '🩺 الحالة', 'masa-geo-pricing' ),
			'manage_woocommerce',
			'masa-geo-status',
			[ $this, 'render_page' ]
		);
	}

	// ─────────────────────────────────────────────────────────
	// الفحوصات — كل فحص بيرجع [status, label, value, fix]
	// status: ok | warn | error
	// ─────────────────────────────────────────────────────────
	public static function run_checks(): array {
		$checks   = [];
		$settings = get_option( MASA_GEO_OPTION_KEY, [] );

		// ── 1. البيئة ──
		$checks[] = [
			'group'  => 'البيئة',
			'status' => version_compare( PHP_VERSION, '8.0', '>=' ) ? 'ok' : 'error',
			'label'  => 'إصدار PHP',
			'value'  => PHP_VERSION,
			'fix'    => 'الحد الأدنى 8.0 — اطلب من الاستضافة الترقية.',
		];

		$wc_active = class_exists( 'WooCommerce' );
		$checks[]  = [
			'group'  => 'البيئة',
			'status' => $wc_active ? 'ok' : 'error',
			'label'  => 'WooCommerce',
			'value'  => $wc_active ? ( 'نشط — v' . WC()->version ) : 'غير نشط',
			'fix'    => 'البلجن لا يعمل بدون WooCommerce.',
		];

		// ── 2. الإعدادات الأساسية ──
		$zones    = (array) ( $settings['zones'] ?? [] );
		$checks[] = [
			'group'  => 'الإعدادات',
			'status' => count( $zones ) > 0 ? 'ok' : 'error',
			'label'  => 'المناطق الجغرافية (Zones)',
			'value'  => count( $zones ) . ' منطقة معرّفة',
			'fix'    => 'بدون مناطق لا يوجد تسعير جغرافي — أضف المناطق من صفحة الإعدادات.',
		];

		$has_fallback = false;
		foreach ( $zones as $zone ) {
			if ( in_array( '__FALLBACK__', (array) ( $zone['countries'] ?? [] ), true ) ) {
				$has_fallback = true;
				break;
			}
		}
		$checks[] = [
			'group'  => 'الإعدادات',
			'status' => $has_fallback ? 'ok' : 'warn',
			'label'  => 'منطقة Fallback (باقي العالم)',
			'value'  => $has_fallback ? 'معرّفة' : 'غير معرّفة',
			'fix'    => 'بدون fallback: الزائر من بلد خارج كل المناطق سيرى الأسعار الأصلية بدون تحويل.',
		];

		// ── 3. تحديد الموقع ──
		$diag         = Masa_Geo_Helper::detect_country_no_cookie();
		$source_label = [
			'cloudflare'              => 'Cloudflare Header',
			'woocommerce_geolocation' => 'WooCommerce Geolocation (MaxMind)',
			'default'                 => 'البلد الافتراضي (فشل التحديد الفعلي!)',
		][ $diag['source'] ] ?? $diag['source'];

		$checks[] = [
			'group'  => 'تحديد الموقع',
			'status' => 'default' === $diag['source'] ? 'error' : 'ok',
			'label'  => 'اختبار التحديد (طلبك الحالي)',
			'value'  => $diag['country'] . ' — ' . $source_label . ' — IP: ' . Masa_Geo_Helper::get_real_ip(),
			'fix'    => 'لو المصدر "البلد الافتراضي": فعّل MaxMind في WooCommerce ← الإعدادات ← عام ← Geolocation، أو راجع وضع الـ Proxy في إعدادات البلجن.',
		];

		// MaxMind DB موجودة؟
		$maxmind_ok = false;
		if ( class_exists( 'WC_Integration_MaxMind_Geolocation' ) ) {
			$maxmind = new WC_Integration_MaxMind_Geolocation();
			if ( method_exists( $maxmind, 'get_database_service' ) ) {
				$db_path    = $maxmind->get_database_service()->get_database_path();
				$maxmind_ok = $db_path && file_exists( $db_path );
			}
		}
		$checks[] = [
			'group'  => 'تحديد الموقع',
			'status' => $maxmind_ok ? 'ok' : 'warn',
			'label'  => 'قاعدة بيانات MaxMind',
			'value'  => $maxmind_ok ? 'موجودة' : 'غير موجودة',
			'fix'    => 'بدونها يعتمد التحديد على Cloudflare فقط. أضف MaxMind License Key المجاني في WooCommerce ← Integration.',
		];

		$checks[] = [
			'group'  => 'تحديد الموقع',
			'status' => 'ok',
			'label'  => 'وضع الـ Proxy',
			'value'  => [
				'auto'        => 'تلقائي (Cloudflare لو موجود)',
				'remote_addr' => 'صارم (REMOTE_ADDR فقط)',
				'custom'      => 'مخصص (' . count( (array) ( $settings['trusted_proxies'] ?? [] ) ) . ' proxies)',
			][ Masa_Geo_Helper::get_proxy_mode() ] ?? '—',
			'fix'    => '',
		];

		// ── 4. أسعار الصرف ──
		$last_update = (int) get_option( Masa_Geo_Api::RATES_LAST_UPDATE, 0 );
		$age_hours   = $last_update ? round( ( time() - $last_update ) / HOUR_IN_SECONDS, 1 ) : null;
		$rates_ok    = null !== $age_hours && $age_hours < 48;

		$checks[] = [
			'group'  => 'أسعار الصرف',
			'status' => $rates_ok ? 'ok' : ( null === $age_hours ? 'error' : 'warn' ),
			'label'  => 'آخر تحديث للأسعار',
			'value'  => null === $age_hours
				? 'لم يتم الجلب مطلقاً'
				: 'منذ ' . $age_hours . ' ساعة (' . wp_date( 'd/m/Y H:i', $last_update ) . ')',
			'fix'    => 'حدّث يدوياً من صفحة الإعدادات. لو فشل: راجع مزود الـ API والـ API Key.',
		];

		$next_cron = wp_next_scheduled( 'masa_geo_fetch_rates_event' );
		$checks[]  = [
			'group'  => 'أسعار الصرف',
			'status' => $next_cron ? 'ok' : 'warn',
			'label'  => 'جدولة التحديث اليومي (Cron)',
			'value'  => $next_cron ? 'التالي: ' . wp_date( 'd/m/Y H:i', $next_cron ) : 'غير مجدول',
			'fix'    => 'عطّل البلجن وفعّله مرة أخرى لإعادة الجدولة. لو الموقع زياراته قليلة فعّل cron حقيقي من السيرفر.',
		];

		// ── 5. الكاش ──
		$cache_plugins = Masa_Geo_Compat::detect_cache_plugins();
		if ( empty( $cache_plugins ) ) {
			$checks[] = [
				'group'  => 'الكاش',
				'status' => 'ok',
				'label'  => 'بلجنز الكاش',
				'value'  => 'لا يوجد page cache مكتشف',
				'fix'    => '',
			];
		} else {
			foreach ( $cache_plugins as $cp ) {
				$checks[] = [
					'group'  => 'الكاش',
					'status' => $cp['supported'] ? 'ok' : 'warn',
					'label'  => $cp['name'],
					'value'  => $cp['supported'] ? 'متوافق تلقائياً ✓' : 'يحتاج إعداد يدوي',
					'fix'    => $cp['note'],
				];
			}
		}

		// ── 6. المخزون والعرض الجغرافي ──
		$restricted = Masa_Geo_Visibility::restrictions_exist();
		$checks[]   = [
			'group'  => 'المخزون الجغرافي',
			'status' => 'ok',
			'label'  => 'المخزون الجغرافي',
			'value'  => $restricted ? 'يوجد منتجات بمخزون/حجب مخصص (الفلترة نشطة)' : 'لا يوجد تخصيصات — الفلترة متوقفة تلقائياً (صفر تكلفة)',
			'fix'    => '',
		];

		return $checks;
	}

	// ─────────────────────────────────────────────────────────
	// تقرير المخزون الجغرافي (v3.7.0)
	// ─────────────────────────────────────────────────────────
	private function render_stock_report(): void {
		global $wpdb;

		$table = Masa_Geo_Zone_Stock::table_name();
		$rows  = $wpdb->get_results(
			"SELECT zs.product_id, zs.zone_id, zs.qty, p.post_title, p.post_type, p.post_parent
			 FROM {$table} zs
			 INNER JOIN {$wpdb->posts} p ON p.ID = zs.product_id
			 ORDER BY zs.qty ASC, p.post_title ASC
			 LIMIT 200",
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return;
		}

		$settings = get_option( MASA_GEO_OPTION_KEY, [] );
		$labels   = [];
		foreach ( (array) ( $settings['zones'] ?? [] ) as $zone ) {
			if ( ! empty( $zone['id'] ) ) {
				$labels[ $zone['id'] ] = $zone['label'];
			}
		}
		?>
		<h2 style="margin-top:2rem;">📦 <?php esc_html_e( 'تقرير المخزون الجغرافي', 'masa-geo-pricing' ); ?></h2>
		<table class="widefat striped" style="max-width:1100px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'المنتج', 'masa-geo-pricing' ); ?></th>
					<th style="width:180px;"><?php esc_html_e( 'المنطقة', 'masa-geo-pricing' ); ?></th>
					<th style="width:130px;"><?php esc_html_e( 'الكمية المتبقية', 'masa-geo-pricing' ); ?></th>
					<th style="width:170px;"><?php esc_html_e( 'الحالة', 'masa-geo-pricing' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $rows as $row ) :
				$qty       = (int) $row['qty'];
				$is_var    = 'product_variation' === $row['post_type'];
				$edit_id   = $is_var ? (int) $row['post_parent'] : (int) $row['product_id'];
				$zone_name = $labels[ $row['zone_id'] ] ?? $row['zone_id'];
			?>
				<tr>
					<td>
						<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $edit_id . '&action=edit' ) ); ?>">
							<?php echo esc_html( $row['post_title'] ); ?>
						</a>
						<?php if ( $is_var ) : ?>
							<span style="color:#6b7280;font-size:.78rem;">(<?php esc_html_e( 'متغير', 'masa-geo-pricing' ); ?>)</span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $zone_name ); ?></td>
					<td style="font-weight:700;"><?php echo esc_html( $qty ); ?></td>
					<td>
						<?php if ( 0 === $qty ) : ?>
							<span style="color:#dc2626;font-weight:600;">🚫 <?php esc_html_e( 'غير متاح', 'masa-geo-pricing' ); ?></span>
						<?php elseif ( $qty <= 5 ) : ?>
							<span style="color:#d97706;font-weight:600;">⚠️ <?php esc_html_e( 'منخفض', 'masa-geo-pricing' ); ?></span>
						<?php else : ?>
							<span style="color:#16a34a;font-weight:600;">✅ <?php esc_html_e( 'متاح', 'masa-geo-pricing' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	// ─────────────────────────────────────────────────────────
	// Render
	// ─────────────────────────────────────────────────────────
	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'غير مصرح لك بالوصول.', 'masa-geo-pricing' ) );
		}

		$checks = self::run_checks();

		$icons  = [ 'ok' => '✅', 'warn' => '⚠️', 'error' => '❌' ];
		$colors = [ 'ok' => '#16a34a', 'warn' => '#d97706', 'error' => '#dc2626' ];

		$errors   = count( array_filter( $checks, fn( $c ) => 'error' === $c['status'] ) );
		$warnings = count( array_filter( $checks, fn( $c ) => 'warn' === $c['status'] ) );
		?>
		<div class="wrap" dir="rtl" style="max-width:1100px;">
			<h1>🩺 <?php esc_html_e( 'الحالة والتشخيص — Masa Geo-Pricing', 'masa-geo-pricing' ); ?></h1>

			<div style="background:<?php echo $errors ? '#fef2f2' : ( $warnings ? '#fffbeb' : '#f0fdf4' ); ?>;border:1px solid <?php echo $errors ? '#fecaca' : ( $warnings ? '#fde68a' : '#bbf7d0' ); ?>;border-radius:8px;padding:1rem 1.5rem;margin:1rem 0;">
				<?php if ( $errors ) : ?>
					<strong>❌ <?php echo esc_html( $errors ); ?> <?php esc_html_e( 'مشكلة حرجة', 'masa-geo-pricing' ); ?></strong>
					— <?php esc_html_e( 'البلجن قد لا يعمل بشكل صحيح حتى يتم حلها.', 'masa-geo-pricing' ); ?>
				<?php elseif ( $warnings ) : ?>
					<strong>⚠️ <?php echo esc_html( $warnings ); ?> <?php esc_html_e( 'تحذير', 'masa-geo-pricing' ); ?></strong>
					— <?php esc_html_e( 'البلجن يعمل، لكن ننصح بمراجعة التحذيرات أدناه.', 'masa-geo-pricing' ); ?>
				<?php else : ?>
					<strong>✅ <?php esc_html_e( 'كل شيء يعمل بشكل ممتاز.', 'masa-geo-pricing' ); ?></strong>
				<?php endif; ?>
			</div>

			<?php
			$current_group = '';
			foreach ( $checks as $check ) :
				if ( $check['group'] !== $current_group ) :
					if ( $current_group !== '' ) echo '</tbody></table>';
					$current_group = $check['group'];
					?>
					<h2 style="margin-top:1.5rem;"><?php echo esc_html( $current_group ); ?></h2>
					<table class="widefat striped" style="max-width:1100px;">
					<tbody>
				<?php endif; ?>
				<tr>
					<td style="width:30px;font-size:1.1rem;"><?php echo esc_html( $icons[ $check['status'] ] ); ?></td>
					<td style="width:240px;font-weight:600;"><?php echo esc_html( $check['label'] ); ?></td>
					<td style="color:<?php echo esc_attr( $colors[ $check['status'] ] ); ?>;font-weight:600;">
						<?php echo esc_html( $check['value'] ); ?>
					</td>
					<td style="color:#6b7280;font-size:.85rem;">
						<?php echo 'ok' !== $check['status'] && $check['fix'] ? esc_html( $check['fix'] ) : ''; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody></table>

			<?php $this->render_stock_report(); ?>

			<p class="description" style="margin-top:1.5rem;">
				<?php esc_html_e( '💡 نصيحة: عند فتح تذكرة دعم، أرفق لقطة شاشة من هذه الصفحة — توفر معظم المعلومات المطلوبة للتشخيص.', 'masa-geo-pricing' ); ?>
			</p>
		</div>
		<?php
	}
}
