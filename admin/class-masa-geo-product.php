<?php
defined( 'ABSPATH' ) || exit;

/**
 * Masa_Geo_Product
 * Meta Boxes على صفحة المنتج — تحديد سعر EGP ثابت لكل Zone
 */
final class Masa_Geo_Product {

    private static ?Masa_Geo_Product $instance = null;

    const META_KEY         = '_masa_geo_zone_prices';
    const META_EXCLUDE_KEY = '_masa_geo_exclude';   // ← منتجات "حسب الطلب"

    public static function instance(): Masa_Geo_Product {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_filter( 'woocommerce_product_data_tabs',   [ $this, 'add_product_tab' ] );
        add_action( 'woocommerce_product_data_panels', [ $this, 'render_tab_panel' ] );
        add_action( 'woocommerce_process_product_meta',[ $this, 'save_meta' ] );

        // Variations
        add_action( 'woocommerce_variation_options_pricing', [ $this, 'render_variation_fields' ], 10, 3 );
        add_action( 'woocommerce_save_product_variation',    [ $this, 'save_variation_meta' ], 10, 2 );
    }

    public function add_product_tab( array $tabs ): array {
        $tabs['masa_geo_pricing'] = [
            'label'    => __( '🌍 التسعير الجغرافي', 'masa-geo-pricing' ),
            'target'   => 'masa_geo_pricing_panel',
            'class'    => [ 'show_if_simple', 'show_if_variable' ],
            'priority' => 61,
        ];
        return $tabs;
    }

