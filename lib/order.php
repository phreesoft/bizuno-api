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
 * @version    7.x Last Update: 2025-12-10
 * @filesource /lib/order.php
 */

namespace bizuno;

class order extends common
{
    public $userID = 0;

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

    /**
     *
     * @param type $order_id
     * @return type
     */
    public function bizuno_api_post_payment($order_id) {
        if ( !empty ( \get_post_meta ( $order_id, 'bizuno_order_exported' ) ) ) { return; } // already downloaded, prevents duplicate download errors
//        \update_post_meta ( $order_id, 'bizuno_order_exported', 0 ); // Does nothing in HPOS
        if ( !empty ( \get_option ( 'bizuno_api_autodownload', false ) ) ) {
            $this->orderExport($order_id); // call return to bit bucket as as all messsages are suppressed
            $wooOrder = new \WC_Order($order_id);
            $wooOrder->update_status('processing');
        }
    }

    /************ Hooks for WooCommerce Order Admin page ****************/
    public function bizuno_api_process_order_meta_box_action( $order ) {
        $this->bizuno_api_manual_download($order->id);
    }
    public function bizuno_api_manual_download($order_id = 0) {
        if (empty($order_id)) { $order_id = (int)$_GET['biz_order_id']; }
        $this->orderExport($order_id);
//      $this->setNotices(); // this is broken, may not need?
        wp_redirect( esc_url(admin_url( 'edit.php?post_type=shop_order') ) );
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
        if ( empty ( $orderID ) ) { error_log("Bad orderID passed: $orderID"); return; }
        $this->client_open();
        if (!$order = $this->mapOrder($orderID)) { msgDebug("\nError mapping order = ".print_r($order, true));  } // return;
        msgDebug("\nMapped order = ".print_r($order, true));
        $resp   = json_decode($this->cURL('post', $order, 'orderAdd'), true);
        $mainID = !empty($resp['ID']) ? $resp['ID'] : 0;
        msgDebug("\npost processing with orderID = $orderID and mainID = $mainID and response = ".print_r($resp, true));
        if ( !empty($mainID) ) {
            msgDebug("\nUpdating post meta as a valid ID was returned.");
            $wcOrder = new \WC_Order($orderID);
            $wcOrder->update_meta_data('bizuno_order_exported', 'yes');
            $wcOrder->save_meta_data();
            $wcOrder->save;
        }
        $this->client_close();
    }

