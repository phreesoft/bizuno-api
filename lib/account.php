<?php
/**
 * Bizuno API WordPress Plugin - account class
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
 * @copyright  2008-2026, PhreeSoft, Inc.
 * @license    https://www.gnu.org/licenses/agpl-3.0.txt
 * @version    7.x Last Update: 2025-07-20
 * @filesource /lib/account.php
 */



namespace bizuno;

if ( ! defined( 'ABSPATH' ) ) exit;

class account extends common
{
    function __construct($options=[])
    {
        parent::__construct($options);
    }

    /*********************************************** WP users screen ************************************************/
    /*
     * Provides additional fields on the users edit page to link the e-store to the Bizuno Books
     */
    public function bizunoUserEdit( $user )
    {
        if (!is_admin()) { return; }
        $html  = '<h3 class="heading">'.esc_html( __('Bizuno Customer Settings', 'bizuno-api' ) ).'</h3>';
        $html .= '<table class="form-table">';
        $html .= '  <tr><th><label for="bizuno_contact_id">'.esc_html( __('Link to Bizuno Customer ID:', 'bizuno-api' ) ).'</label></th>';
        $html .= '      <td><input type="text" name="bizuno_contact_id" id="bizuno_contact_id" value="'.\get_user_meta( $user->ID, 'bizuno_contact_id', true).'" /></td></tr>';
        $html .= '</table>';
        $html .= wp_nonce_field( 'update-user_' . $user->ID, '_wpnonce', true, true );
        echo wp_kses_post($html);
    }

    /**
     * Saves the Contact ID to link to Bizuno
     * @param type $user_id
     * @return type
     */
    public function bizunoUserSave( $user_id )
    {
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'update-user_' . $user_id ) ) { return; }
        if ( ! current_user_can( 'edit_user', $user_id ) ) { return; }
        $contact_id = isset( $_POST['bizuno_contact_id'] ) ? sanitize_text_field( $_POST['bizuno_contact_id'] ) : '';
        $old_value = get_user_meta( $user_id, 'bizuno_contact_id', true );
        if ( $contact_id !== $old_value ) { \update_user_meta( $user_id, 'bizuno_contact_id', $contact_id ); }
    }

    /***********************************************   ************************************************/
    /**
     *
     * @param type $items
     * @return string
     */
    public function biz_add_woo_tabs( $items ) {
        // Remove some tabs
        unset($items['downloads'], $items['customer-logout'] ); // $items['edit-address'], $items['dashboard'], $items['payment-methods'], $items['orders'], $items['edit-account']
        // Add new tabs and resequence
//      $items['biz-account-history']  = 'Order History';
//      $items['biz-account-addresses']= 'Address Book';
        $items['biz-account-wallet']   = 'Wallet';
//      $items['customer-logout']      = 'Logout';
        return $items;
    }
    public function account_address_add($request)
    {

    }

    /**
     * [WooCommerce side] Renders the address tab content on the WooCommerce account page
     */
    public function biz_account_addresses_content() {
        $this->bizunoCtlr();
        msgDebug("\nStarting content generation");
        echo '<h3>Address Book</h3>'
            . '<p>Link to Bizuno Address Book</p>';
        $user = wp_get_current_user();
        msgDebug("\nfetched user: ".print_r($user, true));
        $resp = [];
        if (!empty($user->user_email)) {
            $resp = $this->restGo('get', $this->options['url'], 'account/address/list', ['email'=>$user->user_email]);
            msgDebug("\nReceived back account from REST: ".print_r($resp, true));
        }
        if (isset($resp['message'])) { msgMerge($resp['message']); }
        // renders the address page
        echo esc_html( __('Address Book here', 'bizuno-api' ) );
        msgDebugWrite();
    }

    /**
     * [Bizuno side] Pulls the list of addresses from the Bizuno contact database and returns to the WooCommerce side
     * @param type $request
     */
    public function account_address_list($request)
    {
        $output = [];
        $this->bizunoCtlr();
        msgDebug("\nStarting REST Response");
        $qParams= $request->get_query_params(); // retrieve the get parameters
        $data   = ['email' => !empty($qParams['email']) ? $qParams['email'] : ''];
        msgDebug("\nWorking with contactID = ".print_r($data['email'], true));
//      $id     = dbGetValue(BIZUNO_DB_PREFIX,'contacts', 'id', "short_name='".addslashes($data['contactID'])."'");
        $resp   = new \WP_REST_Response($output);
        $resp->set_status(200);
        msgDebugWrite();
        return $resp;
    }

    public function account_details($request)
    {

    }

    public function account_new($request)
    {

    }

    /**
     * [WooCommerce side] Renders the order history tab content on the WooCommerce account page
     */
    public function biz_account_history_content() {
        $this->bizunoCtlr();
        echo '<h3>Order History</h3>';
        $user = wp_get_current_user();
        $resp = [];
        if (!empty($user->user_email)) {
            $resp = $this->restGo('get', $this->options['url'], 'account/order/history', ['email'=>$user->user_email]);
            msgDebug("\nReceived back hist from REST: ".print_r($resp, true));
        }
        if (isset($resp['message'])) { msgMerge($resp['message']); }
        // renders the order history page
        echo esc_html( __('Order history here', 'bizuno-api' ) );
        $this->shop_close();
    }

    /**
     * [Bizuno side] Pulls the order history from the Bizuno payment extension and returns to the WooCommerce side
     * @param type $request
     */
    public function account_history_list($request)
    {
        $this->bizunoCtlr();
        $qParams= $request->get_query_params(); // retrieve the get parameters
        $data   = ['email' => !empty($qParams['email']) ? $qParams['email'] : ''];
        msgDebug("\nWorking with contactID = ".print_r($data['email'], true));
        $id     = dbGetValue(BIZUNO_DB_PREFIX,'contacts', 'id', "short_name='".addslashes($data['email'])."'");
        $output['addresses'] = !empty($id) ? dbGetMulti(BIZUNO_DB_PREFIX.'contacts', "id=$id") : [];
        $resp   = new \WP_REST_Response($output);
        $resp->set_status(200);
        return $resp;
    }

    public function account_order_history($request)
    {

    }

    public function account_update($request)
    {

    }

    public function account_wallet_add($request)
    {

    }

    /**
     * [WooCommerce side] Renders the wallet tab content on the WooCommerce account page
     */
    public function biz_account_wallet_content() { // Bizuno Allow PO Payment
        $resp = [];
        $this->shop_open();
        echo '<h3>Wallet</h3>';
        if (!empty($this->cID)) { $resp = $this->restGo('get', $this->options['url'], 'account/wallet/list', ['contactID'=>$this->cID]); }
        // renders the wallet page
        echo 'Wallet here';
        $this->shop_close();
    }

    /**
     * [Bizuno side] Pulls the wallet from the Bizuno payment extension and returns to the WooCommerce side
     * @param type $request
     */
    public function account_wallet_list($request)
    {
        global $portal;
        $qParams= $this->bizuno_open($request);
        $data   = ['contactID' => !empty($qParams['contactID']) ? $qParams['contactID'] : ''];
        msgDebug("\nWorking with contactID = ".print_r($data['contactID'], true));
        $cID    = dbGetValue(BIZUNO_DB_PREFIX.'contacts', 'id', "short_name='".addslashes($data['contactID'])."'");
        $output = [];
        if (!empty($cID)) { $output['wallet'] = $portal->accountWalletList($cID); }
        return $this->bizuno_close($output);
    }
}
