<?php
defined( 'ABSPATH' ) || exit;

/**
 * Masa_Geo_Visibility_Admin
 * واجهة الأدمن للتحكم في العرض الجغرافي:
 * - عمود "العرض الجغرافي" في قائمة المنتجات
 * - Bulk Edit لتقييد/إتاحة منتجات كتير مرة واحدة
 *
 * (واجهة المنتج المفرد موجودة في نفس تاب التسعير الجغرافي — Masa_Geo_Product)
 */
final class Masa_Geo_Visibility_Admin {

	private static ?Masa_Geo_Visibility_Admin $instance = null;

	public static function instance(): Masa_Geo_Visibility_Admin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// عمود في قائمة المنتجات
		add_filter( 'manage_edit-product_columns',        [ $this, 'add_column' ] );
		add_action( 'manage_product_posts_custom_column', [ $this, 'render_column' ], 10, 2 );

		// Bulk Edit
		add_action( 'woocommerce_product_bulk_edit_end',  [ $this, 'render_bulk_edit_fields' ] );
		add_action( 'woocommerce_product_bulk_edit_save', [ $this, 'save_bulk_edit' ] );
	}

	// ─────────────────────────────────────────────────────────
	// عمود قائمة المنتجات
	// ─────────────────────────────────────────────────────────

	public function add_column( array $columns ): array {
		$new_columns = [];
		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;
			if ( 'price' === $key ) {
				$new_columns['masa_geo_visibility'] = __( '🌍 العرض الجغرافي', 'masa-geo-pricing' );
			}
		}
		if ( ! isset( $new_columns['masa_geo_visibility'] ) ) {
			$new_columns['masa_geo_visibility'] = __( '🌍 العرض الجغرافي', 'masa-geo-pricing' );
		}
		return $new_columns;
	}

	public function render_column( string $column, int $post_id ): void {
		if ( 'masa_geo_visibility' !== $column ) {
			return;
		}

		$map = Masa_Geo_Zone_Stock::get_stock_map( $post_id );

		if ( empty( $map ) ) {
			echo '<span style="color:#9ca3af;">' . esc_html__( 'كل البلاد', 'masa-geo-pricing' ) . '</span>';
			return;
		}

		$zones = $this->get_zones_indexed();

		foreach ( $map as $zone_id => $qty ) {
			$label = isset( $zones[ $zone_id ] ) ? $zones[ $zone_id ]['label'] : $zone_id;

			if ( 0 === $qty ) {
				// محجوب
				printf(
					'<span style="display:inline-block;background:#fee2e2;color:#991b1b;border-radius:4px;padding:1px 8px;font-size:.75rem;font-weight:600;margin:1px 2px;">🚫 %s</span>',
					esc_html( $label )
				);
			} else {
				// سقف كمية
				printf(
					'<span style="display:inline-block;background:#dcfce7;color:#166534;border-radius:4px;padding:1px 8px;font-size:.75rem;font-weight:600;margin:1px 2px;">%s: %d</span>',
					esc_html( $label ),
					(int) $qty
				);
			}
		}
	}

	// ─────────────────────────────────────────────────────────
	// Bulk Edit
	// ─────────────────────────────────────────────────────────

	public function render_bulk_edit_fields(): void {
		$zones = $this->get_zones_indexed();
		?>
		<div class="inline-edit-group masa-geo-bulk-visibility" dir="rtl" style="margin-top:6px;">
			<label class="alignright" style="width:100%;">
				<span class="title"><?php esc_html_e( '📦 المخزون الجغرافي', 'masa-geo-pricing' ); ?></span>
				<select name="masa_geo_bulk_visibility_mode" id="masa_geo_bulk_visibility_mode">
					<option value=""><?php esc_html_e( '— بدون تغيير —', 'masa-geo-pricing' ); ?></option>
					<option value="all"><?php esc_html_e( 'إزالة كل التخصيصات (متاح في كل البلاد)', 'masa-geo-pricing' ); ?></option>
					<option value="block"><?php esc_html_e( 'حجب في مناطق محددة (كمية = 0)…', 'masa-geo-pricing' ); ?></option>
					<option value="cap"><?php esc_html_e( 'تحديد سقف كمية لمنطقة…', 'masa-geo-pricing' ); ?></option>
				</select>
			</label>

			<div id="masa_geo_bulk_zones_wrap" style="display:none;padding:6px 2px 0;">
				<?php if ( empty( $zones ) ) : ?>
					<em><?php esc_html_e( 'لا توجد مناطق معرّفة في إعدادات البلاجين.', 'masa-geo-pricing' ); ?></em>
				<?php else : ?>
					<?php foreach ( $zones as $zone_id => $zone ) : ?>
						<label style="display:inline-block;margin:2px 0 2px 12px;">
							<input type="checkbox"
								name="masa_geo_bulk_visibility_zones[]"
								value="<?php echo esc_attr( $zone_id ); ?>" />
							<?php echo esc_html( $zone['label'] ); ?>
						</label>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>

			<div id="masa_geo_bulk_cap_wrap" style="display:none;padding:6px 2px 0;">
				<label>
					<?php esc_html_e( 'الكمية:', 'masa-geo-pricing' ); ?>
					<input type="number" min="0" step="1" name="masa_geo_bulk_stock_qty" style="width:90px;" />
				</label>
				<em class="description" style="display:block;margin-top:3px;">
					<?php esc_html_e( 'تُطبَّق على المناطق المحددة أعلاه لكل المنتجات المختارة.', 'masa-geo-pricing' ); ?>
				</em>
			</div>

			<?php wp_nonce_field( 'masa_geo_bulk_visibility', 'masa_geo_bulk_visibility_nonce' ); ?>

			<script>
			jQuery(function($){
				$(document).on('change', '#masa_geo_bulk_visibility_mode', function(){
					var mode = $(this).val();
					$('#masa_geo_bulk_zones_wrap').toggle(mode === 'block' || mode === 'cap');
					$('#masa_geo_bulk_cap_wrap').toggle(mode === 'cap');
				});
			});
			</script>
		</div>
		<?php
	}

	public function save_bulk_edit( $product ): void {
		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		if (
			! isset( $_REQUEST['masa_geo_bulk_visibility_nonce'] ) ||
			! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_REQUEST['masa_geo_bulk_visibility_nonce'] ) ),
				'masa_geo_bulk_visibility'
			)
		) {
			return;
		}

		$product_id = $product->get_id();

		if ( ! current_user_can( 'edit_post', $product_id ) ) {
			return;
		}

		$mode = isset( $_REQUEST['masa_geo_bulk_visibility_mode'] )
			? sanitize_key( wp_unslash( $_REQUEST['masa_geo_bulk_visibility_mode'] ) )
			: '';

		if ( '' === $mode ) {
			return; // بدون تغيير
		}

		if ( 'all' === $mode ) {
			Masa_Geo_Zone_Stock::set_stock_map( $product_id, [] );
			return;
		}

		$raw_zones = isset( $_REQUEST['masa_geo_bulk_visibility_zones'] )
			? (array) wp_unslash( $_REQUEST['masa_geo_bulk_visibility_zones'] )
			: [];
		$zone_ids  = self::sanitize_zone_ids( $raw_zones );

		if ( empty( $zone_ids ) ) {
			return; // مفيش مناطق متحددة — منعملش حاجة
		}

		$map = Masa_Geo_Zone_Stock::get_stock_map( $product_id );

		if ( 'block' === $mode ) {
			foreach ( $zone_ids as $zone_id ) {
				$map[ $zone_id ] = 0;
			}
			Masa_Geo_Zone_Stock::set_stock_map( $product_id, $map );
			return;
		}

		if ( 'cap' === $mode ) {
			$qty = isset( $_REQUEST['masa_geo_bulk_stock_qty'] )
				? trim( sanitize_text_field( wp_unslash( $_REQUEST['masa_geo_bulk_stock_qty'] ) ) )
				: '';

			if ( '' === $qty || ! is_numeric( $qty ) ) {
				return;
			}

			foreach ( $zone_ids as $zone_id ) {
				$map[ $zone_id ] = max( 0, (int) $qty );
			}
			Masa_Geo_Zone_Stock::set_stock_map( $product_id, $map );
		}
	}

	// ─────────────────────────────────────────────────────────
	// Shared save helpers (بيستخدمها كمان Masa_Geo_Product)
	// ─────────────────────────────────────────────────────────

	/**
	 * @deprecated v3.6.0 — استخدم Masa_Geo_Zone_Stock::set_stock_map().
	 * موجود للتوافق الخلفي: بيترجم "zones مسموحة" لخريطة مخزون (الباقي = 0).
	 */
	public static function set_allowed_zones( int $product_id, array $zone_ids ): void {
		$zone_ids = self::sanitize_zone_ids( $zone_ids );

		if ( empty( $zone_ids ) ) {
			Masa_Geo_Zone_Stock::set_stock_map( $product_id, [] );
			return;
		}

		$map = [];
		foreach ( Masa_Geo_Zone_Stock::get_zone_ids() as $zone_id ) {
			if ( ! in_array( $zone_id, $zone_ids, true ) ) {
				$map[ $zone_id ] = 0;
			}
		}

		Masa_Geo_Zone_Stock::set_stock_map( $product_id, $map );
	}

	public static function sanitize_zone_ids( array $raw ): array {
		$valid_ids = array_keys( self::get_zones_indexed_static() );

		$clean = [];
		foreach ( $raw as $zone_id ) {
			$zone_id = sanitize_key( (string) $zone_id );
			if ( $zone_id !== '' && in_array( $zone_id, $valid_ids, true ) ) {
				$clean[ $zone_id ] = $zone_id; // dedupe
			}
		}

		return array_values( $clean );
	}

	private function get_zones_indexed(): array {
		return self::get_zones_indexed_static();
	}

	private static function get_zones_indexed_static(): array {
		$settings = get_option( MASA_GEO_OPTION_KEY, [] );
		$zones    = $settings['zones'] ?? [];

		$indexed = [];
		foreach ( (array) $zones as $zone ) {
			if ( ! empty( $zone['id'] ) ) {
				$indexed[ $zone['id'] ] = $zone;
			}
		}
		return $indexed;
	}
}
