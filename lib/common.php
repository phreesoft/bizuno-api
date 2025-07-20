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
 * @version    7.x Last Update: 2025-07-20
 * @filesource /lib/common.php
 */

namespace bizuno;

class common
{
    public $api_local = false;
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
        msgDebugWrite();
    }
    public function rest_open(\WP_REST_Request $request)
    {
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
     * THIS NEEDS TO BE MOVED TO THE SHARED cURL which has a different set of request variables and sequences
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
                case 'error':   foreach ($value as $msg) { $error   .= $msg['text']."\n"; } break;
                case 'caution':
                case 'warning': foreach ($value as $msg) { $warning .= $msg['text']."\n"; } break;
                case 'success': foreach ($value as $msg) { $success .= $msg['text']."\n"; } break;
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
}
