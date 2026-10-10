<?php
defined( 'ABSPATH' ) || exit;

/**
 * Masa_Geo_Activity_Log
 * سجّل كل التغييرات في الـ Plugin: Zones، Overrides، أسعار الصرف، العرض الجغرافي
 *
 * v3.5.0: السجل اتنقل من serialized option لجدول مستقل —
 * أسرع في الفلترة والـ pagination، وسعة أكبر (1000 سجل) من غير ما
 * نحمّل الـ options table بقيمة ضخمة بتتقري في كل page load.
 * فيه migration تلقائية من الـ option القديمة أول استخدام.
 */
final class Masa_Geo_Activity_Log {

	const LOG_OPTION  = 'masa_geo_activity_log';        // القديمة — بتتمسح بعد الـ migration
	const TABLE       = 'masa_geo_log';
	const DB_VERSION  = '1.0';
	const DB_VER_KEY  = 'masa_geo_log_db_version';
	const MAX_ENTRIES = 1000;

	// ─────────────────────────────────────────────────────────
	// أنواع الأحداث
	// ─────────────────────────────────────────────────────────
	const EVENT_ZONE_SAVED      = 'zone_saved';
	const EVENT_ZONE_DELETED    = 'zone_deleted';
	const EVENT_OVERRIDE_SET    = 'override_set';
	const EVENT_OVERRIDE_REMOVED= 'override_removed';
	const EVENT_RATES_UPDATED   = 'rates_updated';
	const EVENT_RATES_FAILED    = 'rates_failed';
	const EVENT_SETTINGS_SAVED  = 'settings_saved';
	const EVENT_BULK_OVERRIDE   = 'bulk_override';
	const EVENT_IMPORT          = 'import';
	const EVENT_EXPORT          = 'export';
	const EVENT_TIMED_PRICE_SET = 'timed_price_set';
	const EVENT_VISIBILITY_SET     = 'visibility_set';
	const EVENT_VISIBILITY_REMOVED = 'visibility_removed';
	const EVENT_ZONE_STOCK_SET     = 'zone_stock_set';

	// ─────────────────────────────────────────────────────────
	// الجدول
	// ─────────────────────────────────────────────────────────

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * إنشاء الجدول — بيتنادى عند التفعيل، وفيه fallback كسول
	 * لو البلجن اتحدّث بالاستبدال من غير ما يتعطّل ويتفعّل
	 */
	public static function install_table(): void {
		global $wpdb;

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_time INT UNSIGNED NOT NULL,
			event_type VARCHAR(40) NOT NULL,
			user_label VARCHAR(190) NOT NULL DEFAULT '',
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			context LONGTEXT NULL,
			ip VARCHAR(45) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY event_type (event_type),
			KEY event_time (event_time)
		) {$charset};" );

		update_option( self::DB_VER_KEY, self::DB_VERSION, 'no' );

