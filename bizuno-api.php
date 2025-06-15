<?php
/**
 * Plugin Name: Bizuno-API RESTful API
 * Plugin URI:  https://www.phreesoft.com
 * Description: The Bizuno-WordPress plugin allow you to synchronize data between any Bizuno host and your WooCommerce cart. Uploads inventory and order status to the cart, download orders to your Bizuno business.
 * Version:     6.7.8
 * Author:      PhreeSoft, Inc.
 * Author URI:  http://www.PhreeSoft.com
 * Text Domain: bizuno
 * License:     GPL3
 * License URI: https://www.gnu.org/licenses/gpl.html
 * Domain Path: /locale
 */

if (!defined('ABSPATH')) { die('No script kiddies please!'); }

require_once ( dirname(__FILE__).'/lib/admin.php' );
require_once ( dirname(__FILE__).'/lib/common.php' );
require_once ( dirname(__FILE__).'/lib/account.php' );
require_once ( dirname(__FILE__).'/lib/order.php' );
require_once ( dirname(__FILE__).'/lib/payment.php' );
require_once ( dirname(__FILE__).'/lib/product.php' );
require_once ( dirname(__FILE__).'/lib/shipping.php');

if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
    require_once ( dirname(__FILE__).'/plugins/payment-payfabric/payment-payfabric.php' );
    require_once ( dirname(__FILE__).'/plugins/payment-converge.php' );
    require_once ( dirname(__FILE__).'/plugins/payment-purchase-order.php' );
    // Pull in the Shipping plugins
    require_once ( dirname(__FILE__).'/plugins/shipping-bizuno.php' );
}

