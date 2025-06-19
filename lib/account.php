<?php
/**
 * ISP Hosted WordPress Plugin - account class
 *
 * @copyright  2008-2025, PhreeSoft, Inc.
 * @author     David Premo, PhreeSoft, Inc.
 * @version    3.x Last Update: 2024-09-13
 * @filesource ISP WordPress /phreesoft-bizuno/lib/account.php
 */

namespace bizuno;

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
        $html  = '<h3 class="heading">Bizuno Customer Settings</h3>';
        $html .= '<table class="form-table">';
        $html .= '  <tr><th><label for="bizuno_contact_id">Link to Bizuno Customer ID:</label></th>';
        $html .= '      <td><input type="text" name="bizuno_contact_id" id="bizuno_contact_id" value="'.\get_user_meta( $user->ID, 'bizuno_contact_id', true).'" /></td></tr>';
        $html .= '</table>';
        echo $html;
    }

    /**
     * Saves the Contact ID to link to Bizuno
     * @param type $user_id
     * @return type
     */
    public function bizunoUserSave( $user_id )
    {
        if (!is_admin()) { return; }
        $user = \get_userdata( $user_id );
        if (empty($user)) { return; }
        \update_user_meta( $user_id, 'bizuno_contact_id', !empty($_POST['bizuno_contact_id']) ? $_POST['bizuno_contact_id'] : '' );
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
        msgDebug("\nafter REST");
        if (isset($resp['message'])) { msgMerge($resp['message']); }
        // renders the address page
        msgDebug("\nFinakl echo");
        echo 'Response: '.print_r($resp, true).'<br />';
        echo 'Address Book here';
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
        echo 'Response: '.print_r($resp, true).'<br />';
        echo 'order history here';
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
