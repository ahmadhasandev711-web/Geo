<?php
defined( 'ABSPATH' ) || exit;

/**
 * Masa_Geo_Minimum_Order
 * إدارة الحد الأدنى للطلب لكل منطقة تسعير
 */
final class Masa_Geo_Minimum_Order {

    private static ?Masa_Geo_Minimum_Order $instance = null;

    public static function instance(): Masa_Geo_Minimum_Order {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // التحقق من السلة والـ checkout الكلاسيكي
        add_action( 'woocommerce_check_cart_items', [ $this, 'check_minimum_order' ] );
        add_action( 'woocommerce_checkout_process',   [ $this, 'check_minimum_order' ] );

        // التحقق من الـ Blocks Checkout (Store API)
        add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ $this, 'validate_blocks_minimum_order' ], 10, 2 );
    }

    /**
     * التحقق من الحد الأدنى للطلب في السلة والـ Checkout
     */
    public function check_minimum_order(): void {
        if ( is_admin() && ! wp_doing_ajax() ) {
            return;
        }

        if ( ! WC()->cart || WC()->cart->is_empty() ) {
            return;
        }

        $country = Masa_Geo_Helper::get_visitor_country();
        $zone    = Masa_Geo_Helper::get_zone_for_country( $country );

        if ( ! $zone || empty( $zone['minimum_order'] ) ) {
            return;
        }

        $min_order = (float) $zone['minimum_order'];
        if ( $min_order <= 0 ) {
            return;
        }

        // إجمالي السلة بالعملة المحلية (WooCommerce يحسبه باستخدام get_price() المفلترة بالفعل)
        $cart_subtotal = (float) WC()->cart->get_subtotal();

        if ( $cart_subtotal < $min_order ) {
            $currency = strtoupper( $zone['currency'] ?? 'EGP' );
            $formatted_min = strip_tags( html_entity_decode( wc_price( $min_order, [ 'currency' => $currency ] ) ) );
            
            $message = sprintf(
                __( 'الحد الأدنى لإتمام الطلب في منطقتك هو %s. يرجى إضافة المزيد من المنتجات.', 'masa-geo-pricing' ),
                $formatted_min
            );

            // التحقق من عدم وجود نفس الرسالة في إشعارات ووكومرس لتجنب التكرار
            if ( function_exists( 'wc_has_notice' ) && wc_has_notice( $message, 'error' ) ) {
                return;
            }

            wc_add_notice( $message, 'error' );
        }
    }

    /**
     * التحقق من الحد الأدنى في الـ Blocks Checkout (Store API)
     */
    public function validate_blocks_minimum_order( \WC_Order $order, $request ): void {
        $billing_country = strtoupper( (string) $order->get_billing_country() );
        if ( empty( $billing_country ) ) {
            $billing_country = Masa_Geo_Helper::get_visitor_country();
        }

        $zone = Masa_Geo_Helper::get_zone_for_country( $billing_country );
        if ( ! $zone || empty( $zone['minimum_order'] ) ) {
            return;
        }

        $min_order = (float) $zone['minimum_order'];
        if ( $min_order <= 0 ) {
            return;
        }

        // حساب إجمالي المنتجات للطلب الحالي قبل الشحن والضرائب
        $order_subtotal = 0.0;
        foreach ( $order->get_items() as $item ) {
            $order_subtotal += (float) $item->get_subtotal();
        }

        if ( $order_subtotal < $min_order ) {
            $currency = strtoupper( $zone['currency'] ?? 'EGP' );
            $formatted_min = strip_tags( html_entity_decode( wc_price( $min_order, [ 'currency' => $currency ] ) ) );
            $message = sprintf(
                __( 'الحد الأدنى لإتمام الطلب في منطقتك هو %s. يرجى إضافة المزيد من المنتجات.', 'masa-geo-pricing' ),
                $formatted_min
            );

            if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
                throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
                    'masa_geo_minimum_order_error',
                    $message,
                    400
                );
            }

            throw new \Exception( $message );
        }
    }
}
