<?php
/**
 * Plugin Name:       Bizuno API – Secure WooCommerce & ERP Integration
 * Plugin URI:        https://www.phreesoft.com
 * Description:       Secure RESTful API bridge for real-time sync between WooCommerce and Bizuno ERP/Accounting: orders, inventory, customers, prices, and more.
 * Version:           7.3.6
 * Requires at least: 6.5
 * Tested up to:      6.9
 * Requires PHP:      8.0
 * Author:            PhreeSoft, Inc.
 * Author URI:        https://www.phreesoft.com
 * Author Email:      support@phreesoft.com
 * Text Domain:       bizuno
 * Domain Path:       /locale
 * License:           AGPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/agpl-3.0.txt
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
    private $bizLib    = "bizuno-wp";
    private $bizLibURL = "https://bizuno.com/downloads/latest/bizuno-wp.zip";
    private $bizExists = false;

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
        add_action ( 'admin_notices',           [ $this, 'bizAdminNotices' ], 20 );
        // WordPress Filters
        add_filter ( 'mime_types',              [ $this, 'biz_allow_webp_upload' ] ); // filter to allow mime type .webp images to be uploaded
        // WooCommerce hooks
        if ( in_array ( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
            // WooCommerce Actions
//add_action('woocommerce_before_add_to_cart_button', [ $this->order,    'bizuno_bulk_pack_note']);
            add_action('woocommerce_before_add_to_cart_form',                [ $this->product,  'bizuno_single_product_summary'], 10);
//          add_action ( 'woocommerce_single_product_summary',               [ $this->product,  'bizuno_single_product_summary'], 25);
            add_action ( 'woocommerce_before_calculate_totals',              [ $this->order,    'bizuno_before_calculate_totals' ], 9999 );
            add_action ( 'manage_shop_order_posts_custom_column',            [ $this->admin,    'bizuno_api_order_column_content' ], 25, 2 ); // Work with Legacy
            add_action ( 'woocommerce_shop_order_list_table_custom_column',  [ $this->admin,    'bizuno_api_order_column_content_hpos' ], 25, 2 ); // Works with HPOS
            add_action ( 'woocommerce_admin_order_preview_end',              [ $this->admin,    'bizuno_api_order_preview_action' ] );
            add_action ( 'woocommerce_order_action_bizuno_export_action',    [ $this->order,    'bizuno_api_process_order_meta_box_action' ] );
            add_action ( 'wp_ajax_bizuno_api_order_download',                [ $this->order,    'bizuno_api_manual_download' ], 10);
            add_action ( 'woocommerce_order_status_processing',              [ $this->order,    'bizuno_api_post_payment' ], 10, 1);
            add_action ( 'woocommerce_review_order_before_cart_contents',    [ $this->shipping, 'bizuno_validate_order' ], 10 );
            add_action ( 'woocommerce_after_checkout_validation',            [ $this->shipping, 'bizuno_validate_order' ], 10 );
            add_action ( 'shutdown',                                         [ $this,           'bizuno_write_debug' ], 999999 );

            // WooCommerce Filters
            add_filter('woocommerce_quantity_input_args',                    [ $this->order,    'bizuno_enforce_bulk_increment'], 10, 2);
            add_filter('woocommerce_add_to_cart_validation',                 [ $this->order,    'bizuno_validate_bulk_quantity'], 10, 3);
            add_filter ( 'woocommerce_shipping_methods',                     [ $this->shipping, 'add_bizuno_shipping_method' ] );
            add_filter ( 'wc_order_statuses',                                [ $this->admin,    'add_shipped_to_order_statuses' ] );
            add_filter ( 'manage_edit-shop_order_columns',                   [ $this->admin,    'bizuno_api_order_column_header' ], 20 ); // Works with legacy
            add_filter ( 'woocommerce_shop_order_list_table_columns',        [ $this->admin,    'bizuno_api_order_column_header_hpos' ], 20 ); // works with HPOS
            add_filter ( 'woocommerce_admin_order_preview_get_order_details',[ $this->admin,    'bizuno_api_order_preview_filter' ], 10, 2);
            add_filter ( 'woocommerce_order_actions',                        [ $this->admin,    'bizuno_api_add_order_meta_box_filter' ] );
            if ($this->options['tax_enable']=='yes') {
                add_filter ( 'woocommerce_matched_rates',                    [ $this->sales_tax,'bizuno_get_rest_tax_rate' ], 10, 3 );
                add_filter ( 'woocommerce_package_rates',                    [ $this->shipping, 'bizuno_override_shipping_tax_class' ], 20, 2 );
            }
        }
    }

    /**
     * Initializes the Bizuno Environment
     */
    private function initializeBizuno()
    {
        if ( !is_plugin_active( "$this->bizLib/$this->bizLib.php" ) || !file_exists( WP_PLUGIN_DIR . '/' . "$this->bizLib/$this->bizLib.php" ) ) {
            add_action( 'admin_notices', function() {
                echo '<div class="notice notice-warning"><p>The Bizuno Accounting plugin now requires the Bizuno library plugin available from the Bizuno project website. Click <a href="https://dspind.com/wp-admin/admin.php?page=get-bizuno">HERE</a> to download the plugin!</p></div>';
            });
            return;
        } else { $this->bizExists = true; }
        global $msgStack, $cleaner, $db, $io;
        require_once ( plugin_dir_path( __FILE__ ) . 'portalCFG.php' ); // Set Bizuno environment
        if ( !defined( 'BIZUNO_URL_VIEW' ) ) { define( 'BIZUNO_URL_VIEW', WP_PLUGIN_URL . "/$this->bizLib" );  } // contains Bizuno images, icons, css and js
        if (!isset($msgStack)|| !($msgStack instanceof \bizuno\messageStack)){ $msgStack = new \bizuno\messageStack(); }
        if (!isset($cleaner) || !($cleaner  instanceof \bizuno\cleaner))     { $cleaner  = new \bizuno\cleaner(); }
        if (!isset($io)      || !($io       instanceof \bizuno\io))          { $io       = new \bizuno\io(); }
        if (!isset($db)      || !($db       instanceof \bizuno\db))          { $db       = new \bizuno\db(BIZUNO_DB_CREDS); }
        \bizuno\msgDebug("\nFinished instantiating Bizuno.");
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
//          register_rest_route( 'bizuno-api/v1', 'sales_tax/calc',  ['methods' => 'POST', 'args'=>[],
//              'callback' => [ new \bizuno\sales_tax($this->options),'calc_tax' ] ] );
//          register_rest_route( 'bizuno-api/v1', 'shipping/rates',  ['methods' => 'GET', 'args'=>[],
//              'callback' => [ new \bizuno\shipping($this->options),'rates_list' ],     'permission_callback' => [$this, 'check_access'] ] );
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
        \bizuno\msgDebug("\nEntering check_access");
        $email = $request->get_header('email');
        $pass  = $request->get_header('pass');
        if (empty($email) || empty($pass)) { return false; }
        $userID= wp_authenticate( $email, $pass );
        return !empty($userID) ? true : false;
    }
    public function admin_menu_bizuno()
    {
        if ( $this->bizExists ) {
            add_menu_page( 'Bizuno', 'Bizuno', 'manage_options', 'bizuno', 'bizuno_html', 
                plugins_url( 'icon_16.png', WP_PLUGIN_DIR . "/$this->bizLib/$this->bizLib.php" ), 90);            
        } else {
            add_menu_page( 'GET BIZUNO', 'GET BIZUNO', 'manage_options', 'get-bizuno', 'get_bizuno_html',
                plugins_url( 'icon_16.png', WP_PLUGIN_DIR . "/bizuno-api/bizuno-apip.php" ), 1);
        }
    }

    public function bizunoLibTest( $requirements, $plugin_file )
    {
        if ( plugin_basename( __FILE__ ) !== $plugin_file || defined('BIZUNO_FS_LIBRARY' )) { return $requirements; }
        if ( !in_array ( "$this->bizLib/$this->bizLib.php", apply_filters( 'active_plugins', get_option ( 'active_plugins' ) ) ) ) {
            $required["$this->bizLib/$this->bizLib.php"] = $this->bizLibURL;
        }
        return $required;
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
    public function bizAdminNotices() {
        $user_id = get_current_user_id();
        $key     = "bizuno_order_download_notices_{$user_id}";
        $notices = get_transient( $key );
        if ( ! $notices ) { return; }
        delete_transient( $key );
        foreach ( $notices as $n ) { printf( '<div class="%s"><p>%s</p></div>', esc_attr( $n['class'] ), wp_kses_post( $n['message'] ) ); }
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
        }
    }
    public function deactivate()
    {
        if (wp_next_scheduled('bizuno_api_image_process')) { wp_clear_scheduled_hook('bizuno_api_image_process'); }
    }
}
new bizuno_api();

