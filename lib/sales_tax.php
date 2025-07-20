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
    function __construct($options=[])
    {
        parent::__construct($options);
    }
    function add_bizuno_tax_class( $tax_classes )
    {
        $tax_classes[] = 'bizuno-tax-rate';
        return $tax_classes;
    }
    function bizuno_tax_class_name( $name, $slug )
    {
        if ( $slug === 'bizuno-tax-rate' ) { $name = __( 'Bizuno Sales Tax', 'bizuno-api' ); }
        return $name;
    }
    public function bizuno_rest_sales_tax( $cart )
    {
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
        $this->options['url'] = 'https://www.bizuno.com';
        $resp = json_decode($this->cURL('post', $args, 'getSalesTax'), true);
        msgDebug("\nBizuno-API getSalesTax received back from REST: ".print_r($resp, true));
        // error check response
        
        $cart->add_fee( __( 'Sales Tax', 'bizuno-api' ), $resp['sales_tax'], false );
        $this->client_close();
    }
}
