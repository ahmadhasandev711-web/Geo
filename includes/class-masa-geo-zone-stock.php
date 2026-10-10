<?php
defined( 'ABSPATH' ) || exit;

/**
 * Masa_Geo_Zone_Stock
 * محرك المخزون الجغرافي — سعر وكمية وإتاحة لكل Zone
 *
 * v3.7.0 — المعمارية الجديدة:
 * - المخزون في جدول مستقل `{prefix}masa_geo_zone_stock` بدل serialized meta:
 *   صف لكل (product_id, zone_id) مع UNIQUE KEY — وده اللي بيسمح بالخصم
 *   الذرّي (SELECT FOR UPDATE داخل transaction) فيقفل الـ race condition
 *   بتاعة "طلبين على آخر قطعة في نفس اللحظة" نهائياً.
 * - دعم مخزون على مستوى الـ Variation: صف الـ variation (لو موجود)
 *   بيتقدم على صف المنتج الأب. الكتالوج بيتفلتر بمستوى الأب بس —
 *   الـ variation المحجوبة بتبقى غير قابلة للشراء جوه صفحة المنتج.
 * - Refund جزئي: بيرجّع مخزون المنطقة بكمية الاسترجاع بالظبط، بسقف
 *   المخصوم الفعلي المسجل على الطلب (مستحيل over-credit).
 * - تنبيه إيميل عند نفاد منطقة (مع throttle 12 ساعة)، وpurge تلقائي
 *   لكاش صفحة المنتج عند الحجب/الإتاحة.
 *
 * النموذج زي ما هو: مفيش صف = اتبع مخزون ووكومرس العام • N = سقف بيع
 * للمنطقة • 0 = غير متاح فيها.
 */
final class Masa_Geo_Zone_Stock {

	private static ?Masa_Geo_Zone_Stock $instance = null;

	const TABLE      = 'masa_geo_zone_stock';
	const DB_VERSION = '1.0';
	const DB_VER_KEY = 'masa_geo_zone_stock_db_version';

	const META_BLOCKED_PREFIX = '_masa_geo_blocked_zone_';
	const LEGACY_META_MAP     = '_masa_geo_zone_stock'; // serialized القديمة (v3.6) — للـ migration

	// Order meta
	const ORDER_META_REDUCED = '_masa_geo_zone_stock_reduced';
	const ORDER_META_CHANGES = '_masa_geo_zone_stock_changes'; // ['zone'=>z, 'items'=>[owner_id => deducted]]

	/**
	 * قفل ذرّي على مستوى الطلب لمنع الخصم المتزامن المزدوج (race condition).
	 *
	 * المشكلة: قد يحرق ووكومرس `maybe_reduce` مرتين في نفس اللحظة (مثلاً
	 * `payment_complete` + `order_status_processing`)، فيقرأ الطلبان أن
	 * `ORDER_META_REDUCED` = "no" قبل أن يكتب أحدهما "yes"، فيُخصم المخزون
	 * مرتين. حلّنا: نستخدم transient قصير العمر كمحرّك قفل (lock) — إدراج
	 * المفتاح لأول مرة ينجح، والمرة الثانية يفشل فيُحجب الخصم.
	 *
	 * ملاحظة: `set_transient` على object cache خارجي (Redis/Memcached) ذرّي
	 * تماماً. على قاعدة البيانات قد يحصل تزاحم نظري شبه معدوم لأن الإدراج
	 * بـ UPDATE ... SET يفشل لو الصف موجود — وهو كافٍ لحالتنا.
	 */
	const REDUCE_LOCK_PREFIX = 'masa_geo_stock_reducing_';

	private static array $map_cache = []; // request cache: id => [zone => qty]

