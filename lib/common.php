<?php
/**
 * WooCommerce - Bizuno API
 * This class contains the  methods to handle a customers account
 *
 * @copyright  2008-2024, PhreeSoft, Inc.
 * @author     David Premo, PhreeSoft, Inc.
 * @version    3.x Last Update: 2023-11-02
 * @filesource /wp-content/plugins/bizuno-api/lib/common.php
 */

namespace bizuno;

class api_common
{
    public $api_active= false;
    public $api_local = true;
    public $userID = 0;

    function __construct($options=[])
    {
        require_once (ABSPATH.'wp-admin/includes/plugin.php');
        $this->bizActive = \is_plugin_active('bizuno-accounting/bizuno-accounting.php') ? true : false;
        $this->options = $options;
        if ( \is_plugin_active( 'bizuno-api/bizuno-api.php' ) ) {
            $this->api_active= true;
            if (!empty( get_option ( 'bizuno_api_url', '' ) ) ) { $this->api_local = false; }
        }
        $this->useOauth = \is_plugin_active( 'oauth2-provider/wp-oauth-server.php' ) ? true : false;
    }

    /**
     * REQUEST SIDE - Handles the initialization for a REST transaction
     */
    public function client_open()
    {
        $this->bizunoCtlr();
        $this->lang = getLang('bizuno');
    }
    /**
     * REQUEST SIDE - Handles the closeout for a REST transaction
     */
    public function client_close()
    {
        msgDebugWrite();
    }
    /**
     * RESPONSE SIDE - Handles the initialization for a REST transaction
     */
    public function rest_open(\WP_REST_Request $request)
    {
        $this->bizunoCtlr();
        $this->lang= getLang('bizuno');
        $this->user= wp_get_current_user();
        $this->cID = get_user_meta($this->user->ID, 'bizuno_wallet_id', true);
        msgDebug("\nBizuno Contact ID = $this->cID and WordPress user ID {$this->user->ID}");
        $qParams   = $request->get_params(); // didn't work: get_query_params(); // retrieve the get parameters
        return $qParams;
    }
    /**
     * RESPONSE SIDE - Handles the closeout for a REST transaction
     */
    public function rest_close($output, $status=200)
    {
        global $msgStack;
        $output['message'] = $msgStack->error;
        $resp = new \WP_REST_Response($output);
        $resp->set_status($status);
        msgDebugWrite();
        return $resp;
    }

    /**
     * Instantiates the Bizuno controller (mostly loads the functions)
     * @return \bizuno\portalCtl
     */
    public function bizunoCtlr() {
        require_once ( plugin_dir_path( __FILE__ ) . "../../bizuno-accounting/controllers/portal/controller.php" );
        return new \bizuno\portalCtl(); // sets up the Bizuno Environment
    }

    /**
     * Communicates with the remote server through the RESTful API
     * @global type $portal
     * @param type $type
     * @param type $server
     * @param type $endpoint
     * @param type $data
     * @return type
     */
    public function restGo($type, $server, $endpoint, $data=[])
    {
        global $portal;
        $opts = [];
        if (!empty($portal->useOauth)) { // Set the credentials
            $portal->id   = $this->options['oauth_client_id'];
            $portal->pass = $this->options['oauth_client_secret'];
//      } else { // the following duplicates the credentials and causes failed transaction
//          $opts = ['headers'=>['email'=>$this->options['rest_user_name'], 'pass'=>$this->options['rest_user_pass']]];
        }
        $resp = $portal->restRequest($type, $server, "wp-json/bizuno-api/v1/$endpoint", $data, $opts);
        msgDebug("\nAPI Common received back from REST: ".print_r($resp, true));
        if (isset($resp['message'])) {
            msgDebug("\nMerging the msgStack!");
            msgMerge($resp['message']);
        }
        return $resp;
    }

    /**
     * Pulls the responses from the message stack and sets the WordPress notices for the next reload
     * @param array $msgStack
     */
    public function setNotices()
    {
        global $msgStack;
        $error = $warning = $success = "";
        if (empty($msgStack)) {
            msgAdd("Unexpected response from the Bizuno API: ".print_r($msgStack, true), 'error', true);
            return;
        }
        foreach ($msgStack as $key => $value) {
            switch ($key) {
                case 'error':   foreach ($value as $msg) { $error   .= !empty($msg['text']) ? $msg['text']."\n" : ''; } break;
                case 'caution':
                case 'warning': foreach ($value as $msg) { $warning .= !empty($msg['text']) ? $msg['text']."\n" : ''; } break;
                case 'success': foreach ($value as $msg) { $success .= !empty($msg['text']) ? $msg['text']."\n" : ''; } break;
            }
        }
        msgDebug("\nWriting the notice queue.\nOrder Download Error: $error"."\nOrder Download Warning: $warning"."\nOrder Download Success: $success");
        $result = 'error';
        if (!empty($success)) { $result = 'success'; msgAdd( $success, 'success', true ); }
        if (!empty($warning)) { $result = 'warning'; msgAdd( $warning, 'warning', true ); }
        if (!empty($error))   { $result = 'error';   msgAdd( $error,   'error',   true ); }
        return $result;
    }

    public function get_meta_values( $meta_key='', $post_type='post', $post_status='publish' ) {
        global $wpdb;
        if ( empty( $meta_key ) ) { return; }
        $sql = "SELECT pm.meta_value FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key = %s AND p.post_type = %s AND p.post_status = %s";
        $meta_values = $wpdb->get_col( $wpdb->prepare( $sql , $meta_key, $post_type, $post_status ) );
        return $meta_values;
    }

    /***************************************
     * these below should be moved to the account class
     */
    public function account_address_add($request)
    {
// Received back from REST
    }

    /**
     * [Bizuno side] Pulls the wallet from the Bizuno payment extension and returns to the WooCommerce side
     * @param type $request
     */
    public function account_wallet_list($request)
    {
        global $portal;
        $data  = ['contactID' => !empty($qParams['contactID']) ? $qParams['contactID'] : ''];
        msgDebug("\nWorking with contactID = ".print_r($data['contactID'], true));
        $cID   = dbGetValue(BIZUNO_DB_PREFIX.'contacts', 'id', "short_name='".addslashes($data['contactID'])."'");
        $output= [];
        if (!empty($cID)) { $output['wallet'] = $portal->accountWalletList($cID); }
        $resp  = new \WP_REST_Response($output);
        $resp->set_status(200);
        msgDebugWrite();
        return $resp;
    }
}
