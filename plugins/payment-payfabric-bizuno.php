<?php
/**
 * WooCommerce - Payment Method - Payfabric
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * DISCLAIMER
 * Do not edit or add to this file if you wish to upgrade Bizuno to newer
 * versions in the future. If you wish to customize Bizuno for your
 * needs please contact PhreeSoft for more information.
 *
 * @name       Bizuno ERP
 * @author     Dave Premo, PhreeSoft <support@phreesoft.com>
 * @copyright  2008-2026, PhreeSoft, Inc.
 * @license    https://www.gnu.org/licenses/agpl-3.0.txt
 * @version    7.x Last Update: 2026-01-19
 * @filesource /bizuno-api/lib/payment_converge.php
 */

/***************************************************************************************************/
//  Payment Method - Credit Card (PayFabric the Bizuno way)
/***************************************************************************************************/

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Custom Payment Gateway.
 *
 * Provides a Custom Payment Gateway, mainly for testing purposes.
 */
add_action('plugins_loaded', 'bizuno_api_gateway_class');
function bizuno_api_gateway_class(){

    class WC_Gateway_PayFabric extends WC_Payment_Gateway {

        public $domain = 'bizuno-api';

        /**
         * Constructor for the gateway.
         */
        public function __construct() {
            $this->id                 = 'custom';
            $this->icon               = apply_filters('woocommerce_custom_gateway_icon', '');
            $this->has_fields         = false;
            $this->method_title       = __( 'Custom', 'bizuno-api' );
            $this->method_description = __( 'Allows payments with custom gateway.', 'bizuno-api' );

            // Load the settings.
            $this->init_form_fields();
            $this->init_settings();

            // Define user set variables
            $this->title        = $this->get_option( 'title' );
            $this->description  = $this->get_option( 'description' );
            $this->instructions = $this->get_option( 'instructions', $this->description );
            $this->order_status = $this->get_option( 'order_status', 'completed' );

            // Actions
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
            add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );

            // Customer Emails
            add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
        }

        /**
         * Initialise Gateway Settings Form Fields.
         */
        public function init_form_fields() {

            $this->form_fields = array(
                'enabled' => array(
                    'title'   => __( 'Enable/Disable', 'bizuno-api' ),
                    'type'    => 'checkbox',
                    'label'   => __( 'Enable Custom Payment', 'bizuno-api' ),
                    'default' => 'yes'
                ),
                'title' => array(
                    'title'       => __( 'Title', 'bizuno-api' ),
                    'type'        => 'text',
                    'description' => __( 'This controls the title which the user sees during checkout.', 'bizuno-api' ),
                    'default'     => __( 'Custom Payment', 'bizuno-api' ),
                    'desc_tip'    => true,
                ),
                'order_status' => array(
                    'title'       => __( 'Order Status', 'bizuno-api' ),
                    'type'        => 'select',
                    'class'       => 'wc-enhanced-select',
                    'description' => __( 'Choose whether status you wish after checkout.', 'bizuno-api' ),
                    'default'     => 'wc-completed',
                    'desc_tip'    => true,
                    'options'     => wc_get_order_statuses()
                ),
                'description' => array(
                    'title'       => __( 'Description', 'bizuno-api' ),
                    'type'        => 'textarea',
                    'description' => __( 'Payment method description that the customer will see on your checkout.', 'bizuno-api' ),
                    'default'     => __('Payment Information', 'bizuno-api'),
                    'desc_tip'    => true,
                ),
                'instructions' => array(
                    'title'       => __( 'Instructions', 'bizuno-api' ),
                    'type'        => 'textarea',
                    'description' => __( 'Instructions that will be added to the thank you page and emails.', 'bizuno-api' ),
                    'default'     => '',
                    'desc_tip'    => true,
                ),
            );
        }

        /**
         * Output for the order received page.
         */
        public function thankyou_page() {
            if ( $this->instructions ) { echo wp_kses_post ( wpautop( wptexturize( $this->instructions ) ) ); }
        }

        /**
         * Add content to the WC emails.
         *
         * @access public
         * @param WC_Order $order
         * @param bool $sent_to_admin
         * @param bool $plain_text
         */
        public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
            if ( $this->instructions && ! $sent_to_admin && 'custom' === $order->payment_method && $order->has_status( 'on-hold' ) ) {
                echo wp_kses_post ( wpautop ( wptexturize( $this->instructions ) ) ) . PHP_EOL;
            }
        }

        public function payment_fields(){

            if ( $description = $this->get_description() ) {
                echo wp_kses_post ( wpautop ( wptexturize( $description ) ) );
            }

            ?>
            <div id="custom_input">
                <p class="form-row form-row-wide">
                    <label for="mobile" class=""><?php esc_html_e('Mobile Number', 'bizuno-api'); ?></label>
                    <input type="text" class="" name="mobile" id="mobile" placeholder="" value="">
                </p>
                <p class="form-row form-row-wide">
                    <label for="transaction" class=""><?php esc_html_e('Transaction ID', 'bizuno-api'); ?></label>
                    <input type="text" class="" name="transaction" id="transaction" placeholder="" value="">
                </p>
            </div>
            <?php
        }

        /**
         * Process the payment and return the result.
         *
         * @param int $order_id
         * @return array
         */
        public function process_payment( $order_id ) {

            $order = wc_get_order( $order_id );

            $status = 'wc-' === substr( $this->order_status, 0, 3 ) ? substr( $this->order_status, 3 ) : $this->order_status;

            // Set order status
            $order->update_status( $status, __( 'Checkout with custom payment. ', 'bizuno-api' ) );

            // or call the Payment complete
            // $order->payment_complete();

            // Reduce stock levels
            $order->reduce_order_stock();

            // Remove cart
            WC()->cart->empty_cart();

            // Return thankyou redirect
            return array(
                'result'    => 'success',
                'redirect'  => $this->get_return_url( $order )
            );
        }
    }
}

