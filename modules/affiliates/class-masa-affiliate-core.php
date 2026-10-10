<?php
defined( 'ABSPATH' ) || exit;

/**
 * Masa_Geo_Affiliate_Core
 * الكلاس الأساسي لإدارة نظام العمولات والشركاء (البراندات والمسوقين)
 */
final class Masa_Geo_Affiliate_Core {

	private static ?Masa_Geo_Affiliate_Core $instance = null;

	const TABLE = 'masa_affiliate_earnings';
	const DB_VERSION = '1.0';
	const DB_VER_KEY = 'masa_affiliate_db_version';

	const ROLE_BRAND_MANAGER = 'masa_brand_manager';
	const ROLE_AFFILIATE      = 'masa_affiliate';

	// الـ taxonomies الشائعة لإدارة البراندات في ووكومرس
	const BRAND_TAXONOMIES = [
		'product_brand',       // WooCommerce Brands
		'brand',               // Custom/Theme Brand Taxonomy
		'pwb-brand',           // Perfect WooCommerce Brands
		'yith_product_brand',  // YITH WooCommerce Brands
	];

	public static function instance(): Masa_Geo_Affiliate_Core {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// تسجيل الـ Hooks الأساسية
		add_action( 'init', [ $this, 'register_roles' ] );
		add_action( 'admin_init', [ $this, 'register_brand_meta_fields' ] );
		
		// ربط شاشات إدارة تصنيفات البراند
		foreach ( self::BRAND_TAXONOMIES as $taxonomy ) {
			add_action( "{$taxonomy}_edit_form_fields", [ $this, 'add_brand_manager_field' ], 10, 2 );
			add_action( "edited_{$taxonomy}", [ $this, 'save_brand_manager_field' ], 10, 2 );
		}

		// ربط حقول تعديل المنتج في ووكومرس
		add_action( 'woocommerce_product_options_general_product_data', [ $this, 'add_product_commission_rate_field' ] );
		add_action( 'woocommerce_process_product_meta', [ $this, 'save_product_commission_rate_field' ], 10, 1 );

		// توليد كود الإحالة تلقائياً عند حفظ حساب المستخدم
		add_action( 'personal_options_update', [ $this, 'save_user_affiliate_code' ] );
		add_action( 'edit_user_profile_update', [ $this, 'save_user_affiliate_code' ] );
		add_action( 'show_user_profile', [ $this, 'show_affiliate_fields_in_profile' ] );
		add_action( 'edit_user_profile', [ $this, 'show_affiliate_fields_in_profile' ] );
	}

	/**
	 * الحصول على اسم الجدول بالـ prefix
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * إنشاء جدول قاعدة البيانات للعمولات والأرباح
	 */
	public static function install_table(): void {
		global $wpdb;

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// هيكل الجدول المعتمد في الخطة
		dbDelta( "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			affiliate_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			affiliate_code VARCHAR(50) NOT NULL,
			order_id BIGINT UNSIGNED NOT NULL,
			commission_amount DECIMAL(18, 4) NOT NULL DEFAULT 0.0000,
			currency VARCHAR(10) NOT NULL,
			original_currency VARCHAR(10) NOT NULL,
			original_order_total DECIMAL(18, 4) NOT NULL DEFAULT 0.0000,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			created_at INT UNSIGNED NOT NULL,
			paid_at INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY affiliate_id (affiliate_id),
			KEY affiliate_code (affiliate_code),
			KEY order_id (order_id)
		) {$charset};" );

		update_option( self::DB_VER_KEY, self::DB_VERSION, 'no' );
	}

