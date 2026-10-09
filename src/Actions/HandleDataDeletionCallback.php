<?php

namespace Pr4w\SocialTokens\Actions;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Pr4w\SocialTokens\Actions\Concerns\VerifiesCallbacks;
use Pr4w\SocialTokens\Events\ExternalUserDataDeleted;
use Pr4w\SocialTokens\Exceptions\InvalidSignedRequestException;
use Pr4w\SocialTokens\SocialTokens;
use Pr4w\SocialTokens\Support\ConnectorRegistry;
use Pr4w\SocialTokens\Support\DeletionConfirmation;
use RuntimeException;
use Throwable;

/**
 * Meta's Data Deletion Request callback (Facebook Login, Instagram Login) and
 * Threads' Delete callback: the user's tokens are deleted under every owner
 * (SocialTokens::purgeExternalUser()), ExternalUserDataDeleted lets the app
 * delete the rest, and Meta gets the {url, confirmation_code} answer it
 * expects, the url being a page where the user can check the request. Once
 * the tokens are deleted, both happen even when an AccountRevoked listener
 * throws; its exception is reported.
 *
 * Routed at POST {prefix}/{provider}/delete when `callbacks.enabled` is on;
 * call handle() from your own route otherwise.
 */
class HandleDataDeletionCallback
{
    use VerifiesCallbacks;

    public function __construct(protected ConnectorRegistry $registry, protected SocialTokens $tokens) {}

    /**
     * @return array{url: string, confirmation_code: string}
     *
     * @throws InvalidArgumentException for a provider whose connector cannot verify the request
     * @throws InvalidSignedRequestException
     * @throws RuntimeException when there is no status page to point to (nothing is deleted then)
     */
    public function handle(string $provider, string $signedRequest): array
    {
        $userId = $this->verifiedUserId($provider, $signedRequest);

        // Before deleting anything: a missing status page must not leave
        // Meta without an answer for data already gone.
        $code = DeletionConfirmation::issue($provider);
        $url = DeletionConfirmation::statusUrl($provider, $code);

        $deleted = null;

        try {
            $accounts = $this->tokens->purgeExternalUser($provider, $userId, function (Collection $accounts) use (&$deleted) {
                $deleted = $accounts;
            });
        } catch (Throwable $e) {
            // Unless the deletion itself failed, the tokens are gone: the app
            // must still get the accounts to delete, and Meta its answer. A
            // failing AccountRevoked listener (a notification's mail
            // transport) is reported instead.
            if (! $deleted instanceof Collection) {
                throw $e;
            }

            report($e);
            $accounts = $deleted;
        }

        event(new ExternalUserDataDeleted($provider, $userId, $code, $accounts->modelKeys()));

        return ['url' => $url, 'confirmation_code' => $code];
    }

    public function __invoke(Request $request, string $provider): JsonResponse
    {
        return $this->respond($request, $provider, fn (string $signedRequest) => $this->handle($provider, $signedRequest));
    }
}
