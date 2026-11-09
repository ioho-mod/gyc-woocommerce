<?php
/**
 * Cart & Checkout Blocks integration.
 *
 * @package GetYourCash_PayByLink
 */

defined('ABSPATH') || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class GYC_PBL_Blocks_Support extends AbstractPaymentMethodType {
    protected $name = 'getyourcash';
    private $gateway;

    public function initialize() {
        $this->settings = get_option('woocommerce_getyourcash_settings', array());
        $this->gateway = gyc_pbl_get_gateway();
    }

    public function is_active() {
        return $this->gateway && $this->gateway->is_available();
    }

    public function get_payment_method_script_handles() {
        wp_register_script(
            'gyc-blocks',
            GYC_PBL_URL . 'assets/js/blocks.js',
            array('wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities'),
            GYC_PBL_VERSION,
            true
        );
        return array('gyc-blocks');
    }

    public function get_payment_method_data() {
        return array(
            'title' => sanitize_text_field($this->gateway ? $this->gateway->get_title() : 'GetYourCash'),
            'description' => wp_kses_post($this->gateway ? $this->gateway->get_description() : ''),
            'icon' => $this->gateway ? esc_url_raw($this->gateway->icon) : '',
            'supports' => $this->gateway ? array_values(array_filter($this->gateway->supports, array($this->gateway, 'supports'))) : array('products'),
        );
    }
}
