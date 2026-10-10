<?php
defined( 'ABSPATH' ) || exit;

/**
 * Masa_Geo_Shipping
 * تسعير الشحن جغرافياً — يضيف رسوم شحن إضافية حسب الـ Zone
 * بيشتغل مع WooCommerce Shipping Methods الموجودة
 */
final class Masa_Geo_Shipping {

    private static ?Masa_Geo_Shipping $instance = null;

    const OPTION_KEY = 'masa_geo_shipping_rates'; // [ zone_id => egp_cost ]

    public static function instance(): Masa_Geo_Shipping {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // تعديل مصاريف الشحن
        add_filter( 'woocommerce_package_rates', [ $this, 'adjust_shipping_rates' ], 99, 2 );

        // AJAX حفظ إعدادات الشحن
        add_action( 'wp_ajax_masa_geo_save_shipping', [ $this, 'ajax_save_shipping' ] );

        // إضافة المدن كولايات/محافظات في الـ checkout للدول التي ليس لها ولايات افتراضية
        add_filter( 'woocommerce_states', [ $this, 'add_custom_states_to_checkout' ] );
    }

    /**
     * إضافة المدن المهيأة في الإعدادات كخيارات منسدلة (States) في صفحة الدفع
     */
    public function add_custom_states_to_checkout( array $states ): array {
        $city_rates = get_option( 'masa_geo_shipping_cities' );
        if ( ! is_array( $city_rates ) ) {
            $city_rates = self::get_default_cities();
        }

        // تجميع المدن حسب كود الدولة
        $grouped = [];
        foreach ( $city_rates as $item ) {
            $country = strtoupper( trim( $item['country'] ?? '' ) );
            $city    = trim( $item['city'] ?? '' );
            if ( ! empty( $country ) && ! empty( $city ) ) {
                $grouped[ $country ][] = $city;
            }
        }

        // إدخال المدن كخيارات منسدلة للدول المعنية
        foreach ( $grouped as $country => $cities ) {
            // نقوم بالتطبيق على السعودية (SA)، الإمارات (AE)، الكويت (KW) وأي دولة أخرى تم تعريف مدن لها
            // لتسهيل المطابقة، سنضع اسم المدينة كمفتاح وقيمة
            $state_list = [];
            foreach ( $cities as $city ) {
                $state_list[ $city ] = $city;
            }
            // إضافة خيار "مدن أخرى" ليتطابق مع سعر شحن الدولة العام
            $state_list['__OTHER_CITIES__'] = __( 'مدن أخرى (سعر الشحن العام)', 'masa-geo-pricing' );
            $states[ $country ] = $state_list;
        }

        return $states;
    }

    public static function normalize_string( string $str ): string {
        $str = trim( mb_strtolower( $str, 'UTF-8' ) );
        // Normalize Arabic letters
        $find    = [ 'أ', 'إ', 'آ', 'ة', 'ى' ];
        $replace = [ 'ا', 'ا', 'ا', 'ه', 'ي' ];
        return str_replace( $find, $replace, $str );
    }

    public static function get_default_cities(): array {
        return [
            [ 'country' => 'SA', 'city' => 'الرياض', 'cost' => 20.0 ],
            [ 'country' => 'SA', 'city' => 'جدة', 'cost' => 25.0 ],
            [ 'country' => 'SA', 'city' => 'الدمام', 'cost' => 25.0 ],
            [ 'country' => 'SA', 'city' => 'مكة المكرمة', 'cost' => 30.0 ],
            [ 'country' => 'SA', 'city' => 'المدينة المنورة', 'cost' => 30.0 ],
            [ 'country' => 'SA', 'city' => 'الخبر', 'cost' => 25.0 ],
            [ 'country' => 'SA', 'city' => 'الطائف', 'cost' => 30.0 ],
            [ 'country' => 'SA', 'city' => 'تبوك', 'cost' => 35.0 ],
            [ 'country' => 'SA', 'city' => 'بريدة', 'cost' => 30.0 ],
            [ 'country' => 'AE', 'city' => 'دبي', 'cost' => 15.0 ],
            [ 'country' => 'AE', 'city' => 'أبوظبي', 'cost' => 20.0 ],
            [ 'country' => 'AE', 'city' => 'الشارقة', 'cost' => 15.0 ],
            [ 'country' => 'AE', 'city' => 'عجمان', 'cost' => 15.0 ],
            [ 'country' => 'AE', 'city' => 'العين', 'cost' => 20.0 ],
            [ 'country' => 'AE', 'city' => 'رأس الخيمة', 'cost' => 25.0 ],
            [ 'country' => 'AE', 'city' => 'الفجيرة', 'cost' => 25.0 ],
        ];
    }

