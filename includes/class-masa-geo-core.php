<?php
defined( 'ABSPATH' ) || exit;

/**
 * Masa_Geo_Core
 * المنطق الأساسي: حساب الأسعار + حماية الـ Checkout من VPN
 */
final class Masa_Geo_Core {

    private static ?Masa_Geo_Core $instance = null;
    private static array $request_cache   = [];
    private static array $parent_id_cache = []; // cache لـ variation → parent

    private static function get_parent_id( int $variation_id ): int {
        if ( ! isset( self::$parent_id_cache[ $variation_id ] ) ) {
            self::$parent_id_cache[ $variation_id ] = (int) wp_get_post_parent_id( $variation_id );
        }
        return self::$parent_id_cache[ $variation_id ];
    }

    public static function instance(): Masa_Geo_Core {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Price Hooks — منتجات عادية
        add_filter( 'woocommerce_product_get_price',                  [ $this, 'filter_price' ], 99, 2 );
        add_filter( 'woocommerce_product_get_regular_price',          [ $this, 'filter_price' ], 99, 2 );
        add_filter( 'woocommerce_product_get_sale_price',             [ $this, 'filter_sale_price' ], 99, 2 );
        add_filter( 'woocommerce_product_variation_get_price',        [ $this, 'filter_price' ], 99, 2 );
        add_filter( 'woocommerce_product_variation_get_regular_price',[ $this, 'filter_price' ], 99, 2 );
        add_filter( 'woocommerce_product_variation_get_sale_price',   [ $this, 'filter_sale_price' ], 99, 2 );

        // woocommerce_variation_prices_* بيمرر ($price, $variation, $parent_product) — 3 params
        add_filter( 'woocommerce_variation_prices_price',             [ $this, 'filter_variation_prices' ], 99, 3 );
        add_filter( 'woocommerce_variation_prices_regular_price',     [ $this, 'filter_variation_prices' ], 99, 3 );
        add_filter( 'woocommerce_variation_prices_sale_price',        [ $this, 'filter_variation_prices_sale' ], 99, 3 );

        // 🔑 مهم جداً: WooCommerce بيكاش أسعار الـ variations في transient بـ hash.
        // الـ hash الافتراضي مش بياخد بلد الزائر في الاعتبار — يعني أول زائر يفتح المنتج
        // بيكاش سعره، وباقي الزوار من بلاد تانية بياخدوا نفس السعر الغلط من الكاش.
        // بنضيف بلد الزائر للـ hash عشان كل Zone يكون ليه cache منفصل.
        add_filter( 'woocommerce_get_variation_prices_hash', [ $this, 'add_country_to_prices_hash' ], 99, 2 );

        // إيقاف تدخل ووكومرس في الضريبة: منع تعديل الأسعار الشاملة للضريبة باختلاف موقع العميل
        add_filter( 'woocommerce_adjust_non_base_location_prices', '__return_false' );

        // Currency
        add_filter( 'woocommerce_currency',        [ $this, 'filter_currency' ], 99 );
        add_filter( 'woocommerce_currency_symbol', [ $this, 'filter_currency_symbol' ], 99, 2 );

        // Cart
        // Cart — مش محتاجين recalculate_cart لأن فلترة get_price بتغطي السلة
        // (شوف التعليق فوق دالة recalculate_cart المحذوفة)

        // Checkout Anti-VPN — Classic Checkout
        add_action( 'woocommerce_checkout_update_order_review', [ $this, 'on_checkout_country_change' ] );
        add_action( 'woocommerce_checkout_process',             [ $this, 'validate_checkout_country' ] );

        // Checkout Anti-VPN — Block Checkout (Store API)
        // بيشتغل عند تحديث الأوردر من الـ Store API request
        add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ $this, 'validate_block_checkout_country' ], 10, 2 );

        // تقييد الدفع عند الاستلام بناءً على الدولة
        add_filter( 'woocommerce_available_payment_gateways', [ $this, 'filter_gateways_by_country' ], 99 );

        // Cache clear
        add_action( 'woocommerce_cart_emptied', [ $this, 'clear_cache_and_cookie' ] ); // Cart فاضي = ممكن تغيير Zone
        add_action( 'masa_geo_rates_updated',   [ $this, 'clear_price_cache' ] );       // Rates update = سعر بس
    }

    // ═════════════════════════════════════════════════════════
    // الوظيفة المحورية: احسب السعر النهائي
    // ═════════════════════════════════════════════════════════
    public static function calculate_price( $product_id, float $base_egp_price, string $country_code = '' ): array {

        if ( empty( $country_code ) ) {
            $country_code = Masa_Geo_Helper::get_visitor_country();
        }

        $country_code = strtoupper( $country_code );
        $cache_key    = $product_id . '_' . $country_code . '_' . $base_egp_price;

        if ( isset( self::$request_cache[ $cache_key ] ) ) {
            return self::$request_cache[ $cache_key ];
        }

        $zone = Masa_Geo_Helper::get_zone_for_country( $country_code );

        if ( ! $zone ) {
            $result = [
                'egp_price'   => $base_egp_price,
                'final_price' => $base_egp_price,
                'currency'    => 'EGP',
                'zone'        => [],
            ];
            self::$request_cache[ $cache_key ] = $result;
            return $result;
        }

        $zone_id  = $zone['id'];
        $currency = strtoupper( $zone['currency'] ?? 'EGP' );
        $pid      = (int) $product_id;

        // ─── Override Priority ────────────────────────────────
        // 1. Timed Pricing على الـ variation نفسها
        // 2. Static Override على الـ variation نفسها
        // 3. Timed Pricing على الـ parent (لو كانت variation)
        // 4. Static Override على الـ parent
        // 5. السعر الأساسي × مضاعف المنطقة

        $timed_price = Masa_Geo_Timed_Pricing::get_active_price( $pid, $zone_id );

        if ( $timed_price !== null ) {
            $egp_price = $timed_price;
        } else {
            $override = Masa_Geo_Product::get_override_price( $pid, $zone_id );

            if ( $override === null ) {
                // لو variation → دور على override الـ parent
                if ( get_post_type( $pid ) === 'product_variation' ) {
                    $parent_id = self::get_parent_id( $pid );
                    if ( $parent_id ) {
                        $parent_timed = Masa_Geo_Timed_Pricing::get_active_price( $parent_id, $zone_id );
                        if ( $parent_timed !== null ) {
                            $override = $parent_timed;
                        } else {
                            $override = Masa_Geo_Product::get_override_price( $parent_id, $zone_id );
                        }
                    }
                }
            }

            $egp_price = $override !== null ? $override : ( $base_egp_price * (float) $zone['multiplier'] );
        }

        // تحويل العملة
        $rate = Masa_Geo_Api::get_rate( $currency );
        if ( $rate === null ) {
            $result = [
                'egp_price'   => $egp_price,
                'final_price' => $egp_price,
                'currency'    => 'EGP',
                'zone'        => $zone,
            ];
            self::$request_cache[ $cache_key ] = $result;
            return $result;
        }

        $final_price = $egp_price * $rate;
        $final_price = Masa_Geo_Rounding::round_price( $final_price, $currency );

        $result = [
            'egp_price'   => $egp_price,
            'final_price' => $final_price,
            'currency'    => $currency,
            'zone'        => $zone,
        ];

        self::$request_cache[ $cache_key ] = $result;
        return $result;
    }

    // ─────────────────────────────────────────────────────────
    // Tax Compatibility
    // ─────────────────────────────────────────────────────────


    // ═════════════════════════════════════════════════════════
    // Price Filters
    // ═════════════════════════════════════════════════════════
    public function filter_price( $price, $product ): string {
        if ( '' === $price || null === $price ) return $price;
        if ( is_admin() && ! wp_doing_ajax() ) return $price;

        // منتج حسب الطلب — لا نلمسه
        if ( Masa_Geo_Product::is_excluded( $product->get_id() ) ) return $price;

        $result = self::calculate_price( $product->get_id(), (float) $price );
        return (string) $result['final_price'];
    }

    public function filter_sale_price( $price, $product ): string {
        if ( '' === $price || null === $price ) return $price;
        if ( is_admin() && ! wp_doing_ajax() ) return $price;

        // منتج حسب الطلب — لا نلمسه
        if ( Masa_Geo_Product::is_excluded( $product->get_id() ) ) return $price;

        $country = Masa_Geo_Helper::get_visitor_country();
        $result  = self::calculate_price( $product->get_id(), (float) $price, $country );

        // ─── Sale Price Check ────────────────────────────────
        $regular_raw = (float) $product->get_regular_price();
        if ( $regular_raw > 0 ) {
            $regular_converted = self::calculate_price( $product->get_id(), $regular_raw, $country );
            if ( $result['final_price'] >= $regular_converted['final_price'] ) {
                return '';
            }
        }

        return (string) $result['final_price'];
    }

    // variation_prices hooks بتمرر ($price, $variation, $parent) — 3 args
    /**
     * نضيف بلد الزائر (أو الـ zone) للـ variation prices hash
     * عشان WooCommerce يعمل cache منفصل لكل Zone
     */
    public function add_country_to_prices_hash( array $hash, $product ): array {
        $country = Masa_Geo_Helper::get_visitor_country();
        $zone    = Masa_Geo_Helper::get_zone_for_country( $country );
        // نستخدم الـ zone id لو موجود، وإلا الـ country نفسه
        $hash['masa_geo_zone'] = $zone ? $zone['id'] : $country;
        return $hash;
    }

    // variation_prices hooks بتمرر ($price, $variation, $parent) — 3 args
    public function filter_variation_prices( $price, $variation, $parent ): string {
        if ( '' === $price || null === $price ) return $price;
        if ( Masa_Geo_Product::is_excluded( $variation->get_id() ) ) return $price;
        $result = self::calculate_price( $variation->get_id(), (float) $price );
        return (string) $result['final_price'];
    }

    public function filter_variation_prices_sale( $price, $variation, $parent ): string {
        if ( '' === $price || null === $price ) return $price;
        if ( Masa_Geo_Product::is_excluded( $variation->get_id() ) ) return $price;
        $country = Masa_Geo_Helper::get_visitor_country();
        $result  = self::calculate_price( $variation->get_id(), (float) $price, $country );
        $regular_raw = (float) $variation->get_regular_price();
        if ( $regular_raw > 0 ) {
            $reg = self::calculate_price( $variation->get_id(), $regular_raw, $country );
            if ( $result['final_price'] >= $reg['final_price'] ) return '';
        }
        return (string) $result['final_price'];
    }

    // ═════════════════════════════════════════════════════════
    // Currency Filters
    // ═════════════════════════════════════════════════════════
    public function filter_currency( string $currency ): string {
        if ( is_admin() && ! wp_doing_ajax() ) return $currency;

        $country = Masa_Geo_Helper::get_visitor_country();
        $zone    = Masa_Geo_Helper::get_zone_for_country( $country );

        return $zone ? strtoupper( $zone['currency'] ) : $currency;
    }

    public function filter_currency_symbol( string $symbol, string $currency ): string {
        $symbols = [
            'EGP' => 'ج.م',
            'SAR' => 'ر.س',
            'AED' => 'د.إ',
            'KWD' => 'د.ك',
            'QAR' => 'ر.ق',
            'BHD' => 'د.ب',
            'OMR' => 'ر.ع',
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'JPY' => '¥',
            'CAD' => 'C$',
            'AUD' => 'A$',
            'CHF' => 'Fr',
            'SEK' => 'kr',
            'NOK' => 'kr',
            'DKK' => 'kr',
            'TRY' => '₺',
            'MAD' => 'د.م',
            'LYD' => 'ل.د',
            'TND' => 'د.ت',
            'DZD' => 'د.ج',
            'SDG' => 'ج.س',
            'JOD' => 'د.أ',
            'LBP' => 'ل.ل',
        ];

        return $symbols[ strtoupper( $currency ) ] ?? $symbol;
    }

    // ═════════════════════════════════════════════════════════
    // Cart Recalculation
    // ═════════════════════════════════════════════════════════
    /**
     * ملاحظة معمارية مهمة:
     * مش بنعمل set_price() يدوي في السلة. السبب:
     * الـ filters على woocommerce_product_get_price بتشتغل على كل مستوى
     * (صفحة المنتج، السلة، الـ checkout، الـ Store API) فالسعر بيتحوّل تلقائياً
     * من المصدر. لو عملنا set_price() كمان، الـ filter هيحوّل القيمة المحوّلة
     * بالفعل مرة تانية = تحويل مزدوج وأسعار غلط.
     *
     * الاعتماد على فلترة المصدر بس = مصدر واحد للحقيقة، ومفيش تعارض
     * مع الـ Cart/Checkout Blocks (Store API) لأنها بتقرأ من نفس get_price().
     */

    // ═════════════════════════════════════════════════════════
    // Checkout Anti-VPN Protection
    // ═════════════════════════════════════════════════════════
    public function on_checkout_country_change( string $post_data ): void {
        parse_str( $post_data, $data );

        $billing_country = isset( $data['billing_country'] )
            ? strtoupper( sanitize_text_field( $data['billing_country'] ) )
            : '';

        if ( ! Masa_Geo_Helper::is_valid_country_code( $billing_country ) ) {
            return;
        }

        $ip_country = $this->get_ip_country_strict();

        // v3.4.0: القراءة عبر الكوكي الموقّعة — الكوكي المزوّرة بترجع '' تلقائياً
        $stored_country = Masa_Geo_Helper::get_cookie_country();

        if ( $billing_country !== $stored_country ) {
            self::$request_cache = [];

            if ( $ip_country && $ip_country !== $billing_country ) {
                WC()->session->set( 'masa_geo_flagged_country', $billing_country );
                WC()->session->set( 'masa_geo_ip_country', $ip_country );
            } else {
                WC()->session->set( 'masa_geo_flagged_country', null );
                WC()->session->set( 'masa_geo_ip_country', null );
            }
        }
    }

    public function validate_checkout_country(): void {
        $flagged    = WC()->session->get( 'masa_geo_flagged_country' );
        $ip_country = WC()->session->get( 'masa_geo_ip_country' );

        if ( ! $flagged || ! $ip_country ) return;

        $billing = isset( $_POST['billing_country'] )
            ? strtoupper( sanitize_text_field( wp_unslash( $_POST['billing_country'] ) ) )
            : '';

        $billing_zone = Masa_Geo_Helper::get_zone_for_country( $billing );
        $ip_zone      = Masa_Geo_Helper::get_zone_for_country( $ip_country );

        if (
            $billing_zone && $ip_zone &&
            $billing_zone['id'] !== $ip_zone['id']
        ) {
            wc_add_notice(
                __( 'عذراً، لا يمكن إتمام الطلب. بلد الدفع لا يتطابق مع موقعك الجغرافي الفعلي.', 'masa-geo-pricing' ),
                'error'
            );
        }
    }

    /**
     * Anti-VPN للـ Block Checkout (Store API)
     * بيرمي WC_Rest_Exception لو البلد لا يطابق الموقع الجغرافي
     */
    public function validate_block_checkout_country( \WC_Order $order, $request ): void {
        $billing = strtoupper( (string) $order->get_billing_country() );
        if ( ! Masa_Geo_Helper::is_valid_country_code( $billing ) ) {
            return;
        }

        $ip_country = $this->get_ip_country_strict();
        if ( ! $ip_country || $ip_country === $billing ) {
            return;
        }

        $billing_zone = Masa_Geo_Helper::get_zone_for_country( $billing );
        $ip_zone      = Masa_Geo_Helper::get_zone_for_country( $ip_country );

        if (
            $billing_zone && $ip_zone &&
            $billing_zone['id'] !== $ip_zone['id']
        ) {
            $message = esc_html__( 'عذراً، لا يمكن إتمام الطلب. بلد الدفع لا يتطابق مع موقعك الجغرافي الفعلي.', 'masa-geo-pricing' );

            // تأمين دفاعي: لو RouteException مش موجود لأي سبب، استخدم Exception عادي
            if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
                throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
                    'masa_geo_country_mismatch',
                    $message,
                    400
                );
            }

            throw new \Exception( $message );
        }
    }

    private function get_ip_country_strict(): string {
        // v3.4.0: نفس سلسلة التحديد الموحّدة في Helper (بتحترم وضع الـ Trusted Proxy)
        // بس من غير الكوكي — عشان نجيب البلد "الحقيقي" مش المخزّن
        $detected = Masa_Geo_Helper::detect_country_no_cookie();

        // لو النتيجة جاية من الـ default fallback مش من تحديد فعلي →
        // معندناش معلومة موثوقة، فمنرفضش الـ checkout بناءً عليها
        if ( 'default' === $detected['source'] ) {
            return '';
        }

        return $detected['country'];
    }

    public function clear_price_cache(): void {
        self::$request_cache = [];
        // ملاحظة: لا نمسح الـ Cookie هنا — الـ Cookie بيمثل بلد الزائر مش السعر
        // بنمسحها بس لو الـ Cart اتفرّغ أو الـ Zone اتغير
    }

    public function clear_cache_and_cookie(): void {
        self::$request_cache = [];
        Masa_Geo_Helper::clear_country_cookie();
    }

    /**
     * إخفاء بوابة الدفع عند الاستلام (COD) إذا كانت غير مفعّلة للمنطقة الحالية للعميل
     */
    public function filter_gateways_by_country( array $gateways ): array {
        if ( is_admin() && ! wp_doing_ajax() ) {
            return $gateways;
        }

        if ( ! isset( $gateways['cod'] ) ) {
            return $gateways;
        }

        // جلب بلد العميل الحالي (البلد الذي يختاره في الفاتورة أو بلد التتبع)
        $billing_country = '';
        if ( isset( $_POST['billing_country'] ) ) {
            $billing_country = strtoupper( sanitize_text_field( wp_unslash( $_POST['billing_country'] ) ) );
        } elseif ( function_exists( 'WC' ) && WC()->customer ) {
            $billing_country = strtoupper( WC()->customer->get_billing_country() );
        }

        if ( empty( $billing_country ) ) {
            $billing_country = Masa_Geo_Helper::get_visitor_country();
        }

        $zone = Masa_Geo_Helper::get_zone_for_country( $billing_country );

        if ( $zone ) {
            // التحقق من حالة تفعيل COD للمنطقة (مفعّل افتراضياً إذا لم يتم تحديده)
            $cod_enabled = ! isset( $zone['cod_enabled'] ) || ! empty( $zone['cod_enabled'] );
            if ( ! $cod_enabled ) {
                unset( $gateways['cod'] );
            }
        }

        return $gateways;
    }
}
