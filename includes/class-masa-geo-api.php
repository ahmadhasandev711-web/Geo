<?php
defined( 'ABSPATH' ) || exit;

/**
 * Masa_Geo_Api
 * مسؤول عن: جلب أسعار الصرف الحية + WP Cron يومي
 */
final class Masa_Geo_Api {

    private static ?Masa_Geo_Api $instance = null;

    const RATES_OPTION_KEY  = 'masa_geo_exchange_rates';
    const RATES_LAST_UPDATE = 'masa_geo_rates_last_update';

    public static function instance(): Masa_Geo_Api {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'masa_geo_fetch_rates_event', [ $this, 'fetch_and_store_rates' ] );
        add_action( 'plugins_loaded', [ $this, 'maybe_fetch_on_load' ], 20 );
    }

    public function maybe_fetch_on_load(): void {
        // اشتغل بس في الـ Admin عشان متعملش HTTP request على كل page load للـ frontend
        if ( ! is_admin() ) return;

        if ( get_transient( 'masa_geo_rates_fetch_lock' ) ) {
            return;
        }

        $rates = get_option( self::RATES_OPTION_KEY, [] );
        if ( empty( $rates ) ) {
            set_transient( 'masa_geo_rates_fetch_lock', 1, 5 * MINUTE_IN_SECONDS );
            $this->fetch_and_store_rates();
        }
    }

    public function fetch_and_store_rates(): bool {
        $settings = get_option( MASA_GEO_OPTION_KEY, [] );
        $provider = $settings['api_provider'] ?? 'open_er_api';
        $api_key  = sanitize_text_field( $settings['api_key'] ?? '' );

        $rates = [];

        switch ( $provider ) {
            case 'exchangerate_api':
                $rates = $this->fetch_from_exchangerate_api( $api_key );
                break;

            case 'open_er_api':
            default:
                $rates = $this->fetch_from_open_er_api();
                break;
        }

        // v4.0.0: Fallback to open.er-api if exchangerate_api fails
        if ( empty( $rates ) && $provider === 'exchangerate_api' ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[Masa Geo Pricing] API الأساسي فشل، محاولة استخدام الاحتياطي open.er-api.com...' );
            }
            $rates = $this->fetch_from_open_er_api();
        }

        if ( empty( $rates ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[Masa Geo Pricing] فشل جلب أسعار الصرف.' );
            }
            return false;
        }

        // خلّي الـ Alerts تعمل snapshot للـ rates الحالية قبل ما نكتب فوقيها
        do_action( 'masa_geo_before_save_rates', $rates );

        update_option( self::RATES_OPTION_KEY, $rates, 'no' );
        update_option( self::RATES_LAST_UPDATE, time(), 'no' );

        // ─── Cache Invalidation ───────────────────────────────
        // امسح WooCommerce product transients عشان الأسعار القديمة
        wc_delete_product_transients();

        // امسح أي session-based price cache
        if ( function_exists( 'WC' ) && WC()->session ) {
            WC()->session->set( 'masa_geo_cached_prices', null );
        }

        // امسح الـ request-level cache في Core
        do_action( 'masa_geo_rates_updated' );

        // امسح الـ rates static cache
        self::flush_rates_cache();

        // سجّل في Activity Log
        self::log_rate_update( $rates );
        Masa_Geo_Activity_Log::log( Masa_Geo_Activity_Log::EVENT_RATES_UPDATED, [
            'triggered_by' => 'cron',
            'currencies'   => count( $rates ),
        ] );

        return true;
    }

    private function fetch_from_open_er_api(): array {
        $url      = 'https://open.er-api.com/v6/latest/EGP';
        $response = wp_remote_get( $url, [
            'timeout'    => 10,
            'user-agent' => 'MasaGeoPricing/' . MASA_GEO_VERSION,
        ] );

        if ( is_wp_error( $response ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[Masa Geo Pricing] open.er-api.com error: ' . $response->get_error_message() );
            }
            return [];
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== (int) $code ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[Masa Geo Pricing] open.er-api.com HTTP ' . $code );
            }
            return [];
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if (
            empty( $data['result'] ) ||
            $data['result'] !== 'success' ||
            empty( $data['rates'] )
        ) {
            return [];
        }

        return array_map( 'floatval', $data['rates'] );
    }

    private function fetch_from_exchangerate_api( string $api_key ): array {
        if ( empty( $api_key ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[Masa Geo Pricing] exchangerate-api.com يحتاج API key.' );
            }
            return [];
        }

        $url      = esc_url_raw( "https://v6.exchangerate-api.com/v6/{$api_key}/latest/EGP" );
        $response = wp_remote_get( $url, [
            'timeout'    => 10,
            'user-agent' => 'MasaGeoPricing/' . MASA_GEO_VERSION,
        ] );

        if ( is_wp_error( $response ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[Masa Geo Pricing] exchangerate-api.com error: ' . $response->get_error_message() );
            }
            return [];
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if (
            empty( $data['result'] ) ||
            $data['result'] !== 'success' ||
            empty( $data['conversion_rates'] )
        ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[Masa Geo Pricing] exchangerate-api.com response error.' );
            }
            return [];
        }

        return array_map( 'floatval', $data['conversion_rates'] );
    }

    private static ?array $rates_cache = null; // request-level cache للـ exchange rates

    public static function get_rate( string $currency_code ): ?float {
        $currency_code = strtoupper( sanitize_text_field( $currency_code ) );

        if ( $currency_code === 'EGP' ) {
            return 1.0;
        }

        // 1. Check if there's a manual rate for this currency in the zones
        $settings = get_option( MASA_GEO_OPTION_KEY, [] );
        $zones    = $settings['zones'] ?? [];
        $manual_fallback = 0.0;

        foreach ( $zones as $zone ) {
            if ( strtoupper( $zone['currency'] ?? '' ) === $currency_code ) {
                $manual_rate = (float) ( $zone['manual_rate'] ?? 0.0 );
                if ( $manual_rate > 0 ) {
                    // rate stored is "1 Currency = X EGP". 
                    // get_rate() should return "1 EGP = Y Currency" -> Y = 1 / X
                    $computed_rate = 1.0 / $manual_rate;
                    
                    if ( isset( $zone['rate_mode'] ) && 'manual' === $zone['rate_mode'] ) {
                        return $computed_rate; // Forced manual rate
                    }
                    // Otherwise, save it as a fallback
                    $manual_fallback = $computed_rate;
                }
            }
        }

        // Cache في الـ request — متعملش get_option على كل منتج
        if ( self::$rates_cache === null ) {
            self::$rates_cache = get_option( self::RATES_OPTION_KEY, [] ) ?: [];
        }

        if ( ! empty( self::$rates_cache[ $currency_code ] ) ) {
            return (float) self::$rates_cache[ $currency_code ];
        }

        // 3. Fallback to manual fallback if API failed
        if ( $manual_fallback > 0 ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( "[Masa Geo Pricing] تحذير: استخدام السعر الاحتياطي اليدوي للعملة {$currency_code}" );
            }
            return $manual_fallback;
        }

        // 4. v4.0.0: محاولة الرجوع لآخر rate معروف من التاريخ
        $fallback_rate = self::get_last_known_rate( $currency_code );
        if ( $fallback_rate > 0 ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( "[Masa Geo Pricing] تحذير: استخدام آخر rate معروف للعملة {$currency_code}: {$fallback_rate}" );
            }
            return $fallback_rate;
        }

        // 5. Total failure
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( "[Masa Geo Pricing] خطأ: مفيش سعر صرف للعملة {$currency_code} — لا في الذاكرة ولا في التاريخ ولا احتياطي يدوي" );
        }
        return null;
    }

    public static function flush_rates_cache(): void {
        self::$rates_cache = null;
    }

    public static function get_last_update(): string {
        $timestamp = get_option( self::RATES_LAST_UPDATE, 0 );
        if ( ! $timestamp ) {
            return __( 'لم يتم التحديث بعد', 'masa-geo-pricing' );
        }
        return wp_date( 'd/m/Y H:i', $timestamp );
    }

    public static function manual_refresh(): bool {
        return self::instance()->fetch_and_store_rates();
    }

    // ─────────────────────────────────────────────────────────
    // Exchange Rate History (آخر 30 يوم)
    // ─────────────────────────────────────────────────────────
    const RATES_HISTORY_KEY = 'masa_geo_rates_history';

    /**
     * محاولة جلب آخر rate معروف لعملة من سجل التاريخ.
     * بترجع آخر snapshot تحتوي على العملة المطلوبة.
     *
     * @return float الـ rate أو 0.0 لو مفيش تاريخ.
     */
    private static function get_last_known_rate( string $currency_code ): float {
        $currency_code = strtoupper( $currency_code );
        $history       = get_option( self::RATES_HISTORY_KEY, [] );

        if ( empty( $history ) || ! is_array( $history ) ) {
            return 0.0;
        }

        // ابحث من الأحدث للأقدم
        krsort( $history );

        foreach ( $history as $snapshot ) {
            if ( isset( $snapshot['rates'][ $currency_code ] ) && (float) $snapshot['rates'][ $currency_code ] > 0 ) {
                return (float) $snapshot['rates'][ $currency_code ];
            }
        }

        return 0.0;
    }

    private static function log_rate_update( array $new_rates ): void {
        $history = get_option( self::RATES_HISTORY_KEY, [] );

        // احفظ بس العملات المهمة (عملات الـ Zones) — مش كل الـ 500+ عملة
        $settings         = get_option( MASA_GEO_OPTION_KEY, [] );
        $zones            = $settings['zones'] ?? [];
        $watched_currencies = array_unique( array_filter( array_column( $zones, 'currency' ) ) );

        $snapshot_rates = [];
        foreach ( $watched_currencies as $cur ) {
            $cur = strtoupper( $cur );
            if ( isset( $new_rates[ $cur ] ) ) {
                $snapshot_rates[ $cur ] = $new_rates[ $cur ];
            }
        }
        // دايماً احفظ USD و EUR كمرجع
        foreach ( [ 'USD', 'EUR', 'GBP' ] as $ref ) {
            if ( isset( $new_rates[ $ref ] ) ) {
                $snapshot_rates[ $ref ] = $new_rates[ $ref ];
            }
        }

        $today             = current_time( 'Y-m-d' );
        $history[ $today ] = [
            'timestamp' => time(),
            'rates'     => $snapshot_rates,
        ];

        // احتفظ بآخر 30 يوم بس
        if ( count( $history ) > 30 ) {
            ksort( $history );
            $history = array_slice( $history, -30, 30, true );
        }

        update_option( self::RATES_HISTORY_KEY, $history, 'no' );
    }

    /**
     * جيب تاريخ سعر عملة معينة على آخر N يوم
     * بيرجع: [ ['date' => 'YYYY-MM-DD', 'rate' => float], ... ]
     */
    public static function get_rate_history( string $currency, int $days = 30 ): array {
        $currency = strtoupper( $currency );
        $history  = get_option( self::RATES_HISTORY_KEY, [] );

        if ( empty( $history ) ) {
            return [];
        }

        ksort( $history );
        $result = [];

        foreach ( $history as $date => $snapshot ) {
            if ( isset( $snapshot['rates'][ $currency ] ) ) {
                $result[] = [
                    'date' => $date,
                    'rate' => (float) $snapshot['rates'][ $currency ],
                ];
            }
        }

        return array_slice( $result, -$days );
    }
}
