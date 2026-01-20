<?php
/**
 * PayFabric request handler – using WordPress HTTP API instead of raw cURL
 */
class payFabric_Request extends payFabric_Builder
{
    protected function sendXml()
    {
        if (is_object(payFabric_RequestBase::$logger)) {
            self::$logger->logInfo('_data has been generated');
            self::$logger->logDebug('Request body:', json_encode($this->_data));
        }

        $headers = [
            'Content-Type'  => 'application/json',
            'Authorization' => $this->merchantId . '|' . $this->merchantKey,
        ];

        $args = [
            'method'      => !empty($this->_data) ? 'POST' : 'GET',
            'headers'     => $headers,
            'body'        => !empty($this->_data) ? json_encode($this->_data) : '',
            'timeout'     => $this->timeout,
            'sslverify'   => self::$sslVerifyPeer && self::$sslVerifyHost,
            'httpversion' => '1.1',
            'redirection' => 5,
        ];

        // Execute request
        $response = wp_remote_request($this->endpoint, $args);

        if (is_object(payFabric_RequestBase::$logger)) {
            self::$logger->logInfo('Sending request to: ' . $this->endpoint);
        }

        // Handle WP_Error
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            if (is_object(payFabric_RequestBase::$logger)) {
                self::$logger->logError('HTTP request failed: ' . $error_message);
            }
            throw new UnexpectedValueException(
                esc_html( '[PayFabric Class] Connection error with PayFabric server: ' . $error_message ), 503 );
        }

        // Get response body
        $this->xmlResponse = wp_remote_retrieve_body($response);
        $status_code       = wp_remote_retrieve_response_code($response);

        if (payFabric_RequestBase::$debug) {
            $this->printDebug([
                'http_code'   => $status_code,
                'total_time'  => wp_remote_retrieve_response_message($response), // approximate
                'headers'     => wp_remote_retrieve_headers($response),
            ]);
        }

        if ($this->xmlResponse && $status_code >= 200 && $status_code < 300) {
            if (is_object(payFabric_RequestBase::$logger)) {
                self::$logger->logInfo('Response received (HTTP ' . $status_code . ')');
                self::$logger->logDebug('Response body:', $this->xmlResponse);
            }
            return $this->xmlResponse;
        }

        // Error handling
        $error_msg = $this->xmlResponse ?: 'Empty response from server';
        if (is_object(payFabric_RequestBase::$logger)) {
            self::$logger->logError('Request failed - HTTP ' . $status_code . ': ' . $error_msg);
        }

        throw new UnexpectedValueException(
            esc_html ( '[PayFabric Class] PayFabric server returned error (HTTP ' . $status_code . '): ' . $error_msg ), esc_html ( $status_code ) ?: 503 );
    }

    /**
     * Debug output – now safe for WordPress (escapes output)
     */
    private function printDebug($info)
    {
        $output = [];

        $output[] = "Target URL: " . esc_html($this->endpoint);
        $output[] = "Request body: " . esc_html(json_encode($this->_data ?? []));

        if (!empty($info)) {
            $output[] = "HTTP Code: " . esc_html($info['http_code'] ?? 'N/A');
            $output[] = "Response: " . esc_html($this->xmlResponse ?? 'No response');
            $output[] = "Response time: ~" . esc_html($info['total_time'] ?? 'N/A') . " sec";
            if (!empty($info['headers'])) {
                $output[] = "Response headers: " . esc_html(print_r($info['headers'], true));
            }
        } else {
            $output[] = "Connection problems with PayFabric!";
        }

        // Output safely in admin context or log
        if (is_admin() && current_user_can('manage_options')) {
            echo '<div style="background:#fff9c0; padding:10px; border:1px solid #f0c000; margin:10px 0;">';
            echo '<strong>PayFabric Debug:</strong><br>';
            echo '<pre>' . esc_html ( implode("\n", $output) ) . '</pre>';
            echo '</div>';
        }
    }

    /**
     * Safe debug output helper (replaces original debugger)
     */
    private function debugger($string)
    {
        // Use wp_kses_post for safe HTML output if needed
        $timestamp = gmdate('Y-m-d H:i:s');
        $safe_string = esc_html($string);

        if (is_admin() && current_user_can('manage_options')) {
            echo wp_kses_post(
                '<br>' . str_repeat('-', 20) . '<br>' .
                "[$timestamp] " . $safe_string . '<br>' .
                str_repeat('-', 20) . '<br>'
            );
        }
    }
}