function get_bizuno_html()
{
    if (!current_user_can('manage_options')) { wp_die('Insufficient permissions'); }
    echo '<div class="wrap">
        <h1>Get Bizuno (Latest version from the Bizuno.com website)</h1>';
        if (isset($_POST['bizuno_install_private'])) {
            check_admin_referer('bizuno_install_private');
            if (bizuno_install_and_activate_project_plugin()) { return; }
        }
        echo '<form method="post">';
        wp_nonce_field('bizuno_install_private');
        echo '<p>This will download and install the full Bizuno plugin from bizuno.com.</p>
            <p><strong>No license key required</strong> – it’s now publicly available.</p>';
        submit_button('Get Bizuno Now', 'primary', 'bizuno_install_private');
        echo '</form></div>';
}

function bizuno_install_and_activate_project_plugin() {
    if ( is_plugin_active('bizuno/bizuno.php' ) ) {
        echo '<div class="updated"><p>Bizuno ERP is already installed and active!</p></div>';
        return;
    }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
    $download_url = 'https://bizuno.com/downloads/latest/bizuno-wp.zip'; // bizLibURL is inside the class so re-define locally
    $tmp_file = download_url($download_url);
    if (is_wp_error($tmp_file)) {
        echo '<div class="error"><p>Failed to download Bizuno: ' . esc_html($tmp_file->get_error_message()) . '</p></div>';
        return;
    }
    $upgrader = new Plugin_Upgrader(new WP_Upgrader_Skin());
    $installed = $upgrader->install($tmp_file, ['overwrite_package' => true]);
    @unlink($tmp_file); // clean up temp file
    if (!$installed || is_wp_error($installed)) {
        echo '<div class="error"><p>Installation failed.</p></div>';
        return;
    }
    $plugin_path = '/bizuno-wp/bizuno-wp.php';
    if ( file_exists( WP_PLUGIN_DIR . $plugin_path ) ) { 
        $activated = activate_plugin( $plugin_path, '', false, true );
        if (is_wp_error($activated)) {
            echo '<div class="error"><p>Installed but failed to activate: ' . esc_html($activated->get_error_message()) . '</p></div>';
        } else {
            echo '<div class="updated"><p><strong>Bizuno ERP has been successfully installed and activated!</strong></p>';
            echo '<p><a href="' . home_url("/bizuno") . '" class="button button-primary" target="_blank">Go to Bizuno Dashboard →</a></p></div>'; // bizSlug is inside the class so re-define locally
        }
        return true;
    }
    echo '<div class="error"><p>Failed to activate Bizuno!</p></div>';
}

register_uninstall_hook(__FILE__, 'bizuno_isp_uninstall');
function bizuno_isp_uninstall() {
    global $wpdb;
    $wpdb->get_results( "DELETE FROM `{$wpdb->prefix}wc_orders_meta` WHERE `meta_key`='bizuno_order_exported'");
}
