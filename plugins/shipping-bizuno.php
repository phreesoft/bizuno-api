<?php
/**
 * WooCommerce - Shipping Methods
 * This class contains the  methods to handle orders paid via purchase orders with approved credit
 *
 * @copyright  2008-2024, PhreeSoft, Inc.
 * @author     David Premo, PhreeSoft, Inc.
 * @version    3.x Last Update: 2023-10-04
 * @filesource /bizuno-api/lib/shipping.php
 */

if ( ! defined( 'WPINC' ) ) { die; }

/***************************************************************************************************/
//  Hook to Calculate cart freight charges using Bizuno shipping preferences
/***************************************************************************************************/
function bizuno_shipping_method_init() {
    if ( ! class_exists( 'WC_Bizuno_Shipping_Method' ) ) {
        class WC_Bizuno_Shipping_Method extends WC_Shipping_Method {
            public function __construct( $instance_id = 0 ) { // set the method properties
                $this->id                 = 'bizuno_shipping';
                $this->title              = __( 'Bizuno Shipping Calculator', 'bizuno-pro' );
                $this->instance_id        = absint( $instance_id );
                $this->method_title       = __( 'Bizuno Shipping', 'bizuno-pro' );
                $this->method_description = __( 'Calculate shipping methods and costs through the Bizuno Accounting plugin', 'bizuno-pro' );
                $this->supports           = ['shipping-zones', 'instance-settings', 'instance-settings-modal', ];
                $this->init();
            }
            public function init() { // Initialize the method
                $this->init_form_fields();
                $this->init_settings();
                add_action( 'woocommerce_update_options_shipping_' . $this->id, [$this, 'process_admin_options']);
            }
            public function init_form_fields() { // The settings
                $this->instance_form_fields = [
                    'enabled'=> [ 'title'=> __( 'Enable', 'bizuno-pro' ),'type'=>'checkbox','default'=>'no',
                        'description'=> __( 'Enable Bizuno Accounting calculated shipping', 'bizuno-pro' ) ],
                    'title'  => [ 'title'=> __( 'Title', 'bizuno-pro' ), 'type'=>'text',    'default'=> __( 'Shipper Preference', 'bizuno-pro' ),
                        'description'=> __( 'Title to be display on site', 'bizuno-pro' ) ] ];
            }
            public function calculate_shipping( $package=[] ) { // Connect to Bizuno and Calculate Shipping charges
                $admin = new \bizuno\api_admin();
                $api   = new \bizuno\api_shipping($admin->options);
                $rates = $api->getRates($package);
                foreach ($rates as $rate) {
                    $wooRate = ['id'=>$rate['id'], 'label'=>$rate['title'], 'cost'=>$rate['quote']];
                    $this->add_rate( $wooRate );
                }
            }
        }
    }
}
add_action( 'woocommerce_shipping_init', 'bizuno_shipping_method_init' );
function add_bizuno_shipping_method( $methods ) { // Add the method to the list of available Methods
    $methods['bizuno_shipping'] = 'WC_Bizuno_Shipping_Method';
    return $methods;
}
add_filter( 'woocommerce_shipping_methods', 'add_bizuno_shipping_method' );
function bizuno_validate_order( $posted )   {
    $packages = WC()->shipping->get_packages();
    $chosen_methods = WC()->session->get( 'chosen_shipping_methods' );
    if ( is_array( $chosen_methods ) && in_array( 'tutsplus', $chosen_methods ) ) {
        foreach ( $packages as $i => $package ) {
            if ( $chosen_methods[ $i ] != "tutsplus" ) { continue; }
            $TutsPlus_Shipping_Method = new TutsPlus_Shipping_Method();
            $weightLimit = (int) $TutsPlus_Shipping_Method->settings['weight'];
            $weight = 0;
            foreach ( $package['contents'] as $item_id => $values ) {
                $_product = $values['data'];
                $weight = $weight + $_product->get_weight() * $values['quantity'];
            }
            $weight = wc_get_weight( $weight, 'kg' );
            if ( $weight > $weightLimit ) {
                $message = sprintf( __( 'Sorry, %d kg exceeds the maximum weight of %d kg for %s', 'tutsplus' ), $weight, $weightLimit, $TutsPlus_Shipping_Method->title );
                $messageType = 'error'; // 'success', 'error', 'notice'
                if ( ! wc_has_notice( $message, $messageType ) ) {
                    wc_add_notice( $message, $messageType );
                }
            }
        }
    }
}
add_action( 'woocommerce_review_order_before_cart_contents', 'bizuno_validate_order' , 10 );
add_action( 'woocommerce_after_checkout_validation', 'bizuno_validate_order' , 10 );

