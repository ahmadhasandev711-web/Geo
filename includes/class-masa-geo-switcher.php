<?php
defined( 'ABSPATH' ) || exit;

/**
 * Masa_Geo_Switcher
 * Currency Switcher — الزائر يقدر يغير عرض العملة
 * لكن السعر الأساسي بيفضل محسوب من الـ Zone بتاعته
 *
 * مثال:
 * زائر سعودي → Zone SA → السعر الأساسي = 150 EGP
 * لو اختار يعرض بالدولار → 150 EGP × USD rate = $3.06
 * مش 100 EGP (السعر المصري) × USD rate = $2.04
 */
final class Masa_Geo_Switcher {

    private static ?Masa_Geo_Switcher $instance = null;

    const COOKIE_DISPLAY = 'masa_geo_display_currency'; // العملة المختارة للعرض

    public static function instance(): Masa_Geo_Switcher {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // AJAX: تغيير العملة
        add_action( 'wp_ajax_masa_geo_switch_currency',        [ $this, 'ajax_switch_currency' ] );
        add_action( 'wp_ajax_nopriv_masa_geo_switch_currency', [ $this, 'ajax_switch_currency' ] );

        // Shortcode: [masa_currency_switcher]
        add_shortcode( 'masa_currency_switcher', [ $this, 'render_switcher' ] );

        // ضيف الـ Switcher تلقائياً لو الإعداد شغال
        add_action( 'wp_footer', [ $this, 'maybe_render_floating_switcher' ] );

        // Enqueue assets
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    // ─────────────────────────────────────────────────────────
    // جيب العملة المختارة للعرض
    // (مختلفة عن عملة الـ Zone — دي بس للعرض)
    // ─────────────────────────────────────────────────────────
    public static function get_display_currency(): string {
        if ( ! empty( $_COOKIE[ self::COOKIE_DISPLAY ] ) ) {
            $c = strtoupper( sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_DISPLAY ] ) ) );
            if ( preg_match( '/^[A-Z]{3}$/', $c ) ) {
                return $c;
            }
        }
        return ''; // فارغ = استخدم عملة الـ Zone الأصلية
    }

    /**
     * احسب السعر النهائي مع مراعاة الـ Display Currency
     *
     * المنطق:
     * 1. احسب سعر EGP للزائر ده (من multiplier أو override)
     * 2. لو اختار display currency مختلفة → حوّل من EGP للـ display currency
     * 3. لو مختارش → حوّل للعملة الأصلية للـ Zone
     */
    public static function calculate_with_display(
        int $product_id,
        float $base_egp,
        string $visitor_country
    ): array {
        $zone = Masa_Geo_Helper::get_zone_for_country( $visitor_country );

        if ( ! $zone ) {
            return [
                'final_price' => $base_egp,
                'currency'    => 'EGP',
                'egp_price'   => $base_egp,
            ];
        }

        // الـ EGP price للـ Zone بتاعته (مش السعر المصري)
        // أولوية: Timed → Static Override → Multiplier
        $timed_price = Masa_Geo_Timed_Pricing::get_active_price( $product_id, $zone['id'] );
        if ( $timed_price !== null ) {
            $egp_price = $timed_price;
        } else {
            $override  = Masa_Geo_Product::get_override_price( $product_id, $zone['id'] );
            $egp_price = $override !== null
                ? $override
                : ( $base_egp * (float) $zone['multiplier'] );
        }

        // هل اختار display currency مختلفة؟
        $display_currency = self::get_display_currency();

        $target_currency = $display_currency ?: strtoupper( $zone['currency'] );

        // تحويل من EGP للعملة المطلوبة
        $rate        = Masa_Geo_Api::get_rate( $target_currency );
        $final_price = Masa_Geo_Rounding::round_price( $egp_price * $rate, $target_currency );

        return [
            'final_price'      => $final_price,
            'currency'         => $target_currency,
            'egp_price'        => $egp_price,
            'zone_currency'    => $zone['currency'],   // العملة الأصلية للـ Zone
            'display_changed'  => ! empty( $display_currency ) && $display_currency !== $zone['currency'],
        ];
    }

    // ─────────────────────────────────────────────────────────
    // AJAX: تغيير العملة وتحديث الـ Cookie
    // ─────────────────────────────────────────────────────────
    public function ajax_switch_currency(): void {
        // v3.4.0: nonce حتى للـ public action — حماية من CSRF
        check_ajax_referer( 'masa_geo_switcher', 'nonce' );

        $currency = isset( $_POST['currency'] )
            ? strtoupper( sanitize_text_field( wp_unslash( $_POST['currency'] ) ) )
            : '';

        // تحقق من الـ Currency صح
        if ( ! preg_match( '/^[A-Z]{3}$/', $currency ) ) {
            wp_send_json_error( [ 'message' => 'عملة غير صحيحة.' ] );
        }

        // تحقق إن الـ rate موجود
        $rate = Masa_Geo_Api::get_rate( $currency );
        if ( $rate <= 0 ) {
            wp_send_json_error( [ 'message' => 'لا يوجد سعر صرف لهذه العملة.' ] );
        }

        // احفظ في Cookie
        setcookie(
            self::COOKIE_DISPLAY,
            $currency,
            [
                'expires'  => time() + ( 24 * HOUR_IN_SECONDS ),
                'path'     => COOKIEPATH ?: '/',
                'secure'   => is_ssl(),
                'httponly' => false, // JS محتاج يقراه
                'samesite' => 'Lax',
            ]
        );

        wp_send_json_success( [
            'message'  => 'تم تغيير العملة.',
            'currency' => $currency,
            'reload'   => true, // الصفحة محتاجة تتحدث
        ] );
    }

    // ─────────────────────────────────────────────────────────
    // Render الـ Switcher Widget
    // ─────────────────────────────────────────────────────────
    public function render_switcher( array $atts = [] ): string {
        $settings = get_option( MASA_GEO_OPTION_KEY, [] );

        if ( empty( $settings['switcher_enabled'] ) ) return '';

        $visitor_country  = Masa_Geo_Helper::get_visitor_country();
        $zone             = Masa_Geo_Helper::get_zone_for_country( $visitor_country );
        $current_display  = self::get_display_currency() ?: ( $zone['currency'] ?? 'EGP' );

        // العملات المتاحة = عملات كل الـ Zones
        $available = $this->get_available_currencies();

        // لو مفيش غير عملة واحدة → مش محتاج switcher
        if ( count( $available ) <= 1 ) return '';

        ob_start();
        ?>
        <div class="masa-currency-switcher" dir="rtl">
            <label class="masa-switcher-label">
                💱 <?php esc_html_e( 'العملة:', 'masa-geo-pricing' ); ?>
            </label>
            <select class="masa-switcher-select" id="masa-currency-select">
                <?php foreach ( $available as $code => $label ) : ?>
                    <option value="<?php echo esc_attr( $code ); ?>"
                        <?php selected( $current_display, $code ); ?>>
                        <?php echo esc_html( $label ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php
        return ob_get_clean();
    }

    // ─────────────────────────────────────────────────────────
    // Floating Switcher (يظهر تلقائياً في الـ Footer)
    // ─────────────────────────────────────────────────────────
    public function maybe_render_floating_switcher(): void {
        $settings = get_option( MASA_GEO_OPTION_KEY, [] );

        if ( empty( $settings['switcher_enabled'] ) ) return;
        if ( empty( $settings['switcher_floating'] ) ) return;
        if ( is_admin() ) return;

        echo '<div class="masa-floating-switcher">';
        echo wp_kses_post( $this->render_switcher() );
        echo '</div>';
    }

    // ─────────────────────────────────────────────────────────
    // العملات المتاحة = من كل الـ Zones
    // ─────────────────────────────────────────────────────────
    private function get_available_currencies(): array {
        $settings = get_option( MASA_GEO_OPTION_KEY, [] );
        $zones    = $settings['zones'] ?? [];
        $result   = [];

        $symbols = [
            'EGP' => 'جنيه مصري (EGP)',
            'SAR' => 'ريال سعودي (SAR)',
            'AED' => 'درهم إماراتي (AED)',
            'USD' => 'دولار أمريكي (USD)',
            'EUR' => 'يورو (EUR)',
            'GBP' => 'جنيه إسترليني (GBP)',
            'KWD' => 'دينار كويتي (KWD)',
            'QAR' => 'ريال قطري (QAR)',
            'BHD' => 'دينار بحريني (BHD)',
            'OMR' => 'ريال عماني (OMR)',
            'JOD' => 'دينار أردني (JOD)',
        ];

        foreach ( $zones as $zone ) {
            $c = strtoupper( $zone['currency'] ?? '' );
            if ( $c ) {
                $result[ $c ] = $symbols[ $c ] ?? $c;
            }
        }

        return $result;
    }

    // ─────────────────────────────────────────────────────────
    // Assets
    // ─────────────────────────────────────────────────────────
    public function enqueue_assets(): void {
        $settings = get_option( MASA_GEO_OPTION_KEY, [] );
        if ( empty( $settings['switcher_enabled'] ) ) return;

        wp_enqueue_style(
            'masa-geo-frontend',
            MASA_GEO_PLUGIN_URL . 'assets/frontend.css',
            [],
            MASA_GEO_VERSION
        );

        wp_enqueue_script(
            'masa-geo-frontend',
            MASA_GEO_PLUGIN_URL . 'assets/frontend.js',
            [ 'jquery' ],
            MASA_GEO_VERSION,
            true
        );

        wp_localize_script( 'masa-geo-frontend', 'MasaGeoFront', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'masa_geo_switcher' ),
        ] );
    }
}
