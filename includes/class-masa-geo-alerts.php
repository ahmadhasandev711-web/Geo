<?php
defined( 'ABSPATH' ) || exit;

/**
 * Masa_Geo_Alerts
 * مراقبة أسعار الصرف — لو تغيرت أكتر من X% بيبعت إيميل تحذير
 */
final class Masa_Geo_Alerts {

    private static ?Masa_Geo_Alerts $instance = null;

    const PREV_RATES_KEY = 'masa_geo_prev_exchange_rates';
    const ALERT_LOG_KEY  = 'masa_geo_alert_log';

    public static function instance(): Masa_Geo_Alerts {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // قبل ما الـ API يحفظ الـ rates الجديدة → نعمل snapshot للقديمة
        add_action( 'masa_geo_before_save_rates', [ $this, 'snapshot_current_rates' ] );
        // بعد الحفظ → نقارن
        add_action( 'masa_geo_rates_updated', [ $this, 'check_rate_changes' ] );

        add_action( 'wp_ajax_masa_geo_test_alert', [ $this, 'ajax_test_alert' ] );
    }

    // ─────────────────────────────────────────────────────────
    // احفظ الـ rates الحالية كـ snapshot قبل الكتابة فوقيها
    // ─────────────────────────────────────────────────────────
    public function snapshot_current_rates( array $new_rates ): void {
        $current = get_option( Masa_Geo_Api::RATES_OPTION_KEY, [] );
        if ( ! empty( $current ) ) {
            update_option( self::PREV_RATES_KEY, $current, 'no' );
        }
    }

    // ─────────────────────────────────────────────────────────
    // تحقق من التغييرات بعد كل تحديث
    // ─────────────────────────────────────────────────────────
    public function check_rate_changes(): void {
        $settings  = get_option( MASA_GEO_OPTION_KEY, [] );
        $threshold = (float) ( $settings['alert_threshold'] ?? 5.0 );
        $enabled   = ! empty( $settings['alert_enabled'] );

        if ( ! $enabled ) return;

        // الـ rates الجديدة = ما اتحفظ للتو بواسطة الـ API
        $current_rates = get_option( Masa_Geo_Api::RATES_OPTION_KEY, [] );
        // الـ rates القديمة = الـ snapshot اللي حفظناه قبل آخر تحديث
        $prev_rates    = get_option( self::PREV_RATES_KEY, [] );

        if ( empty( $prev_rates ) ) {
            // أول مرة → احفظ الأسعار الحالية كـ baseline
            update_option( self::PREV_RATES_KEY, $current_rates, 'no' );
            return;
        }

        // جيب العملات المهمة من الـ Zones
        $watched = $this->get_watched_currencies();
        $alerts  = [];

        foreach ( $watched as $currency ) {
            $current = $current_rates[ $currency ] ?? 0;
            $prev    = $prev_rates[ $currency ]    ?? 0;

            if ( $prev <= 0 || $current <= 0 ) continue;

            $change_pct = ( ( $current - $prev ) / $prev ) * 100;

            if ( abs( $change_pct ) >= $threshold ) {
                $alerts[] = [
                    'currency'   => $currency,
                    'prev'       => $prev,
                    'current'    => $current,
                    'change_pct' => round( $change_pct, 2 ),
                    'direction'  => $change_pct > 0 ? 'ارتفع' : 'انخفض',
                ];
            }
        }

        if ( ! empty( $alerts ) ) {
            $this->send_alert_email( $alerts, $threshold );
            $this->log_alert( $alerts );
        }

        // احفظ الأسعار الحالية للمقارنة الجاية
        update_option( self::PREV_RATES_KEY, $current_rates, 'no' );
    }

    // ─────────────────────────────────────────────────────────
    // العملات اللي هنراقبها = عملات الـ Zones المعرفة
    // ─────────────────────────────────────────────────────────
    private function get_watched_currencies(): array {
        $settings   = get_option( MASA_GEO_OPTION_KEY, [] );
        $zones      = $settings['zones'] ?? [];
        $currencies = [];

        foreach ( $zones as $zone ) {
            $c = strtoupper( $zone['currency'] ?? '' );
            if ( $c && $c !== 'EGP' ) {
                $currencies[] = $c;
            }
        }

        return array_unique( $currencies );
    }

    // ─────────────────────────────────────────────────────────
    // إرسال إيميل التحذير
    // ─────────────────────────────────────────────────────────
    private function send_alert_email( array $alerts, float $threshold ): void {
        $settings  = get_option( MASA_GEO_OPTION_KEY, [] );
        $recipient = sanitize_email( $settings['alert_email'] ?? get_option( 'admin_email' ) );

        if ( ! is_email( $recipient ) ) return;

        $site_name = get_bloginfo( 'name' );
        $subject   = sprintf(
            '[%s] ⚠️ تحذير: تغيير في أسعار الصرف تجاوز %s%%',
            $site_name,
            $threshold
        );

        // بناء جسم الإيميل
        $rows = '';
        foreach ( $alerts as $alert ) {
            $color = $alert['change_pct'] > 0 ? '#dc2626' : '#16a34a';
            $arrow = $alert['change_pct'] > 0 ? '↑' : '↓';
            $rows .= sprintf(
                '<tr>
                    <td style="padding:8px 12px;border-bottom:1px solid #f0f0f0;font-weight:700;">%s</td>
                    <td style="padding:8px 12px;border-bottom:1px solid #f0f0f0;">%s</td>
                    <td style="padding:8px 12px;border-bottom:1px solid #f0f0f0;">%s</td>
                    <td style="padding:8px 12px;border-bottom:1px solid #f0f0f0;color:%s;font-weight:700;">%s %s%s%%</td>
                </tr>',
                esc_html( $alert['currency'] ),
                esc_html( number_format( $alert['prev'], 6 ) ),
                esc_html( number_format( $alert['current'], 6 ) ),
                $color,
                $arrow,
                $alert['direction'],
                esc_html( abs( $alert['change_pct'] ) )
            );
        }

        $body = '<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head><meta charset="UTF-8"></head>
<body style="font-family:Tahoma,sans-serif;direction:rtl;text-align:right;background:#f9fafb;margin:0;padding:20px;">
<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.08);">

