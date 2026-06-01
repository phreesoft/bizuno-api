# Bizuno RESTful API for WooCommerce

Development repository for the WordPress plugin **Bizuno RESTful API for WooCommerce** (WordPress.org slug: `bizuno-restful-api-for-woocommerce`).

Real-time WooCommerce sync with Bizuno ERP & Accounting: push orders, pull inventory, prices and customers through a secure REST API. Works with self-hosted Bizuno or PhreeSoft Cloud and authenticates with a shared `X-Bizuno-Token` secret, so your store and your books can live on separate domains.

- **Requires:** WordPress 6.5+, PHP 8.1+, WooCommerce 8.0+
- **License:** AGPL-3.0-or-later
- **WordPress.org listing:** the canonical, directory-facing readme is [`readme.txt`](readme.txt)

## Setup

1. Install and activate the plugin (WooCommerce must be active).
2. **Settings → Bizuno → General**: enter Server URL, REST user name, password, and API token.
3. **Bizuno API** settings tab: choose sync options (stock, backorders, prefixes, journal, auto-download).
4. Export orders from the WooCommerce order list or single-order screen; inventory and prices flow back from Bizuno.

## Links

- PhreeSoft: https://www.phreesoft.com
- Bizuno: https://www.bizuno.com
- Core Bizuno library: https://github.com/phreesoft/bizuno
- Accounting portal: https://github.com/phreesoft/bizuno-accounting
