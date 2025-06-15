=== WooCommerce - Bizuno Accounting Interface ===
Contributors:      phreesoft
Tags:              bizuno, accounting, bookkeeping, woocommerce, bizuno api, bizuno interface, woocommerce accounting, wordpress accounting, wordpress erp
Requires at least: 5.3
Tested up to:      6.4.3
Stable tag:        6.7.8
License:           GPL3
License URI:       https://www.gnu.org/licenses/gpl.html

Bizuno Accounting, by PhreeSoft, is a full featured double-entry accounting/ERP application based on the PhreeBooks open source platform. This plugin creates an interface to allow seamless interaction between Bizuno Accounting (hosted locally or at the PhreeSoft cloud) and the WooCommerce plugin.

== Description ==

Bizuno/WooCommerce Interface, by PhreeSoft, provides seamless interaction between your Bizuno accounting application and your WooCommerce store. Features include:

* Download WooCommerce orders into Bizuno automatically or through the WP Admin panel
* Upload Bizuno inventory products to your WooCommerce store (Premium feature, requires PhreeSoft Bizuno WooCommerce Extension, available at PhreeSoft.com)
* Upload order shipment confirmations (Premium feature, requires PhreeSoft Bizuno WooCommerce Extension, available at PhreeSoft.com)
* Keeps Bizuno and WooCommerce Products in sync (Premium feature, requires PhreeSoft Bizuno WooCommerce Extension, available at PhreeSoft.com)

== Installation ==

Follow the standard installation procedure for all WordPress apps. Once activated, click the Bizuno menu item and press Install to complete the installation.

= Minimum Requirements =

* PHP   version 5.4 or greater (PHP 5.6 or greater is recommended, tested with PHP 8)
* MySQL version 5.0 or greater (MySQL 5.6 or greater is recommended, tested with MySQL 5.6 & 5.7)
* WordPress 4.4+

This section describes how to install the plugin and get it working.

e.g.

1. Upload the plugin files to the `/wp-content/plugins/bizuno-api` directory, or install the plugin through the WordPress plugins screen directly.
1. Activate the plugin through the 'Plugins' screen in WordPress
1. From the admin panel, navigate to WooCommerce -> Settings -> Advanced tab -> Buzuno API option.
1. Enter the credentials to connect to your Bizuno accounting business. Note that a valid user account with proper credentials must exist in your Bizuno business to connect.
1. Save your changes.

== Upgrade Notice ==

= All Releases =
No User action is needed. The script includes and auto-update feature for any changes that are required.

== Frequently Asked Questions ==

= How to manually download an order? =

* Once an order has been placed, it can be downloaded through the admin panel WooCommerce -> Orders screen. Either click the preview popup and press the Export Order to Bizuno button OR from the order detail screen 'Other Action', select Export Order to Bizuno and the go icon on the right.

= I don't see the Export Order to Bizuno option? =

* You can reset the Export flag at any time by editing the order and changing the value in the Custom Fields -> bizuno_order_exported to a value of 0 and saving the order.

== Screenshots ==

1. Bizuno-WooCommerce Interface Settings. Establishes the credentials to connect to your Bizuno accounting business.

== Changelog ==

= 6.7.8 = 
2024-04-02 - Final release in current architecture. Next release will have new UI and db structure.
= 6.7.7 = 
2024-02-11 - Minor bug fixes to improve communication with Bizuno.
= 6.7.6 = 
2024-01-11 - Bug fix for price sheets, with API support, minor bug fixes from prior release.
= 6.7.5 = 
2024-01-01 - Updates for WordPress 6.4.2, Re-write price sheets to allow customization, minor bug fixes.
= 6.7.4 =
2023-11-05 - Additional RESTful endpoints, link Bizuno and WordPress wallets
= 6.7.0 =
2023-05-03 - Convert Bizuno API to use WordPress RESTful API; New portal method. Required for Bizuno Pro WooCommerce interface to properly work.
= 6.2.6 =
2021-03-19 - Compatibility with Bizuno Accounting 6.2.6.
= 6.2.4 =
2021-02-18 - Compatibility with Bizuno 6.2.4.
= 6.2.2 =
2021-01-12 - Compatibility with Bizuno 6.2.2. Speed up manual order download by testing to verify user is logged in. Avoids changing user mid session.
= 6.1.1 =
2020-12-18 - Minor compatibility issues with Latest Bizuno release.
= 6.1 =
2020-12-08 - Minor bug fixes.
= 6.0 =
2020-09-09 - Bug fixes to work with Latest Bizuno 6.0 release
= 4.3 =
2020-09-09 - Added variation support. Bug fixes and updates for current WordPress Version.
= 3.3.1 =
2019-10-28 - Bug fixes and compatibility with Bizuno library 3.3.1
= 3.2.6 =
2019-07-24 - Initial Release (based on Bizuno library Revision 3.2.6)

== About PhreeSoft ==

PhreeSoft was the original developer of the PhreeBooks open source ERP/Accounting application back in 2007. PhreeBooks development was suspended in 2015 to focus on the next generation of ERP/Accounting requirements and provide faster, highly customizable, more user friendly experience. Bizuno was also developed as a library that can be plugged into host applications such as WordPress and provided as a hosted solution for those that do not want to manage their web sites.

== Donations ==

= Donations to any freely available plugin developed by PhreeSoft are always appreciated. Thank you in advance for supporting our projects. The PhreeSoft Development Team.