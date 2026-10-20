<?php

return [

    /*
    |--------------------------------------------------------------------------
    | DKIM keys path
    |--------------------------------------------------------------------------
    |
    | Directory where generated DKIM private keys are written. The admin
    | panel runs on the host (not in Docker) — this must be the same host
    | path docker-compose.yml bind-mounts into rspamd (./docker/data/dkim),
    | so set it relative to wherever `php artisan` is actually run from.
    |
    */
    'dkim_keys_path' => env('DKIM_KEYS_PATH', '../docker/data/dkim'),

    /*
    |--------------------------------------------------------------------------
    | Default mailbox quota
    |--------------------------------------------------------------------------
    */
    'default_quota_mb' => (int) env('MAIL_DEFAULT_QUOTA_MB', 3072),

    /*
    |--------------------------------------------------------------------------
    | Mail server hostname
    |--------------------------------------------------------------------------
    |
    | The FQDN this whole stack serves (same value as the docker-compose
    | APP_DOMAIN variable). Used as the MX target when syncing DNS records.
    |
    */
    'app_domain' => env('APP_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Dovecot master user (webmail impersonation)
    |--------------------------------------------------------------------------
    |
    | Lets the admin panel open any mailbox's webmail without knowing its
    | real password, by authenticating to Dovecot as "<mailbox>*<master
    | user>" with this password instead (see docker/dovecot/10-auth.conf).
    |
    */
    'dovecot_master_user' => env('DOVECOT_MASTER_USER', 'admin'),
    'dovecot_master_password' => env('DOVECOT_MASTER_PASSWORD'),

    /*
    |--------------------------------------------------------------------------
    | Mail service hosts (webmail)
    |--------------------------------------------------------------------------
    |
    | The admin panel always runs on the host, outside Docker, so it talks
    | to Dovecot/Postfix through their published ports on 127.0.0.1, not
    | their Docker service names.
    |
    */
    'imap_host' => env('IMAP_HOST', '127.0.0.1'),
    'smtp_host' => env('SMTP_HOST', '127.0.0.1'),
];
