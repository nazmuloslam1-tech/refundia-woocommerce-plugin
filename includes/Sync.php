<?php
namespace Refundia;

defined('ABSPATH') || exit;

/**
 * Forwards WooCommerce events to the Refundia dashboard.
 *
 * Hooks are only registered when the plugin is paired (has a stored token).
 * Failures are logged but never block WooCommerce — a Refundia hiccup must
 * never spoil a customer-facing checkout.
 */
class Sync {

    public static function init() {
        if (! Pairing::is_paired()) return;
        $self = new self();
        add_action('woocommerce_new_order',            [$self, 'on_new_order'],            20, 1);
        add_action('woocommerce_order_status_changed', [$self, 'on_order_status_changed'], 20, 3);
        add_action('woocommerce_order_refunded',       [$self, 'on_order_refunded'],       20, 2);
        add_action('woocommerce_create_refund',        [$self, 'on_create_refund'],        20, 2);
        add_action('woocommerce_new_customer',         [$self, 'on_new_customer'],         20, 1);
        add_action('save_post_product',                [$self, 'on_product_save'],         20, 1);
    }

    public function on_new_order($order_id) {
        if (! function_exists('wc_get_order')) return;
        $order = wc_get_order($order_id);
        if (! $order) return;
        $created = $order->get_date_created();
        $this->post_to_refundia('/sync/order', [
            'woo_order_id'   => (string) $order_id,
            'customer_email' => $order->get_billing_email(),
            'customer_name'  => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
            'total'          => (float) $order->get_total(),
            'currency'       => $order->get_currency(),
            'status'         => $order->get_status(),
            'created_at'     => $created ? $created->date('c') : null,
        ]);
        if ($order->get_billing_email()) {
            $this->post_to_refundia('/sync/customer', [
                'email'   => $order->get_billing_email(),
                'name'    => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                'country' => $order->get_billing_country(),
            ]);
        }
    }

    public function on_order_status_changed($order_id, $old_status, $new_status) {
        if (! function_exists('wc_get_order')) return;
        $order = wc_get_order($order_id);
        if (! $order) return;
        $this->post_to_refundia('/sync/order', [
            'woo_order_id' => (string) $order_id,
            'status'       => $new_status,
            'total'        => (float) $order->get_total(),
            'currency'     => $order->get_currency(),
        ]);
    }

    public function on_order_refunded($order_id, $refund_id) {
        if (! function_exists('wc_get_order')) return;
        $order  = wc_get_order($order_id);
        $refund = wc_get_order($refund_id);
        if (! $order || ! $refund) return;
        $created = $refund->get_date_created();
        $this->post_to_refundia('/sync/refund', [
            'woo_order_id'   => (string) $order_id,
            'refund_id'      => (string) $refund_id,
            'amount'         => (float) $refund->get_amount(),
            'reason'         => $refund->get_reason(),
            'customer_email' => $order->get_billing_email(),
            'customer_name'  => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
            'created_at'     => $created ? $created->date('c') : null,
        ]);
    }

    public function on_create_refund($refund, $args) {
        if (empty($args['order_id'])) return;
        $this->on_order_refunded($args['order_id'], $refund->get_id());
    }

    public function on_new_customer($customer_id) {
        $user = get_userdata($customer_id);
        if (! $user) return;
        $this->post_to_refundia('/sync/customer', [
            'email'   => $user->user_email,
            'name'    => trim($user->first_name . ' ' . $user->last_name),
            'country' => null,
        ]);
    }

    public function on_product_save($post_id) {
        if (get_post_type($post_id) !== 'product') return;
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) return;
        if (! function_exists('wc_get_product')) return;
        $product = wc_get_product($post_id);
        if (! $product) return;
        $this->post_to_refundia('/sync/product', [
            'woo_product_id' => (string) $post_id,
            'name'           => $product->get_name(),
            'sku'            => $product->get_sku(),
            'price'          => (float) $product->get_price(),
        ]);
    }

    /**
     * Fire-and-forget POST to the Refundia backend.
     */
    private function post_to_refundia($path, $payload) {
        $token = Pairing::get_token();
        if (! $token) return;
        $url = REFUNDIA_API_BASE . $path;
        $res = wp_remote_post($url, [
            'timeout'  => 5,
            'blocking' => false,
            'headers'  => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ],
            'body'     => wp_json_encode($payload),
        ]);
        if (is_wp_error($res)) {
            error_log('[Refundia] Sync POST failed for ' . $path . ': ' . $res->get_error_message());
        }
    }
}
