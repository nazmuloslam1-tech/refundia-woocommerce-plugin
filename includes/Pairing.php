<?php
namespace Refundia;

defined('ABSPATH') || exit;

/**
 * Pairing helpers.
 *
 * Plugin generates a short code, registers it with the dashboard, then waits
 * for the dashboard to call back with a long-lived token.
 */
class Pairing {

    const OPT_CODE       = 'refundia_pairing_code';
    const OPT_EXPIRES    = 'refundia_pairing_expires';
    const OPT_TOKEN      = 'refundia_token';
    const OPT_STORE_ID   = 'refundia_store_id';
    const OPT_EMAIL      = 'refundia_account_email';
    const OPT_PAIRED_AT      = 'refundia_paired_at';
    const OPT_OUTBOUND_SECRET = 'refundia_outbound_secret';
    const CODE_TTL       = 900; // 15 minutes

    /**
     * Generate a random pairing code like "ABCD-1234".
     */
    public static function generate_code() {
        $letters = strtoupper(substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ'), 0, 4));
        $digits  = str_pad((string) wp_rand(0, 9999), 4, '0', STR_PAD_LEFT);
        return $letters . '-' . $digits;
    }

    /**
     * Start pairing: generate a code, store it locally, and notify the
     * Refundia dashboard so it can recognise the code when the user enters it.
     */
    public static function start_pair() {
        $code = self::generate_code();
        update_option(self::OPT_CODE, $code, false);
        update_option(self::OPT_EXPIRES, time() + self::CODE_TTL, false);

        $payload = [
            'pairing_code'   => $code,
            'site_url'       => home_url(),
            'site_name'      => get_bloginfo('name'),
            'admin_email'    => get_bloginfo('admin_email'),
            'plugin_version' => REFUNDIA_VERSION,
        ];

        $response = wp_remote_post(REFUNDIA_API_BASE . '/pair/init', [
            'timeout' => 10,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            return ['ok' => false, 'error' => $response->get_error_message(), 'code' => $code];
        }
        $status = wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            return ['ok' => false, 'error' => 'Dashboard returned HTTP ' . $status, 'code' => $code];
        }
        return ['ok' => true, 'code' => $code];
    }

    /**
     * Called by the REST callback when the dashboard finishes pairing.
     */
    public static function receive_token($token, $store_id, $email, $outbound_secret = '') {
        update_option(self::OPT_TOKEN, $token, false);
        update_option(self::OPT_STORE_ID, $store_id, false);
        update_option(self::OPT_EMAIL, $email, false);
        update_option(self::OPT_PAIRED_AT, gmdate('c'), false);
        if (! empty($outbound_secret)) {
            update_option(self::OPT_OUTBOUND_SECRET, $outbound_secret, false);
        }
        delete_option(self::OPT_CODE);
        delete_option(self::OPT_EXPIRES);
        // Kick off background backfill of existing orders/products/customers.
        if (class_exists(__NAMESPACE__ . '\\Backfill')) {
            Backfill::schedule_auto_backfill();
        }
        return true;
    }

    public static function is_paired() {
        return ! empty(get_option(self::OPT_TOKEN));
    }

    public static function get_token() {
        return get_option(self::OPT_TOKEN);
    }

    public static function get_email() {
        return get_option(self::OPT_EMAIL);
    }

    public static function get_pending_code() {
        $code    = get_option(self::OPT_CODE);
        $expires = (int) get_option(self::OPT_EXPIRES);
        if (! $code || $expires < time()) return null;
        return $code;
    }

    public static function get_outbound_secret() {
        return get_option(self::OPT_OUTBOUND_SECRET);
    }

    public static function unpair() {
        delete_option(self::OPT_TOKEN);
        delete_option(self::OPT_STORE_ID);
        delete_option(self::OPT_EMAIL);
        delete_option(self::OPT_PAIRED_AT);
        delete_option(self::OPT_OUTBOUND_SECRET);
        delete_option(self::OPT_CODE);
        delete_option(self::OPT_EXPIRES);
        return true;
    }
}