class bizuno_api
{
    public function __construct()
    {
        $this->admin    = new \bizuno\api_admin(); // loads/updates the options for the plugin
        $this->options  = $this->admin->options;
        $this->api_local= $this->admin->api_local;
        $this->account  = new \bizuno\api_account($this->options);
        $this->product  = new \bizuno\api_product($this->options);
        $this->order    = new \bizuno\api_order($this->options);
//      $this->payment  = new \bizuno\api_payment($this->options);
//      $this->shipping = new \bizuno\api_shipping($this->options);
        // Actions
        add_action ( 'admin_menu',       [ $this->admin, 'bizuno_api_add_setting_submenu' ]);
        add_action ( 'init',             [ $this, 'bizuno_init' ] );
        add_action ( 'admin_init',       [ $this, 'bizuno_admin_init' ] );
        add_action ( 'woocommerce_init', [ $this, 'bizuno_woocommerce_init' ] );
        add_action ( 'plugins_loaded',   [ $this, 'bizuno_plugins_loaded' ] );
        add_action ( 'admin_notices',    [ $this, 'bizuno_api_display_notices' ], 12 );
        // Filters
        add_filter ( 'query_vars',       [ $this, 'bsi_query_vars' ], 0 );
        add_filter ( 'mime_types',       [ $this, 'biz_allow_webp_upload' ] ); // filter to allow mime type .webp images to be uploaded
        if (!empty($this->options['url'])) { // REST is only use site to site, for internal leave url empty
            add_action ( 'rest_api_init',                          [ $this,  'bizuno_rest_api_init' ] );
        }
        if ( in_array ( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
            add_action ( 'manage_shop_order_posts_custom_column',  [ $this->admin,'bizuno_api_order_column_content' ] );
            add_action ( 'woocommerce_admin_order_preview_end',    [ $this->admin,'bizuno_api_order_preview_action' ] );
            add_action ( 'woocommerce_order_action_bizuno_export_action',[ $this->order, 'bizuno_api_process_order_meta_box_action' ] );
            add_action ( 'wp_ajax_bizuno_api_order_download',      [ $this->order,'bizuno_api_manual_download' ], 10);
            add_action ( 'woocommerce_thankyou',                   [ $this, 'bizuno_api_post_payment' ], 10, 1);
//          add_action ( 'woocommerce_account_biz-account-addresses_endpoint',[ $account, 'biz_account_addresses_content'] );
//          add_action ( 'woocommerce_account_biz-account-history_endpoint',  [ $account, 'biz_account_history_content'] );
//          add_action ( 'woocommerce_account_biz-account-wallet_endpoint',   [ $account, 'biz_account_wallet_content'] );
            add_filter ( 'woocommerce_account_menu_items',       [ $this->account,'biz_add_woo_tabs' ], 999 );
            add_filter ( 'wc_order_statuses',                    [ $this->admin,  'add_shipped_to_order_statuses' ] );
            add_filter ( 'manage_edit-shop_order_columns',       [ $this->admin,  'bizuno_api_order_column_header' ], 20 );
            add_filter ( 'woocommerce_admin_order_preview_get_order_details', [ $this->admin, 'bizuno_api_order_preview_filter' ], 10, 2);
            add_filter ( 'woocommerce_order_actions',            [ $this->admin,  'bizuno_api_add_order_meta_box_filter' ] );
            // WooCommerce Product Pricing
            //
            //
            // TAKES VERY LONG
//            add_filter ( 'woocommerce_product_get_price',                  [$this->product, 'adjustPrice'], 99, 2);
//            add_filter ( 'woocommerce_product_get_regular_price',          [$this->product, 'adjustPrice'], 99, 2);
//            add_filter ( 'woocommerce_product_variation_get_price',        [$this->product, 'adjustPrice'], 99, 2);
//            add_filter ( 'woocommerce_product_variation_get_regular_price',[$this->product, 'adjustPrice'], 99, 2);
//            add_filter ( 'woocommerce_variation_prices_price',             [$this->product, 'adjustPrice'], 99, 3);
//            add_filter ( 'woocommerce_variation_prices_regular_price',     [$this->product, 'adjustPrice'], 99, 3);

//          add_filter ( 'woocommerce_get_sections_advanced',    [ $this, ' bizuno_api_add_section' ] );
//          add_filter ( 'woocommerce_get_settings_advanced',    [ $this, ' bizuno_api_setting_submenu' ], 10, 2 );
        }
    }
    private function bizunoCtlr() {
        require_once ( plugin_dir_path( __FILE__ ) . '../bizuno-accounting/controllers/portal/controller.php' );
        return new \bizuno\portalCtl(); // sets up the Bizuno Environment
    }
    public function bizuno_init() {
        // Adjustments to account page
//        add_rewrite_endpoint( 'biz-account-addresses',EP_ROOT | EP_PAGES ); // This is driven via the theme. Most have this built in
//        add_rewrite_endpoint( 'biz-account-history',  EP_ROOT | EP_PAGES ); // Dittos
        add_rewrite_endpoint( 'biz-account-wallet',   EP_ROOT | EP_PAGES ); // for WC add wallet endpoint
        // Add shipped status for API uploads
        register_post_status( 'wc-shipped', [
            'label'                    => 'Shipped',
            'public'                   => true,
            'exclude_from_search'      => false,
            'show_in_admin_all_list'   => true,
            'show_in_admin_status_list'=> true,
            'label_count'              => _n_noop( 'Shipped <span class="count">(%s)</span>', 'Shipped <span class="count">(%s)</span>' )] );
    }
    public function bizuno_admin_init() {
        if ( !is_plugin_active ( 'woocommerce/woocommerce.php' ) ) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die( __( 'This plugin has been inactivated, it requires the <b>WooCommerce</b> plugin and the <b>WP OAuth Server</b> plugins. Sorry about that.', 'textdomain' ) );
        }
    }
    public function bizuno_woocommerce_init() {
        if ( ! WC()->is_rest_api_request() ) { return; }
        WC()->frontend_includes();
        if ( null === WC()->session ) {
            WC()->session = new WC_Session_Handler();
            WC()->session->init();
        } // WC()->customer
        if ( null === WC()->customer ) {
            WC()->customer = new WC_Customer( 0 );
        } // WC()->customer
        if ( null === WC()->cart && function_exists( 'wc_load_cart' ) ) { wc_load_cart(); }
    }
    public function bizuno_plugins_loaded() {
        if ( ! is_plugin_active ( 'woocommerce/woocommerce.php' ) ) { return; }
        WC()->frontend_includes();
        if ( class_exists ( 'WC_Payment_Gateway' ) ) { // get instance of WooCommerce for Payfabric
            require ( plugin_dir_path ( __FILE__ ) . 'plugins/payment-payfabric/classes/class-payfabric-gateway-woocommerce.php' );
            Payfabric_Gateway_Woocommerce::get_instance();
        }
    }
    public function bizuno_rest_api_init() {
        // From WordPress -> Bizuno (downlink)
        register_rest_route( 'bizuno-api/v1', 'order/add',            ['methods' => 'POST','args'=>[],
            'callback' => [ new \bizuno\api_order($this->options),   'order_add' ],            'permission_callback' => [$this, 'check_access']]);
        // Account related
        register_rest_route( 'bizuno-api/v1', 'account/address/list', ['methods' => 'GET', 'args'=>[],
            'callback' => [ new \bizuno\api_account($this->options), 'account_address_list' ], 'permission_callback' => [$this, 'check_access']]);
        register_rest_route( 'bizuno-api/v1', 'account/order/history',['methods' => 'GET', 'args'=>[],
            'callback' => [ new \bizuno\api_account($this->options), 'account_history_list' ],'permission_callback'  => [$this, 'check_access']]);
        register_rest_route( 'bizuno-api/v1', 'account/wallet/list',  ['methods' => 'GET', 'args'=>[],
            'callback' => [ new \bizuno\api_account($this->options), 'account_wallet_list' ],  'permission_callback' => [$this, 'check_access']]);
        register_rest_route( 'bizuno-api/v1', 'account/details',      ['methods' => 'GET', 'args'=>[],
            'callback' => [ new \bizuno\api_account($this->options), 'account_details' ],      'permission_callback' => [$this, 'check_access']]);
        register_rest_route( 'bizuno-api/v1', 'account/new',          ['methods' => 'POST', 'args'=>[],
            'callback' => [ new \bizuno\api_account($this->options), 'account_new' ],          'permission_callback' => [$this, 'check_access']]);
        register_rest_route( 'bizuno-api/v1', 'account/update',       ['methods' => 'PUT', 'args'=>[],
            'callback' => [ new \bizuno\api_account($this->options), 'account_update' ],       'permission_callback' => [$this, 'check_access']]);
        register_rest_route( 'bizuno-api/v1', 'account/address/add',  ['methods' => 'POST','args'=>[],
            'callback' => [ new \bizuno\api_account($this->options), 'account_address_add' ],  'permission_callback' => [$this, 'check_access']]);
        register_rest_route( 'bizuno-api/v1', 'account/wallet/add',   ['methods' => 'POST','args'=>[],
            'callback' => [ new \bizuno\api_account($this->options), 'account_wallet_add' ],   'permission_callback' => [$this, 'check_access']]);
        // Payment related
        register_rest_route( 'bizuno-api/v1', 'payment/methods/add',  ['methods' => 'POST','args'=>[],
            'callback' => [ new \bizuno\api_payment($this->options), 'payment_methods_add' ],  'permission_callback' => [$this, 'check_access']]);
        register_rest_route( 'bizuno-api/v1', 'payment/methods/list', ['methods' => 'GET', 'args'=>[],
            'callback' => [ new \bizuno\api_payment($this->options), 'payment_methods_list' ], 'permission_callback' => [$this, 'check_access']]);
        if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) { // From Bizuno -> WordPress (uplink)
            register_rest_route( 'bizuno-api/v1', 'product/update',       ['methods' => 'PUT', 'args'=>[],
                'callback' => [ new \bizuno\api_product($this->options), 'product_update' ],       'permission_callback' => [$this, 'check_access']]);
            register_rest_route( 'bizuno-api/v1', 'product/refresh',      ['methods' => 'GET', 'args'=>[],
                'callback' => [ new \bizuno\api_product($this->options), 'product_refresh' ],      'permission_callback' => [$this, 'check_access']]);
            register_rest_route( 'bizuno-api/v1', 'product/sync',         ['methods' => 'POST', 'args'=>[],
                'callback' => [ new \bizuno\api_product($this->options), 'product_sync' ],         'permission_callback' => [$this, 'check_access']]);
            register_rest_route( 'bizuno-api/v1', 'order/confirm',        ['methods' => 'POST','args'=>[],
                'callback' => [ new \bizuno\api_order($this->options),   'order_confirm' ],        'permission_callback' => [$this, 'check_access']]);
        }
        if ( in_array( 'bizuno-pro/bizuno-pro.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) { // Shipping Rates
            register_rest_route( 'bizuno-api/v1', 'product/price',        ['methods' => 'GET', 'args'=>[],
                'callback' => [ new \bizuno\api_product($this->options), 'product_price' ],        'permission_callback' => [$this, 'check_access']]);
            register_rest_route( 'bizuno-api/v1', 'shipping/rates',       ['methods' => 'GET','args'=>[],
                'callback' => [ new \bizuno\api_shipping($this->options),'rates_list' ],           'permission_callback' => [$this, 'check_access']]);
        }
    }
    public function check_access(WP_REST_Request $request) {
        if ($this->api_local) { return true; }
        // For local installs, make sure user has certain permissions
// need to create a nonce for same domain REST calls.
// You create the appropriate nonce with the following php code.
//        $nonce = wp_create_nonce( 'wp_restwp_create_nonce' );
//Then you pass that nonce with the HTTP request via the header X-WP-Nonce.

        // else different servers, so make sure the credentials are good to properly initialize configuration values
        $this->bizunoCtlr();
        \bizuno\msgDebug("\nWorking with REST object: ".print_r($request, true));
        $email = $request->get_header('email');
        \bizuno\msgDebug("\nRead email = $email");
        $pass  = $request->get_header('pass');
        $userID = \bizuno\biz_authenticate($email, $pass);
        if (!\bizuno\biz_validate_user()) { \bizuno\biz_validate_user_creds($email, $pass); } // initiate Bizuno login info
        return !empty($userID) ? true : false;
    }
    public function bsi_query_vars( $vars ) { // custom query vars for passing dynamic data
        $vars[] = 'biz-addresses'; // for WC add address book tab
        $vars[] = 'biz-history'; // for WC add order history tab
        $vars[] = 'biz-wallet'; // for WC add wallet tab
        return $vars;
    }
    public function bizuno_api_post_payment($order_id) {
        if ( !empty ( get_post_meta ( $order_id, 'bizuno_order_exported' ) ) ) { return; } // already downloaded, prevents duplicate download errors
        update_post_meta ( $order_id, 'bizuno_order_exported', 0 );
        if ( !empty ( get_option ( 'bizuno_api_autodownload', false ) ) ) {
            $this->order->orderExport($order_id); // call return to bit bucket as as all messsages are suppressed
            $wooOrder = new WC_Order($order_id);
            $wooOrder->update_status('completed');
//          $GLOBALS['bizunoExport'] = true; // turn off messaging since autodownload
        }
    }
    public function bizuno_api_display_notices() {
        $notices = get_option( 'bizuno_flash_notices', [] );
        foreach ( $notices as $notice ) {
            printf('<div class="notice notice-%1$s %2$s"><p>%3$s</p></div>', $notice['type'], $notice['dismissible'], $notice['notice'] );
        }
        if (!empty( $notices)) { delete_option( 'bizuno_flash_notices', [] ); }
    }
    public function biz_allow_webp_upload($existing_mimes) { // allows image uploads of mime type webp
        $existing_mimes['webp'] = 'image/webp';
        return $existing_mimes;
    }
}
new bizuno_api();

