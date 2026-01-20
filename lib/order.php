<?php
/**
 * Bizuno API WordPress Plugin - order class
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
 * @version    7.x Last Update: 2026-01-17
 * @filesource /lib/order.php
 */

namespace bizuno;

if ( ! defined( 'ABSPATH' ) ) exit;

class order extends common
{
    public  $userID= 0;
    private $host  = 'https://www.payfabric.com';

    function __construct($options=[]) {
        parent::__construct($options);
    }
    /**************** REST Endpoints to set tracking info *************/
    public function order_confirm($request) { // RESTful API to set the order tracking information
        $data   = $this->rest_open($request);
        $result = $this->shipConfirm(!empty($data['data']) ? $data['data'] : []);
        $output = ['result'=>!empty($result)?'Success':'Fail'];
        return $this->rest_close($output);
    }

    /********************* Hooks for WooCommerce  *************************/
    public function bizuno_before_calculate_totals($cart) {
        if (is_admin() && !defined('DOING_AJAX')) { return; }
        if (did_action('woocommerce_before_calculate_totals') >= 2) { return; }
        foreach ($cart->get_cart() as $item) {
            $product = $item['data'];
            $qty     = $item['quantity'];
            $tiers = $product->get_meta('_bizuno_price_tiers', true);
            if (empty($tiers) || !is_array($tiers)) { continue; }
            $best_price = null;
            foreach ($tiers as $tier) {
                if ($qty >= $tier['qty']) {
                    $best_price = (float)$tier['price'];
                } else { break; } // tiers are sorted → safe to stop
            }
            if ($best_price !== null && $best_price < $product->get_price('edit')) { $item['data']->set_price($best_price); }
        }
    }

    public function bizuno_enforce_bulk_increment($args, $product) {
        $tiers = $product->get_meta('_bizuno_price_tiers', true);
        if (empty($tiers) || !is_array($tiers)) {
            return $args; // No tiers → standard behavior
        }
        // Sort tiers and get the lowest (first) tier quantity
        usort($tiers, function($a, $b) { return (int)$a['qty'] <=> (int)$b['qty']; });
        $lowest_tier_qty = (int)$tiers[0]['qty']; // e.g., 20 for box of 20 AA batteries
        // Only apply if lowest tier is > 1 (i.e., sold in packs)
        if ($lowest_tier_qty > 1) {
            $args['min_value'] = $lowest_tier_qty;
            $args['step']      = $lowest_tier_qty;
        }
        return $args;
    }

    public function bizuno_validate_bulk_quantity($passed, $product_id, $quantity) {
        $product = wc_get_product($product_id);
        if (!$product) return $passed;
        $tiers = $product->get_meta('_bizuno_price_tiers', true);
        if (empty($tiers) || !is_array($tiers)) return $passed;
        usort($tiers, function($a, $b) { return (int)$a['qty'] <=> (int)$b['qty']; });
        $lowest_tier_qty = (int)$tiers[0]['qty'];
        if ($lowest_tier_qty > 1) {
            if ($quantity < $lowest_tier_qty || $quantity % $lowest_tier_qty !== 0) {
                wc_add_notice(sprintf(
                    'This product is sold in packs of %d. Please order in multiples of %d (e.g., %d, %d, %d).',
                    $lowest_tier_qty,
                    $lowest_tier_qty,
                    $lowest_tier_qty,
                    $lowest_tier_qty * 2,
                    $lowest_tier_qty * 3
                ), 'error');
                return false;
            }
        }
        return $passed;
    }

    /************ Hooks for WooCommerce Order Admin page ****************/
    function bizuno_enqueue_payfabric_scripts() {
        if ( ! is_checkout() && ! is_wc_endpoint_url( 'order-pay' ) ) { return; } // Only load on pages where it's actually needed
        $js_url = $this->host . '/Payment/WebGate/Content/bundles/payfabricpayments.bundle.js';
        wp_enqueue_script(
            'payfabric-sdk',                // unique handle
            $js_url,                        // full URL or plugin-relative path
            array(),                        // dependencies (add 'jquery' if needed)
            null,                           // version (null = no cache busting, or use your version)
            true                            // load in footer = true (recommended for most scripts)
        );
    }
    