/***************************************************************************************************/
//  Hook to Calculate cart freight charges requesting a quote. For quote requests.
/***************************************************************************************************/
/*
function quote_shipping_method_init() {
    if ( ! class_exists( 'WC_Quote_Shipping_Method' ) ) {
        class WC_Quote_Shipping_Method extends WC_Shipping_Method {
            public function __construct( $instance_id = 0 ) { // set the method properties
                $this->id                 = 'quote_shipping';
                $this->title              = __( 'Shipping Quote', 'bizuno-pro' );
                $this->instance_id        = absint( $instance_id );
                $this->method_title       = __( 'Quote Shipping', 'bizuno-pro' );
                $this->method_description = __( 'Sets Shipping at Zero and turns the order into a Request for Quote in Bizuno', 'bizuno-pro' );
                $this->supports           = ['shipping-zones', 'instance-settings', 'instance-settings-modal', ];
                $this->init();
            }
            public function init() { // Initialize the method
                $this->init_form_fields();
                $this->init_settings();
                add_action( 'woocommerce_update_options_shipping_' . $this->id, [$this, 'process_admin_options']);
            }
            public function init_form_fields() { // The settings
                $this->instance_form_fields = [
                    'enabled'=> [ 'title'=> __( 'Enable', 'bizuno-pro' ),'type'=>'checkbox','default'=>'no',
                        'description'=> __( 'Enable Quote Shipping', 'bizuno-pro' ) ],
                    'title'  => [ 'title'=> __( 'Title', 'bizuno_pro' ), 'type'=>'text',    'default'=> __( 'Quote Shipping', 'bizuno-pro' ),
                        'description'=> __( 'Title to be display on site', 'bizuno-pro' ) ] ];
            }
            public function calculate_shipping( $package=[] ) {
                $this->add_rate( [ 'id'=>$this->id, 'label'=>$this->title, 'cost'=>0 ] );
            }
        }
    }
}
add_action( 'woocommerce_shipping_init', 'quote_shipping_method_init' );
function add_quote_shipping_method( $methods ) { // Add the method to the list of available Methods
    $methods['quote_shipping'] = 'WC_Quote_Shipping_Method';
    return $methods;
}
add_filter( 'woocommerce_shipping_methods', 'add_quote_shipping_method' );
function quote_validate_order()   {
    $chosen_methods = WC()->session->get( 'chosen_shipping_methods' );
    if ( is_array( $chosen_methods ) && in_array( 'TBD', $chosen_methods ) ) {
        $message = __( 'This order is either being purchased or shipped outside of our standard delivery area. Your order will be reviewed by our Customer Service team and you will receive a confirmation email with additional information once the order has been processed. No payment is required at this time, The order will be handled as a Request for Quote.', 'bizuno-pro' );
        $messageType = 'notice';
        if ( ! wc_has_notice( $message, $messageType ) ) { wc_add_notice( $message, $messageType ); }
    }
}
add_action( 'woocommerce_review_order_before_cart_contents', 'quote_validate_order', 10 );
add_action( 'woocommerce_after_checkout_validation', 'quote_validate_order', 10 );
*/
