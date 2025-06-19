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

if (!defined('ABSPATH')) { die('No script kiddies please!'); }

if (!defined('SCRIPT_START_TIME')) { define('SCRIPT_START_TIME', microtime(true)); }

/************** THIS NEEDS TO BE DYNAMIC TO SEE IF BIZUNO LIBRARY PLUGIN HAS BEEN LOADED *************/
define('BIZUNO_REPO', '/usr/share/bizuno/vendor/phreesoft/bizuno/');
define('BIZUNO_BIZID', 1);
//$upload_dir = wp_upload_dir();
//define ( 'BIZUNO_DATA',  $upload_dir['basedir'].'/bizuno/' );
define ( 'BIZUNO_DATA',  $_SERVER['PHP_DOCUMENT_ROOT'].'/private/' );
define('BIZUNO_KEY', '0123456S890yQ345'); // 16 alpha-num characters, randomly generated
// Database credentials
define('PORTAL_DB_PREFIX', $wpdb->prefix); // WordPress table prefix
define('BIZUNO_DB_PREFIX', $wpdb->prefix);
define('BIZPORTAL', ['type'=>'mysql','host'=>DB_HOST,'name'=>DB_NAME,'user'=>DB_USER,'pass'=>DB_PASSWORD,'prefix'=>PORTAL_DB_PREFIX]);

define('BIZUNO_PORTAL',  $_SERVER['SERVER_NAME']);

// File system
define('BIZUNO_PATH',   '/var/www/'.BIZUNO_PORTAL.'/web/');
define('BIZUNO_ASSETS', '/usr/share/bizuno/assets/');
// URL's
define('BIZUNO_SRVR',    'https://'.BIZUNO_PORTAL.'/');
define('BIZUNO_SERVERS', [
    '75.112.81.5' =>'fl1', '75.112.81.6' =>'fl2', '75.112.81.7' =>'fl3', '75.112.81.8' =>'fl4',
    '128.92.62.26'=>'tx1', '128.92.62.27'=>'tx2', '128.92.62.28'=>'tx3', '128.92.62.29'=>'tx4']);
$subDom = explode('.', BIZUNO_SERVERS[$_SERVER['SERVER_ADDR']])[0];
define('BIZUNO_SCRIPTS', "https://$subDom.bizuno.com/scripts/"); // pulled from a shared server

require_once ( BIZUNO_REPO . 'bizunoCFG.php'); // Config for current release
require_once ( BIZUNO_REPO . 'model/functions.php');
require_once ( BIZUNO_ASSETS . 'vendor/autoload.php'); // Load the libraries

//require_once ( dirname(__FILE__) . '/bizApiCFG.php' );
// Library files for plugin operations
require_once ( dirname(__FILE__) . '/lib/model.php');
require_once ( dirname(__FILE__) . '/lib/common.php' );
require_once ( dirname(__FILE__) . '/lib/admin.php' );
//require_once ( dirname(__FILE__) . '/lib/account.php' );
require_once ( dirname(__FILE__) . '/lib/order.php' );
//require_once ( dirname(__FILE__) . '/lib/payment.php' );
require_once ( dirname(__FILE__) . '/lib/product.php' );
require_once ( dirname(__FILE__) . '/lib/shipping.php' );

require_once ( BIZBOOKS_ROOT.'locale/cleaner.php');
//require_once(BIZBOOKS_ROOT.'locale/currency.php');
require_once ( BIZBOOKS_ROOT.'model/db.php');
//require_once(BIZBOOKS_ROOT.'model/encrypter.php');
require_once(BIZBOOKS_ROOT.'model/io.php');
require_once(BIZBOOKS_ROOT.'model/manager.php');
require_once(BIZBOOKS_ROOT.'model/msg.php');
//require_once(BIZBOOKS_ROOT.'model/mail.php');
//require_once(BIZBOOKS_ROOT.'view/main.php');
//require_once(BIZBOOKS_ROOT.'view/easyUI/html5.php');


if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
    require_once ( dirname( __FILE__ ) . '/plugins/payment-payfabric/payment-payfabric.php' );
    require_once ( dirname( __FILE__ ) . '/plugins/payment-purchase-order.php' );
    require_once ( dirname( __FILE__ ) . '/plugins/shipping-bizuno.php' );
}

