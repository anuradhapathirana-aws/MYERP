<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\TaxType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TaxRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $id = $this->route('tax')?->id;

        return [
            'code'           => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9_-]+$/'],
            'name'           => ['required', 'string', 'max:100'],
            'tax_type'       => ['required', Rule::enum(TaxType::class)],
            // SSCL is a few percent of turnover; anything above 10 is a typo.
            'rate'           => ['required', 'numeric', 'decimal:0,2', 'min:0', $this->input('tax_type') === TaxType::Sscl->value ? 'max:10' : 'max:100'],
            'effective_from' => [
                'required', 'date',
                Rule::unique('core_taxes', 'effective_from')
                    ->where(fn ($q) => $q->where('code', $this->input('code')))
                    ->ignore($id),
            ],
            'is_active'      => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'tax_type'       => 'type',
            'effective_from' => 'effective from date',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.regex'            => 'The code may only contain letters, numbers, hyphens and underscores.',
            'effective_from.unique' => 'This tax already has a rate starting on that date. Edit that row instead.',
        ];
    }
}