add_filter( 'woocommerce_payment_gateways', 'bizuno_api_payfabric_gateway_class' );
function bizuno_api_payfabric_gateway_class( $methods ) {
    $methods[] = 'WC_Gateway_PayFabric';
    return $methods;
}

add_action('woocommerce_checkout_process', 'bizuno_api_payfabric_payment');
function bizuno_api_payfabric_payment(){

    if($_POST['payment_method'] != 'custom')
        return;

    if( !isset($_POST['mobile']) || empty($_POST['mobile']) )
        wc_add_notice( __( 'Please add your mobile number', 'bizuno-api' ), 'error' );


    if( !isset($_POST['transaction']) || empty($_POST['transaction']) )
        wc_add_notice( __( 'Please add your transaction ID', 'bizuno-api' ), 'error' );

}

/**
 * Update the order meta with field value
 */
add_action( 'woocommerce_checkout_update_order_meta', 'bizuno_api_payfabric_update_order_meta' );
function bizuno_api_payfabric_update_order_meta( $order_id ) {

    if($_POST['payment_method'] != 'custom') { return; }

    // echo "<pre>";
    // print_r($_POST);
    // echo "</pre>";
    // exit();

    update_post_meta( $order_id, 'mobile', $_POST['mobile'] );
    update_post_meta( $order_id, 'transaction', $_POST['transaction'] );
}

/**
 * Display field value on the order edit page
 */
add_action( 'woocommerce_admin_order_data_after_billing_address', 'bizuno_api_payfabric_order_meta', 10, 1 );
function bizuno_api_payfabric_order_meta($order){
    $method = get_post_meta( $order->id, '_payment_method', true );
    if($method != 'custom') { return; }

    $mobile = get_post_meta( $order->id, 'mobile', true );
    $transaction = get_post_meta( $order->id, 'transaction', true );

    echo '<p><strong>'. esc_html ( __( 'Mobile Number', 'bizuno-api' ) ) . ':</strong> ' . esc_html ( $mobile ) . '</p>';
    echo '<p><strong>'.esc_html ( __( 'Transaction ID', 'bizuno-api' ) ) . ':</strong> ' . esc_html ($transaction ) . '</p>';
}