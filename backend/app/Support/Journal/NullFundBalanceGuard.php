<?php

declare(strict_types=1);

namespace App\Support\Journal;

use App\Contracts\FundBalanceGuard;

/**
 * What answers when no general ledger is active.
 *
 * Without Finance there are no account balances to check, so a payment
 * confirms exactly as it did before Finance existed. Same reasoning as
 * NullJournalPoster.
 */
final class NullFundBalanceGuard implements FundBalanceGuard
{
    public function assertCovered(array $outflows, string $date, bool $bankOverdrawConfirmed = false): void
    {
    }
}
