<?php
// Copy this file to ONE of these locations and fill in real values:
//   1) /home/<cpanel-user>/repol-config.php   (recommended: outside public_html)
//   2) public_html/api/config.php              (blocked from the web by api/.htaccess)
// Never commit the real file.
return [
    'app_url'   => 'https://repol.ir',          // no trailing slash
    'app_name'  => 'Repol',
    'env'       => 'production',               // 'development' shows error details

    'db' => [
        'dsn'  => 'mysql:host=localhost;dbname=CPANELUSER_repol;charset=utf8mb4',
        'user' => 'CPANELUSER_repol',
        'pass' => 'CHANGE_ME',
    ],

    // 32+ random characters. Used to encrypt Instagram tokens and sign file links.
    // Generate with:  php -r "echo bin2hex(random_bytes(32));"
    'secret' => 'CHANGE_ME_TO_64_HEX_CHARS',

    // One-time code for creating the very first admin account (setup screen).
    'setup_token' => 'CHANGE_ME',

    // Where uploaded files are stored. Keep it OUTSIDE public_html.
    'storage_dir' => '/home/CPANELUSER/repol-storage',

    // cPanel → Email Accounts → info@repol.ir → "Connect Devices" shows these values.
    'mail' => [
        'host'       => 'mail.repol.ir',
        'port'       => 465,                    // 465 = SSL, 587 = STARTTLS
        'encryption' => 'ssl',                  // 'ssl' | 'tls'
        'user'       => 'info@repol.ir',
        'pass'       => 'CHANGE_ME',
        'from'       => 'info@repol.ir',
        'from_name'  => 'ریپل · Repol',
    ],

    // https://www.zarinpal.com → panel → merchant ID (36 chars). sandbox=true for testing.
    'zarinpal' => [
        'merchant_id' => '',
        'sandbox'     => false,
    ],

    // TRON network (TRX). api key from https://www.trongrid.io (free) — optional but recommended.
    'tron' => [
        'api'     => 'https://api.trongrid.io',
        'api_key' => '',
    ],

    // Meta app → Instagram → API setup with Instagram login.
    'instagram' => [
        'app_id'       => '',
        'app_secret'   => '',
        'verify_token' => 'CHANGE_ME_RANDOM',   // same value in Meta webhook settings
        'graph'        => 'https://graph.instagram.com/v23.0',
    ],

    // Cloudflare Worker relay (relay/worker.js) for hosts in Iran that cannot reach Meta/Telegram.
    // Leave url empty when the server can reach graph.instagram.com directly.
    'relay' => [
        'url' => '',          // e.g. https://repol-relay.<your-subdomain>.workers.dev
        'key' => '',          // same value as the Worker's RELAY_KEY secret
    ],

    // Optional: Telegram bot for admin reports (new orders, payments, daily stats).
    'telegram' => [
        'bot_token' => '',
        'admin_chat_id' => '',
    ],
];
