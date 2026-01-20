<?php

/**
 * Plugin Name: Bizuno Payment PayFabric Gateway
 * Plugin URI: https://www.PhreeSoft.com
 * Description: WooCommerce PayFabric Gateway integration for use with Bizuno-Accounting plugin.
 * Version: 6.7
 * Author: PhreeSoft, Inc. (Enhanced from the Payfabric Woocommerce plugin @GitHub)
 *
 * Reference: https://github.com/PayFabric/WooCommerce-Plugin
 */

/**
 * Abort if the file is called directly
 */
if (!defined('WPINC')) { exit; }

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-payfabric-gateway-woocommerce-activator.php
 */
/*
function activate_payfabric_gateway_woocommerce()
{
    require_once plugin_dir_path(__FILE__) . 'plugins/classes/class-payfabric-gateway-woocommerce-activator.php';
    Payfabric_Gateway_Woocommerce_Activator::activate();
}
register_activation_hook(__FILE__, 'activate_payfabric_gateway_woocommerce'); */

/**
 * Run the plugin after all plugins are loaded
 */
/*function init_payfabric_gateway()
{
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }
    require plugin_dir_path(__FILE__) . 'classes/class-payfabric-gateway-woocommerce.php';
    Payfabric_Gateway_Woocommerce::get_instance();
}
add_action('plugins_loaded', 'init_payfabric_gateway', 0); */

// Add custom action links
function payfabric_gateway_action_links($links)
{
    $plugin_links = array(
        '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=payfabric') . '">' . __('Settings', 'bizuno-api') . '</a>',
    );
    return array_merge($plugin_links, $links);
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'payfabric_gateway_action_links');

// 2023-10-25 - Added by PhreeSoft to fetch the order ID to pass to PayFabric.
// This prevents a bug that causes a critical error on the site after payment has been processed as null is passed for the order ID
// to payfabric causing the callback to report null as the order ID to look up to complete the order.
add_action('woocommerce_checkout_order_processed', 'payfabric_fetch_order_id_before_payment', 10, 3);
function payfabric_fetch_order_id_before_payment( $order_id, $posted_data, $order ) {
    $GLOBALS['payfabric_pending_order_id'] = $order_id;
}
