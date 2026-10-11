<?php

declare(strict_types=1);

namespace App\Support\Journal;

use App\Contracts\JournalPoster;

/**
 * What answers when no general ledger is active.
 *
 * Bound by AppServiceProvider as the default. FinanceServiceProvider rebinds
 * the contract to its own implementation, so this is what runs on an
 * Inventory-only installation.
 *
 * Doing nothing here is a correct outcome, not a degraded one: a client who
 * did not buy Finance has no ledger to post to, and their Supplier Payment
 * screen must keep working exactly as it did before Finance existed. That is
 * the whole premise of selling the modules separately, and
 * FinanceDisabledTest pins it.
 *
 * Deliberately silent — no log line. A no-op firing on every confirm is the
 * expected steady state for those clients, and logging it would bury real
 * problems under noise.
 */
final class NullJournalPoster implements JournalPoster
{
    public function acceptsPostings(): bool
    {
        return false;
    }

    public function post(JournalRequest $request): ?string
    {
        return null;
    }

    public function reverseDocument(string $sourceType, int $sourceId, ?string $narration = null): ?string
    {
        return null;
    }

    public function hasPostingFor(string $sourceType, int $sourceId): bool
    {
        return false;
    }
}
