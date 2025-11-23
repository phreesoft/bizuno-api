<?php
/**
 * Bizuno API WordPress Plugin - sales tax class
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
 * @filesource /lib/sales_tax.php
 */

namespace bizuno;

class sales_tax extends common
{
    private $ShipTaxSt= ['AR','CT','GA','IL','KS','KY','MI','MS','NE','NJ','NM','NY',
        'NC','ND','OH','OK','PA','RI','SC','SD','TN','TX','UT','VT','WA','WV','WI'];

    function __construct($options=[])
    {
        parent::__construct($options);
    }

    /**
     * Adds the Bizuno sales tax option to WooCommerce
     */
    public function add_bizuno_tax_class() {
        $existing_classes = \get_option( 'woocommerce_tax_classes', '' );
        $new_class = 'Bizuno Sales Tax';
        // If it's a string (old format), handle as such
        if ( is_string( $existing_classes ) ) {
            $classes_array = array_filter( array_map( 'trim', explode( "\n", $existing_classes ) ) );
            if ( !in_array( $new_class, $classes_array ) ) {
                $classes_array[] = $new_class;
                \update_option( 'woocommerce_tax_classes', implode( "\n", $classes_array ) );
            }
        } else { // If array (modern), add directly
            if ( !in_array( $new_class, $existing_classes ) ) {
                $existing_classes[] = $new_class;
                \update_option( 'woocommerce_tax_classes', $existing_classes );
            }
        }
    }

    /**
     * Removes the Bizuno sales tax option from WooCommerce
     */
    public function remove_bizuno_tax_class()
    {
        msgDebug("\nEntering remove_bizuno_tax_class.");
        // @TODO - This needs to be written
    }

    // 1. Completely bypass WooCommerce's internal tax lookup for your class
    function bizuno_get_rest_tax_rate( $matched_rates, $tax_class ) {
        msgDebug("\nEntering bizuno_rest_sales_tax.");
//        if ( $tax_class !== 'bizuno-sales-tax' ) { return $rate; } // let normal rates work
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) { return; }
        if ( empty($this->options['tax_enable'] ) )    { return; }

        // This is the only place that is guaranteed to run on every tax line
        $customer = WC()->customer ?: new WC_Customer();
        $freight  = WC()->cart->get_shipping_total();
        $total    = WC()->cart->get_cart_contents_total();
        $postcode = $customer->get_shipping_postcode() ?: $customer->get_billing_postcode();
        $city     = $customer->get_shipping_city()     ?: $customer->get_billing_city();
        $state    = $customer->get_shipping_state()    ?: $customer->get_billing_state();
        $country  = $customer->get_shipping_country()  ?: $customer->get_billing_country();

        msgDebug("\npostcode = $postcode and country = $country");
        if ( empty( $postcode ) || $country !== 'US' ) { return 0.0; }
msgTrap();
        $zip = substr( preg_replace('/[^0-9]/', '', $postcode), 0, 5 );
        // Cache per ZIP (24h)
        $cache_key = 'bizuno_tax_' . $zip;
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) { 
            $rate = $cached; // this is the rate as decimal (8.25, not 0.0825)
        } else {
            $args = ['freight'=>$freight, 'total'=>$total, 'city'=>$city, 'state'=>$state, 'zip'=>$postcode, 'country'=>$country];
            $this->client_open();
            if (empty($args['zip'])) { return; }
            $isTaxable = in_array($args['state'], $this->options['tax_nexus']) ? true : false;
            if (!$isTaxable) { return; }
            msgDebug("\nCalling API with args = ".print_r($args, true));
            $resp = json_decode($this->cURL('post', $args, 'getSalesTax'), true);
            msgDebug("\nBizuno-API getSalesTax received back from REST: ".print_r($resp, true));
            // error check response

            $this->client_close();
            $rate = empty($resp['rate']) ? 0 : $resp['rate'];
        }
        $output = [ 1 => [  // Integer key: Arbitrary rate ID (use 1 for single rate)
            'rate'     => $rate * 100,     // Float: 8.25 (not 0.0825)
            'label'    => 'Bizuno Sales Tax',
            'shipping' => in_array($state, $this->ShipTaxSt) ? 'yes' : 'no',
            'compound' => 'no' ] ];
        set_transient( $cache_key, $rate, DAY_IN_SECONDS );
        msgDebug("\nReturning with rate array = ".print_r($output, true));
        return $output;
}

function bizuno_fix_tax_label( $label, $rate_id ) {
    error_log('reached bizuno_fix_tax_label'); return;
    // $rate_id is sometimes the rate array, sometimes ID – handle both
//    if ( is_array( $rate_id ) ) { $rate_id = $rate_id['tax_rate_id'] ?: 0; }
    // All our rates come from the filter above → just force the label
//    if ( strpos( $label, 'bizuno' ) !== false || $label === '' ) { return 'Bizuno Sales Tax'; }
    return $label;
}

/*
 * This is VERY VERY slow. 
 */
    public function bizuno_rest_sales_tax( $cart )
    {
        msgDebug("\nEntering bizuno_rest_sales_tax.");
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) { return; }
        if ( empty($this->options['tax_enable'] ) )    { return; }
        $args = [
            'freight'=> WC()->cart->get_shipping_total(),
            'total'  => WC()->cart->get_cart_contents_total(),
            'city'   => WC()->customer->get_shipping_city(),
            'state'  => WC()->customer->get_shipping_state(),
            'zip'    => WC()->customer->get_shipping_postcode(),
            'country'=> WC()->customer->get_shipping_country()];
        $this->client_open();
        if (empty($args['zip'])) { return; }
        $isTaxable = in_array($args['state'], $this->options['tax_nexus']) ? true : false;
        if (!$isTaxable) { return; }
        msgDebug("\nCalling API with args = ".print_r($args, true));
        $resp = json_decode($this->cURL('post', $args, 'getSalesTax'), true);
        msgDebug("\nBizuno-API getSalesTax received back from REST: ".print_r($resp, true));
        // error check response
        
        $cart->add_fee( __( 'Sales Tax', 'bizuno-api' ), $resp['sales_tax'], false );
        $this->client_close();
    }
}
