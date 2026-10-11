<?php

declare(strict_types=1);

namespace Modules\Finance\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Finance\Enums\JournalType;

/**
 * A hand-entered voucher.
 *
 * The form blocks saving until the difference reads zero, but the server
 * repeats every rule, because the form is a convenience and this is the
 * boundary. JournalPostingService checks the balance a third time -- that is
 * not redundancy for its own sake: the service is also reached by automatic
 * postings that never pass through here.
 */
class JournalEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'journal_date'  => ['required', 'date'],
            'document_date' => ['nullable', 'date'],

            // Only the three a person may choose. Auto belongs to the posting
            // service and Reversal to the reverse action -- offering either
            // here would let someone hand-write an entry claiming to have come
            // from a document.
            'journal_type' => [
                'required',
                Rule::in(array_column(JournalType::selectableOptions(), 'value')),
            ],

            'location_id' => ['nullable', 'integer', Rule::exists('inv_locations', 'id')],
            'narration'   => ['nullable', 'string', 'max:1000'],

            'lines'                           => ['required', 'array', 'min:2'],
            'lines.*.ledger_account_id'       => [
                'required',
                'integer',
                // Active only, and ledger accounts only. Categories and control
                // accounts are not postable -- a balance against a summary
                // account cannot be rolled up into anything.
                Rule::exists('fin_ledger_accounts', 'id')
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],
            'lines.*.debit'       => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit'      => ['nullable', 'numeric', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.party_type'  => ['nullable', Rule::in(['supplier', 'customer', 'employee'])],
            'lines.*.party_id'    => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * The rules that span lines, which per-field validation cannot express.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $lines = (array) $this->input('lines', []);

            $filled      = 0;
            $totalDebit  = 0.0;
            $totalCredit = 0.0;

            foreach ($lines as $i => $row) {
                $debit  = round((float) ($row['debit'] ?? 0), 2);
                $credit = round((float) ($row['credit'] ?? 0), 2);

                // The grid renders blank rows, so an empty one is normal input
                // rather than an error. It is simply not a line.
                if ($debit <= 0 && $credit <= 0) {
                    continue;
                }

                if ($debit > 0 && $credit > 0) {
                    $v->errors()->add(
                        "lines.{$i}.debit",
                        'A line carries either a debit or a credit, never both.',
                    );
                }

                $filled++;
                $totalDebit  += $debit;
                $totalCredit += $credit;
            }

            if ($filled < 2) {
                $v->errors()->add(
                    'lines',
                    'A journal needs at least two lines with an amount -- one debit and one credit.',
                );

                // No point reporting an imbalance on an incomplete voucher.
                return;
            }

            $difference = round($totalDebit - $totalCredit, 2);

            if (abs($difference) > 0.01) {
                $v->errors()->add('lines', sprintf(
                    'This journal does not balance. Debits total %s, credits total %s, difference %s.',
                    number_format($totalDebit, 2),
                    number_format($totalCredit, 2),
                    number_format($difference, 2),
                ));
            }

            if ($totalDebit <= 0) {
                $v->errors()->add('lines', 'A journal must move a non-zero amount.');
            }
        });
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'journal_date'              => 'journal date',
            'document_date'             => 'document date',
            'journal_type'              => 'journal type',
            'location_id'               => 'location',
            'lines.*.ledger_account_id' => 'ledger account',
            'lines.*.debit'             => 'debit',
            'lines.*.credit'            => 'credit',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'lines.min'                        => 'A journal needs at least two lines.',
            'lines.*.ledger_account_id.exists' => 'That ledger account does not exist, or is inactive.',
            'lines.*.ledger_account_id.required' => 'Every line with an amount needs a ledger account.',
            'journal_type.in'                  => 'Choose General, Opening Balance or Adjustment.',
        ];
    }
}
