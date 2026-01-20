=== Bizuno API – Inventory/Order Management for WooCommerce ===
Stable tag: 7.3.6
Contributors: phreesoft
Donate link: https://www.bizuno.com/donate/
Tags: bizuno, erp, accounting, woocommerce, rest-api
Requires at least: 6.5
Tested up to: 6.9
Requires PHP: 8.0
License: AGPL-3.0-or-later
License URI: https://www.gnu.org/licenses/agpl-3.0.html

Secure RESTful API bridge for real-time WooCommerce ↔ Bizuno ERP sync: orders, inventory, customers, prices & more.

== Description ==

**Bizuno API** is the essential connector that turns your WordPress/WooCommerce powered site into a fully integrated powerhouse with Bizuno Accounting/ERP.

Push and pull data in real time: orders, customers, inventory levels, products, prices, payments, and more – all securely through our modern RESTful API.

Whether you're running a high-volume online store, multi-store operation, or need automated accounting sync, Bizuno API eliminates double-entry headaches and manual imports forever.

= Why Businesses Love Bizuno API =

* **Bidirectional WooCommerce ↔ Bizuno Sync**  
  - New Woo orders → auto-create invoices/sales in Bizuno  
  - Inventory & price changes in Bizuno → instant WooCommerce update  
  - Customer data sync both ways – unified CRM across platforms

* **Secure RESTful API Access**  
  Modern, token-based authentication – safe for production use, custom apps, mobile, or third-party tools.

* **Real-Time Business Automation**  
  Keep stock accurate, orders flowing, and books up-to-date without delay or errors.

* **Perfect Companion to Bizuno Accounting**  
  Works hand-in-hand with the Bizuno Accounting portal plugin – use self-hosted Bizuno or PhreeSoft Cloud.

* **Multi-Store & Enterprise Ready**  
  Scale to multiple WooCommerce stores feeding one central Bizuno ERP instance.

* **Developer Friendly**  
  Clean endpoints, clear documentation (coming soon in Bizuno docs), and extensible for custom integrations.

Stop wasting time on CSV exports or brittle scripts.  
**Bizuno API delivers enterprise-grade connectivity with open-source freedom.**

== Installation ==

**Quick & Painless Setup**

1. Install & activate **Bizuno API** through the WordPress Plugins screen (or upload from GitHub).
2. Go to **WooCommerce → Settings → Integrations** (or Bizuno settings if using the full Bizuno portal plugin).
3. Enter your Bizuno API credentials (API key/secret or token – generated in your Bizuno instance).
4. Choose what to sync: orders, inventory, customers, products, etc.
5. Save → magic happens automatically in the background!

For full Bizuno self-hosted users: activate alongside the Bizuno Accounting portal plugin for the complete experience.

== Frequently Asked Questions ==

= What exactly does this plugin do? =
It provides a secure gateway so WooCommerce (or custom code) can talk directly to your Bizuno ERP/Accounting system via REST API – no more manual work!

= Do I need the Bizuno Accounting plugin too? =
Not on your e-store, Bizuno can reside on a sub-domain or alternate URL to isolate your businesses books from your public presence.

= Is it secure? =
Yes – uses modern token authentication, HTTPS required, and follows best practices for API security.

= Can I sync inventory and prices automatically? =
Yes! Real-time (or scheduled) bidirectional sync keeps everything consistent across platforms.

== Screenshots ==

1. API Settings screen – easy credential entry & sync toggles
2. Sync status dashboard – monitor real-time data flow
3. Example: WooCommerce order instantly appears in Bizuno
4. Inventory levels updating automatically both directions
5. Secure token generation in Bizuno admin

== Changelog ==

= 7.3.6 =
* Modernized for 2026: PHP 8.3+, WordPress 6.7 compatibility
* Improved REST security & token handling
* Better WooCommerce HPOS support
* Enhanced logging & debug tools for easier troubleshooting
* Marketing & documentation polish

= 7.3.4 =
* Architectural alignment with Bizuno 7.3+ portal changes
* Auto-update readiness for core Bizuno library

= 7.0 =
* Initial public release for PhreeSoft Cloud + self-hosted Bizuno customers (2025-03)

Earlier versions focused on internal testing & Cloud rollout.

== Upgrade Notice ==

= 7.3.x =
Important security & compatibility update.  
Re-save API settings after upgrade to refresh tokens.  
No data loss – sync continues seamlessly.

== About PhreeSoft ==

Since creating PhreeBooks in 2007, PhreeSoft has built open-source ERP tools businesses trust.  
**Bizuno** is our modern flagship – faster, more secure, infinitely extensible.  
**Bizuno API** completes the ecosystem, connecting your e-commerce front-end to powerful back-office accounting.

**Explore More:**  
🌐 https://www.phreesoft.com  
🌐 https://www.bizuno.com  
GitHub: https://github.com/phreesoft/bizuno-api  
Core Bizuno: https://github.com/phreesoft/bizuno  
Accounting Portal: https://github.com/phreesoft/bizuno-accounting

Ready to unify your store and accounting? Install Bizuno API today!