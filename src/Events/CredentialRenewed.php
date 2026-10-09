<?php

namespace Pr4w\SocialTokens\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Pr4w\SocialTokens\Models\SocialToken;

class CredentialRenewed
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly SocialToken $token) {}
}
