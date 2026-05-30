<?php
namespace Refundia;

defined('ABSPATH') || exit;

/**
 * REST routes exposed by the plugin.
 * Currently only the pair-callback route the dashboard hits to deliver the
 * long-lived auth token after the merchant enters the pairing code.
 */
class RestRoutes {

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes() {
        register_rest_route('refundia/v1', '/pair-callback', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_pair_callback'],
            'permission_callback' => '__return_true', // shared secret is checked manually below
            'args' => [
                'pairing_code' => ['required' => true, 'type' => 'string'],
                'token'        => ['required' => true, 'type' => 'string'],
                'store_id'     => ['required' => true, 'type' => 'string'],
                'email'        => ['required' => true, 'type' => 'string'],
            ],
        ]);
    }

    /**
     * Dashboard → plugin: "here is the long-lived token for the code I just
     * verified for this user".
     */
    public function handle_pair_callback(\WP_REST_Request $req) {
        $code     = sanitize_text_field($req->get_param('pairing_code'));
        $token    = sanitize_text_field($req->get_param('token'));
        $store_id = sanitize_text_field($req->get_param('store_id'));
        $email    = sanitize_email($req->get_param('email'));

        // Shared secret: the pairing code we ourselves generated must match.
        $expected = Pairing::get_pending_code();
        if (! $expected || ! hash_equals($expected, $code)) {
            return new \WP_REST_Response(['error' => 'invalid_or_expired_code'], 401);
        }

        Pairing::receive_token($token, $store_id, $email);

        return new \WP_REST_Response(['ok' => true], 200);
    }
}
