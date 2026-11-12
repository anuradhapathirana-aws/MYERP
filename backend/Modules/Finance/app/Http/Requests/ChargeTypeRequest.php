<?php

declare(strict_types=1);

namespace Modules\Finance\Http\Requests;

use App\Contracts\LandedCostTargets;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Finance\Enums\ChargeTreatment;
use Modules\Finance\Models\LedgerAccount;

class ChargeTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $clean = static fn ($v) => is_string($v) ? trim(strip_tags($v)) : $v;

        $this->merge(array_filter([
            'code' => is_string($this->input('code')) ? strtoupper(trim($this->input('code'))) : null,
            'name' => $clean($this->input('name')),
        ], static fn ($v) => $v !== null));
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $id = $this->route('charge_type')?->id;

        return [
            'code'                => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('fin_charge_types', 'code')->ignore($id)],
            'name'                => ['required', 'string', 'max:100'],
            'treatment'           => ['required', Rule::enum(ChargeTreatment::class)],
            'ledger_account_id'   => ['nullable', 'integer'],
            'fob_expense_type_id' => ['nullable', 'integer'],
            'cif_expense_type_id' => ['nullable', 'integer'],
            'is_active'           => ['boolean'],
            'sort_order'          => ['nullable', 'integer', 'min:0', 'max:999'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $accountId = $this->input('ledger_account_id');

            // Same rule as a bill line: the default account must be one a bill can charge.
            if ($accountId !== null && $accountId !== '' && ! LedgerAccount::billable()->whereKey((int) $accountId)->exists()) {
                $v->errors()->add('ledger_account_id', 'Choose an active expense account. Bank, cash and party accounts cannot be used.');
            }

            $this->checkCostingRow($v, 'fob_expense_type_id', 'fob');
            $this->checkCostingRow($v, 'cif_expense_type_id', 'cif');
        });
    }

    /** The row must be one of Inventory's Costing rows of the right kind (FOB list / CIF list). */
    private function checkCostingRow(Validator $v, string $field, string $costingType): void
    {
        $id = $this->input($field);

        if ($id === null || $id === '') {
            return;
        }

        $valid = collect(app(LandedCostTargets::class)->costingRows())
            ->contains(fn (array $row): bool => $row['id'] === (int) $id && $row['costing_type'] === $costingType);

        if (! $valid) {
            $v->errors()->add($field, 'Choose a ' . strtoupper($costingType) . ' costing row from the list.');
        }
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'ledger_account_id'   => 'default account',
            'fob_expense_type_id' => 'FOB costing row',
            'cif_expense_type_id' => 'CIF costing row',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.regex' => 'The code may only contain letters, numbers, hyphens and underscores.',
        ];
    }
}
