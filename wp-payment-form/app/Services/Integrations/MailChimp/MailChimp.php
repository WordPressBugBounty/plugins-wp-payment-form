<?php

namespace WPPayForm\App\Services\Integrations\MailChimp;

/**
 * Super-simple, minimum abstraction MailChimp API v3 wrapper
 * MailChimp API v3: http://developer.mailchimp.com
 * This wrapper: https://github.com/drewm/mailchimp-api
 *
 * @author Drew McLellan <drew.mclellan@gmail.com>
 * @version 2.4
 */
class MailChimp
{
    private $api_key;
    private $api_endpoint = 'https://<dc>.api.mailchimp.com/3.0';

    const TIMEOUT = 10;

    /*  SSL Verification
        Read before disabling:
        http://snippets.webaware.com.au/howto/stop-turning-off-curlopt_ssl_verifypeer-and-fix-your-php/
    */
    public $verify_ssl = true;

    private $request_successful = false;
    private $last_error         = '';
    private $last_response      = array();
    private $last_request       = array();

    /**
     * Create a new instance
     * @param string $api_key Your MailChimp API key
     * @param string $api_endpoint Optional custom API endpoint
     * @throws \Exception
     */
    public function __construct($api_key, $api_endpoint = null)
    {
        $this->api_key = $api_key;

        if ($api_endpoint === null) {
            if (strpos($this->api_key, '-') === false) {
                throw new \Exception(
                    sprintf(
                        // translators: %s: The invalid API key.
                        esc_html__('Invalid MailChimp API key %s supplied.', 'wp-payment-form'),
                        esc_html($api_key)
                    )
                );
            }
            list(, $data_center) = explode('-', $this->api_key);
            $this->api_endpoint  = str_replace('<dc>', $data_center, $this->api_endpoint);
        } else {
            $this->api_endpoint  = $api_endpoint;
        }

        $this->last_response = array('httpHeaders' => null, 'http_code' => null, 'body' => null);
    }

    /**
     * @return string The url to the API endpoint
     */
    public function getApiEndpoint()
    {
        return $this->api_endpoint;
    }


    /**
     * Convert an email address into a 'subscriber hash' for identifying the subscriber in a method URL
     * @param   string $email The subscriber's email address
     * @return  string          Hashed version of the input
     */
    public function subscriberHash($email)
    {
        return md5(strtolower($email));
    }

    /**
     * Was the last request successful?
     * @return bool  True for success, false for failure
     */
    public function success()
    {
        return $this->request_successful;
    }

    /**
     * Get the last error returned by either the network transport, or by the API.
     * If something didn't work, this should contain the string describing the problem.
     * @return  string|false  describing the error
     */
    public function getLastError()
    {
        return $this->last_error ?: false;
    }

    /**
     * Get an array containing the HTTP headers and the body of the API response.
     * @return array  Assoc array with keys 'headers' and 'body'
     */
    public function getLastResponse()
    {
        return $this->last_response;
    }

    /**
     * Get an array containing the HTTP headers and the body of the API request.
     * @return array  Assoc array
     */
    public function getLastRequest()
    {
        return $this->last_request;
    }

    /**
     * Make an HTTP DELETE request - for deleting data
     * @param   string $method URL of the API request method
     * @param   array $args Assoc array of arguments (if any)
     * @param   int $timeout Timeout limit for request in seconds
     * @return  array|false   Assoc array of API response, decoded from JSON
     */
    public function delete($method, $args = array(), $timeout = self::TIMEOUT)
    {
        return $this->makeRequest('delete', $method, $args, $timeout);
    }

    /**
     * Make an HTTP GET request - for retrieving data
     * @param   string $method URL of the API request method
     * @param   array $args Assoc array of arguments (usually your data)
     * @param   int $timeout Timeout limit for request in seconds
     * @return  array|false   Assoc array of API response, decoded from JSON
     */
    public function get($method, $args = array(), $timeout = self::TIMEOUT)
    {
        return $this->makeRequest('get', $method, $args, $timeout);
    }

    /**
     * Make an HTTP PATCH request - for performing partial updates
     * @param   string $method URL of the API request method
     * @param   array $args Assoc array of arguments (usually your data)
     * @param   int $timeout Timeout limit for request in seconds
     * @return  array|false   Assoc array of API response, decoded from JSON
     */
    public function patch($method, $args = array(), $timeout = self::TIMEOUT)
    {
        return $this->makeRequest('patch', $method, $args, $timeout);
    }

    /**
     * Make an HTTP POST request - for creating and updating items
     * @param   string $method URL of the API request method
     * @param   array $args Assoc array of arguments (usually your data)
     * @param   int $timeout Timeout limit for request in seconds
     * @return  array|false   Assoc array of API response, decoded from JSON
     */
    public function post($method, $args = array(), $timeout = self::TIMEOUT)
    {
        return $this->makeRequest('post', $method, $args, $timeout);
    }

    /**
     * Make an HTTP PUT request - for creating new items
     * @param string $method URL of the API request method
     * @param array $args Assoc array of arguments (usually your data)
     * @param int $timeout Timeout limit for request in seconds
     * @return  array|false   Assoc array of API response, decoded from JSON
     * @throws \Exception
     */
    public function put($method, $args = array(), $timeout = self::TIMEOUT)
    {
        return $this->makeRequest('put', $method, $args, $timeout);
    }

