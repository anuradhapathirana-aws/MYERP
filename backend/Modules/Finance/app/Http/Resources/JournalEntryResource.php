<?php

declare(strict_types=1);

namespace Modules\Finance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Finance\Models\JournalLine;

/** @mixin \Modules\Finance\Models\JournalEntry */
class JournalEntryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'journal_no'    => $this->journal_no,
            'journal_date'  => $this->journal_date?->toDateString(),
            'document_date' => $this->document_date?->toDateString(),

            'journal_type'       => $this->journal_type?->value,
            'journal_type_label' => $this->journal_type?->label(),
            'status'             => $this->status?->value,
            'status_label'       => $this->status?->label(),
            'is_editable'        => (bool) $this->status?->isEditable(),

            // Which document produced this, for a link back to it. NULL for a
            // hand-entered voucher and for a reversal.
            'source_type' => $this->source_type,
            'source_id'   => $this->source_id,

            'company_id'    => $this->company_id,
            'company_name'  => $this->whenLoaded('company', fn () => $this->company?->company_name),
            'location_id'   => $this->location_id,
            'location_name' => $this->whenLoaded('location', fn () => $this->location?->location_name),

            'narration' => $this->narration,

            'total_debit'  => (float) $this->total_debit,
            'total_credit' => (float) $this->total_credit,
            'is_balanced'  => $this->isBalanced(),

            // The reversal chain, as numbers rather than ids, because that is
            // what the UI displays and links on.
            'reverses_journal_id'     => $this->reverses_journal_id,
            'reverses_journal_no'     => $this->whenLoaded('reverses', fn () => $this->reverses?->journal_no),
            'reversed_by_journal_id'  => $this->reversed_by_journal_id,
            'reversed_by_journal_no'  => $this->whenLoaded('reversedBy', fn () => $this->reversedBy?->journal_no),

            'posted_at'  => $this->posted_at?->toDateTimeString(),
            'created_at' => $this->created_at?->toDateTimeString(),

            'lines_count' => $this->whenCounted('lines'),
            'lines'       => JournalLineResource::collection($this->whenLoaded('lines')),

            // A two-line voucher reads far better as From -> To than as Dr/Cr,
            // which is how Tally shows a Contra. Computed here rather than in
            // the frontend so the rule lives in one place; with three or more
            // lines there is no meaningful pairing and this stays null.
            'transfer' => $this->when(
                $this->relationLoaded('lines') && $this->lines->count() === 2,
                fn (): ?array => $this->transferView(),
            ),
        ];
    }

    /**
     * From -> To for a two-line journal.
     *
     * Debit is the destination (where the money arrived) and credit the source
     * (where it left). Returns null if both lines somehow sit on the same side,
     * which would not be a transfer.
     */
    private function transferView(): ?array
    {
        $debit  = $this->lines->first(fn (JournalLine $l): bool => (float) $l->debit > 0);
        $credit = $this->lines->first(fn (JournalLine $l): bool => (float) $l->credit > 0);

        if ($debit === null || $credit === null) {
            return null;
        }

        return [
            'from_account_id'   => $credit->ledger_account_id,
            'from_account_code' => $credit->ledgerAccount?->code,
            'from_account_name' => $credit->ledgerAccount?->ledger_account_name,
            'to_account_id'     => $debit->ledger_account_id,
            'to_account_code'   => $debit->ledgerAccount?->code,
            'to_account_name'   => $debit->ledgerAccount?->ledger_account_name,
            'amount'            => (float) $debit->debit,
        ];
    }
}
