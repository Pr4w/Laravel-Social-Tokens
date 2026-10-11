<?php

namespace Pr4w\SocialTokens\Console;

use Illuminate\Console\Command;
use Pr4w\SocialTokens\Actions\MergeLinkedInCredentials;
use Pr4w\SocialTokens\Actions\StoreLinkedInOrganizations;
use Pr4w\SocialTokens\Connectors\LinkedInConnector;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\Support\ConnectorRegistry;

/**
 * Turning single authorization mode on for credentials already stored: each
 * LinkedIn member's separate organizations credential is folded into their
 * default one (MergeLinkedInCredentials), so the profile and the
 * organizations renew one token. The connections do it themselves the next
 * time each member connects; this does it for every member at once.
 */
class MergeLinkedInCredentialsCommand extends Command
{
    protected $signature = 'social-tokens:merge-linkedin-credentials';

    protected $description = 'Single authorization mode: fold each LinkedIn member\'s organizations credential into their default one.';

    public function handle(ConnectorRegistry $registry, MergeLinkedInCredentials $merge): int
    {
        $connector = $registry->has('linkedin') ? $registry->for('linkedin') : null;

        if (! $connector instanceof LinkedInConnector || ! $connector->singleAuthorization()) {
            $this->error('Turn single authorization mode on first (LINKEDIN_SINGLE_AUTHORIZATION=true, connectors.linkedin.single_authorization): '
                .'only then do the profile and the organizations post with one credential. Nothing was changed.');

            return self::INVALID;
        }

        $merged = 0;

        SocialToken::query()
            ->where('provider', $connector->credentialProvider())
            ->forPurpose(StoreLinkedInOrganizations::PURPOSE)
            ->select('id')
            ->chunkById(100, function ($chunk) use ($merge, &$merged) {
                foreach ($chunk as $token) {
                    $merge->handle($token);
                    $merged++;
                }
            });

        $this->info("Merged {$merged} LinkedIn organizations credential(s) into the member's default credential.");

        return self::SUCCESS;
    }
}
