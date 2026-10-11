<?php

declare(strict_types=1);

namespace Modules\Finance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Finance\Models\LedgerAccount;

/** @mixin \Modules\Finance\Models\JournalLine */
class JournalLineResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        // The account code and name are flattened in rather than nested: the
        // voucher view shows "20201001 - Accounts Payable" on every row and
        // never needs the account object itself.
        $account = $this->whenLoaded('ledgerAccount');
        $hasAcct = $account instanceof LedgerAccount;

        return [
            'id'      => $this->id,
            'line_no' => $this->line_no,

            'ledger_account_id'   => $this->ledger_account_id,
            'ledger_account_code' => $hasAcct ? $account->code : null,
            'ledger_account_name' => $hasAcct ? $account->ledger_account_name : null,

            'debit'       => (float) $this->debit,
            'credit'      => (float) $this->credit,
            'description' => $this->description,

            // The subledger tags. Present so a voucher view can show which
            // supplier or customer a payables line belongs to.
            'party_type' => $this->party_type,
            'party_id'   => $this->party_id,

            // The document this line clears, which is what lets a report age
            // per GRN rather than only per supplier.
            'source_type' => $this->source_type,
            'source_id'   => $this->source_id,

            'company_id'  => $this->company_id,
            'location_id' => $this->location_id,
        ];
    }
}
