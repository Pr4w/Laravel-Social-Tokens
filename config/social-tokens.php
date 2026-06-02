<?php

use Pr4w\SocialTokens\Connectors\InstagramConnector;
use Pr4w\SocialTokens\Connectors\LinkedInConnector;
use Pr4w\SocialTokens\Connectors\ThreadsConnector;
use Pr4w\SocialTokens\Connectors\TikTokConnector;
use Pr4w\SocialTokens\Connectors\GoogleConnector;

return [

    /*
    |--------------------------------------------------------------------------
    | Table name
    |--------------------------------------------------------------------------
    */
    'table' => 'social_accounts',

    /*
    |--------------------------------------------------------------------------
    | Renewal scheduling
    |--------------------------------------------------------------------------
    |
    | How often the dispatcher command scans for accounts whose token is due
    | for renewal. Each account carries its own renew_at, computed from the
    | connector lead time, so this only needs to run often enough to catch the
    | shortest lead time you support (TikTok renews a couple of hours early).
    |
    */
    'dispatch_schedule' => 'everyFifteenMinutes',

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
    | One entry per provider. "driver" is the ProviderConnector class. The
    | remaining keys are passed to the connector as its config array. Leave
    | "driver" as null for providers you have not implemented yet.
    |
    */
    'connectors' => [

        'tiktok' => [
            'driver' => TikTokConnector::class,
            'client_id' => env('TIKTOK_CLIENT_KEY'),
            'client_secret' => env('TIKTOK_CLIENT_SECRET'),
        ],

        'instagram' => [
            'driver' => InstagramConnector::class,
            'client_id' => env('INSTAGRAM_CLIENT_ID'),       // Meta App ID
            'client_secret' => env('INSTAGRAM_CLIENT_SECRET'), // Meta App secret
            'graph_version' => env('META_GRAPH_VERSION', 'v23.0'),
        ],

        'threads' => [
            'driver' => ThreadsConnector::class,
            'client_id' => env('THREADS_CLIENT_ID'),
            'client_secret' => env('THREADS_CLIENT_SECRET'),
        ],

        'linkedin' => [
            'driver' => LinkedInConnector::class,
            'client_id' => env('LINKEDIN_CLIENT_ID'),
            'client_secret' => env('LINKEDIN_CLIENT_SECRET'),
            // Set true only if your app has Marketing Developer Platform access
            // and therefore receives refresh tokens. Otherwise the connector
            // flags accounts for re-authorisation before the 60 day expiry.
            'refresh_enabled' => env('LINKEDIN_REFRESH_ENABLED', false),
        ],

        // Placeholder. YouTube Shorts (StableRefreshToken strategy).
        'google' => [
            'driver' => GoogleConnector::class,
            'client_id' => env('GOOGLE_CLIENT_ID'),
            'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        ],

    ],

];
