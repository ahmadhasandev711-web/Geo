<?php
defined( 'ABSPATH' ) || exit;

/**
 * Masa_Geo_Compat
 * توافق مع بلجنز الكاش — أهم مصدر مشاكل في أي بلجن تسعير جغرافي
 *
 * المشكلة: page cache بيقدّم نفس الصفحة (بنفس الأسعار) لكل الزوار
 * بغض النظر عن بلدهم. الحل: نسجّل كوكي البلد كـ "vary cookie" عند
 * البلجنز اللي بتدعم ده برمجياً — فيتعمل نسخة كاش منفصلة لكل بلد.
 *
 * بلجنز مدعومة برمجياً: WP Rocket، LiteSpeed Cache، WP-Optimize
 * بلجنز بتحتاج إعداد يدوي (بنكشفها ونحذّر في صفحة الحالة):
 * WP Super Cache، W3 Total Cache، WP Fastest Cache، Cloudflare APO
 */
final class Masa_Geo_Compat {

	private static ?Masa_Geo_Compat $instance = null;

	public static function instance(): Masa_Geo_Compat {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {

		// ─── WP Rocket ───
		// dynamic = نسخة كاش لكل "قيمة" للكوكي (ده اللي إحنا عايزينه)
		add_filter( 'rocket_cache_dynamic_cookies',   [ $this, 'register_cookie' ] );
		// mandatory = الصفحة متتكاشش غير لما الكوكي تكون موجودة
		add_filter( 'rocket_cache_mandatory_cookies', [ $this, 'register_cookie' ] );

		// ─── LiteSpeed Cache ───
		add_filter( 'litespeed_vary_cookies', [ $this, 'register_cookie' ] );

		// ─── WP-Optimize ───
		add_filter( 'wpo_cache_cookies', [ $this, 'register_cookie' ] );
	}

	public function register_cookie( $cookies ): array {
		$cookies   = is_array( $cookies ) ? $cookies : [];
		$cookies[] = Masa_Geo_Helper::COOKIE_NAME;
		return array_values( array_unique( $cookies ) );
	}

	// ─────────────────────────────────────────────────────────
	// Purge كاش صفحة منتج — عند الحجب/الإتاحة (v3.7.0)
	// عشان صفحة منتج اتحجب في نص اليوم متفضلش متكاشة وظاهرة
	// ─────────────────────────────────────────────────────────
	public static function purge_product( int $product_id ): void {
		if ( $product_id <= 0 ) {
			return;
		}

		// WP Rocket — بيمسح صفحة المنتج + الأرشيفات المرتبطة
		if ( function_exists( 'rocket_clean_post' ) ) {
			rocket_clean_post( $product_id );
		}

		// LiteSpeed Cache
		do_action( 'litespeed_purge_post', $product_id );

		// WP-Optimize
		if ( class_exists( 'WPO_Page_Cache' ) && method_exists( 'WPO_Page_Cache', 'delete_single_post_cache' ) ) {
			WPO_Page_Cache::delete_single_post_cache( $product_id );
		}

		// WooCommerce transients بتاعة المنتج
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $product_id );
		}
	}

	// ─────────────────────────────────────────────────────────
	// كشف بلجنز الكاش — لصفحة الحالة
	// ─────────────────────────────────────────────────────────

	/**
	 * @return array قائمة بكل بلجن كاش مكتشف:
	 *               [ name, supported (هل التوافق تلقائي؟), note ]
	 */
	public static function detect_cache_plugins(): array {
		$found = [];

		$known = [
			// ── توافق تلقائي ──
			'wp-rocket/wp-rocket.php' => [
				'name'      => 'WP Rocket',
				'supported' => true,
				'note'      => 'التوافق تلقائي — يُنشئ نسخة كاش لكل بلد. نظّف الكاش بعد تغيير الإعدادات.',
			],
			'litespeed-cache/litespeed-cache.php' => [
				'name'      => 'LiteSpeed Cache',
				'supported' => true,
				'note'      => 'التوافق تلقائي عبر vary cookie. نظّف الكاش بعد تغيير الإعدادات.',
			],
			'wp-optimize/wp-optimize.php' => [
				'name'      => 'WP-Optimize',
				'supported' => true,
				'note'      => 'التوافق تلقائي عبر cache cookies.',
			],
			// ── يحتاج إعداد يدوي ──
			'wp-super-cache/wp-cache.php' => [
				'name'      => 'WP Super Cache',
				'supported' => false,
				'note'      => 'أضف "masa_geo_country" في Advanced ← Accepted Cookie Names، أو فعّل وضع Simple مع تعطيل كاش الزوار المعروفين.',
			],
			'w3-total-cache/w3-total-cache.php' => [
				'name'      => 'W3 Total Cache',
				'supported' => false,
				'note'      => 'أضف "masa_geo_country" في Page Cache ← Advanced ← Accepted Cookies (Cache alias).',
			],
			'wp-fastest-cache/wpFastestCache.php' => [
				'name'      => 'WP Fastest Cache',
				'supported' => false,
				'note'      => 'لا يدعم vary cookies في النسخة المجانية — استثنِ صفحات المتجر من الكاش أو استخدم بلجن كاش آخر.',
			],
			'cache-enabler/cache-enabler.php' => [
				'name'      => 'Cache Enabler',
				'supported' => false,
				'note'      => 'لا يدعم vary cookies — استثنِ صفحات المتجر من الكاش.',
			],
		];

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( $known as $plugin_file => $info ) {
			if ( is_plugin_active( $plugin_file ) ) {
				$found[] = $info;
			}
		}

		// Cloudflare APO مش بلجن بالضرورة — نكشف وجود Cloudflare من الـ headers
		if ( ! empty( $_SERVER['HTTP_CF_RAY'] ) ) {
			$found[] = [
				'name'      => 'Cloudflare (CDN)',
				'supported' => false,
				'note'      => 'لو APO أو Cache Everything مفعّل: أضف bypass cookie لـ "masa_geo_country" أو استثنِ صفحات المتجر من كاش الـ Edge. الكاش الافتراضي (static فقط) لا يسبب مشكلة.',
			];
		}

		return $found;
	}
}
