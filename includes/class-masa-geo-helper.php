<?php
defined( 'ABSPATH' ) || exit;

/**
 * Masa_Geo_Helper
 * مسؤول عن: تحديد بلد الزائر، الـ Cookie الموقّعة، والـ Cache
 *
 * أمان v3.4.0:
 * - الكوكي موقّعة HMAC-SHA256 — الزائر مايقدرش يزوّر بلده بتعديل الكوكي
 * - الـ IP headers (X-Forwarded-For وغيرها) متتصدقش إلا حسب وضع Trusted Proxy
 * - ip-api.com اتشال نهائياً (ترخيصه يمنع الاستخدام التجاري + HTTP فقط)
 *
 * سلسلة التحديد (بالترتيب):
 * 1. الكوكي الموقّعة (لو صالحة)
 * 2. Cloudflare CF-IPCountry header (حسب وضع الـ proxy)
 * 3. WooCommerce Geolocation (MaxMind المدمج)
 * 4. البلد الافتراضي من الإعدادات
 */
final class Masa_Geo_Helper {

	private static ?Masa_Geo_Helper $instance = null;

	const COOKIE_NAME   = 'masa_geo_country';
	const COOKIE_EXPIRE = 6 * HOUR_IN_SECONDS;

	public static function instance(): Masa_Geo_Helper {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	// ─────────────────────────────────────────────────────────
	// الوظيفة الرئيسية: إيه بلد الزائر؟
	// ─────────────────────────────────────────────────────────
	public static function get_visitor_country(): string {
		$result = self::get_visitor_country_with_source();
		return $result['country'];
	}

	/**
	 * نفس التحديد + مصدره — للتشخيص في صفحة الإعدادات
	 *
	 * @return array{country:string,source:string}
	 */
	public static function get_visitor_country_with_source(): array {
		// 1) الكوكي الموقّعة
		$cookie_country = self::get_cookie_country();
		if ( $cookie_country !== '' ) {
			return [ 'country' => $cookie_country, 'source' => 'cookie' ];
		}

		// 2) التحديد الفعلي
		$detected = self::detect_country_no_cookie();

		self::set_country_cookie( $detected['country'] );

		return $detected;
	}

	/**
	 * التحديد بدون الكوكي — بيستخدمها كمان الـ Anti-VPN في Core
	 * عشان يجيب البلد "الحقيقي" بغض النظر عن أي كوكي مخزّنة
	 *
	 * @return array{country:string,source:string}
	 */
	public static function detect_country_no_cookie(): array {
		$country = self::detect_from_cf();
		if ( $country !== '' ) {
			return [ 'country' => $country, 'source' => 'cloudflare' ];
		}

		$country = self::detect_from_wc();
		if ( $country !== '' ) {
			return [ 'country' => $country, 'source' => 'woocommerce_geolocation' ];
		}

		return [ 'country' => self::get_default_country(), 'source' => 'default' ];
	}

	public static function get_default_country(): string {
		$settings = get_option( MASA_GEO_OPTION_KEY, [] );
		$default  = strtoupper( (string) ( $settings['default_country'] ?? 'EG' ) );
		return self::is_valid_country_code( $default ) ? $default : 'EG';
	}

	// ─────────────────────────────────────────────────────────
	// الكوكي الموقّعة (HMAC-SHA256)
	// التنسيق: COUNTRY|expires|signature
	// ─────────────────────────────────────────────────────────

	private static function cookie_secret(): string {
		// wp_salt مفتاح ثابت لكل موقع — مفيش secret جديد العميل يديره
		return wp_salt( 'auth' );
	}

	private static function sign_country( string $country, int $expires ): string {
		return hash_hmac( 'sha256', $country . '|' . $expires, self::cookie_secret() );
	}

	/**
	 * اقرا البلد من الكوكي — بترجع '' لو الكوكي مش موجودة
	 * أو منتهية أو التوقيع مزوّر
	 */
	public static function get_cookie_country(): string {
		if ( empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return '';
		}

		$raw   = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );
		$parts = explode( '|', $raw );

		if ( count( $parts ) !== 3 ) {
			return ''; // تنسيق قديم (قبل v3.4.0) أو متلاعب فيه — هيتعمل re-detect
		}

		[ $country, $expires, $signature ] = $parts;

		$country = strtoupper( $country );
		$expires = (int) $expires;

		if ( ! self::is_valid_country_code( $country ) ) {
			return '';
		}

		if ( $expires < time() ) {
			return '';
		}

		$expected = self::sign_country( $country, $expires );

		// hash_equals = مقارنة timing-safe
		if ( ! hash_equals( $expected, $signature ) ) {
			return '';
		}

		return $country;
	}

