<?php
defined( 'ABSPATH' ) || exit;

/**
 * Masa_Geo_Admin
 * صفحة الإعدادات العامة + إدارة Zones التسعير
 */
final class Masa_Geo_Admin {

    private static ?Masa_Geo_Admin $instance = null;

    public static function instance(): Masa_Geo_Admin {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu',            [ $this, 'register_menu' ] );
        add_action( 'admin_init',            [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_masa_geo_refresh_rates', [ $this, 'ajax_refresh_rates' ] );
        add_action( 'wp_ajax_masa_geo_save_zones',    [ $this, 'ajax_save_zones' ] );
        add_action( 'wp_ajax_masa_geo_update_geoip',  [ $this, 'ajax_update_geoip' ] );
    }

    public function register_menu(): void {
        add_submenu_page(
            'woocommerce',
            __( 'Masa Geo-Pricing', 'masa-geo-pricing' ),
            __( 'Masa Geo-Pricing', 'masa-geo-pricing' ),
            'manage_woocommerce',
            'masa-geo-pricing',
            [ $this, 'render_page' ]
        );
    }

    public function register_settings(): void {
        register_setting(
            'masa_geo_settings_group',
            MASA_GEO_OPTION_KEY,
            [ $this, 'sanitize_settings' ]
        );
        register_setting(
            'masa_geo_settings_group',
            'masa_geo_shipping_rates',
            [ $this, 'sanitize_shipping_rates' ]
        );
        register_setting(
            'masa_geo_settings_group',
            'masa_geo_shipping_cities',
            [ $this, 'sanitize_shipping_cities' ]
        );
    }

    public function sanitize_shipping_rates( $input ): array {
        if ( ! is_array( $input ) ) {
            return [];
        }
        $clean = [];
        foreach ( $input as $zone_id => $cost ) {
            $zone_id = sanitize_key( $zone_id );
            $cost    = trim( (string) $cost );
            if ( '' === $cost ) continue;
            $clean[ $zone_id ] = max( 0.0, (float) $cost );
        }
        return $clean;
    }

    public function sanitize_shipping_cities( $input ): array {
        if ( ! is_array( $input ) ) {
            return [];
        }
        $clean = [];
        foreach ( $input as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $country = strtoupper( trim( sanitize_text_field( $item['country'] ?? '' ) ) );
            $city    = trim( sanitize_text_field( $item['city'] ?? '' ) );
            $cost    = trim( (string) ( $item['cost'] ?? '' ) );

            if ( empty( $country ) || empty( $city ) || '' === $cost ) {
                continue;
            }

            $clean[] = [
                'country' => $country,
                'city'    => $city,
                'cost'    => max( 0.0, (float) $cost ),
            ];
        }
        return $clean;
    }

    public function sanitize_settings( array $input ): array {
        $clean = [];

        if ( isset( $input['maxmind_license_key'] ) ) {
            $new_key = sanitize_text_field( trim( $input['maxmind_license_key'] ) );
            $wc_maxmind_settings = get_option( 'woocommerce_maxmind_geolocation_settings', [] );
            $wc_maxmind_settings['license_key'] = $new_key;
            update_option( 'woocommerce_maxmind_geolocation_settings', $wc_maxmind_settings );
        }

        $clean['api_provider'] = in_array( $input['api_provider'] ?? '', [ 'open_er_api', 'exchangerate_api' ], true )
            ? $input['api_provider']
            : 'open_er_api';

        $clean['api_key'] = sanitize_text_field( $input['api_key'] ?? '' );

        // Alerts
        $clean['alert_enabled']   = ! empty( $input['alert_enabled'] ) ? 1 : 0;
        $clean['alert_email']     = sanitize_email( $input['alert_email'] ?? get_option( 'admin_email' ) );
        $clean['alert_threshold'] = max( 1.0, (float) ( $input['alert_threshold'] ?? 5.0 ) );

        // Switcher
        $clean['switcher_enabled']  = ! empty( $input['switcher_enabled'] ) ? 1 : 0;
        $clean['switcher_floating'] = ! empty( $input['switcher_floating'] ) ? 1 : 0;

        // Shipping
        $clean['shipping_enabled'] = ! empty( $input['shipping_enabled'] ) ? 1 : 0;

        // Visibility — رسالة "غير متاح في بلدك"
        $clean['visibility_message'] = sanitize_text_field( $input['visibility_message'] ?? '' );

        // WhatsApp settings
        $clean['whatsapp_number']            = sanitize_text_field( $input['whatsapp_number'] ?? '' );
        $clean['whatsapp_btn_label']         = sanitize_text_field( $input['whatsapp_btn_label'] ?? '' );
        $clean['whatsapp_message_template']  = sanitize_textarea_field( $input['whatsapp_message_template'] ?? '' );

        // ─── Geolocation (v3.4.0) ────────────────────────────
        $clean['geo_proxy_mode'] = in_array( $input['geo_proxy_mode'] ?? '', [ 'auto', 'remote_addr', 'custom' ], true )
            ? $input['geo_proxy_mode']
            : 'auto';

        // قائمة الـ Trusted Proxies: سطر لكل IP أو CIDR — أي قيمة بايظة بتتشال بصمت
        $clean['trusted_proxies'] = [];
        $raw_proxies = (string) ( $input['trusted_proxies'] ?? '' );
        foreach ( preg_split( '/[\r\n,]+/', $raw_proxies ) as $entry ) {
            $entry = trim( sanitize_text_field( $entry ) );
            if ( '' === $entry ) continue;
            $test_ip = strpos( $entry, '/' ) !== false ? explode( '/', $entry, 2 )[0] : $entry;
            if ( filter_var( $test_ip, FILTER_VALIDATE_IP ) ) {
                $clean['trusted_proxies'][] = $entry;
            }
        }

        $default_country = strtoupper( sanitize_text_field( $input['default_country'] ?? 'EG' ) );
        $clean['default_country'] = Masa_Geo_Helper::is_valid_country_code( $default_country ) ? $default_country : 'EG';

        // v3.5.0: الاحتفاظ بالبيانات عند حذف البلجن
        $clean['keep_data_on_uninstall'] = ! empty( $input['keep_data_on_uninstall'] ) ? 1 : 0;

        // v3.7.0: المخزون الجغرافي — الشارة والتنبيهات
        $clean['zone_stock_badge']           = ! empty( $input['zone_stock_badge'] ) ? 1 : 0;
        $clean['zone_stock_badge_threshold'] = max( 1, min( 100, (int) ( $input['zone_stock_badge_threshold'] ?? 5 ) ) );
        $clean['zone_stock_email_alerts']    = ! empty( $input['zone_stock_email_alerts'] ) ? 1 : 0;

        // v3.8.0: شارات ومميزات إضافية
        $clean['currency_badge_enabled']     = ! empty( $input['currency_badge_enabled'] ) ? 1 : 0;
        $clean['free_shipping_bar_enabled']  = ! empty( $input['free_shipping_bar_enabled'] ) ? 1 : 0;

        // الاحتفاظ بالمناطق الحالية إذا لم تكن مرسلة في الفورم (لأنها تُحفظ عبر AJAX بشكل منفصل)
        $existing_settings = get_option( MASA_GEO_OPTION_KEY, [] );
        $clean['zones'] = isset( $input['zones'] )
            ? $this->sanitize_zones( $input['zones'] )
            : ( $existing_settings['zones'] ?? [] );

        return $clean;
    }

    private function sanitize_zones( $raw ): array {
        if ( ! is_array( $raw ) ) {
            return [];
        }

        $zones = [];
        foreach ( $raw as $zone ) {
            if ( empty( $zone['id'] ) || empty( $zone['label'] ) ) {
                continue;
            }

            $countries = [];
            if ( ! empty( $zone['countries'] ) ) {
                foreach ( (array) $zone['countries'] as $c ) {
                    $c = sanitize_text_field( $c );
                    if ( $c === '__FALLBACK__' || Masa_Geo_Helper::is_valid_country_code( $c ) ) {
                        $countries[] = strtoupper( $c );
                    }
                }
            }

            $zones[] = [
                'id'            => sanitize_key( $zone['id'] ),
                'label'         => sanitize_text_field( $zone['label'] ),
                'countries'     => $countries,
                'multiplier'    => max( 0.01, (float) ( $zone['multiplier'] ?? 1.0 ) ),
                'currency'      => strtoupper( sanitize_text_field( $zone['currency'] ?? 'EGP' ) ),
                'minimum_order' => max( 0.0, (float) ( $zone['minimum_order'] ?? 0.0 ) ),
                'rate_mode'     => isset( $zone['rate_mode'] ) && 'manual' === $zone['rate_mode'] ? 'manual' : 'api',
                'manual_rate'   => max( 0.0, (float) ( $zone['manual_rate'] ?? 0.0 ) ),
                'cod_enabled'   => ! isset( $zone['cod_enabled'] ) || ! empty( $zone['cod_enabled'] ) ? 1 : 0,
            ];
        }

        return $zones;
    }

    public function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, 'masa-geo-pricing' ) === false &&
             ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
            return;
        }

