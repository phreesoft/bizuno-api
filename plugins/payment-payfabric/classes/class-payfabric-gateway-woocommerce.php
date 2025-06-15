<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The core plugin class.
 * This is used to define admin-specific hooks and public-facing site hooks.
 */
class Payfabric_Gateway_Woocommerce
{
    /**
     * The *Singleton* instance of this class
     * @since    2.0.0
     *
     * @var Singleton
     */
    private static $instance;

    /**
     * Returns the *Singleton* instance of this class.
     * @since    2.0.0
     *
     * @return Singleton The *Singleton* instance.
     */
    public static function get_instance()
    {
        if (null == self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private clone method to prevent cloning of the instance of the
     * *Singleton* instance.
     * @since    2.0.0
     *
     * @return void
     */
    private function __clone()
    {
    }

    /**
     * Private unserialize method to prevent unserializing of the *Singleton*
     * instance.
     * @since    2.0.0
     *
     * @return void
     */
    public function __wakeup()
    {
        _doing_it_wrong( __FUNCTION__, __( 'Unserializing instances of this class is forbidden.', 'payfabric-gateway-woocommerce' ), '2.0.0' );
    }

    /**
     * Define the core functionality of the plugin.
     *
     * Load the dependencies and set the hooks for the admin area and
     * the public-facing side of the site.
     *
     */
    public function __construct()
    {
        $this->load_dependencies();
        $this->define_admin_hooks();
    }

    /**
     * Load the required dependencies for this plugin.
     *
     * @since    1.0.0
     * @access   private
     */
    private function load_dependencies()
    {
        /**
         * The class responsible for defining all actions that occur in the admin area.
         */
        require_once plugin_dir_path(dirname(__FILE__)) . 'admin/class-payfabric-gateway.php';
        require_once plugin_dir_path(dirname(__FILE__)) . 'admin/class-payfabric-gateway-request.php';
    }

    /**
     * Register all of the hooks related to the admin area functionality
     * of the plugin.
     *
     * @since    1.0.0
     * @access   private
     */
    private function define_admin_hooks()
    {
        $plugin_admin = new PayFabric();
        add_filter('woocommerce_payment_gateways',                     [$plugin_admin, 'add_new_gateway']);//Add new gateway as Woocommerce payment methods.
        add_action('woocommerce_update_options_payment_gateways_' . $plugin_admin->id, [$plugin_admin, 'process_admin_options']);//Update gateway
        add_action('woocommerce_receipt_payfabric',                    [$plugin_admin, 'receipt_page']);//Generate button or iframe ready to pay on receipt page
        add_action('wp',                                               [$plugin_admin, 'payfabric_response_handler']);//Payment response handler get
        add_action('wp_ajax_get_session',                              [$plugin_admin, 'get_session']);//Ajax request
        add_action('wp_ajax_nopriv_get_session',                       [$plugin_admin, 'get_session']);//Ajax request
        add_action('woocommerce_my_account_my_orders_actions',         [$plugin_admin, 'my_orders_actions']);//My account actions

        add_action('woocommerce_admin_order_data_after_shipping_address', [$plugin_admin, 'show_evo_transaction_id']);//Customize admin order detail page to show transaction ID
        add_action('woocommerce_api_payfabric',                        [$plugin_admin, 'handle_call_back']);//Payment response handler if a post request

        add_filter('woocommerce_order_actions',                        [$plugin_admin, 'add_capture_charge_order_action']);//add the Capture Online Order actions
        add_action('woocommerce_order_action_payfabric_capture_charge',[$plugin_admin, 'maybe_capture_charge']);//Capture when the Capture Online is submitted
        add_filter('woocommerce_order_actions',                        [$plugin_admin, 'add_void_charge_order_action']);//add the VOID Online Order actions
        add_action('woocommerce_order_action_payfabric_void_charge',   [$plugin_admin, 'maybe_void_charge']);//Void when the Void Online is submitted
    }
}
