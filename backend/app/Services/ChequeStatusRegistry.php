<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PaymentModeType;
use App\Models\PaymentMode;
use App\Support\Journal\PostingRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where each cheque of a receipt or payment stands, worked out from the
 * records that already exist -- nothing is stored, so it can never disagree
 * with the books:
 *
 *   Received (customer receipt)       Issued (supplier payment)
 *     In Hand    -> Cheques in Hand     Issued     -> not yet cleared
 *     Deposited  -> on a confirmed deposit, or received straight into a bank
 *     Realized   -> cleared by a posted Bank Reconciliation
 *     Returned   -> the bank returned it (inv_cheque_returns)
 *
 * The cheque is Inventory's (a settlement line); deposits and reconciliations
 * are Finance's. Neither module may reference the other, so this lives in
 * core and reads each side behind hasTable, like ReceivedChequeRegistry. With
 * Finance absent a cheque is only ever In Hand / Issued or Returned.
 *
 * One call per screen: a handful of indexed queries for all its cheques.
 */
class ChequeStatusRegistry
{
    public const IN_HAND   = 'in_hand';
    public const ISSUED    = 'issued';
    public const DEPOSITED = 'deposited';
    public const REALIZED  = 'realized';
    public const RETURNED  = 'returned';

    private const LABELS = [
        self::IN_HAND   => 'In Hand',
        self::ISSUED    => 'Issued',
        self::DEPOSITED => 'Deposited',
        self::REALIZED  => 'Realized',
        self::RETURNED  => 'Returned',
    ];

    /** @var array<string, bool> */
    private array $tables = [];

    /**
     * Status of each cheque line; lines that are not cheques are left out.
     *
     * @param  string    $sourceType PostingRole::SOURCE_CUSTOMER_RECEIPT_SETTLEMENT or ..._SUPPLIER_PAYMENT_SETTLEMENT
     * @param  list<int> $ids        settlement ids
     * @return array<int, array{status:string, label:string, reference:?string, date:?string}>
     */
    public function forSettlements(string $sourceType, array $ids): array
    {
        $received = $sourceType === PostingRole::SOURCE_CUSTOMER_RECEIPT_SETTLEMENT;
        $table    = $received ? 'inv_customer_receipt_settlements' : 'inv_supplier_payment_settlements';

        if ($ids === [] || ! $this->hasTable($table)) {
            return [];
        }

        $rows  = DB::table($table)->whereIn('id', $ids)->get(['id', 'payment_mode_id', 'payment_mode_code', 'ledger_account_id']);
        $types = PaymentMode::typesById($rows->pluck('payment_mode_id')->filter()->map(fn ($id): int => (int) $id)->all());

        $cheques = $rows->filter(fn (object $r): bool => ($types[(int) $r->payment_mode_id] ?? PaymentModeType::fromLegacyCode($r->payment_mode_code)) === PaymentModeType::Cheque);

        if ($cheques->isEmpty()) {
            return [];
        }

        $chequeIds = $cheques->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $returned  = $this->returned($sourceType, $chequeIds);
        $realized  = $this->realized($sourceType, $chequeIds);
        $deposited = $received ? $this->deposited($chequeIds) : [];
        $banks     = $received ? $this->bankAccounts($cheques->pluck('ledger_account_id')->filter()->unique()->values()->all()) : [];

        $result = [];

        foreach ($cheques as $row) {
            $id = (int) $row->id;

            $result[$id] = match (true) {
                isset($returned[$id])  => $this->status(self::RETURNED, ...$returned[$id]),
                isset($realized[$id])  => $this->status(self::REALIZED, ...$realized[$id]),
                isset($deposited[$id]) => $this->status(self::DEPOSITED, ...$deposited[$id]),
                // Received straight into a bank account: banked on the receipt date.
                $received && isset($banks[(int) $row->ledger_account_id]) => $this->status(self::DEPOSITED, 'Direct to bank', null),
                default => $this->status($received ? self::IN_HAND : self::ISSUED, null, null),
            };
        }

        return $result;
    }

