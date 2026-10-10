<?php

namespace Pr4w\SocialTokens\Tests\Fixtures;

use Pr4w\SocialTokens\Connectors\AbstractConnector;
use Pr4w\SocialTokens\Connectors\MetaGraphConnector;
use Pr4w\SocialTokens\Enums\AccountStatus;
use Pr4w\SocialTokens\Models\SocialAccount;
use Pr4w\SocialTokens\Models\SocialToken;
use Pr4w\SocialTokens\SocialTokens;
use RuntimeException;

/**
 * UPGRADE.md, for the tests that check its steps. run() executes a PHP block
 * as the guide prints it: copies of the blocks in the tests kept passing
 * while the guide's own blocks skipped rows past the first thousand.
 */
final class UpgradeGuide
{
    /** A block may rely on the imports of an earlier block of its section: run() gives each all of them. */
    private const IMPORTS = [
        AbstractConnector::class,
        AccountStatus::class,
        MetaGraphConnector::class,
        SocialAccount::class,
        SocialToken::class,
        SocialTokens::class,
    ];

    public static function text(): string
    {
        return (string) file_get_contents(__DIR__.'/../../UPGRADE.md');
    }

    /** The text between $heading and the next heading of the same level or above. */
    public static function section(string $heading): string
    {
        $guide = self::text();
        $start = strpos($guide, "\n{$heading}\n");

        if ($start === false) {
            throw new RuntimeException("UPGRADE.md has no [{$heading}] section.");
        }

        $level = strlen((string) strstr($heading, ' ', true));
        preg_match('/\n#{1,'.$level.'} /', $guide, $next, PREG_OFFSET_CAPTURE, $start + strlen($heading) + 2);

        return substr($guide, $start, ($next[0][1] ?? strlen($guide)) - $start);
    }

    /** The one PHP block of $heading's section that contains $marker. */
    public static function block(string $heading, string $marker): string
    {
        preg_match_all('/```php\n(.*?)```/s', self::section($heading), $blocks);
        $matching = array_values(array_filter($blocks[1], fn (string $block) => str_contains($block, $marker)));

        if (count($matching) !== 1) {
            throw new RuntimeException(count($matching)." PHP blocks of [{$heading}] contain [{$marker}], 1 expected.");
        }

        return $matching[0];
    }

    /** Run the one PHP block of $heading's section that contains $marker. */
    public static function run(string $heading, string $marker): void
    {
        $code = (string) preg_replace('/^\s*use [\w\\\\]+;$/m', '', self::block($heading, $marker));
        $imports = implode('', array_map(fn (string $class) => "use {$class};\n", self::IMPORTS));

        eval($imports.$code);
    }
}
