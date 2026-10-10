<?php
defined( 'ABSPATH' ) || exit;

/**
 * Masa_Geo_Blocks
 * بلوكات Gutenberg لعرض المنتجات مع مراعاة التسعير الجغرافي
 *
 * كل البلوكات Dynamic (render في PHP) — يعني السعر بيتحسب لحظياً
 * حسب بلد الزائر، ومفيش أسعار قديمة بتتخزن في الـ HTML.
 *
 * البلوكات:
 *   1. masa-geo/products-grid    — شبكة منتجات
 *   2. masa-geo/single-product   — منتج واحد
 *   3. masa-geo/featured-products — منتجات مميزة / الأكثر مبيعاً
 */
final class Masa_Geo_Blocks {

    private static ?Masa_Geo_Blocks $instance = null;

    public static function instance(): Masa_Geo_Blocks {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'init', [ $this, 'register_blocks' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_styles' ] );
        // تأكد إن category "woocommerce" موجودة (WooCommerce بيسجّلها، ده fallback)
        add_filter( 'block_categories_all', [ $this, 'ensure_block_category' ], 5 );
    }

    public function ensure_block_category( array $categories ): array {
        foreach ( $categories as $cat ) {
            if ( ( $cat['slug'] ?? '' ) === 'woocommerce' ) {
                return $categories; // موجودة بالفعل
            }
        }
        // مش موجودة → أضفها
        $categories[] = [
            'slug'  => 'woocommerce',
            'title' => 'WooCommerce',
        ];
        return $categories;
    }

    // ═════════════════════════════════════════════════════════
    // تسجيل البلوكات
    // ═════════════════════════════════════════════════════════
    public function register_blocks(): void {
        // الـ editor script المشترك للـ 3 بلوكات
        wp_register_script(
            'masa-geo-blocks-editor',
            MASA_GEO_PLUGIN_URL . 'includes/blocks/blocks-editor.js',
            [ 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-server-side-render' ],
            MASA_GEO_VERSION,
            true
        );

        // ترجمات للـ editor
        if ( function_exists( 'wp_set_script_translations' ) ) {
            wp_set_script_translations( 'masa-geo-blocks-editor', 'masa-geo-pricing' );
        }

        // CSS مشترك (editor + frontend)
        wp_register_style(
            'masa-geo-blocks',
            MASA_GEO_PLUGIN_URL . 'includes/blocks/blocks.css',
            [],
            MASA_GEO_VERSION
        );

        $common_supports = [
            'align'   => [ 'wide', 'full' ],
            'spacing' => [ 'margin' => true, 'padding' => true ],
        ];

        // 1) Products Grid
        register_block_type( 'masa-geo/products-grid', [
            'editor_script'   => 'masa-geo-blocks-editor',
            'editor_style'    => 'masa-geo-blocks',
            'style'           => 'masa-geo-blocks',
            'render_callback' => [ $this, 'render_products_grid' ],
            'supports'        => $common_supports,
            'attributes'      => [
                'columns'      => [ 'type' => 'number',  'default' => 3 ],
                'limit'        => [ 'type' => 'number',  'default' => 6 ],
                'orderby'      => [ 'type' => 'string',  'default' => 'date' ],
                'order'        => [ 'type' => 'string',  'default' => 'DESC' ],
                'category'     => [ 'type' => 'string',  'default' => '' ],
                'showPrice'    => [ 'type' => 'boolean', 'default' => true ],
                'showRating'   => [ 'type' => 'boolean', 'default' => true ],
                'showAddToCart'=> [ 'type' => 'boolean', 'default' => true ],
                'showZoneBadge'=> [ 'type' => 'boolean', 'default' => false ],
            ],
        ] );

        // 2) Single Product
        register_block_type( 'masa-geo/single-product', [
            'editor_script'   => 'masa-geo-blocks-editor',
            'editor_style'    => 'masa-geo-blocks',
            'style'           => 'masa-geo-blocks',
            'render_callback' => [ $this, 'render_single_product' ],
            'supports'        => $common_supports,
            'attributes'      => [
                'productId'    => [ 'type' => 'number',  'default' => 0 ],
                'showPrice'    => [ 'type' => 'boolean', 'default' => true ],
                'showAddToCart'=> [ 'type' => 'boolean', 'default' => true ],
                'layout'       => [ 'type' => 'string',  'default' => 'horizontal' ], // horizontal | vertical
            ],
        ] );

        // 3) Featured / Best Sellers
        register_block_type( 'masa-geo/featured-products', [
            'editor_script'   => 'masa-geo-blocks-editor',
            'editor_style'    => 'masa-geo-blocks',
            'style'           => 'masa-geo-blocks',
            'render_callback' => [ $this, 'render_featured_products' ],
            'supports'        => $common_supports,
            'attributes'      => [
                'columns'      => [ 'type' => 'number',  'default' => 4 ],
                'limit'        => [ 'type' => 'number',  'default' => 4 ],
                'source'       => [ 'type' => 'string',  'default' => 'featured' ], // featured | best_selling | top_rated | on_sale
                'showPrice'    => [ 'type' => 'boolean', 'default' => true ],
                'showAddToCart'=> [ 'type' => 'boolean', 'default' => true ],
                'heading'      => [ 'type' => 'string',  'default' => '' ],
            ],
        ] );
    }

    public function enqueue_frontend_styles(): void {
        // بنعمل enqueue على الـ frontend بس لو فيه بلوك في الصفحة
        if ( has_block( 'masa-geo/products-grid' )
            || has_block( 'masa-geo/single-product' )
            || has_block( 'masa-geo/featured-products' ) ) {
            wp_enqueue_style( 'masa-geo-blocks' );
        }
    }

    // ═════════════════════════════════════════════════════════
    // Render: Products Grid
    // ═════════════════════════════════════════════════════════
    public function render_products_grid( array $attrs ): string {
        $columns = max( 1, min( 6, (int) ( $attrs['columns'] ?? 3 ) ) );
        $limit   = max( 1, min( 24, (int) ( $attrs['limit'] ?? 6 ) ) );

        $query_args = [
            'status'  => 'publish',
            'limit'   => $limit,
            'orderby' => in_array( $attrs['orderby'] ?? 'date', [ 'date', 'title', 'price', 'popularity', 'rand' ], true )
                ? ( $attrs['orderby'] ?? 'date' )
                : 'date',
            'order'   => ( ( $attrs['order'] ?? 'DESC' ) === 'ASC' ) ? 'ASC' : 'DESC',
        ];

        if ( ! empty( $attrs['category'] ) ) {
            $query_args['category'] = array_map( 'sanitize_title', explode( ',', $attrs['category'] ) );
        }

        $products = wc_get_products( $query_args );

        if ( empty( $products ) ) {
            return $this->empty_notice( __( 'لا توجد منتجات لعرضها.', 'masa-geo-pricing' ) );
        }

        $opts = [
            'showPrice'     => ! empty( $attrs['showPrice'] ),
            'showRating'    => ! empty( $attrs['showRating'] ),
            'showAddToCart' => ! empty( $attrs['showAddToCart'] ),
            'showZoneBadge' => ! empty( $attrs['showZoneBadge'] ),
        ];

        $cards = '';
        foreach ( $products as $product ) {
            $cards .= $this->render_product_card( $product, $opts );
        }

        $zone_note = '';
        if ( $opts['showZoneBadge'] ) {
            $zone_note = $this->zone_badge();
        }

        return sprintf(
            '<div class="masa-geo-grid masa-geo-grid--cols-%d">%s</div>%s',
            $columns,
            $cards,
            $zone_note
        );
    }

    // ═════════════════════════════════════════════════════════
    // Render: Single Product
    // ═════════════════════════════════════════════════════════
    public function render_single_product( array $attrs ): string {
        $product_id = (int) ( $attrs['productId'] ?? 0 );
        if ( ! $product_id ) {
            return $this->empty_notice( __( 'اختر منتجاً من إعدادات البلوك.', 'masa-geo-pricing' ) );
        }

        $product = wc_get_product( $product_id );
        if ( ! $product || $product->get_status() !== 'publish' ) {
            return $this->empty_notice( __( 'المنتج غير متاح.', 'masa-geo-pricing' ) );
        }

        $layout = ( $attrs['layout'] ?? 'horizontal' ) === 'vertical' ? 'vertical' : 'horizontal';
        $opts   = [
            'showPrice'     => ! empty( $attrs['showPrice'] ),
            'showRating'    => true,
            'showAddToCart' => ! empty( $attrs['showAddToCart'] ),
            'showZoneBadge' => false,
        ];

        return sprintf(
            '<div class="masa-geo-single masa-geo-single--%s">%s</div>',
            esc_attr( $layout ),
            $this->render_product_card( $product, $opts, true )
        );
    }

    // ═════════════════════════════════════════════════════════
    // Render: Featured / Best Sellers
    // ═════════════════════════════════════════════════════════
    public function render_featured_products( array $attrs ): string {
        $columns = max( 1, min( 6, (int) ( $attrs['columns'] ?? 4 ) ) );
        $limit   = max( 1, min( 24, (int) ( $attrs['limit'] ?? 4 ) ) );
        $source  = $attrs['source'] ?? 'featured';

        $query_args = [
            'status' => 'publish',
            'limit'  => $limit,
        ];

        switch ( $source ) {
            case 'best_selling':
                $query_args['orderby']  = 'popularity';
                break;
            case 'top_rated':
                $query_args['orderby']  = 'rating';
                break;
            case 'on_sale':
                $query_args['include']  = wc_get_product_ids_on_sale();
                if ( empty( $query_args['include'] ) ) {
                    return $this->empty_notice( __( 'لا توجد منتجات في التخفيضات حالياً.', 'masa-geo-pricing' ) );
                }
                break;
            case 'featured':
            default:
                $query_args['featured'] = true;
                break;
        }

        $products = wc_get_products( $query_args );

        if ( empty( $products ) ) {
            return $this->empty_notice( __( 'لا توجد منتجات لعرضها.', 'masa-geo-pricing' ) );
        }

        $opts = [
            'showPrice'     => ! empty( $attrs['showPrice'] ),
            'showRating'    => true,
            'showAddToCart' => ! empty( $attrs['showAddToCart'] ),
            'showZoneBadge' => false,
        ];

        $heading = '';
        if ( ! empty( $attrs['heading'] ) ) {
            $heading = sprintf(
                '<h3 class="masa-geo-section-heading">%s</h3>',
                esc_html( $attrs['heading'] )
            );
        }

        $cards = '';
        foreach ( $products as $product ) {
            $cards .= $this->render_product_card( $product, $opts );
        }

        return sprintf(
            '%s<div class="masa-geo-grid masa-geo-grid--cols-%d">%s</div>',
            $heading,
            $columns,
            $cards
        );
    }

    // ═════════════════════════════════════════════════════════
    // كارت المنتج المشترك
    // ═════════════════════════════════════════════════════════
    private function render_product_card( \WC_Product $product, array $opts, bool $detailed = false ): string {
        $permalink = get_permalink( $product->get_id() );
        $title     = $product->get_name();

        // الصورة
        $image = $product->get_image( $detailed ? 'woocommerce_single' : 'woocommerce_thumbnail' );

        // الشارة (Sale / Out of stock)
        $badge = '';
        if ( ! $product->is_in_stock() ) {
            $badge = '<span class="masa-geo-badge masa-geo-badge--oos">' . esc_html__( 'نفذ المخزون', 'masa-geo-pricing' ) . '</span>';
        } elseif ( $product->is_on_sale() ) {
            $badge = '<span class="masa-geo-badge masa-geo-badge--sale">' . esc_html__( 'تخفيض', 'masa-geo-pricing' ) . '</span>';
        }

        // السعر — get_price_html بيمر على الـ filters بتاعتنا تلقائياً فبيطلع محوّل للـ Zone
        $price_html = '';
        if ( ! empty( $opts['showPrice'] ) ) {
            $price = $product->get_price_html();
            if ( $price ) {
                $price_html = '<div class="masa-geo-card__price">' . $price . '</div>';
            }
        }

        // التقييم
        $rating_html = '';
        if ( ! empty( $opts['showRating'] ) && wc_review_ratings_enabled() ) {
            $avg = $product->get_average_rating();
            if ( $avg > 0 ) {
                $rating_html = '<div class="masa-geo-card__rating">' . wc_get_rating_html( $avg ) . '</div>';
            }
        }

        // الوصف القصير (للـ detailed بس)
        $excerpt_html = '';
        if ( $detailed ) {
            $short = $product->get_short_description();
            if ( $short ) {
                $excerpt_html = '<div class="masa-geo-card__excerpt">' . wp_kses_post( wpautop( $short ) ) . '</div>';
            }
        }

        // زر الإضافة للسلة
        $cart_html = '';
        if ( ! empty( $opts['showAddToCart'] ) ) {
            $cart_html = '<div class="masa-geo-card__cart">' . $this->add_to_cart_button( $product ) . '</div>';
        }

        return sprintf(
            '<article class="masa-geo-card%s">
                <div class="masa-geo-card__media">
                    %s
                    <a href="%s" class="masa-geo-card__img-link" aria-label="%s">%s</a>
                </div>
                <div class="masa-geo-card__body">
                    <h4 class="masa-geo-card__title"><a href="%s">%s</a></h4>
                    %s%s%s%s
                </div>
            </article>',
            $detailed ? ' masa-geo-card--detailed' : '',
            $badge,
            esc_url( $permalink ),
            esc_attr( $title ),
            $image,
            esc_url( $permalink ),
            esc_html( $title ),
            $rating_html,
            $excerpt_html,
            $price_html,
            $cart_html
        );
    }

    // زر إضافة للسلة (بيحترم نوع المنتج)
    private function add_to_cart_button( \WC_Product $product ): string {
        if ( ! $product->is_purchasable() || ! $product->is_in_stock() ) {
            return sprintf(
                '<a href="%s" class="button masa-geo-btn">%s</a>',
                esc_url( get_permalink( $product->get_id() ) ),
                esc_html__( 'عرض التفاصيل', 'masa-geo-pricing' )
            );
        }

        // للمنتجات المتغيرة → رابط لصفحة المنتج (لازم يختار variation)
        if ( $product->is_type( 'variable' ) ) {
            return sprintf(
                '<a href="%s" class="button masa-geo-btn">%s</a>',
                esc_url( get_permalink( $product->get_id() ) ),
                esc_html__( 'اختر الخيارات', 'masa-geo-pricing' )
            );
        }

        return sprintf(
            '<a href="%s" data-quantity="1" class="button masa-geo-btn add_to_cart_button ajax_add_to_cart" data-product_id="%d" rel="nofollow">%s</a>',
            esc_url( $product->add_to_cart_url() ),
            (int) $product->get_id(),
            esc_html( $product->add_to_cart_text() )
        );
    }

    // شارة المنطقة (اختيارية)
    private function zone_badge(): string {
        $country = Masa_Geo_Helper::get_visitor_country();
        $zone    = Masa_Geo_Helper::get_zone_for_country( $country );
        if ( ! $zone ) return '';

        return sprintf(
            '<div class="masa-geo-zone-note">%s <strong>%s</strong> (%s)</div>',
            esc_html__( 'الأسعار معروضة لمنطقة:', 'masa-geo-pricing' ),
            esc_html( $zone['label'] ),
            esc_html( $zone['currency'] )
        );
    }

    private function empty_notice( string $msg ): string {
        return '<div class="masa-geo-empty">' . esc_html( $msg ) . '</div>';
    }
}
