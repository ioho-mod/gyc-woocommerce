<?php
/**
 * Removes plugin settings and scheduled actions on uninstall.
 * Order meta (_gyc_*) is kept, as it is part of the store's order history.
 *
 * @package GetYourCash_PayByLink
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

delete_option('woocommerce_getyourcash_settings');

if (function_exists('as_unschedule_all_actions')) {
    as_unschedule_all_actions('gyc_pbl_sync_pending_orders', array(), 'getyourcash');
}
