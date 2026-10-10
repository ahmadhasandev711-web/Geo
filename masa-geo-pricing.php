<?php
/**
 * Plugin Name: Masa Geo-Pricing & Currency Manager
 * Plugin URI:  https://github.com/ahmadhasandev711-web/Geo
 * Description: تسعير جغرافي ذكي بناءً على بلد الزائر مع دعم أسعار الصرف الحية
 * Version:     4.1.0
 * Author:      Ahmad Hasan
 * Author URI:  https://ahmad-hasan.vercel.app/
 * Text Domain: masa-geo-pricing
 * Domain Path: /languages
 * Requires at least: 6.4
 * Tested up to: 6.6
 * Requires PHP: 8.0
 * WC requires at least: 8.0
 * WC tested up to: 10.8
 */

defined( 'ABSPATH' ) || exit;

define( 'MASA_GEO_VERSION',     '4.1.0' );
define( 'MASA_GEO_PLUGIN_FILE', __FILE__ );
define( 'MASA_GEO_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'MASA_GEO_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'MASA_GEO_OPTION_KEY',  'masa_geo_settings' );

spl_autoload_register( function ( string $class ): void {
    if ( strpos( $class, 'Masa_Geo_' ) !== 0 ) return;
    $map = [
        'Masa_Geo_Helper'       => MASA_GEO_PLUGIN_DIR . 'includes/class-masa-geo-helper.php',
        'Masa_Geo_Api'          => MASA_GEO_PLUGIN_DIR . 'includes/class-masa-geo-api.php',
        'Masa_Geo_Rounding'     => MASA_GEO_PLUGIN_DIR . 'includes/class-masa-geo-rounding.php',
        'Masa_Geo_Core'         => MASA_GEO_PLUGIN_DIR . 'includes/class-masa-geo-core.php',
        'Masa_Geo_Order'        => MASA_GEO_PLUGIN_DIR . 'includes/class-masa-geo-order.php',
        'Masa_Geo_Alerts'       => MASA_GEO_PLUGIN_DIR . 'includes/class-masa-geo-alerts.php',
        'Masa_Geo_Switcher'     => MASA_GEO_PLUGIN_DIR . 'includes/class-masa-geo-switcher.php',
        'Masa_Geo_Shipping'     => MASA_GEO_PLUGIN_DIR . 'includes/class-masa-geo-shipping.php',
        'Masa_Geo_Blocks'       => MASA_GEO_PLUGIN_DIR . 'includes/class-masa-geo-blocks.php',
        'Masa_Geo_Timed_Pricing'=> MASA_GEO_PLUGIN_DIR . 'includes/class-masa-geo-timed-pricing.php',
        'Masa_Geo_Activity_Log' => MASA_GEO_PLUGIN_DIR . 'includes/class-masa-geo-activity-log.php',
        'Masa_Geo_Visibility'   => MASA_GEO_PLUGIN_DIR . 'includes/class-masa-geo-visibility.php',
        'Masa_Geo_Compat'       => MASA_GEO_PLUGIN_DIR . 'includes/class-masa-geo-compat.php',
        'Masa_Geo_Zone_Stock'   => MASA_GEO_PLUGIN_DIR . 'includes/class-masa-geo-zone-stock.php',
        'Masa_Geo_Admin'        => MASA_GEO_PLUGIN_DIR . 'admin/class-masa-geo-admin.php',
        'Masa_Geo_Product'      => MASA_GEO_PLUGIN_DIR . 'admin/class-masa-geo-product.php',
        'Masa_Geo_Coupon'       => MASA_GEO_PLUGIN_DIR . 'admin/class-masa-geo-coupon.php',
        'Masa_Geo_Dashboard'    => MASA_GEO_PLUGIN_DIR . 'admin/class-masa-geo-dashboard.php',
        'Masa_Geo_Tools'        => MASA_GEO_PLUGIN_DIR . 'admin/class-masa-geo-tools.php',
        'Masa_Geo_Visibility_Admin' => MASA_GEO_PLUGIN_DIR . 'admin/class-masa-geo-visibility-admin.php',
        'Masa_Geo_Status'       => MASA_GEO_PLUGIN_DIR . 'admin/class-masa-geo-status.php',
        'Masa_Geo_Whatsapp'     => MASA_GEO_PLUGIN_DIR . 'includes/class-masa-geo-whatsapp.php',
        'Masa_Geo_Minimum_Order'=> MASA_GEO_PLUGIN_DIR . 'includes/class-masa-geo-minimum-order.php',
        'Masa_Geo_Free_Shipping_Bar'=> MASA_GEO_PLUGIN_DIR . 'includes/class-masa-geo-free-shipping-bar.php',
        'Masa_Geo_Email'        => MASA_GEO_PLUGIN_DIR . 'includes/class-masa-geo-email.php',
        // Affiliates & Brands Classes (v3.9.0)
        'Masa_Geo_Affiliate_Core'       => MASA_GEO_PLUGIN_DIR . 'modules/affiliates/class-masa-affiliate-core.php',
        'Masa_Geo_Affiliate_Tracker'    => MASA_GEO_PLUGIN_DIR . 'modules/affiliates/class-masa-affiliate-tracker.php',
        'Masa_Geo_Affiliate_Commission' => MASA_GEO_PLUGIN_DIR . 'modules/affiliates/class-masa-affiliate-commission.php',
        'Masa_Geo_Affiliate_Admin'      => MASA_GEO_PLUGIN_DIR . 'modules/affiliates/admin/class-masa-affiliate-admin.php',
        'Masa_Geo_Affiliate_Dashboard'  => MASA_GEO_PLUGIN_DIR . 'modules/affiliates/frontend/class-masa-affiliate-dashboard.php',
    ];
    if ( isset( $map[ $class ] ) && file_exists( $map[ $class ] ) ) {
        require_once $map[ $class ];
    }
} );

