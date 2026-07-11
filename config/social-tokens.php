<?php

use Pr4w\SocialTokens\Connectors\FacebookConnector;
use Pr4w\SocialTokens\Connectors\GoogleConnector;
use Pr4w\SocialTokens\Connectors\InstagramConnector;
use Pr4w\SocialTokens\Connectors\LinkedInConnector;
use Pr4w\SocialTokens\Connectors\ThreadsConnector;
use Pr4w\SocialTokens\Connectors\TikTokConnector;

return [

    /*
    |--------------------------------------------------------------------------
    | Table names
    |--------------------------------------------------------------------------
    |
    | "table" holds the postable accounts; "tokens_table" holds the renewable
    | credentials that back them (one credential can back many accounts).
    |
    */
    'table' => 'social_accounts',
    'tokens_table' => 'social_tokens',

    /*
    |--------------------------------------------------------------------------
    | Renewal scheduling
    |--------------------------------------------------------------------------
    |
    | How often the dispatcher command scans for credentials whose renew_at has
    | passed. Each credential carries its own renew_at, computed from the connector
    | lead time, so this only needs to run often enough to catch the shortest lead
    | time you support (TikTok renews a couple of hours early).
    |
    */
    'dispatch_schedule' => 'everyFifteenMinutes',

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | When true, renewal errors a connector does not recognise are logged via
    | Log::error with full context (provider, credential, reason, raw payload), so
    | you can catalogue them into explicit terminal/transient cases over time.
    | Known errors (classified transient or terminal) are never logged.
    |
    */
    'log_unknown_errors' => true,

    /*
    |--------------------------------------------------------------------------
    | Job retry behaviour
    |--------------------------------------------------------------------------
    */
    'queue' => [
        'connection' => env('SOCIAL_TOKENS_QUEUE_CONNECTION'),
        'queue' => env('SOCIAL_TOKENS_QUEUE'),
        'backoff' => [60, 300, 900],
        'tries' => 4,
    ],

    /*
    |--------------------------------------------------------------------------
    | Connectors
    |--------------------------------------------------------------------------
    |
    | One entry per provider. "driver" is the ProviderConnector class. Leave it
    | null for providers you have not implemented yet.
    |
    | Client credentials are NOT set here: connectors read client_id/client_secret
    | from Laravel Socialite's config/services.php, so each app declares them once.
    | The services entry defaults to the provider key below; point it elsewhere
    | with a "credentials" key (Instagram and Facebook share one Meta app). You can
    | still hard-set "client_id"/"client_secret" on an entry to override.
    |
    |   // config/services.php
    |   'facebook' => ['client_id' => env('META_APP_ID'), 'client_secret' => env('META_APP_SECRET')],
    |   'tiktok'   => ['client_id' => env('TIKTOK_CLIENT_KEY'), 'client_secret' => env('TIKTOK_CLIENT_SECRET')],
    |   'google'   => ['client_id' => env('GOOGLE_CLIENT_ID'), 'client_secret' => env('GOOGLE_CLIENT_SECRET')],
    |   'linkedin' => ['client_id' => env('LINKEDIN_CLIENT_ID'), 'client_secret' => env('LINKEDIN_CLIENT_SECRET')],
    |
    */
    'connectors' => [

        'tiktok' => [
            'driver' => TikTokConnector::class,
        ],

        'instagram' => [
            'driver' => InstagramConnector::class,
            'credentials' => 'facebook',   // shares the Meta app with Facebook
            'graph_version' => env('META_GRAPH_VERSION', 'v23.0'),
        ],

        'facebook' => [
            'driver' => FacebookConnector::class,
            'credentials' => 'facebook',
            'graph_version' => env('META_GRAPH_VERSION', 'v23.0'),
        ],

        'threads' => [
            'driver' => ThreadsConnector::class,
            // Connect-time long-lived exchange needs services.threads credentials;
            // the background refresh then uses only the stored access token.
        ],

        'linkedin' => [
            'driver' => LinkedInConnector::class,
            // Set true only if your app has Marketing Developer Platform access
            // and therefore receives refresh tokens. Otherwise the connector
            // flags accounts for re-authorisation before the 60 day expiry.
            'refresh_enabled' => env('LINKEDIN_REFRESH_ENABLED', false),
        ],

        // YouTube Shorts via Google OAuth2 (StableRefreshToken strategy).
        'google' => [
            'driver' => GoogleConnector::class,
        ],

    ],

];