	/**
	 * تسجيل رتب المستخدمين المخصصة للمسوقين ومديري البراندات
	 */
	public function register_roles(): void {
		// رتبة مسوق بالعمولة (Affiliate)
		if ( ! get_role( self::ROLE_AFFILIATE ) ) {
			add_role(
				self::ROLE_AFFILIATE,
				esc_html__( 'مسوق بالعمولة', 'masa-geo-pricing' ),
				[
					'read' => true, // صلاحية القراءة الأساسية ودخول الحساب
				]
			);
		}

		// رتبة مدير البراند (Brand Manager)
		if ( ! get_role( self::ROLE_BRAND_MANAGER ) ) {
			add_role(
				self::ROLE_BRAND_MANAGER,
				esc_html__( 'مدير براند', 'masa-geo-pricing' ),
				[
					'read' => true,
				]
			);
		}
	}

	/**
	 * تسجيل حقل الـ Meta لربط البراند بمدير البراند
	 */
	public function register_brand_meta_fields(): void {
		foreach ( self::BRAND_TAXONOMIES as $taxonomy ) {
			register_term_meta( $taxonomy, 'masa_brand_manager_id', [
				'type'              => 'integer',
				'description'       => 'ID of the user managing this brand',
				'single'            => true,
				'sanitize_callback' => 'absint',
				'show_in_rest'      => true,
			] );
			register_term_meta( $taxonomy, 'masa_brand_commission_rate', [
				'type'              => 'number',
				'description'       => 'Custom commission rate for this brand',
				'single'            => true,
				'sanitize_callback' => 'floatval',
				'show_in_rest'      => true,
			] );
		}
	}

