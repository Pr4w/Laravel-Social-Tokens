<?php

namespace Pr4w\SocialTokens\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Pr4w\SocialTokens\Contracts\ChecksCredential;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Enums\RenewalOutcome;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\Support\ConnectorRegistry;

/**
 * Static credentials (e.g. Facebook page tokens) never expire and are never
 * renewed, so a token the provider invalidated (password change, app removed,
 * admin role lost) would otherwise only be discovered by a failed post. This
 * asks the provider about each one and flags the dead ones.
 */
class CheckStaticCredentials extends Command
{
    protected $signature = 'social-tokens:check-static';

    protected $description = 'Flag static credentials (e.g. Facebook page tokens) the provider no longer accepts.';

    public function handle(ConnectorRegistry $registry): int
    {
        $checked = 0;
        $flagged = 0;

        SocialToken::query()
            ->where('status', AccountStatus::Active->value)
            ->whereNull('expires_at')
            ->whereNull('renew_at')
            ->has('accounts')
            ->lazyById()
            ->each(function (SocialToken $token) use ($registry, &$checked, &$flagged) {
                if (! $registry->has($token->provider)) {
                    return;
                }

                $connector = $registry->for($token->provider);

                if (! $connector instanceof ChecksCredential) {
                    return;
                }

                $checked++;
                $result = $connector->checkCredential($token);

                if ($result->outcome === RenewalOutcome::Terminal) {
                    $token->markNeedsReconnect($result->reason);
                    $flagged++;
                } elseif ($result->unknown && config('social-tokens.log_unknown_errors', true)) {
                    Log::error('[social-tokens] Uncatalogued credential check error', [
                        'provider' => $token->provider,
                        'token_id' => $token->getKey(),
                        'reason' => $result->reason,
                        'context' => $result->context,
                    ]);
                }
            });

        $this->info("Checked {$checked} static credential(s), flagged {$flagged}.");

        return self::SUCCESS;
    }
}
