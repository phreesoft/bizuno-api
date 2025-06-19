<?php
/**
 * ISP Hosted WordPress Plugin - payment class
 *
 * @copyright  2008-2025, PhreeSoft, Inc.
 * @author     David Premo, PhreeSoft, Inc.
 * @version    3.x Last Update: 2024-08-25
 * @filesource ISP WordPress /phreesoft-bizuno/lib/payment.php
 */

namespace bizuno;

class payment extends common
{
    public $userID = 0;

    function __construct($options=[])
    {
        parent::__construct($options);
    }
    /********************** REST Endpoints ************************/
    /**
     * [POST] Saves a users new card to their wallet
     */
    public function wallet_add_request($request)
    {
        $data  = $this->rest_open($request);        
        $postID= $this->productImport($data['product']);
        $output= ['result'=>!empty($postID)?'Success':'Fail', 'ID'=>$postID];
        return $this->rest_close($output);
    }
    /**
     * [GET] Response side - Retrieve wallet cards
     */
    public function wallet_add_response()
    {
        $data = [];
        if (!$this->bizActive)  { return; }
        $this->rest_open();
        if (empty($this->pfID)) { 
            msgAdd("Bad payfabric ID passed: $this->pfID");
            $status = 400;
        } else {
            compose('payment', 'wallet', 'add', $data);
            $status = 200;
        }
        $this->rest_close($data, $status);
    }
    /**
     * [GET] Deletes a users card from their wallet
     */
    public function wallet_delete_request($request)
    {
        $data  = $this->rest_open($request);
        $postID= $this->productImport($data['product']);
        $output= ['result'=>!empty($postID)?'Success':'Fail', 'ID'=>$postID];
        return $this->rest_close($output);
    }
    /**
     * [GET] Response side - Retrieve wallet cards
     */
    public function wallet_delete_response()
    {
        $data = [];
        if (!$this->bizActive)  { return; }
        $this->rest_open();
        if (empty($this->pfID)) { 
            msgAdd("Bad payfabric ID passed: $this->pfID");
            $status = 400;
        } else {
            compose('payment', 'wallet', 'delete', $data);
            $status = 200;
        }
        $this->rest_close($data, $status);
    }
    /**
     * [GET] Request side - Pulls the wallet from the Bizuno host and returns the list
     * To be used to manage the users wallet in the admin and during checkout
     */
    public function wallet_list_request($pfID='')
    {
        if ( !$this->bizActive)   { return; }
        if ( empty ( $pfID ) ) { error_log("Bad payfabric ID passed: $pfID"); return; }
        $this->client_open();
        $resp = $this->restGo('get', $this->options['url'], 'wallet/list', ['pfID'=>$this->pfID]);
        $this->client_close();
        if ( isset($resp['message'] ) ) { msgMerge($resp['message']); }
        return $resp;
    }
    /**
     * [GET] Response side - Retrieve wallet cards
     */
    public function wallet_list_response()
    {
        $data = [];
        if (!$this->bizActive)  { return; }
        $this->rest_open();
        if (empty($this->pfID)) { 
            msgAdd("Bad payfabric ID passed: $this->pfID");
            $status = 400;
        } else {
            compose('payment', 'wallet', 'list', $data);
            $status = 200;
        }
        $this->rest_close($data, $status);
    }
}