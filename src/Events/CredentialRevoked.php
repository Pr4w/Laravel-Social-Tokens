<?php

namespace Pr4w\SocialTokens\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Pr4w\SocialTokens\Models\SocialToken;

class CredentialRevoked
{
    use Dispatchable;

    public function __construct(public readonly SocialToken $token) {}
}
