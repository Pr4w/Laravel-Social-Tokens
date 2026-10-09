<?php

namespace Pr4w\SocialTokens\Actions\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Pr4w\SocialTokens\Contracts\VerifiesSignedRequests;
use Pr4w\SocialTokens\Exceptions\InvalidSignedRequestException;
use Pr4w\SocialTokens\Support\ConnectorRegistry;

/**
 * What the Meta callback actions share: the provider's connector verifies the
 * signed_request, and only its `user_id` is acted on. Anyone can post to the
 * callback URLs, so nothing is read from a request that does not verify.
 *
 * @property ConnectorRegistry $registry
 */
trait VerifiesCallbacks
{
    /** Whether $provider has a connector that can verify its callbacks. */
    public function accepts(string $provider): bool
    {
        return $this->registry->has($provider) && $this->registry->for($provider) instanceof VerifiesSignedRequests;
    }

    /**
     * The external user a verified signed_request is about.
     *
     * @throws InvalidArgumentException for a provider accepts() refuses
     * @throws InvalidSignedRequestException
     */
    protected function verifiedUserId(string $provider, string $signedRequest): string
    {
        $connector = $this->accepts($provider) ? $this->registry->for($provider) : null;

        if (! $connector instanceof VerifiesSignedRequests) {
            throw new InvalidArgumentException("No connector for provider [{$provider}] verifies signed requests.");
        }

        $userId = $connector->parseSignedRequest($signedRequest)['user_id'] ?? null;

        if (! is_scalar($userId) || (string) $userId === '') {
            throw new InvalidSignedRequestException('The signed_request names no user.');
        }

        return (string) $userId;
    }

    /**
     * The HTTP side: 404 for a provider that cannot be verified, 400 for a
     * missing or invalid signed_request (logged, without its content), else
     * $handle's answer as JSON.
     *
     * @param  callable(string): array<string, mixed>  $handle  Given the signed_request.
     */
    protected function respond(Request $request, string $provider, callable $handle): JsonResponse
    {
        if (! $this->accepts($provider)) {
            return new JsonResponse(['error' => 'Unknown provider.'], 404);
        }

        $signedRequest = $request->input('signed_request');

        if (! is_string($signedRequest) || $signedRequest === '') {
            return new JsonResponse(['error' => 'Missing signed_request.'], 400);
        }

        try {
            return new JsonResponse($handle($signedRequest));
        } catch (InvalidSignedRequestException $e) {
            Log::warning('[social-tokens] Refused a callback whose signed_request does not verify', [
                'provider' => $provider,
                'reason' => $e->getMessage(),
            ]);

            return new JsonResponse(['error' => 'Invalid signed_request.'], 400);
        }
    }
}
