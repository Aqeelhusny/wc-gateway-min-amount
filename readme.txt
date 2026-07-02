=== Irix Gateway Limits for WooCommerce ===
Contributors:      aqeelhusny
Tags:              woocommerce, payment gateway, minimum order, cart
Requires at least: 6.0
Tested up to:      6.8
Stable tag:        1.3.2
Requires PHP:      8.0
Requires Plugins:  woocommerce
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Restrict WooCommerce payment gateways based on a minimum cart total. Configurable per gateway from WooCommerce > Gateway Limits.

== Description ==

This plugin lets store owners set a minimum cart total (after coupon discounts) for each active WooCommerce payment gateway. Gateways that don't meet the configured minimum are automatically hidden from the checkout payment options, and an informational notice is shown to the customer.

It can also apply a conditional fee or discount per gateway based on the cart subtotal — for example "5% off for bank transfer on orders over 10,000, capped at 1,000". Only the single best-matching rule per gateway is applied, and rules are skipped automatically when a coupon is on the cart.

**Features**

* Per-gateway minimum cart amount (post-coupon, ex-tax)
* Custom customer-facing notice per gateway with `{min}` placeholder
* Conditional per-gateway fees and discounts (percent or fixed, with optional cap and taxable flag)
* Checkout totals refresh live when the customer switches payment method
* Works with both Classic and Block-based WooCommerce checkout
* Order-pay pages compare minimums against the order total being paid
* Admin settings page under WooCommerce > Gateway Limits
* All active and inactive gateways listed with Enabled/Disabled badge
* HPOS (High-Performance Order Storage) compatible

== Installation ==

1. Upload the `irix-gateway-limits-for-woocommerce` folder to `/wp-content/plugins/`
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

= 1.3.2 =
* New: the "minimum not met" customer notice now also shows on the cart page and on the block-based Cart and Checkout pages (previously classic checkout only).
* Fix: gateways with a minimum were hidden on the order-pay page because the (empty) cart total was compared instead of the order total — customers could not pay existing orders.
* Fix: removing a fee/discount rule and adding a new one could silently overwrite another rule on save (duplicate form indexes).
* Fix: quotes in customer notices and rule labels were saved with stray backslashes (missing unslash).
* Fix: the on-load gateway sync could pick a shipping-rate radio instead of the payment method, and never fired on Blocks checkout (payment options render after page load).
* Fix: a cap of 0 on a fee/discount rule is now treated as "no cap" instead of zeroing the rule out.

= 1.3.0 =
* New: conditional per-gateway fees and discounts (percent or fixed, optional cap, taxable flag), with a master enable toggle.
* New: checkout totals refresh live when the customer switches payment method, on both Classic and Blocks checkout.

= 1.0.0 =
* Initial release.
* Per-gateway minimum cart amount with post-coupon evaluation.
* Custom customer notice with `{min}` placeholder.
* Compatible with Classic and Block-based WooCommerce checkout.
* HPOS (High-Performance Order Storage) compatible.