	private static function set_country_cookie( string $country_code ): void {
		if ( headers_sent() ) {
			return;
		}

		$country_code = strtoupper( $country_code );
		$expires      = time() + self::COOKIE_EXPIRE;
		$value        = $country_code . '|' . $expires . '|' . self::sign_country( $country_code, $expires );

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

	public static function clear_country_cookie(): void {
		if ( headers_sent() ) {
			return;
		}
		setcookie( self::COOKIE_NAME, '', time() - 3600, COOKIEPATH ?: '/' );
		unset( $_COOKIE[ self::COOKIE_NAME ] );
	}

	// ─────────────────────────────────────────────────────────
	// Cloudflare country header — حسب وضع الـ proxy
	// ─────────────────────────────────────────────────────────
	private static function detect_from_cf(): string {
		$mode = self::get_proxy_mode();

		// الوضع الصارم: متصدقش أي header — كله ممكن يتزوّر
		// لو السيرفر مش ورا proxy فعلاً
		if ( 'remote_addr' === $mode ) {
			return '';
		}

		// الوضع المخصص: اقبل الـ header بس لو الطلب جاي فعلاً
		// من proxy موثوق (وإلا أي حد يبعت الـ header مباشرة للسيرفر)
		if ( 'custom' === $mode && ! self::remote_addr_is_trusted() ) {
			return '';
		}

		if ( ! empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
			$code = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) );
			if ( self::is_valid_country_code( $code ) && 'XX' !== strtoupper( $code ) ) {
				return strtoupper( $code );
			}
		}