	/**
	 * إضافة حقل اختيار مدير البراند ونسبة العمولة المخصصة في صفحة تعديل الـ Taxonomy
	 */
	public function add_brand_manager_field( $term, $taxonomy ): void {
		$manager_id = get_term_meta( $term->term_id, 'masa_brand_manager_id', true );
		$brand_rate = get_term_meta( $term->term_id, 'masa_brand_commission_rate', true );
		
		// جلب كافة المستخدمين الذين يملكون رتبة مدير براند أو مسؤول
		$users = get_users( [
			'role__in' => [ self::ROLE_BRAND_MANAGER, 'administrator' ],
			'orderby'  => 'display_name',
			'order'    => 'ASC',
		] );
		wp_nonce_field( 'masa_brand_manager_nonce', 'masa_brand_manager_nonce' );
		?>
		<tr class="form-field">
			<th scope="row" valign="top">
				<label for="masa_brand_manager_id"><?php esc_html_e( 'مدير البراند المسؤول', 'masa-geo-pricing' ); ?></label>
			</th>
			<td>
				<select name="masa_brand_manager_id" id="masa_brand_manager_id" class="postform">
					<option value=""><?php esc_html_e( '— اختر مدير البراند —', 'masa-geo-pricing' ); ?></option>
					<?php foreach ( $users as $user ) : ?>
						<option value="<?php echo esc_attr( $user->ID ); ?>" <?php selected( $manager_id, $user->ID ); ?>>
							<?php echo esc_html( $user->display_name . ' (' . $user->user_email . ')' ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'اختر المستخدم المسؤول عن هذا البراند لربطه بإحصائيات وعمولات مبيعات منتجاته.', 'masa-geo-pricing' ); ?></p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row" valign="top">
				<label for="masa_brand_commission_rate"><?php esc_html_e( 'نسبة العمولة المخصصة للبراند (%)', 'masa-geo-pricing' ); ?></label>
			</th>
			<td>
				<input type="number" step="0.01" min="0" max="100" name="masa_brand_commission_rate" id="masa_brand_commission_rate" value="<?php echo esc_attr( $brand_rate ); ?>" style="width: 120px;" />
				<p class="description"><?php esc_html_e( 'حدد نسبة عمولة مخصصة لمبيعات هذا البراند تحديداً. في حال تركت فارغة سيتم تطبيق نسبة المسوق المخصصة أو نسبة الموقع الافتراضية.', 'masa-geo-pricing' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * حفظ حقول البراند
	 */
	public function save_brand_manager_field( $term_id, $tt_id ): void {
		if ( ! isset( $_POST['masa_brand_manager_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['masa_brand_manager_nonce'] ) ), 'masa_brand_manager_nonce' ) ) {
			return;
		}

		if ( isset( $_POST['masa_brand_manager_id'] ) ) {
			$manager_id = absint( $_POST['masa_brand_manager_id'] );
			if ( $manager_id > 0 ) {
				update_term_meta( $term_id, 'masa_brand_manager_id', $manager_id );
			} else {
				delete_term_meta( $term_id, 'masa_brand_manager_id' );
			}
		}

		if ( isset( $_POST['masa_brand_commission_rate'] ) ) {
			$rate = $_POST['masa_brand_commission_rate'];
			if ( $rate === '' ) {
				delete_term_meta( $term_id, 'masa_brand_commission_rate' );
			} else {
				update_term_meta( $term_id, 'masa_brand_commission_rate', floatval( $rate ) );
			}
		}
	}

	/**
	 * توليد كود إحالة عشوائي فريد ومميز
	 */
	public static function generate_unique_affiliate_code(): string {
		$code = strtoupper( wp_generate_password( 8, false, false ) );
		
		// التحقق من أن هذا الكود غير مستخدم لدى أي مستخدم آخر (الاحتمال ضعيف جداً)
		$user_query = new WP_User_Query( [
			'meta_key'   => 'masa_affiliate_code',
			'meta_value' => $code,
			'number'     => 1,
		] );
		
		if ( ! empty( $user_query->get_results() ) ) {
			$code .= wp_rand( 10, 99 );
		}

		return $code;
	}

	/**
	 * الحصول على معرف المستخدم بناءً على كود الإحالة
	 */
	public static function get_affiliate_by_code( string $code ): int {
		if ( empty( $code ) ) {
			return 0;
		}

		$user_query = new WP_User_Query( [
			'meta_key'   => 'masa_affiliate_code',
			'meta_value' => sanitize_text_field( $code ),
			'number'     => 1,
			'fields'     => 'ID',
		] );

		$results = $user_query->get_results();
		return ! empty( $results ) ? (int) $results[0] : 0;
	}

	/**
	 * الحصول على كود الإحالة للمستخدم، وتوليد كود له إن لم يكن يملك كوداً
	 */
	public static function get_affiliate_code_by_user_id( int $user_id ): string {
		$code = get_user_meta( $user_id, 'masa_affiliate_code', true );
		if ( empty( $code ) ) {
			$code = self::generate_unique_affiliate_code();
			update_user_meta( $user_id, 'masa_affiliate_code', $code );
		}
		return $code;
	}

	/**
	 * عرض حقول الأفلييت في صفحة الملف الشخصي للمستخدم
	 */
	public function show_affiliate_fields_in_profile( $user ): void {
		// إظهار الحقول فقط للآدمن، أو للمسوق نفسه ليرى كوده
		if ( ! current_user_can( 'manage_options' ) && get_current_user_id() !== $user->ID ) {
			return;
		}

		// جلب الكود الحالي
		$code = get_user_meta( $user->ID, 'masa_affiliate_code', true );
		if ( empty( $code ) && ( in_array( self::ROLE_AFFILIATE, $user->roles, true ) || in_array( self::ROLE_BRAND_MANAGER, $user->roles, true ) ) ) {
			$code = self::get_affiliate_code_by_user_id( $user->ID );
		}

		// جلب نسبة العمولات المخصصة للمسوق (لو فارغة تستخدم النسبة الافتراضية للموقع)
		$custom_rate = get_user_meta( $user->ID, 'masa_affiliate_commission_rate', true );
		
		// جلب بيانات الدفع والسحب
		$payment_method  = get_user_meta( $user->ID, '_masa_affiliate_payment_method', true );
		$payment_details = get_user_meta( $user->ID, '_masa_affiliate_payment_details', true );
		?>
		<h3><?php esc_html_e( 'إعدادات نظام التسويق والبراندات (Masa Affiliates)', 'masa-geo-pricing' ); ?></h3>
		<table class="form-table">
			<tr>
				<th><label for="masa_affiliate_code"><?php esc_html_e( 'كود الإحالة الخاص بك (Referral Code)', 'masa-geo-pricing' ); ?></label></th>
				<td>
					<input type="text" name="masa_affiliate_code" id="masa_affiliate_code" value="<?php echo esc_attr( $code ); ?>" class="regular-text" <?php disabled( ! current_user_can( 'manage_options' ) ); ?> />
					<p class="description">
						<?php esc_html_e( 'كود عشوائي مميز يتم استخدامه لتتبع الإحالات من خلال الروابط.', 'masa-geo-pricing' ); ?>
						<strong><?php echo esc_html( home_url( '/?ref=' . $code ) ); ?></strong>
					</p>
				</td>
			</tr>
			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<tr>
					<th><label for="masa_affiliate_commission_rate"><?php esc_html_e( 'نسبة العمولة المخصصة (%)', 'masa-geo-pricing' ); ?></label></th>
					<td>
						<input type="number" name="masa_affiliate_commission_rate" id="masa_affiliate_commission_rate" value="<?php echo esc_attr( $custom_rate ); ?>" step="0.01" min="0" max="100" class="small-text" />
						<p class="description"><?php esc_html_e( 'اترك الحقل فارغاً لاستخدام النسبة الافتراضية للموقع المحددة في الإعدادات العامة.', 'masa-geo-pricing' ); ?></p>
					</td>
				</tr>
			<?php endif; ?>
			<tr>
				<th><label for="masa_affiliate_payment_method"><?php esc_html_e( 'طريقة الدفع المفضلة', 'masa-geo-pricing' ); ?></label></th>
				<td>
					<select name="masa_affiliate_payment_method" id="masa_affiliate_payment_method">
						<option value=""><?php esc_html_e( '— اختر طريقة الدفع —', 'masa-geo-pricing' ); ?></option>
						<option value="vodafone" <?php selected( $payment_method, 'vodafone' ); ?>><?php esc_html_e( 'فودافون كاش (Vodafone Cash)', 'masa-geo-pricing' ); ?></option>
						<option value="bank" <?php selected( $payment_method, 'bank' ); ?>><?php esc_html_e( 'تحويل بنكي (Bank Transfer)', 'masa-geo-pricing' ); ?></option>
						<option value="paypal" <?php selected( $payment_method, 'paypal' ); ?>><?php esc_html_e( 'بايبال (PayPal)', 'masa-geo-pricing' ); ?></option>
						<option value="instapay" <?php selected( $payment_method, 'instapay' ); ?>><?php esc_html_e( 'إنستاباي (InstaPay)', 'masa-geo-pricing' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="masa_affiliate_payment_details"><?php esc_html_e( 'تفاصيل حساب الدفع', 'masa-geo-pricing' ); ?></label></th>
				<td>
					<textarea name="masa_affiliate_payment_details" id="masa_affiliate_payment_details" rows="3" class="regular-text" placeholder="<?php esc_attr_e( 'أدخل رقم المحفظة، أو رقم الحساب البنكي والآيبان، أو بريد بايبال...', 'masa-geo-pricing' ); ?>"><?php echo esc_textarea( $payment_details ); ?></textarea>
					<p class="description"><?php esc_html_e( 'اكتب تفاصيل الحساب بدقة ليتمكن التاجر من تحويل مستحقاتك يدوياً.', 'masa-geo-pricing' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * حفظ كود الإحالة ونسبة العمولة للمستخدم
	 */
	public function save_user_affiliate_code( $user_id ): void {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		// حفظ كود الإحالة (فقط للأدمن)
		if ( current_user_can( 'manage_options' ) && isset( $_POST['masa_affiliate_code'] ) ) {
			$code = sanitize_text_field( $_POST['masa_affiliate_code'] );
			if ( ! empty( $code ) ) {
				// التحقق من أن الكود غير مستخدم لدى مستخدم آخر
				$existing = self::get_affiliate_by_code( $code );
				if ( $existing && $existing !== $user_id ) {
					// كود مكرّر — لا نحدث، وسنعرض تحذيراً في الـ profile
					add_action( 'admin_notices', function () use ( $code ) {
						printf(
							'<div class="notice notice-error"><p>%s</p></div>',
							esc_html( sprintf(
								/* translators: %s: كود الإحالة المكرّر */
								__( '⚠️ كود الإحالة "%s" مُسجل بالفعل لمسوق آخر. لم يتم الحفظ.', 'masa-geo-pricing' ),
								$code
							) )
						);
					} );
				} else {
					update_user_meta( $user_id, 'masa_affiliate_code', $code );
				}
			}
		}

		// حفظ نسبة العمولة المخصصة (فقط للأدمن)
		if ( current_user_can( 'manage_options' ) && isset( $_POST['masa_affiliate_commission_rate'] ) ) {
			$rate = $_POST['masa_affiliate_commission_rate'];
			if ( $rate === '' ) {
				delete_user_meta( $user_id, 'masa_affiliate_commission_rate' );
			} else {
				update_user_meta( $user_id, 'masa_affiliate_commission_rate', floatval( $rate ) );
			}
		}

		// حفظ طريقة وتفاصيل الدفع (للأدمن وللمستخدم نفسه)
		if ( isset( $_POST['masa_affiliate_payment_method'] ) ) {
			update_user_meta( $user_id, '_masa_affiliate_payment_method', sanitize_text_field( $_POST['masa_affiliate_payment_method'] ) );
		}
		if ( isset( $_POST['masa_affiliate_payment_details'] ) ) {
			update_user_meta( $user_id, '_masa_affiliate_payment_details', sanitize_textarea_field( $_POST['masa_affiliate_payment_details'] ) );
		}
	}

	/**
	 * إضافة حقل عمولة المسوق المخصصة في صفحة تعديل المنتج في ووكومرس (General Tab)
	 */
	public function add_product_commission_rate_field(): void {
		echo '<div class="options_group">';
		woocommerce_wp_text_input( [
			'id'          => '_masa_commission_rate',
			'label'       => esc_html__( 'عمولة المسوق المخصصة (%)', 'masa-geo-pricing' ),
			'placeholder' => esc_html__( 'تطبق إعدادات البراند أو المسوق أو الموقع', 'masa-geo-pricing' ),
			'desc_tip'    => 'true',
			'description' => esc_html__( 'حدد نسبة عمولة مخصصة لهذا المنتج تحديداً لتتخطي نسبة البراند، نسبة المسوق، والنسبة العامة للموقع.', 'masa-geo-pricing' ),
			'type'        => 'number',
			'custom_attributes' => [
				'step' => '0.01',
				'min'  => '0',
				'max'  => '100',
			],
		] );
		echo '</div>';
	}

	/**
	 * حفظ حقل عمولة المسوق المخصصة للمنتج
	 */
	public function save_product_commission_rate_field( int $product_id ): void {
		if ( ! current_user_can( 'edit_product', $product_id ) ) {
			return;
		}

		if ( isset( $_POST['_masa_commission_rate'] ) ) {
			$rate = $_POST['_masa_commission_rate'];
			if ( $rate === '' ) {
				delete_post_meta( $product_id, '_masa_commission_rate' );
			} else {
				update_post_meta( $product_id, '_masa_commission_rate', floatval( $rate ) );
			}
		}
	}

	/**
	 * جيب البراندات المرتبطة بمعرف مستخدم معين
	 *
	 * @param int $user_id
	 * @return int[] مصفوفة من الـ Term IDs للبراندات المرتبطة
	 */
	public static function get_user_brands( int $user_id ): array {
		$brand_ids = [];
		foreach ( self::BRAND_TAXONOMIES as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			$terms = get_terms( [
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'meta_query' => [
					[
						'key'   => 'masa_brand_manager_id',
						'value' => $user_id,
					]
				]
			] );

			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				foreach ( $terms as $term ) {
					$brand_ids[] = (int) $term->term_id;
				}
			}
		}
		return $brand_ids;
	}
}
