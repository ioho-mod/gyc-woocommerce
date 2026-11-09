<?php
/**
 * Plugin Name:          GetYourCash PayByLink
 * Description:          Accept WooCommerce payments through GetYourCash PayByLink, with pre-authorization, redirect and automatic status synchronization.
 * Version:              1.0.0
 * Requires at least:    6.5
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * Author:               IOHO SRL
 * License:              GPLv2 or later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          getyourcash-paybylink
 * Domain Path:          /languages
 * WC requires at least: 7.0
 * WC tested up to:      11.1
 *
 * @package GetYourCash_PayByLink
 */

defined('ABSPATH') || exit;

define('GYC_PBL_VERSION', '1.0.0');
define('GYC_PBL_FILE', __FILE__);
define('GYC_PBL_DIR', plugin_dir_path(__FILE__));
define('GYC_PBL_URL', plugin_dir_url(__FILE__));
define('GYC_PBL_SYNC_HOOK', 'gyc_pbl_sync_pending_orders');
define('GYC_PBL_AS_GROUP', 'getyourcash');

add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'cart_checkout_blocks',
            __FILE__,
            true
        );
    }
});

add_action('init', function () {
    // Distributed through WooCommerce.com, not WordPress.org: the bundled translations must be loaded explicitly.
    load_plugin_textdomain('getyourcash-paybylink', false, dirname(plugin_basename(__FILE__)) . '/languages'); // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound
});

add_action('plugins_loaded', function () {
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    require_once GYC_PBL_DIR . 'includes/class-gyc-pbl-api-client.php';
    require_once GYC_PBL_DIR . 'includes/class-gyc-pbl-gateway.php';

    add_filter('woocommerce_payment_gateways', function ($gateways) {
        $gateways[] = 'GYC_PBL_Gateway';
        return $gateways;
    });

    if (defined('WP_CLI') && WP_CLI) {
        require_once GYC_PBL_DIR . 'includes/class-gyc-pbl-cli.php';
        WP_CLI::add_command('gyc', 'GYC_PBL_CLI');
    }
}, 20);

add_action('woocommerce_blocks_loaded', function () {
    if (!class_exists('\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        return;
    }

    require_once GYC_PBL_DIR . 'includes/class-gyc-pbl-blocks-support.php';

    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function ($registry) {
            $registry->register(new GYC_PBL_Blocks_Support());
        }
    );
});

add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $url = admin_url('admin.php?page=wc-settings&tab=checkout&section=getyourcash');
    array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'getyourcash-paybylink') . '</a>');
    return $links;
});

/**
 * Returns the registered gateway instance, or null when WooCommerce is not ready.
 *
 * @return GYC_PBL_Gateway|null
 */
function gyc_pbl_get_gateway() {
    if (!function_exists('WC') || !WC()->payment_gateways()) {
        return null;
    }
    $gateways = WC()->payment_gateways()->payment_gateways();
    return $gateways['getyourcash'] ?? null;
}

/**
 * Schedules the recurring status sync through Action Scheduler (bundled with WooCommerce).
 */
function gyc_pbl_schedule_sync() {
    if (!function_exists('as_has_scheduled_action') || !function_exists('as_schedule_recurring_action')) {
        return;
    }
    if (false === as_has_scheduled_action(GYC_PBL_SYNC_HOOK, array(), GYC_PBL_AS_GROUP)) {
        as_schedule_recurring_action(
            time() + 5 * MINUTE_IN_SECONDS,
            5 * MINUTE_IN_SECONDS,
            GYC_PBL_SYNC_HOOK,
            array(),
            GYC_PBL_AS_GROUP
        );
    }
}

// Self-heal the schedule (e.g. after a WooCommerce reinstall) without querying on every front-end request.
add_action('admin_init', 'gyc_pbl_schedule_sync');
register_activation_hook(__FILE__, 'gyc_pbl_schedule_sync');

register_deactivation_hook(__FILE__, function () {
    if (function_exists('as_unschedule_all_actions')) {
        as_unschedule_all_actions(GYC_PBL_SYNC_HOOK, array(), GYC_PBL_AS_GROUP);
    }
});