		return '';
	}

	// ─────────────────────────────────────────────────────────
	// WooCommerce Geolocation (MaxMind المدمج)
	// ─────────────────────────────────────────────────────────
	private static function detect_from_wc(): string {
		if ( ! class_exists( 'WC_Geolocation' ) ) {
			return '';
		}

		// بنمرر الـ IP اللي إحنا حددناه بقواعد الـ Trusted Proxy —
		// مش الـ IP اللي ووكومرس بيستنتجه بنفسه من الـ headers
		$ip   = self::get_real_ip();
		$data = WC_Geolocation::geolocate_ip( $ip, true, false );

		if ( ! empty( $data['country'] ) && self::is_valid_country_code( $data['country'] ) ) {
			return strtoupper( $data['country'] );
		}

		return '';
	}

	// ─────────────────────────────────────────────────────────
	// Zone للـ country
	// ─────────────────────────────────────────────────────────
	private static array $zone_cache = [];  // request-level cache

	public static function get_zone_for_country( string $country_code ): ?array {
		$country_code = strtoupper( $country_code );

		if ( array_key_exists( $country_code, self::$zone_cache ) ) {
			return self::$zone_cache[ $country_code ];
		}

		$settings = get_option( MASA_GEO_OPTION_KEY, [] );
		$zones    = $settings['zones'] ?? [];
		$fallback = null;

		foreach ( $zones as $zone ) {
			if ( empty( $zone['countries'] ) || empty( $zone['id'] ) ) {
				continue;
			}

			if ( in_array( '__FALLBACK__', (array) $zone['countries'], true ) ) {
				$fallback = $zone;
				continue;
			}

			if ( in_array( $country_code, array_map( 'strtoupper', (array) $zone['countries'] ), true ) ) {
				self::$zone_cache[ $country_code ] = $zone;
				return $zone;
			}
		}

		self::$zone_cache[ $country_code ] = $fallback;
		return $fallback;
	}

	// امسح الـ zone cache لما الإعدادات تتغير
	public static function flush_zone_cache(): void {
		self::$zone_cache = [];
	}

	// ─────────────────────────────────────────────────────────
	// Trusted Proxy + IP resolution
	// ─────────────────────────────────────────────────────────

	/**
	 * وضع الـ proxy:
	 * - auto        : لو فيه CF-Connecting-IP استخدمه، غير كده REMOTE_ADDR (افتراضي — بيشتغل في كل البيئات)
	 * - remote_addr : REMOTE_ADDR فقط — الأكثر صرامة، للسيرفرات اللي مش ورا أي proxy
	 * - custom      : صدّق X-Forwarded-For بس لو الطلب جاي من IP/CIDR في القائمة الموثوقة
	 */
	public static function get_proxy_mode(): string {
		$settings = get_option( MASA_GEO_OPTION_KEY, [] );
		$mode     = (string) ( $settings['geo_proxy_mode'] ?? 'auto' );
		return in_array( $mode, [ 'auto', 'remote_addr', 'custom' ], true ) ? $mode : 'auto';
	}

	private static function get_trusted_proxies(): array {
		$settings = get_option( MASA_GEO_OPTION_KEY, [] );
		$list     = $settings['trusted_proxies'] ?? [];
		return is_array( $list ) ? $list : [];
	}

	private static function get_remote_addr(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';

		return filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '127.0.0.1';
	}

	public static function remote_addr_is_trusted(): bool {
		$remote  = self::get_remote_addr();
		$proxies = self::get_trusted_proxies();

		foreach ( $proxies as $entry ) {
			if ( self::ip_matches( $remote, $entry ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * هل IP معين بيطابق IP أو CIDR؟ (IPv4 + IPv6)
	 */
	public static function ip_matches( string $ip, string $entry ): bool {
		$entry = trim( $entry );
		if ( '' === $entry ) {
			return false;
		}

		// IP مفرد
		if ( strpos( $entry, '/' ) === false ) {
			return filter_var( $entry, FILTER_VALIDATE_IP ) && inet_pton( $ip ) === inet_pton( $entry );
		}

		// CIDR
		[ $subnet, $bits ] = explode( '/', $entry, 2 );
		$bits = (int) $bits;

		$ip_bin     = inet_pton( $ip );
		$subnet_bin = inet_pton( $subnet );

		if ( false === $ip_bin || false === $subnet_bin || strlen( $ip_bin ) !== strlen( $subnet_bin ) ) {
			return false; // عائلتين مختلفتين (v4 vs v6) أو قيمة بايظة
		}

		$max_bits = strlen( $ip_bin ) * 8;
		if ( $bits < 0 || $bits > $max_bits ) {
			return false;
		}

		$full_bytes = intdiv( $bits, 8 );
		$rem_bits   = $bits % 8;

		if ( $full_bytes > 0 && substr( $ip_bin, 0, $full_bytes ) !== substr( $subnet_bin, 0, $full_bytes ) ) {
			return false;
		}

		if ( $rem_bits > 0 ) {
			$mask = 0xFF << ( 8 - $rem_bits ) & 0xFF;
			if ( ( ord( $ip_bin[ $full_bytes ] ) & $mask ) !== ( ord( $subnet_bin[ $full_bytes ] ) & $mask ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * الـ IP الحقيقي للزائر — حسب وضع الـ Trusted Proxy
	 *
	 * ⚠️ ملحوظة أمنية: قبل v3.4.0 كنا بناخد X-Forwarded-For من غير شروط —
	 * أي زائر كان يقدر يبعت header مزيف ويتحوّل لـ zone أرخص.
	 */
	public static function get_real_ip(): string {
		$remote = self::get_remote_addr();
		$mode   = self::get_proxy_mode();

		if ( 'remote_addr' === $mode ) {
			return $remote;
		}

		if ( 'custom' === $mode ) {
			if ( ! self::remote_addr_is_trusted() ) {
				return $remote; // الطلب مش جاي من proxy موثوق → متصدقش أي header
			}
			return self::extract_client_ip_from_headers() ?: $remote;
		}

		// auto: Cloudflare header لو موجود، وإلا REMOTE_ADDR
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$cf = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
			if ( filter_var( $cf, FILTER_VALIDATE_IP ) ) {
				return $cf;
			}
		}

		return $remote;
	}

	private static function extract_client_ip_from_headers(): string {
		// الترتيب: CF أولاً (أدق)، بعدين XFF (أول IP عام في السلسلة)، بعدين X-Real-IP
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$cf = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
			if ( filter_var( $cf, FILTER_VALIDATE_IP ) ) {
				return $cf;
			}
		}

		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$chain = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			foreach ( explode( ',', $chain ) as $candidate ) {
				$candidate = trim( $candidate );
				if ( filter_var( $candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
					return $candidate;
				}
			}
		}

		if ( ! empty( $_SERVER['HTTP_X_REAL_IP'] ) ) {
			$real = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) );
			if ( filter_var( $real, FILTER_VALIDATE_IP ) ) {
				return $real;
			}
		}

		return '';
	}

	// ─────────────────────────────────────────────────────────
	// Helpers
	// ─────────────────────────────────────────────────────────
	public static function is_valid_country_code( string $code ): bool {
		return (bool) preg_match( '/^[A-Za-z]{2}$/', $code );
	}

	/**
	 * تحويل كود الدولة المكون من حرفين إلى علم (Emoji)
	 */
	public static function get_flag_emoji( string $country_code ): string {
		$code = strtoupper( trim( $country_code ) );
		if ( strlen( $code ) !== 2 ) {
			return '';
		}
		$chr1 = ord( $code[0] ) - 65 + 127462;
		$chr2 = ord( $code[1] ) - 65 + 127462;
		return mb_chr( $chr1, 'UTF-8' ) . mb_chr( $chr2, 'UTF-8' );
	}

	/**
	 * استرجاع الاسم العربي للعملة لتأكيد شارة السعر للعميل
	 */
	public static function get_currency_name_ar( string $currency ): string {
		$names = [
			'EGP' => 'الجنيه المصري',
			'SAR' => 'الريال السعودي',
			'AED' => 'الدرهم الإماراتي',
			'KWD' => 'الدينار الكويتي',
			'BHD' => 'الدينار البحريني',
			'QAR' => 'الريال القطري',
			'OMR' => 'الريال العماني',
			'USD' => 'الدولار الأمريكي',
			'EUR' => 'اليورو',
			'GBP' => 'الجنيه الإسترليني',
		];
		return $names[ strtoupper( $currency ) ] ?? $currency;
	}
}
