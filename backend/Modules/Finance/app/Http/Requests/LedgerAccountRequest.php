<?php

declare(strict_types=1);

namespace Modules\Finance\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Finance\Enums\CashBookType;

/**
 * Account Type and Account Category appear on the form only to narrow the
 * Control Account dropdown; both are derived from the control account and are
 * not accepted here.
 *
 * The cash / bank block is conditional. Each branch of it is validated only
 * when that branch is selected, and LedgerAccountData clears the other
 * branches' fields before anything is persisted.
 */
class LedgerAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $id     = $this->route('ledger_account')?->id;
        $isBank = $this->input('cash_book_type') === CashBookType::Bank->value;

        return [
            'control_account_id' => [
                'required', 'integer',
                Rule::exists('fin_control_accounts', 'id')->whereNull('deleted_at'),
            ],
            'ledger_account_name' => [
                'required', 'string', 'max:100',
                Rule::unique('fin_ledger_accounts', 'ledger_account_name')
                    ->ignore($id)
                    ->where(fn ($q) => $q->where('control_account_id', $this->input('control_account_id')))
                    ->whereNull('deleted_at'),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active'   => ['boolean'],

            // ── Cash / bank classification ───────────────────────────────────
            // Null for ordinary accounts; only funds-bearing accounts carry one.
            'cash_book_type' => ['nullable', 'string', Rule::in(CashBookType::values())],
            'allows_cash'    => ['boolean'],
            'allows_cheque'  => ['boolean'],

            // The owning legal entity. Required for EVERY cash/bank branch, not
            // only the bank one: a cash box and a petty cash float belong to a
            // single company just as a bank account does. Ordinary accounts are
            // shared group-wide and leave this null.
            'company_id' => [
                'nullable', 'required_with:cash_book_type', 'integer',
                // A soft link, so exists: is the only integrity check there is.
                Rule::exists('inv_companies', 'id'),
            ],

            // ── Bank branch only ─────────────────────────────────────────────
            'bank_id' => [
                'nullable', 'required_if:cash_book_type,' . CashBookType::Bank->value, 'integer',
                Rule::exists('core_banks', 'id')->whereNull('deleted_at'),
            ],
            'bank_branch_id' => [
                'nullable', 'required_if:cash_book_type,' . CashBookType::Bank->value, 'integer',
                // Must be a branch OF THE SELECTED BANK, not just any branch.
                Rule::exists('core_bank_branches', 'id')
                    ->where(fn ($q) => $q->where('bank_id', $this->input('bank_id')))
                    ->whereNull('deleted_at'),
            ],
            'bank_account_no' => array_filter([
                'nullable',
                'required_if:cash_book_type,' . CashBookType::Bank->value,
                'string', 'max:50', 'regex:/^[A-Za-z0-9 \-]+$/',
                // One ledger account per physical bank account. Two ledgers on
                // the same account number would silently split one balance in
                // two and never reconcile.
                $isBank
                    ? Rule::unique('fin_ledger_accounts', 'bank_account_no')
                        ->ignore($id)
                        ->where(fn ($q) => $q->where('bank_id', $this->input('bank_id')))
                        ->whereNull('deleted_at')
                    : null,
            ]),
        ];
    }

    /**
     * A Cash Book that accepts neither cash nor cheques cannot receive money,
     * so it would be dead data. Cross-field rules like this belong after the
     * per-field pass, once cash_book_type is known to be valid.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('cash_book_type') !== CashBookType::CashBook->value) {
                return;
            }

            if (! $this->boolean('allows_cash') && ! $this->boolean('allows_cheque')) {
                $validator->errors()->add(
                    'allows_cash',
                    'A cash book must accept cash, cheques, or both.',
                );
            }
        });
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'control_account_id'  => 'control account',
            'ledger_account_name' => 'ledger account',
            'cash_book_type'      => 'cash / bank type',
            'company_id'          => 'company',
            'bank_id'             => 'bank',
            'bank_branch_id'      => 'bank branch',
            'bank_account_no'     => 'bank account number',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'control_account_id.exists'   => 'The selected control account no longer exists.',
            'ledger_account_name.unique'  => 'A ledger account with this name already exists under the selected control account.',
            'company_id.required_with'    => 'Select the company that owns this cash or bank account.',
            'company_id.exists'           => 'The selected company no longer exists.',
            'bank_id.required_if'         => 'Select the bank this account is held with.',
            'bank_branch_id.required_if'  => 'Select the branch this account is held at.',
            'bank_branch_id.exists'       => 'The selected branch does not belong to the selected bank.',
            'bank_account_no.required_if' => 'Enter the bank account number.',
            'bank_account_no.unique'      => 'This account number is already linked to another ledger account at this bank.',
            'bank_account_no.regex'       => 'The account number may only contain letters, numbers, spaces and hyphens.',
        ];
    }
}
