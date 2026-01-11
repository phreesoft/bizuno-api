<?php
/**
 * Bizuno API WordPress Plugin - common support methods
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
 * @version    7.x Last Update: 2026-01-10
 * @filesource /lib/common.php
 */

namespace bizuno;

class common
{
    public $api_local = false;
    public $ShipTaxSt = ['AR','CT','GA','IL','KS','KY','MI','MS','NE','NJ','NM','NY',
        'NC','ND','OH','OK','PA','RI','SC','SD','TN','TX','UT','VT','WA','WV','WI'];
    public $state = '';
    public $lang = [
        'confirm_success' => "Order status update complete, the following %s order(s) were updated: %s",
    ];

    function __construct($options=[])
    {
        require_once (ABSPATH.'wp-admin/includes/plugin.php');
        $this->defaults = ['client_id'=>'', 'client_secret'=>''];
        $this->useOauth = \is_plugin_active('oauth2-provider/wp-oauth-server.php' ) ? true : false;
        $this->options  = $options;
    }
    
    public function client_open()
    {
        
    }
    public function client_close()
    {
//        msgDebugWrite();
    }
    public function rest_open(\WP_REST_Request $request)
    {
        msgDebug("\nEntering rest_open");
        $this->user = \wp_get_current_user();
        $qParams = $request->get_params(); // retrieve the get parameters
        if (empty($qParams)) { 
            $qParams = $request->get_query_params();
            msgDebug("\nTried again with get_query_params: ".print_r($qParams, true));
        }
        return $qParams;
    }
    public function rest_close($output=[], $status=200)
    {
        global $msgStack;
        $output['message'] = $msgStack->error;
        msgDebugWrite();
        return new \WP_REST_Response($output, $status);
    }

    /**
     * Retrieves the sales tax rate from PhreeSoft via REST
     * @return float
     */
    public function getSalesTaxRate()
    {
        msgDebug("\nEntering getSalesTaxRate");
        // This is the only place that is guaranteed to run on every tax line
        $customer   = WC()->customer ?: new WC_Customer();
        $freight    = WC()->cart->get_shipping_total();
        $total      = WC()->cart->get_cart_contents_total();
        $postcode   = $customer->get_shipping_postcode() ?: $customer->get_billing_postcode();
        $city       = $customer->get_shipping_city()     ?: $customer->get_billing_city();
        $this->state= $customer->get_shipping_state()    ?: $customer->get_billing_state();
        $country    = $customer->get_shipping_country()  ?: $customer->get_billing_country();

        msgDebug("\npostcode = $postcode and country = $country");
        if ( empty( $postcode ) || $country !== 'US' ) { return 0.0; }
        
        $zip = substr( preg_replace('/[^0-9]/', '', $postcode), 0, 5 );
        // Cache per ZIP (24h)
        $cache_key = 'bizuno_tax_' . $zip;
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) { 
            $rate = $cached; // this is the rate as decimal (8.25, not 0.0825)
        } else {
            $args = ['freight'=>$freight, 'total'=>$total, 'city'=>$city, 'state'=>$this->state, 'zip'=>$postcode, 'country'=>$country];
            $this->client_open();
            if (empty($args['zip'])) { return; }
            $isTaxable = in_array($args['state'], $this->options['tax_nexus']) ? true : false;
            if (!$isTaxable) { return; }
            msgDebug("\nCalling API with args = ".print_r($args, true));
            $resp = json_decode($this->cURL('post', $args, 'getSalesTax'), true);
            msgDebug("\nBizuno-API getSalesTax received back from REST: ".print_r($resp, true));
            // error check response

            $this->client_close();
            $rate = empty($resp['rate']) ? 0 : $resp['rate'];
        }
        set_transient( $cache_key, $rate, DAY_IN_SECONDS );
        return $rate;
    }

    /**
     * THIS NEEDS TO BE MOVED TO THE SHARED cURL ($io) which has a different set of request variables and sequences
     * @param type $type
     * @param type $data
     * @param type $endPoint
     * @return type
     */
    function cURL($type='get', $data=[], $endPoint='')
    {
        msgDebug("\nEntering cURL with endPoint = $endPoint and options = ".print_r($this->options, true));
        $url    = $this->options['url'].'?bizRt=portal/api/'.$endPoint;
        $opts   = ['headers'=>['BIZUSER'=>$this->options['rest_user_name'], 'BIZPASS'=>$this->options['rest_user_pass']]];
        $useragent = 'Mozilla/5.0 (Windows NT 5.1; rv:31.0) Gecko/20100101 Firefox/31.0';
        $rData  = is_array($data) ? http_build_query($data) : $data;
        if ($type == 'get') { $url .= '&'.$rData; }
        $headers= [];
        if (!empty($opts['headers'])) { foreach ($opts['headers'] as $key => $value) { $headers[] = "$key: $value"; } }
        if (!empty($opts['cookies'])) { foreach ($opts['cookies'] as $key => $value) { $headers[] = "$key: $value"; } }
        unset($opts['headers'], $opts['cookies']);
        $options= [];
        msgDebug("\nReady to send to url = $url");
        $ch     = curl_init();
        if (!empty($options)) { foreach ($options as $opt => $value) {
            switch ($opt) {
                case 'useragent': curl_setopt($ch, CURLOPT_USERAGENT, $useragent); break;
                default:          curl_setopt($ch, constant($opt), $value); break;
            }
        } }
        curl_setopt($ch, CURLOPT_URL,           $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER,    $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER,true);
        curl_setopt($ch, CURLOPT_TIMEOUT,       30); // in seconds
        curl_setopt($ch, CURLOPT_HEADER,        false);
        curl_setopt($ch, CURLOPT_VERBOSE,       false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER,false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST,false);
        if (strtolower($type) == 'post') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $rData);
        }
