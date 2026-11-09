<?php
/**
 * WP-CLI commands.
 *
 * @package GetYourCash_PayByLink
 */

defined('ABSPATH') || exit;

/**
 * Manage GetYourCash PayByLink payments.
 */
class GYC_PBL_CLI {
    /**
     * Synchronizes the status of pending GetYourCash orders.
     *
     * ## EXAMPLES
     *
     *     wp gyc sync
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function sync($args, $assoc_args) {
        $gateway = gyc_pbl_get_gateway();
        if (!$gateway || !method_exists($gateway, 'sync_pending_orders')) {
            WP_CLI::error('GetYourCash gateway is not available.');
        }

        $results = $gateway->sync_pending_orders();
        if (!$results) {
            WP_CLI::success('No pending GetYourCash orders.');
            return;
        }

        foreach ($results as $order_id => $result) {
            if (is_wp_error($result)) {
                WP_CLI::warning('#' . $order_id . ' ' . $result->get_error_message());
            } else {
                WP_CLI::log('#' . $order_id . ' => ' . ($result ? $result : 'no status'));
            }
        }
        WP_CLI::success(count($results) . ' order(s) processed.');
    }
}
