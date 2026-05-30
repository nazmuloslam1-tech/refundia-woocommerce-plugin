<?php
namespace Refundia;

defined('ABSPATH') || exit;

/**
 * Receives signed refund requests from the Refundia dashboard and applies them
 * via wc_create_refund(). HMAC-signed with the outbound_secret stored at pairing
 * time. Idempotent via the dashboard-supplied idempotency_key.
 */
class RefundHandler {

    const SIG_HEADER    = 'X-Refundia-Signature';
    const TS_HEADER     = 'X-Refundia-Timestamp';
    const MAX_AGE       = 300; // 5 minutes
    const IDEMPOTENCY_TTL = 86400; // 24 hours
    const IDEMP_OPTION_PREFIX = 'refundia_idemp_';

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes() {
        register_rest_route('refundia/v1', '/apply-refund', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_apply_refund'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle_apply_refund(\WP_REST_Request $req) {
        // 0) Plugin must be paired
        $secret = Pairing::get_outbound_secret();
        if (! $secret) {
            return new \WP_REST_Response(['ok' => false, 'error' => 'not_paired'], 412);
        }

        // 1) Signature + timestamp must be present
        $sigHeader = $req->get_header('x_refundia_signature');
        $tsHeader  = $req->get_header('x_refundia_timestamp');
        if (! $sigHeader || ! $tsHeader) {
            return new \WP_REST_Response(['ok' => false, 'error' => 'missing_signature'], 401);
        }

        // 2) Replay protection
        $ts = (int) $tsHeader;
        if ($ts <= 0 || abs(time() - $ts) > self::MAX_AGE) {
            return new \WP_REST_Response(['ok' => false, 'error' => 'stale_signature'], 401);
        }

        // 3) Verify HMAC over raw body + '.' + timestamp
        $bodyRaw = $req->get_body();
        $sigBase = $bodyRaw . '.' . $tsHeader;
        $expected = hash_hmac('sha256', $sigBase, $secret);
        if (! hash_equals($expected, (string) $sigHeader)) {
            return new \WP_REST_Response(['ok' => false, 'error' => 'bad_signature'], 401);
        }

        // 4) Parse payload
        $payload = json_decode($bodyRaw, true);
        if (! is_array($payload)) {
            return new \WP_REST_Response(['ok' => false, 'error' => 'invalid_json'], 400);
        }
        $order_id        = isset($payload['order_id']) ? sanitize_text_field($payload['order_id']) : '';
        $refund_amount   = isset($payload['refund_amount']) ? (float) $payload['refund_amount'] : 0;
        $reason          = isset($payload['reason']) ? sanitize_text_field($payload['reason']) : '';
        $idempotency_key = isset($payload['idempotency_key']) ? sanitize_text_field($payload['idempotency_key']) : '';

        if (! $order_id) {
            return new \WP_REST_Response(['ok' => false, 'error' => 'missing_order_id'], 400);
        }
        if (! $idempotency_key) {
            return new \WP_REST_Response(['ok' => false, 'error' => 'missing_idempotency_key'], 400);
        }

        // 5) Idempotency check
        $idempOption = self::IDEMP_OPTION_PREFIX . md5($idempotency_key);
        $previous = get_option($idempOption);
        if (is_array($previous) && isset($previous['processed_at'])) {
            return new \WP_REST_Response($previous, 200);
        }

        // 6) Look up the WC order
        if (! function_exists('wc_get_order') || ! function_exists('wc_create_refund')) {
            return new \WP_REST_Response(['ok' => false, 'error' => 'woocommerce_unavailable'], 500);
        }
        $order = wc_get_order($order_id);
        if (! $order) {
            return new \WP_REST_Response(['ok' => false, 'error' => 'order_not_found'], 404);
        }

        if ($refund_amount <= 0) {
            $refund_amount = (float) $order->get_total();
        }

        // 7) Create the refund
        $args = [
            'order_id'       => $order->get_id(),
            'amount'         => $refund_amount,
            'reason'         => $reason ? $reason : 'Refundia',
            'refund_payment' => true,
        ];
        $refund = wc_create_refund($args);
        if (is_wp_error($refund)) {
            return new \WP_REST_Response([
                'ok'    => false,
                'error' => 'wc_refund_failed: ' . $refund->get_error_message(),
            ], 422);
        }

        $result = [
            'ok'            => true,
            'refund_id'     => $refund->get_id(),
            'order_id'      => (string) $order->get_id(),
            'amount'        => (float) $refund->get_amount(),
            'processed_at'  => gmdate('c'),
        ];

        // Cache for idempotency
        update_option($idempOption, $result, false);
        // Schedule cleanup
        wp_schedule_single_event(time() + self::IDEMPOTENCY_TTL, 'refundia_cleanup_idemp', [$idempOption]);

        return new \WP_REST_Response($result, 200);
    }
}

// One-off cleanup callback for idempotency cache
add_action('refundia_cleanup_idemp', function ($idempOption) {
    delete_option($idempOption);
});