add_action(GYC_PBL_SYNC_HOOK, function () {
    $gateway = gyc_pbl_get_gateway();
    if ($gateway && method_exists($gateway, 'sync_pending_orders')) {
        $gateway->sync_pending_orders();
    }
});

/**
 * Capture on status change ("Authorize only" mode): when a pre-authorized order is moved to Processing or
 * Completed. Runs on woocommerce_order_status_{status}, which fires before the customer emails.
 * If the capture fails, the order goes back to On hold so it is never left as paid without funds.
 */
function gyc_pbl_maybe_capture($order_id, $order = null) {
    $order = $order instanceof WC_Order ? $order : wc_get_order($order_id);
    if (!$order || 'getyourcash' !== $order->get_payment_method()) {
        return;
    }
    $gateway = gyc_pbl_get_gateway();
    if (!$gateway || 'yes' !== $gateway->get_option('auto_capture', 'yes') || !$gateway->can_capture($order)) {
        return;
    }
    $result = $gateway->capture_payment($order);
    if (is_wp_error($result)) {
        $order->update_status('on-hold', __('GetYourCash capture failed; order put back on hold.', 'getyourcash-paybylink'));
        if (class_exists('WC_Admin_Meta_Boxes')) {
            WC_Admin_Meta_Boxes::add_error($result->get_error_message());
        }
    }
}
add_action('woocommerce_order_status_processing', 'gyc_pbl_maybe_capture', 5, 2);
add_action('woocommerce_order_status_completed', 'gyc_pbl_maybe_capture', 5, 2);

add_filter('woocommerce_order_actions', function ($actions, $order = null) {
    if (!$order instanceof WC_Order) {
        global $theorder;
        $order = $theorder;
    }
    if (!$order instanceof WC_Order || 'getyourcash' !== $order->get_payment_method()) {
        return $actions;
    }
    $gateway = gyc_pbl_get_gateway();
    if ($gateway && $gateway->can_capture($order)) {
        $actions['gyc_pbl_capture'] = __('Capture GetYourCash payment', 'getyourcash-paybylink');
    }
    if ($order->get_meta('_gyc_transaction_id')) {
        $actions['gyc_pbl_sync'] = __('Check GetYourCash payment status', 'getyourcash-paybylink');
    }
    return $actions;
}, 10, 2);

add_action('woocommerce_order_action_gyc_pbl_capture', function ($order) {
    $gateway = gyc_pbl_get_gateway();
    if (!$gateway) {
        return;
    }
    $result = $gateway->capture_payment($order);
    if (is_wp_error($result) && class_exists('WC_Admin_Meta_Boxes')) {
        WC_Admin_Meta_Boxes::add_error($result->get_error_message());
    }
});

add_action('woocommerce_order_action_gyc_pbl_sync', function ($order) {
    $gateway = gyc_pbl_get_gateway();
    if (!$gateway) {
        return;
    }
    $result = $gateway->sync_order($order);
    if (is_wp_error($result) && class_exists('WC_Admin_Meta_Boxes')) {
        WC_Admin_Meta_Boxes::add_error($result->get_error_message());
    }
});

// The API has no void endpoint: tell the merchant what happens to the reserved funds.
add_action('woocommerce_order_status_cancelled', function ($order_id, $order = null) {
    $order = $order instanceof WC_Order ? $order : wc_get_order($order_id);
    $gateway = gyc_pbl_get_gateway();
    if ($order && $gateway && $gateway->can_capture($order)) {
        $order->add_order_note(__('GetYourCash: the pre-authorization was not captured, so the customer is not charged. The reserved funds are released according to the card issuer\'s rules; contact GetYourCash to release them earlier.', 'getyourcash-paybylink'));
    }
}, 10, 2);

add_action('admin_init', function () {
    if (!function_exists('wp_add_privacy_policy_content')) {
        return;
    }
    wp_add_privacy_policy_content(
        __('GetYourCash PayByLink', 'getyourcash-paybylink'),
        wp_kses_post(wpautop(__('When you pay with GetYourCash, we send your name, company, billing address, phone number, email address, customer ID and the order amount to GetYourCash to process the payment. GetYourCash handles your payment details according to its own privacy policy.', 'getyourcash-paybylink')))
    );
});
