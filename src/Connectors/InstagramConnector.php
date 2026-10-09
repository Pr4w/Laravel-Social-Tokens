<?php

namespace Pr4w\SocialTokens\Connectors;

use Pr4w\SocialTokens\Support\RenewalResult;

/**
 * Instagram publishing via the Instagram Graph API, Facebook Login path: the
 * Instagram professional account is reached through the Facebook Page it is
 * linked to, and posts with the Facebook user's long-lived token.
 *
 * Token facts (per Meta developer docs; renewal behaviour is not guaranteed,
 * see SocialToken::applyRenewal() for a renewal that does not extend):
 *  - publishing uses a Facebook long lived User access token (~60 days)
 *  - there is NO refresh token; the long lived token is extended in place via
 *    the fb_exchange_token grant on graph.facebook.com (MetaGraphConnector)
 *
 * An account with no Facebook Page uses the "Instagram API with Instagram
 * Login" instead (graph.instagram.com, ig_exchange_token and
 * ig_refresh_token): InstagramLoginConnector, under its own provider key.
 * Swapping the requests here would not do, since StoreConnection sends every
 * connection of this connector to the Facebook Page fan-out and its
 * credentials are renewed under "facebook".
 */
class InstagramConnector extends MetaGraphConnector
{
    /**
     * Instagram shares the Meta user credential, refreshed via the Facebook
     * connector (fb_exchange_token). So its credentials live under "facebook".
     * refreshCredential() is therefore not used by the package; it is kept for
     * custom setups that store provider 'instagram'.
     */
    public function credentialProvider(): string
    {
        return 'facebook';
    }

    public function exchangeForLongLived(#[\SensitiveParameter] string $accessToken): ?RenewalResult
    {
        return $this->exchangeUserToken($accessToken);
    }
}
