<?php
/**
 * Bizuno API WordPress Plugin - shipping class
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
 * @copyright  2008-2025, PhreeSoft, Inc.
 * @license    https://www.gnu.org/licenses/agpl-3.0.txt
 * @version    7.x Last Update: 2025-07-20
 * @filesource /lib/shipping.php
 */

namespace bizuno;

class shipping extends common
{
    function __construct($options=[])
    {
        parent::__construct($options);
    }
    /********************** Local get rate and return values *******************/
    public function getRates($package=[])
    {
        $package['destination']['totalWeight'] = WC()->cart->get_cart_contents_weight();
        $layout = ['pkg'=>$package, 'rates'=>[]];
        if (empty($package['destination']['postcode'])) { return $layout['rates']; }
        $this->client_open();
        msgDebug("\nCalling API with package = ".print_r($package['destination'], true));
        $resp = json_decode($this->cURL('get', $package['destination'], 'shipGetRates'), true);
        msgDebug("\nBizuno-API getRates received back from REST: ".print_r($resp, true));
        if (isset($resp['message'])) { msgMerge($resp['message']); }
        $layout['rates'] = !empty($resp['rates']) ? $resp['rates'] : [];
        msgDebug("\nSending back to WooCommerce: ".print_r($layout, true));
        $this->client_close();
        return $layout['rates'];
    }
    
    public function add_bizuno_shipping_method( $methods ) { // Add the method to the list of available Methods
        $methods['bizuno_shipping'] = 'WC_Bizuno_Shipping_Method';
        return $methods;
    }
    
    function force_bizuno_tax_class_for_shipping( $tax_class, $shipping_method ) {
        // Only override if the product(s) in cart use your custom class
        foreach ( WC()->cart->get_cart() as $item ) {
            $product_tax_class = $item['data']->get_tax_class();
            if ( $product_tax_class === 'bizuno-sales-tax' ) {
                return 'bizuno-sales-tax'; // ← this forces shipping to use your class
            }
        }
        return $tax_class; // fallback to normal behavior
    }

    public function bizuno_validate_order( $posted )   {
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
}
