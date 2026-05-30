<?php
namespace Refundia;

defined('ABSPATH') || exit;

class Plugin {
    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
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

    public function render_admin_page() {
        ?>
        <div class="wrap">
            <h1>Refundia — Returns Management</h1>
            <p>Welcome to Refundia. Pairing flow coming in the next release.</p>
            <p><strong>Status:</strong> Not paired with Refundia dashboard yet.</p>
            <p><a href="https://refundia-dashboard-plus-api.vercel.app" target="_blank" class="button button-primary">Open Refundia Dashboard</a></p>
        </div>
        <?php
    }
}
