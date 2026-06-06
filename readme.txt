=== Refundia ===
Contributors: refundia
Tags: woocommerce, returns, refunds, exchanges, rma
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automated returns, refunds, and exchange management for WooCommerce stores. Built for the Spanish market.

== Description ==

Refundia gives WooCommerce merchants a complete returns management dashboard. Customers request returns through a branded public portal; merchants approve, reject, or convert to exchange in one click; refunds flow back to Stripe; shipping labels generate automatically through Correos.

Features:
* Branded customer return portal (no merchant intervention needed for routine returns)
* One-click approve / reject / exchange from the Refundia dashboard
* Automatic Stripe refunds when a return is approved
* Spanish carrier integration (Correos label generation)
* WhatsApp & email notifications to customers at every stage
* Factura Rectificativa auto-generation for Spanish AEAT compliance
* "Keep the Item" optimizer for low-value returns
* Real-time sync of orders, products, customers between WooCommerce and Refundia

== Installation ==

1. Install and activate Refundia from the WordPress plugin directory
2. A new "Refundia" menu appears in your WordPress admin sidebar
3. Open it and click "Pair with Refundia"
4. Sign in to your Refundia account (or create one — free)
5. Approve the pairing — your store now syncs automatically

== Changelog ==

= 0.5.0 =
* Backfill: new `/wp-json/refundia/v1/backfill` route (HMAC-signed) lets the Refundia dashboard re-push existing orders, products and customers. Also auto-runs in the background right after pairing.
* Health check: new public `/wp-json/refundia/v1/health` route returns plugin version and pairing state — used by the dashboard "Test connection" button.

= 0.4.0 =
* Refund execution: dashboard can now trigger refunds. Plugin receives an HMAC-signed request and runs wc_create_refund() (refunds the captured payment automatically). Idempotent via dashboard-supplied keys.

= 0.3.0 =
* Sync flow: WooCommerce new-order, order-status-change, refund, product-save and new-customer events now POST to the Refundia dashboard automatically (paired stores only). Fire-and-forget; failures are logged but never block WooCommerce.

= 0.2.0 =
* Pairing flow: admin page generates a short code, dashboard accepts the code to issue a long-lived token. WooCommerce sync hooks still pending in 0.3.0.

= 0.1.0 =
* Initial skeleton release. Admin menu placeholder. Pairing flow coming in 0.2.0.