class bizuno_api
{
    public function __construct()
    {
        global $msgStack, $db, $cleaner, $io, $wpdb; // , $html5, $portal
        // Activate/Uninstall hooks
        register_activation_hook  ( __FILE__ ,  [ $this, 'activate' ] );
        register_deactivation_hook( __FILE__ ,  [ $this, 'deactivate' ] );
        // initialize Bizuno
        $msgStack      = new \bizuno\messageStack();
        $cleaner       = new \bizuno\cleaner();
//      $html5         = new \bizuno\html5();
//      $portal        = new \bizuno\portal();
        $io            = new \bizuno\io();
        $db            = new \bizuno\db(['type'=>'mysql','host'=>DB_HOST,'name'=>DB_NAME,'user'=>DB_USER,'pass'=>DB_PASSWORD,'prefix'=>$wpdb->prefix]);
        $this->admin   = new \bizuno\admin(); // loads/updates the options for the plugin
        $this->options = $this->admin->options;
//      $this->account = new \bizuno\account($this->options);
        $this->order   = new \bizuno\order($this->options);
//      $this->payment = new \bizuno\payment($this->options);
        $this->product = new \bizuno\product($this->options);
//      $this->shipping= new \bizuno\shipping($this->options);
        // WordPress Actions
        add_action ( 'admin_menu',                [ $this->admin, 'bizuno_api_add_setting_submenu' ]);
        add_action ( 'init',                      [ $this, 'ps_init' ] );
        add_action ( 'rest_api_init',             [ $this, 'ps_register_rest' ] );
        add_action ( 'woocommerce_init',          [ $this, 'ps_woocommerce_init' ] );
//      add_action ( 'wp_ajax_bizuno_ajax',       [ $this, 'bizunoAjax' ] );
//      add_action ( 'wp_ajax_nopriv_bizuno_ajax',[ $this, 'bizunoAjax' ] );
        add_action ( 'plugins_loaded',            [ $this, 'ps_plugins_loaded' ] );
//      add_action ( 'edit_user_profile',         [ $this->account, 'bizunoUserEdit'] );
//      add_action ( 'show_user_profile',         [ $this->account, 'bizunoUserEdit'] );
//      add_action ( 'personal_options_update',   [ $this->account, 'bizunoUserSave'] );
//      add_action ( 'edit_user_profile_update',  [ $this->account, 'bizunoUserSave'] );
        add_action ( 'bizuno_api_image_process',  [ $this->product, 'cron_image' ] );
        // WordPress Filters
        add_filter ( 'mime_types',                [ $this, 'biz_allow_webp_upload' ] ); // filter to allow mime type .webp images to be uploaded
        // WooCommerce hooks
        if ( in_array ( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
            // WooCommerce Actions
            add_action ( 'manage_shop_order_posts_custom_column',            [ $this->admin,  'bizuno_api_order_column_content' ], 25, 2 ); // Work with Legacy???
//          add_action ( 'manage_woocommerce_page_wc-orders_custom_column',  [ $this->admin,  'bizuno_api_order_column_content' ], 25, 2 ); // Works with HPOS???
            add_action ( 'woocommerce_admin_order_preview_end',              [ $this->admin,  'bizuno_api_order_preview_action' ] );
            add_action ( 'woocommerce_order_action_bizuno_export_action',    [ $this->order,  'bizuno_api_process_order_meta_box_action' ] );
            add_action ( 'wp_ajax_bizuno_api_order_download',                [ $this->order,  'bizuno_api_manual_download' ], 10);
            add_action ( 'woocommerce_thankyou',                             [ $this->order,  'bizuno_api_post_payment' ], 10, 1);
            // WooCommerce Filters
//          add_filter ( 'woocommerce_account_menu_items',                   [ $this->account,'biz_add_woo_tabs' ], 999 );
            add_filter ( 'wc_order_statuses',                                [ $this->admin,  'add_shipped_to_order_statuses' ] );
            add_filter ( 'manage_edit-shop_order_columns',                   [ $this->admin,  'bizuno_api_order_column_header' ], 20 ); // Works with legacy???
//          add_filter ( 'manage_woocommerce_page_wc-orders_columns',        [ $this->admin,  'bizuno_api_order_column_header' ], 20 ); // works with HPOS???
            add_filter ( 'woocommerce_admin_order_preview_get_order_details',[ $this->admin,  'bizuno_api_order_preview_filter' ], 10, 2);
            add_filter ( 'woocommerce_order_actions',                        [ $this->admin,  'bizuno_api_add_order_meta_box_filter' ] );
        }
    }
    public function ps_init()
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
            register_rest_route( 'bizuno-api/v1', 'shipping/rates',  ['methods' => 'GET','args'=>[],
                'callback' => [ new \bizuno\shipping($this->options),'rates_list' ],     'permission_callback' => [$this, 'check_access'] ] );
            register_rest_route( 'bizuno-api/v1', 'product/update',  ['methods' => 'POST', 'args'=>[],
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
//return true;
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
    public static function activate()
    {
        global $wpdb;
        if ( is_plugin_active( 'woocommerce/woocommerce.php' ) ) { // set all existing orders to downloaded to hide Download button for past orders
            $orders = $wpdb->get_results( "SELECT `ID` FROM `{$wpdb->prefix}posts` WHERE `post_type` LIKE 'shop_order'", ARRAY_A);
            foreach ($orders as $order) {
                // @TODO - This doesn't work in HPOS mode, need fixin'
                update_post_meta($order['ID'], 'bizuno_order_exported', 'yes'); }
//              $wpdb->get_results( "INSERT INTO `{$wpdb->prefix}wc_orders_meta` ... WHERE `meta_key`='bizuno_order_exported'");
            if (!wp_next_scheduled('bizuno_api_image_process')) { wp_schedule_event(time(), 'hourly', 'bizuno_api_image_process'); }
        }
    }
    public static function deactivate()
    {
        if (wp_next_scheduled('bizuno_api_image_process')) { wp_clear_scheduled_hook('bizuno_api_image_process'); }
    }
}
new bizuno_api();

register_uninstall_hook(__FILE__, 'bizuno_isp_uninstall');
function bizuno_isp_uninstall() {
    global $wpdb;
    $wpdb->get_results( "DELETE FROM `{$wpdb->prefix}wc_orders_meta` WHERE `meta_key`='bizuno_order_exported'");
}