    /**
     * Performs the underlying HTTP request. Not very exciting.
     * @param  string $http_verb The HTTP verb to use: get, post, put, patch, delete
     * @param  string $method The API method to be called
     * @param  array $args Assoc array of parameters to be passed
     * @param int $timeout
     * @return array|false Assoc array of decoded result
     * @throws \Exception
     */

    private function makeRequest($http_verb, $method, $args = array(), $timeout = self::TIMEOUT)
    {
        $url = $this->api_endpoint . '/' . $method;

        $response = $this->prepareStateForRequest($http_verb, $method, $url, $timeout);

        // Headers
        $headers = [
            'Accept'        => 'application/vnd.api+json',
            'Content-Type'  => 'application/vnd.api+json',
            'Authorization' => 'apikey ' . $this->api_key,
        ];

        if (isset($args['language'])) {
            $headers['Accept-Language'] = $args['language'];
        }

        // Arguments for wp_remote_* functions
        $request_args = [
            'headers'   => $headers,
            'timeout'   => $timeout,
            'sslverify' => $this->verify_ssl,
            'httpversion' => '1.0',
            'user-agent'  => 'DrewM/MailChimp-API/3.0 (github.com/drewm/mailchimp-api)',
        ];

        // Attach body for post/put/patch
        if (in_array(strtolower($http_verb), ['post', 'put', 'patch'])) {
            $request_args['body'] = wp_json_encode($args);
        }

        // GET request: attach query string
        if (strtolower($http_verb) === 'get' && !empty($args)) {
            $url = add_query_arg($args, $url);
        }

        // Make the request
        switch (strtolower($http_verb)) {
            case 'post':
                $wp_response = wp_remote_post($url, $request_args);
                break;

            case 'get':
                $wp_response = wp_remote_get($url, $request_args);
                break;

            case 'delete':
                $request_args['method'] = 'DELETE';
                $wp_response = wp_remote_request($url, $request_args);
                break;

            case 'patch':
            case 'put':
                $request_args['method'] = strtoupper($http_verb);
                $wp_response = wp_remote_request($url, $request_args);
                break;

            default:
            throw new \Exception(sprintf(
                // translators: %s: The unsupported HTTP verb.
                esc_html(__('Unsupported HTTP verb: %s', 'wp-payment-form')),
                esc_html($http_verb)
            ));
        }

        // Check for errors
        if (is_wp_error($wp_response)) {
            throw new \Exception(sprintf(
                // translators: %s: The error message. 
                esc_html(__('Request failed: %s', 'wp-payment-form')),
                esc_html($wp_response->get_error_message())
            ));
        }

        // Extract response info
        $response['httpHeaders'] = wp_remote_retrieve_headers($wp_response);
        $response['http_code']   = wp_remote_retrieve_response_code($wp_response);
        $response['body']        = wp_remote_retrieve_body($wp_response);

        $formattedResponse = $this->formatResponse($response);

        $this->determineSuccess($response, $formattedResponse, $timeout);

        return $formattedResponse;
    }

    /**
    * @param string $http_verb
    * @param string $method
    * @param string $url
    * @param integer $timeout
    */
    private function prepareStateForRequest($http_verb, $method, $url, $timeout)
    {
        $this->last_error = '';

        $this->request_successful = false;

        $this->last_response = array(
            'httpHeaders' => null,
            'http_code'   => null,
            'body'        => null,
        );

        $this->last_request = array(
            'method'  => $http_verb,
            'path'    => $method,
            'url'     => $url,
            'body'    => '',
            'timeout' => $timeout,
        );

        return $this->last_response;
    }

    /**
     * Decode the response and format any error messages for debugging
     * @param array $response The response array with 'body' key
     * @return array|false    The JSON decoded into an array
     */
    private function formatResponse($response)
    {
        $this->last_response = $response;

        if (!empty($response['body'])) {
            return json_decode($response['body'], true);
        }

        return false;
    }

    /**
     * Check if the response was successful or a failure. If it failed, store the error.
     * @param array $response The response array with 'http_code' and 'body' keys
     * @param array|false $formattedResponse The decoded JSON response body
     * @param int $timeout The timeout supplied to the request.
     * @return bool     If the request was successful
     */
    private function determineSuccess($response, $formattedResponse, $timeout)
    {
        $status = $this->findHTTPStatus($response, $formattedResponse);

        if ($status >= 200 && $status <= 299) {
            $this->request_successful = true;
            return true;
        }

        if (isset($formattedResponse['detail'])) {
            $this->last_error = sprintf('%d: %s', $formattedResponse['status'], $formattedResponse['detail']);
            return false;
        }

        $this->last_error = 'Unknown error, call getLastResponse() to find out what happened.';
        return false;
    }

    /**
     * Find the HTTP status code from the response or API response body
     * @param array $response The response array with 'http_code' key
     * @param array|false $formattedResponse The decoded JSON response body
     * @return int  HTTP status code
     */
    private function findHTTPStatus($response, $formattedResponse)
    {
        if (!empty($response['http_code'])) {
            return (int) $response['http_code'];
        }

        if (!empty($response['body']) && isset($formattedResponse['status'])) {
            return (int) $formattedResponse['status'];
        }

        return 418;
    }
}
