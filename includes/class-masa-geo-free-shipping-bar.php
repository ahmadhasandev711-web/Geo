<?php
defined( 'ABSPATH' ) || exit;

/**
 * Masa_Geo_Free_Shipping_Bar
 * عرض شريط تقدم الشحن المجاني بالعملة المحلية
 */
final class Masa_Geo_Free_Shipping_Bar {

    private static ?Masa_Geo_Free_Shipping_Bar $instance = null;

    public static function instance(): Masa_Geo_Free_Shipping_Bar {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // التحقق من تفعيل الميزة
        $settings = get_option( MASA_GEO_OPTION_KEY, [] );
        if ( empty( $settings['free_shipping_bar_enabled'] ) ) {
            return;
        }

        // عرض الشريط في السلة وصفحة الدفع
        add_action( 'woocommerce_before_cart', [ $this, 'render_progress_bar' ], 15 );
        add_action( 'woocommerce_before_checkout_form', [ $this, 'render_progress_bar' ], 15 );

        // تصحيح التحقق من الشحن المجاني لمنع تعارض العملات في ووكومرس
        add_filter( 'woocommerce_shipping_free_shipping_is_available', [ $this, 'adjust_free_shipping_availability' ], 99, 3 );
    }

    /**
     * العثور على طريقة الشحن المجاني النشطة للوجهة الحالية
     */
    private function get_active_free_shipping_method(): ?\WC_Shipping_Method {
        if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
            return null;
        }

        $country = '';
        $state   = '';
        $city    = '';

        if ( WC()->customer ) {
            $country = WC()->customer->get_shipping_country();
            $state   = WC()->customer->get_shipping_state();
            $city    = WC()->customer->get_shipping_city();
        }

        if ( ! $country ) {
            $country = Masa_Geo_Helper::get_visitor_country();
        }

        if ( WC()->customer ) {
            $state = WC()->customer->get_shipping_state();
            $city  = WC()->customer->get_shipping_city();
        }

        $package = [
            'destination' => [
                'country' => $country,
                'state'   => $state,
                'city'    => $city,
            ]
        ];

        // البحث عن المنطقة المطابقة
        $zone = \WC_Shipping_Zones::get_zone_matching_package( $package );
        if ( $zone ) {
            foreach ( $zone->get_shipping_methods( true ) as $method ) {
                if ( 'free_shipping' === $method->id ) {
                    return $method;
                }
            }
        }

        // البحث في المنطقة الافتراضية
        $default_zone = new \WC_Shipping_Zone( 0 );
        foreach ( $default_zone->get_shipping_methods( true ) as $method ) {
            if ( 'free_shipping' === $method->id ) {
                return $method;
            }
        }

        return null;
    }

    /**
     * تصحيح تحقق ووكومرس للشحن المجاني بمقارنة الإجمالي بالحد الأدنى المحول
     */
    public function adjust_free_shipping_availability( bool $is_available, array $package, \WC_Shipping_Method $method ): bool {
        $min_amount = (float) $method->get_option( 'min_amount' );
        if ( $min_amount <= 0 ) {
            return $is_available;
        }

        $country = $package['destination']['country'] ?? '';
        if ( ! $country && WC()->customer ) {
            $country = WC()->customer->get_shipping_country();
        }
        if ( ! $country ) {
            $country = Masa_Geo_Helper::get_visitor_country();
        }
        $zone    = Masa_Geo_Helper::get_zone_for_country( $country );
        if ( ! $zone ) {
            return $is_available;
        }

        $currency = strtoupper( $zone['currency'] );
        $rate     = Masa_Geo_Api::get_rate( $currency );
        $converted_min = Masa_Geo_Rounding::round_price( $min_amount * $rate, $currency );

        $cart_subtotal = (float) WC()->cart->get_subtotal();

        // فحص الخصومات والكوبونات
        $ignore_discounts = 'no' === $method->get_option( 'ignore_discounts' );
        if ( $ignore_discounts && WC()->cart->has_discount() ) {
            $cart_subtotal -= WC()->cart->get_discount_total();
        }

        return ( $cart_subtotal >= $converted_min );
    }

    /**
     * رندر شريط التقدم على السلة والدفع
     */
    public function render_progress_bar(): void {
        if ( ! WC()->cart || WC()->cart->is_empty() ) {
            return;
        }

        $free_shipping = $this->get_active_free_shipping_method();
        if ( ! $free_shipping ) {
            return;
        }

        $min_amount = (float) $free_shipping->get_option( 'min_amount' );
        if ( $min_amount <= 0 ) {
            return;
        }

        $country = '';
        if ( WC()->customer ) {
            $country = WC()->customer->get_shipping_country();
        }
        if ( ! $country ) {
            $country = Masa_Geo_Helper::get_visitor_country();
        }
        $geo_zone = Masa_Geo_Helper::get_zone_for_country( $country );
        if ( ! $geo_zone ) {
            return;
        }

        $currency = strtoupper( $geo_zone['currency'] );
        $rate     = Masa_Geo_Api::get_rate( $currency );
        $converted_min = Masa_Geo_Rounding::round_price( $min_amount * $rate, $currency );

        $cart_subtotal = (float) WC()->cart->get_subtotal();

        // فحص طريقة احتساب الكوبونات (قبل أو بعد الخصم)
        $ignore_discounts = 'no' === $free_shipping->get_option( 'ignore_discounts' );
        if ( $ignore_discounts && WC()->cart->has_discount() ) {
            $cart_subtotal -= WC()->cart->get_discount_total();
        }

        wp_enqueue_style( 'masa-geo-frontend', MASA_GEO_PLUGIN_URL . 'assets/frontend.css', [], MASA_GEO_VERSION );

        echo '<div class="masa-geo-free-shipping-bar-wrapper">';

        if ( $cart_subtotal >= $converted_min ) {
            echo '<div class="masa-geo-free-shipping-text">';
            echo '🎉 <span>' . esc_html__( 'تهانينا! لقد حصلت على شحن مجاني لطلبك.', 'masa-geo-pricing' ) . '</span>';
            echo '</div>';
            echo '<div class="masa-geo-free-shipping-progress">';
            echo '<div class="masa-geo-free-shipping-progress-inner" style="width: 100%;"></div>';
            echo '</div>';
        } else {
            $remaining     = $converted_min - $cart_subtotal;
            $percentage    = min( 100, max( 0, ( $cart_subtotal / $converted_min ) * 100 ) );
            $formatted_rem = wc_price( $remaining, [ 'currency' => $currency ] );

            echo '<div class="masa-geo-free-shipping-text">';
            echo '🚚 <span>' . sprintf( 
                /* translators: %s: formatted remaining amount */
                esc_html__( 'أضف بقيمة %s إضافية إلى سلتك للحصول على شحن مجاني!', 'masa-geo-pricing' ), 
                $formatted_rem 
            ) . '</span>';
            echo '</div>';
            echo '<div class="masa-geo-free-shipping-progress">';
            echo '<div class="masa-geo-free-shipping-progress-inner" style="width: ' . esc_attr( $percentage ) . '%;"></div>';
            echo '</div>';
        }

        echo '</div>';
    }
}
