<?php

declare(strict_types=1);

namespace Modules\Finance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Finance\Models\Bill;
use Modules\Finance\Models\BillPayment;

/**
 * Only what the Bill Payment screens need. No request_id, no file paths, no
 * bank account numbers.
 *
 * @mixin BillPayment
 */
class BillPaymentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var BillPayment $p */
        $p = $this->resource;

        $account = $p->relationLoaded('payFromAccount') ? $p->payFromAccount : null;

        return [
            'id'                    => $p->id,
            'payment_no'            => $p->payment_no,
            'supplier_id'           => $p->supplier_id,
            'supplier_name'         => $p->supplier?->supplier_name,
            'supplier_code'         => $p->supplier?->supplier_code,
            'company_id'            => $p->company_id,
            'company_name'          => $p->company?->company_name,
            'payment_date'          => $p->payment_date?->toDateString(),
            'reference_no'          => $p->reference_no,
            'pay_from_account_id'   => $p->pay_from_account_id,
            'pay_from_account_name' => $account === null ? null : $account->code . ' - ' . $account->ledger_account_name,
            'pay_from_type'         => $account?->cash_book_type?->value,
            'payment_mode_id'       => $p->payment_mode_id,
            'payment_mode_name'     => $p->payment_mode_name,
            'cheque_no'             => $p->cheque_no,
            'cheque_date'           => $p->cheque_date?->toDateString(),
            'description'           => $p->description,
            'currency'              => $p->currency,
            'total_amount'          => (string) $p->total_amount,
            'bill_count'            => $p->relationLoaded('allocations') ? $p->allocations->count() : null,
            'status'                => $p->status?->value,
            'status_label'          => $p->status?->label(),

            'allocations' => $p->relationLoaded('allocations')
                ? $p->allocations->map(fn ($a): array => [
                    'bill_id'          => $a->bill_id,
                    'bill_no'          => $a->bill?->bill_no,
                    'document_no'      => $a->bill?->document_no,
                    'transaction_date' => $a->bill?->transaction_date?->toDateString(),
                    'due_date'         => $a->bill?->due_date?->toDateString(),
                    'total_amount'     => $a->bill === null ? null : (string) $a->bill->total_amount,
                    // What the bill owes now; balance_before is what it owed when this was confirmed.
                    'balance'          => $a->bill?->balance(),
                    'balance_before'   => $a->balance_before === null ? null : (string) $a->balance_before,
                    'amount'           => (string) $a->amount,
                    'bill_payment_status' => $a->bill?->payment_status?->value,
                ])->values()->all()
                : [],

            'attachments' => $p->relationLoaded('attachments')
                ? $p->attachments->map(fn ($a): array => [
                    'id'            => $a->id,
                    'original_name' => $a->original_name,
                    'mime'          => $a->mime,
                    'size'          => $a->size,
                    'created_at'    => $a->created_at?->toISOString(),
                ])->values()->all()
                : [],

            'journal_no'          => $p->journal_no,
            'reversal_journal_no' => $p->reversal_journal_no,
            'void_reason'         => $p->void_reason,
            'reversal_date'       => $p->reversal_date?->toDateString(),
            'confirmed_at'        => $p->confirmed_at?->toISOString(),
            'voided_at'           => $p->voided_at?->toISOString(),
            'created_by_name'     => $p->relationLoaded('creator') ? $p->creator?->name : null,
            'created_at'          => $p->created_at?->toISOString(),
            'updated_at'          => $p->updated_at?->toISOString(),
        ];
    }
}
