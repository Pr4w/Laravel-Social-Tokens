<?php

use Pr4w\SocialTokens\Connectors\FacebookConnector;
use Pr4w\SocialTokens\Connectors\GoogleConnector;
use Pr4w\SocialTokens\Connectors\InstagramConnector;
use Pr4w\SocialTokens\Connectors\LinkedInConnector;
use Pr4w\SocialTokens\Connectors\MetaGraphConnector;
use Pr4w\SocialTokens\Connectors\ThreadsConnector;
use Pr4w\SocialTokens\Connectors\TikTokConnector;
use Pr4w\SocialTokens\Connectors\XConnector;

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
    | lead time, so it must run clearly more often than the shortest lead time
    | you support, queue latency included: Google renews 25 minutes early.
    |
    | Either a scheduler frequency method that takes no argument
    | ('everyTenMinutes', 'hourly') or a cron expression ('5,20,35,50 * * * *',
    | '@hourly'). Anything else, including a method that only picks the days
    | ('weekdays'), logs an error and keeps the default.
    |
    | Set to null to disable (e.g. to schedule social-tokens:dispatch-renewals
    | yourself). Both scheduled commands run withoutOverlapping()->onOneServer(),
    | which needs a cache store shared by your servers.
    |
    */
    'dispatch_schedule' => 'everyFifteenMinutes',

    /*
    |--------------------------------------------------------------------------
    | Static credential check
    |--------------------------------------------------------------------------
    |
    | Static credentials (Facebook page tokens) never expire, but die when the
    | user changes their password, removes the app or loses the Page's admin
    | role. This schedule asks the provider about each one (one light call per
    | credential) and flags the dead ones. Same forms as dispatch_schedule; set
    | to null to disable.
    |
    */
    'check_static_schedule' => 'daily',

    /*
    | Safety breaker for that check, applied to each provider: when at least
    | `min_checked` of its credentials were checked and more than
    | `max_terminal_ratio` of them came back rejected, the run treats it as that
    | provider's incident (or a change in the app's setup), flags none of its
    | credentials and logs a critical alert instead. The same test over every
    | checked credential stops the whole run. A `min_checked` below 1 counts as 1.
    */
    'check_static_breaker' => ['min_checked' => 10, 'max_terminal_ratio' => 0.2],

    /*
    | When true, that same run also asks about renewable credentials between two
    | renewals (the Meta user token behind Instagram, Threads, LinkedIn), for
    | connectors implementing ChecksCredential, so a token the user revoked is
    | caught before its renewal comes due. One light call per credential per run.
    */
    'check_renewable' => env('SOCIAL_TOKENS_CHECK_RENEWABLE', false),

    /*
    |--------------------------------------------------------------------------
    | Meta data access
    |--------------------------------------------------------------------------
    |
    | A Facebook Login user token (the one Instagram accounts post with) has
    | two clocks: the token's own expiry, which renewals extend (or none at
    | all), and data access, which lapses 90 days after the user was last
    | active in your app and only comes back with a new login. The package
    | stores the second (data_access_expires_at) from debug_token at connect
    | and after each renewal, and the dispatcher fires
    | CredentialDataAccessExpiring once, `lead_days` before it, for a
    | credential an active account posts with. Meta does not say whether a
    | lapse stops publishing with business permissions, so nothing is flagged:
    | ask the user to reconnect.
    |
    */
    'data_access_warning' => [
        'enabled' => env('SOCIAL_TOKENS_DATA_ACCESS_WARNING', true),
        'lead_days' => 7,
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | When true, renewal errors a connector does not recognise are logged via
    | Log::error with the provider, the credential id, the reason and a redacted
    | context (error codes, HTTP status, the response's keys), so you can
    | catalogue them into explicit terminal/transient cases over time. The
    | response body is never logged: it may carry tokens.
    | Known errors (classified transient or terminal) are not logged here. A
    | provider rejecting the app's own OAuth client (wrong or missing client id
    | or secret) is always logged as critical, whatever this setting.
    |
    */
    'log_unknown_errors' => true,

    /*
    |--------------------------------------------------------------------------
    | Provider requests
    |--------------------------------------------------------------------------
    |
    | Timeouts (seconds) for every call to a provider. Keep their sum well under
    | 60s: renewals run under a 60s lock, and a request that outlives it lets a
    | second process refresh the same credential.
    |
    */
    'http' => [
        'timeout' => 15,
        'connect_timeout' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job retry behaviour
    |--------------------------------------------------------------------------
    |
    | "backoff" is in seconds: one value (60) or one per retry, the last one
    | repeating ([60, 300, 900]). The sync queue runs each job once, inline,
    | and applies neither "tries" nor "backoff": run a real queue worker.
    |
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
    |   'x'        => ['client_id' => env('X_CLIENT_ID'), 'client_secret' => env('X_CLIENT_SECRET')],
    |
    */
    'connectors' => [

        'tiktok' => [
            'driver' => TikTokConnector::class,
        ],

        // Instagram through its linked Facebook Page (Facebook Login). Its
        // connections and renewals go through the facebook connector, whose
        // graph_version is the one they use: META_GRAPH_VERSION sets both.
        'instagram' => [
            'driver' => InstagramConnector::class,
            'credentials' => 'facebook',   // shares the Meta app with Facebook
            'graph_version' => env('META_GRAPH_VERSION', MetaGraphConnector::DEFAULT_GRAPH_VERSION),
            // See facebook below: appSecretProof() for your own Instagram calls.
            'appsecret_proof' => env('META_APPSECRET_PROOF', true),
        ],

        'facebook' => [
            'driver' => FacebookConnector::class,
            'credentials' => 'facebook',
            'graph_version' => env('META_GRAPH_VERSION', MetaGraphConnector::DEFAULT_GRAPH_VERSION),
            // Sign Graph calls made with a user or Page token with
            // appsecret_proof, so the Meta app can turn on "Require App
            // Secret". Needs the secret of the app that issued the tokens.
            'appsecret_proof' => env('META_APPSECRET_PROOF', true),
        ],

        'threads' => [
            'driver' => ThreadsConnector::class,
            // Connect-time long-lived exchange needs services.threads credentials;
            // the background refresh then uses only the stored access token.
        ],

        'linkedin' => [
            'driver' => LinkedInConnector::class,
            // Set true only if your app has Marketing Developer Platform access
            // and therefore receives refresh tokens. Otherwise the credential
            // fires CredentialExpiringSoon ahead of the 60 day expiry and is
            // flagged for re-authorisation once it actually expires. A
            // credential stored without a refresh token is renewed that way
            // even when this is true.
            'refresh_enabled' => env('LINKEDIN_REFRESH_ENABLED', false),
            // organizationAcls roles that can publish as the Page. Organizations
            // the member holds only through other roles (ANALYST, CURATOR,
            // LEAD_GEN_FORMS_MANAGER...) are not stored, and are reconciled away.
            'posting_roles' => LinkedInConnector::DEFAULT_POSTING_ROLES,
        ],

        // X (Twitter) via OAuth 2.0: connect with Socialite's `x` driver and
        // request offline.access (plus tweet.write, users.read, media.write).
        'x' => [
            'driver' => XConnector::class,
        ],

        // YouTube Shorts via Google OAuth2 (StableRefreshToken strategy).
        'google' => [
            'driver' => GoogleConnector::class,
        ],

    ],

];
