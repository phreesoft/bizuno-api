<?php
/**
 * Bizuno API WordPress Plugin - Bizuno shipping method
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
 * @version    7.x Last Update: 2026-01-19
 * @filesource /lib/shipping.php
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/***************************************************************************************************/
//  Adds Bizuno shipping method class to Calculate cart freight charges using Bizuno shipping preferences
/***************************************************************************************************/
function bizuno_shipping_method_init() {
    if ( ! class_exists( 'Bizuno_API_Shipping_Method' ) ) {
        class Bizuno_API_Shipping_Method extends WC_Shipping_Method {
            public function __construct( $instance_id = 0 ) { // set the method properties
                $this->id                 = 'bizuno_shipping';
                $this->title              = __( 'Bizuno Shipping Calculator', 'bizuno-api' );
                $this->instance_id        = absint( $instance_id );
                $this->method_title       = __( 'Bizuno Shipping', 'bizuno-api' );
                $this->method_description = __( 'Calculate shipping methods and costs through the Bizuno Accounting plugin', 'bizuno-api' );
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
                    'enabled'=> [ 'title'=> __( 'Enable', 'bizuno-api' ),'type'=>'checkbox','default'=>'no',
                        'description'=> __( 'Enable Bizuno Accounting calculated shipping', 'bizuno-api' ) ],
                    'title'  => [ 'title'=> __( 'Title', 'bizuno-api' ), 'type'=>'text',    'default'=> __( 'Shipper Preference', 'bizuno-api' ),
                        'description'=> __( 'Title to be display on site', 'bizuno-api' ) ] ];
            }
            public function calculate_shipping( $package=[] ) { // Connect to Bizuno and Calculate Shipping charges
                $admin = new \bizuno\admin();
                $api   = new \bizuno\shipping($admin->options);
                $rates = $api->getRates($package);
                foreach ($rates as $rate) {
                    $wooRate = ['id'=>$rate['id'], 'label'=>$rate['title'], 'cost'=>$rate['quote']];
                    $this->add_rate( $wooRate );
                }
            }
        }
    }
}
add_action ( 'woocommerce_shipping_init', 'bizuno_shipping_method_init' );
