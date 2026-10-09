<?php

namespace Pr4w\SocialTokens\Connectors;

/**
 * Instagram publishing via the "Instagram API with Instagram Login": an
 * Instagram professional account (business or creator) connected directly,
 * with no Facebook Page and no Facebook user behind it.
 *
 * Token facts (per Meta developer docs):
 *  - Business Login for Instagram returns a short-lived token (1 hour); the
 *    connect-time exchange (ig_exchange_token, with the Instagram app secret)
 *    turns it into a long-lived one valid for 60 days
 *  - no refresh token; the long-lived token is extended via ig_refresh_token,
 *    with the token alone, once it is at least 24 hours old, while it is
 *    still valid, and with instagram_business_basic granted
 *  - every call goes to graph.instagram.com, with Instagram-scoped user ids
 *
 * Its own provider key (instagram_login by default), its own credential and
 * its own Meta credentials (the Instagram app id and secret, not the Facebook
 * app's): StoreConnection stores one account per connection, never the
 * Facebook Page fan-out of InstagramConnector.
 */
class InstagramLoginConnector extends LongLivedTokenConnector
{
    protected const HOST = 'https://graph.instagram.com';

    protected function refreshUrl(): string
    {
        return static::HOST.'/refresh_access_token';
    }

    protected function exchangeUrl(): string
    {
        return static::HOST.'/access_token';
    }

    protected function meUrl(): string
    {
        return static::HOST.'/'.$this->graphVersion().'/me';
    }

    protected function refreshGrant(): string
    {
        return 'ig_refresh_token';
    }

    protected function exchangeGrant(): string
    {
        return 'ig_exchange_token';
    }

    protected function label(): string
    {
        return 'Instagram';
    }

    /** The Graph API version of the connector's block (`graph_version`), or the Facebook connectors' default. */
    public function graphVersion(): string
    {
        $version = $this->config['graph_version'] ?? null;

        return is_string($version) && $version !== '' ? $version : MetaGraphConnector::DEFAULT_GRAPH_VERSION;
    }
}
