<?php
defined( 'ABSPATH' ) || exit;

/**
 * Masa_Geo_Email
 * عرض قيم الطلب بالعملة الأساسية (EGP) في الإيميلات وفواتير ووكومرس
 * بجانب عملة الطلب المحلية.
 *
 * المنطق:
 *   - كل أوردر بيتخزن بعملة الـ Zone (مثلاً USD للزائر الأمريكي).
 *   - وعنده meta محفوظة: `_masa_geo_egp_subtotal` (إجمالي الأوردر بالجنيه).
 *   - هنا بنعرض القيمة بالـ EGP جنب القيمة المحلية لتسهيل المطابقة المحاسبية.
 *
 * v4.0.0: إصلاح المنطق — النسخة القديمة كانت بتقارن العملتين وتخرج فوراً
 *         لأن `get_currency()` و`_masa_geo_currency` بيخزّنان نفس القيمة،
 *         فكانت الميزة لا تظهر أبداً. دلوقتي بنحوّل من عملة الطلب إلى EGP
 *         عبر معدل الصرف العكسي.
 */
final class Masa_Geo_Email {

    private static ?Masa_Geo_Email $instance = null;

    public static function instance(): Masa_Geo_Email {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // فلترة المجاميع والأسعار في الإيميلات وفواتير الطلبات
        add_filter( 'woocommerce_order_formatted_line_subtotal', [ $this, 'append_base_currency_to_line_subtotal' ], 99, 3 );
        add_filter( 'woocommerce_get_order_item_totals',          [ $this, 'append_base_currency_to_totals' ], 99, 2 );
    }

    /**
	 * سعر الصرف من عملة الطلب إلى العملة الأساسية (EGP).
	 *
	 * `Masa_Geo_Api::get_rate( $currency )` بيرجّع كم وحدة من العملة تساوي 1 EGP،
	 * يعني عشان نحوّل من العملة إلى EGP بنقسم على الـ rate.
	 *
	 * @return float|null معدل التحويل، أو null لو مش متاح.
	 */
	private function get_rate_to_base( string $order_currency ): ?float {
		if ( ! $order_currency ) {
			return null;
		}

		$rate = Masa_Geo_Api::get_rate( $order_currency );
		if ( ! $rate || $rate <= 0 ) {
			return null;
		}

		return 1 / $rate;
	}

	/**
	 * صياغة قيمة بالعملة الأساسية (EGP) لتُعرض بجانب القيمة المحلية.
	 */
	private function format_base_suffix( float $amount ): string {
		$formatted = strip_tags( html_entity_decode( wc_price( $amount, [ 'currency' => 'EGP' ] ) ) );
		return ' <span style="color: #6b7280; font-size: 0.85em; font-weight: normal;">(≈ ' . esc_html( $formatted ) . ')</span>';
	}

	/**
	 * تحديد العملة الأساسية ومعدل التحويل للطلب.
	 * بيرجّع [ rate, currency ] أو null لو مش محتاج عرض.
	 *
	 * @return array{rate: float, currency: string}|null
	 */
	private function get_conversion_context( \WC_Order $order ): ?array {
		$order_currency = $order->get_currency();

		// لو الأوردر أصلاً بالجنيه → مفيش حاجة نعرضها
		if ( ! $order_currency || 'EGP' === strtoupper( $order_currency ) ) {
			return null;
		}

		$rate = $this->get_rate_to_base( $order_currency );
		if ( null === $rate ) {
			return null;
		}

		return [ 'rate' => $rate, 'currency' => 'EGP' ];
	}

	/**
	 * إضافة القيمة بالعملة الأساسية (EGP) لأسعار الأسطر (المنتجات)
	 */
	public function append_base_currency_to_line_subtotal( string $subtotal_html, $item, \WC_Order $order ): string {
		$ctx = $this->get_conversion_context( $order );
		if ( null === $ctx ) {
			return $subtotal_html;
		}

		// `$item->get_subtotal()` محفوظة بعملة الطلب، فنحوّلها للـ EGP
		$line_subtotal = (float) $item->get_subtotal();
		if ( $line_subtotal > 0 ) {
			$base_amount = Masa_Geo_Rounding::round_price( $line_subtotal * $ctx['rate'], $ctx['currency'] );
			$subtotal_html .= $this->format_base_suffix( $base_amount );
		}

		return $subtotal_html;
	}

	/**
	 * إضافة القيمة بالعملة الأساسية (EGP) للمجاميع في أسفل الجدول
	 * (المجموع الفرعي، الشحن، الخصم، الإجمالي)
	 */
	public function append_base_currency_to_totals( array $total_rows, \WC_Order $order ): array {
		$ctx = $this->get_conversion_context( $order );
		if ( null === $ctx ) {
			return $total_rows;
		}

		// خريطة المفاتيح ودوال الاسترجاع المناسبة لها من الطلب
		$mapping = [
			'cart_subtotal'  => 'get_subtotal',
			'shipping'       => 'get_shipping_total',
			'discount'       => 'get_discount_total',
			'discount_total' => 'get_discount_total',
			'order_total'    => 'get_total',
		];

		foreach ( $mapping as $key => $method ) {
			if ( isset( $total_rows[ $key ] ) ) {
				$base_val = (float) $order->$method();
				if ( $base_val > 0 ) {
					$base_amount = Masa_Geo_Rounding::round_price( $base_val * $ctx['rate'], $ctx['currency'] );
					$total_rows[ $key ]['value'] .= $this->format_base_suffix( $base_amount );
				}
			}
		}

		return $total_rows;
	}
}