    public function render_tab_panel(): void {
        global $post;
        $zones        = $this->get_zones();
        $saved_prices = get_post_meta( $post->ID, self::META_KEY, true );
        if ( ! is_array( $saved_prices ) ) {
            $saved_prices = [];
        }
        $timed_rules = Masa_Geo_Timed_Pricing::get_rules( $post->ID );
        $is_excluded = (bool) get_post_meta( $post->ID, self::META_EXCLUDE_KEY, true );

        // المخزون الجغرافي (v3.6.0 — بيوحّد الإتاحة والكمية)
        $stock_map     = Masa_Geo_Zone_Stock::get_stock_map( $post->ID );
        $has_custom    = ! empty( $stock_map );
        ?>
        <div id="masa_geo_pricing_panel" class="panel woocommerce_options_panel" dir="rtl">

            <!-- ─── المخزون والعرض الجغرافي ─── -->
            <div class="masa-product-panel-header" style="background:<?php echo $has_custom ? '#eff6ff' : '#fff'; ?>;">
                <h3 style="margin:0 0 .4rem;">📦 <?php esc_html_e( 'المخزون والعرض الجغرافي — الكمية المتاحة لكل منطقة', 'masa-geo-pricing' ); ?></h3>
                <p class="description" style="margin:0 0 .8rem;">
                    <?php esc_html_e( 'فارغ = غير محدود (يتبع مخزون ووكومرس العام) • رقم = سقف بيع للمنطقة يُخصم مع كل طلب • صفر = غير متاح في المنطقة (يختفي من المتجر وتظهر صفحته برسالة "غير متاح في بلدك").', 'masa-geo-pricing' ); ?>
                </p>

                <?php if ( empty( $zones ) ) : ?>
                    <em><?php esc_html_e( 'لا توجد مناطق معرّفة — أضف المناطق من إعدادات البلاجين أولاً.', 'masa-geo-pricing' ); ?></em>
                <?php else : ?>
                    <table class="widefat" style="max-width:680px;margin-bottom:.6rem;">
                        <thead>
                            <tr>
                                <th style="text-align:right;"><?php esc_html_e( 'المنطقة', 'masa-geo-pricing' ); ?></th>
                                <th style="width:200px;text-align:right;"><?php esc_html_e( 'الكمية المتاحة', 'masa-geo-pricing' ); ?></th>
                                <th style="text-align:right;"><?php esc_html_e( 'الحالة', 'masa-geo-pricing' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $zones as $zone ) :
                            if ( empty( $zone['id'] ) ) continue;
                            $zid   = $zone['id'];
                            $val   = array_key_exists( $zid, $stock_map ) ? (string) $stock_map[ $zid ] : '';
                        ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html( $zone['label'] ); ?></strong>
                                    <span class="masa-zone-currency-tag"><?php echo esc_html( $zone['currency'] ); ?></span>
                                </td>
                                <td>
                                    <label for="masa_zone_stock_<?php echo esc_attr( $zid ); ?>" class="screen-reader-text">
                                        <?php
                                        /* translators: %s: اسم المنطقة */
                                        printf( esc_html__( 'الكمية المتاحة — %s', 'masa-geo-pricing' ), esc_html( $zone['label'] ) );
                                        ?>
                                    </label>
                                    <input type="number" min="0" step="1"
                                        id="masa_zone_stock_<?php echo esc_attr( $zid ); ?>"
                                        class="masa-zone-stock-field"
                                        name="masa_geo_zone_stock[<?php echo esc_attr( $zid ); ?>]"
                                        value="<?php echo esc_attr( $val ); ?>"
                                        placeholder="<?php esc_attr_e( 'غير محدود', 'masa-geo-pricing' ); ?>"
                                        style="width:130px;" />
                                </td>
                                <td class="masa-zone-stock-status" data-zone="<?php echo esc_attr( $zid ); ?>">
                                    <?php
                                    if ( '' === $val ) {
                                        echo '<span style="color:#6b7280;">♾️ ' . esc_html__( 'يتبع المخزون العام', 'masa-geo-pricing' ) . '</span>';
                                    } elseif ( '0' === $val ) {
                                        echo '<span style="color:#dc2626;font-weight:600;">🚫 ' . esc_html__( 'غير متاح في المنطقة', 'masa-geo-pricing' ) . '</span>';
                                    } else {
                                        echo '<span style="color:#16a34a;font-weight:600;">✅ ' . esc_html( sprintf( __( 'متاح — %s قطعة', 'masa-geo-pricing' ), $val ) ) . '</span>';
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="description" style="margin:0;">
                        💡 <?php esc_html_e( 'الخصم تلقائي مع كل طلب مكتمل/قيد التنفيذ من المنطقة، والإرجاع تلقائي عند الإلغاء أو الاسترجاع. عند وصول الكمية لصفر يختفي المنتج من المنطقة فوراً.', 'masa-geo-pricing' ); ?>
                    </p>
                <?php endif; ?>
            </div>

            <!-- ─── Exclude Toggle (حسب الطلب) ─── -->
            <div class="masa-product-panel-header" style="background:<?php echo $is_excluded ? '#fef9ec' : '#fff'; ?>;">
                <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
                    <input type="checkbox"
                        name="masa_geo_exclude"
                        id="masa_geo_exclude"
                        value="1"
                        <?php checked( $is_excluded ); ?>
                        style="width:18px;height:18px;" />
                    <span>
                        <strong>🚫 <?php esc_html_e( 'منتج حسب الطلب — استثنِه من التسعير الجغرافي', 'masa-geo-pricing' ); ?></strong><br>
                        <small class="description">
                            <?php esc_html_e( 'عند التفعيل: لا يُطبَّق أي تحويل عملة أو مضاعف على هذا المنتج. مناسب للمنتجات التي ليس لها سعر ثابت (WhatsApp inquiry, custom orders).', 'masa-geo-pricing' ); ?>
                        </small>
                    </span>
                </label>
            </div>


            <div id="masa-pricing-fields-wrap">
            <div class="masa-product-panel-header">
                <h3><?php esc_html_e( '🌍 أسعار EGP الثابتة حسب المنطقة الجغرافية', 'masa-geo-pricing' ); ?></h3>
                <p class="description">
                    <?php esc_html_e( 'لو تركت الحقل فارغاً، سيُحسب السعر تلقائياً من سعر المنتج الأساسي × مضاعف المنطقة. القيمة هنا بالجنيه المصري (EGP) وتُعتبر Override.', 'masa-geo-pricing' ); ?>
                </p>
            </div>

            <?php if ( empty( $zones ) ) : ?>
                <p style="padding:1rem 1.5rem;color:#666;">
                    <?php
                    printf(
                        wp_kses(
                            __( 'لا توجد مناطق تسعير. <a href="%s">اضغط هنا لإضافة المناطق أولاً.</a>', 'masa-geo-pricing' ),
                            [ 'a' => [ 'href' => [] ] ]
                        ),
                        esc_url( admin_url( 'admin.php?page=masa-geo-pricing' ) )
                    );
                    ?>
                </p>
            <?php else : ?>
                <div class="masa-zones-grid">
                    <?php foreach ( $zones as $zone ) :
                        $zone_id         = $zone['id'];
                        $is_fallback     = in_array( '__FALLBACK__', $zone['countries'] ?? [], true );
                        $saved_val       = isset( $saved_prices[ $zone_id ] ) ? (float) $saved_prices[ $zone_id ] : '';
                        $countries_disp  = $is_fallback
                            ? __( 'باقي العالم', 'masa-geo-pricing' )
                            : implode( '، ', (array) $zone['countries'] );
                    ?>
                        <div class="masa-zone-price-card">
                            <div class="masa-zone-price-header">
                                <strong><?php echo esc_html( $zone['label'] ); ?></strong>
                                <?php if ( $is_fallback ) : ?>
                                    <span class="masa-badge masa-badge--fallback"><?php esc_html_e( 'افتراضي', 'masa-geo-pricing' ); ?></span>
                                <?php endif; ?>
                                <span class="masa-zone-currency-tag"><?php echo esc_html( $zone['currency'] ); ?></span>
                            </div>

                            <div class="masa-zone-price-meta">
                                <span><?php printf( esc_html__( 'المضاعف: ×%s', 'masa-geo-pricing' ), esc_html( $zone['multiplier'] ) ); ?></span>
                                <span title="<?php echo esc_attr( $countries_disp ); ?>">🌐 <?php echo esc_html( $countries_disp ); ?></span>
                            </div>

                            <div class="masa-zone-price-input-wrap">
                                <label for="masa_zone_<?php echo esc_attr( $zone_id ); ?>">
                                    <?php esc_html_e( 'سعر EGP ثابت (Override)', 'masa-geo-pricing' ); ?>
                                </label>
                                <div class="masa-input-group" title="<?php esc_attr_e( 'اتركه فارغاً ليُحسب السعر تلقائياً (السعر الأساسي × المضاعف × سعر الصرف)', 'masa-geo-pricing' ); ?>">
                                    <span class="masa-input-prefix">ج.م</span>
                                    <input
                                        type="number"
                                        id="masa_zone_<?php echo esc_attr( $zone_id ); ?>"
                                        name="masa_geo_zone_prices[<?php echo esc_attr( $zone_id ); ?>]"
                                        value="<?php echo '' !== $saved_val ? esc_attr( $saved_val ) : ''; ?>"
                                        step="0.01"
                                        min="0"
                                        placeholder="<?php esc_attr_e( 'تلقائي', 'masa-geo-pricing' ); ?>"
                                        class="masa-zone-price-field"
                                    />
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php wp_nonce_field( 'masa_geo_save_product_meta', 'masa_geo_product_nonce' ); ?>

                <!-- ─── Timed Pricing ─── -->
                <div class="masa-product-panel-header" style="margin-top:1.5rem;">
                    <h3>⏰ <?php esc_html_e( 'أسعار مؤقتة (Time-Based Pricing)', 'masa-geo-pricing' ); ?></h3>
                    <p class="description">
                        <?php esc_html_e( 'Override مؤقت بتاريخ بداية ونهاية — مثال: سعر رمضان. له أولوية على الـ Override الثابت.', 'masa-geo-pricing' ); ?>
                    </p>
                </div>

                <div id="masa-timed-rules-wrap" style="padding:0 1.5rem 1rem;">
                    <?php foreach ( $zones as $zone ) :
                        $zone_id   = $zone['id'];
                        $zone_rules= $timed_rules[ $zone_id ] ?? [];
                    ?>
                    <div class="masa-timed-zone-section" style="margin-bottom:1rem;">
                        <strong style="display:block;margin-bottom:.4rem;">
                            <?php echo esc_html( $zone['label'] ); ?>
                            <span class="masa-zone-currency-tag"><?php echo esc_html( $zone['currency'] ); ?></span>
                        </strong>
                        <div class="masa-timed-rules" data-zone="<?php echo esc_attr( $zone_id ); ?>">
                            <?php foreach ( $zone_rules as $ri => $rule ) : ?>
                            <div class="masa-timed-rule" style="display:flex;gap:8px;align-items:center;margin-bottom:6px;flex-wrap:wrap;">
                                <input type="text" placeholder="وصف (مثال: رمضان)"
                                    name="masa_geo_timed[<?php echo esc_attr( $zone_id ); ?>][<?php echo esc_attr( $ri ); ?>][label]"
                                    value="<?php echo esc_attr( $rule['label'] ?? '' ); ?>"
                                    style="width:130px;" />
                                <input type="number" step="0.01" min="0" placeholder="سعر EGP"
                                    name="masa_geo_timed[<?php echo esc_attr( $zone_id ); ?>][<?php echo esc_attr( $ri ); ?>][price]"
                                    value="<?php echo esc_attr( $rule['price'] ?? '' ); ?>"
                                    style="width:110px;" />
                                <input type="date"
                                    name="masa_geo_timed[<?php echo esc_attr( $zone_id ); ?>][<?php echo esc_attr( $ri ); ?>][date_from]"
                                    value="<?php echo esc_attr( $rule['date_from'] ?? '' ); ?>"
                                    style="width:140px;" />
                                <span style="color:#9ca3af;">→</span>
                                <input type="date"
                                    name="masa_geo_timed[<?php echo esc_attr( $zone_id ); ?>][<?php echo esc_attr( $ri ); ?>][date_to]"
                                    value="<?php echo esc_attr( $rule['date_to'] ?? '' ); ?>"
                                    style="width:140px;" />
                                <button type="button" class="button button-small masa-remove-timed-rule" style="color:#dc2626;">✕</button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="button button-small masa-add-timed-rule" data-zone="<?php echo esc_attr( $zone_id ); ?>">
                            + <?php esc_html_e( 'إضافة قاعدة', 'masa-geo-pricing' ); ?>
                        </button>
                    </div>
                    <?php endforeach; ?>
                </div>



            <?php endif; ?>
            </div><!-- #masa-pricing-fields-wrap -->
        </div><!-- #masa_geo_pricing_panel -->

        <?php
    }

    public function save_meta( int $post_id ): void {
        if (
            ! isset( $_POST['masa_geo_product_nonce'] ) ||
            ! wp_verify_nonce(
                sanitize_text_field( wp_unslash( $_POST['masa_geo_product_nonce'] ) ),
                'masa_geo_save_product_meta'
            )
        ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // ─── المخزون الجغرافي (v3.6.0) ──────────────────────
        // لازم يتحفظ قبل أي early return — منتج "حسب الطلب" ممكن برضه يكون مقيّد
        if ( isset( $_POST['masa_geo_zone_stock'] ) && is_array( $_POST['masa_geo_zone_stock'] ) ) {
            $raw_stock = wp_unslash( $_POST['masa_geo_zone_stock'] );
            $map       = [];

            foreach ( $raw_stock as $zone_id => $qty ) {
                $zone_id = sanitize_key( (string) $zone_id );
                $qty     = trim( (string) $qty );

                // فارغ = اتبع المخزون العام (مفيش entry)
                if ( '' === $zone_id || '' === $qty || ! is_numeric( $qty ) ) {
                    continue;
                }

                $map[ $zone_id ] = max( 0, (int) $qty );
            }

            Masa_Geo_Zone_Stock::set_stock_map( $post_id, $map );
        }

        // ─── Exclude Flag (حسب الطلب) ───────────────────────
        $excluded = ! empty( $_POST['masa_geo_exclude'] );
        if ( $excluded ) {
            update_post_meta( $post_id, self::META_EXCLUDE_KEY, '1' );
        } else {
            delete_post_meta( $post_id, self::META_EXCLUDE_KEY );
        }

        // لو المنتج مستثنى → مش محتاج نحفظ أي حاجة تانية
        if ( $excluded ) {
            return;
        }

        // ─── Static Overrides ────────────────────────────────
        $raw   = isset( $_POST['masa_geo_zone_prices'] )
            ? (array) wp_unslash( $_POST['masa_geo_zone_prices'] )
            : [];

        $clean = $this->sanitize_zone_prices( $raw );

        $had_override = (bool) get_post_meta( $post_id, self::META_KEY, true );

        if ( empty( $clean ) ) {
            delete_post_meta( $post_id, self::META_KEY );
            if ( $had_override ) {
                Masa_Geo_Activity_Log::log( Masa_Geo_Activity_Log::EVENT_OVERRIDE_REMOVED, [
                    'product_id' => $post_id,
                ] );
            }
        } else {
            update_post_meta( $post_id, self::META_KEY, $clean );
            Masa_Geo_Activity_Log::log( Masa_Geo_Activity_Log::EVENT_OVERRIDE_SET, [
                'product_id' => $post_id,
                'zones'      => array_keys( $clean ),
            ] );
        }

        // ─── Timed Pricing ───────────────────────────────────
        $raw_timed = isset( $_POST['masa_geo_timed'] )
            ? (array) wp_unslash( $_POST['masa_geo_timed'] )
            : [];

        Masa_Geo_Timed_Pricing::save_rules( $post_id, $raw_timed );

        if ( ! empty( $raw_timed ) ) {
            Masa_Geo_Activity_Log::log( Masa_Geo_Activity_Log::EVENT_TIMED_PRICE_SET, [
                'product_id' => $post_id,
                'zones'      => array_keys( $raw_timed ),
            ] );
        }
    }

    public function render_variation_fields( int $loop, array $variation_data, \WP_Post $variation ): void {
        $zones        = $this->get_zones();
        $saved_prices = get_post_meta( $variation->ID, self::META_KEY, true );
        if ( ! is_array( $saved_prices ) ) {
            $saved_prices = [];
        }

        // جيب سعر الـ variation الأصلي عشان نحسب المضاعف كـ placeholder
        $variation_base_price = (float) get_post_meta( $variation->ID, '_price', true );

        if ( empty( $zones ) ) return;

        $is_excluded = self::is_excluded( $variation->post_parent );
        ?>
        <?php
        // v3.7.2: hidden field بـ id فريد لكل variation — name ثابت (يكفي PHP)
        // عشان منتجش "Duplicate form field id" لكل variation زي ما كان مع wp_nonce_field()
        // (نونس action واحد لكل request، فالقيمة نفسها في كل الصفوف — التحقق سليم)
        ?>
        <input type="hidden"
            id="masa_geo_variation_nonce_<?php echo esc_attr( $loop ); ?>"
            name="masa_geo_variation_nonce"
            value="<?php echo esc_attr( wp_create_nonce( 'masa_geo_save_variation' ) ); ?>" />
        <?php
        // ─── المخزون الجغرافي للـ variation (v3.7.0 — اختياري) ───
        $variation_stock = Masa_Geo_Zone_Stock::get_stock_map( $variation->ID );
        $vzones          = $this->get_zones();
        if ( ! empty( $vzones ) ) :
        ?>
        <div class="masa-variation-zone-stock" dir="rtl" style="margin:8px 0;padding:8px 10px;background:#f8fafc;border:1px dashed #cbd5e1;border-radius:6px;">
            <strong style="display:block;margin-bottom:4px;font-size:.82rem;">📦 <?php esc_html_e( 'مخزون جغرافي خاص بهذا المتغير (اختياري)', 'masa-geo-pricing' ); ?></strong>
            <span class="description" style="display:block;margin-bottom:6px;font-size:.75rem;">
                <?php esc_html_e( 'فارغ = يتبع إعدادات المنتج الأب. القيمة هنا تتقدم على الأب لهذا المتغير فقط.', 'masa-geo-pricing' ); ?>
            </span>
            <?php foreach ( $vzones as $vzone ) :
                if ( empty( $vzone['id'] ) ) continue;
                $vzid = $vzone['id'];
                $vval = array_key_exists( $vzid, $variation_stock ) ? (string) $variation_stock[ $vzid ] : '';
            ?>
                <label style="display:inline-flex;align-items:center;gap:5px;margin:2px 0 2px 12px;font-size:.8rem;">
                    <span><?php echo esc_html( $vzone['label'] ); ?>:</span>
                    <input type="number" min="0" step="1"
                        name="masa_geo_variation_zone_stock[<?php echo esc_attr( $loop ); ?>][<?php echo esc_attr( $vzid ); ?>]"
                        value="<?php echo esc_attr( $vval ); ?>"
                        placeholder="<?php esc_attr_e( 'الأب', 'masa-geo-pricing' ); ?>"
                        style="width:80px;" />
                </label>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="masa-variation-wrap" dir="rtl" style="
            margin: 10px 0;
            padding: 10px 14px;
            background: <?php echo $is_excluded ? '#fef9ec' : '#f8fafc'; ?>;
            border: 1px solid <?php echo $is_excluded ? '#fcd34d' : '#e2e8f0'; ?>;
            border-radius: 6px;
        ">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                <strong style="font-size:.82rem;color:#374151;">
                    🌍 <?php esc_html_e( 'سعر EGP لكل منطقة — هذا المتغير', 'masa-geo-pricing' ); ?>
                </strong>
                <?php if ( $is_excluded ) : ?>
                    <span style="font-size:.75rem;background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:4px;">
                        🚫 <?php esc_html_e( 'المنتج مستثنى من التسعير الجغرافي', 'masa-geo-pricing' ); ?>
                    </span>
                <?php else : ?>
                    <span style="font-size:.72rem;color:#9ca3af;">
                        <?php esc_html_e( 'اتركه فارغاً = يُحسب تلقائياً من سعر المتغير × مضاعف المنطقة', 'masa-geo-pricing' ); ?>
                    </span>
                <?php endif; ?>
            </div>

            <?php if ( $is_excluded ) : ?>
                <p style="font-size:.8rem;color:#92400e;margin:0;">
                    <?php esc_html_e( 'لا يُطبَّق أي تحويل على هذا المنتج — سعره كما هو لكل الدول.', 'masa-geo-pricing' ); ?>
                </p>
            <?php else : ?>
            <div style="display:flex;flex-wrap:wrap;gap:8px;">
                <?php foreach ( $zones as $zone ) :
                    $zone_id      = $zone['id'];
                    $saved_val    = isset( $saved_prices[ $zone_id ] ) ? (float) $saved_prices[ $zone_id ] : '';
                    $is_fallback  = in_array( '__FALLBACK__', $zone['countries'] ?? [], true );
                    $multiplier   = (float) ( $zone['multiplier'] ?? 1.0 );
                    // placeholder = السعر المتوقع لو اتحسب بالمضاعف
                    $auto_price   = $variation_base_price > 0
                        ? round( $variation_base_price * $multiplier, 2 )
                        : 0;
                    $placeholder  = $auto_price > 0
                        ? sprintf( __( 'تلقائي (~%s)', 'masa-geo-pricing' ), number_format( $auto_price, 2 ) )
                        : __( 'تلقائي', 'masa-geo-pricing' );
                ?>
                    <div style="
                        background:#fff;
                        border:1px solid <?php echo '' !== $saved_val ? '#2563eb' : '#e5e7eb'; ?>;
                        border-radius:6px;
                        padding:6px 10px;
                        min-width:130px;
                        position:relative;
                    ">
                        <label style="display:block;font-size:.72rem;font-weight:700;color:#374151;margin-bottom:4px;white-space:nowrap;">
                            <?php echo esc_html( $zone['label'] ); ?>
                            <?php if ( $is_fallback ) : ?>
                                <span style="font-size:.65rem;background:#e0e7ff;color:#3730a3;padding:1px 4px;border-radius:3px;">
                                    <?php esc_html_e( 'افتراضي', 'masa-geo-pricing' ); ?>
                                </span>
                            <?php endif; ?>
                            <span style="color:#6b7280;font-weight:400;">(<?php echo esc_html( $zone['currency'] ); ?>)</span>
                        </label>
                        <div style="display:flex;align-items:center;gap:4px;">
                            <input
                                type="number"
                                name="masa_variation_zone_prices[<?php echo esc_attr( $loop ); ?>][<?php echo esc_attr( $zone_id ); ?>]"
                                value="<?php echo '' !== $saved_val ? esc_attr( $saved_val ) : ''; ?>"
                                step="0.01"
                                min="0"
                                placeholder="<?php echo esc_attr( $placeholder ); ?>"
                                style="width:100%;font-size:.82rem;border:none;outline:none;padding:0;"
                            />
                            <span style="font-size:.7rem;color:#9ca3af;">ج.م</span>
                        </div>
                        <?php if ( '' !== $saved_val ) : ?>
                            <div style="position:absolute;top:4px;left:6px;width:6px;height:6px;background:#2563eb;border-radius:50%;"
                                title="<?php esc_attr_e( 'Override نشط', 'masa-geo-pricing' ); ?>">
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function save_variation_meta( int $variation_id, int $loop ): void {
        // v3.4.0: nonce مستقل بدل الاعتماد على nonce ووكومرس العام
        if (
            ! isset( $_POST['masa_geo_variation_nonce'] ) ||
            ! wp_verify_nonce(
                sanitize_text_field( wp_unslash( $_POST['masa_geo_variation_nonce'] ) ),
                'masa_geo_save_variation'
            )
        ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $variation_id ) ) {
            return;
        }

        // ─── المخزون الجغرافي للـ variation (v3.7.0) ───
        if ( isset( $_POST['masa_geo_variation_zone_stock'][ $loop ] ) && is_array( $_POST['masa_geo_variation_zone_stock'][ $loop ] ) ) {
            $raw_vstock = wp_unslash( $_POST['masa_geo_variation_zone_stock'][ $loop ] );
            $vmap       = [];

            foreach ( $raw_vstock as $zone_id => $qty ) {
                $zone_id = sanitize_key( (string) $zone_id );
                $qty     = trim( (string) $qty );

                if ( '' === $zone_id || '' === $qty || ! is_numeric( $qty ) ) {
                    continue; // فارغ = اتبع الأب
                }

                $vmap[ $zone_id ] = max( 0, (int) $qty );
            }

            Masa_Geo_Zone_Stock::set_stock_map( $variation_id, $vmap );
        }

        $all_raw = isset( $_POST['masa_variation_zone_prices'] )
            ? (array) wp_unslash( $_POST['masa_variation_zone_prices'] )
            : [];

        $raw   = $all_raw[ $loop ] ?? [];
        $clean = $this->sanitize_zone_prices( (array) $raw );

        if ( empty( $clean ) ) {
            delete_post_meta( $variation_id, self::META_KEY );
        } else {
            update_post_meta( $variation_id, self::META_KEY, $clean );
        }
    }

    private function sanitize_zone_prices( array $raw ): array {
        $clean = [];
        foreach ( $raw as $zone_id => $price ) {
            $zone_id = sanitize_key( $zone_id );
            $price   = trim( sanitize_text_field( (string) $price ) );
            if ( $price === '' ) continue;
            $price = (float) $price;
            if ( $price <= 0 ) continue;
            $clean[ $zone_id ] = $price;
        }
        return $clean;
    }

    private function get_zones(): array {
        $settings = get_option( MASA_GEO_OPTION_KEY, [] );
        return $settings['zones'] ?? [];
    }

    private static array $override_cache = []; // request-level cache

    public static function get_override_price( int $product_id, string $zone_id ): ?float {
        $cache_key = $product_id . '_' . $zone_id;

        if ( array_key_exists( $cache_key, self::$override_cache ) ) {
            return self::$override_cache[ $cache_key ];
        }

        $prices = get_post_meta( $product_id, self::META_KEY, true );

        if ( ! is_array( $prices ) || ! isset( $prices[ $zone_id ] ) ) {
            self::$override_cache[ $cache_key ] = null;
            return null;
        }

        $price = (float) $prices[ $zone_id ];
        $result = $price > 0 ? $price : null;
        self::$override_cache[ $cache_key ] = $result;
        return $result;
    }

    private static array $exclude_cache = []; // request-level cache

    /**
     * هل المنتج مستثنى من التسعير الجغرافي؟
     * بيشيك على المنتج نفسه، ولو كان variation بيشيك على الـ parent
     */
    public static function is_excluded( int $product_id ): bool {
        if ( isset( self::$exclude_cache[ $product_id ] ) ) {
            return self::$exclude_cache[ $product_id ];
        }

        // شيك مباشر على الـ ID
        if ( get_post_meta( $product_id, self::META_EXCLUDE_KEY, true ) ) {
            self::$exclude_cache[ $product_id ] = true;
            return true;
        }

        // لو كان variation → شيك على الـ parent
        $parent_id = wp_get_post_parent_id( $product_id );
        // wp_get_post_parent_id بتستخدم WP object cache داخلياً — لا داعي لـ cache إضافي
        if ( $parent_id && get_post_meta( $parent_id, self::META_EXCLUDE_KEY, true ) ) {
            self::$exclude_cache[ $product_id ] = true;
            return true;
        }

        self::$exclude_cache[ $product_id ] = false;
        return false;
    }
}
