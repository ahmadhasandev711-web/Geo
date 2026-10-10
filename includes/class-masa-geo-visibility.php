<?php
defined( 'ABSPATH' ) || exit;

/**
 * Masa_Geo_Visibility
 * التحكم في عرض المنتجات حسب الـ Zone الجغرافية للزائر
 *
 * المعمارية:
 * - المنتج الافتراضي = متاح في كل البلاد (صفر تأثير على الأداء)
 * - المنتج المقيّد = ليه صفوف meta منفصلة `_masa_geo_allowed_zone` (صف لكل zone مسموحة)
 *   → ده بيخلي فلترة الـ query بـ meta_query بسيطة ومفهرسة:
 *     (NOT EXISTS) OR (= zone الزائر)
 *
 * مستويات الحماية (متدرجة):
 * 1. فلترة الكتالوج: المتجر / الأرشيف / البحث / الشورتكودز / wc_get_products / REST
 * 2. صفحة المنتج المباشرة: تفضل مفتوحة (SEO) لكن من غير زرار شراء + رسالة "غير متاح في بلدك"
 * 3. حماية الكارت: منع الإضافة (Classic + AJAX + Store API)
 * 4. فحص السلة: لو البلد اتغير بعد الإضافة → المنتج يتشال من السلة بإشعار
 */
final class Masa_Geo_Visibility {

	private static ?Masa_Geo_Visibility $instance = null;

	const META_ALLOWED_ZONE = '_masa_geo_allowed_zone'; // صف meta لكل zone مسموحة

	private static array $allowed_cache = []; // request-level cache: product_id => array zones
	private static array $visible_cache = []; // request-level cache: product_id => bool

	public static function instance(): Masa_Geo_Visibility {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {

		// ─── 1. فلترة الكتالوج ───────────────────────────────
		// المتجر والأرشيف (الـ main query بتاع WooCommerce)
		add_action( 'woocommerce_product_query', [ $this, 'filter_product_query' ] );

		// البحث العام للموقع (بيشمل منتجات + مقالات — الـ NOT EXISTS بيخلي المقالات تعدي عادي)
		add_action( 'pre_get_posts', [ $this, 'filter_search_query' ] );

		// شورتكودز WooCommerce ([products], [featured_products], ...)
		add_filter( 'woocommerce_shortcode_products_query', [ $this, 'filter_shortcode_query' ] );

		// wc_get_products() — بيغطي بلوكات البلجن نفسه (products-grid, featured-products)
		add_filter( 'woocommerce_product_object_query', [ $this, 'filter_product_objects' ], 10, 2 );

		// REST / Store API product listings (بلوكات Gutenberg بتاعة WooCommerce)
		add_filter( 'woocommerce_rest_product_object_query', [ $this, 'filter_rest_query' ], 10, 2 );

		// is_visible — بيغطي related / up-sells / cross-sells في القوالب
		add_filter( 'woocommerce_product_is_visible', [ $this, 'filter_is_visible' ], 10, 2 );

		// related products (الـ IDs نفسها قبل الـ render)
		add_filter( 'woocommerce_related_products', [ $this, 'filter_related_products' ], 10, 2 );

		// السعر يختفي مع المنتج المحجوب — مايتعرضش سعر بجنب رسالة "غير متاح"
		add_filter( 'woocommerce_get_price_html', [ $this, 'filter_price_html' ], 100, 2 );

		// ─── 2. صفحة المنتج المباشرة ─────────────────────────
		add_filter( 'woocommerce_is_purchasable',           [ $this, 'filter_is_purchasable' ], 99, 2 );
		add_filter( 'woocommerce_variation_is_purchasable', [ $this, 'filter_is_purchasable' ], 99, 2 );
		add_action( 'wp', [ $this, 'maybe_replace_add_to_cart' ] );

		// ─── 3. حماية الكارت ─────────────────────────────────
		// Classic + AJAX add to cart
		add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'validate_add_to_cart' ], 10, 2 );
		// Block Cart / Store API add to cart
		add_action( 'woocommerce_store_api_validate_add_to_cart', [ $this, 'validate_store_api_add_to_cart' ], 10, 2 );

