<?php
defined( 'ABSPATH' ) || exit;

/**
 * Masa_Geo_Affiliate_Admin
 * لوحة التحكم في العمولات والشركاء لمدير المتجر
 */
final class Masa_Geo_Affiliate_Admin {

	private static ?Masa_Geo_Affiliate_Admin $instance = null;

	const OPTION_KEY = 'masa_affiliate_settings';

	public static function instance(): Masa_Geo_Affiliate_Admin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_init', [ $this, 'handle_commission_actions' ] );
	}

	/**
	 * تسجيل القائمة الفرعية تحت قائمة WooCommerce
	 */
	public function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			esc_html__( 'الشركاء والعمولات', 'masa-geo-pricing' ),
			esc_html__( 'الشركاء والعمولات', 'masa-geo-pricing' ),
			'manage_woocommerce',
			'masa-affiliates',
			[ $this, 'render_page' ]
		);
	}

	/**
	 * تسجيل إعدادات العمولات
	 */
	public function register_settings(): void {
		register_setting(
			'masa_affiliate_settings_group',
			self::OPTION_KEY,
			[ $this, 'sanitize_settings' ]
		);
	}

	/**
	 * تنظيف وحفظ الإعدادات
	 */
	public function sanitize_settings( $input ): array {
		$clean = [];
		$clean['affiliate_default_commission_rate'] = max( 0.0, min( 100.0, floatval( $input['affiliate_default_commission_rate'] ?? 10.0 ) ) );
		$clean['affiliate_cookie_expiry']           = max( 1, absint( $input['affiliate_cookie_expiry'] ?? 30 ) );
		return $clean;
	}

	/**
	 * معالجة الإجراءات اليدوية على العمولات (تغيير الحالة لمدفوعة أو ملغاة)
	 */
	public function handle_commission_actions(): void {
		if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'masa-affiliates' ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';
		$id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		if ( ! $id || ! in_array( $action, [ 'mark_paid', 'mark_pending', 'cancel' ], true ) ) {
			return;
		}

		check_admin_referer( 'masa_affiliate_action_' . $id );

		global $wpdb;
		$table_name = Masa_Geo_Affiliate_Core::table_name();

		$status  = 'pending';
		$paid_at = 0;

		if ( $action === 'mark_paid' ) {
			$status  = 'paid';
			$paid_at = time();
		} elseif ( $action === 'cancel' ) {
			$status = 'cancelled';
		}

		$wpdb->update(
			$table_name,
			[
				'status'  => $status,
				'paid_at' => $paid_at,
			],
			[ 'id' => $id ],
			[ '%s', '%d' ],
			[ '%d' ]
		);

		// إعادة توجيه لمنع تكرار الإجراء عند عمل refresh للمتصفح
		wp_safe_redirect( remove_query_arg( [ 'action', 'id', '_wpnonce' ] ) );
		exit;
	}

	/**
	 * عرض الصفحة الرئيسية لإدارة العمولات والشركاء
	 */
	public function render_page(): void {
		global $wpdb;

		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'commissions';
		$table_name = Masa_Geo_Affiliate_Core::table_name();

		// جلب إعدادات الأفلييت
		$settings = get_option( self::OPTION_KEY, [] );
		$default_rate = $settings['affiliate_default_commission_rate'] ?? 10.0;
		$cookie_expiry = $settings['affiliate_cookie_expiry'] ?? 30;

		?>
		<div class="wrap masa-geo-wrap" dir="rtl" style="margin-top: 20px; font-family: system-ui, -apple-system, sans-serif;">
			<h1 style="font-weight: 700; margin-bottom: 20px; color: #1e293b; display: flex; align-items: center; gap: 8px;">
				<span>🤝</span> <?php esc_html_e( 'إدارة الشركاء والعمولات (Affiliates & Brands)', 'masa-geo-pricing' ); ?>
			</h1>

			<!-- التبويبات -->
			<nav class="nav-tab-wrapper" style="margin-bottom: 20px;">
				<a href="?page=masa-affiliates&tab=commissions" class="nav-tab <?php echo $active_tab === 'commissions' ? 'nav-tab-active' : ''; ?>">
					💵 <?php esc_html_e( 'سجل العمولات والأرباح', 'masa-geo-pricing' ); ?>
				</a>
				<a href="?page=masa-affiliates&tab=settings" class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
					⚙️ <?php esc_html_e( 'إعدادات العمولات', 'masa-geo-pricing' ); ?>
				</a>
			</nav>

			<?php if ( $active_tab === 'commissions' ) : 
				// Pagination
				$per_page     = 20;
				$current_page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
				$offset       = ( $current_page - 1 ) * $per_page;

				// فلترة حسب المسوق
				$filter_user_id = isset( $_GET['affiliate_filter'] ) ? absint( $_GET['affiliate_filter'] ) : 0;
				
				$where = '1=1';
				$params = [];
				
				if ( $filter_user_id ) {
					$where .= ' AND affiliate_id = %d';
					$params[] = $filter_user_id;
				}

				// جلب إجمالي السجلات والبيانات
				$total_items = $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$table_name} WHERE {$where}",
					...$params
				) );

				$query = "SELECT * FROM {$table_name} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
				$query_params = array_merge( $params, [ $per_page, $offset ] );
				$commissions = $wpdb->get_results( $wpdb->prepare( $query, ...$query_params ) );

				$total_pages = ceil( $total_items / $per_page );

				// جلب قائمة المسوقين للفلترة
				$affiliates_list = get_users( [
					'role__in' => [ Masa_Geo_Affiliate_Core::ROLE_AFFILIATE, Masa_Geo_Affiliate_Core::ROLE_BRAND_MANAGER, 'administrator' ],
					'orderby'  => 'display_name',
					'order'    => 'ASC',
				] );
				?>

				<div class="tab-content" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border: 1px solid #e2e8f0;">
					
					<!-- الفلترة -->
					<form method="get" action="" style="margin-bottom: 20px; display: flex; gap: 10px; align-items: center; background: #f8fafc; padding: 15px; border-radius: 6px;">
						<input type="hidden" name="page" value="masa-affiliates" />
						<input type="hidden" name="tab" value="commissions" />
						<label for="affiliate_filter" style="font-weight: 600; color: #475569;"><?php esc_html_e( 'فلترة حسب المسوق:', 'masa-geo-pricing' ); ?></label>
						<select name="affiliate_filter" id="affiliate_filter" style="min-width: 200px; padding: 6px; border-radius: 4px; border: 1px solid #cbd5e1;">
							<option value=""><?php esc_html_e( 'عرض الجميع', 'masa-geo-pricing' ); ?></option>
							<?php foreach ( $affiliates_list as $aff ) : ?>
								<option value="<?php echo esc_attr( $aff->ID ); ?>" <?php selected( $filter_user_id, $aff->ID ); ?>>
									<?php echo esc_html( $aff->display_name . ' (' . $aff->user_email . ')' ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'تطبيق الفلترة', 'masa-geo-pricing' ); ?></button>
						<?php if ( $filter_user_id ) : ?>
							<a href="?page=masa-affiliates&tab=commissions" class="button button-secondary"><?php esc_html_e( 'إعادة تعيين', 'masa-geo-pricing' ); ?></a>
						<?php endif; ?>
					</form>

					<!-- جدول العمولات -->
					<table class="wp-list-table widefat fixed striped table-view-list" style="border: 1px solid #e2e8f0; border-radius: 6px; overflow: hidden;">
						<thead>
							<tr>
								<th style="padding: 12px; font-weight: 700; width: 60px;"><?php esc_html_e( 'مستند', 'masa-geo-pricing' ); ?></th>
								<th style="padding: 12px; font-weight: 700;"><?php esc_html_e( 'المسوق / الشريك', 'masa-geo-pricing' ); ?></th>
								<th style="padding: 12px; font-weight: 700;"><?php esc_html_e( 'الكود', 'masa-geo-pricing' ); ?></th>
								<th style="padding: 12px; font-weight: 700;"><?php esc_html_e( 'رقم الطلب', 'masa-geo-pricing' ); ?></th>
								<th style="padding: 12px; font-weight: 700;"><?php esc_html_e( 'قيمة الطلب الأصلية', 'masa-geo-pricing' ); ?></th>
								<th style="padding: 12px; font-weight: 700;"><?php esc_html_e( 'قيمة العمولة المقدرة', 'masa-geo-pricing' ); ?></th>
								<th style="padding: 12px; font-weight: 700;"><?php esc_html_e( 'الحالة', 'masa-geo-pricing' ); ?></th>
								<th style="padding: 12px; font-weight: 700;"><?php esc_html_e( 'التاريخ', 'masa-geo-pricing' ); ?></th>
								<th style="padding: 12px; font-weight: 700; width: 220px;"><?php esc_html_e( 'إجراءات الدفع', 'masa-geo-pricing' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $commissions ) ) : ?>
								<tr>
									<td colspan="9" style="text-align: center; padding: 20px; color: #64748b;"><?php esc_html_e( 'لا توجد عمولات مسجلة حتى الآن.', 'masa-geo-pricing' ); ?></td>
								</tr>
							<?php else : ?>
								<?php foreach ( $commissions as $comm ) : 
									$user_data = get_userdata( $comm->affiliate_id );
									$order_edit_url = admin_url( 'post.php?post=' . $comm->order_id . '&action=edit' );
									// إذا كان يدعم HPOS
									if ( class_exists( 'Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore' ) ) {
										$order_edit_url = admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $comm->order_id );
									}
									?>
									<tr>
										<td style="padding: 12px;">#<?php echo esc_html( $comm->id ); ?></td>
										<td style="padding: 12px;">
											<strong>
												<?php if ( $user_data ) : ?>
													<a href="<?php echo esc_url( get_edit_user_link( $comm->affiliate_id ) ); ?>">
														<?php echo esc_html( $user_data->display_name ); ?>
													</a>
													<?php
													$method  = get_user_meta( $comm->affiliate_id, '_masa_affiliate_payment_method', true );
													$details = get_user_meta( $comm->affiliate_id, '_masa_affiliate_payment_details', true );
													if ( $method ) :
														$method_labels = [
															'vodafone' => __( 'فودافون كاش', 'masa-geo-pricing' ),
															'bank'     => __( 'تحويل بنكي', 'masa-geo-pricing' ),
															'paypal'   => __( 'بايبال', 'masa-geo-pricing' ),
															'instapay' => __( 'إنستاباي', 'masa-geo-pricing' ),
														];
														$method_label = $method_labels[ $method ] ?? $method;
														?>
														<div style="font-size: 11px; color: #475569; margin-top: 4px; background: #f1f5f9; padding: 4px 8px; border-radius: 4px; border: 1px solid #e2e8f0; word-break: break-all;">
															<strong>💳 <?php echo esc_html( $method_label ); ?>:</strong> <br>
															<span style="user-select: all; font-family: monospace;"><?php echo esc_html( $details ); ?></span>
														</div>
													<?php endif; ?>
												<?php else : ?>
													<span style="color: #94a3b8;"><?php esc_html_e( 'مستخدم محذوف', 'masa-geo-pricing' ); ?></span>
												<?php endif; ?>
											</strong>
										</td>
										<td style="padding: 12px;"><code><?php echo esc_html( $comm->affiliate_code ); ?></code></td>
										<td style="padding: 12px;">
											<a href="<?php echo esc_url( $order_edit_url ); ?>" target="_blank" style="font-weight: 600;">
												#<?php echo esc_html( $comm->order_id ); ?> ↗
											</a>
										</td>
										<td style="padding: 12px;"><?php echo wp_kses_post( wc_price( $comm->original_order_total, [ 'currency' => $comm->original_currency ] ) ); ?></td>
										<td style="padding: 12px; color: #16a34a; font-weight: 600;"><?php echo wp_kses_post( wc_price( $comm->commission_amount, [ 'currency' => $comm->currency ] ) ); ?></td>
										<td style="padding: 12px;">
											<?php if ( $comm->status === 'pending' ) : ?>
												<span style="background: #fef3c7; color: #d97706; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;"><?php esc_html_e( 'معلقة', 'masa-geo-pricing' ); ?></span>
											<?php elseif ( $comm->status === 'paid' ) : ?>
												<span style="background: #dcfce7; color: #16a34a; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;"><?php esc_html_e( 'تم الدفع', 'masa-geo-pricing' ); ?></span>
											<?php else : ?>
												<span style="background: #fee2e2; color: #dc2626; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;"><?php esc_html_e( 'ملغاة', 'masa-geo-pricing' ); ?></span>
											<?php endif; ?>
										</td>
										<td style="padding: 12px; font-size: 12px; color: #64748b;"><?php echo esc_html( wp_date( 'Y-m-d H:i', $comm->created_at ) ); ?></td>
										<td style="padding: 12px; display: flex; gap: 8px;">
											<?php if ( $comm->status === 'pending' ) : ?>
												<a href="<?php echo esc_url( wp_nonce_url( '?page=masa-affiliates&action=mark_paid&id=' . $comm->id, 'masa_affiliate_action_' . $comm->id ) ); ?>" class="button button-small button-primary" style="background-color: #16a34a; border-color: #16a34a;">
													✓ <?php esc_html_e( 'تعليم كمدفوع', 'masa-geo-pricing' ); ?>
												</a>
												<a href="<?php echo esc_url( wp_nonce_url( '?page=masa-affiliates&action=cancel&id=' . $comm->id, 'masa_affiliate_action_' . $comm->id ) ); ?>" class="button button-small button-link-delete" style="color: #dc2626; text-decoration: none;" onclick="return confirm('هل أنت متأكد من إلغاء هذه العمولة؟');">
													✕ <?php esc_html_e( 'إلغاء', 'masa-geo-pricing' ); ?>
												</a>
											<?php elseif ( $comm->status === 'paid' ) : ?>
												<small style="color: #64748b;">
													<?php esc_html_e( 'دُفعت في:', 'masa-geo-pricing' ); ?> <br>
													<strong><?php echo esc_html( wp_date( 'Y-m-d H:i', $comm->paid_at ) ); ?></strong>
												</small>
											<?php else : ?>
												<span style="color: #94a3b8; font-size: 12px;"><?php esc_html_e( 'لا توجد إجراءات متاحة', 'masa-geo-pricing' ); ?></span>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>

					<!-- Pagination Links -->
					<?php if ( $total_pages > 1 ) : ?>
						<div class="tablenav" style="margin-top: 15px;">
							<div class="tablenav-pages" style="float: right;">
								<span class="displaying-num"><?php printf( esc_html__( '%d عمولات', 'masa-geo-pricing' ), $total_items ); ?></span>
								<?php
								echo paginate_links( [
									'base'      => add_query_arg( 'paged', '%#%' ),
									'format'    => '',
									'prev_text' => '&laquo; السابق',
									'next_text' => 'التالي &raquo;',
									'total'     => $total_pages,
									'current'   => $current_page,
								] );
								?>
							</div>
						</div>
					<?php endif; ?>
				</div>

			<?php elseif ( $active_tab === 'settings' ) : ?>
				
				<div class="tab-content" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border: 1px solid #e2e8f0; max-width: 800px;">
					<form method="post" action="options.php">
						<?php settings_fields( 'masa_affiliate_settings_group' ); ?>
						
						<h3 style="font-weight: 600; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px; margin-bottom: 20px; color: #1e293b;">
							⚙️ <?php esc_html_e( 'إعدادات نظام العمولات العامة', 'masa-geo-pricing' ); ?>
						</h3>

						<table class="form-table">
							<tr>
								<th scope="row" style="width: 250px; font-weight: 600; color: #475569;">
									<label for="affiliate_default_commission_rate"><?php esc_html_e( 'نسبة العمولة الافتراضية (%)', 'masa-geo-pricing' ); ?></label>
								</th>
								<td>
									<input type="number" step="0.01" min="0" max="100" 
										name="masa_affiliate_settings[affiliate_default_commission_rate]" 
										id="affiliate_default_commission_rate" 
										value="<?php echo esc_attr( $default_rate ); ?>" 
										class="small-text" style="height: 30px;" />
									<p class="description" style="margin-top: 5px;"><?php esc_html_e( 'النسبة المئوية التي يحصل عليها الشريك/المسوق من إجمالي المبيعات المؤهلة، إذا لم تحدد له نسبة خاصة في ملفه الشخصي.', 'masa-geo-pricing' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row" style="width: 250px; font-weight: 600; color: #475569;">
									<label for="affiliate_cookie_expiry"><?php esc_html_e( 'مدة صلاحية التتبع (الأيام)', 'masa-geo-pricing' ); ?></label>
								</th>
								<td>
									<input type="number" min="1" max="365" 
										name="masa_affiliate_settings[affiliate_cookie_expiry]" 
										id="affiliate_cookie_expiry" 
										value="<?php echo esc_attr( $cookie_expiry ); ?>" 
										class="small-text" style="height: 30px;" />
									<p class="description" style="margin-top: 5px;"><?php esc_html_e( 'عدد الأيام التي ستظل فيها ملفات تعريف الارتباط (Cookies) الخاصة بالتتبع نشطة في متصفح الزائر للربط بالمسوق.', 'masa-geo-pricing' ); ?></p>
								</td>
							</tr>
						</table>

						<div style="margin-top: 30px;">
							<?php submit_button( __( 'حفظ إعدادات العمولات', 'masa-geo-pricing' ) ); ?>
						</div>
					</form>
				</div>
				
			<?php endif; ?>
		</div>
		<?php
	}
}
