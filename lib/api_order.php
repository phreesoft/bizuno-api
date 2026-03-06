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
 * @author     Dave Premo, Bizuno Project <support@bizuno.com>
 * @copyright  2008-2026, PhreeSoft, Inc.
 * @license    https://www.gnu.org/licenses/agpl-3.0.txt
 * @version    7.x Last Update: 2026-02-22
 * @filesource /lib/order.php
 */

namespace bizuno;

if ( ! defined( 'ABSPATH' ) ) exit;

class api_order extends api_common
{
    public  $userID= 0;
    private $host  = 'https://www.payfabric.com';
    public  $locale= [
        'confirm_success' => "Order status update complete, the following %s order(s) were updated: %s",
    ];


    function __construct()
    {
        parent::__construct();
    }
    /**************** REST Endpoints to set tracking info *************/
    public function order_confirm($request)
    {
        $data   = $this->rest_open($request);
        $result = $this->shipConfirm(!empty($data['data']) ? $data['data'] : []);
        $output = ['result'=>!empty($result)?'Success':'Fail'];
        return $this->rest_close($output);
    }

    /********************* Hooks for WooCommerce  *************************/
    public function bizuno_before_calculate_totals($cart)
    {
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

    public function bizuno_enforce_bulk_increment($args, $product)
    {
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

    public function bizuno_validate_bulk_quantity($passed, $product_id, $quantity)
    {
        $product = wc_get_product($product_id);
        if (!$product) { return $passed; }
        $tiers = $product->get_meta('_bizuno_price_tiers', true);
        if (empty($tiers) || !is_array($tiers)) { return $passed; }
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
    public function bizuno_api_post_payment( $order_id ) {
        msgDebug("\nEntering bizuno_api_post_payment with order_id = $order_id and bizuno_api_autodownload = " . get_option( 'bizuno_api_autodownload', false ) );
        if ( ! $order_id ) { return; }
        // Load the order object early (HPOS-safe)
        $order = wc_get_order( $order_id );
        if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
            msgDebug("Invalid or missing WC_Order for ID $order_id - aborting export");
            return;
        }
        if ( $order->get_meta( 'bizuno_order_exported', true ) == 'yes' ) {
            msgDebug("Order #{$order->get_id()} already exported - skipping");
            return;
        }
        msgDebug("Auto-download enabled - exporting order #".$order->get_id());
        $this->orderExport( $order->get_id() );  // or pass $order if orderExport accepts object
    }

    public function bizuno_order_meta_box_action( $order ) {
        // Same export logic
        $order_id = $order->get_id();
        $resp = $this->orderExport( $order_id );
        $this->setNotices( $resp );
        // Redirect to summary page (instead of back to edit screen)
        wp_safe_redirect( admin_url( 'edit.php?post_type=shop_order' ) );
        exit;
    }


    public function bizuno_export_order_handler() {
        $order_id = absint( $_GET['biz_order_id'] ?? 0 );
        if ( ! $order_id || ! check_admin_referer( 'bizuno_export_order' ) ) {
            wp_die( __( 'Security check failed or invalid order.', 'bizuno-api' ) );
        }
        if ( ! current_user_can( 'edit_shop_orders' ) ) { wp_die( __( 'No permission.', 'bizuno-api' ) ); }
        $resp = $this->orderExport( $order_id );
        $this->setNotices( $resp );
        wp_safe_redirect( admin_url( 'edit.php?post_type=shop_order' ) );
        exit;
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
        if (!$order = $this->mapOrder($orderID)) { msgDebug("\nError mapping order = ".msgPrint($order));  } // return;
        msgDebug("\nMapped order = ".msgPrint($order));
        $apiResp= json_decode($this->cURL('post', $order, 'orderAdd'), true );
        msgDebug("\nBizuno-API orderExport received back from REST: ".msgPrint($apiResp));
        $mainID = !empty($apiResp['ID']) ? $apiResp['ID'] : 0;
        msgDebug("\npost processing with orderID = $orderID and mainID = $mainID and response = ".msgPrint($apiResp));
        if ( !empty($mainID) ) {
            msgDebug("\nUpdating post meta as a valid ID was returned.");
            $wcOrder = \wc_get_order($orderID);
            $wcOrder->update_meta_data('bizuno_order_exported', 'yes');
            $wcOrder->save_meta_data();
            $wcOrder->save();
        }
        $this->client_close();
        return $apiResp;
    }

    /**
     * Converts the WooCommerce order to the Bizuno API format
     * @param type $order_id - WooCommerce order ID
     * @return type
     */
    private function mapOrder($order_id)
    {
        $order = \wc_get_order($order_id);
        $options = get_option( BIZUNO_API_OPT_GROUP, [] );
        msgDebug("\nEntering mapOrder with order_id = $order_id and order transaction ID = ".msgPrint($order->get_meta('_transaction_id', true)));
        msgDebug("\norder get_transaction_id = ".msgPrint($order->get_transaction_id()));
        $transID = !empty($order->get_transaction_id()) ? $order->get_transaction_id() : $order->get_meta('_transaction_id', true);
        $map = [
            'General' => [
//              'OrderID'         => $options['prefix_order'] . $order->get_id(), // force a new invoice
                'PurchaseOrderID' => $options['prefix_order'] . $order->get_id(),
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
                'CustomerID'      => $options['prefix_customer'].$order->get_customer_id(),
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
        $options = get_option( BIZUNO_API_OPT_GROUP, [] );
        msgDebug("\nEntering shipConfirm with orders = ".msgPrint($orders));
        $order_cnt = 0;
        $order_list= [];
        $prefix    = $options['prefix_order'];
        $status    = 'wc-shipped';
        if (!isset($orders['head'])) { return msgAdd("No orders were sent to confirm!", 'info'); }
        foreach ($orders['head'] as $oID => $value) {
            // strip prefix from order ref (WC3044 => 3044) that is the record number
            if     ($prefix && (strpos($oID, $prefix) !== 0 || strpos($oID, $prefix) === false)) { continue; }
            elseif ($prefix &&  strpos($oID, $prefix) === 0) { $id = substr($oID, strlen($prefix)); }
            else   { $id  = intval($oID); }
            $msg   = $value . '<br />'.$orders['body'][$oID];
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
        msgAdd(sprintf($this->locale['confirm_success'], sizeof($order_list), sizeof($order_list)>0?" (".implode(', ', $order_list).")":''), 'success');
        msgDebug("\nLeaving shipConfirm with order count = $order_cnt");
        return true;
    }
}
