<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

use App\Contracts\FundBalanceGuard;
use App\Services\ReceivedChequeRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Finance\Enums\CashBookType;
use Modules\Finance\Enums\JournalStatus;
use Modules\Finance\Models\Bill;
use Modules\Finance\Models\LedgerAccount;

/**
 * How much money a cash or bank account holds, and whether a payment fits.
 *
 * Balance = posted debits minus credits on the account. Two adjustments make
 * it the money actually available:
 *
 *   - Dated: the lower of the balance on the document date and the balance
 *     now. A back-dated payment must not spend money that was paid out again
 *     after that date.
 *   - Cash only: a Cash Book that takes cheques holds cash and received
 *     cheques in one balance. Cheques not yet banked are not cash, so they
 *     come off. Without this, 50,000 of cheques and no notes would let 50,000
 *     of "cash" be paid out, and banking the cheques later would drive the
 *     book negative.
 *
 * All amounts are worked in integer cents, like Bill and BankDeposit.
 */
class FundBalanceService implements FundBalanceGuard
{
    public function __construct(private readonly ReceivedChequeRegistry $cheques) {}

    /** Posted balance in cents (debit minus credit), optionally up to a date. */
    public function balanceCents(int $accountId, ?string $upTo = null): int
    {
        return Bill::toCents(
            $this->postedLines()
                ->where('jl.ledger_account_id', $accountId)
                ->when($upTo !== null, fn ($q) => $q->where('je.journal_date', '<=', $upTo))
                ->value(DB::raw('COALESCE(SUM(jl.debit), 0) - COALESCE(SUM(jl.credit), 0)')),
        );
    }

    /**
     * Current posted balance of many accounts in one query, in cents.
     * Accounts with no lines are absent; read them as zero.
     *
     * @param  list<int>  $accountIds
     * @return array<int, int>
     */
    public function balancesCents(array $accountIds): array
    {
        if ($accountIds === []) {
            return [];
        }

        return $this->postedLines()
            ->whereIn('jl.ledger_account_id', $accountIds)
            ->groupBy('jl.ledger_account_id')
            ->selectRaw('jl.ledger_account_id as id, COALESCE(SUM(jl.debit), 0) - COALESCE(SUM(jl.credit), 0) as balance')
            ->get()
            ->mapWithKeys(fn ($row): array => [(int) $row->id => Bill::toCents($row->balance)])
            ->all();
    }

    /**
     * Money that can leave the account on a date, in cents. Can be negative
     * for a bank that is already overdrawn; a cash book is floored at zero.
     */
    public function availableCents(LedgerAccount $account, string $date): int
    {
        $cents = min($this->balanceCents($account->id, $date), $this->balanceCents($account->id));

        if (! $this->isCash($account)) {
            return $cents;
        }

        return max(0, $cents - $this->undepositedChequeCents([$account])[$account->id]);
    }

    /**
     * What a payment screen shows under Pay From: the money in each account
     * now, cash books net of unbanked cheques. Keyed by account id, as a
     * 2-decimal string.
     *
     * @param  iterable<LedgerAccount>  $accounts
     * @return array<int, string>
     */
    public function availableNow(iterable $accounts): array
    {
        $accounts = collect($accounts)->filter(fn ($a) => $a->cash_book_type !== null)->values();
        $balances = $this->balancesCents($accounts->pluck('id')->all());
        $cheques  = $this->undepositedChequeCents($accounts->filter(fn ($a) => $this->isCash($a))->all());

        return $accounts->mapWithKeys(function (LedgerAccount $a) use ($balances, $cheques): array {
            $cents = $balances[$a->id] ?? 0;

            if ($this->isCash($a)) {
                $cents = max(0, $cents - ($cheques[$a->id] ?? 0));
            }

            return [$a->id => Bill::fromCents($cents)];
        })->all();
    }

    public function assertCovered(array $outflows, string $date, bool $bankOverdrawConfirmed = false): void
    {
        $wanted = [];

        foreach ($outflows as $accountId => $amount) {
            $cents = Bill::toCents($amount);

            if ($cents > 0) {
                $wanted[(int) $accountId] = ($wanted[(int) $accountId] ?? 0) + $cents;
            }
        }

        if ($wanted === []) {
            return;
        }

        // Locked so two payments confirming at once from the same account
        // cannot both pass against the same balance. Ascending id, the lock
        // order Bank Deposit uses too.
        $accounts = LedgerAccount::query()
            ->whereIn('id', array_keys($wanted))
            ->whereNotNull('cash_book_type')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $cashErrors   = [];
        $bankWarnings = [];
        $shownDate    = CarbonImmutable::parse($date)->format('d-m-Y');

        foreach ($accounts as $account) {
            $amount    = $wanted[$account->id];
            $available = $this->availableCents($account, $date);

            if ($amount <= $available) {
                continue;
            }

            if ($this->isBank($account)) {
                $bankWarnings[] = $account->ledger_account_name . ' has ' . self::money($available)
                    . ' on ' . $shownDate . '. Paying ' . self::money($amount)
                    . ' will take it to ' . self::money($available - $amount) . '.';

                continue;
            }

            $cashErrors[] = 'Only ' . self::money($available) . ' is available in '
                . $account->ledger_account_name . ' on ' . $shownDate
                . '. This payment needs ' . self::money($amount) . '.';
        }

        // Cash first: no confirmation can make notes appear, so there is no
        // point asking about the bank while a cash line is impossible anyway.
        if ($cashErrors !== []) {
            throw ValidationException::withMessages(['pay_from' => $cashErrors]);
        }

        if ($bankWarnings !== [] && ! $bankOverdrawConfirmed) {
            throw ValidationException::withMessages(['bank_overdraw' => $bankWarnings]);
        }
    }

    /**
     * @param  iterable<LedgerAccount>  $accounts
     * @return array<int, int> every given account id, zero when none
     */
    private function undepositedChequeCents(iterable $accounts): array
    {
        $ids    = collect($accounts)->filter(fn ($a) => $a->allows_cheque)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $totals = $this->cheques->undepositedTotals($ids);

        return collect($accounts)->mapWithKeys(
            fn ($a): array => [$a->id => Bill::toCents($totals[$a->id] ?? 0)],
        )->all();
    }

    private function postedLines(): \Illuminate\Database\Query\Builder
    {
        return DB::table('fin_journal_lines as jl')
            ->join('fin_journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->whereIn('je.status', [JournalStatus::Posted->value, JournalStatus::Reversed->value])
            ->whereNull('je.deleted_at');
    }

    private function isBank(LedgerAccount $account): bool
    {
        return $account->cash_book_type === CashBookType::Bank;
    }

    private function isCash(LedgerAccount $account): bool
    {
        return in_array($account->cash_book_type, [CashBookType::CashBook, CashBookType::PettyCashBook], true);
    }

    private static function money(int $cents): string
    {
        return number_format($cents / 100, 2);
    }
}