register_activation_hook(__FILE__ , 'bizuno_api_activate' );
function bizuno_api_activate() {
//    global $wpdb;
    update_option('bizuno_api_active', true); //activate
    // set all existing orders to downloaded to hide Download button
// Commented out as when you inactivate and reactivate it sets all orders to downloaded
// need to see if the meta bizuno_order_exported exists anywhere in db, if so then skip this step
//    $orders = $wpdb->get_results( "SELECT `ID` FROM `{$wpdb->prefix}posts` WHERE `post_type` LIKE 'shop_order'");
//    foreach ($orders as $order) { update_post_meta($order->ID, 'bizuno_order_exported', 1); }
    // This function will be called at activation, and then on every init as well:
//  Plugin_Class_Public::add_endpoint(); // @PhreeSoft - may be needed for new installs.
    // Run this at activation ONLY, AFTER setting the endpoint - needed for your endpoint to actually create a query_vars entry:
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__ , 'bizuno_api_deactivate' );
function bizuno_api_deactivate() {
    update_option('bizuno_api_active', false);
    flush_rewrite_rules();
}
register_uninstall_hook(__FILE__, 'bizuno_api_uninstall');
function bizuno_api_uninstall() {
    global $wpdb;
    $admin = new \bizuno\api_admin();
    foreach (array_keys($admin->options) as $key) {
        delete_option ( 'bizuno_api_'.$key );
        delete_site_option ( 'bizuno_api_'.$key ); //multi site
    }
    delete_option('bizuno_api_active');
    delete_site_option('bizuno_api_active');
    // remove order fields
    $orders = $wpdb->get_results( "SELECT `ID` FROM `{$wpdb->prefix}posts` WHERE `post_type` LIKE 'shop_order'");
    foreach ($orders as $order) { delete_post_meta($order->ID, 'bizuno_order_exported'); }
}
