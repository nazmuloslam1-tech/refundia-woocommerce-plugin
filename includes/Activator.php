<?php
namespace Refundia;

defined('ABSPATH') || exit;

class Activator {
    public static function activate() {
        // Will create DB tables, schedule webhooks, etc. in later phases
        add_option('refundia_version', REFUNDIA_VERSION);
    }
}
