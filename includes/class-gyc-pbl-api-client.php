<?php
/**
 * GetYourCash API client.
 *
 * @package GetYourCash_PayByLink
 */

defined('ABSPATH') || exit;

class GYC_PBL_API_Client {
    private $base_url;
    private $username;
    private $password;
    private $debug;
    private $logger;
    private $access_token = '';
    private $user_token = '';

    public function __construct($base_url, $username, $password, $debug = false) {
        $this->base_url = untrailingslashit(trim((string) $base_url));
        $this->username = (string) $username;
        $this->password = (string) $password;
        $this->debug = (bool) $debug;
        $this->logger = function_exists('wc_get_logger') ? wc_get_logger() : null;
    }

    public function login() {
        $response = $this->request(
            'POST',
            '/api/External/v1/Login/Token',
            array(
                'username' => $this->username,
                'password' => $this->password,
            ),
            false
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $this->access_token = isset($response['accessToken']) ? (string) $response['accessToken'] : '';
        $this->user_token = isset($response['userToken']) ? (string) $response['userToken'] : '';

        if ($this->access_token === '' || $this->user_token === '') {
            return new WP_Error('gyc_invalid_login_response', __('Incomplete GetYourCash login response.', 'getyourcash-paybylink'));
        }

        /**
         * Filters how long (in seconds) GetYourCash tokens are cached.
         *
         * @param int $ttl Cache lifetime in seconds.
         */
        $ttl = (int) apply_filters('gyc_pbl_token_ttl', 15 * MINUTE_IN_SECONDS);
        if ($ttl > 0) {
            set_transient(
                $this->token_cache_key(),
                array('access' => $this->access_token, 'user' => $this->user_token),
                $ttl
            );
        }

        return true;
    }

    public function local_branches() {
        return $this->authenticated_request('GET', '/api/External/v1/PayByLink/LocalBranches');
    }

    public function create($payload) {
        return $this->authenticated_request('POST', '/api/External/v1/PayByLink/Create', $payload);
    }

    public function status($transaction_id) {
        return $this->authenticated_request(
            'GET',
            '/api/External/v1/PayByLink/Status/' . rawurlencode((string) $transaction_id)
        );
    }

    /**
     * Performs an authenticated request, logging in again once if the cached token was rejected.
     */
    /**
     * Captures a pre-authorization (ConfirmPaymentAfterPreAuthorization). Returns 204 No Content on success.
     */
    public function confirm_pre_authorization($payload) {
        return $this->authenticated_request('POST', '/api/External/v1/PayByLink/ConfirmPaymentAfterPreAuthorization', $payload);
    }

    private function authenticated_request($method, $path, $payload = null) {
        $login = $this->ensure_login();
        if (is_wp_error($login)) {
            return $login;
        }

        $response = $this->request($method, $path, $payload);

        if (is_wp_error($response) && 'gyc_api_error' === $response->get_error_code()) {
            $data = $response->get_error_data();
            if (isset($data['status']) && 401 === (int) $data['status']) {
                $this->forget_tokens();
                $login = $this->ensure_login();
                if (is_wp_error($login)) {
                    return $login;
                }
                $response = $this->request($method, $path, $payload);
            }
        }

        return $response;
    }

    private function ensure_login() {
        if ($this->access_token !== '' && $this->user_token !== '') {
            return true;
        }

        $cached = get_transient($this->token_cache_key());
        if (is_array($cached) && !empty($cached['access']) && !empty($cached['user'])) {
            $this->access_token = (string) $cached['access'];
            $this->user_token = (string) $cached['user'];
            return true;
        }

        $result = $this->login();
        if (is_wp_error($result)) {
            return new WP_Error(
                'gyc_login_failed',
                __('GetYourCash authentication failed.', 'getyourcash-paybylink') . ' ' . $result->get_error_message()
            );
        }
        return true;
    }

    private function forget_tokens() {
        $this->access_token = '';
        $this->user_token = '';
        delete_transient($this->token_cache_key());
    }

    private function token_cache_key() {
        return 'gyc_pbl_token_' . md5($this->base_url . '|' . $this->username . '|' . $this->password);
    }

    private function request($method, $path, $payload = null, $authenticated = true) {
        if ($this->base_url === '') {
            return new WP_Error('gyc_missing_base_url', __('GetYourCash API URL is not configured.', 'getyourcash-paybylink'));
        }

        $headers = array('Content-Type' => 'application/json');
        if ($authenticated) {
            $headers['Authorization'] = 'Bearer ' . $this->access_token;
            $headers['X-User-Token'] = $this->user_token;
        }

        $args = array(
            'method' => $method,
            'headers' => $headers,
            'timeout' => 45,
        );

        if ($payload !== null) {
            // Encode decimal amounts exactly (19.99, not 19.989999999999998) whatever the server's php.ini says.
            $precision = ini_get('serialize_precision');
            ini_set('serialize_precision', '-1'); // phpcs:ignore WordPress.PHP.IniSet.Risky, Squiz.PHP.DiscouragedFunctions.Discouraged -- Restored immediately below.
            $args['body'] = wp_json_encode($payload);
            ini_set('serialize_precision', (string) $precision); // phpcs:ignore WordPress.PHP.IniSet.Risky, Squiz.PHP.DiscouragedFunctions.Discouraged
        }

        $url = $this->base_url . $path;
        $this->log('request', array(
            'method' => $method,
            'url' => $url,
            'payload' => $this->redact($payload),
        ));

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            $this->log('transport_error', $response->get_error_message());
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $decoded = $body === '' ? array() : json_decode($body, true);

        $this->log('response', array(
            'code' => $code,
            'body' => $decoded !== null ? $this->redact($decoded) : '(non-JSON body, ' . strlen($body) . ' bytes)',
        ));

        if ($code < 200 || $code >= 300) {
            /* translators: %d: HTTP status code. */
            $message = sprintf(__('GetYourCash API error (HTTP %d)', 'getyourcash-paybylink'), $code);
            if (is_array($decoded)) {
                $api_code = $decoded['ErrorCode'] ?? $decoded['errorCode'] ?? '';
                $api_message = $decoded['ErrorMessage'] ?? $decoded['errorMessage'] ?? $decoded['message'] ?? '';
                if (is_scalar($api_code) && $api_code !== '') {
                    $message .= ' [' . sanitize_text_field((string) $api_code) . ']';
                }
                if (is_scalar($api_message) && $api_message !== '') {
                    $message .= ': ' . sanitize_text_field((string) $api_message);
                }
            } elseif ($this->looks_like_html($body)) {
                $message .= ': ' . $this->html_response_hint();
            }
            return new WP_Error('gyc_api_error', $message, array('status' => $code));
        }

        if ($decoded === null && $body !== '') {
            $message = __('Invalid JSON response from GetYourCash.', 'getyourcash-paybylink');
            if ($this->looks_like_html($body)) {
                $message .= ' ' . $this->html_response_hint();
            }
            return new WP_Error('gyc_invalid_json', $message);
        }

        return $decoded;
    }

    private function looks_like_html($body) {
        return (bool) preg_match('/^\s*</', (string) $body);
    }

    private function html_response_hint() {
        /* translators: %s: API base URL. */
        return sprintf(__('%s returned a web page instead of an API response: check that it is the GetYourCash API address.', 'getyourcash-paybylink'), $this->base_url);
    }

    /**
     * Recursively masks credentials, tokens and customer personal data before logging.
     */
    private function redact($data) {
        if (!is_array($data)) {
            return $data;
        }
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match('/password|token|secret|^customer(?!ref$)/i', $key)) {
                $data[$key] = '***';
            } elseif (is_array($value)) {
                $data[$key] = $this->redact($value);
            }
        }
        return $data;
    }

    private function log($event, $data) {
        if (!$this->debug || !$this->logger) {
            return;
        }
        $this->logger->debug($event . ' ' . wp_json_encode($data, JSON_UNESCAPED_SLASHES), array('source' => 'getyourcash'));
    }
}