    /**
     * Converts the WooCommerce order to the Bizuno API format
     * @param type $order_id - WooCommerce order ID
     * @return type
     */
    private function mapOrder($order_id) {
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
        if (!empty($this->options['tax_enable'])) { // Uses PhreeSoft RESTful tax
            $fees = $order->get_fees();
            foreach ( $fees as $fee_item ) {
                $name = $fee_item->get_name();
                if ('Sales Tax'==$name) {
                    $amount = $fee_item->get_total();
                    msgDebug("\nRead RESTful sales tax amount = $amount");
                    return !empty($amount) ? $amount : 0; // fixes if value is null
                }
            }
        } // else Woocommerce tax calculator
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
     * Posts Bizuno formatted API order to POST variables and creates a journal entry
     * @return
     */
    public function apiJournalEntry($order=[])
    {
        $layout = [];
        bizAutoLoad(BIZUNO_FS_LIBRARY.'controllers/phreebooks/main.php', 'journal');
        bizAutoLoad(BIZUNO_FS_LIBRARY.'controllers/inventory/functions.php', 'availableQty', 'function');
        $this->mapPost($order); // map the input to the proper post format to use existing
        msgDebug("\nModified post = ".print_r($_POST, true));
        setUserCache('security', 'j10_mgr', 2);
        setUserCache('security', 'j12_mgr', 2);
        setUserCache('security', 'mgr_c', 2);
        $entry = new phreebooksMain();
        $entry->save($layout);
        // compose doesn't work because user is not logged in
//      compose('phreebooks', 'main', 'save', $layout);
        msgDebug("\nAfter phrebooks compose, layout = ".print_r($layout, true));
        $this->setJournalPayment();
        return $layout['rID'];
    }

    private function mapPost($values=[])
    {
        if (empty($values)) { $values = $_POST; }
        $defjID = get_option ( 'bizuno_api_journal_id' );
        $this->jID     = !empty($defjID) ? $defjID : 12; // defaults to Invoice if empty or Auto
        $this->auto_jID= get_option ( 'bizuno_api_autodownload' );
        $cID           = dbGetValue(BIZUNO_DB_PREFIX.'contacts', 'id', "type='c' AND email='{$values['Billing']['Email']}'");
        $_POST['waiting'] = 1;
        $_POST['AddUpdate_b'] = 1;
        $_POST['id'] = 0; // force new journal entry
        $_POST['purch_order_id'] = $values['General']['PurchaseOrderID'];
        $_POST['store_id'] = 0;
        $_POST['rep_id'] = 0;
        $_POST['post_date'] = viewFormat($values['General']['OrderDate'], 'date');
        $_POST['terminal_date'] = viewFormat($values['General']['OrderDate'], 'date');
        $_POST['currency'] = getDefaultCurrency();
        $_POST['terms'] = 0; // should be Bizuno default
        $_POST['contact_id_b'] = $cID;
        $_POST['primary_name_b'] = $values['Billing']['PrimaryName'];
        $_POST['contact_b'] = $values['Billing']['Contact'];
        $_POST['address1_b'] = $values['Billing']['Address1'];
        $_POST['address2_b'] = $values['Billing']['Address2'];
        $_POST['city_b'] = $values['Billing']['City'];
        $_POST['state_b'] = $values['Billing']['State'];
        $_POST['postal_code_b'] = $values['Billing']['PostalCode'];
        $_POST['country_b'] = clean($values['Billing']['Country'], 'country');
        $_POST['telephone1_b'] = $values['Billing']['Telephone'];
        $_POST['email_b'] = $values['Billing']['Email'];
        $_POST['contact_id_s'] = $cID;
        $_POST['address_id_s'] = 0;
        $_POST['primary_name_s'] = $values['Shipping']['PrimaryName'];
        $_POST['contact_s'] = $values['Shipping']['Contact'];
        $_POST['address1_s'] = $values['Shipping']['Address1'];
        $_POST['address2_s'] = $values['Shipping']['Address2'];
        $_POST['city_s'] = $values['Shipping']['City'];
        $_POST['state_s'] = $values['Shipping']['State'];
        $_POST['postal_code_s'] = $values['Shipping']['PostalCode'];
        $_POST['country_s'] = clean($values['Shipping']['Country'], 'country');
        $_POST['telephone1_s'] = $values['Shipping']['Telephone'];
        $_POST['email_s'] = $values['Shipping']['Email'];
        $itmCnt  = 1;
        $items   = [];
        $subTotal= 0;
        foreach ($values['Item'] as $item) { // Process items
            if ($this->auto_jID) { $this->getStockLevels($item['ItemID'], $item['Quantity']); }
            $items[] = [
                'item_cnt'   => $itmCnt,
                'sku'        => $item['ItemID'],
                'description'=> str_replace('"', '\"', $item['Description']), // becasue of WordPress, need to add extra slashes or decode will fail
                'qty'        => $item['Quantity'],
                'gl_account' => $this->setDefGLItem(),
//              'notUsed0'   => $item['UnitPrice'],
//              'notUsed1'   => $item['SalesTaxAmount'],
                'total'      => $item['TotalPrice']];
            $subTotal += $item['TotalPrice'];
            $itmCnt++;
        } // glt
        $_POST['item_array'] = json_encode(['total'=>sizeof($items), 'rows'=>$items]);
        $_POST['journal_id'] = $_GET['jID'] = $this->jID; // must be after items so the auto journal can be set
        // Shipping
        $_POST['freight'] = $values['General']['ShippingTotal'];
        $_POST['method_code'] = $this->guessShipMethod($values['General']['ShippingCarrier']); // fedex:GND
        // Work the totals
        $_POST['totals_subtotal'] = $subTotal;
        $_POST['totals_shipping_bill_type'] = 'sender'; // for now but allows third party billing
        $_POST['totals_shipping_bill_acct'] = '';
        $_POST['totals_total_txid'] = $values['Payment']['TransactionID'];
        $_POST['total_amount'] = $values['General']['OrderTotal'];
        $_POST['gl_acct_id'] = $this->setDefGL($this->jID);
        $_POST['notes'] = $values['General']['OrderNotes'];
        // Payment map
        $_POST['pmt_method'] = $this->guessPaymentMethod($values['Payment']['Method']); // => ppcp-gateway
        $_POST['pmt_title'] = $values['Payment']['Title']; // => Credit or debit cards (via PayPal)
        $_POST['pmt_status'] = $values['Payment']['Status']; // => processing
        $_POST['pmt_transid'] = $values['Payment']['TransactionID']; // => 4RW06302HK8809324
        $this->guessTaxMethod($values);
        // Clear the Post variables now that they have been remapped.
        unset($_POST['General'], $_POST['Payment'], $_POST['Billing'], $_POST['Shipping'], $_POST['Item']);
    }
    private function getStockLevels($sku, $qty)
    {
        $stock = dbGetValue(BIZUNO_DB_PREFIX.'inventory', 'qty_stock', "sku='".addslashes($sku)."'");
        msgDebug("\nEntering getStockLevels with sku = $sku and Qty = $qty and in stock = $stock");
        if ($qty > $stock) { $this->jID = 10; }
    }
    private function guessPaymentMethod($fromCart='creditcard')
    {
        $test = strtolower($fromCart);
        if (strpos($test, 'payfabric')   !==false) { return 'payfabric'; }
        if (strpos($test, 'paypal')      !==false) { return 'paypal'; }
        if (strpos($test, 'ppcp-gateway')!==false) { return 'paypal'; } // returned from WordPress PayPal plugin
        if (strpos($test, 'elevon')      !==false) { return 'converge'; }
        if (strpos($test, 'converge')    !==false) { return 'converge'; }
        return $fromCart;
    }
    private function guessShipMethod($carrier)
    {
        switch (strtolower($carrier)) {
            default:
            case 'bestway':
            case 'ground': return 'fedex:GND';
            case '1day':   return 'fedex:1DA';
            case '2day':   return 'fedex:2DA';
            case 'ltl':    return 'fedex:FRT';
        }
    }
    private function guessTaxMethod($values)
    {
        if (!in_array($values['Shipping']['Country'], ['US', 'USA'])) { return; }
        if (!empty(getModuleCache('phreebooks', 'totals', 'tax_rest', 'status'))) {
            $_POST['totals_tax_rest'] = $values['General']['SalesTaxAmount'];
        } else {
            $_POST['totals_tax_other'] = $values['General']['SalesTaxAmount'];
        }
    }
    private function setDefGL()
    {
        if (in_array($this->jID, [3,4,6,7])) { return getModuleCache('phreebooks', 'settings', 'vendors', 'gl_payables'); }
        return getModuleCache('bizuno','settings','bizuno_api','gl_receivables',getModuleCache('phreebooks','settings','customers','gl_receivables'));
    }

    private function setDefGLItem()
    {
        if (in_array($this->jID, [3,4,6,7])) { return getModuleCache('phreebooks', 'settings', 'vendors', 'gl_purchases'); }
        return getModuleCache('bizuno', 'settings', 'bizuno_api', 'gl_sales', getModuleCache('phreebooks','settings','customers','gl_sales'));
    }

    /**
     * Sets the payment status of an order
     * @return null
     */
    private function setJournalPayment()
    {
        $rID    = clean('rID', 'integer', 'post');
        if (empty($rID)) { return; }
        $method = clean('pmt_method', 'cmd', 'post');
        $title  = clean('pmt_title', 'cmd', 'post');
        $transID= clean('pmt_transid', 'cmd', 'post');
        $status = clean('pmt_status', 'cmd', 'post');
        $bizStat= in_array($status, ['auth','authorize','on-hold']) ? 'auth': 'cap';
        $iID    = dbGetValue(BIZUNO_DB_PREFIX.'journal_item', ['id','description'], "ref_id=$rID AND gl_type='ttl'");
        $iID['description'] .= ";method:$method;title:$title;status:$bizStat";
        switch ($bizStat) {
            case 'auth':
//              if (empty($transID)) { $pmtInfo['transaction_id'] .= $pmtInfo['auth_code']; }
                if (empty($transID)) {
                    msgAdd('The order has been authorized but the Authorization Code is not present, the payment for this order must be completed in Bizuno AND at the merchant website.', 'caution');
                }
                break;
            case 'cap':
                // This can be written but needs to know the payment method, fetch the order record
                // check to make sure it was posted successfully
                // make sure it was journal 12 NOT 10, if 10 flag as payment received but product not available???
                // build the save $this->main array, try to map the merchant to get gl_account and reference_id no need to io to merchant
                // post it, close it as it is now paid
                msgAdd('The order has been paid at the cart, the payment for this order must be completed manually in Bizuno.', 'caution');
            case 'unpaid':
            default:
        }
        dbWrite(BIZUNO_DB_PREFIX.'journal_item', ['description'=>$iID['description'],'trans_code'=>$transID], 'update', "id={$iID['id']}");
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
