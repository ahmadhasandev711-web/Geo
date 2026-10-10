<?php
defined( 'ABSPATH' ) || exit;

/**
 * Masa_Geo_Whatsapp
 * يعرض زر التواصل عبر الواتساب بدلاً من "إضافة للسلة" للمنتجات التي بدون سعر.
 */
final class Masa_Geo_Whatsapp {

    private static ?Masa_Geo_Whatsapp $instance = null;

    public static function instance(): Masa_Geo_Whatsapp {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // تحميل الستايل الخاص بالـ frontend
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_styles' ] );

        // صفحة المنتج التفصيلية — زر الواتساب يظهر فقط للمنتجات بدون سعر
        add_action( 'woocommerce_single_product_summary', [ $this, 'maybe_render_whatsapp_btn' ], 30 );

        // ملاحظة (v4.0.0): لا نتدخل في الكتالوج/الأرشيف — ووكومرس يعرض زر
        // "اقرأ المزيد" (Read more) افتراضياً للمنتجات بلا سعر، وهو السلوك المطلوب.
    }

    /**
     * تحقق من أن ميزة الواتساب مفعلة (تم إدخال رقم هاتف)
     */
    private function is_whatsapp_active(): bool {
        $settings = get_option( MASA_GEO_OPTION_KEY, [] );
        return ! empty( $settings['whatsapp_number'] );
    }

    /**
     * تحميل ملف الـ CSS
     */
    public function enqueue_styles(): void {
        if ( $this->is_whatsapp_active() ) {
            wp_enqueue_style(
                'masa-geo-frontend',
                MASA_GEO_PLUGIN_URL . 'assets/frontend.css',
                [],
                MASA_GEO_VERSION
            );
        }
    }

    /**
     * عرض زر الواتساب في صفحة المنتج الفردي
     */
    public function maybe_render_whatsapp_btn(): void {
        if ( ! $this->is_whatsapp_active() ) {
            return;
        }

        global $product;
        if ( ! $product || ! $product instanceof \WC_Product ) {
            return;
        }

        // الزر يظهر فقط عندما لا يكون للمنتج سعر (empty price).
        // `get_price()` بترجّع string فاضي '' للمنتجات بلا سعر.
        $raw_price = $product->get_price();
        if ( '' !== (string) $raw_price && (float) $raw_price > 0 ) {
            return;
        }

        $settings = get_option( MASA_GEO_OPTION_KEY, [] );
        $phone    = preg_replace( '/[^0-9]/', '', $settings['whatsapp_number'] ?? '' );
        if ( ! $phone ) {
            return;
        }

        $btn_label = ! empty( $settings['whatsapp_btn_label'] )
            ? $settings['whatsapp_btn_label']
            : __( 'تواصل عبر الواتساب', 'masa-geo-pricing' );

        $template = ! empty( $settings['whatsapp_message_template'] )
            ? $settings['whatsapp_message_template']
            : 'السلام عليكم، أود الاستفسار عن منتج: {product_name} - الرابط: {product_url}';

        $product_name = $product->get_name();
        $product_url  = get_permalink( $product->get_id() );

        $message = str_replace(
            [ '{product_name}', '{product_url}' ],
            [ $product_name, $product_url ],
            $template
        );

        $whatsapp_url = 'https://wa.me/' . $phone . '?text=' . rawurlencode( $message );
        ?>
        <div class="masa-geo-whatsapp-container">
            <a href="<?php echo esc_url( $whatsapp_url ); ?>" target="_blank" rel="noopener noreferrer" class="masa-geo-whatsapp-btn">
                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946C.06 5.348 5.397.01 12.008.01c3.202.001 6.212 1.246 8.477 3.514 2.266 2.268 3.507 5.28 3.505 8.484-.004 6.657-5.34 11.997-11.953 11.997-2.005-.001-3.973-.502-5.724-1.455L0 24zm6.59-4.846c1.66.986 3.292 1.493 4.97 1.494 5.405 0 9.805-4.329 9.808-9.65.002-2.578-1.002-5.001-2.83-6.83C16.715 2.337 14.288 1.33 11.72 1.33 6.312 1.33 1.91 5.658 1.908 10.98c0 1.73.46 3.41 1.337 4.89l-1.018 3.715 3.822-.989zm11.566-7.63c-.301-.15-1.785-.88-2.063-.98-.277-.1-.478-.15-.68.15-.201.3-.778.98-.952 1.18-.174.2-.347.225-.648.075-.3-.15-1.266-.467-2.41-1.487-.89-.794-1.49-1.77-1.665-2.07-.175-.3-.019-.462.13-.611.135-.133.301-.35.451-.525.15-.175.2-.3.3-.5.1-.2.05-.375-.025-.525-.075-.15-.68-1.637-.93-2.25-.243-.587-.49-.508-.68-.518-.173-.009-.371-.01-.57-.01-.2 0-.525.075-.8.375-.275.3-1.05 1.025-1.05 2.5 0 1.475 1.075 2.9 1.225 3.1.15.2 2.11 3.22 5.11 4.52.714.31 1.27.496 1.703.635.717.227 1.37.195 1.885.118.574-.085 1.785-.73 2.038-1.43.252-.7.252-1.3.177-1.43-.075-.13-.275-.205-.575-.355z"/>
                </svg>
                <span><?php echo esc_html( $btn_label ); ?></span>
            </a>
        </div>
        <?php
    }

    /**
     * ملاحظة (v4.0.0): استبدال زر الكتالوج/الأرشيف أُزيل عمداً.
     * السلوك المطلوب في الأرشيف هو إبقاء زر "اقرأ المزيد" (Read more)
     * الافتراضي من ووكومرس للمنتجات بلا سعر — بدون أي تدخل من البلاجين.
     */
}
