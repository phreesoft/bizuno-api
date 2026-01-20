<?php

/**
 * Fired during plugin activation
 *
 * @since      1.0.0
 *
 * @package    PayFabric_Gateway_Woocommerce
 * @subpackage IPayFabric_Gateway_Woocommerce/includes
 */
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @since      1.0.0
 * @package    PayFabric_Gateway_Woocommerce
 * @subpackage PayFabric_Gateway_Woocommerce/includes
 */
class Payfabric_Gateway_Woocommerce_Activator
{

    /**
     * Check for Woocommerce version on activation
     *
     * @since    1.0.0
     */
    public static function activate()
    {
        if (!class_exists('woocommerce') || !function_exists('WC')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die( wp_kses_post ( __('PayFabric Gateway for Woocommerce requires Woocommerce version 3.0 or higher', 'bizuno-api'), __('Plugin Activation Error', 'bizuno-api'), array('response' => 200, 'back_link' => TRUE)));

        }
        if (version_compare(WC()->version, "3.0", '<')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die( wp_kses_post ( __('PayFabric Gateway for Woocommerce requires Woocommerce version 3.0 or higher', 'bizuno-api'), __('Plugin Activation Error', 'bizuno-api'), array('response' => 200, 'back_link' => TRUE)));
        }
    }
}