    public function bizuno_api_post_payment($order_id)
    {
        msgDebug("\nEntering bizuno_api_post_payment with order_id = $order_id and bizuno_api_autodownload = ".\get_option ( 'bizuno_api_autodownload', false )." and bizuno_order_exported = ".print_r(\get_post_meta ( $order_id, 'bizuno_order_exported' ), true));
        if ( !empty ( \get_post_meta ( $order_id, 'bizuno_order_exported' ) ) ) { return; } // already downloaded, prevents duplicate download errors
        if ( in_array ( \get_option ( 'bizuno_api_autodownload', false ), ['on', 'yes', 1] ) ) {
            $this->orderExport($order_id); // call return to bit bucket as as all messsages are suppressed
        }
    }

    public function bizuno_api_process_order_meta_box_action( $order )
    {
        $this->bizuno_api_manual_download($order->id);
    }
    public function bizuno_api_manual_download( $order_id = 0 ) {
        if ( empty( $order_id ) ) { $order_id = (int) $_GET['biz_order_id'] ?? 0; }
        if ( empty( $order_id ) ) {
            wc_add_notice( __( 'No order ID provided.', 'bizuno-api' ), 'error' );
            wp_safe_redirect( esc_url( admin_url( 'edit.php?post_type=shop_order' ) ) );
            exit;
        }
        $resp = $this->orderExport( $order_id );
        $this->setNotices( $resp );
        wp_safe_redirect( esc_url( admin_url( 'edit.php?post_type=shop_order' ) ) );
        exit;
    }
    public function bizuno_api_order_download($order_id)
    {
        $this->orderExport($order_id);
    }
    /**
     * Uses the WordPress REST API to download an order to Bizuno
     * @param string $orderID
     * @return array with two elements 'result' => string message level 'error','warning','success'; 'message' => result message
     */
    public function orderExport($orderID=false)
    {
        if ( empty ( $orderID ) ) { magAdd("Bad orderID passed: $orderID"); return; }
        $this->client_open();
        if (!$order = $this->mapOrder($orderID)) { msgDebug("\nError mapping order = ".print_r($order, true));  } // return;
        msgDebug("\nMapped order = ".print_r($order, true));
        $resp   = json_decode($this->cURL('post', $order, 'orderAdd'), true);
        $mainID = !empty($resp['ID']) ? $resp['ID'] : 0;
        msgDebug("\npost processing with orderID = $orderID and mainID = $mainID and response = ".print_r($resp, true));
        if ( !empty($mainID) ) {
            msgDebug("\nUpdating post meta as a valid ID was returned.");
            $wcOrder = \wc_get_order($orderID);
            $wcOrder->update_meta_data('bizuno_order_exported', 'yes');
            $wcOrder->save_meta_data();
            $wcOrder->save();
        }
        $this->client_close();
        return $resp;
    }

