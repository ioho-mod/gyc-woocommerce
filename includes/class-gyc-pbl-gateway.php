<?php
/**
 * GetYourCash PayByLink payment gateway.
 *
 * @package GetYourCash_PayByLink
 */

defined('ABSPATH') || exit;

class GYC_PBL_Gateway extends WC_Payment_Gateway {
    const PAID_STATUSES = array('PaidStatus');
    const PREAUTH_STATUSES = array('PreAuthStatus');
    // UndoPreAuthStatus is not in the API document (v1.7); the sandbox returns it when a pre-authorization is cancelled.
    const FAILED_STATUSES = array('Cancelled', 'Expired', 'HardDeclined', 'FailedStatus', 'BurnedLink', 'UndoPreAuthStatus');

    const API_URLS = array(
        'production' => 'https://coresecure.getyourcash.eu',
        'sandbox' => 'https://coretestsecure.getyourcash.eu',
    );

    public function __construct() {
        $this->id = 'getyourcash';
        $this->icon = apply_filters('gyc_pbl_icon', GYC_PBL_URL . 'assets/images/getyourcash-logo.png');
        $this->has_fields = false;
        $this->method_title = __('GetYourCash PayByLink', 'getyourcash-paybylink');
        $this->method_description = __('Take payments through GetYourCash PayByLink in pre-authorization mode, and capture them from the WooCommerce order screen.', 'getyourcash-paybylink');
        $this->supports = array('products');

        $this->init_form_fields();
        $this->init_settings();
        $this->migrate_legacy_settings();

        $this->title = $this->get_option('title', 'GetYourCash');
        $this->description = $this->get_option('description', __('Pay securely with GetYourCash.', 'getyourcash-paybylink'));
        $this->enabled = $this->get_option('enabled', 'no');

        if ($this->is_test_mode()) {
            $this->description = trim($this->description . ' ' . __('TEST MODE: no real payment will be taken.', 'getyourcash-paybylink'));
        }

        add_action(
            'woocommerce_update_options_payment_gateways_' . $this->id,
            array($this, 'process_admin_options')
        );
        add_action('woocommerce_api_gyc_paybylink', array($this, 'handle_return'));
    }

