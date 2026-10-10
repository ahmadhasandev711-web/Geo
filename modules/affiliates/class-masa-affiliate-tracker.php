<?php
defined( 'ABSPATH' ) || exit;

/**
 * Masa_Geo_Affiliate_Tracker
 * مسؤول عن تتبع روابط الإحالة وحفظ الكوكيز للعملاء
 */
final class Masa_Geo_Affiliate_Tracker {

	private static ?Masa_Geo_Affiliate_Tracker $instance = null;

	const COOKIE_NAME = 'masa_affiliate_ref';

	public static function instance(): Masa_Geo_Affiliate_Tracker {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', [ $this, 'capture_referral' ] );
		add_action( 'woocommerce_checkout_create_order', [ $this, 'save_referral_to_order_meta' ], 10, 2 );
	}

	// ─────────────────────────────────────────────────────────
	// توقيع الكوكي (HMAC-SHA256) — نفس نمط كوكي البلد في Masa_Geo_Helper
	// التنسيق: CODE|expires|signature
	// يمنع العميل من تعديل الكود يدوياً لإسناد عمولة لمسوق آخر.
	// ─────────────────────────────────────────────────────────
	private static function cookie_secret(): string {
		return wp_salt( 'auth' );
	}

	private static function sign_ref_code( string $ref_code, int $expires ): string {
		return hash_hmac( 'sha256', $ref_code . '|' . $expires, self::cookie_secret() );
	}

	/**
	 * حفظ كود الإحالة في الـ metadata الخاصة بالطلب أثناء عملية الـ checkout
	 */
	public function save_referral_to_order_meta( $order, $data ): void {
		$ref_code = self::get_referred_code();
		if ( ! empty( $ref_code ) ) {
			$order->update_meta_data( '_masa_affiliate_ref', $ref_code );
		}
	}

	/**
	 * التقاط كود الإحالة وتخزينه في الكوكي
	 */
	public function capture_referral(): void {
		// لا تتبع طلبات الـ API أو الـ CRON أو الـ Admin
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || defined( 'REST_REQUEST' ) ) {
			return;
		}

		if ( ! isset( $_GET['ref'] ) ) {
			return;
		}

		$ref_code = sanitize_text_field( wp_unslash( $_GET['ref'] ) );

		if ( empty( $ref_code ) ) {
			return;
		}

		// التحقق من صحة وجود مسوق بهذا الكود
		$affiliate_id = Masa_Geo_Affiliate_Core::get_affiliate_by_code( $ref_code );
		if ( ! $affiliate_id ) {
			return;
		}

		// حفظ الكوكي في المتصفح
		$this->set_referral_cookie( $ref_code );
	}

	/**
	 * تعيين الكوكي للمتصفح بالمعايير الأمنية
	 */
	private function set_referral_cookie( string $ref_code ): void {
		if ( headers_sent() ) {
			return;
		}

		// جلب مدة صلاحية الكوكي من الإعدادات العامة (الافتراضي 30 يوم)
		$settings = get_option( 'masa_affiliate_settings', [] );
		$days     = isset( $settings['affiliate_cookie_expiry'] ) ? absint( $settings['affiliate_cookie_expiry'] ) : 30;
		$expires  = time() + ( $days * DAY_IN_SECONDS );

		// القيمة الموقّعة: CODE|expires|signature
		$value = $ref_code . '|' . $expires . '|' . self::sign_ref_code( $ref_code, $expires );

		setcookie(
			self::COOKIE_NAME,
			$value,
			[
				'expires'  => $expires,
				'path'     => COOKIEPATH ?: '/',
				'domain'   => COOKIE_DOMAIN ?: '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			]
		);

		$_COOKIE[ self::COOKIE_NAME ] = $value;
	}

	/**
	 * قراءة كود الإحالة الحالي المخزن لدى العميل
	 * بترجع '' لو الكوكي غير موجودة أو التوقيع مزوّر.
	 */
	public static function get_referred_code(): string {
		if ( empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return '';
		}

		$raw   = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );
		$parts = explode( '|', $raw );

		// تنسيق قديم (غير موقّع) أو متلاعب فيه — ارفض بصمت
		if ( count( $parts ) !== 3 ) {
			return '';
		}

		[ $ref_code, $expires, $signature ] = $parts;

		$ref_code = sanitize_text_field( $ref_code );
		$expires  = (int) $expires;

		if ( $ref_code === '' || $expires < time() ) {
			return '';
		}

		// hash_equals = مقارنة timing-safe
		if ( ! hash_equals( self::sign_ref_code( $ref_code, $expires ), $signature ) ) {
			return '';
		}

		return $ref_code;
	}

	/**
	 * مسح الكوكي بعد إتمام الطلب أو عند الحاجة
	 */
	public static function clear_referral_cookie(): void {
		if ( headers_sent() ) {
			return;
		}
		setcookie( self::COOKIE_NAME, '', time() - 3600, COOKIEPATH ?: '/' );
		unset( $_COOKIE[ self::COOKIE_NAME ] );
	}
}
