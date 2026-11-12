<?php

declare(strict_types=1);

namespace Modules\Finance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Finance\Models\BankReconciliation;
use Modules\Finance\Models\BankReconciliationLine;

/**
 * Only what the Bank Reconciliation screens need. No request_id. Lines come
 * from the frozen snapshot, so a posted reconciliation always reads the same.
 *
 * @mixin BankReconciliation
 */
class BankReconciliationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var BankReconciliation $r */
        $r = $this->resource;

        $bank  = $r->relationLoaded('bankAccount') ? $r->bankAccount : null;
        $lines = $r->relationLoaded('lines') ? $r->lines : null;

        return [
            'id'                  => $r->id,
            'recon_no'            => $r->recon_no,
            'company_id'          => $r->company_id,
            'company_name'        => $r->company?->company_name,
            'bank_account_id'     => $r->bank_account_id,
            'bank_account_name'   => $bank === null ? null : $bank->code . ' - ' . $bank->ledger_account_name,
            'bank_name'           => $bank?->bank?->bank_name,
            'bank_branch_name'    => $bank?->bankBranch?->branch_name,
            'bank_account_no'     => $bank?->bank_account_no,
            'date_from'           => $r->date_from?->toDateString(),
            'statement_date'      => $r->statement_date?->toDateString(),
            'opening_balance'     => (string) $r->opening_balance,
            'statement_balance'   => (string) $r->statement_balance,
            'cleared_deposits'    => (string) $r->cleared_deposits,
            'cleared_withdrawals' => (string) $r->cleared_withdrawals,
            'closing_balance'     => (string) $r->closing_balance,
            'difference'          => (string) $r->difference,
            'book_balance'        => $r->book_balance === null ? null : (string) $r->book_balance,
            'notes'               => $r->notes,
            'status'              => $r->status?->value,
            'status_label'        => $r->status?->label(),

            'lines' => $lines === null ? [] : $lines->map(fn (BankReconciliationLine $l): array => [
                'journal_line_id'   => $l->journal_line_id,
                'journal_no'        => $l->journal_no,
                'journal_date'      => $l->journal_date?->toDateString(),
                'direction'         => $l->direction,
                'status'            => $l->status?->value,
                // A cheque an earlier statement cleared, returned on this one.
                'cleared_before'    => $l->status === \Modules\Finance\Enums\ReconLineStatus::ReturnedLater,
                // The day the bank returned it, for Returned lines.
                'cleared_date'      => $l->cleared_date?->toDateString(),
                'amount'            => (string) $l->amount,
                'instrument_type'   => $l->instrument_type,
                'instrument_no'     => $l->instrument_no,
                'instrument_date'   => $l->instrument_date?->toDateString(),
                'description'       => $l->description,
                'return_journal_no' => $l->return_journal_no,
            ])->values()->all(),

            'void_reason'     => $r->void_reason,
            'posted_at'       => $r->posted_at?->toISOString(),
            'voided_at'       => $r->voided_at?->toISOString(),
            'created_by_name' => $r->relationLoaded('creator') ? $r->creator?->name : null,
            'posted_by_name'  => $r->relationLoaded('poster') ? $r->poster?->name : null,
            'created_at'      => $r->created_at?->toISOString(),
            'updated_at'      => $r->updated_at?->toISOString(),
        ];
    }
}