    /**
     * The document and party behind each settlement line, for reports that
     * start from the bank side (a ledger line naming the settlement).
     *
     * @param  string    $sourceType PostingRole::SOURCE_CUSTOMER_RECEIPT_SETTLEMENT or ..._SUPPLIER_PAYMENT_SETTLEMENT
     * @param  list<int> $ids        settlement ids
     * @return array<int, array{document_no:?string, document_date:?string, party_name:?string, drawee_bank:?string}>
     */
    public function detailsFor(string $sourceType, array $ids): array
    {
        $received = match ($sourceType) {
            PostingRole::SOURCE_CUSTOMER_RECEIPT_SETTLEMENT => true,
            PostingRole::SOURCE_SUPPLIER_PAYMENT_SETTLEMENT => false,
            default                                          => null,
        };

        if ($received === null || $ids === []) {
            return [];
        }

        [$settlements, $documents, $docFk, $docNo, $docDate, $parties, $partyFk, $partyName] = $received
            ? ['inv_customer_receipt_settlements', 'inv_customer_receipts', 'receipt_id', 'receipt_no', 'receipt_date', 'inv_customer_masters', 'customer_id', 'customer_name']
            : ['inv_supplier_payment_settlements', 'inv_supplier_payments', 'payment_id', 'payment_no', 'payment_date', 'inv_supplier_masters', 'supplier_id', 'supplier_name'];

        if (! $this->hasTable($settlements) || ! $this->hasTable($documents)) {
            return [];
        }

        $query = DB::table($settlements . ' as s')
            ->join($documents . ' as d', 'd.id', '=', 's.' . $docFk)
            ->whereIn('s.id', $ids)
            ->select(['s.id', 's.bank_name', 'd.' . $docNo . ' as document_no', 'd.' . $docDate . ' as document_date']);

        if ($this->hasTable($parties)) {
            $query->leftJoin($parties . ' as p', 'p.id', '=', 'd.' . $partyFk)->addSelect('p.' . $partyName . ' as party_name');
        }

        return $query->get()
            ->mapWithKeys(fn (object $r): array => [(int) $r->id => [
                'document_no'   => $r->document_no,
                'document_date' => $r->document_date === null ? null : substr((string) $r->document_date, 0, 10),
                'party_name'    => $r->party_name ?? null,
                'drawee_bank'   => $r->bank_name,
            ]])
            ->all();
    }

    /** @return array{status:string, label:string, reference:?string, date:?string} */
    private function status(string $status, ?string $reference, ?string $date): array
    {
        return [
            'status'    => $status,
            'label'     => self::LABELS[$status],
            'reference' => $reference,
            'date'      => $date === null ? null : substr($date, 0, 10),
        ];
    }

    /** @return array<int, array{0:?string, 1:?string}> */
    private function returned(string $sourceType, array $ids): array
    {
        if (! $this->hasTable('inv_cheque_returns')) {
            return [];
        }

        return DB::table('inv_cheque_returns')
            ->where('settlement_type', $sourceType)
            ->whereIn('settlement_id', $ids)
            ->get(['settlement_id', 'reference', 'return_date'])
            ->mapWithKeys(fn (object $r): array => [(int) $r->settlement_id => [$r->reference, (string) $r->return_date]])
            ->all();
    }

    /** The bank line naming the cheque, cleared as Realized by a posted reconciliation. */
    private function realized(string $sourceType, array $ids): array
    {
        if (! $this->hasTable('fin_bank_reconciliation_lines')) {
            return [];
        }

        return DB::table('fin_journal_lines as l')
            ->join('fin_bank_reconciliation_lines as rl', 'rl.journal_line_id', '=', 'l.id')
            ->join('fin_bank_reconciliations as r', 'r.id', '=', 'rl.bank_reconciliation_id')
            ->where('l.source_type', $sourceType)
            ->whereIn('l.source_id', $ids)
            ->where('rl.status', 'realized')
            ->where('r.status', 'posted')
            ->whereNull('r.deleted_at')
            ->get(['l.source_id', 'r.recon_no', 'rl.cleared_date'])
            ->mapWithKeys(fn (object $r): array => [(int) $r->source_id => [$r->recon_no, $r->cleared_date === null ? null : (string) $r->cleared_date]])
            ->all();
    }

    /** On a confirmed bank deposit. */
    private function deposited(array $ids): array
    {
        if (! $this->hasTable('fin_bank_deposit_lines')) {
            return [];
        }

        return DB::table('fin_bank_deposit_lines as l')
            ->join('fin_bank_deposits as d', 'd.id', '=', 'l.bank_deposit_id')
            ->where('l.source_type', ReceivedChequeRegistry::SOURCE_CUSTOMER_RECEIPT)
            ->whereIn('l.source_id', $ids)
            ->where('d.status', 'confirmed')
            ->whereNull('d.deleted_at')
            ->get(['l.source_id', 'd.deposit_no', 'd.deposit_date'])
            ->mapWithKeys(fn (object $r): array => [(int) $r->source_id => [$r->deposit_no, (string) $r->deposit_date]])
            ->all();
    }

    /**
     * @param  list<int> $accountIds
     * @return array<int, true> the ones that are bank accounts
     */
    private function bankAccounts(array $accountIds): array
    {
        if ($accountIds === [] || ! $this->hasTable('fin_ledger_accounts')) {
            return [];
        }

        return DB::table('fin_ledger_accounts')
            ->whereIn('id', $accountIds)
            ->where('cash_book_type', 'bank')
            ->pluck('id')
            ->mapWithKeys(fn ($id): array => [(int) $id => true])
            ->all();
    }

    private function hasTable(string $table): bool
    {
        return $this->tables[$table] ??= Schema::hasTable($table);
    }
}
