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
 * @author     Dave Premo, Bizuno Project <support@bizuno.com>
 * @copyright  2008-2026, PhreeSoft, Inc.
 * @license    https://www.gnu.org/licenses/agpl-3.0.txt
 * @version    7.x Last Update: 2026-02-13
 * @filesource /lib/common.php
 */

namespace bizuno;

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'BIZUNO_API_OPT_GROUP', 'bizuno_api_options' );

class api_common
{
    public $bizLib    = 'bizuno-wp';
    public $api_local = false;
    public $lang      = [
        'confirm_success' => "Order status update complete, the following %s order(s) were updated: %s",
    ];

    function __construct()
    {
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
        msgDebug("\nEntering rest_open");
        $this->user = \wp_get_current_user();
        $qParams = $request->get_params(); // retrieve the get parameters
        if (empty($qParams)) { 
            $qParams = $request->get_query_params();
            msgDebug("\nTried again with get_query_params: ".msgPrint($qParams));
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
     * @param type $type
     * @param type $data
     * @param type $endPoint
     * @return type
     */
    function cURL( $type='get', $data=[], $endPoint='' )
    {
        $options = get_option( 'bizuno_api_options', [] );
        msgDebug( "\nEntering cURL (WP HTTP API) with endPoint = $endPoint and options = " . msgPrint( $options ) );
        $base_url = $options['url'] ?? '';
        $url      = trailingslashit( $base_url ) . '?bizRt=portal/api/' . $endPoint;
        // Build query string for GET
        $rData = is_array( $data ) ? http_build_query( $data ) : $data;
        if ( 'get' === strtolower( $type ) && ! empty( $rData ) ) {
            $url .= ( strpos( $url, '?' ) === false ? '?' : '&' ) . $rData;
        }
        // Authentication: Use Basic Auth header (secure replacement for custom BIZUSER/BIZPASS)
        $username = $options['rest_user_name'] ?? '';
        $password = $options['rest_user_pass'] ?? '';
        $auth     = base64_encode( $username . ':' . $password );
        $headers = [
            'Authorization' => 'Basic ' . $auth,
            'Accept'        => 'application/json',           // Assume JSON API
            'User-Agent'    => 'Mozilla/5.0 (compatible; Bizuno-WP-Plugin/' . MODULE_BIZUNO_VERSION . '; +https://www.bizuno.com)'];
        // If you had other headers/cookies in $opts, merge here
        // $headers = array_merge( $headers, $additional_headers );
        // WP HTTP args
        $args = [
            'method'=>strtoupper($type), 'headers'=>$headers, 'timeout'=>30, 'sslverify'=>true, 'httpversion'=>'1.1', 'blocking'=>true];
        if ( 'POST' === strtoupper( $type ) ) { // POST body
            $args['body'] = $rData;  // Already a string (form-urlencoded or raw JSON)
            // If JSON payload needed: $args['body'] = wp_json_encode( $data );
            //     $args['headers']['Content-Type'] = 'application/json';
        }
        msgDebug( "\nReady to send to url = $url" );
        // Execute request
        if ( 'POST' === strtoupper( $type ) ) { $response = wp_remote_post( $url, $args ); }
        else                                  { $response = wp_remote_get( $url, $args ); }
        if ( is_wp_error( $response ) ) { // Handle WP_Error
            $error_msg = 'WP HTTP Error: ' . $response->get_error_message();
            msgDebug( $error_msg );
            msgAdd( $error_msg, 'error' );
            return false;  // or return null / array() as needed
        }
        $body       = wp_remote_retrieve_body( $response ); // Get useful parts
        $status_code= wp_remote_retrieve_response_code( $response );
        if ( 200 !== $status_code ) { msgAdd( "Received HTTP $status_code from API.", 'caution' ); }
        if ( empty( $body ) )       { msgAdd( "Oops! Received an empty response. Likely a connection/protocol issue (e.g., TLS/ALPN mismatch).", 'caution' ); }
        msgDebug( "\nAPI Common received back from REST: " . msgPrint( $body ) );
        // If response has 'message' key (your original logic)
        $decoded = json_decode( $body, true );
        if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) && isset( $decoded['message'] ) ) {
            msgDebug( "\nMerging the msgStack!" );
            msgMerge( $decoded['message'] );
            return $decoded;  // Return decoded array for easier use
        }
        // Fallback: return raw body (or decode if always JSON)
        return $body;  // Or json_decode( $body, true ) if you expect JSON
    }

    public function setNotices($resp=[])
    {
        msgDebug("\nEntering setNotices with resp = ".msgPrint($resp));
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
        msgDebug("\nnotices is ready to set transients: ".msgPrint($notices));
        if ( !empty( $notices ) ) { \set_transient( "bizuno_order_download_notices_{$user_id}", $notices, 45 ); }
    }

    protected function encrypt_password( $password ) {
       if ( ! function_exists( 'openssl_encrypt' ) ) { return base64_encode( $password ); } // fallback – not ideal
       $key    = wp_salt( 'auth' ); // or wp_salt( 'secure_auth' ) or a constant from wp-config
       $method = 'aes-256-cbc';
       $iv_len = openssl_cipher_iv_length( $method );
       $iv     = openssl_random_pseudo_bytes( $iv_len );
       $encrypted = openssl_encrypt( $password, $method, substr( $key, 0, 32 ), 0, $iv );
       return $encrypted ? base64_encode( $encrypted . '::' . base64_encode( $iv ) ) : '';
    }

    public function decrypt_password( $encrypted ) {
        if ( empty( $encrypted ) ) { return ''; }
        $decoded = base64_decode( $encrypted );
        $parts   = explode( '::', $decoded, 2 );
        if ( count( $parts ) !== 2 ) { return base64_decode( $decoded ); } // fallback for old plain/base64 values
        $key    = wp_salt( 'auth' );
        $method = 'aes-256-cbc';
        $decrypted = openssl_decrypt( $parts[0], $method, substr( $key, 0, 32 ), 0, base64_decode( $parts[1] ) );
        return $decrypted !== false ? $decrypted : '';
    }
}
