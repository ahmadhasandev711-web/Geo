<?php
defined( 'ABSPATH' ) || exit;

/**
 * Masa_Geo_Tools
 * صفحة الأدوات المتقدمة:
 *   - Bulk Override
 *   - Import/Export Zones & Overrides
 *   - Zone Preview Calculator
 *   - Exchange Rate History Chart
 *   - Activity Log
 */
final class Masa_Geo_Tools {

    private static ?Masa_Geo_Tools $instance = null;

    public static function instance(): Masa_Geo_Tools {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu',            [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // AJAX endpoints
        add_action( 'wp_ajax_masa_geo_bulk_override',      [ $this, 'ajax_bulk_override' ] );
        add_action( 'wp_ajax_masa_geo_export_settings',    [ $this, 'ajax_export_settings' ] );
        add_action( 'wp_ajax_masa_geo_import_settings',    [ $this, 'ajax_import_settings' ] );
        add_action( 'wp_ajax_masa_geo_zone_preview',       [ $this, 'ajax_zone_preview' ] );
        add_action( 'wp_ajax_masa_geo_get_product_price',  [ $this, 'ajax_get_product_price' ] );
        add_action( 'wp_ajax_masa_geo_get_rate_history',   [ $this, 'ajax_get_rate_history' ] );
        add_action( 'wp_ajax_masa_geo_clear_log',          [ $this, 'ajax_clear_log' ] );
        add_action( 'wp_ajax_masa_geo_get_log',            [ $this, 'ajax_get_log' ] );
    }