register_activation_hook( __FILE__, [ 'Masa_Geo_Plugin', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'Masa_Geo_Plugin', 'deactivate' ] );

final class Masa_Geo_Plugin {
    private static ?Masa_Geo_Plugin $instance = null;

    public static function instance(): Masa_Geo_Plugin {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'plugins_loaded', [ $this, 'init' ] );
        // أعلن توافق البلاجين مع HPOS (High-Performance Order Storage)
        add_action( 'before_woocommerce_init', [ $this, 'declare_hpos_compatibility' ] );
    }

    public function declare_hpos_compatibility(): void {
        if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                MASA_GEO_PLUGIN_FILE,
                true
            );
            // توافق مع Cart/Checkout Blocks كمان
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'cart_checkout_blocks',
                MASA_GEO_PLUGIN_FILE,
                true
            );
        }
    }

    public function init(): void {
        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', [ $this, 'notice_woo_missing' ] );
            return;
        }
        $this->load_textdomain();
        $this->load_classes();
    }

    private function load_textdomain(): void {
        load_plugin_textdomain( 'masa-geo-pricing', false,
            dirname( plugin_basename( MASA_GEO_PLUGIN_FILE ) ) . '/languages/' );
    }

    private function is_woo_management_api(): bool {
        if ( empty( $_SERVER['REQUEST_URI'] ) ) {
            return false;
        }
        $uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
        if ( strpos( $uri, '/wc/v1' ) !== false || strpos( $uri, '/wc/v2' ) !== false || strpos( $uri, '/wc/v3' ) !== false ) {
            return true;
        }
        return false;
    }

    private function load_classes(): void {
        // دايماً محتاجين دول
        Masa_Geo_Helper::instance();
        Masa_Geo_Api::instance();
        Masa_Geo_Alerts::instance();
        Masa_Geo_Order::instance();
        Masa_Geo_Email::instance(); // لرسائل البريد الإلكتروني والفواتير دائماً

        // البلوكات لازم تتسجّل في الـ editor (admin) والـ frontend الاتنين
        Masa_Geo_Blocks::instance();

        // تحميل العمولات والشركاء الأساسية (v3.9.0)
        Masa_Geo_Affiliate_Core::instance();
        Masa_Geo_Affiliate_Tracker::instance();
        Masa_Geo_Affiliate_Commission::instance();

        if ( is_admin() ) {
            Masa_Geo_Admin::instance();
            Masa_Geo_Product::instance();
            Masa_Geo_Coupon::instance();
            Masa_Geo_Dashboard::instance();
            Masa_Geo_Tools::instance();
            Masa_Geo_Visibility_Admin::instance();
            Masa_Geo_Status::instance();
            Masa_Geo_Compat::instance();
            Masa_Geo_Zone_Stock::instance();
            Masa_Geo_Affiliate_Admin::instance(); // لوحة الإدارة للعمولات

            // في REST requests (زي ServerSideRender للبلوكات) نحمّل الـ Core كمان
            // عشان معاينة الأسعار في الـ editor تطلع محوّلة صح
            if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
                Masa_Geo_Core::instance();
            }
        } elseif ( $this->is_woo_management_api() ) {
            // استثناء لتطبيق ووكومرس للموبايل وأنظمة ERP 
            // لعرض السعر الأساسي والعملة الأساسية للمديرين بدون تحويلات
            Masa_Geo_Zone_Stock::instance();
            Masa_Geo_Compat::instance();
        } else {
            Masa_Geo_Core::instance();
            Masa_Geo_Visibility::instance();
            Masa_Geo_Compat::instance();
            Masa_Geo_Zone_Stock::instance();
            Masa_Geo_Switcher::instance();
            Masa_Geo_Shipping::instance();
            Masa_Geo_Whatsapp::instance();
            Masa_Geo_Minimum_Order::instance();
            Masa_Geo_Free_Shipping_Bar::instance();
            Masa_Geo_Affiliate_Dashboard::instance(); // لوحة التحكم الأمامية للمسوقين
        }
    }

    public static function activate(): void {
        // v3.5.0: جدول الـ Activity Log
        Masa_Geo_Activity_Log::install_table();
        // v3.7.0: جدول المخزون الجغرافي
        Masa_Geo_Zone_Stock::install_table();
        // v3.9.0: جدول العمولات والشركاء
        Masa_Geo_Affiliate_Core::install_table();

        if ( ! get_option( MASA_GEO_OPTION_KEY ) ) {
            update_option( MASA_GEO_OPTION_KEY, [
                'api_provider'      => 'open_er_api',
                'api_key'           => '',
                'alert_enabled'     => 0,
                'alert_email'       => get_option( 'admin_email' ),
                'alert_threshold'   => 5.0,
                'switcher_enabled'  => 0,
                'switcher_floating' => 0,
                'shipping_enabled'  => 0,
                'visibility_message'=> 'عذراً، هذا المنتج غير متاح في بلدك حالياً.',
                'geo_proxy_mode'    => 'auto',
                'trusted_proxies'   => [],
                'default_country'   => 'EG',
                'keep_data_on_uninstall' => 1,
                'zone_stock_badge'           => 0,
                'zone_stock_badge_threshold' => 5,
                'zone_stock_email_alerts'    => 1,
                'whatsapp_number'            => '',
                'whatsapp_btn_label'         => 'طلب عبر الواتساب',
                'whatsapp_message_template'  => 'السلام عليكم، أود الاستفسار عن منتج: {product_name} - الرابط: {product_url}',
                'zones' => [
                    [ 'id' => 'eg',  'label' => 'مصر',            'countries' => ['EG'],                                                                                    'multiplier' => 1.0, 'currency' => 'EGP', 'minimum_order' => 0.0, 'rate_mode' => 'api', 'manual_rate' => 0.0, 'cod_enabled' => 1 ],
                    [ 'id' => 'sa',  'label' => 'السعودية',       'countries' => ['SA'],                                                                                    'multiplier' => 1.0, 'currency' => 'SAR', 'minimum_order' => 0.0, 'rate_mode' => 'api', 'manual_rate' => 0.0, 'cod_enabled' => 1 ],
                    [ 'id' => 'ae',  'label' => 'الإمارات',       'countries' => ['AE'],                                                                                    'multiplier' => 1.0, 'currency' => 'AED', 'minimum_order' => 0.0, 'rate_mode' => 'api', 'manual_rate' => 0.0, 'cod_enabled' => 1 ],
                    [ 'id' => 'bh',  'label' => 'البحرين',        'countries' => ['BH'],                                                                                    'multiplier' => 1.0, 'currency' => 'BHD', 'minimum_order' => 0.0, 'rate_mode' => 'api', 'manual_rate' => 0.0, 'cod_enabled' => 0 ],
                    [ 'id' => 'kw',  'label' => 'الكويت',         'countries' => ['KW'],                                                                                    'multiplier' => 1.0, 'currency' => 'KWD', 'minimum_order' => 0.0, 'rate_mode' => 'api', 'manual_rate' => 0.0, 'cod_enabled' => 0 ],
                    [ 'id' => 'om',  'label' => 'عمان',           'countries' => ['OM'],                                                                                    'multiplier' => 1.0, 'currency' => 'OMR', 'minimum_order' => 0.0, 'rate_mode' => 'api', 'manual_rate' => 0.0, 'cod_enabled' => 0 ],
                    [ 'id' => 'jo',  'label' => 'الأردن',         'countries' => ['JO'],                                                                                    'multiplier' => 1.0, 'currency' => 'JOD', 'minimum_order' => 0.0, 'rate_mode' => 'api', 'manual_rate' => 0.0, 'cod_enabled' => 0 ],
                    [ 'id' => 'eu',  'label' => 'أوروبا',         'countries' => ['DE','FR','GB','IT','ES','NL','BE','AT','CH','SE','NO','DK','FI','IE','PL','PT','GR'],     'multiplier' => 1.0, 'currency' => 'EUR', 'minimum_order' => 0.0, 'rate_mode' => 'api', 'manual_rate' => 0.0, 'cod_enabled' => 0 ],
                    [ 'id' => 'us',  'label' => 'أمريكا',         'countries' => ['US'],                                                                                    'multiplier' => 1.0, 'currency' => 'USD', 'minimum_order' => 0.0, 'rate_mode' => 'api', 'manual_rate' => 0.0, 'cod_enabled' => 0 ],
                    [ 'id' => 'row', 'label' => 'باقي دول العالم','countries' => ['__FALLBACK__'],                                                                          'multiplier' => 1.0, 'currency' => 'USD', 'minimum_order' => 0.0, 'rate_mode' => 'api', 'manual_rate' => 0.0, 'cod_enabled' => 0 ],
                ],
            ], 'no' );
        }
        if ( ! wp_next_scheduled( 'masa_geo_fetch_rates_event' ) ) {
            wp_schedule_event( time(), 'daily', 'masa_geo_fetch_rates_event' );
        }
    }

    public static function deactivate(): void {
        $ts = wp_next_scheduled( 'masa_geo_fetch_rates_event' );
        if ( $ts ) wp_unschedule_event( $ts, 'masa_geo_fetch_rates_event' );
    }

    public function notice_woo_missing(): void {
        echo '<div class="notice notice-error"><p>';
        esc_html_e( 'بلاجين Masa Geo-Pricing يتطلب تفعيل WooCommerce أولاً.', 'masa-geo-pricing' );
        echo '</p></div>';
    }
}

Masa_Geo_Plugin::instance();

// ─── Upgrade routine: migrations عند تغيير الإصدار ───
add_action( 'admin_init', static function () {
    $installed = get_option( 'masa_geo_db_version', '' );

    if ( $installed === MASA_GEO_VERSION ) {
        return;
    }

    // v3.7.0: جدول المخزون الجغرافي + migrations متسلسلة
    Masa_Geo_Zone_Stock::install_table();

    // v3.9.0: جدول العمولات والشركاء
    Masa_Geo_Affiliate_Core::install_table();

    // v3.3–3.5 → نظام الـ allowed zones القديم
    if ( version_compare( $installed ?: '3.5.0', '3.6.0', '<' ) ) {
        Masa_Geo_Zone_Stock::migrate_from_visibility();
    }

    // v3.6 → الـ serialized meta للجدول
    if ( version_compare( $installed ?: '3.5.0', '3.7.0', '<' ) ) {
        Masa_Geo_Zone_Stock::migrate_from_meta();
    }

    // جدول اللوج — لو البلجن اتحدث بالاستبدال من غير تفعيل
    Masa_Geo_Activity_Log::install_table();

    update_option( 'masa_geo_db_version', MASA_GEO_VERSION, 'no' );
}, 5 );