    <div style="background:#ef4444;padding:20px 24px;">
        <h1 style="color:#fff;margin:0;font-size:18px;">⚠️ تحذير: تغيير كبير في أسعار الصرف</h1>
        <p style="color:#fecaca;margin:6px 0 0;font-size:13px;">
            تجاوزت نسبة التغيير حد التنبيه المحدد (' . $threshold . '%)
        </p>
    </div>

    <div style="padding:20px 24px;">
        <p style="color:#374151;">العملات التالية شهدت تغييراً ملحوظاً منذ آخر تحديث:</p>

        <table style="width:100%;border-collapse:collapse;font-size:13px;">
            <thead>
                <tr style="background:#f3f4f6;">
                    <th style="padding:8px 12px;text-align:right;color:#6b7280;">العملة</th>
                    <th style="padding:8px 12px;text-align:right;color:#6b7280;">السعر السابق</th>
                    <th style="padding:8px 12px;text-align:right;color:#6b7280;">السعر الحالي</th>
                    <th style="padding:8px 12px;text-align:right;color:#6b7280;">التغيير</th>
                </tr>
            </thead>
            <tbody>' . $rows . '</tbody>
        </table>

        <div style="margin-top:20px;padding:14px;background:#fef3c7;border-radius:6px;border-right:4px solid #f59e0b;">
            <strong style="color:#92400e;">توصية:</strong>
            <span style="color:#78350f;font-size:13px;">
                راجع أسعار المنتجات في المناطق المتأثرة وتأكد من أن الأسعار لا تزال مناسبة.
            </span>
        </div>

        <p style="margin-top:16px;">
            <a href="' . esc_url( admin_url( 'admin.php?page=masa-geo-pricing' ) ) . '"
               style="background:#2563eb;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none;font-size:13px;">
                فتح لوحة التحكم
            </a>
        </p>
    </div>

    <div style="background:#f9fafb;padding:12px 24px;border-top:1px solid #f0f0f0;">
        <p style="color:#9ca3af;font-size:11px;margin:0;">
            هذا البريد أُرسل تلقائياً من بلاجين Masa Geo-Pricing على موقع ' . esc_html( $site_name ) . '
            بتاريخ ' . wp_date( 'd/m/Y H:i' ) . '
        </p>
    </div>

</div>
</body>
</html>';

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $site_name . ' <' . get_option( 'admin_email' ) . '>',
        ];

        wp_mail( $recipient, $subject, $body, $headers );
    }

    // ─────────────────────────────────────────────────────────
    // سجل التنبيهات (آخر 20 تنبيه)
    // ─────────────────────────────────────────────────────────
    private function log_alert( array $alerts ): void {
        $log = get_option( self::ALERT_LOG_KEY, [] );

        $log[] = [
            'time'   => time(),
            'alerts' => $alerts,
        ];

        // احتفظ بآخر 20 تنبيه بس
        if ( count( $log ) > 20 ) {
            $log = array_slice( $log, -20 );
        }

        update_option( self::ALERT_LOG_KEY, $log, 'no' );
    }

    // ─────────────────────────────────────────────────────────
    // AJAX: إرسال تنبيه تجريبي
    // ─────────────────────────────────────────────────────────
    public function ajax_test_alert(): void {
        check_ajax_referer( 'masa_geo_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'غير مصرح.' ], 403 );
        }

        $test_alerts = [
            [
                'currency'   => 'USD',
                'prev'       => 0.0200,
                'current'    => 0.0215,
                'change_pct' => 7.5,
                'direction'  => 'ارتفع',
            ],
            [
                'currency'   => 'EUR',
                'prev'       => 0.0188,
                'current'    => 0.0175,
                'change_pct' => -6.9,
                'direction'  => 'انخفض',
            ],
        ];

        $settings  = get_option( MASA_GEO_OPTION_KEY, [] );
        $threshold = (float) ( $settings['alert_threshold'] ?? 5.0 );

        $this->send_alert_email( $test_alerts, $threshold );

        wp_send_json_success( [ 'message' => 'تم إرسال إيميل تجريبي بنجاح ✓' ] );
    }

    // ─────────────────────────────────────────────────────────
    // جيب سجل التنبيهات للـ Admin
    // ─────────────────────────────────────────────────────────
    public static function get_log(): array {
        $log = get_option( self::ALERT_LOG_KEY, [] );
        return array_reverse( $log ); // الأحدث أول
    }
}