    public function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, 'masa-geo-tools' ) === false ) return;

        wp_enqueue_script(
            'chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js',
            [],
            '4.4.2',
            true
        );
    }

    public function register_menu(): void {
        add_submenu_page(
            'woocommerce',
            __( 'Masa - أدوات متقدمة', 'masa-geo-pricing' ),
            __( '🛠️ أدوات Masa', 'masa-geo-pricing' ),
            'manage_woocommerce',
            'masa-geo-tools',
            [ $this, 'render_page' ]
        );
    }

    // ═════════════════════════════════════════════════════════
    // AJAX: Bulk Override
    // ═════════════════════════════════════════════════════════
    public function ajax_bulk_override(): void {
        check_ajax_referer( 'masa_geo_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [], 403 );
        }

        $zone_id     = sanitize_key( $_POST['zone_id']   ?? '' );
        $price       = (float) sanitize_text_field( $_POST['price'] ?? '0' );
        $product_ids = isset( $_POST['product_ids'] ) ? wp_parse_id_list( wp_unslash( $_POST['product_ids'] ) ) : [];
        $action_type = sanitize_key( $_POST['action_type'] ?? 'set' ); // set | remove

        if ( empty( $zone_id ) || empty( $product_ids ) ) {
            wp_send_json_error( [ 'message' => 'بيانات ناقصة.' ] );
        }

        $updated = 0;

        foreach ( $product_ids as $pid ) {
            $pid = (int) $pid;

            // تجاهل المنتجات المستثناة من التسعير الجغرافي
            if ( Masa_Geo_Product::is_excluded( $pid ) ) continue;

            $prices = get_post_meta( $pid, Masa_Geo_Product::META_KEY, true );
            if ( ! is_array( $prices ) ) $prices = [];

            if ( $action_type === 'remove' ) {
                unset( $prices[ $zone_id ] );
            } else {
                if ( $price <= 0 ) continue;
                $prices[ $zone_id ] = $price;
            }

            if ( empty( $prices ) ) {
                delete_post_meta( $pid, Masa_Geo_Product::META_KEY );
            } else {
                update_post_meta( $pid, Masa_Geo_Product::META_KEY, $prices );
            }
            $updated++;
        }

        Masa_Geo_Activity_Log::log( Masa_Geo_Activity_Log::EVENT_BULK_OVERRIDE, [
            'zone_id'     => $zone_id,
            'price'       => $price,
            'count'       => $updated,
            'action_type' => $action_type,
        ] );

        wp_send_json_success( [
            'message' => sprintf( 'تم تحديث %d منتج.', $updated ),
            'updated' => $updated,
        ] );
    }

    // ═════════════════════════════════════════════════════════
    // AJAX: Export Settings (Zones + Overrides)
    // ═════════════════════════════════════════════════════════
    public function ajax_export_settings(): void {
        check_ajax_referer( 'masa_geo_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [], 403 );
        }

        $settings = get_option( MASA_GEO_OPTION_KEY, [] );
        $zones    = $settings['zones'] ?? [];

        // جيب كل الـ Overrides
        $overrides = [];
        global $wpdb;
        $overrides_db = $wpdb->get_results( $wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
            Masa_Geo_Product::META_KEY
        ) );
        foreach ( $overrides_db as $row ) {
            $prices = maybe_unserialize( $row->meta_value );
            if ( ! empty( $prices ) ) {
                $overrides[ $row->post_id ] = $prices;
            }
        }

        $export = [
            'version'    => MASA_GEO_VERSION,
            'exported_at'=> current_time( 'c' ),
            'zones'      => $zones,
            'overrides'  => $overrides,
        ];

        Masa_Geo_Activity_Log::log( Masa_Geo_Activity_Log::EVENT_EXPORT, [
            'zones_count'    => count( $zones ),
            'overrides_count'=> count( $overrides ),
        ] );

        wp_send_json_success( [ 'data' => $export ] );
    }

    // ═════════════════════════════════════════════════════════
    // AJAX: Import Settings
    // ═════════════════════════════════════════════════════════
    public function ajax_import_settings(): void {
        check_ajax_referer( 'masa_geo_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [], 403 );
        }

        $raw = isset( $_POST['import_data'] ) ? wp_unslash( $_POST['import_data'] ) : '';
        $data = json_decode( $raw, true );

        if ( ! $data || ! is_array( $data ) ) {
            wp_send_json_error( [ 'message' => 'بيانات غير صحيحة. تأكد إن الملف JSON صحيح.' ] );
        }

        $imported_zones     = 0;
        $imported_overrides = 0;

        // Import Zones
        if ( ! empty( $data['zones'] ) && is_array( $data['zones'] ) ) {
            $settings = get_option( MASA_GEO_OPTION_KEY, [] );
            $settings['zones'] = array_map( function( $z ) {
                return [
                    'id'         => sanitize_key( $z['id'] ?? '' ),
                    'label'      => sanitize_text_field( $z['label'] ?? '' ),
                    'countries'  => array_map( 'strtoupper', array_map( 'sanitize_text_field', (array) ( $z['countries'] ?? [] ) ) ),
                    'multiplier' => max( 0.01, (float) ( $z['multiplier'] ?? 1.0 ) ),
                    'currency'   => strtoupper( sanitize_text_field( $z['currency'] ?? 'EGP' ) ),
                ];
            }, $data['zones'] );
            update_option( MASA_GEO_OPTION_KEY, $settings, 'no' );
            $imported_zones = count( $data['zones'] );
        }

        // Import Overrides
        if ( ! empty( $data['overrides'] ) && is_array( $data['overrides'] ) ) {
            foreach ( $data['overrides'] as $pid => $prices ) {
                $pid = (int) $pid;
                if ( $pid <= 0 ) continue;

                $clean = [];
                foreach ( (array) $prices as $zone_id => $price ) {
                    $price = (float) $price;
                    if ( $price > 0 ) {
                        $clean[ sanitize_key( $zone_id ) ] = $price;
                    }
                }

                if ( $clean ) {
                    update_post_meta( $pid, Masa_Geo_Product::META_KEY, $clean );
                    $imported_overrides++;
                }
            }
        }

        Masa_Geo_Activity_Log::log( Masa_Geo_Activity_Log::EVENT_IMPORT, [
            'zones_count'    => $imported_zones,
            'overrides_count'=> $imported_overrides,
        ] );

        wp_send_json_success( [
            'message'           => sprintf( 'تم الاستيراد: %d منطقة، %d Override.', $imported_zones, $imported_overrides ),
            'imported_zones'    => $imported_zones,
            'imported_overrides'=> $imported_overrides,
        ] );
    }

    public function ajax_zone_preview(): void {
        check_ajax_referer( 'masa_geo_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [], 403 );
        }

        $egp_price   = (float) sanitize_text_field( $_POST['price'] ?? '0' );
        $selected_id = (int) sanitize_text_field( $_POST['product_id'] ?? '0' );

        $product_id   = 0;
        $variation_id = 0;

        if ( $selected_id > 0 ) {
            if ( get_post_type( $selected_id ) === 'product_variation' ) {
                $variation_id = $selected_id;
                $product_id   = (int) wp_get_post_parent_id( $selected_id );
            } else {
                $product_id   = $selected_id;
            }
        }

        // الـ lookup_id = variation لو موجودة، وإلا parent
        $lookup_id = $variation_id ?: $product_id;

        if ( $egp_price <= 0 ) {
            wp_send_json_error( [ 'message' => 'أدخل سعراً صحيحاً.' ] );
        }

        $settings = get_option( MASA_GEO_OPTION_KEY, [] );
        $zones    = $settings['zones'] ?? [];
        $result   = [];

        foreach ( $zones as $zone ) {
            $zone_id  = $zone['id'];
            $currency = strtoupper( $zone['currency'] ?? 'EGP' );

            // مستثنى؟
            if ( $lookup_id && Masa_Geo_Product::is_excluded( $lookup_id ) ) {
                $result[] = [
                    'zone_id'    => $zone_id,
                    'zone_label' => $zone['label'],
                    'currency'   => 'EGP',
                    'egp_base'   => $egp_price,
                    'rate'       => 1.0,
                    'final'      => $egp_price,
                    'source'     => 'excluded',
                    'is_fallback'=> in_array( '__FALLBACK__', $zone['countries'] ?? [], true ),
                ];
                continue;
            }

            $source = 'multiplier';
            $base   = $egp_price * (float) $zone['multiplier'];

            if ( $lookup_id ) {
                // 1. Timed على الـ variation/product
                $timed = Masa_Geo_Timed_Pricing::get_active_price( $lookup_id, $zone_id );
                if ( $timed !== null ) {
                    $base   = $timed;
                    $source = 'timed';
                } else {
                    // 2. Override على الـ variation/product
                    $override = Masa_Geo_Product::get_override_price( $lookup_id, $zone_id );
                    if ( $override === null && $variation_id && $product_id ) {
                        // 3. Fallback: Timed/Override على الـ parent
                        $pt = Masa_Geo_Timed_Pricing::get_active_price( $product_id, $zone_id );
                        if ( $pt !== null ) {
                            $override = $pt;
                            $source   = 'timed (parent)';
                        } else {
                            $po = Masa_Geo_Product::get_override_price( $product_id, $zone_id );
                            if ( $po !== null ) {
                                $override = $po;
                                $source   = 'override (parent)';
                            }
                        }
                    }
                    if ( $override !== null ) {
                        $base   = $override;
                        $source = $source === 'multiplier' ? 'override' : $source;
                    }
                }
            }

            $rate        = Masa_Geo_Api::get_rate( $currency );
            $final_price = Masa_Geo_Rounding::round_price( $base * $rate, $currency );

            $result[] = [
                'zone_id'    => $zone_id,
                'zone_label' => $zone['label'],
                'currency'   => $currency,
                'egp_base'   => round( $base, 2 ),
                'rate'       => round( $rate, 6 ),
                'final'      => $final_price,
                'source'     => $source,
                'is_fallback'=> in_array( '__FALLBACK__', $zone['countries'] ?? [], true ),
            ];
        }

        wp_send_json_success( [ 'rows' => $result ] );
    }

    /**
     * AJAX: جلب سعر المنتج الأساسي بالاسم/ID لملء حقل السعر تلقائياً
     */
    public function ajax_get_product_price(): void {
        check_ajax_referer( 'masa_geo_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [], 403 );
        }

        $pid     = (int) ( $_POST['product_id'] ?? 0 );
        $product = wc_get_product( $pid );

        if ( $product ) {
            wp_send_json_success( [
                'price' => $product->get_regular_price(),
            ] );
        } else {
            wp_send_json_error( [ 'message' => 'المنتج غير موجود.' ] );
        }
    }

    // ═════════════════════════════════════════════════════════
    // AJAX: Rate History للـ Chart
    // ═════════════════════════════════════════════════════════
    public function ajax_get_rate_history(): void {
        check_ajax_referer( 'masa_geo_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [], 403 );
        }

        $currency = strtoupper( sanitize_text_field( $_POST['currency'] ?? 'USD' ) );
        $days     = min( 90, max( 7, (int) sanitize_text_field( $_POST['days'] ?? '30' ) ) );

        $history = Masa_Geo_Api::get_rate_history( $currency, $days );

        wp_send_json_success( [
            'currency' => $currency,
            'history'  => $history,
        ] );
    }

    // ═════════════════════════════════════════════════════════
    // AJAX: Activity Log
    // ═════════════════════════════════════════════════════════
    public function ajax_get_log(): void {
        check_ajax_referer( 'masa_geo_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [], 403 );
        }

        $page   = max( 1, (int) sanitize_text_field( $_POST['page']        ?? '1' ) );
        $filter = sanitize_key( $_POST['filter_type'] ?? '' );
        $limit  = 20;
        $offset = ( $page - 1 ) * $limit;

        $entries = Masa_Geo_Activity_Log::get( $limit, $offset, $filter );
        $total   = Masa_Geo_Activity_Log::count( $filter );

        $rows = array_map( function( $e ) {
            return [
                'id'         => $e['id'],
                'time_human' => wp_date( 'd/m/Y H:i', $e['time'] ),
                'event_label'=> Masa_Geo_Activity_Log::get_event_label( $e['event_type'] ),
                'event_type' => $e['event_type'],
                'user'       => esc_html( $e['user'] ),
                'context'    => $e['context'],
                'ip'         => $e['ip'],
            ];
        }, $entries );

        wp_send_json_success( [
            'rows'       => $rows,
            'total'      => $total,
            'pages'      => ceil( $total / $limit ),
            'page'       => $page,
        ] );
    }

    public function ajax_clear_log(): void {
        check_ajax_referer( 'masa_geo_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [], 403 );
        }
        Masa_Geo_Activity_Log::clear();
        wp_send_json_success( [ 'message' => 'تم مسح السجل.' ] );
    }

    // ═════════════════════════════════════════════════════════
    // Render الصفحة الرئيسية
    // ═════════════════════════════════════════════════════════
    public function render_page(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'غير مصرح.', 'masa-geo-pricing' ) );
        }

        $settings       = get_option( MASA_GEO_OPTION_KEY, [] );
        $zones          = $settings['zones'] ?? [];
        $zone_currencies= array_unique( array_column( $zones, 'currency' ) );
        sort( $zone_currencies );
        ?>
        <div class="wrap masa-geo-wrap" dir="rtl">
            <h1 class="masa-geo-title">🛠️ <?php esc_html_e( 'أدوات Masa المتقدمة', 'masa-geo-pricing' ); ?></h1>
            <div id="masa-tools-notice" class="masa-geo-notice" style="display:none;"></div>

            <!-- ─── Tabs ─── -->
            <nav class="masa-tools-tabs">
                <button class="masa-tab-btn active" data-tab="bulk">📦 Bulk Override</button>
                <button class="masa-tab-btn" data-tab="preview">🔍 Zone Preview</button>
                <button class="masa-tab-btn" data-tab="chart">📈 سعر الصرف</button>
                <button class="masa-tab-btn" data-tab="import-export">📁 Import/Export</button>
                <button class="masa-tab-btn" data-tab="log">📋 سجل النشاط</button>
            </nav>

            <!-- ─────────────── TAB: BULK OVERRIDE ─────────────── -->
            <div class="masa-tab-panel active" id="tab-bulk">
                <div class="masa-geo-card">
                    <h2>📦 <?php esc_html_e( 'Bulk Override — تعديل سعر Zone لعدة منتجات دفعة واحدة', 'masa-geo-pricing' ); ?></h2>
                    <p class="description"><?php esc_html_e( 'اختر Zone، حدد السعر، ثم ابحث عن المنتجات.', 'masa-geo-pricing' ); ?></p>

                    <table class="form-table masa-geo-form-table">
                        <tr>
                            <th><?php esc_html_e( 'المنطقة', 'masa-geo-pricing' ); ?></th>
                            <td>
                                <select id="bulk-zone-id">
                                    <?php foreach ( $zones as $zone ) : ?>
                                        <option value="<?php echo esc_attr( $zone['id'] ); ?>">
                                            <?php echo esc_html( $zone['label'] . ' (' . $zone['currency'] . ')' ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'الإجراء', 'masa-geo-pricing' ); ?></th>
                            <td>
                                <label><input type="radio" name="bulk-action-type" value="set" checked> <?php esc_html_e( 'تحديد سعر ثابت', 'masa-geo-pricing' ); ?></label>
                                &nbsp;&nbsp;
                                <label><input type="radio" name="bulk-action-type" value="remove"> <?php esc_html_e( 'إزالة Override (عودة للمضاعف)', 'masa-geo-pricing' ); ?></label>
                            </td>
                        </tr>
                        <tr id="bulk-price-row">
                            <th><?php esc_html_e( 'السعر بالـ EGP', 'masa-geo-pricing' ); ?></th>
                            <td>
                                <input type="number" id="bulk-price" step="0.01" min="0" style="width:150px;" placeholder="مثال: 350" />
                                <span style="color:#6b7280;font-size:.85rem;"> ج.م</span>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'اختيار المنتجات', 'masa-geo-pricing' ); ?></th>
                            <td>
                                <div style="display:flex;gap:10px;margin-bottom:8px;">
                                    <input type="text" id="bulk-product-search" class="regular-text" placeholder="<?php esc_attr_e( 'ابحث باسم المنتج...', 'masa-geo-pricing' ); ?>" />
                                    <button type="button" id="bulk-search-btn" class="button"><?php esc_html_e( 'بحث', 'masa-geo-pricing' ); ?></button>
                                </div>
                                <div id="bulk-search-results" style="max-height:250px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:6px;display:none;"></div>
                                <div id="bulk-selected-products" style="margin-top:8px;"></div>
                            </td>
                        </tr>
                    </table>

                    <div style="padding:1rem 0;">
                        <button type="button" id="bulk-apply-btn" class="button button-primary button-large">
                            ⚡ <?php esc_html_e( 'تطبيق على المنتجات المحددة', 'masa-geo-pricing' ); ?>
                        </button>
                        <span id="bulk-result" style="margin-right:12px;font-size:.9rem;"></span>
                    </div>
                </div>
            </div>

            <!-- ─────────────── TAB: ZONE PREVIEW ─────────────── -->
            <div class="masa-tab-panel" id="tab-preview">
                <div class="masa-geo-card">
                    <h2>🔍 <?php esc_html_e( 'Zone Preview Calculator', 'masa-geo-pricing' ); ?></h2>
                    <p class="description"><?php esc_html_e( 'ابحث عن منتج بالاسم/SKU أو اكتب سعر EGP مباشرة لمعاينة السعر في كل منطقة جغرافية.', 'masa-geo-pricing' ); ?></p>

                    <div style="display:flex;gap:16px;align-items:flex-end;margin-bottom:1.5rem;flex-wrap:wrap;">
                        <input type="hidden" id="preview-product-id" value="0" />

                        <div style="position:relative; display:inline-block; width: 300px;">
                            <label style="font-weight:600; display:block; margin-bottom:4px;"><?php esc_html_e( 'ابحث عن المنتج بالاسم أو الـ SKU (اختياري):', 'masa-geo-pricing' ); ?></label>
                            <div style="display:flex; gap:6px;">
                                <input type="text" id="preview-product-search" style="width:100%; height: 30px;" placeholder="<?php esc_attr_e( 'الاسم أو الـ SKU...', 'masa-geo-pricing' ); ?>" class="regular-text" />
                                <button type="button" id="preview-search-btn" class="button" style="height: 30px;"><?php esc_html_e( 'بحث', 'masa-geo-pricing' ); ?></button>
                            </div>
                            <div id="preview-search-results" style="position:absolute; top:100%; left:0; right:0; z-index:99; max-height:200px; overflow-y:auto; border:1px solid #e5e7eb; border-radius:6px; background:#fff; display:none; box-shadow: 0 4px 6px rgba(0,0,0,0.1);"></div>
                        </div>

                        <div>
                            <label style="font-weight:600; display:block; margin-bottom:4px;"><?php esc_html_e( 'سعر المنتج بالـ EGP:', 'masa-geo-pricing' ); ?></label>
                            <input type="number" id="preview-price" step="0.01" min="0" style="width:150px; height: 30px;" placeholder="مثال: 500" class="regular-text" />
                        </div>

                        <button type="button" id="preview-calc-btn" class="button button-primary" style="height: 30px;">
                            🧮 <?php esc_html_e( 'احسب المعاينة', 'masa-geo-pricing' ); ?>
                        </button>
                    </div>

                    <div id="preview-selected-display" style="display:none; align-items:center; gap:8px; background:#f1f5f9; padding:6px 12px; border-radius:6px; border:1px solid #cbd5e1; margin-bottom: 1.5rem; font-size:13px; max-width: fit-content;">
                        <strong><?php esc_html_e( 'المنتج المحدد:', 'masa-geo-pricing' ); ?></strong>
                        <span id="preview-selected-name"></span>
                        <button type="button" id="preview-clear-selected" class="button button-small" style="color:#dc2626; border-color:#fca5a5; background:#fef2f2; font-weight:600; padding:0 6px; min-height:20px; line-height:20px;">✕</button>
                    </div>

                    <div id="preview-results" style="display:none;">
                        <table class="widefat masa-report-table" id="preview-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'المنطقة', 'masa-geo-pricing' ); ?></th>
                                    <th><?php esc_html_e( 'سعر EGP المحوّل', 'masa-geo-pricing' ); ?></th>
                                    <th><?php esc_html_e( 'سعر الصرف', 'masa-geo-pricing' ); ?></th>
                                    <th><?php esc_html_e( 'السعر النهائي', 'masa-geo-pricing' ); ?></th>
                                    <th><?php esc_html_e( 'المصدر', 'masa-geo-pricing' ); ?></th>
                                </tr>
                            </thead>
                            <tbody id="preview-tbody"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ─────────────── TAB: RATE HISTORY CHART ─────────────── -->
            <div class="masa-tab-panel" id="tab-chart">
                <div class="masa-geo-card">
                    <h2>📈 <?php esc_html_e( 'تاريخ أسعار الصرف', 'masa-geo-pricing' ); ?></h2>
                    <p class="description"><?php esc_html_e( 'رسم بياني لتغير سعر الصرف على آخر 30 يوم. الأسعار تُحفظ تلقائياً عند كل تحديث يومي.', 'masa-geo-pricing' ); ?></p>

                    <div style="display:flex;gap:12px;align-items:center;margin-bottom:1.5rem;flex-wrap:wrap;">
                        <label style="font-weight:600;"><?php esc_html_e( 'العملة:', 'masa-geo-pricing' ); ?></label>
                        <select id="chart-currency">
                            <?php foreach ( $zone_currencies as $cur ) : ?>
                                <option value="<?php echo esc_attr( $cur ); ?>"><?php echo esc_html( $cur ); ?></option>
                            <?php endforeach; ?>
                            <option value="USD">USD</option>
                            <option value="EUR">EUR</option>
                        </select>
                        <label style="font-weight:600;"><?php esc_html_e( 'الفترة:', 'masa-geo-pricing' ); ?></label>
                        <select id="chart-days">
                            <option value="7"><?php esc_html_e( '7 أيام', 'masa-geo-pricing' ); ?></option>
                            <option value="14"><?php esc_html_e( '14 يوماً', 'masa-geo-pricing' ); ?></option>
                            <option value="30" selected><?php esc_html_e( '30 يوماً', 'masa-geo-pricing' ); ?></option>
                        </select>
                        <button type="button" id="chart-load-btn" class="button button-primary">
                            📊 <?php esc_html_e( 'عرض', 'masa-geo-pricing' ); ?>
                        </button>
                    </div>

                    <div id="chart-no-data" style="display:none;color:#9ca3af;text-align:center;padding:2rem;">
                        <?php esc_html_e( 'لا توجد بيانات تاريخية بعد. ستُجمع بعد أول تحديث يومي للأسعار.', 'masa-geo-pricing' ); ?>
                    </div>
                    <canvas id="rate-history-chart" style="max-height:350px;display:none;"></canvas>
                </div>
            </div>

            <!-- ─────────────── TAB: IMPORT/EXPORT ─────────────── -->
            <div class="masa-tab-panel" id="tab-import-export">
                <div class="masa-geo-grid">

                    <!-- Export -->
                    <div class="masa-geo-card">
                        <h2>📤 <?php esc_html_e( 'تصدير الإعدادات', 'masa-geo-pricing' ); ?></h2>
                        <p class="description">
                            <?php esc_html_e( 'تصدير كل الـ Zones والـ Overrides كملف JSON — تقدر تستورده على موقع تاني.', 'masa-geo-pricing' ); ?>
                        </p>
                        <button type="button" id="export-btn" class="button button-primary button-large">
                            💾 <?php esc_html_e( 'تصدير الآن', 'masa-geo-pricing' ); ?>
                        </button>
                        <div id="export-result" style="margin-top:1rem;"></div>
                    </div>

                    <!-- Import -->
                    <div class="masa-geo-card">
                        <h2>📥 <?php esc_html_e( 'استيراد الإعدادات', 'masa-geo-pricing' ); ?></h2>
                        <p class="description">
                            <?php esc_html_e( 'الصق محتوى JSON هنا. سيحل محل الـ Zones الموجودة ويضيف/يحدث الـ Overrides.', 'masa-geo-pricing' ); ?>
                        </p>
                        <textarea id="import-data" rows="6" class="large-text" placeholder='{"version":"2.0.0","zones":[...],"overrides":{}}'></textarea>
                        <br><br>
                        <button type="button" id="import-btn" class="button button-primary">
                            📥 <?php esc_html_e( 'استيراد', 'masa-geo-pricing' ); ?>
                        </button>
                        <div id="import-result" style="margin-top:1rem;"></div>
                    </div>

                </div>
            </div>

            <!-- ─────────────── TAB: ACTIVITY LOG ─────────────── -->
            <div class="masa-tab-panel" id="tab-log">
                <div class="masa-geo-card">
                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:1rem;">
                        <h2 style="margin:0;">📋 <?php esc_html_e( 'سجل النشاط', 'masa-geo-pricing' ); ?></h2>
                        <div style="display:flex;gap:8px;align-items:center;">
                            <select id="log-filter">
                                <option value=""><?php esc_html_e( 'كل الأحداث', 'masa-geo-pricing' ); ?></option>
                                <option value="zone_saved"><?php esc_html_e( 'Zone محفوظة', 'masa-geo-pricing' ); ?></option>
                                <option value="override_set"><?php esc_html_e( 'Override محدد', 'masa-geo-pricing' ); ?></option>
                                <option value="bulk_override">Bulk Override</option>
                                <option value="rates_updated"><?php esc_html_e( 'تحديث أسعار', 'masa-geo-pricing' ); ?></option>
                                <option value="import"><?php esc_html_e( 'استيراد', 'masa-geo-pricing' ); ?></option>
                                <option value="export"><?php esc_html_e( 'تصدير', 'masa-geo-pricing' ); ?></option>
                            </select>
                            <button type="button" id="log-refresh-btn" class="button">🔄 <?php esc_html_e( 'تحديث', 'masa-geo-pricing' ); ?></button>
                            <button type="button" id="log-clear-btn" class="button button-link-delete">🗑️ <?php esc_html_e( 'مسح السجل', 'masa-geo-pricing' ); ?></button>
                        </div>
                    </div>

                    <table class="widefat masa-report-table" id="log-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'التاريخ', 'masa-geo-pricing' ); ?></th>
                                <th><?php esc_html_e( 'الحدث', 'masa-geo-pricing' ); ?></th>
                                <th><?php esc_html_e( 'المستخدم', 'masa-geo-pricing' ); ?></th>
                                <th><?php esc_html_e( 'التفاصيل', 'masa-geo-pricing' ); ?></th>
                                <th>IP</th>
                            </tr>
                        </thead>
                        <tbody id="log-tbody">
                            <tr><td colspan="5" style="text-align:center;color:#9ca3af;"><?php esc_html_e( 'جاري التحميل...', 'masa-geo-pricing' ); ?></td></tr>
                        </tbody>
                    </table>

                    <div id="log-pagination" style="margin-top:1rem;display:flex;gap:6px;"></div>
                </div>
            </div>

        </div><!-- .wrap -->

        <?php $this->render_styles(); ?>
        <?php $this->render_scripts(); ?>
        <?php
    }

    // ─────────────────────────────────────────────────────────
    private function render_styles(): void {
        ?>
        <style>
        .masa-tools-tabs {
            display: flex;
            gap: 4px;
            border-bottom: 2px solid #e5e7eb;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }
        .masa-tab-btn {
            padding: .6rem 1.2rem;
            border: 1px solid #e5e7eb;
            border-bottom: none;
            border-radius: 6px 6px 0 0;
            background: #f9fafb;
            cursor: pointer;
            font-size: .9rem;
            color: #374151;
            transition: background .15s;
        }
        .masa-tab-btn.active {
            background: #fff;
            border-color: #e5e7eb;
            border-bottom-color: #fff;
            color: #2563eb;
            font-weight: 700;
            margin-bottom: -2px;
        }
        .masa-tab-panel { display: none; }
        .masa-tab-panel.active { display: block; }

        #bulk-search-results .bulk-product-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: .5rem .8rem;
            cursor: pointer;
            border-bottom: 1px solid #f0f0f0;
        }
        #bulk-search-results .bulk-product-item:hover { background: #f0f9ff; }
        #bulk-selected-products .bulk-tag {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #dbeafe;
            color: #1e40af;
            border-radius: 999px;
            padding: .2rem .7rem;
            font-size: .8rem;
            margin: 2px;
        }
        #bulk-selected-products .bulk-tag-remove {
            cursor: pointer;
            font-size: 1rem;
            line-height: 1;
            color: #60a5fa;
        }
        .log-context-details {
            font-size: .78rem;
            color: #6b7280;
            max-width: 300px;
            word-break: break-word;
        }
        .page-btn {
            padding: .3rem .8rem;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            cursor: pointer;
            background: #fff;
            font-size: .85rem;
        }
        .page-btn.active {
            background: #2563eb;
            color: #fff;
            border-color: #2563eb;
        }
        .source-badge {
            display: inline-block;
            padding: .1rem .5rem;
            border-radius: 4px;
            font-size: .72rem;
            font-weight: 700;
        }
        .source-timed    { background: #fef3c7; color: #92400e; }
        .source-override { background: #dcfce7; color: #15803d; }
        .source-multiplier { background: #e0e7ff; color: #3730a3; }
        </style>
        <?php
    }

    private function render_scripts(): void {
        $zones    = get_option( MASA_GEO_OPTION_KEY, [] )['zones'] ?? [];
        $ajax_url = admin_url( 'admin-ajax.php' );
        $nonce    = wp_create_nonce( 'masa_geo_admin_nonce' );
        ?>
        <script>
        (function($) {
            var AJAX_URL = <?php echo wp_json_encode( $ajax_url ); ?>;
            var NONCE    = <?php echo wp_json_encode( $nonce ); ?>;
            var selectedProducts = {};
            var logCurrentPage = 1;
            var rateChart = null;

            function showNotice(msg, type) {
                var cls = type === 'error' ? 'notice-error' : 'notice-success';
                $('#masa-tools-notice')
                    .removeClass('notice-success notice-error')
                    .addClass('notice ' + cls)
                    .html('<p>' + msg + '</p>')
                    .show();
                setTimeout(function(){ $('#masa-tools-notice').hide(); }, 4000);
            }

            // ── Tabs ────────────────────────────────────────────
            $(document).on('click', '.masa-tab-btn', function() {
                var tab = $(this).data('tab');
                $('.masa-tab-btn').removeClass('active');
                $(this).addClass('active');
                $('.masa-tab-panel').removeClass('active');
                $('#tab-' + tab).addClass('active');

                if (tab === 'log') loadLog(1);
                if (tab === 'chart') loadChart();
            });

            // ── Bulk: radio toggle ───────────────────────────────
            $(document).on('change', 'input[name="bulk-action-type"]', function() {
                if ($(this).val() === 'remove') {
                    $('#bulk-price-row').hide();
                } else {
                    $('#bulk-price-row').show();
                }
            });

            // ── Bulk: product search ─────────────────────────────
            $('#bulk-search-btn').on('click', function() {
                var q = $('#bulk-product-search').val().trim();
                if (q.length < 2) return;
                var $res = $('#bulk-search-results').html('جاري البحث...').show();

                $.getJSON(AJAX_URL, {
                    action: 'woocommerce_json_search_products_and_variations',
                    security: '<?php echo esc_js( wp_create_nonce( 'search-products' ) ); ?>',
                    term: q
                }, function(data) {
                    $res.empty();
                    if (!data || $.isEmptyObject(data)) { $res.html('<div style="padding:.5rem 1rem;color:#9ca3af;">لا توجد نتائج.</div>'); return; }
                    $.each(data, function(id, name) {
                        $('<div class="bulk-product-item">')
                             .data({ id: id, name: name })
                             .html('<input type="checkbox" ' + (selectedProducts[id] ? 'checked' : '') + '> ' + name + ' <small style="color:#9ca3af;">#' + id + '</small>')
                             .appendTo($res);
                    });
                });
            });

            $(document).on('change', '#bulk-search-results .bulk-product-item input[type=checkbox]', function() {
                var $item = $(this).closest('.bulk-product-item');
                var id = $item.data('id'), name = $item.data('name');
                if (this.checked) {
                    selectedProducts[id] = name;
                } else {
                    delete selectedProducts[id];
                }
                renderSelectedTags();
            });

            function renderSelectedTags() {
                var $wrap = $('#bulk-selected-products').empty();
                $.each(selectedProducts, function(id, name) {
                    $('<span class="bulk-tag">')
                        .html(name + ' <span class="bulk-tag-remove" data-id="' + id + '">×</span>')
                        .appendTo($wrap);
                });
            }

            $(document).on('click', '.bulk-tag-remove', function() {
                delete selectedProducts[$(this).data('id')];
                renderSelectedTags();
            });

            // ── Bulk: Apply ──────────────────────────────────────
            $('#bulk-apply-btn').on('click', function() {
                var zone_id     = $('#bulk-zone-id').val();
                var price       = $('#bulk-price').val();
                var action_type = $('input[name="bulk-action-type"]:checked').val();
                var ids         = Object.keys(selectedProducts);

                if (!ids.length) { showNotice('اختر منتجاً على الأقل.', 'error'); return; }
                if (action_type === 'set' && (!price || parseFloat(price) <= 0)) {
                    showNotice('أدخل سعراً صحيحاً.', 'error'); return;
                }

                var $btn = $(this).prop('disabled', true).text('⏳ جاري...');

                $.post(AJAX_URL, {
                    action: 'masa_geo_bulk_override',
                    nonce: NONCE,
                    zone_id: zone_id,
                    price: price,
                    product_ids: ids,
                    action_type: action_type
                }, function(res) {
                    $btn.prop('disabled', false).html('⚡ تطبيق على المنتجات المحددة');
                    if (res.success) {
                        showNotice(res.data.message, 'success');
                        $('#bulk-result').text(res.data.message);
                    } else {
                        showNotice(res.data.message || 'خطأ.', 'error');
                    }
                });
            });

            // ── Preview: product search ──────────────────────────
            $('#preview-search-btn').on('click', function() {
                var q = $('#preview-product-search').val().trim();
                if (q.length < 2) return;
                var $res = $('#preview-search-results').html('<div style="padding:.5rem;color:#6b7280;">جاري البحث...</div>').show();

                $.getJSON(AJAX_URL, {
                    action: 'woocommerce_json_search_products_and_variations',
                    security: '<?php echo esc_js( wp_create_nonce( 'search-products' ) ); ?>',
                    term: q
                }, function(data) {
                    $res.empty();
                    if (!data || $.isEmptyObject(data)) { $res.html('<div style="padding:.5rem 1rem;color:#9ca3af;">لا توجد نتائج.</div>'); return; }
                    $.each(data, function(id, name) {
                        $('<div class="preview-product-item" style="padding:.5rem; cursor:pointer; border-bottom:1px solid #f1f5f9;">')
                            .data({ id: id, name: name })
                            .html(name + ' <small style="color:#9ca3af;">#' + id + '</small>')
                            .appendTo($res);
                    });
                });
            });

            // Hide dropdown when clicking outside
            $(document).on('click', function(e) {
                if (!$(e.target).closest('#preview-product-search, #preview-search-btn, #preview-search-results').length) {
                    $('#preview-search-results').hide();
                }
            });

            // Selecting a product from preview search
            $(document).on('click', '.preview-product-item', function() {
                var id   = $(this).data('id');
                var name = $(this).data('name');
                
                $('#preview-product-id').val(id);
                $('#preview-product-search').val('');
                $('#preview-search-results').hide();
                
                // Show selected display
                $('#preview-selected-name').text(name + ' (#' + id + ')');
                $('#preview-selected-display').css('display', 'inline-flex');
                
                // Fetch the product price automatically
                $.post(AJAX_URL, {
                    action: 'masa_geo_get_product_price',
                    nonce: NONCE,
                    product_id: id
                }, function(res) {
                    if (res.success && res.data.price) {
                        $('#preview-price').val(res.data.price);
                        // Trigger calculation automatically!
                        $('#preview-calc-btn').click();
                    }
                });
            });

            // Clear selected product
            $('#preview-clear-selected').on('click', function() {
                $('#preview-product-id').val('0');
                $('#preview-selected-name').text('');
                $('#preview-selected-display').hide();
                $('#preview-price').val('');
                $('#preview-tbody').empty();
                $('#preview-results').hide();
            });

            // ── Zone Preview ─────────────────────────────────────
            $('#preview-calc-btn').on('click', function() {
                var price    = parseFloat($('#preview-price').val());
                var pid      = parseInt($('#preview-product-id').val())  || 0;
                if (!price || price <= 0) { showNotice('أدخل سعراً صحيحاً.', 'error'); return; }

                $(this).prop('disabled', true);

                $.post(AJAX_URL, {
                    action: 'masa_geo_zone_preview',
                    nonce: NONCE,
                    price: price,
                    product_id: pid
                }, function(res) {
                    $('#preview-calc-btn').prop('disabled', false);
                    if (!res.success) { showNotice('خطأ في الحساب.', 'error'); return; }

                    var $tbody = $('#preview-tbody').empty();
                    var sourceMap = {
                        'timed'           : '⏰ Timed',
                        'timed (parent)'  : '⏰ Timed (parent)',
                        'override'        : '✏️ Override',
                        'override (parent)': '✏️ Override (parent)',
                        'multiplier'      : '× مضاعف',
                        'excluded'        : '🚫 مستثنى'
                    };
                    $.each(res.data.rows, function(i, row) {
                        var srcLabel  = sourceMap[row.source] || row.source;
                        var srcClass  = row.source.indexOf('timed') !== -1 ? 'timed'
                                      : row.source.indexOf('override') !== -1 ? 'override'
                                      : row.source === 'excluded' ? 'timed'
                                      : 'multiplier';
                        var fallback = row.is_fallback ? '<span class="masa-badge masa-badge--fallback">افتراضي</span>' : '';
                        $tbody.append('<tr>'
                            + '<td><strong>' + row.zone_label + '</strong> ' + fallback + '</td>'
                            + '<td>' + row.egp_base.toFixed(2) + ' ج.م</td>'
                            + '<td style="font-family:monospace;font-size:.82rem;">' + row.rate + '</td>'
                            + '<td><strong style="color:#2563eb;">' + row.final.toLocaleString() + ' ' + row.currency + '</strong></td>'
                            + '<td><span class="source-badge source-' + srcClass + '">' + srcLabel + '</span></td>'
                            + '</tr>');
                    });
                    $('#preview-results').show();
                });
            });

            // ── Rate History Chart ───────────────────────────────
            function loadChart() {
                var currency = $('#chart-currency').val();
                var days     = $('#chart-days').val();

                $.post(AJAX_URL, {
                    action: 'masa_geo_get_rate_history',
                    nonce: NONCE,
                    currency: currency,
                    days: days
                }, function(res) {
                    if (!res.success || !res.data.history.length) {
                        $('#rate-history-chart').hide();
                        $('#chart-no-data').show();
                        return;
                    }

                    $('#chart-no-data').hide();
                    var $canvas = $('#rate-history-chart').show();
                    var labels  = res.data.history.map(function(p){ return p.date; });
                    var values  = res.data.history.map(function(p){ return p.rate; });

                    if (rateChart) rateChart.destroy();
                    rateChart = new Chart($canvas[0], {
                        type: 'line',
                        data: {
                            labels: labels,
                            datasets: [{
                                label: 'EGP → ' + currency,
                                data: values,
                                borderColor: '#2563eb',
                                backgroundColor: 'rgba(37,99,235,.08)',
                                tension: 0.3,
                                fill: true,
                                pointRadius: 4,
                            }]
                        },
                        options: {
                            responsive: true,
                            plugins: { legend: { position: 'top' } },
                            scales: {
                                y: { title: { display: true, text: 'Rate (1 EGP → ' + currency + ')' } }
                            }
                        }
                    });
                });
            }

            $('#chart-load-btn').on('click', loadChart);

            // ── Export ────────────────────────────────────────────
            $('#export-btn').on('click', function() {
                $(this).prop('disabled', true).text('⏳ جاري التصدير...');
                $.post(AJAX_URL, { action: 'masa_geo_export_settings', nonce: NONCE }, function(res) {
                    $('#export-btn').prop('disabled', false).html('💾 تصدير الآن');
                    if (!res.success) { showNotice('فشل التصدير.', 'error'); return; }

                    var json = JSON.stringify(res.data.data, null, 2);
                    var blob = new Blob([json], { type: 'application/json' });
                    var a    = document.createElement('a');
                    a.href   = URL.createObjectURL(blob);
                    a.download = 'masa-geo-pricing-export-' + new Date().toISOString().slice(0,10) + '.json';
                    a.click();
                    showNotice('تم التصدير.', 'success');
                });
            });

            // ── Import ────────────────────────────────────────────
            $('#import-btn').on('click', function() {
                var data = $('#import-data').val().trim();
                if (!data) { showNotice('الصق بيانات JSON.', 'error'); return; }

                if (!confirm('سيحل الاستيراد محل إعدادات الـ Zones الحالية. هل أنت متأكد؟')) return;

                $(this).prop('disabled', true).text('⏳ جاري الاستيراد...');
                $.post(AJAX_URL, {
                    action: 'masa_geo_import_settings',
                    nonce: NONCE,
                    import_data: data
                }, function(res) {
                    $('#import-btn').prop('disabled', false).html('📥 استيراد');
                    if (res.success) {
                        showNotice(res.data.message, 'success');
                        $('#import-result').html('<span style="color:#16a34a;">' + res.data.message + '</span>');
                    } else {
                        showNotice(res.data.message || 'فشل الاستيراد.', 'error');
                    }
                });
            });

            // ── Activity Log ──────────────────────────────────────
            function loadLog(page) {
                logCurrentPage = page;
                var filter = $('#log-filter').val();

                $.post(AJAX_URL, {
                    action: 'masa_geo_get_log',
                    nonce: NONCE,
                    page: page,
                    filter_type: filter
                }, function(res) {
                    var $tbody = $('#log-tbody').empty();

                    if (!res.success || !res.data.rows.length) {
                        $tbody.html('<tr><td colspan="5" style="text-align:center;color:#9ca3af;">لا توجد سجلات.</td></tr>');
                        $('#log-pagination').empty();
                        return;
                    }

                    $.each(res.data.rows, function(i, row) {
                        var ctx = '';
                        if (row.context && Object.keys(row.context).length) {
                            ctx = '<div class="log-context-details">' + JSON.stringify(row.context).replace(/[{}"]/g, '').replace(/,/g, ' | ') + '</div>';
                        }
                        $tbody.append('<tr>'
                            + '<td style="white-space:nowrap;">' + row.time_human + '</td>'
                            + '<td>' + row.event_label + '</td>'
                            + '<td>' + row.user + '</td>'
                            + '<td>' + ctx + '</td>'
                            + '<td style="font-size:.75rem;color:#9ca3af;">' + row.ip + '</td>'
                            + '</tr>');
                    });

                    // Pagination
                    var $pag = $('#log-pagination').empty();
                    for (var p = 1; p <= res.data.pages; p++) {
                        $('<button class="page-btn' + (p === page ? ' active' : '') + '">')
                            .text(p)
                            .data('page', p)
                            .on('click', function(){ loadLog($(this).data('page')); })
                            .appendTo($pag);
                    }
                });
            }

            $('#log-refresh-btn').on('click', function(){ loadLog(1); });
            $('#log-filter').on('change', function(){ loadLog(1); });

            $('#log-clear-btn').on('click', function() {
                if (!confirm('سيُحذف كل سجل النشاط. هل أنت متأكد؟')) return;
                $.post(AJAX_URL, { action: 'masa_geo_clear_log', nonce: NONCE }, function(res) {
                    if (res.success) {
                        showNotice('تم مسح السجل.', 'success');
                        loadLog(1);
                    }
                });
            });

            // Load log on first visit
            if ($('#tab-log').hasClass('active')) loadLog(1);

        })(jQuery);
        </script>
        <?php
    }
}
