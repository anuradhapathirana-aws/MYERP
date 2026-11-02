<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cheques received from customers, and which bank deposit carried them.
 *
 * The cheque is recorded by Inventory (a Customer Receipt settlement line);
 * the deposit by Finance. Neither module may reference the other, so this
 * lives in core and reads each side through a soft link guarded by hasTable,
 * the same way ChequeRegistry does for issued cheques:
 *
 *   Finance asks   chequesInHand()  -- what can go on a deposit slip
 *   Inventory asks depositedOn()    -- may this receipt still be voided
 *
 * A cheque line is one whose payment mode code is 'cheque' -- Inventory's own
 * test (StoreCustomerReceiptRequest) -- with the cheque number in reference_no
 * and its date in instrument_date.
 */
class ReceivedChequeRegistry
{
    /** source_type on fin_bank_deposit_lines for a received cheque. */
    public const SOURCE_CUSTOMER_RECEIPT = 'customer_receipt_settlement';

    /** @var array<string, bool> */
    private array $tables = [];

    /**
     * Cheques sitting in the given accounts: on a confirmed, undeleted
     * receipt and not on any live deposit (a draft deposit holds its cheques
     * too -- one cheque, one slip). Oldest cheque date first.
     *
     * @param  list<int>      $accountIds       the cheque-accepting cash books to look in
     * @param  int|null       $exceptDepositId  a deposit being edited, whose own cheques still count as in hand
     * @param  list<int>|null $onlyIds          restrict to these settlement ids
     * @return list<array<string, mixed>>
     */
    public function chequesInHand(array $accountIds, ?int $exceptDepositId = null, ?array $onlyIds = null): array
    {
        if ($accountIds === [] || $onlyIds === [] || ! $this->hasTable('inv_customer_receipt_settlements')) {
            return [];
        }

        $query = DB::table('inv_customer_receipt_settlements as s')
            ->join('inv_customer_receipts as r', 'r.id', '=', 's.receipt_id')
            ->leftJoin('inv_customer_masters as c', 'c.id', '=', 'r.customer_id')
            ->whereIn('s.ledger_account_id', $accountIds)
            ->where('s.payment_mode_code', 'cheque')
            ->where('s.amount', '>', 0)
            ->where('r.status', 'confirmed')
            ->whereNull('r.deleted_at')
            ->when($onlyIds !== null, fn ($q) => $q->whereIn('s.id', $onlyIds));

        if ($this->hasTable('fin_bank_deposit_lines')) {
            $query->whereNotExists(fn ($q) => $this->liveDepositLines($q)
                ->whereColumn('l.source_id', 's.id')
                ->when($exceptDepositId !== null, fn ($w) => $w->where('d.id', '!=', $exceptDepositId)));
        }

        return $query
            ->orderBy('s.instrument_date')
            ->orderBy('s.id')
            ->limit(1000)
            ->get([
                's.id as settlement_id',
                's.ledger_account_id',
                's.reference_no as cheque_no',
                's.instrument_date as cheque_date',
                's.bank_name as drawee_bank',
                's.amount',
                'r.receipt_no',
                'r.receipt_date',
                'c.customer_name',
            ])
            ->map(fn (object $row): array => [
                'settlement_id'     => (int) $row->settlement_id,
                'ledger_account_id' => (int) $row->ledger_account_id,
                'cheque_no'         => $row->cheque_no,
                'cheque_date'       => $row->cheque_date === null ? null : substr((string) $row->cheque_date, 0, 10),
                'drawee_bank'       => $row->drawee_bank,
                'amount'            => number_format((float) $row->amount, 2, '.', ''),
                'receipt_no'        => $row->receipt_no,
                'receipt_date'      => substr((string) $row->receipt_date, 0, 10),
                'customer_name'     => $row->customer_name,
            ])
            ->values()
            ->all();
    }

    /**
     * The number of a live deposit carrying a cheque from this receipt, or
     * null. A receipt whose cheque is already on a slip cannot be voided: the
     * money has left Cheques in Hand, so the reversal would drive it negative.
     */
    public function depositedOn(int $receiptId): ?string
    {
        if (! $this->hasTable('fin_bank_deposit_lines') || ! $this->hasTable('inv_customer_receipt_settlements')) {
            return null;
        }

        $number = $this->liveDepositLines(DB::query())
            ->join('inv_customer_receipt_settlements as s', 's.id', '=', 'l.source_id')
            ->where('s.receipt_id', $receiptId)
            ->value('d.deposit_no');

        return $number === null ? null : (string) $number;
    }

    /**
     * Cheque value still sitting in each account's ledger balance, keyed by
     * account id: cheques on confirmed receipts that no CONFIRMED deposit has
     * moved out yet. A draft deposit has not posted, so its cheques still count.
     *
     * A Cash Book that takes cheques holds cash and cheques in one balance; this
     * is what has to come off that balance to know how much of it is cash.
     *
     * @param  list<int>  $accountIds
     * @return array<int, float>
     */
    public function undepositedTotals(array $accountIds): array
    {
        if ($accountIds === [] || ! $this->hasTable('inv_customer_receipt_settlements')) {
            return [];
        }

        $query = DB::table('inv_customer_receipt_settlements as s')
            ->join('inv_customer_receipts as r', 'r.id', '=', 's.receipt_id')
            ->whereIn('s.ledger_account_id', $accountIds)
            ->where('s.payment_mode_code', 'cheque')
            ->where('s.amount', '>', 0)
            ->where('r.status', 'confirmed')
            ->whereNull('r.deleted_at');

        if ($this->hasTable('fin_bank_deposit_lines')) {
            $query->whereNotExists(fn ($q) => $q
                ->from('fin_bank_deposit_lines as l')
                ->join('fin_bank_deposits as d', 'd.id', '=', 'l.bank_deposit_id')
                ->where('l.source_type', self::SOURCE_CUSTOMER_RECEIPT)
                ->where('d.status', 'confirmed')
                ->whereNull('d.deleted_at')
                ->whereColumn('l.source_id', 's.id'));
        }

        return $query
            ->groupBy('s.ledger_account_id')
            ->selectRaw('s.ledger_account_id as id, SUM(s.amount) as total')
            ->get()
            ->mapWithKeys(fn ($row): array => [(int) $row->id => (float) $row->total])
            ->all();
    }

    /** Cheque lines on deposits that still hold them: not voided, not deleted. */
    private function liveDepositLines(Builder $query): Builder
    {
        return $query
            ->from('fin_bank_deposit_lines as l')
            ->join('fin_bank_deposits as d', 'd.id', '=', 'l.bank_deposit_id')
            ->where('l.source_type', self::SOURCE_CUSTOMER_RECEIPT)
            ->where('d.status', '!=', 'voided')
            ->whereNull('d.deleted_at');
    }

    private function hasTable(string $table): bool
    {
        return $this->tables[$table] ??= Schema::hasTable($table);
    }
}
