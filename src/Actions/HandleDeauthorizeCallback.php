<?php

namespace Pr4w\SocialTokens\Actions;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Pr4w\SocialTokens\Actions\Concerns\VerifiesCallbacks;
use Pr4w\SocialTokens\Exceptions\InvalidSignedRequestException;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\SocialTokens;
use Pr4w\SocialTokens\Support\ConnectorRegistry;

/**
 * Meta's Deauthorize callback (Facebook Login, Instagram Login) and Threads'
 * Uninstall callback: the user removed the app, so every credential and
 * account of theirs, under every owner, is revoked at once
 * (SocialTokens::revokeExternalUser()), instead of being found dead at the
 * next publish or renewal.
 *
 * Routed at POST {prefix}/{provider}/deauthorize when `callbacks.enabled` is
 * on; call handle() from your own route otherwise.
 */
class HandleDeauthorizeCallback
{
    use VerifiesCallbacks;

    public function __construct(protected ConnectorRegistry $registry, protected SocialTokens $tokens) {}

    /**
     * @return Collection<int, SocialAccount> The user's accounts, revoked.
     *
     * @throws InvalidArgumentException for a provider whose connector cannot verify the request
     * @throws InvalidSignedRequestException
     */
    public function handle(string $provider, string $signedRequest): Collection
    {
        return $this->tokens->revokeExternalUser($provider, $this->verifiedUserId($provider, $signedRequest));
    }

    public function __invoke(Request $request, string $provider): JsonResponse
    {
        return $this->respond($request, $provider, function (string $signedRequest) use ($provider) {
            $this->handle($provider, $signedRequest);

            return ['success' => true];
        });
    }
}