    public function adjust_shipping_rates( array $rates, array $package ): array {
        $settings = get_option( MASA_GEO_OPTION_KEY, [] );
        if ( empty( $settings['shipping_enabled'] ) ) return $rates;

        $country = $this->get_shipping_country( $package );
        $city    = trim( $package['destination']['city'] ?? '' );
        $state   = trim( $package['destination']['state'] ?? '' );
        
        $zone    = Masa_Geo_Helper::get_zone_for_country( $country );
        if ( ! $zone ) return $rates;

        $shipping_rates = get_option( self::OPTION_KEY, [] );
        $city_rates     = get_option( 'masa_geo_shipping_cities' );
        if ( ! is_array( $city_rates ) ) {
            $city_rates = self::get_default_cities();
        }
        $zone_id        = $zone['id'];

        // Determine the local shipping surcharge
        $local_shipping = null;
        $city_norm      = self::normalize_string( $city );
        $state_norm     = self::normalize_string( $state );

        foreach ( $city_rates as $item ) {
            $item_country = strtoupper( $item['country'] ?? '' );
            if ( $item_country !== $country ) {
                continue;
            }

            $item_city      = trim( $item['city'] ?? '' );
            $item_city_norm = self::normalize_string( $item_city );

            // Match either the typed city or the selected state (dropdown)
            if ( $item_city_norm === $city_norm || $item_city_norm === $state_norm ) {
                $local_shipping = (float) ( $item['cost'] ?? 0.0 );
                break;
            }
        }

        // If no city match, fallback to zone rate
        if ( null === $local_shipping ) {
            $local_shipping = isset( $shipping_rates[ $zone_id ] ) ? (float) $shipping_rates[ $zone_id ] : null;
        }

        // If shipping is not available (null), we block checkout by unsetting all shipping rates
        if ( null === $local_shipping ) {
            return [];
        }

        // Convert WooCommerce base shipping rate from EGP to active local currency
        $currency = strtoupper( $zone['currency'] );
        $rate     = Masa_Geo_Api::get_rate( $currency );

        // If WooCommerce has no shipping methods configured, we dynamically inject a standard shipping rate
        if ( empty( $rates ) ) {
            $rate_id = 'masa_geo_dynamic_shipping';
            $rates[ $rate_id ] = new WC_Shipping_Rate(
                $rate_id,
                __( 'شحن قياسي', 'masa-geo-pricing' ),
                $local_shipping,
                array(),
                'flat_rate'
            );
            return $rates;
        }

        // Apply rates adjustment to existing WooCommerce rates
        foreach ( $rates as $rate_id => $shipping_rate ) {
            $method_id = $shipping_rate->get_method_id();

            // Skip free shipping methods entirely (never add surcharges/modify free shipping)
            if ( 'free_shipping' === $method_id ) {
                continue;
            }

            $current_cost = (float) $shipping_rate->cost;

            // Convert WooCommerce base EGP rate to local currency
            $converted_base = 0.0;
            if ( $current_cost > 0 ) {
                $converted_base = Masa_Geo_Rounding::round_price( $current_cost * $rate, $currency );
            }
            
            // Set total cost: converted base cost + local shipping surcharge
            $rates[ $rate_id ]->cost = $converted_base + $local_shipping;

            // Update taxes if active
            if ( ! empty( $shipping_rate->taxes ) && $current_cost > 0 ) {
                foreach ( $rates[ $rate_id ]->taxes as $tax_id => $tax_amount ) {
                    $tax_rate = $tax_amount / $current_cost;
                    $rates[ $rate_id ]->taxes[ $tax_id ] = $rates[ $rate_id ]->cost * $tax_rate;
                }
            }

            // Display info about the surcharge if it was applied and > 0
            if ( $local_shipping > 0 ) {
                $suffix = sprintf(
                    ' (%s %s %s)',
                    __( 'شحن دولي:', 'masa-geo-pricing' ),
                    number_format( $local_shipping, 2 ),
                    $currency
                );
                if ( strpos( $rates[ $rate_id ]->label, $suffix ) === false ) {
                    $rates[ $rate_id ]->label .= $suffix;
                }
            }
        }

        return $rates;
    }

    // ─────────────────────────────────────────────────────────
    // جيب بلد الشحن (من الـ package أو الـ Billing)
    // ─────────────────────────────────────────────────────────
    private function get_shipping_country( array $package ): string {
        // أولاً من الـ package (shipping address)
        if ( ! empty( $package['destination']['country'] ) ) {
            return strtoupper( $package['destination']['country'] );
        }

        // ثانياً من الـ Visitor country
        return Masa_Geo_Helper::get_visitor_country();
    }

    // ─────────────────────────────────────────────────────────
    // AJAX: حفظ إعدادات الشحن
    // ─────────────────────────────────────────────────────────
    public function ajax_save_shipping(): void {
        check_ajax_referer( 'masa_geo_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'غير مصرح.' ], 403 );
        }

        $raw  = isset( $_POST['shipping_rates'] ) ? (array) wp_unslash( $_POST['shipping_rates'] ) : [];
        $clean = [];

        foreach ( $raw as $zone_id => $cost ) {
            $zone_id = sanitize_key( $zone_id );
            $cost    = (float) sanitize_text_field( (string) $cost );
            if ( $cost >= 0 ) {
                $clean[ $zone_id ] = $cost;
            }
        }

        update_option( self::OPTION_KEY, $clean, 'no' );

        // تفعيل/تعطيل
        $settings = get_option( MASA_GEO_OPTION_KEY, [] );
        $settings['shipping_enabled'] = ! empty( $_POST['shipping_enabled'] ) ? 1 : 0;
        update_option( MASA_GEO_OPTION_KEY, $settings, 'no' );

        wp_send_json_success( [ 'message' => 'تم حفظ إعدادات الشحن بنجاح.' ] );
    }

    // ─────────────────────────────────────────────────────────
    // جيب الإعدادات للـ Admin Panel
    // ─────────────────────────────────────────────────────────
    public static function get_zone_rates(): array {
        return get_option( self::OPTION_KEY, [] );
    }
}
