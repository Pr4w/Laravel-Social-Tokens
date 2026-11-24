<?php

namespace Pr4w\SocialTokens\Connectors\Concerns;

use Closure;
use Illuminate\Http\Client\Response;
use Pr4w\SocialTokens\Connectors\MetaErrorMapper;
use Pr4w\SocialTokens\Support\AccountProfile;
use Pr4w\SocialTokens\Support\RenewalResult;

/**
 * A Meta Graph answer to a profile read (FetchesProfile), in Graph's error
 * shape: a permission error (10, 200-299) means the token lacks what the
 * profile needs (null); any other error is a failure, never a verdict on the
 * token.
 */
trait ReadsGraphProfiles
{
    /**
     * @param  Closure(array<string, mixed>): AccountProfile  $map
     */
    protected function graphProfile(Response|RenewalResult $response, Closure $map): AccountProfile|RenewalResult|null
    {
        if ($response instanceof RenewalResult) {
            return $response;
        }

        $body = $response->json();
        $body = is_array($body) ? $body : [];

        if (! empty($body['error'])) {
            $code = is_array($body['error']) ? (int) ($body['error']['code'] ?? 0) : 0;

            return $code === 10 || ($code >= 200 && $code <= 299) ? null : MetaErrorMapper::map($body['error']);
        }

        if (! $response->successful()) {
            return RenewalResult::unknownFailure("Could not read the profile (HTTP {$response->status()}).", ['status' => $response->status()]);
        }

        return $map($body);
    }
}
