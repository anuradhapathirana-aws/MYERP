<?php

declare(strict_types=1);

namespace Modules\Finance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Finance\Models\Bill;

/**
 * Only what the Bills screens need. No supplier bank details, no file paths,
 * no request_id.
 *
 * @mixin Bill
 */
class BillResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Bill $bill */
        $bill = $this->resource;

        $firstLine = $bill->relationLoaded('lines') ? $bill->lines->first() : null;

        return [
            'id'               => $bill->id,
            'bill_no'          => $bill->bill_no,
            'supplier_id'      => $bill->supplier_id,
            'supplier_name'    => $bill->supplier?->supplier_name,
            'supplier_code'    => $bill->supplier?->supplier_code,
            'transaction_date' => $bill->transaction_date?->toDateString(),
            'document_no'      => $bill->document_no,
            'document_date'    => $bill->document_date?->toDateString(),
            'due_date'         => $bill->due_date?->toDateString(),
            'company_id'       => $bill->company_id,
            'company_name'     => $bill->company?->company_name,
            'location_id'      => $bill->location_id,
            'location_name'    => $bill->location?->location_name,
            'description'      => $bill->description,
            'currency'         => $bill->currency,
            'total_amount'     => (string) $bill->total_amount,
            'paid_amount'      => (string) $bill->paid_amount,
            'balance'          => $bill->balance(),
            'status'           => $bill->status?->value,
            'status_label'     => $bill->status?->label(),
            'payment_status'   => $bill->payment_status?->value,
            'payment_status_label' => $bill->payment_status?->label(),
            'is_overdue'       => $bill->isOverdue(),

            // The single-line screen reads these; the lines array is the full truth.
            'ledger_account_id'   => $firstLine?->ledger_account_id,
            'ledger_account_name' => $firstLine?->ledgerAccount === null
                ? null
                : $firstLine->ledgerAccount->code . ' - ' . $firstLine->ledgerAccount->ledger_account_name,

            'lines' => $bill->relationLoaded('lines')
                ? $bill->lines->map(fn ($l): array => [
                    'line_no'             => $l->line_no,
                    'ledger_account_id'   => $l->ledger_account_id,
                    'ledger_account_code' => $l->ledgerAccount?->code,
                    'ledger_account_name' => $l->ledgerAccount?->ledger_account_name,
                    'description'         => $l->description,
                    'amount'              => (string) $l->amount,
                ])->values()->all()
                : [],

            'attachments' => $bill->relationLoaded('attachments')
                ? $bill->attachments->map(fn ($a): array => [
                    'id'            => $a->id,
                    'original_name' => $a->original_name,
                    'mime'          => $a->mime,
                    'size'          => $a->size,
                    'created_at'    => $a->created_at?->toISOString(),
                ])->values()->all()
                : [],

            // Payments made against this bill (drafts included, flagged by status).
            'payments' => $bill->relationLoaded('paymentAllocations')
                ? $bill->paymentAllocations
                    ->filter(fn ($a): bool => $a->payment !== null)
                    ->map(fn ($a): array => [
                        'bill_payment_id' => $a->bill_payment_id,
                        'payment_no'      => $a->payment->payment_no,
                        'payment_date'    => $a->payment->payment_date?->toDateString(),
                        'method'          => $a->payment->payment_mode_name,
                        'status'          => $a->payment->status?->value,
                        'amount'          => (string) $a->amount,
                    ])->values()->all()
                : [],

            'journal_no'          => $bill->journal_no,
            'reversal_journal_no' => $bill->reversal_journal_no,
            'void_reason'         => $bill->void_reason,
            'reversal_date'       => $bill->reversal_date?->toDateString(),
            'confirmed_at'        => $bill->confirmed_at?->toISOString(),
            'voided_at'           => $bill->voided_at?->toISOString(),
            'created_by_name'     => $bill->relationLoaded('creator') ? $bill->creator?->name : null,
            'created_at'          => $bill->created_at?->toISOString(),
            // Sent back on save for the optimistic lock.
            'updated_at'          => $bill->updated_at?->toISOString(),
        ];
    }
}
