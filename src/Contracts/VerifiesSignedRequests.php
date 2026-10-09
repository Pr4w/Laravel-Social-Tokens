<?php

namespace Pr4w\SocialTokens\Contracts;

use Pr4w\SocialTokens\Exceptions\InvalidSignedRequestException;

/**
 * Optional connector capability: verify a signed_request the provider posts
 * to the app (Meta's Deauthorize and Data Deletion callbacks, Threads'
 * Uninstall and Delete callbacks) with the connector's own app secret. The
 * callback actions accept a provider only when its connector implements it.
 */
interface VerifiesSignedRequests
{
    /**
     * The verified payload; its `user_id` is the external user it is about.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidSignedRequestException
     */
    public function parseSignedRequest(string $signedRequest): array;
}
