<?php
defined( 'ABSPATH' ) || exit;

/**
 * Masa_Geo_Affiliate_Commission
 * مسؤول عن حساب العمولات عند إتمام الطلب وتخزينها في قاعدة البيانات
 */
final class Masa_Geo_Affiliate_Commission {

	private static ?Masa_Geo_Affiliate_Commission $instance = null;

	public static function instance(): Masa_Geo_Affiliate_Commission {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// الحساب عند اكتمال حالة الطلب
		add_action( 'woocommerce_order_status_completed', [ $this, 'calculate_order_commission' ], 10, 1 );
	}

	/**
	 * حساب وتسجيل العمولات للطلب المكتمل
	 */
	public function calculate_order_commission( int $order_id ): void {
		global $wpdb;

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// التحقق من وجود عملية عمولة سابقة مسجلة لهذا الطلب لتفادي التكرار
		$table_name = Masa_Geo_Affiliate_Core::table_name();
		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table_name} WHERE order_id = %d",
			$order_id
		) );

		if ( $exists > 0 ) {
			return; // تم حساب عمولة هذا الطلب مسبقاً
		}

		// قراءة كود الإحالة من الكوكي المخزنة لدى العميل (أو من بيانات الطلب لو تم تخزينها فيه)
		// للضمان الكامل، سنحاول جلب الكوكي، ويمكننا تخزينها كـ Metadata في الطلب عند الإنشاء
		$ref_code = Masa_Geo_Affiliate_Tracker::get_referred_code();
		
		// لو الكوكي فارغة، نقرأ من الـ metadata الخاصة بالطلب (في حالة ما إذا تم حفظه وقت الـ checkout)
		if ( empty( $ref_code ) ) {
			$ref_code = $order->get_meta( '_masa_affiliate_ref', true );
		}

		if ( empty( $ref_code ) ) {
			return; // لا توجد إحالة لهذا الطلب
		}

		$affiliate_id = Masa_Geo_Affiliate_Core::get_affiliate_by_code( $ref_code );
		if ( ! $affiliate_id ) {
			return; // معرف مسوق غير صالح
		}

		// جلب رتبة المسوق
		$user = get_userdata( $affiliate_id );
		if ( ! $user ) {
			return;
		}

		$is_brand_manager = in_array( Masa_Geo_Affiliate_Core::ROLE_BRAND_MANAGER, $user->roles, true );
		$is_affiliate     = in_array( Masa_Geo_Affiliate_Core::ROLE_AFFILIATE, $user->roles, true ) || in_array( 'administrator', $user->roles, true );

		if ( ! $is_brand_manager && ! $is_affiliate ) {
			return; // المستخدم ليس مسوقاً ولا مدير براند
		}

		// جلب العملة التي تم الدفع بها
		$order_currency = $order->get_currency();
		$base_currency  = get_option( 'woocommerce_currency' ); // عملة الموقع الأساسية

		// جلب سعر الصرف للعملة التي تم الدفع بها مقارنة بالجنيه/العملة الأساسية للموقع
		// بما أن أسعار الصرف في Masa Geo-Pricing مبنية على أساس العملة الأساسية للموقع (EGP=1.0)
		$rate = Masa_Geo_Api::get_rate( $order_currency );
		if ( $rate <= 0 ) {
			$rate = 1.0;
		}

		// جلب نسبة العمولات الأساسية للمسوق من حسابه أو إعدادات الموقع
		$settings = get_option( 'masa_affiliate_settings', [] );
		$affiliate_rate = get_user_meta( $affiliate_id, 'masa_affiliate_commission_rate', true );
		
		if ( $affiliate_rate === '' ) {
			$affiliate_rate = isset( $settings['affiliate_default_commission_rate'] ) ? floatval( $settings['affiliate_default_commission_rate'] ) : 10.0;
		} else {
			$affiliate_rate = floatval( $affiliate_rate );
		}

		$commissionable_total_original = 0.0; // الإجمالي القابل للعمولة بعملة الدفع
		$commission_amount_base        = 0.0; // إجمالي العمولات المحسوبة بالعملة الأساسية للموقع
		$items = $order->get_items();

		$owned_brands = [];
		if ( $is_brand_manager ) {
			$owned_brands = Masa_Geo_Affiliate_Core::get_user_brands( $affiliate_id );
			if ( empty( $owned_brands ) ) {
				return; // لا يملك أي براندات مرتبطة
			}
		}

		foreach ( $items as $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}

			// جلب معرف المنتج الأب لو كان variation
			$product_id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();

			$belongs_to_brand = false;
			$matched_brand_id = 0;

			// البحث عن البراند المرتبط بالمنتج لتأكيد الصلاحية ومعرفة عمولة البراند
			foreach ( Masa_Geo_Affiliate_Core::BRAND_TAXONOMIES as $taxonomy ) {
				if ( ! taxonomy_exists( $taxonomy ) ) {
					continue;
				}
				$terms = get_the_terms( $product_id, $taxonomy );
				if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
					foreach ( $terms as $term ) {
						$matched_brand_id = (int) $term->term_id;
						if ( $is_brand_manager && in_array( $matched_brand_id, $owned_brands, true ) ) {
							$belongs_to_brand = true;
						}
					}
					// كاحتياط، لو لم يتم التحديد نأخذ أول ترم للبراند لتسجيله
					if ( ! $matched_brand_id && isset( $terms[0] ) ) {
						$matched_brand_id = (int) $terms[0]->term_id;
					}
					break;
				}
			}

			// قيمة السطر الصافية بعد خصومات الكوبونات وقبل الضريبة والشحن
			$line_total_original = floatval( $item->get_total() );
			if ( $line_total_original <= 0 ) {
				continue;
			}

			// تحديد نسبة عمولة هذا المنتج بناءً على الأولويات:
			// 1. عمولة المنتج المخصصة (_masa_commission_rate)
			// 2. عمولة البراند المخصصة (إذا كان مدير براند والمنتج ينتمي لبرانده)
			// 3. عمولة المسوق المخصصة (أو عمولة الموقع الافتراضية)
			$item_commission_rate = get_post_meta( $product_id, '_masa_commission_rate', true );

			if ( $item_commission_rate === '' ) {
				$brand_rate = '';
				if ( $is_brand_manager && $belongs_to_brand && $matched_brand_id ) {
					$brand_rate = get_term_meta( $matched_brand_id, 'masa_brand_commission_rate', true );
				}

				if ( $brand_rate !== '' && $brand_rate !== false ) {
					$item_commission_rate = floatval( $brand_rate );
				} else {
					// تراجع لنسبة المسوق أو النسبة الافتراضية للموقع
					$item_commission_rate = $affiliate_rate;
				}
			} else {
				$item_commission_rate = floatval( $item_commission_rate );
			}

			// جمع الإجمالي الأصلي القابل للعمولة
			$commissionable_total_original += $line_total_original;

			// تحويل القيمة للعملة الأساسية
			$line_total_base = $line_total_original / $rate;

			// حساب عمولة هذا السطر بالعملة الأساسية وجمعها للطلب
			$line_commission_base = $line_total_base * ( $item_commission_rate / 100.0 );
			$commission_amount_base += $line_commission_base;
		}

		if ( $commissionable_total_original <= 0 ) {
			return; // لا توجد مبيعات تستحق العمولة
		}

		// تسجيل الأرباح في الداتا بيز
		$wpdb->insert(
			$table_name,
			[
				'affiliate_id'         => $affiliate_id,
				'affiliate_code'       => $ref_code,
				'order_id'             => $order_id,
				'commission_amount'    => $commission_amount_base,
				'currency'             => $base_currency,
				'original_currency'    => $order_currency,
				'original_order_total' => $order->get_total(),
				'status'               => 'pending',
				'created_at'           => time(),
				'paid_at'              => 0,
			],
			[
				'%d', // affiliate_id
				'%s', // affiliate_code
				'%d', // order_id
				'%f', // commission_amount
				'%s', // currency
				'%s', // original_currency
				'%f', // original_order_total
				'%s', // status
				'%d', // created_at
				'%d', // paid_at
			]
		);

		// حفظ الإحالة كـ metadata للأوردر للتوثيق
		$order->update_meta_data( '_masa_affiliate_id', $affiliate_id );
		$order->update_meta_data( '_masa_affiliate_commission', $commission_amount_base );
		$order->save();

		// مسح الكوكيز للعميل لكي لا تتكرر العمولات بلا داعٍ في عمليات الشراء اللاحقة
		// إلا إذا أراد المسوق أن يشتري الزائر مرة أخرى برابط جديد
		Masa_Geo_Affiliate_Tracker::clear_referral_cookie();
	}
}
