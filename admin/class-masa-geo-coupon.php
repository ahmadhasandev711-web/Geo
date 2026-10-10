<?php
defined( 'ABSPATH' ) || exit;

/**
 * Masa_Geo_Coupon
 * تقييد الكوبونات على Zones معينة
 * مثال: كوبون EGYPT20 يشتغل بس للـ Zone المصري
 */
final class Masa_Geo_Coupon {

    private static ?Masa_Geo_Coupon $instance = null;

    const META_KEY = '_masa_geo_zone_restriction'; // array of zone IDs

    public static function instance(): Masa_Geo_Coupon {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // تحقق من الـ Coupon عند إضافته للـ Cart
        add_filter( 'woocommerce_coupon_is_valid', [ $this, 'validate_coupon_zone' ], 10, 3 );

        // أضف حقل الـ Zone لصفحة الكوبون في الـ Admin
        add_action( 'woocommerce_coupon_options',       [ $this, 'render_coupon_field' ], 10, 2 );
        add_action( 'woocommerce_coupon_options_save',  [ $this, 'save_coupon_field' ], 10, 2 );
    }

    // ─────────────────────────────────────────────────────────
    // تحقق من صلاحية الكوبون
    // ─────────────────────────────────────────────────────────
    public function validate_coupon_zone( bool $valid, \WC_Coupon $coupon, \WC_Discounts $discounts ): bool {
        if ( ! $valid ) return false;

        $coupon_id   = $coupon->get_id();
        $restrictions = get_post_meta( $coupon_id, self::META_KEY, true );

        // لو مفيش restrictions على الكوبون ده → اسمح بيه
        if ( empty( $restrictions ) || ! is_array( $restrictions ) ) {
            return true;
        }

        // جيب الـ Zone الحالية للزائر
        $visitor_country = Masa_Geo_Helper::get_visitor_country();
        $visitor_zone    = Masa_Geo_Helper::get_zone_for_country( $visitor_country );

        if ( ! $visitor_zone ) {
            // الزائر مش في أي zone → امنع الكوبون
            add_filter( 'woocommerce_coupon_error', function( $err, $err_code, $coupon_obj ) use ( $coupon ) {
                return $err;
            }, 10, 3 );

            throw new \Exception(
                __( 'هذا الكوبون غير متاح في منطقتك الجغرافية.', 'masa-geo-pricing' ),
                100
            );
        }

        $visitor_zone_id = $visitor_zone['id'];

        // هل الـ Zone بتاع الزائر في القائمة المسموح بيها؟
        if ( in_array( $visitor_zone_id, $restrictions, true ) ) {
            return true;
        }

        // Zone غير مسموح بيه
        throw new \Exception(
            sprintf(
                /* translators: %s: اسم المنطقة */
                __( 'هذا الكوبون متاح فقط لزوار %s.', 'masa-geo-pricing' ),
                $this->get_allowed_zones_label( $restrictions )
            ),
            100
        );
    }

    // ─────────────────────────────────────────────────────────
    // Render حقل الـ Zones في صفحة الكوبون
    // ─────────────────────────────────────────────────────────
    public function render_coupon_field( int $coupon_id, \WC_Coupon $coupon ): void {
        $settings    = get_option( MASA_GEO_OPTION_KEY, [] );
        $zones       = $settings['zones'] ?? [];
        $saved       = get_post_meta( $coupon_id, self::META_KEY, true );
        $saved       = is_array( $saved ) ? $saved : [];
        ?>
        <div class="options_group" dir="rtl" style="text-align:right;">
            <p class="form-field">
                <label style="display:block;font-weight:600;margin-bottom:8px;">
                    🌍 <?php esc_html_e( 'تقييد المناطق الجغرافية (Masa Geo)', 'masa-geo-pricing' ); ?>
                </label>
                <span class="description" style="display:block;margin-bottom:10px;color:#6b7280;font-size:12px;">
                    <?php esc_html_e( 'اختر المناطق التي يُسمح فيها باستخدام هذا الكوبون. اتركها فارغة للسماح لجميع المناطق.', 'masa-geo-pricing' ); ?>
                </span>

                <?php if ( empty( $zones ) ) : ?>
                    <em style="color:#9ca3af;font-size:12px;">
                        <?php esc_html_e( 'لا توجد مناطق تسعير معرفة بعد.', 'masa-geo-pricing' ); ?>
                    </em>
                <?php else : ?>
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:8px;">
                        <?php foreach ( $zones as $zone ) :
                            $zone_id     = $zone['id'];
                            $is_checked  = in_array( $zone_id, $saved, true );
                            $is_fallback = in_array( '__FALLBACK__', $zone['countries'] ?? [], true );
                        ?>
                            <label style="
                                display:flex;
                                align-items:center;
                                gap:8px;
                                padding:8px 10px;
                                border:1px solid <?php echo $is_checked ? '#2563eb' : '#e5e7eb'; ?>;
                                border-radius:6px;
                                cursor:pointer;
                                background:<?php echo $is_checked ? '#eff6ff' : '#fff'; ?>;
                                font-size:13px;
                            ">
                                <input
                                    type="checkbox"
                                    name="masa_geo_coupon_zones[]"
                                    value="<?php echo esc_attr( $zone_id ); ?>"
                                    <?php checked( $is_checked ); ?>
                                    style="margin:0;"
                                />
                                <span>
                                    <?php echo esc_html( $zone['label'] ); ?>
                                    <?php if ( $is_fallback ) : ?>
                                        <small style="color:#9ca3af;">(افتراضي)</small>
                                    <?php endif; ?>
                                    <br>
                                    <small style="color:#6b7280;"><?php echo esc_html( $zone['currency'] ); ?></small>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </p>
        </div>
        <?php
    }

    // ─────────────────────────────────────────────────────────
    // حفظ حقل الـ Zones
    // ─────────────────────────────────────────────────────────
    public function save_coupon_field( int $coupon_id, \WC_Coupon $coupon ): void {
        // WooCommerce بيعمل nonce verification للـ coupon form قبل ما يستدعي الـ hook ده
        // لكن نتحقق من الصلاحية بشكل صريح
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $raw   = isset( $_POST['masa_geo_coupon_zones'] )
            ? (array) wp_unslash( $_POST['masa_geo_coupon_zones'] )
            : [];

        $clean = array_map( 'sanitize_key', $raw );
        $clean = array_filter( $clean );

        if ( empty( $clean ) ) {
            delete_post_meta( $coupon_id, self::META_KEY );
        } else {
            update_post_meta( $coupon_id, self::META_KEY, array_values( $clean ) );
        }
    }

    // ─────────────────────────────────────────────────────────
    // Helper: اسم الـ Zones المسموح بيها
    // ─────────────────────────────────────────────────────────
    private function get_allowed_zones_label( array $zone_ids ): string {
        $settings = get_option( MASA_GEO_OPTION_KEY, [] );
        $zones    = $settings['zones'] ?? [];
        $labels   = [];

        foreach ( $zones as $zone ) {
            if ( in_array( $zone['id'], $zone_ids, true ) ) {
                $labels[] = $zone['label'];
            }
        }

        return implode( ' و ', $labels ) ?: implode( ', ', $zone_ids );
    }
}
