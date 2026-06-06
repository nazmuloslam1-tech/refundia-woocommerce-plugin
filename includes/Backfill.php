<?php
namespace Refundia;

defined('ABSPATH') || exit;

/**
 * Backfill — re-pushes existing WooCommerce orders / products / customers
 * to the Refundia dashboard. Two entry points:
 *
 *  1) Manual: dashboard hits /wp-json/refundia/v1/backfill (HMAC-signed)
 *     with { mode: orders|products|customers, page, per_page }.
 *     Plugin walks one page and reports back.
 *
 *  2) Auto:   right after pairing completes, schedule a one-off WP-cron
 *     event that walks everything in the background (chunked).
 *
 * Reuses the same fire-and-forget POST helper as Sync.php so the data
 * lands in the existing /api/sync/* endpoints.
 *
 * Also exposes /wp-json/refundia/v1/health — used by the dashboard
 * "Test connection" button. No auth required (just confirms the route exists).
 */
class Backfill {

    const SIG_HEADER = 'X-Refundia-Signature';
    const TS_HEADER  = 'X-Refundia-Timestamp';
    const MAX_AGE    = 300;
    const CRON_HOOK  = 'refundia_auto_backfill';
    const MAX_PER_PAGE = 100;

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
        add_action(self::CRON_HOOK,  [$this, 'run_auto_backfill_chunk'], 10, 2);
    }

    public function register_routes() {
        register_rest_route('refundia/v1', '/backfill', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_backfill'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('refundia/v1', '/health', [
            'methods'             => 'GET',
            'callback'            => [$this, 'handle_health'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * GET /wp-json/refundia/v1/health
     * Returns plugin version + pairing status. Used by dashboard for the
     * "Test connection" button.
     */
    public function handle_health(\WP_REST_Request $req) {
        return new \WP_REST_Response([
            'ok'             => true,
            'plugin'         => 'refundia',
            'plugin_version' => defined('REFUNDIA_VERSION') ? REFUNDIA_VERSION : 'unknown',
            'paired'         => Pairing::is_paired(),
            'wc_active'      => class_exists('WooCommerce'),
            'site_url'       => home_url(),
        ], 200);
    }

    /**
     * POST /wp-json/refundia/v1/backfill
     * HMAC-signed (X-Refundia-Signature, X-Refundia-Timestamp).
     * Body: { mode: orders|products|customers, page, per_page }
     */
    public function handle_backfill(\WP_REST_Request $req) {
        $secret = Pairing::get_outbound_secret();
        if (! $secret) {
            return new \WP_REST_Response(['ok' => false, 'error' => 'not_paired'], 412);
        }
        $sigHeader = $req->get_header('x_refundia_signature');
        $tsHeader  = $req->get_header('x_refundia_timestamp');
        if (! $sigHeader || ! $tsHeader) {
            return new \WP_REST_Response(['ok' => false, 'error' => 'missing_signature'], 401);
        }
        $ts = (int) $tsHeader;
        if ($ts <= 0 || abs(time() - $ts) > self::MAX_AGE) {
            return new \WP_REST_Response(['ok' => false, 'error' => 'stale_signature'], 401);
        }
        $bodyRaw  = $req->get_body();
        $expected = hash_hmac('sha256', $bodyRaw . '.' . $tsHeader, $secret);
        if (! hash_equals($expected, (string) $sigHeader)) {
            return new \WP_REST_Response(['ok' => false, 'error' => 'bad_signature'], 401);
        }

        $payload  = json_decode($bodyRaw, true);
        if (! is_array($payload)) {
            return new \WP_REST_Response(['ok' => false, 'error' => 'invalid_json'], 400);
        }
        $mode     = isset($payload['mode']) ? sanitize_text_field($payload['mode']) : 'orders';
        $page     = max(1, (int) ($payload['page']     ?? 1));
        $per_page = min(self::MAX_PER_PAGE, max(1, (int) ($payload['per_page'] ?? 25)));

        switch ($mode) {
            case 'orders':    return new \WP_REST_Response($this->backfill_orders($page, $per_page), 200);
            case 'products':  return new \WP_REST_Response($this->backfill_products($page, $per_page), 200);
            case 'customers': return new \WP_REST_Response($this->backfill_customers($page, $per_page), 200);
            default:
                return new \WP_REST_Response(['ok' => false, 'error' => 'unknown_mode'], 400);
        }
    }

    /**
     * After successful pairing, schedule a chunked background backfill.
     * Called from Pairing::receive_token().
     */
    public static function schedule_auto_backfill() {
        if (! wp_next_scheduled(self::CRON_HOOK, ['orders', 1])) {
            wp_schedule_single_event(time() + 5, self::CRON_HOOK, ['orders', 1]);
        }
        if (! wp_next_scheduled(self::CRON_HOOK, ['products', 1])) {
            wp_schedule_single_event(time() + 10, self::CRON_HOOK, ['products', 1]);
        }
        if (! wp_next_scheduled(self::CRON_HOOK, ['customers', 1])) {
            wp_schedule_single_event(time() + 15, self::CRON_HOOK, ['customers', 1]);
        }
    }

    /**
     * WP-cron callback: walk one page, reschedule next.
     */
    public function run_auto_backfill_chunk($mode, $page) {
        if (! Pairing::is_paired()) return;
        $per_page = 25;
        $result = ($mode === 'orders')    ? $this->backfill_orders($page, $per_page)
                : (($mode === 'products') ? $this->backfill_products($page, $per_page)
                : (($mode === 'customers') ? $this->backfill_customers($page, $per_page)
                : ['ok' => false]));
        if (! empty($result['has_more'])) {
            wp_schedule_single_event(time() + 10, self::CRON_HOOK, [$mode, $page + 1]);
        }
    }

    /* ---------- orders ---------- */

    private function backfill_orders($page, $per_page) {
        if (! function_exists('wc_get_orders')) {
            return ['ok' => false, 'error' => 'woocommerce_unavailable'];
        }
        $orders = wc_get_orders([
            'limit'   => $per_page,
            'page'    => $page,
            'orderby' => 'date',
            'order'   => 'DESC',
            'paginate' => true,
        ]);
        $processed = 0;
        foreach ($orders->orders as $order) {
            if (! $order) continue;
            $created = $order->get_date_created();
            $this->push('/sync/order', [
                'woo_order_id'   => (string) $order->get_id(),
                'customer_email' => $order->get_billing_email(),
                'customer_name'  => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                'total'          => (float) $order->get_total(),
                'currency'       => $order->get_currency(),
                'status'         => $order->get_status(),
                'created_at'     => $created ? $created->date('c') : null,
            ]);
            $processed++;
        }
        return [
            'ok'        => true,
            'mode'      => 'orders',
            'page'      => $page,
            'per_page'  => $per_page,
            'processed' => $processed,
            'total'     => (int) $orders->total,
            'has_more'  => ($page * $per_page) < (int) $orders->total,
            'next_page' => (($page * $per_page) < (int) $orders->total) ? ($page + 1) : null,
        ];
    }

    /* ---------- products ---------- */

    private function backfill_products($page, $per_page) {
        if (! function_exists('wc_get_product')) {
            return ['ok' => false, 'error' => 'woocommerce_unavailable'];
        }
        $q = new \WP_Query([
            'post_type'      => 'product',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'post_status'    => ['publish', 'private', 'draft'],
            'fields'         => 'ids',
        ]);
        $processed = 0;
        foreach ($q->posts as $product_id) {
            $product = wc_get_product($product_id);
            if (! $product) continue;
            $this->push('/sync/product', [
                'woo_product_id' => (string) $product_id,
                'name'           => $product->get_name(),
                'sku'            => $product->get_sku(),
                'price'          => (float) $product->get_price(),
            ]);
            $processed++;
        }
        $total = (int) $q->found_posts;
        return [
            'ok'        => true,
            'mode'      => 'products',
            'page'      => $page,
            'per_page'  => $per_page,
            'processed' => $processed,
            'total'     => $total,
            'has_more'  => ($page * $per_page) < $total,
            'next_page' => (($page * $per_page) < $total) ? ($page + 1) : null,
        ];
    }

    /* ---------- customers ---------- */

    private function backfill_customers($page, $per_page) {
        $offset = ($page - 1) * $per_page;
        $users  = get_users([
            'role__in' => ['customer', 'subscriber'],
            'number'   => $per_page,
            'offset'   => $offset,
            'orderby'  => 'registered',
            'order'    => 'DESC',
            'count_total' => true,
            'fields'   => ['ID', 'user_email', 'display_name'],
        ]);
        $processed = 0;
        foreach ($users as $u) {
            if (empty($u->user_email)) continue;
            $first = get_user_meta($u->ID, 'first_name', true);
            $last  = get_user_meta($u->ID, 'last_name', true);
            $name  = trim($first . ' ' . $last) ?: $u->display_name;
            $country = get_user_meta($u->ID, 'billing_country', true) ?: null;
            $this->push('/sync/customer', [
                'email'   => $u->user_email,
                'name'    => $name,
                'country' => $country,
            ]);
            $processed++;
        }
        // count_total returns via global; use a separate cheap query for total.
        $totalQuery = new \WP_User_Query([
            'role__in' => ['customer', 'subscriber'],
            'count_total' => true,
            'fields' => 'ID',
            'number' => 1,
        ]);
        $total = (int) $totalQuery->get_total();
        return [
            'ok'        => true,
            'mode'      => 'customers',
            'page'      => $page,
            'per_page'  => $per_page,
            'processed' => $processed,
            'total'     => $total,
            'has_more'  => ($page * $per_page) < $total,
            'next_page' => (($page * $per_page) < $total) ? ($page + 1) : null,
        ];
    }

    /* ---------- shared push ---------- */

    private function push($path, $payload) {
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
            'body' => wp_json_encode($payload),
        ]);
        if (is_wp_error($res)) {
            error_log('[Refundia] Backfill POST failed for ' . $path . ': ' . $res->get_error_message());
        }
    }
}
