<?php
/**
 * Masa Geo-Pricing — Uninstall
 *
 * بيتنفذ بس لما المستخدم يحذف البلجن نهائياً من لوحة التحكم
 * (مش عند التعطيل). بيمسح كل آثار البلجن من الداتابيز —
 * إلا لو خيار "الاحتفاظ بالبيانات عند الحذف" مفعّل في الإعدادات.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$masa_geo_settings = get_option( 'masa_geo_settings', [] );

// لو العميل مفعّل "احتفظ بالبيانات" → اخرج من غير ما تمسح حاجة
// (مفيد لو بيشيل البلجن مؤقتاً أو بينقل الموقع)
if ( ! empty( $masa_geo_settings['keep_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;

// ─── 1. الـ Options ──────────────────────────────────────────
$masa_geo_options = [
	'masa_geo_settings',
	'masa_geo_exchange_rates',
	'masa_geo_prev_exchange_rates',
	'masa_geo_shipping_rates',
	'masa_geo_rates_last_update',
	'masa_geo_rates_history',
	'masa_geo_activity_log',      // الـ option القديمة (قبل v3.5.0) لو لسه موجودة
	'masa_geo_log_db_version',
	'masa_geo_zone_stock_db_version',
];

foreach ( $masa_geo_options as $masa_geo_opt ) {
	delete_option( $masa_geo_opt );
}

// ─── 2. الـ Transients ───────────────────────────────────────
// بنمسحها مباشرة من الجدول عشان نلقط أي transient بالبادئة بتاعتنا
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_masa\_geo\_%'
	    OR option_name LIKE '\_transient\_timeout\_masa\_geo\_%'"
);

// ─── 3. الـ Post Meta (المنتجات والـ variations) ─────────────
$masa_geo_meta_keys = [
	'_masa_geo_zone_prices',
	'_masa_geo_exclude',
	'_masa_geo_allowed_zone',
	'_masa_geo_zone_stock',
	'_masa_geo_timed_prices',
];

foreach ( $masa_geo_meta_keys as $masa_geo_key ) {
	$wpdb->query( $wpdb->prepare(
		"DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s",
		$masa_geo_key
	) );
}

// الـ blocked markers (مفتاح لكل zone) + meta الطلبات
$wpdb->query(
	"DELETE FROM {$wpdb->postmeta}
	 WHERE meta_key LIKE '\_masa\_geo\_blocked\_zone\_%'"
);

foreach ( [ '_masa_geo_zone_stock_reduced', '_masa_geo_zone_stock_changes' ] as $masa_geo_order_key ) {
	$wpdb->query( $wpdb->prepare(
		"DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s",
		$masa_geo_order_key
	) );
	// HPOS orders meta
	$masa_geo_hpos_table = $wpdb->prefix . 'wc_orders_meta';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $masa_geo_hpos_table ) ) === $masa_geo_hpos_table ) {
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$masa_geo_hpos_table} WHERE meta_key = %s",
			$masa_geo_order_key
		) );
	}
}

unset( $masa_geo_order_key );

delete_option( 'masa_geo_db_version' );

// ─── 4. جدول الـ Activity Log ────────────────────────────────
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}masa_geo_log" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}masa_geo_zone_stock" );

// ─── 5. الـ Cron ─────────────────────────────────────────────
wp_clear_scheduled_hook( 'masa_geo_fetch_rates_event' );

// ─── 6. تنظيف بيانات الشركاء والبراندات (Affiliates & Brands) ─
delete_option( 'masa_affiliate_settings' );
delete_option( 'masa_affiliate_db_version' );

// مسح جدول العمولات
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}masa_affiliate_earnings" );

// مسح حقول الـ Meta للمستخدمين
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ('masa_affiliate_code', 'masa_affiliate_commission_rate')" );

// مسح حقول الـ Meta للتصنيفات (Brands)
$wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE meta_key IN ('masa_brand_manager_id', 'masa_brand_commission_rate')" );

// مسح حقول الـ Meta للمنتجات
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_masa_commission_rate'" );

// مسح حقول الـ Meta للطلبات (في جدول الميتا العادي وجدول ووكومرس HPOS)
$masa_aff_meta_keys = [ '_masa_affiliate_ref', '_masa_affiliate_id', '_masa_affiliate_commission' ];
foreach ( $masa_aff_meta_keys as $masa_aff_key ) {
	$wpdb->query( $wpdb->prepare(
		"DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s",
		$masa_aff_key
	) );
	
	$masa_geo_hpos_table = $wpdb->prefix . 'wc_orders_meta';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $masa_geo_hpos_table ) ) === $masa_geo_hpos_table ) {
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$masa_geo_hpos_table} WHERE meta_key = %s",
			$masa_aff_key
		) );
	}
}
unset( $masa_aff_key );
