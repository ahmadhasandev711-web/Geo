<?php
defined( 'ABSPATH' ) || exit;

/**
 * Masa_Geo_Rounding
 * تقريب الأسعار لأعلى رقم صحيح دايماً
 * 10.33 → 11 | 12.40 → 13 | 10.00 → 10
 */
final class Masa_Geo_Rounding {

    // عملات بدون كسور عشرية (JPY, KRW...)
    const ZERO_DECIMAL = [
        'JPY', 'KRW', 'VND', 'IDR', 'CLP',
        'GNF', 'UGX', 'XOF', 'XAF', 'BIF',
        'MGA', 'PYG', 'RWF',
    ];

    public static function round_price( float $price, string $currency ): float {
        if ( $price <= 0 ) return 0.0;

        // عملات zero decimal → تقريب لأقرب رقم صحيح عادي
        if ( in_array( strtoupper( $currency ), self::ZERO_DECIMAL, true ) ) {
            return (float) round( $price );
        }

        // باقي العملات → ceil لأعلى رقم صحيح
        return (float) ceil( $price );
    }

    public static function is_zero_decimal( string $currency ): bool {
        return in_array( strtoupper( $currency ), self::ZERO_DECIMAL, true );
    }
}