	public static function instance(): Masa_Geo_Zone_Stock {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// ─── الخصم — نفس triggers ووكومرس ───
		add_action( 'woocommerce_payment_complete',        [ $this, 'maybe_reduce' ] );
		add_action( 'woocommerce_order_status_processing', [ $this, 'maybe_reduce' ] );
		add_action( 'woocommerce_order_status_completed',  [ $this, 'maybe_reduce' ] );
		add_action( 'woocommerce_order_status_on-hold',    [ $this, 'maybe_reduce' ] );

		// ─── الإرجاع الكامل ───
		add_action( 'woocommerce_order_status_cancelled', [ $this, 'maybe_restore' ] );
		add_action( 'woocommerce_order_status_pending',   [ $this, 'maybe_restore' ] );
		add_action( 'woocommerce_order_status_refunded',  [ $this, 'maybe_restore' ] );

		// ─── الإرجاع الجزئي (Partial Refund) ───
		add_action( 'woocommerce_order_refunded', [ $this, 'handle_partial_refund' ], 10, 2 );

		// ─── فحص الكميات ───
		add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'validate_add_quantity' ], 20, 4 );
		add_action( 'woocommerce_check_cart_items',       [ $this, 'check_cart_quantities' ], 20 );

		// ─── الـ variation المحجوبة في منطقة الزائر = غير قابلة للشراء ───
		add_filter( 'woocommerce_variation_is_purchasable', [ $this, 'variation_purchasable' ], 99, 2 );

		// ─── شارة "باقي N فقط في منطقتك" ───
		add_action( 'woocommerce_single_product_summary', [ $this, 'render_low_stock_badge' ], 11 );
	}

	// ═════════════════════════════════════════════════════════
	// الجدول
	// ═════════════════════════════════════════════════════════

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	public static function install_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( "CREATE TABLE " . self::table_name() . " (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			product_id BIGINT UNSIGNED NOT NULL,
			zone_id VARCHAR(50) NOT NULL,
			qty INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY product_zone (product_id, zone_id),
			KEY zone_id (zone_id)
		) {$wpdb->get_charset_collate()};" );

		update_option( self::DB_VER_KEY, self::DB_VERSION, 'no' );
	}

	private static function ensure_table(): void {
		if ( get_option( self::DB_VER_KEY ) !== self::DB_VERSION ) {
			self::install_table();
		}
	}

	// ═════════════════════════════════════════════════════════
	// القراءة
	// ═════════════════════════════════════════════════════════

	/**
	 * خريطة مخزون لـ id معين (منتج أو variation) — صفوفه هو بس، من غير وراثة
	 */
	public static function get_stock_map( int $product_id ): array {
		if ( isset( self::$map_cache[ $product_id ] ) ) {
			return self::$map_cache[ $product_id ];
		}

		global $wpdb;
		self::ensure_table();

		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT zone_id, qty FROM ' . self::table_name() . ' WHERE product_id = %d',
			$product_id
		), ARRAY_A );

		$map = [];
		foreach ( (array) $rows as $row ) {
			$map[ sanitize_key( $row['zone_id'] ) ] = max( 0, (int) $row['qty'] );
		}

		self::$map_cache[ $product_id ] = $map;
		return $map;
	}

	/**
	 * المخزون الفعّال لـ id في zone — variation بترث الأب لو ملهاش صف خاص
	 *
	 * @return int|null null = مفيش تخصيص (اتبع مخزون ووكومرس العام)
	 */
	public static function get_zone_stock( int $product_id, string $zone_id ): ?int {
		$owner = self::resolve_stock_owner( $product_id, $zone_id );
		return $owner ? $owner['qty'] : null;
	}

	/**
	 * مين صاحب الصف الفعّال؟ (الـ variation نفسها ولا الأب)
	 * @return array{owner_id:int,qty:int}|null
	 */
	public static function resolve_stock_owner( int $product_id, string $zone_id ): ?array {
		$own = self::get_stock_map( $product_id );
		if ( array_key_exists( $zone_id, $own ) ) {
			return [ 'owner_id' => $product_id, 'qty' => $own[ $zone_id ] ];
		}

		if ( get_post_type( $product_id ) === 'product_variation' ) {
			$parent_id = (int) wp_get_post_parent_id( $product_id );
			if ( $parent_id ) {
				$parent_map = self::get_stock_map( $parent_id );
				if ( array_key_exists( $zone_id, $parent_map ) ) {
					return [ 'owner_id' => $parent_id, 'qty' => $parent_map[ $zone_id ] ];
				}
			}
		}

		return null;
	}

	public static function is_available_in_zone( int $product_id, string $zone_id ): bool {
		$stock = self::get_zone_stock( $product_id, $zone_id );
		return null === $stock || $stock > 0;
	}

	/**
	 * هل فيه أي تخصيص مخزون في الموقع كله؟ (لتصفير تكلفة الفلترة)
	 */
	public static function any_rows_exist(): bool {
		global $wpdb;
		self::ensure_table();
		return (bool) $wpdb->get_var( 'SELECT 1 FROM ' . self::table_name() . ' LIMIT 1' );
	}

	// ═════════════════════════════════════════════════════════
	// الكتابة
	// ═════════════════════════════════════════════════════════

	/**
	 * احفظ خريطة كاملة لـ id — المفاتيح الغايبة بتتمسح (= اتبع العام)
	 */
	public static function set_stock_map( int $product_id, array $map, bool $log = true ): void {
		global $wpdb;
		self::ensure_table();

		$clean = [];
		foreach ( $map as $zone_id => $qty ) {
			$zone_id = sanitize_key( (string) $zone_id );
			if ( $zone_id !== '' && is_numeric( $qty ) ) {
				$clean[ $zone_id ] = max( 0, (int) $qty );
			}
		}

		$current = self::get_stock_map( $product_id );
		if ( $clean === $current ) {
			return;
		}

		$table = self::table_name();

		// امسح الصفوف اللي اتشالت من الخريطة
		foreach ( array_diff_key( $current, $clean ) as $zone_id => $_ ) {
			$wpdb->delete( $table, [ 'product_id' => $product_id, 'zone_id' => $zone_id ], [ '%d', '%s' ] );
		}

		// upsert الباقي
		foreach ( $clean as $zone_id => $qty ) {
			if ( isset( $current[ $zone_id ] ) && $current[ $zone_id ] === $qty ) {
				continue;
			}
			$wpdb->query( $wpdb->prepare(
				"INSERT INTO {$table} (product_id, zone_id, qty) VALUES (%d, %s, %d)
				 ON DUPLICATE KEY UPDATE qty = VALUES(qty)",
				$product_id, $zone_id, $qty
			) );
		}

		self::flush_product_cache( $product_id );
		self::sync_blocked_markers( $product_id );

		if ( $log ) {
			Masa_Geo_Activity_Log::log( Masa_Geo_Activity_Log::EVENT_ZONE_STOCK_SET, [
				'product_id' => $product_id,
				'stock'      => $clean ?: 'global',
			] );
		}
	}

	/**
	 * خصم ذرّي — SELECT FOR UPDATE داخل transaction:
	 * طلبين متزامنين على آخر قطعة → واحد بس هياخدها، مضمون من الداتابيز نفسها
	 *
	 * @return int المخصوم فعلياً (0 لو مفيش رصيد)
	 */
	public static function atomic_deduct( int $owner_id, string $zone_id, int $qty ): int {
		global $wpdb;
		self::ensure_table();

		$table = self::table_name();

		$wpdb->query( 'START TRANSACTION' );

		$current = $wpdb->get_var( $wpdb->prepare(
			"SELECT qty FROM {$table} WHERE product_id = %d AND zone_id = %s FOR UPDATE",
			$owner_id, $zone_id
		) );

		if ( null === $current ) {
			$wpdb->query( 'ROLLBACK' );
			return 0;
		}

		$deduct = min( (int) $current, max( 0, $qty ) );

		if ( $deduct > 0 ) {
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$table} SET qty = qty - %d WHERE product_id = %d AND zone_id = %s",
				$deduct, $owner_id, $zone_id
			) );
		}

		$wpdb->query( 'COMMIT' );

		self::flush_product_cache( $owner_id );

		if ( ( (int) $current - $deduct ) === 0 && $deduct > 0 ) {
			self::on_zone_depleted( $owner_id, $zone_id );
		}

		return $deduct;
	}

	/**
	 * إرجاع ذرّي للمخزون (الصف لازم يكون موجود — لو التخصيص اتشال منرجعش لحاجة مش موجودة)
	 */
	public static function atomic_add( int $owner_id, string $zone_id, int $qty ): bool {
		global $wpdb;
		self::ensure_table();

		$was_zero = 0 === (int) ( self::get_stock_map( $owner_id )[ $zone_id ] ?? -1 );

		$updated = $wpdb->query( $wpdb->prepare(
			'UPDATE ' . self::table_name() . ' SET qty = qty + %d WHERE product_id = %d AND zone_id = %s',
			max( 0, $qty ), $owner_id, $zone_id
		) );

		if ( $updated ) {
			self::flush_product_cache( $owner_id );
			if ( $was_zero ) {
				// رجع من الصفر → شيل الحجب وامسح كاش الصفحة
				self::sync_blocked_markers( $owner_id );
				Masa_Geo_Compat::purge_product( self::parent_of( $owner_id ) );
			}
		}

		return (bool) $updated;
	}

	/**
	 * نفدت منطقة: marker + purge كاش + تنبيه إيميل
	 */
	private static function on_zone_depleted( int $owner_id, string $zone_id ): void {
		self::sync_blocked_markers( $owner_id );
		Masa_Geo_Compat::purge_product( self::parent_of( $owner_id ) );
		self::maybe_send_depletion_email( $owner_id, $zone_id );
	}

	private static function parent_of( int $product_id ): int {
		if ( get_post_type( $product_id ) === 'product_variation' ) {
			$parent = (int) wp_get_post_parent_id( $product_id );
			return $parent ?: $product_id;
		}
		return $product_id;
	}

	/**
	 * زامن الـ blocked markers — على مستوى المنتج الأب بس
	 * (الكتالوج بيعرض المنتجات الأب؛ الـ variation المحجوبة بتتحكم
	 * فيها فلترة الـ purchasable جوه صفحة المنتج)
	 */
	private static function sync_blocked_markers( int $product_id ): void {
		if ( get_post_type( $product_id ) === 'product_variation' ) {
			return;
		}

		$map         = self::get_stock_map( $product_id );
		$changed_any = false;

		foreach ( self::get_zone_ids() as $zone_id ) {
			$key        = self::META_BLOCKED_PREFIX . $zone_id;
			$should_set = array_key_exists( $zone_id, $map ) && 0 === $map[ $zone_id ];
			$is_set     = '' !== (string) get_post_meta( $product_id, $key, true );

			if ( $should_set && ! $is_set ) {
				update_post_meta( $product_id, $key, 1 );
				$changed_any = true;
			} elseif ( ! $should_set && $is_set ) {
				delete_post_meta( $product_id, $key );
				$changed_any = true;
			}
		}

		if ( $changed_any ) {
			Masa_Geo_Compat::purge_product( $product_id );
		}
	}

	private static function flush_product_cache( int $product_id ): void {
		unset( self::$map_cache[ $product_id ] );
		Masa_Geo_Visibility::flush_cache();
	}

	public static function get_zone_ids(): array {
		$settings = get_option( MASA_GEO_OPTION_KEY, [] );
		$ids      = [];
		foreach ( (array) ( $settings['zones'] ?? [] ) as $zone ) {
			if ( ! empty( $zone['id'] ) ) {
				$ids[] = sanitize_key( $zone['id'] );
			}
		}
		return $ids;
	}

	// ═════════════════════════════════════════════════════════
	// الخصم والإرجاع مع الطلبات
	// ═════════════════════════════════════════════════════════

	/**
	 * محاولة اكتساب قفل الخصم لطلب معيّن.
	 * بترجّع true لو اكتُسب (نحن الأول)، وfalse لو كان هناك خصم جارٍ/مكتمل.
	 *
	 * نستخدم transient أُنشئ بقيمة أولية محدّدة (قفل) + نحتفظ بقيمة مُخصّصة
	 * (token) نميّز بها "مكتمل" عن "جارٍ": لو القفل موجود بالفعل فهناك إما
	 * خصم جارٍ أو خصم اكتمل — في الحالتين نخرج.
	 */
	private function acquire_reduce_lock( int $order_id ): bool {
		$key    = self::REDUCE_LOCK_PREFIX . $order_id;
		// add_option operates atomically (INSERT IGNORE) on the database.
		// It returns false if the option already exists, acting as a true lock.
		$locked = add_option( $key, time(), '', 'no' );
		return (bool) $locked;
	}

	public function maybe_reduce( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order || 'yes' === $order->get_meta( self::ORDER_META_REDUCED ) ) {
			return;
		}

		// قفل ذرّي: لو فيه طلب تاني بيخصم نفس الأوردر دلوقتي → اخرج فوراً.
		// ده بيحمي من سيناريو payment_complete + order_status_processing المتزامن.
		if ( ! $this->acquire_reduce_lock( (int) $order_id ) ) {
			return;
		}

		$zone_id = $this->resolve_order_zone( $order );
		if ( '' === $zone_id ) {
			// لا توجد منطقة → لا يوجد مخزون جغرافي → حرّر القفل واخرج
			delete_option( self::REDUCE_LOCK_PREFIX . $order_id );
			return;
		}

		// نلفّ منطق الخصم بـ try/finally لضمان تحرير القفل حتى لو حدث استثناء،
		// حتى لا يبقى الطلب مقفولاً 5 دقائق بعد أي خطأ غير متوقع.
		try {
			$changes = [];

			foreach ( $order->get_items() as $item ) {
				if ( ! $item instanceof \WC_Order_Item_Product ) {
					continue;
				}

				$qty = (int) $item->get_quantity();
				if ( $qty <= 0 ) {
					continue;
				}

				// الـ variation أولاً (لو ليها صف خاص)، وإلا الأب
				$target_id = (int) ( $item->get_variation_id() ?: $item->get_product_id() );
				$owner     = self::resolve_stock_owner( $target_id, $zone_id );

				if ( null === $owner ) {
					continue; // تابع للمخزون العام
				}

				$deducted = self::atomic_deduct( $owner['owner_id'], $zone_id, $qty );

				if ( $deducted > 0 ) {
					$changes[ $owner['owner_id'] ] = ( $changes[ $owner['owner_id'] ] ?? 0 ) + $deducted;

					if ( 0 === self::get_zone_stock( $owner['owner_id'], $zone_id ) ) {
						$order->add_order_note( sprintf(
							/* translators: 1: اسم المنتج 2: الـ zone */
							__( '🌍 نفد المخزون الجغرافي للمنتج "%1$s" في منطقة "%2$s".', 'masa-geo-pricing' ),
							$item->get_name(),
							$zone_id
						) );
					}
				}
			}

			if ( ! empty( $changes ) ) {
				$order->update_meta_data( self::ORDER_META_CHANGES, [ 'zone' => $zone_id, 'items' => $changes ] );
			}

			$order->update_meta_data( self::ORDER_META_REDUCED, 'yes' );
			$order->save();
		} finally {
			// حرّر القفل فوراً — بما إن ORDER_META_REDUCED = "yes"،
			// المحاولة التانية هتخرج من أول guard في السطر الأول.
			delete_option( self::REDUCE_LOCK_PREFIX . (int) $order_id );
		}
	}

	public function maybe_restore( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order || 'yes' !== $order->get_meta( self::ORDER_META_REDUCED ) ) {
			return;
		}

		$record = $order->get_meta( self::ORDER_META_CHANGES );

		if ( is_array( $record ) && ! empty( $record['items'] ) && ! empty( $record['zone'] ) ) {
			$zone_id = sanitize_key( (string) $record['zone'] );

			foreach ( (array) $record['items'] as $owner_id => $deducted ) {
				if ( (int) $owner_id > 0 && (int) $deducted > 0 ) {
					self::atomic_add( (int) $owner_id, $zone_id, (int) $deducted );
				}
			}
		}

		$order->delete_meta_data( self::ORDER_META_CHANGES );
		$order->update_meta_data( self::ORDER_META_REDUCED, 'no' );
		$order->save();
	}

	/**
	 * Refund جزئي: رجّع مخزون المنطقة بكمية الاسترجاع، بسقف المخصوم
	 * المسجل — وعدّل السجل عشان الإلغاء الكامل بعدها ميرجّعش مرتين
	 */
	public function handle_partial_refund( $order_id, $refund_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order || 'yes' !== $order->get_meta( self::ORDER_META_REDUCED ) ) {
			return;
		}

		// الـ refund الكامل بحالة "مسترجع" بيتغطى في maybe_restore — منكررش
		if ( $order->has_status( 'refunded' ) ) {
			return;
		}

		$refund = wc_get_order( $refund_id );
		if ( ! $refund instanceof \WC_Order_Refund ) {
			return;
		}

		$record = $order->get_meta( self::ORDER_META_CHANGES );
		if ( ! is_array( $record ) || empty( $record['items'] ) || empty( $record['zone'] ) ) {
			return;
		}

		$zone_id = sanitize_key( (string) $record['zone'] );
		$items   = (array) $record['items'];
		$touched = false;

		foreach ( $refund->get_items() as $refund_item ) {
			if ( ! $refund_item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$refunded_qty = abs( (int) $refund_item->get_quantity() );
			if ( $refunded_qty <= 0 ) {
				continue;
			}

			$target_id = (int) ( $refund_item->get_variation_id() ?: $refund_item->get_product_id() );

			// الصف اتخصم باسم مين وقتها؟ نفس قاعدة الـ resolve
			$owner_id = isset( $items[ $target_id ] ) ? $target_id : self::parent_of( $target_id );

			if ( empty( $items[ $owner_id ] ) ) {
				continue; // المنتج ده ماتخصمش منه أصلاً
			}

			$restore = min( $refunded_qty, (int) $items[ $owner_id ] );
			if ( $restore <= 0 ) {
				continue;
			}

			self::atomic_add( $owner_id, $zone_id, $restore );

			$items[ $owner_id ] -= $restore;
			if ( $items[ $owner_id ] <= 0 ) {
				unset( $items[ $owner_id ] );
			}
			$touched = true;
		}

		if ( $touched ) {
			if ( empty( $items ) ) {
				$order->delete_meta_data( self::ORDER_META_CHANGES );
			} else {
				$order->update_meta_data( self::ORDER_META_CHANGES, [ 'zone' => $zone_id, 'items' => $items ] );
			}
			$order->save();
		}
	}

	private function resolve_order_zone( \WC_Order $order ): string {
		$zone_id = sanitize_key( (string) $order->get_meta( Masa_Geo_Order::META_ZONE_ID ) );
		if ( '' !== $zone_id ) {
			return $zone_id;
		}

		$country = strtoupper( $order->get_billing_country() );
		if ( '' === $country ) {
			return '';
		}

		$zone = Masa_Geo_Helper::get_zone_for_country( $country );
		return $zone && ! empty( $zone['id'] ) ? (string) $zone['id'] : '';
	}

	// ═════════════════════════════════════════════════════════
	// فحص الكميات + purchasability الـ variations
	// ═════════════════════════════════════════════════════════

	public function validate_add_quantity( bool $passed, $product_id, $quantity, $variation_id = 0 ): bool {
		if ( ! $passed ) {
			return false;
		}

		$zone_id = Masa_Geo_Visibility::get_visitor_zone_id();
		if ( '' === $zone_id ) {
			return true;
		}

		$target_id = (int) ( $variation_id ?: $product_id );
		$owner     = self::resolve_stock_owner( $target_id, $zone_id );

		if ( null === $owner ) {
			return true; // تابع للعام
		}

		// المطلوب + الموجود في السلة المحسوب على نفس الصف
		$in_cart = $this->cart_quantity_for_owner( $owner['owner_id'], $zone_id );

		if ( ( $in_cart + (int) $quantity ) > $owner['qty'] ) {
			wc_add_notice( sprintf(
				/* translators: %d: الكمية المتاحة */
				__( 'عذراً، الكمية المتاحة من هذا المنتج في منطقتك هي %d فقط.', 'masa-geo-pricing' ),
				$owner['qty']
			), 'error' );
			return false;
		}

		return true;
	}

	public function check_cart_quantities(): void {
		if ( ! WC()->cart ) {
			return;
		}

		$zone_id = Masa_Geo_Visibility::get_visitor_zone_id();
		if ( '' === $zone_id ) {
			return;
		}

		// اجمع الكميات على مستوى "صاحب الصف" — variations ليها صفوف بتتحسب لوحدها
		$totals = [];
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$target_id = (int) ( ( $cart_item['variation_id'] ?? 0 ) ?: ( $cart_item['product_id'] ?? 0 ) );
			if ( ! $target_id ) {
				continue;
			}
			$owner = self::resolve_stock_owner( $target_id, $zone_id );
			if ( null === $owner ) {
				continue;
			}
			$totals[ $owner['owner_id'] ] = ( $totals[ $owner['owner_id'] ] ?? 0 ) + (int) ( $cart_item['quantity'] ?? 0 );
		}

		foreach ( $totals as $owner_id => $qty ) {
			$stock = self::get_stock_map( $owner_id )[ $zone_id ] ?? null;

			if ( null === $stock || $qty <= $stock || 0 === $stock ) {
				continue; // الصفر بيتشال بالـ Visibility/purchasable — هنا السقف بس
			}

			$product = wc_get_product( $owner_id );
			wc_add_notice( sprintf(
				/* translators: 1: اسم المنتج 2: الكمية المتاحة */
				__( 'الكمية المتاحة من "%1$s" في منطقتك هي %2$d فقط — يرجى تعديل سلتك.', 'masa-geo-pricing' ),
				$product ? $product->get_name() : "#{$owner_id}",
				$stock
			), 'error' );
		}
	}

	private function cart_quantity_for_owner( int $owner_id, string $zone_id ): int {
		if ( ! WC()->cart ) {
			return 0;
		}

		$total = 0;
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$target_id = (int) ( ( $cart_item['variation_id'] ?? 0 ) ?: ( $cart_item['product_id'] ?? 0 ) );
			if ( ! $target_id ) {
				continue;
			}
			$owner = self::resolve_stock_owner( $target_id, $zone_id );
			if ( $owner && $owner['owner_id'] === $owner_id ) {
				$total += (int) ( $cart_item['quantity'] ?? 0 );
			}
		}
		return $total;
	}

	public function variation_purchasable( bool $purchasable, $variation ): bool {
		if ( ! $purchasable ) {
			return false;
		}

		$zone_id = Masa_Geo_Visibility::get_visitor_zone_id();
		if ( '' === $zone_id ) {
			return true;
		}

		return self::is_available_in_zone( $variation->get_id(), $zone_id );
	}

	// ═════════════════════════════════════════════════════════
	// شارة "باقي N فقط في منطقتك"
	// ═════════════════════════════════════════════════════════

	public function render_low_stock_badge(): void {
		$settings = get_option( MASA_GEO_OPTION_KEY, [] );
		if ( empty( $settings['zone_stock_badge'] ) ) {
			return;
		}

		$threshold = max( 1, (int) ( $settings['zone_stock_badge_threshold'] ?? 5 ) );

		$product_id = get_queried_object_id();
		$zone_id    = Masa_Geo_Visibility::get_visitor_zone_id();
		if ( ! $product_id || '' === $zone_id ) {
			return;
		}

		$stock = self::get_zone_stock( (int) $product_id, $zone_id );

		if ( null === $stock || $stock <= 0 || $stock > $threshold ) {
			return;
		}

		wp_enqueue_style(
			'masa-geo-frontend',
			MASA_GEO_PLUGIN_URL . 'assets/frontend.css',
			[],
			MASA_GEO_VERSION
		);

		printf(
			'<div class="masa-geo-low-stock">🔥 %s</div>',
			esc_html( sprintf(
				/* translators: %d: الكمية المتبقية */
				__( 'باقي %d فقط في منطقتك!', 'masa-geo-pricing' ),
				$stock
			) )
		);
	}

	// ═════════════════════════════════════════════════════════
	// تنبيه الإيميل عند النفاد (throttle 12 ساعة لكل منتج/منطقة)
	// ═════════════════════════════════════════════════════════

	private static function maybe_send_depletion_email( int $owner_id, string $zone_id ): void {
		$settings = get_option( MASA_GEO_OPTION_KEY, [] );
		if ( empty( $settings['zone_stock_email_alerts'] ) ) {
			return;
		}

		$throttle_key = 'masa_geo_depl_' . $owner_id . '_' . $zone_id;
		if ( get_transient( $throttle_key ) ) {
			return;
		}
		set_transient( $throttle_key, 1, 12 * HOUR_IN_SECONDS );

		$product = wc_get_product( $owner_id );
		$name    = $product ? $product->get_name() : "#{$owner_id}";

		wp_mail(
			get_option( 'admin_email' ),
			sprintf( '🌍 [%s] نفد المخزون الجغرافي', get_bloginfo( 'name' ) ),
			sprintf(
				"المنتج: %s\nالمنطقة: %s\n\nنفدت الكمية المخصصة لهذه المنطقة وأصبح المنتج غير متاح فيها.\nرابط التعديل: %s",
				$name,
				$zone_id,
				admin_url( 'post.php?post=' . self::parent_of( $owner_id ) . '&action=edit' )
			)
		);
	}

	// ═════════════════════════════════════════════════════════
	// Migrations
	// ═════════════════════════════════════════════════════════

	/**
	 * v3.6 → v3.7: serialized meta → جدول
	 */
	public static function migrate_from_meta(): int {
		global $wpdb;
		self::ensure_table();

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
			self::LEGACY_META_MAP
		), ARRAY_A );

		$migrated = 0;
		foreach ( (array) $rows as $row ) {
			$map = maybe_unserialize( $row['meta_value'] );
			if ( is_array( $map ) && ! empty( $map ) ) {
				self::set_stock_map( (int) $row['post_id'], $map, false );
				$migrated++;
			}
			delete_post_meta( (int) $row['post_id'], self::LEGACY_META_MAP );
		}

		return $migrated;
	}

	/**
	 * v3.3–3.5 → v3.7: نظام الـ allowed zones → صفوف بقيمة 0 للمحجوب
	 */
	public static function migrate_from_visibility(): int {
		global $wpdb;
		self::ensure_table();

		$product_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s",
			'_masa_geo_allowed_zone'
		) );

		if ( empty( $product_ids ) ) {
			return 0;
		}

		$all_zones = self::get_zone_ids();
		$migrated  = 0;

		foreach ( $product_ids as $product_id ) {
			$product_id = (int) $product_id;
			$allowed    = array_map( 'sanitize_key', (array) get_post_meta( $product_id, '_masa_geo_allowed_zone', false ) );

			$map = self::get_stock_map( $product_id );
			foreach ( $all_zones as $zone_id ) {
				if ( ! in_array( $zone_id, $allowed, true ) && ! array_key_exists( $zone_id, $map ) ) {
					$map[ $zone_id ] = 0;
				}
			}

			self::set_stock_map( $product_id, $map, false );
			delete_post_meta( $product_id, '_masa_geo_allowed_zone' );
			$migrated++;
		}

		return $migrated;
	}
}