        wp_enqueue_style(
            'masa-geo-admin',
            MASA_GEO_PLUGIN_URL . 'admin/assets/admin.css',
            [],
            MASA_GEO_VERSION
        );

        wp_enqueue_script(
            'masa-geo-admin',
            MASA_GEO_PLUGIN_URL . 'admin/assets/admin.js',
            [ 'jquery', 'wp-util' ],
            MASA_GEO_VERSION,
            true
        );

        wp_localize_script( 'masa-geo-admin', 'MasaGeo', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'masa_geo_admin_nonce' ),
            'i18n'     => [
                'confirm_delete'  => __( 'هل أنت متأكد من حذف هذه المنطقة؟', 'masa-geo-pricing' ),
                'refreshing'      => __( 'جاري تحديث الأسعار...', 'masa-geo-pricing' ),
                'refresh_success' => __( 'تم تحديث أسعار الصرف بنجاح ✓', 'masa-geo-pricing' ),
                'refresh_fail'    => __( 'فشل التحديث، راجع السجلات.', 'masa-geo-pricing' ),
                'saving'          => __( 'جاري الحفظ...', 'masa-geo-pricing' ),
                'save_success'    => __( 'تم حفظ المناطق بنجاح ✓', 'masa-geo-pricing' ),
                'save_fail'       => __( 'فشل الحفظ.', 'masa-geo-pricing' ),
            ],
        ] );
    }

    public function ajax_refresh_rates(): void {
        check_ajax_referer( 'masa_geo_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'غير مصرح.', 'masa-geo-pricing' ) ], 403 );
        }

        $success = Masa_Geo_Api::manual_refresh();

        if ( $success ) {
            Masa_Geo_Activity_Log::log( Masa_Geo_Activity_Log::EVENT_RATES_UPDATED, [
                'triggered_by' => 'manual',
                'last_update'  => Masa_Geo_Api::get_last_update(),
            ] );
            wp_send_json_success( [
                'message'     => __( 'تم تحديث أسعار الصرف بنجاح.', 'masa-geo-pricing' ),
                'last_update' => Masa_Geo_Api::get_last_update(),
            ] );
        } else {
            Masa_Geo_Activity_Log::log( Masa_Geo_Activity_Log::EVENT_RATES_FAILED, [
                'triggered_by' => 'manual',
            ] );
            wp_send_json_error( [ 'message' => __( 'فشل جلب الأسعار. راجع السجلات.', 'masa-geo-pricing' ) ] );
        }
    }

    public function ajax_save_zones(): void {
        check_ajax_referer( 'masa_geo_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'غير مصرح.', 'masa-geo-pricing' ) ], 403 );
        }

        $raw_zones = isset( $_POST['zones'] ) ? wp_unslash( $_POST['zones'] ) : [];

        if ( is_string( $raw_zones ) ) {
            $raw_zones = json_decode( $raw_zones, true ) ?: [];
        }

        $clean_zones = $this->sanitize_zones( (array) $raw_zones );

        $settings          = get_option( MASA_GEO_OPTION_KEY, [] );
        $settings['zones'] = $clean_zones;
        update_option( MASA_GEO_OPTION_KEY, $settings, 'no' );

        // امسح الـ zone cache عشان التغييرات تأثر فوراً
        Masa_Geo_Helper::flush_zone_cache();

        Masa_Geo_Activity_Log::log( Masa_Geo_Activity_Log::EVENT_ZONE_SAVED, [
            'zones_count' => count( $clean_zones ),
            'zone_ids'    => array_column( $clean_zones, 'id' ),
        ] );

        wp_send_json_success( [ 'message' => __( 'تم حفظ المناطق بنجاح.', 'masa-geo-pricing' ) ] );
    }

    public function ajax_update_geoip(): void {
        check_ajax_referer( 'masa_geo_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'غير مصرح.', 'masa-geo-pricing' ) ], 403 );
        }

        if ( ! class_exists( 'WC_Geolocation' ) ) {
            wp_send_json_error( [ 'message' => __( 'الـ Geolocation غير مدعوم في هذا الإصدار من ووكومرس.', 'masa-geo-pricing' ) ] );
        }

        // تشغيل تحديث قاعدة البيانات (دعم لـ WooCommerce 3.9+)
        try {
            if ( class_exists( 'WC_Integration_MaxMind_Geolocation' ) ) {
                $maxmind = new \WC_Integration_MaxMind_Geolocation();
                if ( method_exists( $maxmind, 'update_database' ) ) {
                    $maxmind->update_database();
                } else {
                    \WC_Geolocation::update_database();
                }
            } else {
                \WC_Geolocation::update_database();
            }
        } catch ( \Throwable $e ) {
            wp_send_json_error( [ 'message' => 'خطأ برمجي أثناء التحديث: ' . $e->getMessage() ] );
        }

        // فحص النتيجة
        $db_path = \WC_Geolocation::get_local_database_path();
        if ( ! empty( $db_path ) && file_exists( $db_path ) ) {
            $db_time = filemtime( $db_path );
            $message = sprintf(
                __( '✅ تم تحديث قاعدة البيانات بنجاح! آخر تحديث: %s', 'masa-geo-pricing' ),
                wp_date( 'Y-m-d H:i', $db_time )
            );
            wp_send_json_success( [ 'message' => $message ] );
        } else {
            wp_send_json_error( [ 'message' => __( '❌ فشل تحميل قاعدة البيانات. تأكد من صحة مفتاح الترخيص ومن قدرة السيرفر على الاتصال بـ MaxMind.', 'masa-geo-pricing' ) ] );
        }
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'غير مصرح لك بالوصول.', 'masa-geo-pricing' ) );
        }

        $settings     = get_option( MASA_GEO_OPTION_KEY, [] );
        $zones        = $settings['zones']        ?? [];
        $api_key      = $settings['api_key']      ?? '';
        $api_provider = $settings['api_provider'] ?? 'open_er_api';
        $last_update  = Masa_Geo_Api::get_last_update();
        ?>
        <div class="wrap masa-geo-wrap" dir="rtl">
            <h1 class="masa-geo-title">🌍 <?php esc_html_e( 'Masa Geo-Pricing & Currency Manager', 'masa-geo-pricing' ); ?></h1>

            <?php
            $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings';
            ?>
            <h2 class="nav-tab-wrapper" style="margin-bottom: 20px;">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=masa-geo-pricing&tab=settings' ) ); ?>" class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    ⚙️ <?php esc_html_e( 'الإعدادات العامة والشحن', 'masa-geo-pricing' ); ?>
                </a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=masa-geo-pricing&tab=analytics' ) ); ?>" class="nav-tab <?php echo $active_tab === 'analytics' ? 'nav-tab-active' : ''; ?>">
                    📊 <?php esc_html_e( 'تقارير المبيعات الجغرافية', 'masa-geo-pricing' ); ?>
                </a>
            </h2>

            <div id="masa-geo-notice" class="masa-geo-notice" style="display:none;"></div>

            <?php if ( $active_tab === 'settings' ) : ?>
            <form method="post" action="options.php">
                <?php settings_fields( 'masa_geo_settings_group' ); ?>

                <div class="masa-geo-grid">

                    <div class="masa-geo-card">
                        <h2><?php esc_html_e( '⚙️ الإعدادات العامة', 'masa-geo-pricing' ); ?></h2>

                        <table class="form-table masa-geo-form-table">
                            <tr>
                                <th><?php esc_html_e( 'مزود أسعار الصرف', 'masa-geo-pricing' ); ?></th>
                                <td>
                                    <select name="<?php echo esc_attr( MASA_GEO_OPTION_KEY ); ?>[api_provider]" id="masa_api_provider">
                                        <option value="open_er_api" <?php selected( $api_provider, 'open_er_api' ); ?>>
                                            open.er-api.com <?php esc_html_e( '(مجاني - بدون API Key)', 'masa-geo-pricing' ); ?>
                                        </option>
                                        <option value="exchangerate_api" <?php selected( $api_provider, 'exchangerate_api' ); ?>>
                                            exchangerate-api.com <?php esc_html_e( '(يحتاج API Key)', 'masa-geo-pricing' ); ?>
                                        </option>
                                    </select>
                                </td>
                            </tr>
                            <tr id="masa_api_key_row" <?php echo $api_provider === 'open_er_api' ? 'style="display:none;"' : ''; ?>>
                                <th><?php esc_html_e( 'API Key', 'masa-geo-pricing' ); ?></th>
                                <td>
                                    <input type="text"
                                        name="<?php echo esc_attr( MASA_GEO_OPTION_KEY ); ?>[api_key]"
                                        value="<?php echo esc_attr( $api_key ); ?>"
                                        class="regular-text"
                                        placeholder="<?php esc_attr_e( 'ضع API Key هنا', 'masa-geo-pricing' ); ?>" />
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'آخر تحديث للأسعار', 'masa-geo-pricing' ); ?></th>
                                <td>
                                    <span id="masa-last-update"><?php echo esc_html( $last_update ); ?></span>
                                    &nbsp;
                                    <button type="button" id="masa-refresh-rates" class="button button-secondary">
                                        🔄 <?php esc_html_e( 'تحديث الآن', 'masa-geo-pricing' ); ?>
                                    </button>
                                </td>
                            </tr>
                            <tr>
                                <th colspan="2" style="padding-bottom:0;">
                                    <h3 style="margin:.5rem 0 0;">🛰️ <?php esc_html_e( 'تحديد الموقع الجغرافي', 'masa-geo-pricing' ); ?></h3>
                                </th>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'وضع الـ Proxy', 'masa-geo-pricing' ); ?></th>
                                <td>
                                    <?php $proxy_mode = $settings['geo_proxy_mode'] ?? 'auto'; ?>
                                    <select name="<?php echo esc_attr( MASA_GEO_OPTION_KEY ); ?>[geo_proxy_mode]" id="masa_geo_proxy_mode">
                                        <option value="auto" <?php selected( $proxy_mode, 'auto' ); ?>>
                                            <?php esc_html_e( 'تلقائي — Cloudflare لو موجود، وإلا IP الاتصال المباشر (موصى به)', 'masa-geo-pricing' ); ?>
                                        </option>
                                        <option value="remote_addr" <?php selected( $proxy_mode, 'remote_addr' ); ?>>
                                            <?php esc_html_e( 'صارم — IP الاتصال المباشر فقط (سيرفر بدون أي proxy)', 'masa-geo-pricing' ); ?>
                                        </option>
                                        <option value="custom" <?php selected( $proxy_mode, 'custom' ); ?>>
                                            <?php esc_html_e( 'مخصص — صدّق الـ headers فقط من Proxies محددة', 'masa-geo-pricing' ); ?>
                                        </option>
                                    </select>
                                    <p class="description">
                                        <?php esc_html_e( 'يتحكم في مدى الثقة بترويسات مثل X-Forwarded-For. الإعداد الخاطئ قد يسمح بتزوير البلد للحصول على أسعار أرخص.', 'masa-geo-pricing' ); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr id="masa_trusted_proxies_row" <?php echo 'custom' !== $proxy_mode ? 'style="display:none;"' : ''; ?>>
                                <th><?php esc_html_e( 'Proxies موثوقة', 'masa-geo-pricing' ); ?></th>
                                <td>
                                    <textarea name="<?php echo esc_attr( MASA_GEO_OPTION_KEY ); ?>[trusted_proxies]"
                                        rows="4" class="large-text code"
                                        placeholder="173.245.48.0/20&#10;10.0.0.5"><?php
                                        echo esc_textarea( implode( "\n", (array) ( $settings['trusted_proxies'] ?? [] ) ) );
                                    ?></textarea>
                                    <p class="description"><?php esc_html_e( 'سطر لكل IP أو نطاق CIDR. تُقبل الترويسات فقط عندما يأتي الطلب من أحد هذه العناوين.', 'masa-geo-pricing' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'البلد الافتراضي', 'masa-geo-pricing' ); ?></th>
                                <td>
                                    <input type="text" maxlength="2"
                                        name="<?php echo esc_attr( MASA_GEO_OPTION_KEY ); ?>[default_country]"
                                        value="<?php echo esc_attr( $settings['default_country'] ?? 'EG' ); ?>"
                                        style="width:70px;text-transform:uppercase;" />
                                    <p class="description"><?php esc_html_e( 'كود ISO من حرفين (مثل EG). يُستخدم فقط عند فشل كل طرق التحديد.', 'masa-geo-pricing' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'مفتاح ترخيص MaxMind Geolocation', 'masa-geo-pricing' ); ?></th>
                                <td>
                                    <?php
                                    $wc_maxmind_settings = get_option( 'woocommerce_maxmind_geolocation_settings', [] );
                                    $maxmind_key = $wc_maxmind_settings['license_key'] ?? '';
                                    
                                    $db_status_desc = '';
                                    if ( class_exists( 'WC_Geolocation' ) && method_exists( 'WC_Geolocation', 'get_local_database_path' ) ) {
                                        $db_path = WC_Geolocation::get_local_database_path();
                                        if ( ! empty( $db_path ) && file_exists( $db_path ) ) {
                                            $db_time = filemtime( $db_path );
                                            $db_status_desc = sprintf(
                                                __( '✅ قاعدة البيانات محملة ونشطة. آخر تحديث: %s', 'masa-geo-pricing' ),
                                                wp_date( 'Y-m-d H:i', $db_time )
                                            );
                                        } else {
                                            $db_status_desc = __( '❌ قاعدة البيانات غير محملة حالياً (يرجى حفظ مفتاح الترخيص والضغط على تحديث الآن).', 'masa-geo-pricing' );
                                        }
                                    }
                                    ?>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <input type="text"
                                            name="<?php echo esc_attr( MASA_GEO_OPTION_KEY ); ?>[maxmind_license_key]"
                                            id="masa_maxmind_license_key"
                                            value="<?php echo esc_attr( $maxmind_key ); ?>"
                                            class="regular-text"
                                            placeholder="<?php esc_attr_e( 'أدخل مفتاح ترخيص MaxMind هنا', 'masa-geo-pricing' ); ?>" />
                                        
                                        <button type="button" id="masa-update-geoip" class="button button-secondary">
                                            🔄 <?php esc_html_e( 'تحديث الآن', 'masa-geo-pricing' ); ?>
                                        </button>
                                    </div>
                                    <p class="description" style="margin-top: 5px;">
                                        <?php esc_html_e( 'كود ترخيص مجاني لتحديث قاعدة بيانات تحديد المواقع الجغرافية تلقائياً كل أسبوع.', 'masa-geo-pricing' ); ?>
                                        <a href="https://www.maxmind.com/en/geolite2/signup" target="_blank" style="font-weight: 600;">
                                            <?php esc_html_e( 'احصل على مفتاح ترخيص مجاني من هنا ↗', 'masa-geo-pricing' ); ?>
                                        </a>
                                    </p>
                                    <p class="description" id="masa-geoip-status" style="font-weight: 600; color: #16a34a; margin-top: 5px;">
                                        <?php echo esc_html( $db_status_desc ); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'اختبار التحديد', 'masa-geo-pricing' ); ?></th>
                                <td>
                                    <?php
                                    $diag         = Masa_Geo_Helper::detect_country_no_cookie();
                                    $diag_zone    = Masa_Geo_Helper::get_zone_for_country( $diag['country'] );
                                    $source_label = [
                                        'cloudflare'              => __( 'Cloudflare Header', 'masa-geo-pricing' ),
                                        'woocommerce_geolocation' => __( 'WooCommerce Geolocation (MaxMind)', 'masa-geo-pricing' ),
                                        'default'                 => __( '⚠️ البلد الافتراضي — فشل التحديد الفعلي', 'masa-geo-pricing' ),
                                    ][ $diag['source'] ] ?? $diag['source'];
                                    ?>
                                    <code><?php echo esc_html( $diag['country'] ); ?></code>
                                    — <?php echo esc_html( $source_label ); ?>
                                    <?php if ( $diag_zone ) : ?>
                                        | Zone: <strong><?php echo esc_html( $diag_zone['label'] ); ?></strong>
                                    <?php endif; ?>
                                    <br><small style="color:#6b7280;">
                                        IP: <code><?php echo esc_html( Masa_Geo_Helper::get_real_ip() ); ?></code>
                                        — <?php esc_html_e( 'نتيجة طلبك أنت الحالي (بتجاهل الكوكي). لو ظهر "البلد الافتراضي" راجع وضع الـ Proxy أو فعّل MaxMind في إعدادات WooCommerce.', 'masa-geo-pricing' ); ?>
                                    </small>
                                </td>
                            </tr>
                            <tr>
                                <th colspan="2" style="padding-bottom:0;">
                                    <h3 style="margin:.5rem 0 0;">📦 <?php esc_html_e( 'المخزون الجغرافي', 'masa-geo-pricing' ); ?></h3>
                                </th>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'شارة "باقي N فقط"', 'masa-geo-pricing' ); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox"
                                            name="<?php echo esc_attr( MASA_GEO_OPTION_KEY ); ?>[zone_stock_badge]"
                                            value="1"
                                            <?php checked( ! empty( $settings['zone_stock_badge'] ) ); ?> />
                                        <?php esc_html_e( 'اعرض "🔥 باقي N فقط في منطقتك!" على صفحة المنتج عندما تقل الكمية', 'masa-geo-pricing' ); ?>
                                    </label>
                                    &nbsp;&nbsp;
                                    <label>
                                        <?php esc_html_e( 'عند', 'masa-geo-pricing' ); ?>
                                        <input type="number" min="1" max="100" step="1"
                                            name="<?php echo esc_attr( MASA_GEO_OPTION_KEY ); ?>[zone_stock_badge_threshold]"
                                            value="<?php echo esc_attr( $settings['zone_stock_badge_threshold'] ?? 5 ); ?>"
                                            style="width:70px;" />
                                        <?php esc_html_e( 'قطعة أو أقل', 'masa-geo-pricing' ); ?>
                                    </label>
                                    <p class="description"><?php esc_html_e( 'يظهر فقط للمنتجات ذات مخزون مخصص للمنطقة — يرفع معدل التحويل بخلق إحساس الندرة.', 'masa-geo-pricing' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'تنبيهات النفاد', 'masa-geo-pricing' ); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox"
                                            name="<?php echo esc_attr( MASA_GEO_OPTION_KEY ); ?>[zone_stock_email_alerts]"
                                            value="1"
                                            <?php checked( ! empty( $settings['zone_stock_email_alerts'] ?? 1 ) ); ?> />
                                        <?php esc_html_e( 'أرسل بريداً للمدير عند نفاد مخزون منطقة لأي منتج', 'masa-geo-pricing' ); ?>
                                    </label>
                                    <p class="description"><?php esc_html_e( 'بريد واحد كحد أقصى كل 12 ساعة لكل منتج/منطقة لتجنب الإزعاج.', 'masa-geo-pricing' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'شارة العملة المحلية', 'masa-geo-pricing' ); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox"
                                            name="<?php echo esc_attr( MASA_GEO_OPTION_KEY ); ?>[currency_badge_enabled]"
                                            value="1"
                                            <?php checked( ! empty( $settings['currency_badge_enabled'] ) ); ?> />
                                        <?php esc_html_e( 'تفعيل عرض شارة العملة للمشترين (مثال: 🇸🇦 الأسعار بالريال السعودي)', 'masa-geo-pricing' ); ?>
                                    </label>
                                    <p class="description"><?php esc_html_e( 'تظهر أسفل السعر في صفحة المنتج الفردي وفي المتجر لتأكيد العملة للمشتري.', 'masa-geo-pricing' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'شريط الشحن المجاني', 'masa-geo-pricing' ); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox"
                                            name="<?php echo esc_attr( MASA_GEO_OPTION_KEY ); ?>[free_shipping_bar_enabled]"
                                            value="1"
                                            <?php checked( ! empty( $settings['free_shipping_bar_enabled'] ) ); ?> />
                                        <?php esc_html_e( 'عرض شريط تقدم الشحن المجاني بالعملة المحلية في السلة والدفع', 'masa-geo-pricing' ); ?>
                                    </label>
                                    <p class="description"><?php esc_html_e( 'يحث العملاء على زيادة مشترياتهم للحصول على شحن مجاني (يحول قيمة الشحن المجاني من إعدادات ووكومرس تلقائياً).', 'masa-geo-pricing' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'عند حذف البلاجين', 'masa-geo-pricing' ); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox"
                                            name="<?php echo esc_attr( MASA_GEO_OPTION_KEY ); ?>[keep_data_on_uninstall]"
                                            value="1"
                                            <?php checked( ! empty( $settings['keep_data_on_uninstall'] ?? 1 ) ); ?> />
                                        <?php esc_html_e( 'احتفظ بالإعدادات والبيانات عند حذف البلاجين نهائياً', 'masa-geo-pricing' ); ?>
                                    </label>
                                    <p class="description"><?php esc_html_e( 'مفعّل افتراضياً — أمان عند نقل الموقع أو إعادة التثبيت. عطّله فقط إن أردت مسح كل شيء عند الحذف.', 'masa-geo-pricing' ); ?></p>
                                </td>
                            </tr>
                            <tr><th colspan="2"><hr style="margin:0;"></th></tr>
                            <tr>
                                <th><?php esc_html_e( 'رسالة "غير متاح في بلدك"', 'masa-geo-pricing' ); ?></th>
                                <td>
                                    <input type="text"
                                        name="<?php echo esc_attr( MASA_GEO_OPTION_KEY ); ?>[visibility_message]"
                                        value="<?php echo esc_attr( $settings['visibility_message'] ?? '' ); ?>"
                                        class="regular-text"
                                        placeholder="<?php esc_attr_e( 'عذراً، هذا المنتج غير متاح في بلدك حالياً.', 'masa-geo-pricing' ); ?>" />
                                    <p class="description"><?php esc_html_e( 'تظهر على صفحة المنتج المقيّد مكان زر الشراء. اتركها فارغة لاستخدام الرسالة الافتراضية.', 'masa-geo-pricing' ); ?></p>
                                </td>
                            </tr>
                            <tr><th colspan="2"><hr style="margin:0;"></th></tr>
                            <tr>
                                <th><?php esc_html_e( 'رقم الواتساب للاستفسارات', 'masa-geo-pricing' ); ?></th>
                                <td>
                                    <input type="text"
                                        name="<?php echo esc_attr( MASA_GEO_OPTION_KEY ); ?>[whatsapp_number]"
                                        value="<?php echo esc_attr( $settings['whatsapp_number'] ?? '' ); ?>"
                                        class="regular-text"
                                        placeholder="<?php esc_attr_e( 'مثال: 201012345678', 'masa-geo-pricing' ); ?>" />
                                    <p class="description"><?php esc_html_e( 'رقم الواتساب مع كود الدولة وبدون علامة + (مثال لمصر: 201012345678). اتركه فارغاً لتعطيل الميزة.', 'masa-geo-pricing' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'نص زر الواتساب', 'masa-geo-pricing' ); ?></th>
                                <td>
                                    <input type="text"
                                        name="<?php echo esc_attr( MASA_GEO_OPTION_KEY ); ?>[whatsapp_btn_label]"
                                        value="<?php echo esc_attr( $settings['whatsapp_btn_label'] ?? '' ); ?>"
                                        class="regular-text"
                                        placeholder="<?php esc_attr_e( 'تواصل عبر الواتساب', 'masa-geo-pricing' ); ?>" />
                                    <p class="description"><?php esc_html_e( 'النص الذي يظهر على زر الواتساب للمنتجات بدون سعر. الافتراضي: "تواصل عبر الواتساب".', 'masa-geo-pricing' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'رسالة الواتساب التلقائية', 'masa-geo-pricing' ); ?></th>
                                <td>
                                    <textarea name="<?php echo esc_attr( MASA_GEO_OPTION_KEY ); ?>[whatsapp_message_template]"
                                        rows="2" class="large-text"
                                        placeholder="<?php esc_attr_e( 'السلام عليكم، أود الاستفسار عن منتج: {product_name} - الرابط: {product_url}', 'masa-geo-pricing' ); ?>"><?php
                                        echo esc_textarea( $settings['whatsapp_message_template'] ?? '' );
                                    ?></textarea>
                                    <p class="description"><?php esc_html_e( 'الرسالة التلقائية التي تُرسل عند الضغط على الزر. يمكنك استخدام الكلمات المفتاحية {product_name} و {product_url}.', 'masa-geo-pricing' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( 'اختبار التسعير', 'masa-geo-pricing' ); ?></th>
                                <td>
                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=masa-geo-tools' ) ); ?>" class="button button-secondary">
                                        🔍 <?php esc_html_e( 'افتح Zone Preview Calculator', 'masa-geo-pricing' ); ?>
                                    </a>
                                    <p class="description"><?php esc_html_e( 'تقدر تختبر أسعار أي منتج لكل Zone من صفحة الأدوات.', 'masa-geo-pricing' ); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="masa-geo-card">
                        <h2><?php esc_html_e( '🚚 تسعير الشحن الجغرافي والمدن', 'masa-geo-pricing' ); ?></h2>
                        <p class="description">
                            <?php esc_html_e( 'حدد رسوم الشحن مباشرة بالعملة المحلية لكل منطقة جغرافية أو لكل مدينة رئيسية في السعودية والإمارات.', 'masa-geo-pricing' ); ?>
                        </p>

                        <table class="form-table masa-geo-form-table">
                            <tr>
                                <th><?php esc_html_e( 'تفعيل الشحن الجغرافي', 'masa-geo-pricing' ); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox"
                                            name="<?php echo esc_attr( MASA_GEO_OPTION_KEY ); ?>[shipping_enabled]"
                                            value="1"
                                            <?php checked( ! empty( $settings['shipping_enabled'] ) ); ?> />
                                        <?php esc_html_e( 'تفعيل تسعير الشحن بناءً على بلد أو مدينة العميل الجغرافية', 'masa-geo-pricing' ); ?>
                                    </label>
                                </td>
                            </tr>
                            <?php if ( ! empty( $zones ) ) : ?>
                            <tr>
                                <th><?php esc_html_e( 'رسوم الشحن للمناطق (بالعملة المحلية)', 'masa-geo-pricing' ); ?></th>
                                <td>
                                    <?php
                                    $shipping_rates = get_option( 'masa_geo_shipping_rates', [] );
                                    $city_rates     = get_option( 'masa_geo_shipping_cities', [] );
                                    ?>
                                    <table class="widefat" style="max-width: 600px; border: 1px solid #ccd0d4; border-radius: 6px; margin-bottom: 1.5rem;">
                                        <thead>
                                            <tr>
                                                <th style="padding: 10px;"><?php esc_html_e( 'المنطقة الجغرافية', 'masa-geo-pricing' ); ?></th>
                                                <th style="padding: 10px;"><?php esc_html_e( 'العملة', 'masa-geo-pricing' ); ?></th>
                                                <th style="padding: 10px;"><?php esc_html_e( 'رسوم الشحن الثابتة', 'masa-geo-pricing' ); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ( $zones as $zone ) :
                                                $zone_id = $zone['id'];
                                                $rate_val = isset( $shipping_rates[ $zone_id ] ) ? $shipping_rates[ $zone_id ] : '';
                                            ?>
                                                <tr>
                                                    <td style="padding: 10px; font-weight: 600;"><?php echo esc_html( $zone['label'] ); ?></td>
                                                    <td style="padding: 10px;"><span class="masa-zone-currency-tag"><?php echo esc_html( $zone['currency'] ); ?></span></td>
                                                    <td style="padding: 10px;">
                                                        <div class="masa-input-group" style="width: 150px;">
                                                            <input type="number" step="0.01" min="0"
                                                                name="masa_geo_shipping_rates[<?php echo esc_attr( $zone_id ); ?>]"
                                                                value="<?php echo esc_attr( $rate_val ); ?>"
                                                                placeholder="<?php esc_attr_e( 'مثال: 50', 'masa-geo-pricing' ); ?>"
                                                                class="masa-zone-price-field" style="height: 30px;" />
                                                            <span class="masa-input-prefix"><?php echo esc_html( $zone['currency'] ); ?></span>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </td>
                            </tr>

                            <tr>
                                <th><?php esc_html_e( 'شحن المدن التفصيلي (لأي بلد)', 'masa-geo-pricing' ); ?></th>
                                <td>
                                    <p class="description" style="margin-bottom: 8px;">
                                        <?php esc_html_e( 'حدد تكلفة شحن خاصة لمدن معينة بعملة البلد المحلية. سيتم مطابقة اسم المدينة الذي يكتبه الزبون تلقائياً.', 'masa-geo-pricing' ); ?>
                                    </p>
                                    <table id="masa-shipping-cities-table" class="widefat" style="max-width: 700px; border: 1px solid #ccd0d4; border-radius: 6px;">
                                        <thead>
                                            <tr>
                                                <th style="padding: 10px; width: 150px;"><?php esc_html_e( 'كود البلد (مثل: SA, AE)', 'masa-geo-pricing' ); ?></th>
                                                <th style="padding: 10px;"><?php esc_html_e( 'اسم المدينة (مثال: الرياض, دبي)', 'masa-geo-pricing' ); ?></th>
                                                <th style="padding: 10px; width: 180px;"><?php esc_html_e( 'تكلفة الشحن بالعملة المحلية', 'masa-geo-pricing' ); ?></th>
                                                <th style="padding: 10px; width: 50px;"></th>
                                            </tr>
                                        </thead>
                                        <tbody id="masa-shipping-cities-body">
                                            <?php
                                            $city_rates = get_option( 'masa_geo_shipping_cities' );
                                            if ( ! is_array( $city_rates ) ) {
                                                $city_rates = Masa_Geo_Shipping::get_default_cities();
                                            }

                                            foreach ( $city_rates as $index => $item ) :
                                                $country_code = strtoupper( $item['country'] ?? '' );
                                                $city_name    = $item['city'] ?? '';
                                                $city_cost    = $item['cost'] ?? '';
                                            ?>
                                                <tr class="masa-shipping-city-row">
                                                    <td style="padding: 8px;">
                                                        <input type="text" name="masa_geo_shipping_cities[<?php echo esc_attr( $index ); ?>][country]"
                                                            value="<?php echo esc_attr( $country_code ); ?>"
                                                            placeholder="SA" maxlength="2" style="width: 80px; text-transform: uppercase;" required />
                                                    </td>
                                                    <td style="padding: 8px;">
                                                        <input type="text" name="masa_geo_shipping_cities[<?php echo esc_attr( $index ); ?>][city]"
                                                            value="<?php echo esc_attr( $city_name ); ?>"
                                                            placeholder="<?php esc_attr_e( 'الرياض', 'masa-geo-pricing' ); ?>" style="width: 95%;" required />
                                                    </td>
                                                    <td style="padding: 8px;">
                                                        <input type="number" step="0.01" min="0" name="masa_geo_shipping_cities[<?php echo esc_attr( $index ); ?>][cost]"
                                                            value="<?php echo esc_attr( $city_cost ); ?>"
                                                            placeholder="30" style="width: 110px;" required />
                                                    </td>
                                                    <td style="padding: 8px; text-align: center; vertical-align: middle;">
                                                        <button type="button" class="button button-link-delete masa-remove-city-row" style="color: #dc2626; font-weight: bold;">✕</button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                    
                                    <div style="margin-top: 10px; max-width: 700px; display: flex; justify-content: space-between; align-items: center;">
                                        <button type="button" id="masa-add-shipping-city" class="button button-secondary">+ <?php esc_html_e( 'إضافة مدينة جديدة', 'masa-geo-pricing' ); ?></button>
                                        <p class="description" style="margin: 0;"><?php esc_html_e( 'اترك حقل التكلفة فارغاً أو احذف الصف لتطبيق السعر الافتراضي للمنطقة.', 'masa-geo-pricing' ); ?></p>
                                    </div>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </table>
                    </div>

                    <div class="masa-submit-wrap" style="margin-top: 0.5rem; text-align: right;">
                        <?php submit_button( __( 'حفظ جميع الإعدادات والشحن', 'masa-geo-pricing' ) ); ?>
                    </div>

                </div>
            </form>

            <div class="masa-geo-grid" style="margin-top: 1.5rem;">
                <div class="masa-geo-card masa-geo-card--full">
                    <div class="masa-geo-card-header">
                        <h2><?php esc_html_e( '🗺️ مناطق التسعير', 'masa-geo-pricing' ); ?></h2>
                        <button type="button" id="masa-add-zone" class="button button-primary">
                            + <?php esc_html_e( 'إضافة منطقة جديدة', 'masa-geo-pricing' ); ?>
                        </button>
                    </div>

                    <p class="description">
                        <?php esc_html_e( 'السعر النهائي = (سعر المنتج بالجنيه × المضاعف) × سعر صرف العملة المحلية.', 'masa-geo-pricing' ); ?>
                        <br>
                        <?php esc_html_e( 'منطقة "باقي العالم" تُطبَّق تلقائياً على أي بلد غير مُدرج في المناطق الأخرى.', 'masa-geo-pricing' ); ?>
                    </p>

                    <div id="masa-zones-container">
                        <?php foreach ( $zones as $index => $zone ) : ?>
                            <?php $this->render_zone_row( $zone, $index ); ?>
                        <?php endforeach; ?>
                    </div>

                    <div class="masa-geo-zones-footer">
                        <button type="button" id="masa-save-zones" class="button button-primary button-large">
                            💾 <?php esc_html_e( 'حفظ جميع المناطق', 'masa-geo-pricing' ); ?>
                        </button>
                    </div>
                </div>
            </div>
            <?php else : 
                $from = isset( $_GET['from'] ) ? sanitize_text_field( $_GET['from'] ) : '';
                $to   = isset( $_GET['to'] ) ? sanitize_text_field( $_GET['to'] ) : '';
                $zone_revenue = Masa_Geo_Order::get_revenue_by_zone( $from, $to );
                
                $total_orders = 0;
                $total_egp_revenue = 0.0;
                foreach ( $zone_revenue as $z_rev ) {
                    $total_orders += (int) $z_rev['orders'];
                    $total_egp_revenue += (float) $z_rev['egp_revenue'];
                }
                ?>
                <div class="masa-geo-grid">
                    <div class="masa-geo-card">
                        <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                            <input type="hidden" name="page" value="masa-geo-pricing" />
                            <input type="hidden" name="tab" value="analytics" />
                            
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <label for="from" style="font-weight: 600;"><?php esc_html_e( 'من تاريخ:', 'masa-geo-pricing' ); ?></label>
                                <input type="date" id="from" name="from" value="<?php echo esc_attr( $from ); ?>" style="padding: 5px; border-radius: 4px; border: 1px solid #ccd0d4; height: 32px;" />
                            </div>

                            <div style="display: flex; align-items: center; gap: 8px;">
                                <label for="to" style="font-weight: 600;"><?php esc_html_e( 'إلى تاريخ:', 'masa-geo-pricing' ); ?></label>
                                <input type="date" id="to" name="to" value="<?php echo esc_attr( $to ); ?>" style="padding: 5px; border-radius: 4px; border: 1px solid #ccd0d4; height: 32px;" />
                            </div>

                            <button type="submit" class="button button-primary" style="height: 32px; display: flex; align-items: center; justify-content: center;"><?php esc_html_e( 'تصفية 🔍', 'masa-geo-pricing' ); ?></button>
                            <?php if ( $from || $to ) : ?>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=masa-geo-pricing&tab=analytics' ) ); ?>" class="button button-secondary" style="height: 32px; display: inline-flex; align-items: center;"><?php esc_html_e( 'إعادة تعيين 🔄', 'masa-geo-pricing' ); ?></a>
                            <?php endif; ?>
                        </form>
                    </div>

                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px;">
                        <div class="masa-geo-card" style="display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center; border-inline-start: 5px solid #3b82f6; padding: 1.5rem 1rem;">
                            <span style="font-size: 2.2rem; margin-bottom: 8px;">📦</span>
                            <span style="font-size: 1.8rem; font-weight: 700; color: #1e3a8a;"><?php echo esc_html( $total_orders ); ?></span>
                            <span style="color: #6b7280; font-size: 0.9rem; margin-top: 4px;"><?php esc_html_e( 'إجمالي الطلبات المكتملة/قيد التنفيذ', 'masa-geo-pricing' ); ?></span>
                        </div>
                        <div class="masa-geo-card" style="display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center; border-inline-start: 5px solid #10b981; padding: 1.5rem 1rem;">
                            <span style="font-size: 2.2rem; margin-bottom: 8px;">💰</span>
                            <span style="font-size: 1.8rem; font-weight: 700; color: #065f46;"><?php echo esc_html( number_format( $total_egp_revenue, 2 ) ); ?> EGP</span>
                            <span style="color: #6b7280; font-size: 0.9rem; margin-top: 4px;"><?php esc_html_e( 'إجمالي المبيعات بالعملة الأساسية (EGP)', 'masa-geo-pricing' ); ?></span>
                        </div>
                    </div>

                    <div class="masa-geo-card masa-geo-card--full">
                        <h2 style="margin-bottom: 15px;"><?php esc_html_e( '📊 أداء المبيعات لكل منطقة جغرافية', 'masa-geo-pricing' ); ?></h2>
                        <table class="wp-list-table widefat fixed striped table-view-list" style="border: 1px solid #e2e8f0; border-radius: 6px; overflow: hidden; border-collapse: collapse; width: 100%;">
                            <thead>
                                <tr>
                                    <th style="padding: 12px; font-weight: 700; font-size: 0.9rem; text-align: right;"><?php esc_html_e( 'المنطقة الجغرافية', 'masa-geo-pricing' ); ?></th>
                                    <th style="padding: 12px; font-weight: 700; font-size: 0.9rem; text-align: right;"><?php esc_html_e( 'العملة المحلية', 'masa-geo-pricing' ); ?></th>
                                    <th style="padding: 12px; font-weight: 700; font-size: 0.9rem; text-align: center;"><?php esc_html_e( 'عدد الطلبات الناجحة', 'masa-geo-pricing' ); ?></th>
                                    <th style="padding: 12px; font-weight: 700; font-size: 0.9rem; text-align: right;"><?php esc_html_e( 'المبيعات بالعملة المحلية', 'masa-geo-pricing' ); ?></th>
                                    <th style="padding: 12px; font-weight: 700; font-size: 0.9rem; text-align: right;"><?php esc_html_e( 'المبيعات بـ (EGP)', 'masa-geo-pricing' ); ?></th>
                                    <th style="padding: 12px; font-weight: 700; font-size: 0.9rem; text-align: right; width: 260px;"><?php esc_html_e( 'النسبة من إجمالي المبيعات', 'masa-geo-pricing' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ( empty( $zone_revenue ) ) : ?>
                                    <tr>
                                        <td colspan="6" style="padding: 30px; text-align: center; color: #64748b; font-size: 0.95rem;">
                                            <?php esc_html_e( 'لا توجد بيانات مبيعات متوفرة لهذه الفترة.', 'masa-geo-pricing' ); ?>
                                        </td>
                                    </tr>
                                <?php else : ?>
                                    <?php foreach ( $zone_revenue as $zone_id => $data ) :
                                        $pct = $total_egp_revenue > 0 ? ( $data['egp_revenue'] / $total_egp_revenue ) * 100 : 0;
                                        ?>
                                        <tr>
                                            <td style="padding: 12px; font-weight: 600; color: #1e293b; font-size: 0.9rem;"><?php echo esc_html( $data['label'] ); ?></td>
                                            <td style="padding: 12px;"><span class="masa-zone-currency-tag"><?php echo esc_html( $data['currency'] ); ?></span></td>
                                            <td style="padding: 12px; text-align: center; font-weight: 700; color: #3b82f6; font-size: 0.95rem;"><?php echo esc_html( $data['orders'] ); ?></td>
                                            <td style="padding: 12px; font-weight: 600; color: #0f172a; font-size: 0.9rem;"><?php echo esc_html( number_format( $data['local_revenue'], 2 ) ) . ' ' . esc_html( $data['currency'] ); ?></td>
                                            <td style="padding: 12px; font-weight: 600; color: #059669; font-size: 0.9rem;"><?php echo esc_html( number_format( $data['egp_revenue'], 2 ) ); ?> ج.م</td>
                                            <td style="padding: 12px; vertical-align: middle;">
                                                <div style="display: flex; align-items: center; gap: 10px;">
                                                    <div style="flex: 1; background: #e2e8f0; border-radius: 9999px; height: 10px; overflow: hidden; position: relative;">
                                                        <div style="width: <?php echo esc_attr( $pct ); ?>%; background: linear-gradient(90deg, #3b82f6, #10b981); height: 100%; border-radius: 9999px;"></div>
                                                    </div>
                                                    <span style="font-size: 0.85rem; font-weight: 700; color: #475569; width: 45px; text-align: left;"><?php echo number_format( $pct, 1 ); ?>%</span>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function render_zone_row( array $zone, int $index ): void {
        $is_fallback   = in_array( '__FALLBACK__', $zone['countries'] ?? [], true );
        $countries_str = $is_fallback
            ? ''
            : implode( ', ', array_map( 'esc_html', $zone['countries'] ?? [] ) );
        ?>
        <div class="masa-zone-row" data-index="<?php echo esc_attr( $index ); ?>">
            <div class="masa-zone-header">
                <span class="masa-zone-drag">☰</span>
                <strong class="masa-zone-label-display"><?php echo esc_html( $zone['label'] ); ?></strong>
                <?php if ( ! $is_fallback && ! empty( $countries_str ) ) : ?>
                    <span class="masa-zone-countries-display" style="font-size: 0.85rem; color: #64748b; margin-right: 8px; font-weight: normal; max-width: 300px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?php echo esc_attr( $countries_str ); ?>">(<?php echo $countries_str; ?>)</span>
                <?php endif; ?>
                <?php if ( $is_fallback ) : ?>
                    <span class="masa-badge masa-badge--fallback"><?php esc_html_e( 'افتراضي', 'masa-geo-pricing' ); ?></span>
                <?php endif; ?>
                <div class="masa-zone-actions">
                    <button type="button" class="button button-small masa-toggle-zone"><?php esc_html_e( 'تعديل', 'masa-geo-pricing' ); ?></button>
                    <?php if ( ! $is_fallback ) : ?>
                        <button type="button" class="button button-small button-link-delete masa-delete-zone"><?php esc_html_e( 'حذف', 'masa-geo-pricing' ); ?></button>
                    <?php endif; ?>
                </div>
            </div>

            <div class="masa-zone-body" style="display:none;">
                <input type="hidden" class="masa-zone-id" value="<?php echo esc_attr( $zone['id'] ); ?>" />

                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e( 'اسم المنطقة', 'masa-geo-pricing' ); ?></th>
                        <td><input type="text" class="masa-zone-label regular-text" value="<?php echo esc_attr( $zone['label'] ); ?>" /></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'رمز العملة', 'masa-geo-pricing' ); ?></th>
                        <td>
                            <input type="text" class="masa-zone-currency small-text"
                                value="<?php echo esc_attr( $zone['currency'] ); ?>"
                                maxlength="3" style="width:80px;" />
                            <p class="description"><?php esc_html_e( '3 أحرف — مثال: SAR, EUR, USD', 'masa-geo-pricing' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'المضاعف', 'masa-geo-pricing' ); ?></th>
                        <td>
                            <input type="number" class="masa-zone-multiplier small-text"
                                value="<?php echo esc_attr( $zone['multiplier'] ); ?>"
                                step="0.01" min="0.01" style="width:100px;" />
                            <p class="description"><?php esc_html_e( 'مصر = 1.0 (السعر الأساسي). مثال: 1.5 يعني السعر × 1.5 قبل تحويل العملة.', 'masa-geo-pricing' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'مصدر سعر الصرف', 'masa-geo-pricing' ); ?></th>
                        <td>
                            <select class="masa-zone-rate-mode">
                                <option value="api" <?php selected( $zone['rate_mode'] ?? 'api', 'api' ); ?>><?php esc_html_e( 'تلقائي (API) مع احتياطي', 'masa-geo-pricing' ); ?></option>
                                <option value="manual" <?php selected( $zone['rate_mode'] ?? 'api', 'manual' ); ?>><?php esc_html_e( 'يدوي إجباري', 'masa-geo-pricing' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'سعر الصرف اليدوي/الاحتياطي', 'masa-geo-pricing' ); ?></th>
                        <td>
                            <div style="display:flex;align-items:center;gap:5px;">
                                <span>1 <strong class="masa-zone-currency-badge"><?php echo esc_html( $zone['currency'] ); ?></strong> = </span>
                                <input type="number" class="masa-zone-manual-rate small-text"
                                    value="<?php echo esc_attr( isset( $zone['manual_rate'] ) && $zone['manual_rate'] > 0 ? $zone['manual_rate'] : '' ); ?>"
                                    step="0.0001" min="0" style="width:100px;" />
                                <span><?php esc_html_e( 'EGP', 'masa-geo-pricing' ); ?></span>
                            </div>
                            <p class="description"><?php esc_html_e( 'لو اخترت "تلقائي"، سيتم استخدامه كاحتياطي عند فشل الـ API. لو اخترت "يدوي إجباري"، سيتم تجاهل الـ API تماماً.', 'masa-geo-pricing' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'الحد الأدنى للطلب', 'masa-geo-pricing' ); ?></th>
                        <td>
                            <input type="number" class="masa-zone-minimum-order small-text"
                                value="<?php echo esc_attr( isset( $zone['minimum_order'] ) && $zone['minimum_order'] > 0 ? $zone['minimum_order'] : '' ); ?>"
                                step="0.01" min="0" style="width:100px;" />
                            <span class="masa-zone-currency-badge" style="font-weight: 600;"><?php echo esc_html( $zone['currency'] ); ?></span>
                            <p class="description"><?php esc_html_e( 'الحد الأدنى للطلب لهذه المنطقة بالعملة المحلية. اتركه فارغاً أو 0 لتعطيل الميزة.', 'masa-geo-pricing' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'الدفع عند الاستلام (COD)', 'masa-geo-pricing' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" class="masa-zone-cod-enabled"
                                    value="1" <?php checked( ! isset( $zone['cod_enabled'] ) || ! empty( $zone['cod_enabled'] ) ); ?> />
                                <?php esc_html_e( 'تفعيل الدفع عند الاستلام لهذه المنطقة', 'masa-geo-pricing' ); ?>
                            </label>
                            <p class="description"><?php esc_html_e( 'إذا لم يكن مفعلاً، فسيتم إخفاء بوابة الدفع عند الاستلام لعملاء هذه المنطقة عند إتمام الطلب.', 'masa-geo-pricing' ); ?></p>
                        </td>
                    </tr>
                    <?php if ( ! $is_fallback ) : ?>
                    <tr>
                        <th><?php esc_html_e( 'الدول (كود ISO)', 'masa-geo-pricing' ); ?></th>
                        <td>
                            <textarea class="masa-zone-countries large-text" rows="3"
                                placeholder="SA, AE, KW, QA"><?php echo esc_textarea( $countries_str ); ?></textarea>
                            <p class="description">
                                <?php esc_html_e( 'أكواد البلدان مفصولة بفواصل — مثال: SA, AE, KW, QA', 'masa-geo-pricing' ); ?>
                                <a href="https://en.wikipedia.org/wiki/ISO_3166-1_alpha-2" target="_blank">
                                    <?php esc_html_e( 'قائمة الأكواد ↗', 'masa-geo-pricing' ); ?>
                                </a>
                            </p>
                        </td>
                    </tr>
                    <?php else : ?>
                        <input type="hidden" class="masa-zone-countries" value="__FALLBACK__" />
                    <?php endif; ?>
                </table>
            </div>
        </div>
        <?php
    }
}
