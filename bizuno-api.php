<?php
/**
 * Plugin Name: Bizuno API
 * Plugin URI:  https://www.phreesoft.com
 * Description: Integrates your WordPress e-store with your Bizuno business
 * Version:     7.1
 * Author:      PhreeSoft, Inc. (support@phreesoft.com)
 * Author URI:  http://www.PhreeSoft.com
 * Text Domain: bizuno
 * License:     GNU Affero General Public License 3.0
 * License URI: https://www.gnu.org/licenses/agpl-3.0.txt
 * Domain Path: /locale
 */

defined( 'ABSPATH' ) || exit;

// Library files for plugin operations
require_once ( dirname(__FILE__) . '/lib/common.php' );
require_once ( dirname(__FILE__) . '/lib/admin.php' );
//require ( dirname(__FILE__) . '/lib/account.php' ); // need to finish development
require_once ( dirname(__FILE__) . '/lib/order.php' );
//require ( dirname(__FILE__) . '/lib/payment.php' ); // need to finish development
require_once ( dirname(__FILE__) . '/lib/product.php' );
require_once ( dirname(__FILE__) . '/lib/sales_tax.php' );
require_once ( dirname(__FILE__) . '/lib/shipping.php' );

// Load Woocommerce plugins only if WooCommerce is installed and active
if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
    require_once ( dirname( __FILE__ ) . '/plugins/payment-payfabric/payment-payfabric.php' );
    require_once ( dirname( __FILE__ ) . '/plugins/payment-purchase-order.php' );
    require_once ( dirname( __FILE__ ) . '/plugins/shipping-bizuno.php' );
}

class bizuno_api
{
    private $bizSlug   = 'bizuno';
    private $bizLib    = "bizuno-wp-library";
    private $bizLibURL = "https://bizuno.com/downloads/wordpress/plugins/bizuno-wp-library/bizuno-wp-library.latest-stable.zip";

