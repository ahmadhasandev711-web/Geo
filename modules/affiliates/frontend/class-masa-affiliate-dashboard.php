<?php
defined( 'ABSPATH' ) || exit;

/**
 * Masa_Geo_Affiliate_Dashboard
 * مسؤول عن عرض لوحة التحكم الأمامية للمسوقين والبراندات عبر Shortcode
 */
final class Masa_Geo_Affiliate_Dashboard {

	private static ?Masa_Geo_Affiliate_Dashboard $instance = null;

	public static function instance(): Masa_Geo_Affiliate_Dashboard {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// تسجيل الـ Shortcode
		add_shortcode( 'masa_affiliate_dashboard', [ $this, 'render_dashboard' ] );
		// تحميل الـ CSS الخارجي عند استخدام الـ Shortcode
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	public function enqueue_assets(): void {
		wp_enqueue_style( 'masa-geo-frontend', MASA_GEO_PLUGIN_URL . 'assets/frontend.css', [], MASA_GEO_VERSION );
	}

	/**
	 * عرض لوحة التحكم الأمامية
	 */
	public function render_dashboard(): string {
		if ( ! is_user_logged_in() ) {
			return sprintf(
				'<div class="masa-affiliate-alert">%s <a href="%s">%s</a></div>',
				esc_html__( 'يرجى تسجيل الدخول أولاً لعرض لوحة تحكم الشركاء والمسوقين.', 'masa-geo-pricing' ),
				esc_url( wp_login_url( get_permalink() ) ),
				esc_html__( 'تسجيل الدخول من هنا', 'masa-geo-pricing' )
			);
		}

		$user_id = get_current_user_id();
		$user    = get_userdata( $user_id );
		if ( ! $user ) {
			return '';
		}

		$is_brand_manager = in_array( Masa_Geo_Affiliate_Core::ROLE_BRAND_MANAGER, $user->roles, true );
		$is_affiliate     = in_array( Masa_Geo_Affiliate_Core::ROLE_AFFILIATE, $user->roles, true ) || in_array( 'administrator', $user->roles, true );

		if ( ! $is_brand_manager && ! $is_affiliate ) {
			return sprintf(
				'<div class="masa-affiliate-alert">%s</div>',
				esc_html__( 'عذراً، هذا الحساب غير مسجل كشريك أو مسوق في الموقع حالياً.', 'masa-geo-pricing' )
			);
		}

		// جلب كود الإحالة الخاص بالمستخدم
		$ref_code = Masa_Geo_Affiliate_Core::get_affiliate_code_by_user_id( $user_id );
		
		// معالجة تحديث بيانات الدفع
		$message = '';
		$message_type = '';
		if ( isset( $_POST['masa_affiliate_update_payment'] ) && check_admin_referer( 'masa_affiliate_update_payment_action', 'masa_payment_nonce' ) ) {
			$method  = sanitize_text_field( $_POST['masa_affiliate_payment_method'] ?? '' );
			$details = sanitize_textarea_field( $_POST['masa_affiliate_payment_details'] ?? '' );

			update_user_meta( $user_id, '_masa_affiliate_payment_method', $method );
			update_user_meta( $user_id, '_masa_affiliate_payment_details', $details );

			$message = esc_html__( 'تم حفظ بيانات السحب والدفع بنجاح.', 'masa-geo-pricing' );
			$message_type = 'success';
		}

		$payment_method  = get_user_meta( $user_id, '_masa_affiliate_payment_method', true );
		$payment_details = get_user_meta( $user_id, '_masa_affiliate_payment_details', true );
		
		// جلب إحصائيات الأرباح من قاعدة البيانات
		global $wpdb;
		$table_name = Masa_Geo_Affiliate_Core::table_name();
		$base_currency = get_option( 'woocommerce_currency' );

		// إجمالي الأرباح المعتمدة (المدفوعة)
		$paid_earnings = (float) $wpdb->get_var( $wpdb->prepare(
			"SELECT SUM(commission_amount) FROM {$table_name} WHERE affiliate_id = %d AND status = 'paid'",
			$user_id
		) );

		// إجمالي الأرباح المعلقة
		$pending_earnings = (float) $wpdb->get_var( $wpdb->prepare(
			"SELECT SUM(commission_amount) FROM {$table_name} WHERE affiliate_id = %d AND status = 'pending'",
			$user_id
		) );

		// عدد الطلبات الناجحة
		$total_orders = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table_name} WHERE affiliate_id = %d AND status IN ('pending', 'paid')",
			$user_id
		) );

		// جلب آخر 15 عملية عمولة مسجلة للمسوق
		$conversions = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table_name} WHERE affiliate_id = %d ORDER BY id DESC LIMIT 15",
			$user_id
		) );

		// توليد روابط الإحالة
		$referral_links = [];
		if ( $is_brand_manager ) {
			// إذا كان مدير براند، نجيب روابط البراندات اللي ماسكها
			$brand_ids = Masa_Geo_Affiliate_Core::get_user_brands( $user_id );
			foreach ( $brand_ids as $brand_id ) {
				$term = get_term( $brand_id );
				if ( $term && ! is_wp_error( $term ) ) {
					$term_link = get_term_link( $term );
					if ( ! is_wp_error( $term_link ) ) {
						$referral_links[] = [
							'name' => $term->name,
							'url'  => add_query_arg( 'ref', $ref_code, $term_link ),
						];
					}
				}
			}
		} else {
			// مسوق عام: رابط الصفحة الرئيسية للموقع مع كود الإحالة
			$referral_links[] = [
				'name' => esc_html__( 'رابط إحالة عام للموقع', 'masa-geo-pricing' ),
				'url'  => add_query_arg( 'ref', $ref_code, home_url( '/' ) ),
			];
		}

		ob_start();
		?>
		<div class="masa-dashboard-container" dir="rtl">

			<?php if ( ! empty( $message ) ) : ?>
				<div class="masa-affiliate-alert alert-success" style="margin-bottom: 1.5rem; background: #d1fae5; border: 1px solid #6ee7b7; color: #065f46; padding: 12px; border-radius: 6px; font-weight: 600;">
					<?php echo esc_html( $message ); ?>
				</div>
			<?php endif; ?>

			<!-- الهيدر والترحيب -->
			<div class="masa-dashboard-header">
				<div class="masa-welcome-area">
					<h2><?php printf( esc_html__( 'أهلاً بك، %s 👋', 'masa-geo-pricing' ), esc_html( $user->display_name ) ); ?></h2>
					<p>
						<?php if ( $is_brand_manager ) : ?>
							<span class="masa-badge badge-brand"><?php esc_html_e( 'حساب شريك / براند', 'masa-geo-pricing' ); ?></span>
						<?php else : ?>
							<span class="masa-badge badge-affiliate"><?php esc_html_e( 'حساب مسوق بالعمولة', 'masa-geo-pricing' ); ?></span>
						<?php endif; ?>
					</p>
				</div>
				<div class="masa-code-display">
					<span class="label"><?php esc_html_e( 'كود الإحالة الخاص بك:', 'masa-geo-pricing' ); ?></span>
					<strong class="code"><?php echo esc_html( $ref_code ); ?></strong>
				</div>
			</div>

			<!-- كروت الإحصائيات -->
			<div class="masa-stats-grid">
				<div class="masa-stat-card card-pending">
					<div class="card-icon">⏳</div>
					<div class="card-info">
						<h3><?php esc_html_e( 'أرباح معلقة', 'masa-geo-pricing' ); ?></h3>
						<p class="amount"><?php echo wp_kses_post( wc_price( $pending_earnings, [ 'currency' => $base_currency ] ) ); ?></p>
						<span class="subtext"><?php esc_html_e( 'بانتظار تأكيد الإدارة وتحويلها', 'masa-geo-pricing' ); ?></span>
					</div>
				</div>

				<div class="masa-stat-card card-paid">
					<div class="card-icon">✅</div>
					<div class="card-info">
						<h3><?php esc_html_e( 'أرباح مستلمة', 'masa-geo-pricing' ); ?></h3>
						<p class="amount"><?php echo wp_kses_post( wc_price( $paid_earnings, [ 'currency' => $base_currency ] ) ); ?></p>
						<span class="subtext"><?php esc_html_e( 'تم إرسالها لحسابك بنجاح', 'masa-geo-pricing' ); ?></span>
					</div>
				</div>

				<div class="masa-stat-card card-total">
					<div class="card-icon">📈</div>
					<div class="card-info">
						<h3><?php esc_html_e( 'إجمالي المبيعات الناجحة', 'masa-geo-pricing' ); ?></h3>
						<p class="amount" style="color: #0f172a;"><?php echo esc_html( $total_orders ); ?></p>
						<span class="subtext"><?php esc_html_e( 'عدد الطلبات التي تمت من خلالك', 'masa-geo-pricing' ); ?></span>
					</div>
				</div>
			</div>

			<!-- روابط الإحالة الخاصة بك -->
			<div class="masa-section-card">
				<h3 class="section-title">🔗 <?php esc_html_e( 'روابط الإحالة الخاصة بك والتسويق', 'masa-geo-pricing' ); ?></h3>
				<p class="section-desc">
					<?php if ( $is_brand_manager ) : ?>
						<?php esc_html_e( 'شارك الروابط التالية مع زبائنك لبيع منتجات البراند الخاص بك مباشرة وحصد العمولات تلقائياً.', 'masa-geo-pricing' ); ?>
					<?php else : ?>
						<?php esc_html_e( 'انسخ الرابط التالي وشاركه لربط الزبائن بحسابك عند دخولهم للمتجر للشراء.', 'masa-geo-pricing' ); ?>
					<?php endif; ?>
				</p>

				<div class="masa-links-list">
					<?php foreach ( $referral_links as $link ) : ?>
						<div class="masa-link-item">
							<span class="link-name"><?php echo esc_html( $link['name'] ); ?></span>
							<div class="link-input-group">
								<input type="text" readonly value="<?php echo esc_url( $link['url'] ); ?>" class="link-url-input" onclick="this.select();" />
								<button class="copy-btn" onclick="navigator.clipboard.writeText('<?php echo esc_js( $link['url'] ); ?>'); alert('<?php esc_attr_e( 'تم نسخ الرابط بنجاح!', 'masa-geo-pricing' ); ?>');"><?php esc_html_e( 'نسخ الرابط', 'masa-geo-pricing' ); ?></button>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>

			<!-- 💳 بيانات السحب والدفع المفضلة -->
			<div class="masa-section-card">
				<h3 class="section-title">💳 <?php esc_html_e( 'بيانات السحب والدفع المفضلة', 'masa-geo-pricing' ); ?></h3>
				<p class="section-desc">
					<?php esc_html_e( 'حدد طريقة الدفع المفضلة لديك واكتب تفاصيل الحساب بدقة ليتمكن التاجر من تحويل مستحقاتك يدوياً.', 'masa-geo-pricing' ); ?>
				</p>

				<form method="post" action="">
					<?php wp_nonce_field( 'masa_affiliate_update_payment_action', 'masa_payment_nonce' ); ?>
					
					<div style="margin-bottom: 15px;">
						<label for="masa_affiliate_payment_method" style="display: block; font-weight: 600; margin-bottom: 5px; color: #475569; font-size: 0.9rem;"><?php esc_html_e( 'طريقة الدفع:', 'masa-geo-pricing' ); ?></label>
						<select name="masa_affiliate_payment_method" id="masa_affiliate_payment_method" style="width: 100%; max-width: 400px; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.9rem; outline: none; background-color: #fff; box-sizing: border-box;">
							<option value=""><?php esc_html_e( '— اختر طريقة الدفع —', 'masa-geo-pricing' ); ?></option>
							<option value="vodafone" <?php selected( $payment_method, 'vodafone' ); ?>><?php esc_html_e( 'فودافون كاش (Vodafone Cash)', 'masa-geo-pricing' ); ?></option>
							<option value="bank" <?php selected( $payment_method, 'bank' ); ?>><?php esc_html_e( 'تحويل بنكي (Bank Transfer)', 'masa-geo-pricing' ); ?></option>
							<option value="paypal" <?php selected( $payment_method, 'paypal' ); ?>><?php esc_html_e( 'بايبال (PayPal)', 'masa-geo-pricing' ); ?></option>
							<option value="instapay" <?php selected( $payment_method, 'instapay' ); ?>><?php esc_html_e( 'إنستاباي (InstaPay)', 'masa-geo-pricing' ); ?></option>
						</select>
					</div>

					<div style="margin-bottom: 20px;">
						<label for="masa_affiliate_payment_details" style="display: block; font-weight: 600; margin-bottom: 5px; color: #475569; font-size: 0.9rem;"><?php esc_html_e( 'تفاصيل حساب الدفع:', 'masa-geo-pricing' ); ?></label>
						<textarea name="masa_affiliate_payment_details" id="masa_affiliate_payment_details" rows="4" style="width: 100%; max-width: 600px; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.9rem; outline: none; resize: vertical; box-sizing: border-box;" placeholder="<?php esc_attr_e( 'رقم الهاتف للمحفظة، أو رقم الحساب البنكي والآيبان، أو البريد الإلكتروني لحساب بايبال، إلخ...', 'masa-geo-pricing' ); ?>"><?php echo esc_textarea( $payment_details ); ?></textarea>
					</div>

					<button type="submit" name="masa_affiliate_update_payment" class="button" style="background: #3b82f6; color: #fff; padding: 10px 20px; border: none; border-radius: 6px; cursor: pointer; font-weight: 700; font-size: 0.9rem; transition: background 0.2s; box-shadow: none;">
						<?php esc_html_e( 'حفظ بيانات الدفع 💾', 'masa-geo-pricing' ); ?>
					</button>
				</form>
			</div>

			<!-- سجل المبيعات والعمولات الأخيرة -->
			<div class="masa-section-card">
				<h3 class="section-title">📊 <?php esc_html_e( 'آخر عمليات البيع والعمولات', 'masa-geo-pricing' ); ?></h3>
				
				<div class="table-responsive">
					<table class="masa-dashboard-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'رقم الطلب', 'masa-geo-pricing' ); ?></th>
								<th><?php esc_html_e( 'قيمة الطلب الأصلية', 'masa-geo-pricing' ); ?></th>
								<th><?php esc_html_e( 'عمولتك المقدرة', 'masa-geo-pricing' ); ?></th>
								<th><?php esc_html_e( 'الحالة', 'masa-geo-pricing' ); ?></th>
								<th><?php esc_html_e( 'التاريخ', 'masa-geo-pricing' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $conversions ) ) : ?>
								<tr>
									<td colspan="5" class="empty-row"><?php esc_html_e( 'لم يتم تسجيل أي عمليات عمولة لحسابك حتى الآن.', 'masa-geo-pricing' ); ?></td>
								</tr>
							<?php else : ?>
								<?php foreach ( $conversions as $conv ) : ?>
									<tr>
										<td><strong>#<?php echo esc_html( $conv->order_id ); ?></strong></td>
										<td><?php echo wp_kses_post( wc_price( $conv->original_order_total, [ 'currency' => $conv->original_currency ] ) ); ?></td>
										<td class="amount-cell"><?php echo wp_kses_post( wc_price( $conv->commission_amount, [ 'currency' => $conv->currency ] ) ); ?></td>
										<td>
											<?php if ( $conv->status === 'pending' ) : ?>
												<span class="status-badge status-pending"><?php esc_html_e( 'معلقة', 'masa-geo-pricing' ); ?></span>
											<?php elseif ( $conv->status === 'paid' ) : ?>
												<span class="status-badge status-paid"><?php esc_html_e( 'تم الدفع', 'masa-geo-pricing' ); ?></span>
											<?php else : ?>
												<span class="status-badge status-cancelled"><?php esc_html_e( 'ملغاة', 'masa-geo-pricing' ); ?></span>
											<?php endif; ?>
										</td>
										<td class="date-cell"><?php echo esc_html( wp_date( 'Y-m-d H:i', $conv->created_at ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
