<?php
/**
 * M-Pesa headless login - EXAMPLE.
 *
 * Copy this file to mpesa/config.php (git-ignored). M-Pesa callbacks and the cron job
 * post payments with FrontAccounting's own code, so they log in as a FrontAccounting
 * user. Create a dedicated user for it (Setup > User Accounts) with a role that can
 * enter customer payments and allocate them, and nothing else.
 *
 * If this file is missing, api/config.php's service account is used instead.
 */
return array(
    'fa_root' => dirname(__DIR__),
    'fa_company' => 0,
    'fa_service_user' => 'mpesa',
    'fa_service_pass' => 'change-me',

    // Optional: only accept callbacks from these addresses (Safaricom's callback IPs).
    // Leave empty to rely on the secret in the callback URL alone.
    'allowed_ips' => array(),
);
