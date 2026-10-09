<?php

namespace Pr4w\SocialTokens\Connectors\Concerns;

use Pr4w\SocialTokens\Support\Settings;

/**
 * Meta's appsecret_proof: an HMAC-SHA256 of the access token, keyed with the
 * app secret. With "Require App Secret" on in the Meta app, every Graph call
 * made with a user or Page token must carry it, so a token that leaks is
 * useless without the secret. Meta accepts a valid proof whether or not the
 * setting is on, so it is sent by default (connector option `appsecret_proof`).
 *
 * The proof only matches tokens issued to the configured app: a token minted
 * by another Meta app is rejected with it.
 */
trait SignsGraphRequests
{
    /**
     * The proof for a Graph call made with $accessToken, or null when signing
     * is turned off or no app secret is configured. Public, so an app signs
     * its own publishing calls the same way.
     */
    public function appSecretProof(#[\SensitiveParameter] string $accessToken): ?string
    {
        $secret = $this->clientSecret();

        if (! Settings::flag($this->config['appsecret_proof'] ?? true, true) || $secret === null || $secret === '') {
            return null;
        }

        return hash_hmac('sha256', $accessToken, $secret);
    }

    /**
     * Query parameters that sign a call made with $accessToken.
     *
     * @return array<string, string>
     */
    protected function appSecretProofQuery(#[\SensitiveParameter] string $accessToken): array
    {
        $proof = $this->appSecretProof($accessToken);

        return $proof === null ? [] : ['appsecret_proof' => $proof];
    }

    /**
     * A complete Graph URL (a paging.next link) signed for $accessToken. Meta
     * echoes the original call's parameters in paging links, but that is not
     * documented: the proof is added when the link lacks one.
     */
    protected function withAppSecretProof(string $url, #[\SensitiveParameter] string $accessToken): string
    {
        $proof = $this->appSecretProof($accessToken);

        if ($proof === null) {
            return $url;
        }

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        if (isset($query['appsecret_proof'])) {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').'appsecret_proof='.$proof;
    }
}
