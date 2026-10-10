<?php
defined( 'ABSPATH' ) || exit;

/**
 * Masa_Geo_Dashboard
 * لوحة الإيرادات الجغرافية + Zone Revenue Report
 */
final class Masa_Geo_Dashboard {

    private static ?Masa_Geo_Dashboard $instance = null;

    public static function instance(): Masa_Geo_Dashboard {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'wp_ajax_masa_geo_dashboard_data', [ $this, 'ajax_dashboard_data' ] );
    }

    public function register_menu(): void {
        add_submenu_page(
            'woocommerce',
            __( 'Masa - تقارير جغرافية', 'masa-geo-pricing' ),
            __( '📊 تقارير Masa', 'masa-geo-pricing' ),
            'manage_woocommerce',
            'masa-geo-dashboard',
            [ $this, 'render_page' ]
        );
    }

    // ─────────────────────────────────────────────────────────
    // AJAX: جيب بيانات الـ Dashboard
    // ─────────────────────────────────────────────────────────
    public function ajax_dashboard_data(): void {
        check_ajax_referer( 'masa_geo_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [], 403 );
        }

        $from = sanitize_text_field( $_POST['from'] ?? date( 'Y-m-01' ) );
        $to   = sanitize_text_field( $_POST['to']   ?? date( 'Y-m-d' ) );

        $zone_revenue    = Masa_Geo_Order::get_revenue_by_zone( $from, $to );
        $country_revenue = Masa_Geo_Order::get_orders_by_country( 10 );
        $alert_log       = Masa_Geo_Alerts::get_log();

        wp_send_json_success( [
            'zone_revenue'    => $zone_revenue,
            'country_revenue' => $country_revenue,
            'alert_log'       => array_slice( $alert_log, 0, 5 ),
            'from'            => $from,
            'to'              => $to,
        ] );
    }

    // ─────────────────────────────────────────────────────────
    // Render الصفحة
    // ─────────────────────────────────────────────────────────
    public function render_page(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'غير مصرح.', 'masa-geo-pricing' ) );
        }

        $settings     = get_option( MASA_GEO_OPTION_KEY, [] );
        $zone_revenue = Masa_Geo_Order::get_revenue_by_zone();
        $top_countries = Masa_Geo_Order::get_orders_by_country( 10 );
        $alert_log    = Masa_Geo_Alerts::get_log();
        $last_update  = Masa_Geo_Api::get_last_update();

        // إجماليات
        $total_egp_revenue = array_sum( array_column( $zone_revenue, 'egp_revenue' ) );
        $total_orders      = array_sum( array_column( $zone_revenue, 'orders' ) );
        ?>
        <div class="wrap masa-geo-wrap" dir="rtl">
            <h1 class="masa-geo-title">📊 <?php esc_html_e( 'التقارير الجغرافية', 'masa-geo-pricing' ); ?></h1>

            <!-- ─── KPI Cards ─── -->
            <div class="masa-kpi-grid">
                <div class="masa-kpi-card">
                    <div class="masa-kpi-icon">💰</div>
                    <div class="masa-kpi-value"><?php echo esc_html( number_format( $total_egp_revenue, 0 ) ); ?> ج.م</div>
                    <div class="masa-kpi-label"><?php esc_html_e( 'إجمالي الإيرادات (EGP)', 'masa-geo-pricing' ); ?></div>
                </div>
                <div class="masa-kpi-card">
                    <div class="masa-kpi-icon">📦</div>
                    <div class="masa-kpi-value"><?php echo esc_html( number_format( $total_orders ) ); ?></div>
                    <div class="masa-kpi-label"><?php esc_html_e( 'إجمالي الأوردرات', 'masa-geo-pricing' ); ?></div>
                </div>
                <div class="masa-kpi-card">
                    <div class="masa-kpi-icon">🌍</div>
                    <div class="masa-kpi-value"><?php echo esc_html( count( $top_countries ) ); ?></div>
                    <div class="masa-kpi-label"><?php esc_html_e( 'دول نشطة', 'masa-geo-pricing' ); ?></div>
                </div>
                <div class="masa-kpi-card">
                    <div class="masa-kpi-icon">🔄</div>
                    <div class="masa-kpi-value" style="font-size:13px;"><?php echo esc_html( $last_update ); ?></div>
                    <div class="masa-kpi-label"><?php esc_html_e( 'آخر تحديث أسعار', 'masa-geo-pricing' ); ?></div>
                </div>
            </div>

            <div class="masa-geo-grid" style="grid-template-columns:1fr 1fr;">

                <!-- ─── Zone Revenue Report ─── -->
                <div class="masa-geo-card">
                    <h2>🗺️ <?php esc_html_e( 'إيرادات المناطق', 'masa-geo-pricing' ); ?></h2>

                    <?php if ( empty( $zone_revenue ) ) : ?>
                        <p style="color:#9ca3af;text-align:center;padding:2rem;">
                            <?php esc_html_e( 'لا توجد بيانات بعد. ستظهر البيانات بعد أول أوردر مكتمل.', 'masa-geo-pricing' ); ?>
                        </p>
                    <?php else : ?>
                        <table class="widefat masa-report-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'المنطقة', 'masa-geo-pricing' ); ?></th>
                                    <th><?php esc_html_e( 'العملة', 'masa-geo-pricing' ); ?></th>
                                    <th><?php esc_html_e( 'الأوردرات', 'masa-geo-pricing' ); ?></th>
                                    <th><?php esc_html_e( 'إجمالي EGP', 'masa-geo-pricing' ); ?></th>
                                    <th><?php esc_html_e( 'إجمالي محلي', 'masa-geo-pricing' ); ?></th>
                                    <th><?php esc_html_e( 'النسبة', 'masa-geo-pricing' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $zone_revenue as $zone_id => $data ) :
                                    $pct = $total_egp_revenue > 0
                                        ? round( ( $data['egp_revenue'] / $total_egp_revenue ) * 100, 1 )
                                        : 0;
                                ?>
                                <tr>
                                    <td><strong><?php echo esc_html( $data['label'] ); ?></strong></td>
                                    <td>
                                        <span style="background:#dbeafe;color:#1e40af;padding:2px 6px;border-radius:4px;font-size:11px;font-weight:700;">
                                            <?php echo esc_html( $data['currency'] ); ?>
                                        </span>
                                    </td>
                                    <td><?php echo esc_html( number_format( $data['orders'] ) ); ?></td>
                                    <td><strong><?php echo esc_html( number_format( $data['egp_revenue'], 2 ) ); ?> ج.م</strong></td>
                                    <td><?php echo esc_html( number_format( $data['local_revenue'], 2 ) ); ?></td>
                                    <td>
                                        <div style="display:flex;align-items:center;gap:6px;">
                                            <div style="flex:1;background:#f3f4f6;border-radius:4px;height:6px;min-width:60px;">
                                                <div style="width:<?php echo esc_attr( $pct ); ?>%;background:#2563eb;height:6px;border-radius:4px;"></div>
                                            </div>
                                            <span style="font-size:12px;color:#6b7280;"><?php echo esc_html( $pct ); ?>%</span>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr style="background:#f8fafc;">
                                    <td colspan="2"><strong><?php esc_html_e( 'الإجمالي', 'masa-geo-pricing' ); ?></strong></td>
                                    <td><strong><?php echo esc_html( number_format( $total_orders ) ); ?></strong></td>
                                    <td><strong><?php echo esc_html( number_format( $total_egp_revenue, 2 ) ); ?> ج.م</strong></td>
                                    <td colspan="2"></td>
                                </tr>
                            </tfoot>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- ─── Top Countries ─── -->
                <div class="masa-geo-card">
                    <h2>🏆 <?php esc_html_e( 'أعلى الدول مبيعاً', 'masa-geo-pricing' ); ?></h2>

                    <?php if ( empty( $top_countries ) ) : ?>
                        <p style="color:#9ca3af;text-align:center;padding:2rem;">
                            <?php esc_html_e( 'لا توجد بيانات بعد.', 'masa-geo-pricing' ); ?>
                        </p>
                    <?php else : ?>
                        <div class="masa-countries-list">
                            <?php
                            $max_rev = max( array_column( $top_countries, 'egp_revenue' ) );
                            $rank = 1;
                            foreach ( $top_countries as $country_code => $data ) :
                                $pct = $max_rev > 0 ? round( ( $data['egp_revenue'] / $max_rev ) * 100 ) : 0;
                                $medal = $rank === 1 ? '🥇' : ( $rank === 2 ? '🥈' : ( $rank === 3 ? '🥉' : "#$rank" ) );
                            ?>
                            <div class="masa-country-row">
                                <span class="masa-country-rank"><?php echo esc_html( $medal ); ?></span>
                                <span class="masa-country-code"><?php echo esc_html( $country_code ); ?></span>
                                <div class="masa-country-bar-wrap">
                                    <div class="masa-country-bar" style="width:<?php echo esc_attr( $pct ); ?>%"></div>
                                </div>
                                <span class="masa-country-orders"><?php echo esc_html( $data['orders'] ); ?> أوردر</span>
                                <span class="masa-country-revenue"><?php echo esc_html( number_format( $data['egp_revenue'], 0 ) ); ?> ج.م</span>
                            </div>
                            <?php $rank++; endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- ─── سجل التنبيهات ─── -->
                <div class="masa-geo-card masa-geo-card--full">
                    <h2>🔔 <?php esc_html_e( 'سجل تنبيهات أسعار الصرف', 'masa-geo-pricing' ); ?></h2>

                    <?php if ( empty( $alert_log ) ) : ?>
                        <p style="color:#9ca3af;text-align:center;padding:1rem;">
                            <?php esc_html_e( 'لا توجد تنبيهات بعد. ستظهر هنا عند حدوث تغييرات كبيرة في أسعار الصرف.', 'masa-geo-pricing' ); ?>
                        </p>
                    <?php else : ?>
                        <table class="widefat masa-report-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'التاريخ', 'masa-geo-pricing' ); ?></th>
                                    <th><?php esc_html_e( 'العملة', 'masa-geo-pricing' ); ?></th>
                                    <th><?php esc_html_e( 'السعر السابق', 'masa-geo-pricing' ); ?></th>
                                    <th><?php esc_html_e( 'السعر الجديد', 'masa-geo-pricing' ); ?></th>
                                    <th><?php esc_html_e( 'التغيير', 'masa-geo-pricing' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $alert_log as $log_entry ) :
                                    foreach ( $log_entry['alerts'] as $alert ) :
                                        $color = $alert['change_pct'] > 0 ? '#dc2626' : '#16a34a';
                                        $arrow = $alert['change_pct'] > 0 ? '↑' : '↓';
                                ?>
                                <tr>
                                    <td><?php echo esc_html( wp_date( 'd/m/Y H:i', $log_entry['time'] ) ); ?></td>
                                    <td><strong><?php echo esc_html( $alert['currency'] ); ?></strong></td>
                                    <td><?php echo esc_html( number_format( $alert['prev'], 6 ) ); ?></td>
                                    <td><?php echo esc_html( number_format( $alert['current'], 6 ) ); ?></td>
                                    <td style="color:<?php echo esc_attr( $color ); ?>;font-weight:700;">
                                        <?php echo esc_html( $arrow . ' ' . $alert['direction'] . ' ' . abs( $alert['change_pct'] ) . '%' ); ?>
                                    </td>
                                </tr>
                                <?php endforeach; endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

            </div><!-- .masa-geo-grid -->
        </div><!-- .wrap -->

        <style>
        .masa-kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .masa-kpi-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 1.2rem;
            text-align: center;
            box-shadow: 0 2px 6px rgba(0,0,0,.05);
        }
        .masa-kpi-icon { font-size: 1.8rem; margin-bottom: .4rem; }
        .masa-kpi-value { font-size: 1.4rem; font-weight: 700; color: #1d2327; }
        .masa-kpi-label { font-size: .78rem; color: #6b7280; margin-top: .2rem; }

        .masa-report-table { direction: rtl; }
        .masa-report-table th { text-align: right !important; background: #f8fafc; }
        .masa-report-table td { vertical-align: middle; }

        .masa-countries-list { display: flex; flex-direction: column; gap: .6rem; }
        .masa-country-row {
            display: flex;
            align-items: center;
            gap: .8rem;
            padding: .5rem 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .masa-country-rank { font-size: 1.1rem; min-width: 28px; }
        .masa-country-code { font-weight: 700; min-width: 35px; font-size: .9rem; }
        .masa-country-bar-wrap { flex: 1; background: #f3f4f6; border-radius: 4px; height: 8px; }
        .masa-country-bar { background: linear-gradient(90deg, #2563eb, #60a5fa); height: 8px; border-radius: 4px; transition: width .3s; }
        .masa-country-orders { font-size: .8rem; color: #6b7280; min-width: 65px; text-align: center; }
        .masa-country-revenue { font-weight: 700; font-size: .85rem; color: #1d2327; min-width: 100px; text-align: left; }

        @media (max-width: 900px) {
            .masa-kpi-grid { grid-template-columns: repeat(2, 1fr); }
        }
        </style>
        <?php
    }
}
