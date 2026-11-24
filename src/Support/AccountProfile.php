<?php

namespace Pr4w\SocialTokens\Support;

/**
 * An account's profile as its provider shows it now: its display name, its
 * handle and its picture. Read by a connector (Contracts\FetchesProfile) and
 * stored on the account by Actions\SyncAccountProfile.
 *
 * Only the values the provider gave count: a blank one (a LinkedIn
 * organization LinkedIn did not describe, a provider with no handle) means
 * "unknown", never "erase what is stored". A picture is kept only as an
 * http(s) URL.
 */
final class AccountProfile
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $nickname = null,
        public readonly ?string $avatar = null,
    ) {}

    /** From a provider's answer, whatever the type of each value. */
    public static function from(mixed $name = null, mixed $nickname = null, mixed $avatar = null): self
    {
        $text = fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null;

        return new self($text($name), $text($nickname), $text($avatar));
    }

    /**
     * The account attributes it knows.
     *
     * @return array<'name'|'nickname'|'avatar', string>
     */
    public function attributes(): array
    {
        $avatar = $this->avatar !== null && preg_match('#^https?://#i', $this->avatar) === 1 ? $this->avatar : null;

        return array_filter(
            ['name' => $this->name, 'nickname' => $this->nickname, 'avatar' => $avatar],
            fn (?string $value) => $value !== null && trim($value) !== '',
        );
    }
}
