=== WC Gateway Minimum Amount ===
Contributors:      aqeelhusny
Tags:              woocommerce, payment gateway, minimum order, cart
Requires at least: 6.0
Tested up to:      6.7
Stable tag:        1.0.0
Requires PHP:      8.0
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Restrict WooCommerce payment gateways based on a minimum cart total. Configurable per gateway from WooCommerce > Gateway Limits.

== Description ==

This plugin lets store owners set a minimum cart total (after coupon discounts) for each active WooCommerce payment gateway. Gateways that don't meet the configured minimum are automatically hidden from the checkout payment options, and an informational notice is shown to the customer.

**Features**

* Per-gateway minimum cart amount (post-coupon, ex-tax)
* Custom customer-facing notice per gateway with `{min}` placeholder
* Works with both Classic and Block-based WooCommerce checkout
* Admin settings page under WooCommerce > Gateway Limits
* All active and inactive gateways listed with Enabled/Disabled badge
* HPOS (High-Performance Order Storage) compatible

== Installation ==

1. Upload the `wc-gateway-min-amount` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Go to **WooCommerce > Gateway Limits** to configure minimums

== Frequently Asked Questions ==

= Does it work with coupons? =
Yes. The minimum is compared against the cart subtotal *after* coupon discounts are applied (`get_cart_contents_total()`), so coupon-discounted carts are evaluated at their real discounted value.

= Does it work with the WooCommerce Block checkout? =
Yes. The gateway filter applies to both Classic and Block-based checkout. Notices are surfaced via the Store API notice buffer on Block checkout.

= What does `{min}` do in the customer notice? =
It is replaced with the formatted minimum amount in your store currency, e.g. `රු 2,000.00`.

== Changelog ==

= 1.0.0 =
* Initial release.
