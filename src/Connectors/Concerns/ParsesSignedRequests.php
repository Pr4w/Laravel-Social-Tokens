<?php

namespace Pr4w\SocialTokens\Connectors\Concerns;

use Pr4w\SocialTokens\Support\MetaSignedRequest;

/**
 * VerifiesSignedRequests for a Meta connector: the request is signed with
 * the secret of the app the connector reads (its services entry, or its own
 * client_secret).
 */
trait ParsesSignedRequests
{
    /**
     * @return array<string, mixed>
     */
    public function parseSignedRequest(string $signedRequest): array
    {
        return MetaSignedRequest::parse($signedRequest, (string) $this->clientSecret());
    }
}
