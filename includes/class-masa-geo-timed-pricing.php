<?php
defined( 'ABSPATH' ) || exit;

/**
 * Masa_Geo_Timed_Pricing
 * Override مؤقت بتاريخ بداية ونهاية
 * مثال: سعر رمضان خلال 30/3 → 29/4
 *
 * يتخزّن في post meta: _masa_geo_timed_prices
 * الشكل:
 * [
 *   'sa' => [
 *     [ 'price' => 120.0, 'date_from' => '2025-03-30', 'date_to' => '2025-04-29', 'label' => 'رمضان' ],
 *     ...
 *   ],
 * ]
 */
final class Masa_Geo_Timed_Pricing {

    const META_KEY = '_masa_geo_timed_prices';

    // ─────────────────────────────────────────────────────────
    // هل فيه Override مؤقت نشط لـ product + zone دلوقتي؟
    // ─────────────────────────────────────────────────────────
    private static array $active_cache = []; // request-level cache

    public static function get_active_price( int $product_id, string $zone_id ): ?float {
        $cache_key = $product_id . '_' . $zone_id;

        if ( array_key_exists( $cache_key, self::$active_cache ) ) {
            return self::$active_cache[ $cache_key ];
        }

        $timed = get_post_meta( $product_id, self::META_KEY, true );

        if ( ! is_array( $timed ) || empty( $timed[ $zone_id ] ) ) {
            self::$active_cache[ $cache_key ] = null;
            return null;
        }

        $today = current_time( 'Y-m-d' );

        foreach ( (array) $timed[ $zone_id ] as $rule ) {
            if ( empty( $rule['price'] ) || empty( $rule['date_from'] ) || empty( $rule['date_to'] ) ) {
                continue;
            }
            if ( $today >= $rule['date_from'] && $today <= $rule['date_to'] ) {
                $price = (float) $rule['price'];
                if ( $price > 0 ) {
                    self::$active_cache[ $cache_key ] = $price;
                    return $price;
                }
            }
        }

        self::$active_cache[ $cache_key ] = null;
        return null;
    }

    // ─────────────────────────────────────────────────────────
    // جيب كل الـ Timed Rules لمنتج
    // ─────────────────────────────────────────────────────────
    public static function get_rules( int $product_id ): array {
        $data = get_post_meta( $product_id, self::META_KEY, true );
        return is_array( $data ) ? $data : [];
    }

    // ─────────────────────────────────────────────────────────
    // Save (admin)
    // ─────────────────────────────────────────────────────────
    public static function save_rules( int $product_id, array $rules ): void {
        $clean = [];

        foreach ( $rules as $zone_id => $zone_rules ) {
            $zone_id    = sanitize_key( $zone_id );
            $zone_rules = (array) $zone_rules;
            $zone_clean = [];

            foreach ( $zone_rules as $rule ) {
                $price     = (float) sanitize_text_field( $rule['price'] ?? '0' );
                $date_from = sanitize_text_field( $rule['date_from'] ?? '' );
                $date_to   = sanitize_text_field( $rule['date_to']   ?? '' );
                $label     = sanitize_text_field( $rule['label']     ?? '' );

                if ( $price <= 0 ) continue;
                if ( ! self::valid_date( $date_from ) || ! self::valid_date( $date_to ) ) continue;
                if ( $date_from > $date_to ) continue;

                // تحقق من تداخل مع قواعد سابقة في نفس الـ Zone
                foreach ( $zone_clean as $existing ) {
                    if ( $date_from <= $existing['date_to'] && $date_to >= $existing['date_from'] ) {
                        // تداخل — تخطَّ القاعدة ده (الأول يكسب)
                        continue 2;
                    }
                }

                $zone_clean[] = [
                    'price'     => $price,
                    'date_from' => $date_from,
                    'date_to'   => $date_to,
                    'label'     => $label,
                ];
            }

            if ( ! empty( $zone_clean ) ) {
                $clean[ $zone_id ] = $zone_clean;
            }
        }

        if ( empty( $clean ) ) {
            delete_post_meta( $product_id, self::META_KEY );
        } else {
            update_post_meta( $product_id, self::META_KEY, $clean );
        }
    }

    private static function valid_date( string $d ): bool {
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ) return false;
        [ $y, $m, $day ] = explode( '-', $d );
        return checkdate( (int) $m, (int) $day, (int) $y );
    }
}
