<?php

declare(strict_types=1);

namespace Modules\Finance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Finance\Models\BankDeposit;
use Modules\Finance\Models\BankDepositLine;

/**
 * Only what the Bank Deposit screens need. No request_id and no file paths.
 * The bank account number is shown: it is printed on every paying-in slip.
 *
 * @mixin BankDeposit
 */
class BankDepositResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var BankDeposit $d */
        $d = $this->resource;

        $bank  = $d->relationLoaded('bankAccount') ? $d->bankAccount : null;
        $lines = $d->relationLoaded('lines') ? $d->lines : null;
        $cash  = $lines?->firstWhere('line_type', BankDepositLine::TYPE_CASH);

        return [
            'id'                => $d->id,
            'deposit_no'        => $d->deposit_no,
            'company_id'        => $d->company_id,
            'company_name'      => $d->company?->company_name,
            'deposit_date'      => $d->deposit_date?->toDateString(),
            'bank_account_id'   => $d->bank_account_id,
            'bank_account_name' => $bank === null ? null : $bank->code . ' - ' . $bank->ledger_account_name,
            'bank_name'         => $bank?->bank?->bank_name,
            'bank_branch_name'  => $bank?->bankBranch?->branch_name,
            'bank_account_no'   => $bank?->bank_account_no,
            'slip_no'           => $d->slip_no,
            'description'       => $d->description,
            'currency'          => $d->currency,
            'cash_total'        => (string) $d->cash_total,
            'cheque_total'      => (string) $d->cheque_total,
            'total_amount'      => (string) $d->total_amount,
            'cheque_count'      => $d->cheque_count ?? $lines?->where('line_type', BankDepositLine::TYPE_CHEQUE)->count(),
            'status'            => $d->status?->value,
            'status_label'      => $d->status?->label(),

            'cash_account_id'   => $cash?->from_account_id,
            'cash_account_name' => $cash?->fromAccount === null ? null : $cash->fromAccount->code . ' - ' . $cash->fromAccount->ledger_account_name,

            'cheques' => $lines === null ? [] : $lines
                ->where('line_type', BankDepositLine::TYPE_CHEQUE)
                ->map(fn (BankDepositLine $l): array => [
                    'settlement_id'     => $l->source_id,
                    'ledger_account_id' => $l->from_account_id,
                    'account'           => $l->fromAccount === null ? null : $l->fromAccount->code . ' - ' . $l->fromAccount->ledger_account_name,
                    'receipt_no'        => $l->receipt_no,
                    'receipt_date'      => $l->receipt_date?->toDateString(),
                    'customer_name'     => $l->customer_name,
                    'cheque_no'         => $l->cheque_no,
                    'cheque_date'       => $l->cheque_date?->toDateString(),
                    'drawee_bank'       => $l->drawee_bank,
                    'amount'            => (string) $l->amount,
                ])->values()->all(),

            'attachments' => $d->relationLoaded('attachments')
                ? $d->attachments->map(fn ($a): array => [
                    'id'            => $a->id,
                    'original_name' => $a->original_name,
                    'mime'          => $a->mime,
                    'size'          => $a->size,
                    'created_at'    => $a->created_at?->toISOString(),
                ])->values()->all()
                : [],

            'journal_no'          => $d->journal_no,
            'reversal_journal_no' => $d->reversal_journal_no,
            'void_reason'         => $d->void_reason,
            'reversal_date'       => $d->reversal_date?->toDateString(),
            'confirmed_at'        => $d->confirmed_at?->toISOString(),
            'voided_at'           => $d->voided_at?->toISOString(),
            'created_by_name'     => $d->relationLoaded('creator') ? $d->creator?->name : null,
            'created_at'          => $d->created_at?->toISOString(),
            'updated_at'          => $d->updated_at?->toISOString(),
        ];
    }
}