    public function __construct()
    {
        register_activation_hook  ( __FILE__ , [ $this, 'activate' ] );
        register_deactivation_hook( __FILE__ , [ $this, 'deactivate' ] );
        $this->initializeBizuno();
        $this->admin    = new \bizuno\admin(); // loads/updates the options for the plugin
        $this->options  = $this->admin->options;
//      $this->account  = new \bizuno\account($this->options); // need to finish development
        $this->order    = new \bizuno\order($this->options);
//      $this->payment  = new \bizuno\payment($this->options); // need to finish development
        $this->product  = new \bizuno\product($this->options);
        $this->sales_tax= new \bizuno\sales_tax($this->options);
        $this->shipping = new \bizuno\shipping($this->options);
        // WordPress Actions
        add_action ( 'admin_menu',              [ $this->admin, 'bizuno_api_add_setting_submenu' ] );
        add_action ( 'init',                    [ $this, 'initializePlugin' ] );
        add_action ( 'rest_api_init',           [ $this, 'ps_register_rest' ] );
        add_action ( 'woocommerce_init',        [ $this, 'ps_woocommerce_init' ] );
        add_action ( 'plugins_loaded',          [ $this, 'ps_plugins_loaded' ] );
        add_action ( 'bizuno_api_image_process',[ $this->product, 'cron_image' ] );

        // WordPress Filters
        add_filter ( 'mime_types',              [ $this, 'biz_allow_webp_upload' ] ); // filter to allow mime type .webp images to be uploaded
        // WooCommerce hooks
        if ( in_array ( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
            // WooCommerce Actions
//          add_action ( 'woocommerce_cart_calculate_fees',                  [ $this->sales_tax,'bizuno_rest_sales_tax' ], 10, 1 );
            add_action ( 'manage_shop_order_posts_custom_column',            [ $this->admin,    'bizuno_api_order_column_content' ], 25, 2 ); // Work with Legacy
            add_action ( 'woocommerce_shop_order_list_table_custom_column',  [ $this->admin,    'bizuno_api_order_column_content_hpos' ], 25, 2 ); // Works with HPOS
            add_action ( 'woocommerce_admin_order_preview_end',              [ $this->admin,    'bizuno_api_order_preview_action' ] );
            add_action ( 'woocommerce_order_action_bizuno_export_action',    [ $this->order,    'bizuno_api_process_order_meta_box_action' ] );
            add_action ( 'wp_ajax_bizuno_api_order_download',                [ $this->order,    'bizuno_api_manual_download' ], 10);
            add_action ( 'woocommerce_thankyou',                             [ $this->order,    'bizuno_api_post_payment' ], 10, 1);
            add_action ( 'woocommerce_review_order_before_cart_contents',    [ $this->shipping, 'bizuno_validate_order' ], 10 );
            add_action ( 'woocommerce_after_checkout_validation',            [ $this->shipping, 'bizuno_validate_order' ], 10 );
            add_action ( 'shutdown',                                         [ $this,           'bizuno_write_debug' ], 999999 );

            // WooCommerce Filters
            add_filter ( 'woocommerce_rate_label',                           [ $this->sales_tax,'bizuno_fix_tax_label' ], 10, 2 );
            add_filter ( 'woocommerce_matched_rates',                        [ $this->sales_tax,'bizuno_get_rest_tax_rate' ], 10, 3 );
            add_filter ( 'woocommerce_shipping_tax_class',                   [ $this->shipping, 'force_bizuno_tax_class_for_shipping'], 100, 2 );
            add_filter ( 'woocommerce_shipping_is_taxable', '__return_true' );
            add_filter ( 'woocommerce_shipping_methods',                     [ $this->shipping, 'add_bizuno_shipping_method' ] );
            add_filter ( 'wc_order_statuses',                                [ $this->admin,    'add_shipped_to_order_statuses' ] );
            add_filter ( 'manage_edit-shop_order_columns',                   [ $this->admin,    'bizuno_api_order_column_header' ], 20 ); // Works with legacy
            add_filter ( 'woocommerce_shop_order_list_table_columns',        [ $this->admin,    'bizuno_api_order_column_header_hpos' ], 20 ); // works with HPOS
            add_filter ( 'woocommerce_admin_order_preview_get_order_details',[ $this->admin,    'bizuno_api_order_preview_filter' ], 10, 2);
            add_filter ( 'woocommerce_order_actions',                        [ $this->admin,    'bizuno_api_add_order_meta_box_filter' ] );
        }
    }
    
    /**
     * Initializes the Bizuno Environment
     */
    private function initializeBizuno()
    {
        global $msgStack, $db, $cleaner, $io, $wpdb; // , $html5, $portal
        // Locate the Library
        if ( !defined('BIZUNO_FS_LIBRARY' ) ) { // if not, then check to see if the library plugin is installed.
            if ( !in_array ( 'bizuno-wp-lib/bizuno-wp-lib.php', apply_filters( 'active_plugins', get_option ( 'active_plugins' ) ) ) ) {
                return $this->deactivateBizuno()();
            }        
            define( 'BIZUNO_FS_LIBRARY', WP_PLUGIN_DIR . '/bizuno-wp-lib/' );
        }
        // Business Specific
        if ( !defined( 'BIZUNO_BIZID' ) )       { define( 'BIZUNO_BIZID',       '12345' ); } // Bizuno Business ID [for multi-business]
        if ( !defined( 'BIZUNO_DATA' ) )        { define( 'BIZUNO_DATA',        wp_get_upload_dir()['basedir']."/$this->bizSlug/" ); } // Path to user files, cache and backup
        if ( !defined( 'BIZUNO_KEY' ) )         { define( 'BIZUNO_KEY',         '0123456789abcdef' ); } // Unique key used for encryption
        if ( !defined( 'BIZUNO_DB_PREFIX' ) )   { define( 'BIZUNO_DB_PREFIX',   $wpdb->prefix . 'bizuno_' ); } // Database table prefix
        if ( !defined( 'BIZUNO_DB_CREDS' ) )    { define( 'BIZUNO_DB_CREDS',    ['type'=>'mysql', 'host'=>DB_HOST, 'name'=>DB_NAME, 'user'=>DB_USER, 'pass'=>DB_PASSWORD, 'prefix'=>BIZUNO_DB_PREFIX ] ); }
        // Platform Specific - File System Paths
// Since the API only is access via REST, this plugin doesn't set the portal, so disable some constants
//      if ( !defined( 'BIZUNO_FS_PORTAL' ) )   { define( 'BIZUNO_FS_PORTAL',   plugin_dir_path( __FILE__ ) ); } // file system path to the portal
        if ( !defined( 'BIZUNO_FS_LIBRARY' ) )  { define( 'BIZUNO_FS_LIBRARY',  WP_PLUGIN_DIR . "/$this->bizLib/" ); }
        if ( !defined( 'BIZUNO_FS_ASSETS' ) )   { define( 'BIZUNO_FS_ASSETS',   WP_PLUGIN_DIR . "/$this->bizLib/assets/" ); } // contains third party php apps
        // Platform Specific - URL's
//      if ( !defined( 'BIZUNO_URL_AJAX' ) )    { define( 'BIZUNO_URL_AJAX',    admin_url(). 'admin-ajax.php?action=bizuno_ajax' ); }
//      if ( !defined( 'BIZUNO_URL_API' ) )     { define( 'BIZUNO_URL_API',     plugin_dir_url( __FILE__ ) . "portalAPI.php?bizRt=" ); }
//      if ( !defined( 'BIZUNO_URL_FS' ) )      { define( 'BIZUNO_URL_FS',      plugin_dir_url( __FILE__ ) . "portalAPI.php?bizRt=portal/api/fs&src=" ); }
//      if ( !defined( 'BIZUNO_URL_PORTAL' ) )  { define( 'BIZUNO_URL_PORTAL',  home_url() . "/$this->bizSlug?" ); } // full url to Bizuno root folder
//      if ( !defined( 'BIZUNO_URL_SCRIPTS' ) ) { define( 'BIZUNO_URL_SCRIPTS', plugins_url()."/$this->bizLib/scripts/" );  } // contains third party js and css files
        // Special case for WordPress
        if ( !defined( 'BIZUNO_STRIP_SLASHES' ) ) { define('BIZUNO_STRIP_SLASHES', true); } // WordPress adds slashes to all input data
        // Initialize & load Bizuno library
        require_once ( BIZUNO_FS_LIBRARY . 'portal/controller.php' );
        require_once ( BIZUNO_FS_LIBRARY . 'bizunoCFG.php' );
        // Instantiate Bizuno classes
        $msgStack = new \bizuno\messageStack();
        $cleaner  = new \bizuno\cleaner();
        $io       = new \bizuno\io();
        $db       = new \bizuno\db(BIZUNO_DB_CREDS);
    }

    /**
     * Initializes this plugins environment
     */
    public function initializePlugin()
    {
        add_rewrite_endpoint( 'biz-account-wallet',   EP_ROOT | EP_PAGES ); // for WC add wallet endpoint
        register_post_status( 'wc-shipped', [ // Add shipped status for API uploads
            'label'                    => 'Shipped',
            'public'                   => true,
            'exclude_from_search'      => false,
            'show_in_admin_all_list'   => true,
            'show_in_admin_status_list'=> true,
            'label_count'              => _n_noop( 'Shipped <span class="count">(%s)</span>', 'Shipped <span class="count">(%s)</span>' )] );
    }
    public function ps_woocommerce_init()
    {
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
    public function ps_register_rest()
    {
        if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) { // From Bizuno -> WordPress (uplink)
//            register_rest_route( 'bizuno-api/v1', 'sales_tax/calc',  ['methods' => 'POST', 'args'=>[],
//                'callback' => [ new \bizuno\sales_tax($this->options),'calc_tax' ] ] );
//            register_rest_route( 'bizuno-api/v1', 'shipping/rates',  ['methods' => 'GET', 'args'=>[],
//                'callback' => [ new \bizuno\shipping($this->options),'rates_list' ],     'permission_callback' => [$this, 'check_access'] ] );
            register_rest_route( 'bizuno-api/v1', 'product/update',  ['methods' => 'POST','args'=>[],
                'callback' => [ new \bizuno\product($this->options), 'product_update' ], 'permission_callback' => [$this, 'check_access'] ] );
            register_rest_route( 'bizuno-api/v1', 'product/refresh', ['methods' => 'PUT', 'args'=>[],
                'callback' => [ new \bizuno\product($this->options), 'product_refresh' ],'permission_callback' => [$this, 'check_access'] ] );
            register_rest_route( 'bizuno-api/v1', 'product/sync',    ['methods' => 'POST','args'=>[],
                'callback' => [ new \bizuno\product($this->options), 'product_sync' ],   'permission_callback' => [$this, 'check_access'] ] );
            register_rest_route( 'bizuno-api/v1', 'order/confirm',   ['methods' => 'POST','args'=>[],
                'callback' => [ new \bizuno\order($this->options),   'order_confirm' ],  'permission_callback' => [$this, 'check_access'] ] );
        }
    }
    public function check_access(WP_REST_Request $request)
    {
        $email = $request->get_header('email');
        $pass  = $request->get_header('pass');
        if (empty($email) || empty($pass)) { return false; }
        $userID= wp_authenticate( $email, $pass );
        return !empty($userID) ? true : false;
    }
    public function ps_plugins_loaded()
    {
        if ( ! is_plugin_active ( 'woocommerce/woocommerce.php' ) ) { return; }
        WC()->frontend_includes();
        if ( class_exists ( 'WC_Payment_Gateway' ) ) { // get instance of WooCommerce for Payfabric
            require ( plugin_dir_path ( __FILE__ ) . 'plugins/payment-payfabric/classes/class-payfabric-gateway-woocommerce.php' );
            Payfabric_Gateway_Woocommerce::get_instance();
        }
    }
    public function biz_allow_webp_upload($existing_mimes) { // allows image uploads of mime type webp
        $existing_mimes['webp'] = 'image/webp';
        return $existing_mimes;
    }
    public function bizuno_write_debug() {
        // This runs as the VERY LAST thing WordPress does
        // Right before PHP finishes execution and sends output
        if ( function_exists("\\bizuno\\msgDebugWrite" ) ) { \bizuno\msgDebugWrite(); }
    }

    public function activate()
    {
        global $wpdb;
        if ( is_plugin_active( 'woocommerce/woocommerce.php' ) ) { // set all existing orders to downloaded to hide Download button for past orders
            $orders = $wpdb->get_results( "SELECT `ID` FROM `{$wpdb->prefix}posts` WHERE `post_type` LIKE 'shop_order'", ARRAY_A);
            foreach ($orders as $order) {
                // @TODO - This doesn't work in HPOS mode, need fixin'
                update_post_meta($order['ID'], 'bizuno_order_exported', 'yes');
//              $wpdb->get_results( "INSERT INTO `{$wpdb->prefix}wc_orders_meta` ... WHERE `meta_key`='bizuno_order_exported'");
            }
            if (!wp_next_scheduled('bizuno_api_image_process')) { wp_schedule_event(time(), 'hourly', 'bizuno_api_image_process'); }
            $this->sales_tax->add_bizuno_tax_class();
        }
    }
    public function deactivate()
    {
        if (wp_next_scheduled('bizuno_api_image_process')) { wp_clear_scheduled_hook('bizuno_api_image_process'); }
        // @TODO - Remove Bizuno Tax class
        $this->sales_tax->remove_bizuno_tax_class();
    }
}
new bizuno_api();

register_uninstall_hook(__FILE__, 'bizuno_isp_uninstall');
function bizuno_isp_uninstall() {
    global $wpdb;
    $wpdb->get_results( "DELETE FROM `{$wpdb->prefix}wc_orders_meta` WHERE `meta_key`='bizuno_order_exported'");
}

add_action( 'wp_footer', function() {
    if ( is_admin() || ! current_user_can('manage_woocommerce') ) return;
    if ( WC()->cart && WC()->cart->needs_shipping_address() ) {
        echo '<!-- Bizuno Tax Hook IS ACTIVE -->';
    }
});