		self::maybe_migrate_from_option();
	}

	private static function ensure_table(): void {
		if ( get_option( self::DB_VER_KEY ) !== self::DB_VERSION ) {
			self::install_table();
		}
	}

	/**
	 * انقل السجلات من الـ option القديمة للجدول — مرة واحدة بس
	 */
	private static function maybe_migrate_from_option(): void {
		global $wpdb;

		$old = get_option( self::LOG_OPTION, [] );
		if ( empty( $old ) || ! is_array( $old ) ) {
			delete_option( self::LOG_OPTION );
			return;
		}

		$table = self::table_name();

		// القديمة كانت الأحدث أولاً — نعكسها عشان الـ auto-increment يحافظ على الترتيب
		foreach ( array_reverse( $old ) as $entry ) {
			if ( empty( $entry['event_type'] ) ) {
				continue;
			}
			$wpdb->insert(
				$table,
				[
					'event_time' => (int) ( $entry['time'] ?? time() ),
					'event_type' => sanitize_key( $entry['event_type'] ),
					'user_label' => sanitize_text_field( (string) ( $entry['user'] ?? '' ) ),
					'user_id'    => (int) ( $entry['user_id'] ?? 0 ),
					'context'    => wp_json_encode( $entry['context'] ?? [] ),
					'ip'         => sanitize_text_field( (string) ( $entry['ip'] ?? '' ) ),
				],
				[ '%d', '%s', '%s', '%d', '%s', '%s' ]
			);
		}

		delete_option( self::LOG_OPTION );
	}

	// ─────────────────────────────────────────────────────────
	// سجّل حدث
	// ─────────────────────────────────────────────────────────
	public static function log( string $event_type, array $context = [] ): void {
		global $wpdb;

		self::ensure_table();

		$current_user = wp_get_current_user();
		$user_label   = $current_user->ID
			? ( $current_user->display_name ?: $current_user->user_login )
			: 'System (Cron)';

		$wpdb->insert(
			self::table_name(),
			[
				'event_time' => time(),
				'event_type' => sanitize_key( $event_type ),
				'user_label' => $user_label,
				'user_id'    => (int) $current_user->ID,
				'context'    => wp_json_encode( $context ),
				'ip'         => Masa_Geo_Helper::get_real_ip(),
			],
			[ '%d', '%s', '%s', '%d', '%s', '%s' ]
		);

		self::maybe_prune();
	}

	/**
	 * احتفظ بآخر MAX_ENTRIES بس — pruning احتمالي (1 من كل ~20 كتابة)
	 * عشان منعملش DELETE query على كل log
	 */
	private static function maybe_prune(): void {
		if ( wp_rand( 1, 20 ) !== 1 ) {
			return;
		}

		global $wpdb;
		$table = self::table_name();

		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$table}
			 WHERE id NOT IN (
				SELECT id FROM ( SELECT id FROM {$table} ORDER BY id DESC LIMIT %d ) keep_rows
			 )",
			self::MAX_ENTRIES
		) );
	}

	// ─────────────────────────────────────────────────────────
	// جيب السجل — نفس توقيع وشكل النتائج القديم بالظبط
	// (id, time, event_type, user, user_id, context, ip)
	// ─────────────────────────────────────────────────────────
	public static function get( int $limit = 50, int $offset = 0, string $filter_type = '' ): array {
		global $wpdb;

		self::ensure_table();

		$table = self::table_name();

		if ( $filter_type ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$table} WHERE event_type = %s ORDER BY id DESC LIMIT %d OFFSET %d",
				sanitize_key( $filter_type ), $limit, $offset
			), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d",
				$limit, $offset
			), ARRAY_A );
		}

		return array_map( static function ( array $row ): array {
			$context = json_decode( (string) $row['context'], true );
			return [
				'id'         => (int) $row['id'],
				'time'       => (int) $row['event_time'],
				'event_type' => $row['event_type'],
				'user'       => $row['user_label'],
				'user_id'    => (int) $row['user_id'],
				'context'    => is_array( $context ) ? $context : [],
				'ip'         => $row['ip'],
			];
		}, $rows ?: [] );
	}

	public static function count( string $filter_type = '' ): int {
		global $wpdb;

		self::ensure_table();

		$table = self::table_name();

		if ( $filter_type ) {
			return (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE event_type = %s",
				sanitize_key( $filter_type )
			) );
		}

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	public static function clear(): void {
		global $wpdb;
		self::ensure_table();
		$table = self::table_name();
		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}

	// ─────────────────────────────────────────────────────────
	// Labels بشرية للأحداث
	// ─────────────────────────────────────────────────────────
	public static function get_event_label( string $event_type ): string {
		$labels = [
			self::EVENT_ZONE_SAVED       => '🗺️ حفظ/تعديل Zone',
			self::EVENT_ZONE_DELETED     => '🗑️ حذف Zone',
			self::EVENT_OVERRIDE_SET     => '✏️ تحديد Override لمنتج',
			self::EVENT_OVERRIDE_REMOVED => '🔄 إزالة Override من منتج',
			self::EVENT_RATES_UPDATED    => '💱 تحديث أسعار الصرف',
			self::EVENT_RATES_FAILED     => '❌ فشل تحديث الأسعار',
			self::EVENT_SETTINGS_SAVED   => '⚙️ حفظ الإعدادات',
			self::EVENT_BULK_OVERRIDE    => '📦 Bulk Override',
			self::EVENT_IMPORT           => '📥 استيراد إعدادات',
			self::EVENT_EXPORT           => '📤 تصدير إعدادات',
			self::EVENT_TIMED_PRICE_SET  => '⏰ تسعير مؤقت',
			self::EVENT_VISIBILITY_SET     => '🌍 تقييد عرض منتج بمناطق',
			self::EVENT_VISIBILITY_REMOVED => '🌐 إتاحة منتج لكل البلاد',
			self::EVENT_ZONE_STOCK_SET     => '📦 تعديل المخزون الجغرافي',
		];
		return $labels[ $event_type ] ?? $event_type;
	}
}