// for debugging cURL issues, uncomment below
//$fp = fopen(BIZUNO_DATA."cURL_trace.txt", 'w');
//curl_setopt($ch, CURLOPT_VERBOSE, true);
//curl_setopt($ch, CURLOPT_STDERR, $fp);
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            msgDebug('cURL Error # '.curl_errno($ch).'. '.curl_error($ch));
            msgAdd('cURL Error # '.curl_errno($ch).'. '.curl_error($ch));
            curl_close ($ch);
            return;
        } elseif (empty($response)) { // had an issue connecting with TLSv1.2, returned no error but no response (ALPN, server did not agree to a protocol)
            msgAdd("Oops! I Received an empty response back from the cURL request. There was most likely a problem with the connection that was not reported.", 'caution');
        }
        curl_close ($ch);
        msgDebug("\nAPI Common received back from REST: ".print_r($response, true));
        if (isset($response['message'])) {
            msgDebug("\nMerging the msgStack!");
            msgMerge($response['message']);
        }
        return $response;
    }

    public function setNotices($resp=[])
    {
        msgDebug("\nEntering setNotices with resp = ".print_r($resp, true));
        if ( empty( $resp['messages'] ) ) { return; }
        $notices = [];
        $user_id = get_current_user_id();
        foreach ( ['error', 'warning', 'info', 'success'] as $type ) {
            $wc_type = $type==='success' ? 'success' : ( $type === 'error' ? 'error' : 'warning' );
            msgDebug("\nChecking type = $type");
            if ( empty( $resp['messages'][$type] ) ) { continue; }
            foreach ( $resp['messages'][$type] as $msg ) {
                msgDebug("\nFound one...");
                $text = trim( $msg['text'] ?? '' );
                if ( $text ) { $notices[] = [ 'class'=>"notice notice-{$wc_type} is-dismissible", 'message'=>$text ]; }
            }
        }
        msgDebug("\nnotices is ready to set transients: ".print_r($notices, true));
        if ( !empty( $notices ) ) { \set_transient( "bizuno_order_download_notices_{$user_id}", $notices, 45 ); }
    }
    public function get_meta_values( $meta_key='', $post_type='post', $post_status='publish' )
    {
        global $wpdb;
        if ( empty( $meta_key ) ) { return; }
        $sql = "SELECT pm.meta_value FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key = %s AND p.post_type = %s AND p.post_status = %s";
        $meta_values = $wpdb->get_col( $wpdb->prepare( $sql , $meta_key, $post_type, $post_status ) );
        return $meta_values;
    }
}
