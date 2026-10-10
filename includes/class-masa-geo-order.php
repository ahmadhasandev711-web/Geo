<?php
defined( 'ABSPATH' ) || exit;

/**
 * Masa_Geo_Order
 * يحفظ بيانات الـ Zone والـ Country في كل أوردر
 * ضروري للـ Refunds والـ Reports والـ Dashboard
 */
final class Masa_Geo_Order {

    private static ?Masa_Geo_Order $instance = null;

    // مفاتيح الـ Order Meta
    const META_COUNTRY      = '_masa_geo_country';
    const META_ZONE_ID      = '_masa_geo_zone_id';
    const META_ZONE_LABEL   = '_masa_geo_zone_label';
    const META_CURRENCY     = '_masa_geo_currency';
    const META_EGP_SUBTOTAL = '_masa_geo_egp_subtotal'; // إجمالي الأوردر بالجنيه

    public static function instance(): Masa_Geo_Order {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // woocommerce_checkout_order_created متاح من WC 8.2+
        // WC_VERSION ثابت دايماً متاح لما WooCommerce محمّل
        $wc_version = defined( 'WC_VERSION' ) ? WC_VERSION : '0';
        if ( version_compare( $wc_version, '8.2', '>=' ) ) {
            add_action( 'woocommerce_checkout_order_created', [ $this, 'lock_order_geo_data' ], 10, 1 );
        } else {
            add_action( 'woocommerce_checkout_order_processed', [ $this, 'lock_order_geo_data_legacy' ], 10, 3 );
        }

        // دعم الـ WooCommerce Checkout Blocks (Store API)
        add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'lock_order_geo_data' ], 10, 1 );

        // دعم للأوردرات اللي بتتعمل يدوياً من لوحة التحكم أو عبر الـ API العادي
        add_action( 'woocommerce_process_shop_order_meta', [ $this, 'lock_order_geo_data_manual' ], 10, 1 );
        add_action( 'woocommerce_saved_order_items', [ $this, 'lock_order_geo_data_manual' ], 10, 1 );


        // اعرض البيانات دي في صفحة الأوردر في الـ Admin
        add_action( 'woocommerce_admin_order_data_after_billing_address', [ $this, 'display_in_admin' ], 10, 1 );

        // عمود إضافي في قائمة الأوردرات
        add_filter( 'manage_woocommerce_page_wc-orders_columns', [ $this, 'add_order_column' ] );
        add_action( 'manage_woocommerce_page_wc-orders_custom_column', [ $this, 'render_order_column' ], 10, 2 );

        // دعم الـ Orders القديم (WC < 7.9)
        add_filter( 'manage_edit-shop_order_columns', [ $this, 'add_order_column' ] );
        add_action( 'manage_shop_order_posts_custom_column', [ $this, 'render_order_column_legacy' ], 10, 2 );
    }

    // Legacy WC < 8.2: woocommerce_checkout_order_processed passes order_id not object
    public function lock_order_geo_data_legacy( int $order_id, array $posted_data, \WC_Order $order ): void {
        $this->lock_order_geo_data( $order );
    }

    public function lock_order_geo_data_manual( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( $order ) {
            // نمنع الحفظ المتكرر لو الداتا موجودة أصلاً عشان الأداء
            if ( ! $order->get_meta( self::META_ZONE_ID ) ) {
                $this->lock_order_geo_data( $order );
            }
        }
    }

    // ─────────────────────────────────────────────────────────
    // احفظ بيانات الـ Zone في الأوردر
    // ─────────────────────────────────────────────────────────
    public function lock_order_geo_data( \WC_Order $order ): void {
        // البلد من الـ Billing (ده المعتمد بعد الـ checkout)
        $country = strtoupper( $order->get_billing_country() );

        if ( ! $country ) {
            $country = Masa_Geo_Helper::get_visitor_country();
        }

        $zone = Masa_Geo_Helper::get_zone_for_country( $country );

        if ( ! $zone ) return;

        // احفظ البيانات
        $order->update_meta_data( self::META_COUNTRY,    $country );
        $order->update_meta_data( self::META_ZONE_ID,    $zone['id'] );
        $order->update_meta_data( self::META_ZONE_LABEL, $zone['label'] );
        $order->update_meta_data( self::META_CURRENCY,   $zone['currency'] );

        // احسب الإجمالي بالجنيه (للـ Reports)
        $egp_subtotal = $this->calculate_egp_subtotal( $order, $zone );
        $order->update_meta_data( self::META_EGP_SUBTOTAL, $egp_subtotal );

        $order->save();
    }

    // ─────────────────────────────────────────────────────────
    // احسب الإجمالي بالجنيه المصري
    // ─────────────────────────────────────────────────────────
    private function calculate_egp_subtotal( \WC_Order $order, array $zone ): float {
        $egp_total = 0.0;
        $zone_id   = $zone['id'];

        foreach ( $order->get_items() as $item ) {
            /** @var \WC_Order_Item_Product $item */
            $product_id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
            $qty        = $item->get_quantity();

            // منتج مستثنى من التسعير الجغرافي → استخدم سعره الأصلي
            if ( Masa_Geo_Product::is_excluded( $product_id ) ) {
                $egp_total += (float) get_post_meta( $product_id, '_price', true ) * $qty;
                continue;
            }

            $base_price = (float) get_post_meta( $product_id, '_price', true );

            // أولوية: Timed → Static Override → Multiplier
            $timed = Masa_Geo_Timed_Pricing::get_active_price( $product_id, $zone_id );
            if ( $timed !== null ) {
                $egp_price = $timed;
            } else {
                $override  = Masa_Geo_Product::get_override_price( $product_id, $zone_id );
                $egp_price = $override !== null
                    ? $override
                    : ( $base_price * (float) $zone['multiplier'] );
            }

            $egp_total += $egp_price * $qty;
        }

        return round( $egp_total, 2 );
    }

    // ─────────────────────────────────────────────────────────
    // اعرض في صفحة الأوردر في الـ Admin
    // ─────────────────────────────────────────────────────────
    public function display_in_admin( \WC_Order $order ): void {
        $country      = $order->get_meta( self::META_COUNTRY );
        $zone_label   = $order->get_meta( self::META_ZONE_LABEL );
        $currency     = $order->get_meta( self::META_CURRENCY );
        $egp_subtotal = $order->get_meta( self::META_EGP_SUBTOTAL );

        if ( ! $country ) return;
        ?>
        <div class="masa-order-geo-box" style="
            margin-top:12px;
            padding:10px 14px;
            background:#f0f9ff;
            border:1px solid #bae6fd;
            border-radius:6px;
            direction:rtl;
            text-align:right;
        ">
            <strong style="color:#0369a1;display:block;margin-bottom:6px;">
                🌍 <?php esc_html_e( 'بيانات التسعير الجغرافي', 'masa-geo-pricing' ); ?>
            </strong>
            <table style="width:100%;font-size:12px;color:#374151;">
                <tr>
                    <td style="padding:2px 0;color:#6b7280;"><?php esc_html_e( 'بلد الزائر:', 'masa-geo-pricing' ); ?></td>
                    <td style="font-weight:600;"><?php echo esc_html( $country ); ?></td>
                </tr>
                <tr>
                    <td style="padding:2px 0;color:#6b7280;"><?php esc_html_e( 'منطقة التسعير:', 'masa-geo-pricing' ); ?></td>
                    <td style="font-weight:600;"><?php echo esc_html( $zone_label ); ?></td>
                </tr>
                <tr>
                    <td style="padding:2px 0;color:#6b7280;"><?php esc_html_e( 'العملة المستخدمة:', 'masa-geo-pricing' ); ?></td>
                    <td style="font-weight:600;"><?php echo esc_html( $currency ); ?></td>
                </tr>
                <?php if ( $egp_subtotal ) : ?>
                <tr>
                    <td style="padding:2px 0;color:#6b7280;"><?php esc_html_e( 'إجمالي EGP:', 'masa-geo-pricing' ); ?></td>
                    <td style="font-weight:600;"><?php echo esc_html( number_format( $egp_subtotal, 2 ) ); ?> ج.م</td>
                </tr>
                <?php endif; ?>
            </table>
        </div>
        <?php
    }

    // ─────────────────────────────────────────────────────────
    // عمود في قائمة الأوردرات (WC جديد)
    // ─────────────────────────────────────────────────────────
    public function add_order_column( array $columns ): array {
        $new = [];
        foreach ( $columns as $key => $label ) {
            $new[ $key ] = $label;
            if ( $key === 'order_total' ) {
                $new['masa_geo_zone'] = __( 'المنطقة', 'masa-geo-pricing' );
            }
        }
        return $new;
    }

    public function render_order_column( string $column, $order_or_id ): void {
        if ( $column !== 'masa_geo_zone' ) return;

        // HPOS بيمرر object عادة، لكن في بعض الحالات بيمرر ID — نتعامل مع الاتنين
        $order = $order_or_id instanceof \WC_Order ? $order_or_id : wc_get_order( $order_or_id );
        if ( ! $order ) {
            echo '<span style="color:#9ca3af;">—</span>';
            return;
        }

        $zone_label = $order->get_meta( self::META_ZONE_LABEL );
        $country    = $order->get_meta( self::META_COUNTRY );
        $currency   = $order->get_meta( self::META_CURRENCY );

        if ( $zone_label ) {
            echo '<span style="font-size:11px;background:#dbeafe;color:#1e40af;padding:2px 6px;border-radius:4px;">';
            echo esc_html( $zone_label );
            echo '</span>';
            echo '<br><small style="color:#6b7280;">' . esc_html( $country ) . ' · ' . esc_html( $currency ) . '</small>';
        } else {
            echo '<span style="color:#9ca3af;">—</span>';
        }
    }

    public function render_order_column_legacy( string $column, int $post_id ): void {
        $this->render_order_column( $column, $post_id );
    }

    // ─────────────────────────────────────────────────────────
    // Static Helpers للـ Dashboard
    // ─────────────────────────────────────────────────────────
    public static function get_revenue_by_zone( string $from = '', string $to = '' ): array {
        // لو مفيش تاريخ محدد → آخر 6 شهور (حماية من full-table scan)
        if ( ! $from && ! $to ) {
            $from = gmdate( 'Y-m-d', strtotime( '-6 months' ) );
        }

        $args = [
            'status' => [ 'wc-completed', 'wc-processing' ],
            'limit'  => -1,
            'return' => 'objects',
        ];

        // date_created في wc_get_orders بيقبل range بشكل: 'YYYY-MM-DD...YYYY-MM-DD'
        if ( $from && $to ) {
            $args['date_created'] = $from . '...' . $to;
        } elseif ( $from ) {
            $args['date_created'] = '>=' . $from;
        } elseif ( $to ) {
            $args['date_created'] = '<=' . $to;
        }

        $orders = wc_get_orders( $args );
        $zones  = [];

        foreach ( $orders as $order ) {
            $zone_id    = $order->get_meta( self::META_ZONE_ID );
            $zone_label = $order->get_meta( self::META_ZONE_LABEL );
            $currency   = $order->get_meta( self::META_CURRENCY );
            $egp_sub    = (float) $order->get_meta( self::META_EGP_SUBTOTAL );
            $local_total = (float) $order->get_total();

            if ( ! $zone_id ) continue;

            if ( ! isset( $zones[ $zone_id ] ) ) {
                $zones[ $zone_id ] = [
                    'label'       => $zone_label,
                    'currency'    => $currency,
                    'orders'      => 0,
                    'egp_revenue' => 0.0,
                    'local_revenue' => 0.0,
                ];
            }

            $zones[ $zone_id ]['orders']++;
            $zones[ $zone_id ]['egp_revenue']   += $egp_sub;
            $zones[ $zone_id ]['local_revenue']  += $local_total;
        }

        // ترتيب حسب الـ EGP revenue
        uasort( $zones, fn( $a, $b ) => $b['egp_revenue'] <=> $a['egp_revenue'] );

        return $zones;
    }

    public static function get_orders_by_country( int $limit = 10 ): array {
        $orders   = wc_get_orders([
            'status' => [ 'wc-completed', 'wc-processing' ],
            'limit'  => -1,
            'return' => 'objects',
        ]);

        $countries = [];

        foreach ( $orders as $order ) {
            $country = $order->get_meta( self::META_COUNTRY );
            if ( ! $country ) continue;

            if ( ! isset( $countries[ $country ] ) ) {
                $countries[ $country ] = [ 'orders' => 0, 'egp_revenue' => 0.0 ];
            }
            $countries[ $country ]['orders']++;
            $countries[ $country ]['egp_revenue'] += (float) $order->get_meta( self::META_EGP_SUBTOTAL );
        }

        uasort( $countries, fn( $a, $b ) => $b['egp_revenue'] <=> $a['egp_revenue'] );
        return array_slice( $countries, 0, $limit, true );
    }
}
