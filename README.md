# 🌍 Masa Geo-Pricing & Currency Manager for WooCommerce

[![WordPress](https://img.shields.io/badge/WordPress-6.4%2B-blue.svg?logo=wordpress)](https://wordpress.org)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-8.0%2B-purple.svg?logo=woocommerce)](https://woocommerce.com)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4.svg?logo=php)](https://php.net)
[![HPOS Ready](https://img.shields.io/badge/WooCommerce-HPOS%20Compatible-brightgreen.svg)](https://woocommerce.com/document/high-performance-order-storage/)
[![License: GPL v2+](https://img.shields.io/badge/License-GPLv2%2B-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Developer](https://img.shields.io/badge/Developed%20By-Ahmad%20Hasan-orange.svg)](https://ahmad-hasan.vercel.app/)

> **Enterprise-grade Geolocation, Dynamic Multi-Currency Pricing, Regional Stock Management, and Affiliate Portal for WooCommerce stores.**

Developed by **[Ahmad Hasan](https://ahmad-hasan.vercel.app/)** | [GitHub Profile](https://github.com/ahmadhasandev711-web)

---

## 📌 Executive Overview

**Masa Geo-Pricing & Currency Manager** is an advanced WooCommerce extension designed to solve complex international commerce challenges. Rather than simply converting currencies visually, this plugin provides **true regional e-commerce infrastructure**:

* **Location-Aware Dynamic Pricing:** Serve unique price structures, currency conversions, and rate multipliers per country or customs territory.
* **Atomic Regional Inventory:** Prevent overselling across international warehouses using a custom, high-concurrency database table with row-level locks (`SELECT FOR UPDATE`).
* **Anti-VPN & Checkout Fraud Guard:** Automatically detects country spoofing at checkout, recalibrating totals to match verified billing jurisdictions.
* **Full Caching & HPOS Compatibility:** Native support for modern WooCommerce High-Performance Order Storage (HPOS) and edge/page caching solutions (LiteSpeed, WP Rocket, Cloudflare) via signed vary cookies.
* **Modular Affiliate & Brand Engine:** Built-in multi-tier referral tracking, branded product line management, and a standalone frontend affiliate portal.

---

## 🏗️ Architecture & Technical Highlights

```
masa-geo-pricing/
├── masa-geo-pricing.php         # Plugin Bootstrap, Autoloader, HPOS declaration
├── uninstall.php                # Clean uninstallation & schema drop routine
├── includes/
│   ├── class-masa-geo-core.php              # Geolocation detection & session routing
│   ├── class-masa-geo-zone-stock.php        # Atomic DB inventory manager (custom tables)
│   ├── class-masa-geo-order.php             # Order lifecycle hooks, HPOS meta mapping
│   ├── class-masa-geo-api.php               # Exchange rate live API with fallback
│   ├── class-masa-geo-compat.php            # Cache headers & edge vary cookie dispatcher
│   ├── class-masa-geo-rounding.php          # Currency rounding strategies (.99, .95, etc.)
│   ├── class-masa-geo-visibility.php        # Geo-visibility & SEO fallback handler
│   ├── class-masa-geo-activity-log.php      # Diagnostic telemetry & security logging
│   ├── class-masa-geo-free-shipping-bar.php # Dynamic AJAX shipping progress threshold
│   ├── class-masa-geo-minimum-order.php     # Per-zone minimum checkout validations
│   ├── class-masa-geo-whatsapp.php          # Direct-order quotation routing for unpriced items
│   └── class-masa-geo-email.php             # Multi-currency email invoice adapters
├── admin/
│   ├── class-masa-geo-admin.php             # Admin settings UI & zone configurations
│   ├── class-masa-geo-product.php           # WooCommerce product data metabox tabs
│   ├── class-masa-geo-dashboard.php         # Analytics dashboard & regional performance
│   └── class-masa-geo-status.php            # Real-time health diagnostic suite
└── modules/
    └── affiliates/                          # Standalone Affiliate & Brand management module
        ├── class-masa-affiliate-core.php
        ├── class-masa-affiliate-tracker.php # HMAC-SHA256 signed tracking
        └── frontend/class-masa-affiliate-dashboard.php
```

### 1. High-Performance Concurrency & Atomic Inventory
Standard WordPress `postmeta` operations suffer from race conditions when handling simultaneous checkout operations across regions.
* Implemented a dedicated database table: `{$wpdb->prefix}masa_geo_zone_stock`.
* Implements **Row-Level Locking (`SELECT FOR UPDATE`)** inside database transactions during checkout and order placement.
* Uses **Transient mutex locks** (`try/finally` pattern) across Webhook and Payment callbacks to guarantee that concurrent notifications (`payment_complete` + `order_status_processing`) never result in double stock deductions.
* Full support for **Partial Refunds**: returning items correctly replenishes regional stock up to the recorded allocation ceiling.

### 2. Multi-Tier Geolocation Resolution Pipeline
To maximize accuracy and eliminate latency:
1. **Cryptographically Signed Session Cookie:** Stored as `COUNTRY|expires|HMAC-SHA256` to prevent client-side spoofing.
2. **Reverse Proxy / CDN Header:** Direct extraction of Cloudflare `HTTP_CF_IPCOUNTRY` or reverse proxy headers based on configured trusted proxies.
3. **MaxMind GeoIP Integration:** Native fallback to WooCommerce MaxMind database integration.
4. **Fallback Default Zone:** Graceful degradation if client geolocation is blocked or unavailable.

### 3. Edge & Page Cache Compatibility
Multi-currency dynamic stores frequently struggle with page caching. This plugin implements:
* Dynamic HTTP Vary headers (`Vary: Cookie`).
* Cache vary cookie integrations with **WP Rocket**, **LiteSpeed Cache**, and **WP-Optimize**.
* Automatic cache invalidation (`purge_cache`) triggered whenever regional inventory hits zero or recovers availability.

### 4. Anti-VPN & Geo-Arbitrage Protection
When opportunistic shoppers use VPNs to switch to low-tier pricing zones:
* During WooCommerce checkout evaluation (`woocommerce_checkout_process`), the system verifies the client's detected IP against their verified Billing Country.
* If a discrepancy is identified, the order totals and currency are instantaneously recalculated to the verified jurisdiction, preventing revenue loss.

---

## ⚡ Core Features

| Feature | Description |
| :--- | :--- |
| **Zone-Based Pricing** | Define unlimited zones with custom currencies, exchange rate multipliers, and fixed price overrides. |
| **Live Exchange Rates** | Automated daily synchronization with reliable exchange rate APIs, backed by local transient caching. |
| **Regional Stock Ceilings** | Configure inventory limits per region or set to zero for regional exclusivity while maintaining SEO rankings. |
| **Variation-Level Geo Stock** | Granular stock control down to product variation level (e.g., Size Large in GCC = 5 units). |
| **Affiliate & Brand Portal** | Built-in affiliate tracking with HMAC cookies, custom commission rules, and a frontend dashboard. |
| **WhatsApp Direct Order** | Smart WhatsApp CTA for unpriced or custom quotation products with pre-filled metadata. |
| **Free Shipping Bar** | Real-time dynamic cart threshold progress bar converted into the customer's local currency. |
| **Localized Emails** | Displays both customer's local checkout currency and base store currency in notifications and invoices. |
| **Diagnostic Health Suite** | Built-in system checker inspecting PHP version, DB tables, MaxMind health, and cache configurations. |

---

## 💻 Developer API & Extensibility

The codebase follows strict WordPress and WooCommerce coding standards, providing clean hooks and filters for customization.

### Custom Hooks & Filters

```php
// Customize the "Not Available in your country" message on product pages
add_filter( 'masa_geo_unavailable_message', function( $message, $product_id, $country_code ) {
    return sprintf( __( 'Sorry! This item cannot be shipped to %s at this time.', 'my-theme' ), $country_code );
}, 10, 3 );

// Trigger actions after exchange rates are successfully updated
add_action( 'masa_geo_rates_updated', function( $rates ) {
    error_log( 'Exchange rates synchronized successfully: ' . count( $rates ) . ' currencies updated.' );
} );

// Adjust minimum order amount logic per country
add_filter( 'masa_geo_minimum_order_amount', function( $min_amount, $country_code ) {
    if ( 'SA' === $country_code ) {
        return 150.00; // SAR
    }
    return $min_amount;
}, 10, 2 );
```

---

## ⚙️ Installation & Requirements

### System Requirements
* **WordPress:** 6.4 or higher
* **WooCommerce:** 8.0 or higher (HPOS supported)
* **PHP:** 8.0 or higher
* **MySQL:** 5.7+ or **MariaDB:** 10.3+

### Setup Guide
1. Clone or download this repository into your WordPress plugins directory:
   ```bash
   cd wp-content/plugins/
   git clone https://github.com/ahmadhasandev711-web/Geo.git masa-geo-pricing
   ```
2. Activate the plugin via **WordPress Admin → Plugins → Installed Plugins**.
3. Navigate to **Masa Geo-Pricing → Settings** to configure your Zones, Currencies, and Geo-Detection mode.
4. Visit **Masa Geo-Pricing → 🩺 Status** to verify that all system diagnostics pass.

---

## 👨‍💻 Author & Contact

**Ahmad Hasan**  
*Senior WordPress & WooCommerce Engineer*

* 🌐 **Portfolio:** [ahmad-hasan.vercel.app](https://ahmad-hasan.vercel.app/)
* 🐙 **GitHub:** [@ahmadhasandev711-web](https://github.com/ahmadhasandev711-web)
* 💼 **Specialization:** High-scale WooCommerce Plugins, Custom Themes, Headless WordPress, Database Concurrency & API Integrations.

---

## 📄 License

This project is licensed under the **GNU General Public License v2.0 or later** - see the [LICENSE](LICENSE) file or [GNU.org](http://www.gnu.org/licenses/gpl-2.0.html) for details.