		// ─── 4. فحص السلة (لو البلد اتغير بعد الإضافة) ───────
		add_action( 'woocommerce_check_cart_items', [ $this, 'check_cart_items' ] );

		// ─── 5. شارة العملة المحلية (v3.8.0) ──────────────────
		$settings = get_option( MASA_GEO_OPTION_KEY, [] );
		if ( ! empty( $settings['currency_badge_enabled'] ) ) {
			add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_styles' ] );
			add_action( 'woocommerce_single_product_summary', [ $this, 'render_single_currency_badge' ], 11 );
			add_action( 'woocommerce_after_shop_loop_item_title', [ $this, 'render_loop_currency_badge' ], 11 );
		}
	}

	// ═════════════════════════════════════════════════════════
	// المنطق المحوري: هل المنتج ظاهر للزائر الحالي؟
	// ═════════════════════════════════════════════════════════

	/**
	 * @deprecated v3.6.0 — اتبدل بنظام المخزون الجغرافي (Masa_Geo_Zone_Stock).
	 * موجود بس عشان migration البيانات القديمة.
	 */
	public static function get_allowed_zones( int $product_id ): array {
		$zones = get_post_meta( $product_id, self::META_ALLOWED_ZONE, false );
		return is_array( $zones ) ? array_values( array_filter( array_map( 'sanitize_key', $zones ) ) ) : [];
	}

	/**
	 * هل المنتج ظاهر للزائر الحالي؟
	 */
	public static function is_product_visible( int $product_id ): bool {
		if ( ! self::restrictions_exist() ) {
			return true;
		}

		// variation → الـ parent (المخزون الجغرافي على مستوى المنتج)
		if ( get_post_type( $product_id ) === 'product_variation' ) {
			$parent_id = (int) wp_get_post_parent_id( $product_id );
			if ( $parent_id ) {
				$product_id = $parent_id;
			}
		}

		if ( isset( self::$visible_cache[ $product_id ] ) ) {
			return self::$visible_cache[ $product_id ];
		}

		$zone_id = self::get_visitor_zone_id();

		if ( '' === $zone_id ) {
			// زائر بدون zone معروفة: لو المنتج عليه أي تخصيص مخزون → نخفيه احتياطياً
			$visible = empty( Masa_Geo_Zone_Stock::get_stock_map( $product_id ) );
		} else {
			// v3.6.0: المنطق المحوري — متاح لو فيه مخزون للـ zone (أو مفيش تخصيص)
			$visible = Masa_Geo_Zone_Stock::is_available_in_zone( $product_id, $zone_id );
		}

		self::$visible_cache[ $product_id ] = $visible;
		return $visible;
	}

	/**
	 * الـ zone id بتاعة الزائر الحالي (نفس الـ detection بتاع التسعير بالظبط —
	 * مصدر واحد للحقيقة، فمستحيل السعر يقول "سعودي" والعرض يقول "مصري")
	 */
	public static function get_visitor_zone_id(): string {
		$country = Masa_Geo_Helper::get_visitor_country();
		$zone    = Masa_Geo_Helper::get_zone_for_country( $country );
		return $zone && ! empty( $zone['id'] ) ? (string) $zone['id'] : '';
	}

	/**
	 * meta_query موحّدة لكل الفلاتر (v3.6.0):
	 * المنتج المحجوب في zone عليه marker بمفتاح خاص بالـ zone دي —
	 * فالفلترة كلها شرط NOT EXISTS واحد مفهرس (أبسط وأسرع من نظام OR القديم)
	 */
	public static function get_visibility_meta_query(): array {
		$zone_id = self::get_visitor_zone_id();

		if ( '' === $zone_id ) {
			return []; // مفيش zone معروفة → منفلترش الكتالوج (الحالة شبه مستحيلة مع fallback)
		}

		return [
			'key'     => Masa_Geo_Zone_Stock::META_BLOCKED_PREFIX . $zone_id,
			'compare' => 'NOT EXISTS',
		];
	}

	// امسح الـ caches (مفيد في الاختبارات أو لما يتغير الـ context)
	public static function flush_cache(): void {
		self::$allowed_cache = [];
		self::$visible_cache = [];
		delete_transient( self::TRANSIENT_IN_USE );
	}

	// ─────────────────────────────────────────────────────────
	// تحسين أداء: لو مفيش ولا منتج مقيّد في الموقع كله،
	// منضيفش أي meta_query أو JOIN على postmeta نهائياً (صفر تكلفة)
	// ─────────────────────────────────────────────────────────
	const TRANSIENT_IN_USE = 'masa_geo_visibility_in_use';

	public static function restrictions_exist(): bool {
		$cached = get_transient( self::TRANSIENT_IN_USE );
		if ( 'yes' === $cached ) return true;
		if ( 'no' === $cached )  return false;

		// v3.7.0: أي صف في جدول المخزون الجغرافي = الفلترة نشطة
		$exists = Masa_Geo_Zone_Stock::any_rows_exist();

		set_transient( self::TRANSIENT_IN_USE, $exists ? 'yes' : 'no', 5 * MINUTE_IN_SECONDS );
		return $exists;
	}

	// ═════════════════════════════════════════════════════════
	// 1. فلترة الكتالوج
	// ═════════════════════════════════════════════════════════

	public function filter_product_query( \WP_Query $q ): void {
		if ( ! self::restrictions_exist() ) {
			return;
		}
		$clause = self::get_visibility_meta_query();
		if ( empty( $clause ) ) {
			return;
		}
		$meta_query   = (array) $q->get( 'meta_query', [] );
		$meta_query[] = $clause;
		$q->set( 'meta_query', $meta_query );
	}

	public function filter_search_query( \WP_Query $q ): void {
		if ( is_admin() || ! $q->is_main_query() || ! $q->is_search() ) {
			return;
		}

		// woocommerce_product_query بيغطي بحث المنتجات الخاص بـ WC —
		// هنا بنغطي البحث العام (post_type=any) من غير ما نأثر على المقالات
		// لأن شرط NOT EXISTS بيعدّي أي post مالوش الـ meta أصلاً
		if ( $q->get( 'wc_query' ) === 'product_query' ) {
			return; // اتغطت فوق — منعاً للـ JOIN المكرر
		}

		if ( ! self::restrictions_exist() ) {
			return;
		}

		$clause = self::get_visibility_meta_query();
		if ( empty( $clause ) ) {
			return;
		}

		$meta_query   = (array) $q->get( 'meta_query', [] );
		$meta_query[] = $clause;
		$q->set( 'meta_query', $meta_query );
	}

	public function filter_shortcode_query( array $query_args ): array {
		if ( ! self::restrictions_exist() ) {
			return $query_args;
		}
		$clause = self::get_visibility_meta_query();
		if ( empty( $clause ) ) {
			return $query_args;
		}
		$query_args['meta_query']   = isset( $query_args['meta_query'] ) ? (array) $query_args['meta_query'] : [];
		$query_args['meta_query'][] = $clause;
		return $query_args;
	}

	/**
	 * فلترة نتايج wc_get_products() — آخر خط دفاع للكتالوج،
	 * بيغطي بلوكات البلجن نفسه وأي كود theme بيستخدم wc_get_products
	 */
	public function filter_product_objects( $results, $query_vars ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $results;
		}
		if ( ! self::restrictions_exist() ) {
			return $results;
		}
		if ( ! is_array( $results ) ) {
			return $results;
		}

		return array_values( array_filter( $results, static function ( $product ) {
			if ( ! $product instanceof \WC_Product ) {
				return true;
			}
			return self::is_product_visible( $product->get_id() );
		} ) );
	}

	public function filter_rest_query( array $args, $request ): array {
		// الأدمن والمحررين بيشوفوا كل المنتجات في لوحة التحكم والـ editor
		if ( current_user_can( 'edit_products' ) || ! self::restrictions_exist() ) {
			return $args;
		}

		$clause = self::get_visibility_meta_query();
		if ( empty( $clause ) ) {
			return $args;
		}

		$args['meta_query']   = isset( $args['meta_query'] ) ? (array) $args['meta_query'] : [];
		$args['meta_query'][] = $clause;
		return $args;
	}

	public function filter_is_visible( bool $visible, int $product_id ): bool {
		if ( ! $visible ) {
			return false;
		}
		return self::is_product_visible( $product_id );
	}

	/**
	 * المنتج المحجوب في منطقة الزائر: مايظهرش له سعر —
	 * عرض "10 ج.م" جنب رسالة "غير متاح في بلدك" متضادان منطقياً.
	 * صفحته (الوحيدة المسموح ظهورها) بتفضل بس بالوصف والرسالة.
	 */
	public function filter_price_html( string $price_html, $product ): string {
		if ( '' === $price_html ) {
			return $price_html;
		}

		return self::is_product_visible( $product->get_id() ) ? $price_html : '';
	}

	public function filter_related_products( array $related, $product_id ): array {
		return array_values( array_filter( $related, static function ( $id ) {
			return self::is_product_visible( (int) $id );
		} ) );
	}

	// ═════════════════════════════════════════════════════════
	// 2. صفحة المنتج المباشرة
	// ═════════════════════════════════════════════════════════

	public function filter_is_purchasable( bool $purchasable, $product ): bool {
		if ( ! $purchasable ) {
			return false;
		}
		return self::is_product_visible( $product->get_id() );
	}

	/**
	 * على صفحة المنتج المحجوب: شيل زرار الإضافة للكارت وحط الرسالة مكانه.
	 * الصفحة نفسها بتفضل مفتوحة عمداً (SEO + اللينكات المتشيرة متتكسرش).
	 */
	public function maybe_replace_add_to_cart(): void {
		if ( ! is_product() ) {
			return;
		}

		$product_id = get_queried_object_id();
		if ( ! $product_id || self::is_product_visible( (int) $product_id ) ) {
			return;
		}

		remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
		add_action( 'woocommerce_single_product_summary', [ $this, 'render_unavailable_notice' ], 30 );

		// ستايل الرسالة — مستقل عن تفعيل الـ switcher
		add_action( 'wp_enqueue_scripts', static function () {
			wp_enqueue_style(
				'masa-geo-frontend',
				MASA_GEO_PLUGIN_URL . 'assets/frontend.css',
				[],
				MASA_GEO_VERSION
			);
		} );
	}

	public function render_unavailable_notice(): void {
		echo '<div class="masa-geo-unavailable">';
		echo '🌍 ' . esc_html( self::get_unavailable_message() );
		echo '</div>';
	}

	public static function get_unavailable_message(): string {
		$settings = get_option( MASA_GEO_OPTION_KEY, [] );
		$message  = trim( (string) ( $settings['visibility_message'] ?? '' ) );

		if ( $message === '' ) {
			$message = __( 'عذراً، هذا المنتج غير متاح في بلدك حالياً.', 'masa-geo-pricing' );
		}

		/**
		 * فلتر للمطورين لو حابين يخصصوا الرسالة برمجياً
		 */
		return (string) apply_filters( 'masa_geo_unavailable_message', $message );
	}

	// ═════════════════════════════════════════════════════════
	// 3. حماية الكارت
	// ═════════════════════════════════════════════════════════

	public function validate_add_to_cart( bool $passed, $product_id ): bool {
		if ( ! $passed ) {
			return false;
		}

		if ( ! self::is_product_visible( (int) $product_id ) ) {
			wc_add_notice( self::get_unavailable_message(), 'error' );
			return false;
		}

		return true;
	}

	/**
	 * Store API (Block Cart) — نفس أسلوب الـ Anti-VPN في Core:
	 * RouteException لو موجود، وإلا Exception عادي كتأمين دفاعي
	 */
	public function validate_store_api_add_to_cart( $product, $request ): void {
		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		if ( self::is_product_visible( $product->get_id() ) ) {
			return;
		}

		$message = self::get_unavailable_message();

		if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
				'masa_geo_product_unavailable',
				esc_html( $message ),
				403
			);
		}

		throw new \Exception( esc_html( $message ) );
	}

	// ═════════════════════════════════════════════════════════
	// 4. فحص السلة — لو البلد/الـ Zone اتغير بعد الإضافة
	// ═════════════════════════════════════════════════════════

	public function check_cart_items(): void {
		if ( ! WC()->cart ) {
			return;
		}

		$removed = [];

		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			$product_id = (int) ( $cart_item['product_id'] ?? 0 );
			if ( ! $product_id ) {
				continue;
			}

			if ( ! self::is_product_visible( $product_id ) ) {
				$product   = $cart_item['data'] ?? null;
				$removed[] = $product instanceof \WC_Product ? $product->get_name() : "#{$product_id}";
				WC()->cart->remove_cart_item( $cart_item_key );
			}
		}

		if ( ! empty( $removed ) ) {
			wc_add_notice(
				sprintf(
					/* translators: %s: أسماء المنتجات المحذوفة */
					__( 'تمت إزالة المنتجات التالية من سلتك لأنها غير متاحة في بلدك: %s', 'masa-geo-pricing' ),
					implode( '، ', array_map( 'esc_html', $removed ) )
				),
				'notice'
			);
		}
	}

	public function enqueue_frontend_styles(): void {
		wp_enqueue_style( 'masa-geo-frontend', MASA_GEO_PLUGIN_URL . 'assets/frontend.css', [], MASA_GEO_VERSION );
	}

	/**
	 * عرض شارة العملة في صفحة المنتج الفردي
	 */
	public function render_single_currency_badge(): void {
		$country = Masa_Geo_Helper::get_visitor_country();
		$zone    = Masa_Geo_Helper::get_zone_for_country( $country );
		if ( ! $zone ) {
			return;
		}

		$currency = strtoupper( $zone['currency'] ?? 'EGP' );
		$flag     = Masa_Geo_Helper::get_flag_emoji( $country );
		$name     = Masa_Geo_Helper::get_currency_name_ar( $currency );



		echo '<div class="masa-geo-currency-badge masa-geo-currency-badge--single">';
		echo '<span class="masa-geo-badge-flag">' . esc_html( $flag ) . '</span> ';
		echo '<span class="masa-geo-badge-text">' . sprintf( esc_html__( 'الأسعار معروضة بـ %s', 'masa-geo-pricing' ), esc_html( $name ) ) . '</span>';
		echo '</div>';
	}

	/**
	 * عرض شارة العملة في قائمة المنتجات (المتجر)
	 */
	public function render_loop_currency_badge(): void {
		$country = Masa_Geo_Helper::get_visitor_country();
		$zone    = Masa_Geo_Helper::get_zone_for_country( $country );
		if ( ! $zone ) {
			return;
		}

		$currency = strtoupper( $zone['currency'] ?? 'EGP' );
		$flag     = Masa_Geo_Helper::get_flag_emoji( $country );



		echo '<div class="masa-geo-currency-badge masa-geo-currency-badge--loop">';
		echo '<span class="masa-geo-badge-flag">' . esc_html( $flag ) . '</span> ';
		echo '<span class="masa-geo-badge-text">' . esc_html( $currency ) . '</span>';
		echo '</div>';
	}
}
