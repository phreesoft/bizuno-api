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
 * @author     Dave Premo, Bizuno Project <support@bizuno.com>
 * @copyright  2008-2026, PhreeSoft, Inc.
 * @license    https://www.gnu.org/licenses/agpl-3.0.txt
 * @version    7.x Last Update: 2026-02-11
 * @filesource /lib/shipping.php
 */

namespace bizuno;

if ( ! defined( 'ABSPATH' ) ) exit;

class api_shipping extends api_common
{
    private $bizLibURL = "https://bizuno.com/downloads/latest/bizuno-wp.zip";

    function __construct()
    {
        parent::__construct();
    }
    /********************** Local get rate and return values *******************/
    public function getRates($package=[])
    {
        $package['destination']['totalWeight'] = WC()->cart->get_cart_contents_weight();
        $layout = ['pkg'=>$package, 'rates'=>[]];
        if (empty($package['destination']['postcode'])) { return $layout['rates']; }
        $this->client_open();
        msgDebug("\nCalling API with package = ".msgPrint($package['destination']));
        $resp = json_decode($this->cURL('get', $package['destination'], 'shipGetRates'), true);
        msgDebug("\nBizuno-API getRates received back from REST: ".msgPrint($resp));
        if (isset($resp['message'])) { msgMerge($resp['message']); }
        $layout['rates'] = !empty($resp['rates']) ? $resp['rates'] : [];
        msgDebug("\nSending back to WooCommerce: ".msgPrint($layout));
        $this->client_close();
        return $layout['rates'];
    }
    
    public function add_bizuno_shipping_method( $methods ) { // Add the method to the list of available Methods
        $methods['bizuno_shipping'] = 'Bizuno_API_Shipping_Method';
        return $methods;
    }

    public function bizuno_validate_order( $posted )   {
        $packages = WC()->shipping->get_packages();
        $chosen_methods = WC()->session->get( 'chosen_shipping_methods' );
        if ( is_array( $chosen_methods ) && in_array( 'bizplus', $chosen_methods ) ) {
            foreach ( $packages as $i => $package ) {
                if ( $chosen_methods[ $i ] != 'bizplus' ) { continue; }
                $BizPlus_Shipping_Method = new BizPlus_Shipping_Method();
                $weightLimit = (int) $BizPlus_Shipping_Method->settings['weight'];
                $weight = 0;
                foreach ( $package['contents'] as $values ) {
                    $_product = $values['data'];
                    $weight = $weight + $_product->get_weight() * $values['quantity'];
                }
                $weight = wc_get_weight( $weight, 'kg' );
                if ( $weight > $weightLimit ) {
                    $message = sprintf( 'Sorry, %d kg exceeds the maximum weight of %d kg for %s', $weight, $weightLimit, $BizPlus_Shipping_Method->title );
                    $messageType = 'error'; // 'success', 'error', 'notice'
                    if ( ! wc_has_notice( $message, $messageType ) ) {
                        wc_add_notice( $message, $messageType );
                    }
                }
            }
        }
    }
}
