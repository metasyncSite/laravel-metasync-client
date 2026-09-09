<?php

return [
    /*
    |--------------------------------------------------------------------------
    | MetaSync connection
    |--------------------------------------------------------------------------
    | The base URL of your MetaSync installation and the project API token
    | (issued when the project is created in MetaSync).
    */
    'url' => env('METASYNC_URL', 'https://app.metasync.site'),
    'token' => env('METASYNC_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Webhook
    |--------------------------------------------------------------------------
    | Secret used to verify pings from MetaSync (returned by
    | `php artisan metasync:webhook`). When a signed ping arrives at
    | /metasync/webhook the package pulls fresh meta automatically.
    */
    'webhook_secret' => env('METASYNC_WEBHOOK_SECRET'),
    'webhook_path' => 'metasync/webhook',

    /*
    |--------------------------------------------------------------------------
    | Redirects middleware
    |--------------------------------------------------------------------------
    | When enabled, the package registers a global middleware that applies
    | redirects synced from MetaSync before your routes run.
    */
    'redirects_enabled' => env('METASYNC_REDIRECTS', true),

    /*
    |--------------------------------------------------------------------------
    | Scheduled pull
    |--------------------------------------------------------------------------
    | When enabled, `metasync:pull` self-registers on the scheduler as a
    | fallback for sites where the webhook cannot reach the server.
    */
    'schedule_pull' => env('METASYNC_SCHEDULE_PULL', true),
    'schedule_cron' => env('METASYNC_SCHEDULE_CRON', '*/15 * * * *'),

    /*
    |--------------------------------------------------------------------------
    | Default language
    |--------------------------------------------------------------------------
    */
    'default_lang' => env('METASYNC_DEFAULT_LANG', 'uk'),
];
