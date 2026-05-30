<?php
namespace Refundia;

defined('ABSPATH') || exit;

class Plugin {

    public function __construct() {
        new RestRoutes();
        new RefundHandler();
        Sync::init();
        add_action('admin_menu',  [$this, 'add_admin_menu']);
        add_action('admin_post_refundia_pair',   [$this, 'handle_pair_request']);
        add_action('admin_post_refundia_unpair', [$this, 'handle_unpair_request']);
    }

    public function add_admin_menu() {
        add_menu_page(
            'Refundia',
            'Refundia',
            'manage_woocommerce',
            'refundia',
            [$this, 'render_admin_page'],
            'dashicons-update',
            56
        );
    }

    public function handle_pair_request() {
        if (! current_user_can('manage_woocommerce')) wp_die('Not allowed');
        check_admin_referer('refundia_pair');
        $result = Pairing::start_pair();
        $args = ['page' => 'refundia'];
        $args[$result['ok'] ? 'paired' : 'pair_error'] = $result['ok'] ? '1' : rawurlencode($result['error'] ?? 'unknown');
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    public function handle_unpair_request() {
        if (! current_user_can('manage_woocommerce')) wp_die('Not allowed');
        check_admin_referer('refundia_unpair');
        Pairing::unpair();
        wp_safe_redirect(add_query_arg(['page' => 'refundia', 'unpaired' => '1'], admin_url('admin.php')));
        exit;
    }

    public function render_admin_page() {
        $paired   = Pairing::is_paired();
        $code     = Pairing::get_pending_code();
        $email    = Pairing::get_email();
        $dash_url = 'https://refundia-dashboard-plus-api.vercel.app/#/integrations';
        ?>
        <div class="wrap">
            <h1>Refundia &mdash; Returns Management</h1>

            <?php if (! empty($_GET['pair_error'])) : ?>
                <div class="notice notice-error"><p>Could not start pairing: <?php echo esc_html(rawurldecode($_GET['pair_error'])); ?></p></div>
            <?php endif; ?>

            <?php if ($paired) : ?>
                <div class="notice notice-success"><p><strong>✓ Connected</strong> to Refundia account: <code><?php echo esc_html($email); ?></code></p></div>
                <p>Your store is paired with Refundia. Returns, orders and refunds will sync automatically once the WooCommerce hooks ship in the next release.</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Unpair this store from Refundia?');">
                    <?php wp_nonce_field('refundia_unpair'); ?>
                    <input type="hidden" name="action" value="refundia_unpair" />
                    <?php submit_button('Unpair', 'delete'); ?>
                </form>
            <?php elseif ($code) : ?>
                <div class="notice notice-info"><p>Your pairing code is ready.</p></div>
                <p>Open the Refundia dashboard, go to <strong>Integrations → WooCommerce</strong>, and paste this code:</p>
                <p style="font-size:34px;letter-spacing:6px;font-family:monospace;background:#f6f7f7;padding:18px 24px;display:inline-block;border:1px solid #ccd0d4;border-radius:6px;"><strong><?php echo esc_html($code); ?></strong></p>
                <p>This code expires in 15 minutes. After you enter it in the dashboard, this page will update automatically (refresh after pairing).</p>
                <p><a class="button button-primary" target="_blank" href="<?php echo esc_url($dash_url); ?>">Open Refundia Dashboard</a></p>
            <?php else : ?>
                <p>Pair this store with your Refundia dashboard account in one step. We&rsquo;ll generate a short code; you paste it in the Refundia dashboard.</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('refundia_pair'); ?>
                    <input type="hidden" name="action" value="refundia_pair" />
                    <?php submit_button('Pair with Refundia', 'primary large'); ?>
                </form>
                <p style="margin-top:24px;"><a href="<?php echo esc_url($dash_url); ?>" target="_blank">Open Refundia Dashboard →</a></p>
            <?php endif; ?>
        </div>
        <?php
    }
}
