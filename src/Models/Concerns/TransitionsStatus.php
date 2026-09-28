<?php

namespace Pr4w\SocialTokens\Models\Concerns;

use Pr4w\SocialTokens\Enums\AccountStatus;

/**
 * One-way, idempotent status changes for models with an AccountStatus `status`.
 */
trait TransitionsStatus
{
    /**
     * Compare-and-set in SQL, so concurrent processes holding stale copies
     * cannot both win. Returns whether this call changed the row; either way
     * the in-memory status is brought up to date.
     *
     * @param  array<int, AccountStatus>  $from
     * @param  array<string, mixed>  $attributes
     */
    protected function transitionStatus(AccountStatus $to, array $from, array $attributes = []): bool
    {
        $attributes = ['status' => $to] + $attributes;

        if (! $this->exists) {
            $this->forceFill($attributes)->save();

            return true;
        }

        $changed = $this->newQuery()
            ->whereKey($this->getKey())
            ->whereIn('status', array_map(fn (AccountStatus $status) => $status->value, $from))
            ->update(array_map(fn ($value) => $value instanceof AccountStatus ? $value->value : $value, $attributes)) > 0;

        if ($changed) {
            $this->forceFill($attributes)->syncOriginalAttributes(array_keys($attributes));
        } else {
            $current = $this->newQuery()->whereKey($this->getKey())->value('status'); // cast: an AccountStatus

            if ($current !== null) {
                $this->status = $current;
                $this->syncOriginalAttribute('status');
            }
        }

        return $changed;
    }
}
