<?php

namespace Pr4w\SocialTokens\Actions;

use Illuminate\Http\Response;
use Pr4w\SocialTokens\Support\DeletionConfirmation;

/**
 * The page the data deletion answer points the user to, when the app does
 * not serve its own (`callbacks.deletion_status_url`). The deletion is done
 * before the code is issued, so a code the package signed is a completed
 * request; any other code is not found.
 */
class ShowDataDeletionStatus
{
    public function __invoke(string $provider, string $code): Response
    {
        $issuedAt = DeletionConfirmation::issuedAt($provider, $code);

        abort_if($issuedAt === null, 404);

        return new Response(
            "Data deletion request {$code}: completed on {$issuedAt->toDateString()}. The access tokens this app held for you were deleted.",
            200,
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        );
    }
}
