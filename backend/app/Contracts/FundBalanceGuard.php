<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * "Is there enough money in the account this payment leaves?"
 *
 * Same seam as JournalPoster: Inventory's Supplier Payment has to ask the
 * question, only Finance knows account balances, and neither module may
 * reference the other. Inventory type-hints this interface; the container
 * hands back Finance's FundBalanceService when Finance is installed, or
 * NullFundBalanceGuard (which never objects) when it is not.
 *
 * The rule (client decision):
 *   Cash Book / Petty Cash  -> blocked. Cash that is not in the box cannot be
 *                              handed over.
 *   Bank                    -> warned. The ERP's bank balance often lags the
 *                              real bank (an unrecorded receipt, an overdraft
 *                              facility), so the user may go ahead once they
 *                              have seen the warning and said yes.
 *
 * Called from inside the caller's transaction, before the journal is posted.
 */
interface FundBalanceGuard
{
    /**
     * @param  array<int, float|string>  $outflows  ledger_account_id => amount leaving it
     * @param  string                    $date      the document date (Y-m-d)
     * @param  bool                      $bankOverdrawConfirmed  the user already said yes to the bank warning
     *
     * @throws \Illuminate\Validation\ValidationException
     *         'pay_from'      when a cash book would go below zero;
     *         'bank_overdraw' when a bank would, and the user has not confirmed.
     */
    public function assertCovered(array $outflows, string $date, bool $bankOverdrawConfirmed = false): void;
}