    /**
     * Converts the WooCommerce order to the Bizuno API format
     * @param type $order_id - WooCommerce order ID
     * @return type
     */
    private function mapOrder($order_id)
    {
        $order = \wc_get_order($order_id);
        // @TODO - get the transaction ID, Payfabric does not set the standard WooCommerce reference,
        // instead they create a postmeta key = _transaction_id
        // Need to properly set this in payfabric method: $order->set_transaction_id($transaction_id); $order->save(); 
        $transID = !empty($order->get_transaction_id()) ? $order->get_transaction_id() : get_post_meta($order_id, '_transaction_id', true);
        $map = [
            'General' => [
//              'OrderID'         => $this->options['prefix_order'] . $order->get_id(), // force a new invoice
                'PurchaseOrderID' => $this->options['prefix_order'] . $order->get_id(),
                'OrderDate'       => substr($order->get_date_created(), 0, 10),
                'OrderTotal'      => $order->get_total(),
//              'DiscountTotal'   => $order->get_total_discount(),
                'SalesTaxAmount'  => $this->getTaxAmount($order),
//              'SalesTaxPercent' => '',
//              'SalesTaxTitle'   => '',
                'ShippingTotal'   => $order->get_shipping_total(),
                'ShippingCarrier' => $order->get_shipping_method(),
                'OrderNotes'      => $order->get_customer_note()],
            'Payment' => [
                'Method'          => $order->get_payment_method(),
                'Title'           => $order->get_payment_method_title(),
                'Status'          => $order->get_status(),
//              'Authorization'   => $order_info['_payment_auth_code'], // Authorization code from credit cards that need to be captured to complete the sale
                'TransactionID'   => $transID], // transaction ID from gateway
            'Billing' => [
                'CustomerID'      => $this->options['prefix_customer'].$order->get_customer_id(),
                'PrimaryName'     => !empty($order->get_billing_company()) ? $order->get_billing_company() : $order->get_formatted_billing_full_name(),
                'Contact'         => !empty($order->get_billing_company()) ? $order->get_formatted_billing_full_name() : '',
                'Address1'        => $order->get_billing_address_1(),
                'Address2'        => $order->get_billing_address_2(),
                'City'            => $order->get_billing_city(),
                'State'           => $order->get_billing_state(),
                'PostalCode'      => $order->get_billing_postcode(),
                'Country'         => $order->get_billing_country(),
                'Telephone'       => $order->get_billing_phone(),
                'Email'           => $order->get_billing_email()],
            'Shipping' => [
                'PrimaryName'     => !empty($order->get_shipping_company())  ? $order->get_shipping_company()  : $order->get_formatted_shipping_full_name(),
                'Contact'         => !empty($order->get_shipping_company())  ? $order->get_formatted_shipping_full_name() : '',
                'Address1'        => !empty($order->get_shipping_address_1())? $order->get_shipping_address_1(): $order->get_billing_address_1(),
                'Address2'        => !empty($order->get_shipping_address_2())? $order->get_shipping_address_2(): $order->get_billing_address_2(),
                'City'            => !empty($order->get_shipping_city())     ? $order->get_shipping_city()     : $order->get_billing_city(),
                'State'           => !empty($order->get_shipping_state())    ? $order->get_shipping_state()    : $order->get_billing_state(),
                'PostalCode'      => !empty($order->get_shipping_postcode()) ? $order->get_shipping_postcode() : $order->get_billing_postcode(),
                'Country'         => !empty($order->get_shipping_country())  ? $order->get_shipping_country()  : $order->get_billing_country(),
                'Telephone'       => !empty($order->get_shipping_phone())    ? $order->get_shipping_phone()    : $order->get_billing_phone(),
                'Email'           => $order->get_billing_email()]];
        $this->mapProducts($map, $order);
        return $map; // json_encode($Map, JSON_UNESCAPED_UNICODE);
    }
    private function getTaxAmount($order)
    {
        $temp = $order->get_tax_totals();
        $arrTax = array_shift($temp);
        return !empty($arrTax->amount) ? $arrTax->amount : 0;
    }
    private function mapProducts(&$map, $order)
    {
        $map['Item'] = [];
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            $map['Item'][] = [
                'ItemID'          => $product->get_sku(),
                'Description'     => $product->get_name(),
                'Quantity'        => $item->get_quantity(),
                'UnitPrice'       => $item->get_subtotal()/$item->get_quantity(),
//              'SalesTaxPercent' => '',
//              'SalesTaxTitle'   => '',
                'SalesTaxAmount'  => $item->get_subtotal_tax(),
                'TotalPrice'      => $item->get_total()];
        }
        return $map['Item'];
    }
    /*******************************************************************/
    /**
     * Updates order status
     * @return null - fills the messageStack
     */
    public function shipConfirm($orders=[])
    {
        msgDebug("\nEntering shipConfirm"); // with options = ".print_r($this->options, true));
        $order_cnt = 0;
        $order_list= [];
        $prefix    = $this->options['prefix_order'];
        $status    = 'wc-shipped';
        if (!isset($orders['head'])) { return msgAdd("No orders were sent to confirm!", 'info'); }
        foreach ($orders['head'] as $oID => $value) {
            // strip prefix from order ref (WC3044 => 3044) that is the record number
            if     ($prefix && (strpos($oID, $prefix) !== 0 || strpos($oID, $prefix) === false)) { continue; }
            elseif ($prefix &&  strpos($oID, $prefix) === 0) { $id = substr($oID, strlen($prefix)); }
            else   { $id  = intval($oID); }
            $msg   = $value . '<br />'.$this->extractTracking($orders['body'][$oID]);
            $order = \wc_get_order ( $id );
            if (!empty($order)) {
                $curStat = $order->get_status();
                if ('shipped'<>$curStat) {
                    $order->add_order_note ( $msg, 1 );
                    $order->update_status ( $status );
                    $order_cnt++;
                    $order_list[] = $oID;
                }
            }
        }
        msgAdd(sprintf($this->lang['confirm_success'], sizeof($order_list), sizeof($order_list)>0?" (".implode(', ', $order_list).")":''), 'success');
        msgDebug("\nLeaving shipConfirm with order count = $order_cnt");
        return true;
    }
    
    private function extractTracking($pkg=[])
    {
        if (empty($pkg['rows'])) { return ''; }
        $tracking = [];
        foreach ($pkg['rows'] as $box) {
            if (!empty($box['tracking_id'])) { $tracking[] = $box['tracking_id']; }
        }
        return implode(', ', $tracking);
    }
}
