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
 * @version    7.x Last Update: 2025-12-19
 * @filesource /lib/sales_tax.php
 */

namespace bizuno;

class sales_tax extends common
{
    function __construct($options=[])
    {
        parent::__construct($options);
    }

    function bizuno_get_rest_tax_rate( $matched_rates, $tax_class ) {
        msgDebug("\nEntering bizuno_get_rest_tax_rate.");
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) { return; }
        if ( in_array ( $this->options['tax_enable'], ['no', 0] ) ) { return; } // tax_enable used to be 0,1 and is now WP compliant: no, yes
        $rate  = $this->getSalesTaxRate();
        $output= [ 1 => [  // Integer key: Arbitrary rate ID (use 1 for single rate)
            'rate'     => $rate * 100,     // Float: 8.25 (not 0.0825)
            'label'    => 'Bizuno Sales Tax',
            'shipping' => in_array($state, $this->ShipTaxSt) ? 'yes' : 'no',
            'compound' => 'no' ] ];
        msgDebug("\nReturning with rate array = ".print_r($output, true));
        return $output;
    }
}