    /**
     * Pre-release builds stored a free-text API base URL. Map it to the new environment settings in memory;
     * the mapping is persisted the next time the merchant saves the settings.
     */
    private function migrate_legacy_settings() {
        if (isset($this->settings['testmode']) || empty($this->settings['base_url'])) {
            return;
        }
        if (preg_match('#//(core)?test(secure)?\.getyourcash\.eu#i', (string) $this->settings['base_url'])) {
            $this->settings['testmode'] = 'yes';
            $this->settings['sandbox_username'] = $this->settings['username'] ?? '';
            $this->settings['sandbox_password'] = $this->settings['password'] ?? '';
            $this->settings['sandbox_merchant_local_branch_id'] = $this->settings['merchant_local_branch_id'] ?? '';
        } else {
            $this->settings['testmode'] = 'no';
        }
    }

    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'getyourcash-paybylink'),
                'type' => 'checkbox',
                'label' => __('Enable GetYourCash PayByLink', 'getyourcash-paybylink'),
                'default' => 'no',
            ),
            'title' => array(
                'title' => __('Title', 'getyourcash-paybylink'),
                'type' => 'text',
                'description' => __('Payment method name shown to customers at checkout.', 'getyourcash-paybylink'),
                'default' => 'GetYourCash',
            ),
            'description' => array(
                'title' => __('Description', 'getyourcash-paybylink'),
                'type' => 'textarea',
                'default' => __('Pay securely with GetYourCash.', 'getyourcash-paybylink'),
            ),
            'testmode' => array(
                'title' => __('Test mode', 'getyourcash-paybylink'),
                'type' => 'checkbox',
                'label' => __('Use the GetYourCash sandbox', 'getyourcash-paybylink'),
                'description' => __('In test mode, payments go to the sandbox (coretestsecure.getyourcash.eu) with the sandbox credentials below, and no real money is taken.', 'getyourcash-paybylink'),
                'default' => 'no',
            ),
            'live_credentials' => array(
                'title' => __('Live credentials', 'getyourcash-paybylink'),
                'type' => 'title',
            ),
            'username' => array(
                'title' => __('API username', 'getyourcash-paybylink'),
                'type' => 'text',
                'default' => '',
            ),
            'password' => array(
                'title' => __('API password', 'getyourcash-paybylink'),
                'type' => 'password',
                'default' => '',
            ),
            'merchant_local_branch_id' => array(
                'title' => __('Merchant Local Branch ID', 'getyourcash-paybylink'),
                'type' => 'number',
                'description' => __('Save your credentials to see the list of available branches. Filled in automatically when your account has only one branch.', 'getyourcash-paybylink'),
                'default' => '',
            ),
            'sandbox_credentials' => array(
                'title' => __('Sandbox credentials', 'getyourcash-paybylink'),
                'type' => 'title',
            ),
            'sandbox_username' => array(
                'title' => __('Sandbox API username', 'getyourcash-paybylink'),
                'type' => 'text',
                'default' => '',
            ),
            'sandbox_password' => array(
                'title' => __('Sandbox API password', 'getyourcash-paybylink'),
                'type' => 'password',
                'default' => '',
            ),
            'sandbox_merchant_local_branch_id' => array(
                'title' => __('Sandbox Merchant Local Branch ID', 'getyourcash-paybylink'),
                'type' => 'number',
                'description' => __('Save your credentials to see the list of available branches. Filled in automatically when your account has only one branch.', 'getyourcash-paybylink'),
                'default' => '',
            ),
            'payment_options' => array(
                'title' => __('Payment options', 'getyourcash-paybylink'),
                'type' => 'title',
            ),
            'validity' => array(
                'title' => __('Link validity (minutes)', 'getyourcash-paybylink'),
                'type' => 'number',
                'default' => '1440',
                'custom_attributes' => array('min' => '15', 'max' => '86400'),
            ),
            'payment_action' => array(
                'title' => __('Payment action', 'getyourcash-paybylink'),
                'type' => 'select',
                'description' => __('"Authorize only" reserves the funds on the customer\'s card and puts the order On hold: the payment is captured when you confirm the order. "Authorize and capture" charges the customer as soon as the payment is authorized and sets the order to Processing.', 'getyourcash-paybylink'),
                'default' => 'authorize',
                'options' => array(
                    'authorize' => __('Authorize only (capture later)', 'getyourcash-paybylink'),
                    'capture' => __('Authorize and capture immediately', 'getyourcash-paybylink'),
                ),
            ),
            'auto_capture' => array(
                'title' => __('Capture on status change', 'getyourcash-paybylink'),
                'type' => 'checkbox',
                'label' => __('Capture the payment when the order is moved to Processing or Completed', 'getyourcash-paybylink'),
                'description' => __('Applies to "Authorize only". If the capture fails, the order is put back On hold. You can always capture manually with the "Capture GetYourCash payment" order action.', 'getyourcash-paybylink'),
                'default' => 'yes',
            ),
            'custom_paybylink' => array(
                'title' => __('Custom PayByLink', 'getyourcash-paybylink'),
                'type' => 'checkbox',
                'label' => __('Set IsUsingCustomPayByLink = true', 'getyourcash-paybylink'),
                'default' => 'no',
            ),
            'redirect_after_ms' => array(
                'title' => __('RedirectAfterMs', 'getyourcash-paybylink'),
                'type' => 'number',
                'description' => __('Delay in milliseconds before GetYourCash redirects the customer back to the store.', 'getyourcash-paybylink'),
                'default' => '3000',
            ),
            'debug' => array(
                'title' => __('Debug log', 'getyourcash-paybylink'),
                'type' => 'checkbox',
                'label' => __('Log API requests', 'getyourcash-paybylink'),
                'description' => __('Logs are written to WooCommerce > Status > Logs (source "getyourcash"). Credentials and customer data are masked.', 'getyourcash-paybylink'),
                'default' => 'no',
            ),
        );
    }

    /**
     * "authorize" (capture later) or "capture" (capture as soon as the payment is authorized).
     */
    public function get_payment_action() {
        return 'capture' === $this->get_option('payment_action', 'authorize') ? 'capture' : 'authorize';
    }

    public function is_test_mode() {
        return 'yes' === $this->get_option('testmode', 'no');
    }

    /**
     * Returns an environment-specific setting (live, or the "sandbox_" prefixed one in test mode).
     */
    private function get_env_option($key, $environment = null) {
        $environment = $environment ? $environment : $this->get_environment();
        return $this->get_option('sandbox' === $environment ? 'sandbox_' . $key : $key);
    }

    private function get_environment() {
        return $this->is_test_mode() ? 'sandbox' : 'production';
    }

    /**
     * The environment an order was created in, so later calls go to the same API.
     */
    private function get_order_environment($order) {
        $environment = (string) $order->get_meta('_gyc_environment');
        return isset(self::API_URLS[$environment]) ? $environment : $this->get_environment();
    }

    private function get_api_base_url($environment = null) {
        $environment = $environment ? $environment : $this->get_environment();
        /**
         * Filters the GetYourCash API base URL.
         *
         * @param string $url         Base URL.
         * @param string $environment "production" or "sandbox".
         */
        return (string) apply_filters('gyc_pbl_api_base_url', self::API_URLS[$environment], $environment);
    }

    /**
     * Currencies accepted by the PayByLink API.
     */
    public function get_supported_currencies() {
        return (array) apply_filters('gyc_pbl_supported_currencies', array('EUR'));
    }

    /**
     * The logo is 250x125; keep it at text height next to the payment method name in the classic checkout.
     */
    public function get_icon() {
        $icon = '';
        if ($this->icon) {
            $icon = '<img src="' . esc_url($this->icon) . '" alt="' . esc_attr__('GetYourCash', 'getyourcash-paybylink') . '" style="max-height:28px;width:auto;" />';
        }
        return apply_filters('woocommerce_gateway_icon', $icon, $this->id); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core filter.
    }

    public function is_available() {
        if (!parent::is_available()) {
            return false;
        }
        return in_array(get_woocommerce_currency(), $this->get_supported_currencies(), true);
    }

    public function admin_options() {
        if (!in_array(get_woocommerce_currency(), $this->get_supported_currencies(), true)) {
            echo '<div class="notice notice-error inline"><p>' . esc_html(
                sprintf(
                    /* translators: 1: store currency, 2: supported currencies. */
                    __('GetYourCash is not shown at checkout because your store currency is %1$s. Supported currencies: %2$s.', 'getyourcash-paybylink'),
                    get_woocommerce_currency(),
                    implode(', ', $this->get_supported_currencies())
                )
            ) . '</p></div>';
        }
        parent::admin_options();
    }

    /**
     * Saves the settings, then checks the credentials and lists the merchant's branches.
     */
    public function process_admin_options() {
        $saved = parent::process_admin_options();

        if ('yes' !== $this->get_option('enabled') && !trim((string) $this->get_env_option('username'))) {
            return $saved;
        }
        if (!class_exists('WC_Admin_Settings')) {
            return $saved;
        }

        $environment = $this->is_test_mode() ? __('sandbox', 'getyourcash-paybylink') : __('live', 'getyourcash-paybylink');
        $branches = $this->client()->local_branches();

        if (is_wp_error($branches)) {
            WC_Admin_Settings::add_error(
                /* translators: 1: environment (live/sandbox), 2: error message. */
                sprintf(__('GetYourCash (%1$s): connection test failed. %2$s', 'getyourcash-paybylink'), $environment, $branches->get_error_message())
            );
            return $saved;
        }

        $labels = array();
        $ids = array();
        foreach ((array) $branches as $branch) {
            if (!is_array($branch) || !isset($branch['id'])) {
                continue;
            }
            $ids[] = (int) $branch['id'];
            $name = trim(implode(' ', array_filter(array($branch['alias'] ?? '', $branch['city'] ?? ''), 'is_scalar')));
            $labels[] = (int) $branch['id'] . ($name !== '' ? ' (' . sanitize_text_field($name) . ')' : '');
        }

        WC_Admin_Settings::add_message(
            /* translators: 1: environment (live/sandbox), 2: list of branches. */
            sprintf(__('GetYourCash (%1$s): connection OK. Available Merchant Local Branch IDs: %2$s', 'getyourcash-paybylink'), $environment, $labels ? implode(', ', $labels) : '-')
        );

        $branch_key = $this->is_test_mode() ? 'sandbox_merchant_local_branch_id' : 'merchant_local_branch_id';
        $branch_id = (int) $this->get_option($branch_key);
        if ($ids && !in_array($branch_id, $ids, true)) {
            if (1 === count($ids)) {
                $this->update_option($branch_key, (string) $ids[0]);
                WC_Admin_Settings::add_message(
                    /* translators: %d: branch ID. */
                    sprintf(__('GetYourCash: Merchant Local Branch ID set to %d, the only branch on your account.', 'getyourcash-paybylink'), $ids[0])
                );
            } else {
                WC_Admin_Settings::add_error(
                    $branch_id > 0
                        /* translators: %d: configured branch ID. */
                        ? sprintf(__('GetYourCash: Merchant Local Branch ID %d is not one of your branches.', 'getyourcash-paybylink'), $branch_id)
                        : __('GetYourCash: enter one of the Merchant Local Branch IDs listed above.', 'getyourcash-paybylink')
                );
            }
        }

        return $saved;
    }

    private function validate_configuration() {
        $missing = array();

        if (!trim((string) $this->get_env_option('username'))) {
            $missing[] = __('API username', 'getyourcash-paybylink');
        }
        if (!trim((string) $this->get_env_option('password'))) {
            $missing[] = __('API password', 'getyourcash-paybylink');
        }
        if ((int) $this->get_env_option('merchant_local_branch_id') <= 0) {
            $missing[] = __('Merchant Local Branch ID', 'getyourcash-paybylink');
        }

        if ($missing) {
            return new WP_Error(
                'gyc_incomplete_configuration',
                /* translators: %s: comma-separated list of missing settings. */
                sprintf(__('GetYourCash configuration is incomplete: %s.', 'getyourcash-paybylink'), implode(', ', $missing))
            );
        }

        return true;
    }

    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            wc_add_notice(__('Invalid order.', 'getyourcash-paybylink'), 'error');
            return array('result' => 'failure');
        }

        $configuration = $this->validate_configuration();
        if (is_wp_error($configuration)) {
            $this->log_error($configuration->get_error_message());
            $order->add_order_note($configuration->get_error_message());
            wc_add_notice(__('This payment method is temporarily unavailable. Please choose another payment method or contact us.', 'getyourcash-paybylink'), 'error');
            return array('result' => 'failure');
        }

        $transaction_id = (string) $order->get_id() . '-' . wp_generate_password(8, false, false);
        $success_url = add_query_arg(
            array(
                'wc-api' => 'gyc_paybylink',
                'order_id' => $order->get_id(),
                'key' => $order->get_order_key(),
            ),
            home_url('/')
        );
        $amount = $this->to_api_amount($order->get_total(), $order->get_currency());

        $payload = array(
            'TransactionId' => $transaction_id,
            'Validity' => $this->get_validity(),
            'Currency' => $order->get_currency(),
            'Amount' => $amount,
            /* translators: %s: order number. */
            'Description' => mb_substr(sprintf(__('WooCommerce order #%s', 'getyourcash-paybylink'), $order->get_order_number()), 0, 500),
            'PaymentType' => 1,
            'SuccessWebUrl' => $success_url,
            'FailedWebUrl' => wc_get_checkout_url(),
            'IsUsingCustomPayByLink' => ('yes' === $this->get_option('custom_paybylink')),
            'RedirectAfterMs' => max(0, (int) $this->get_option('redirect_after_ms', 3000)),
            'CustomerRef' => (string) $order->get_customer_id(),
            'CustomerFirstName' => $order->get_billing_first_name(),
            'CustomerLastName' => $order->get_billing_last_name(),
            'CustomerCompanyName' => $order->get_billing_company(),
            'CustomerAddress' => trim($order->get_billing_address_1() . ' ' . $order->get_billing_address_2()),
            'CustomerCity' => $order->get_billing_city(),
            'CustomerZipCode' => $order->get_billing_postcode(),
            'CustomerCountry' => $order->get_billing_country(),
            'CustomerPhoneNumber' => $order->get_billing_phone(),
            'CustomerEmail' => $order->get_billing_email(),
        );

        $payload['MerchantLocalBranchId'] = (int) $this->get_env_option('merchant_local_branch_id');

        /**
         * Filters the PayByLink create request before it is sent.
         *
         * @param array    $payload Request payload.
         * @param WC_Order $order   Order being paid.
         */
        $payload = apply_filters('gyc_pbl_create_payload', $payload, $order);

        $response = $this->client()->create($payload);

        if (is_wp_error($response)) {
            $order->add_order_note('GetYourCash: ' . $response->get_error_message());
            wc_add_notice(__('We could not start the GetYourCash payment. Please try again or choose another payment method.', 'getyourcash-paybylink'), 'error');
            return array('result' => 'failure');
        }

        $payment_link = isset($response['paymentLink']) && is_string($response['paymentLink']) ? esc_url_raw($response['paymentLink']) : '';
        // The Status API is keyed by the TransactionId we sent. The "transactionId" in the Create response is
        // the link code (urlCode), which the Status API does not accept.
        $sent_transaction = isset($payload['TransactionId']) ? (string) $payload['TransactionId'] : $transaction_id;
        $url_code = isset($response['transactionId']) && is_scalar($response['transactionId'])
            ? sanitize_text_field((string) $response['transactionId'])
            : '';

        if ($payment_link === '') {
            $order->add_order_note(__('GetYourCash: the response did not contain a paymentLink.', 'getyourcash-paybylink'));
            wc_add_notice(__('We could not start the GetYourCash payment. Please try again or choose another payment method.', 'getyourcash-paybylink'), 'error');
            return array('result' => 'failure');
        }

        $order->update_meta_data('_gyc_transaction_id', $sent_transaction);
        $order->update_meta_data('_gyc_url_code', $url_code);
        $order->update_meta_data('_gyc_payment_link', $payment_link);
        $order->update_meta_data('_gyc_authorized_amount', (float) ($payload['Amount'] ?? $amount));
        $order->update_meta_data('_gyc_environment', $this->get_environment());
        $order->delete_meta_data('_gyc_payment_status');
        $order->delete_meta_data('_gyc_captured_at');
        $order->save();
        $order->update_status('pending', __('Awaiting GetYourCash pre-authorization.', 'getyourcash-paybylink'));

        // The cart is emptied when the customer returns (handle_return), so an abandoned payment keeps it intact.
        return array(
            'result' => 'success',
            'redirect' => $payment_link,
        );
    }

    public function handle_return() {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- External redirect; authenticated by the order key.
        $order_id = isset($_GET['order_id']) ? absint(wp_unslash($_GET['order_id'])) : 0;
        $key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';
        // phpcs:enable

        $order = $order_id ? wc_get_order($order_id) : false;

        if (!$order || !hash_equals((string) $order->get_order_key(), (string) $key)) {
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        $this->sync_order($order);

        if ($order->has_status(array('failed', 'cancelled'))) {
            wc_add_notice(__('Your GetYourCash payment was not completed. Please try again.', 'getyourcash-paybylink'), 'error');
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        if (WC()->cart) {
            WC()->cart->empty_cart();
        }
        wp_safe_redirect($this->get_return_url($order));
        exit;
    }

    /**
     * Syncs every recent pending GetYourCash order. Called by Action Scheduler and WP-CLI.
     *
     * @return array<int, string|WP_Error> Results keyed by order ID.
     */
    public function sync_pending_orders() {
        if ('yes' !== $this->enabled || is_wp_error($this->validate_configuration())) {
            return array();
        }

        // Stop polling once the link has expired and a day of grace has passed.
        $window = $this->get_validity() * MINUTE_IN_SECONDS + DAY_IN_SECONDS;

        $orders = wc_get_orders(array(
            'limit' => (int) apply_filters('gyc_pbl_sync_batch_size', 50),
            'status' => array('pending'),
            'payment_method' => $this->id,
            'date_created' => '>' . (time() - $window),
            'orderby' => 'date',
            'order' => 'ASC',
            'return' => 'objects',
        ));

        $results = array();
        foreach ($orders as $order) {
            if (!$order->get_meta('_gyc_transaction_id')) {
                continue;
            }
            $results[$order->get_id()] = $this->sync_order($order);
        }

        // Pre-authorized orders waiting On hold: re-check them at most once an hour, to catch a pre-authorization
        // cancelled on the GetYourCash side or a capture made from the GetYourCash dashboard.
        $interval = (int) apply_filters('gyc_pbl_on_hold_sync_interval', HOUR_IN_SECONDS);
        $held = wc_get_orders(array(
            'limit' => (int) apply_filters('gyc_pbl_sync_batch_size', 50),
            'status' => array('on-hold'),
            'payment_method' => $this->id,
            'date_created' => '>' . (time() - 30 * DAY_IN_SECONDS),
            'orderby' => 'date',
            'order' => 'ASC',
            'return' => 'objects',
        ));
        foreach ($held as $order) {
            if (!$this->can_capture($order) || time() - (int) $order->get_meta('_gyc_last_sync') < $interval) {
                continue;
            }
            $order->update_meta_data('_gyc_last_sync', time());
            $order->save();
            $results[$order->get_id()] = $this->sync_order($order);
        }

        return $results;
    }

    public function sync_order($order) {
        $transaction_id = $order->get_meta('_gyc_transaction_id');
        if (!$transaction_id) {
            return new WP_Error('gyc_missing_transaction', __('GetYourCash TransactionId is missing.', 'getyourcash-paybylink'));
        }

        $status_rows = $this->client($this->get_order_environment($order))->status($transaction_id);
        if (is_wp_error($status_rows)) {
            $this->log_error('sync #' . $order->get_id() . ': ' . $status_rows->get_error_message());
            return $status_rows;
        }

        if (isset($status_rows['paymentStatus'])) {
            $status_rows = array($status_rows);
        }

        $latest = is_array($status_rows) && $status_rows ? end($status_rows) : array();
        $latest = is_array($latest) ? $latest : array();
        $payment_status = isset($latest['paymentStatus']) && is_scalar($latest['paymentStatus']) ? sanitize_text_field((string) $latest['paymentStatus']) : '';
        $previous_status = (string) $order->get_meta('_gyc_payment_status');

        $order->update_meta_data('_gyc_payment_status', $payment_status);
        if (!empty($latest['authorizationId']) && is_scalar($latest['authorizationId'])) {
            $order->update_meta_data('_gyc_authorization_id', sanitize_text_field((string) $latest['authorizationId']));
        }
        $order->save();

        if ($payment_status === $previous_status) {
            return $payment_status;
        }

        /* translators: %s: GetYourCash payment status. */
        $note = sprintf(__('GetYourCash status: %s.', 'getyourcash-paybylink'), $payment_status ? $payment_status : __('not available', 'getyourcash-paybylink'));

        if (in_array($payment_status, self::PAID_STATUSES, true)) {
            if (!$order->is_paid()) {
                $order->payment_complete($transaction_id);
            }
            $order->add_order_note($note);
        } elseif (in_array($payment_status, self::PREAUTH_STATUSES, true)) {
            if (!$order->has_status('pending')) {
                $order->add_order_note($note);
            } elseif ('capture' === $this->get_payment_action()) {
                $order->set_transaction_id($transaction_id);
                $order->add_order_note($note);
                $captured = $this->capture_payment($order);
                if (is_wp_error($captured)) {
                    $order->update_status(
                        'on-hold',
                        __('Immediate capture failed: the funds are still reserved. Capture the payment with the "Capture GetYourCash payment" order action.', 'getyourcash-paybylink')
                    );
                }
            } else {
                $order->set_transaction_id($transaction_id);
                $order->update_status(
                    'on-hold',
                    $note . ' ' . __('Funds are reserved: move the order to Processing or Completed to capture the payment.', 'getyourcash-paybylink')
                );
            }
        } elseif (in_array($payment_status, self::FAILED_STATUSES, true)) {
            if ($order->has_status(array('pending', 'on-hold'))) {
                $order->update_status('failed', $note);
            } else {
                $order->add_order_note($note);
            }
        } else {
            $order->add_order_note($note);
        }

        return $payment_status;
    }

    /**
     * Whether the order holds a GetYourCash pre-authorization that has not been captured yet.
     */
    public function can_capture($order) {
        return $order instanceof WC_Order
            && $this->id === $order->get_payment_method()
            && in_array($order->get_meta('_gyc_payment_status'), self::PREAUTH_STATUSES, true)
            && '' !== (string) $order->get_meta('_gyc_authorization_id')
            && '' === (string) $order->get_meta('_gyc_captured_at');
    }

    /**
     * Captures the pre-authorized payment for the current order total (less any refunds).
     * Any remaining pre-authorized amount is released by GetYourCash.
     *
     * @return true|WP_Error
     */
    public function capture_payment($order) {
        if (!$this->can_capture($order)) {
            return new WP_Error('gyc_not_capturable', __('This order has no GetYourCash pre-authorization to capture.', 'getyourcash-paybylink'));
        }

        $amount = $this->to_api_amount(max(0, (float) $order->get_total() - (float) $order->get_total_refunded()), $order->get_currency());
        $authorized = (float) $order->get_meta('_gyc_authorized_amount');
        if ($authorized > 0 && $amount > $authorized + 0.0001) {
            $error = new WP_Error('gyc_capture_exceeds', __('The order total is higher than the pre-authorized amount. Create a new payment for the difference.', 'getyourcash-paybylink'));
            $order->add_order_note('GetYourCash: ' . $error->get_error_message());
            return $error;
        }

        $date_created = $order->get_date_created();
        $payload = array(
            'PayByLinkAuthorizationId' => (string) $order->get_meta('_gyc_authorization_id'),
            'EffectiveAmount' => $amount,
            'Orders' => array(
                array(
                    'Number' => mb_substr((string) $order->get_order_number(), 0, 20),
                    'Date' => $date_created ? $date_created->date('Y-m-d') : gmdate('Y-m-d'),
                ),
            ),
        );

        /**
         * Filters the capture (ConfirmPaymentAfterPreAuthorization) request before it is sent.
         *
         * @param array    $payload Request payload.
         * @param WC_Order $order   Order being captured.
         */
        $payload = apply_filters('gyc_pbl_capture_payload', $payload, $order);

        $response = $this->client($this->get_order_environment($order))->confirm_pre_authorization($payload);
        if (is_wp_error($response)) {
            /* translators: %s: error message. */
            $order->add_order_note(sprintf(__('GetYourCash capture failed: %s', 'getyourcash-paybylink'), $response->get_error_message()));
            return $response;
        }

        $order->update_meta_data('_gyc_captured_at', time());
        $order->update_meta_data('_gyc_captured_amount', $amount);
        if (!$order->get_date_paid()) {
            $order->set_date_paid(time());
        }
        $order->save();

        $order->add_order_note(sprintf(
            /* translators: %s: captured amount. */
            __('GetYourCash payment captured: %s.', 'getyourcash-paybylink'),
            wp_strip_all_tags(wc_price($amount, array('currency' => $order->get_currency())))
        ));

        if ($order->has_status(array('pending', 'on-hold'))) {
            $order->payment_complete((string) $order->get_meta('_gyc_transaction_id'));
        }

        return true;
    }

    private function get_validity() {
        return max(15, min(86400, (int) $this->get_option('validity', 1440)));
    }

    private function get_currency_decimals($currency) {
        $zero_decimal = array('BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF');
        $three_decimal = array('BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND');

        $decimals = 2;
        if (in_array($currency, $zero_decimal, true)) {
            $decimals = 0;
        } elseif (in_array($currency, $three_decimal, true)) {
            $decimals = 3;
        }

        /**
         * Filters the number of decimals the API amount is rounded to for a currency.
         *
         * @param int    $decimals Decimals.
         * @param string $currency ISO 4217 currency code.
         */
        return (int) apply_filters('gyc_pbl_currency_decimals', $decimals, $currency);
    }

    /**
     * Formats an amount for the API, which expects major units with decimals (EUR 18.50 is sent as 18.5),
     * not cents. Verified against the sandbox: "Amount": 1800 creates a EUR 1,800.00 payment.
     */
    private function to_api_amount($amount, $currency) {
        return round((float) $amount, $this->get_currency_decimals($currency));
    }

    private function log_error($message) {
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->error($message, array('source' => 'getyourcash'));
        }
    }

    private function client($environment = null) {
        return new GYC_PBL_API_Client(
            $this->get_api_base_url($environment),
            $this->get_env_option('username', $environment),
            $this->get_env_option('password', $environment),
            'yes' === $this->get_option('debug', 'no')
        );
    }
}
