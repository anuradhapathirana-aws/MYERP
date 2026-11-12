<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\VatCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class TaxCodeRequest extends FormRequest
{
    /** More than a few stacked taxes on one line is a setup mistake, not a real invoice. */
    private const MAX_COMPONENTS = 4;

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
        $id = $this->route('tax_code')?->id;

        return [
            'code'                          => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('core_tax_codes', 'code')->ignore($id)],
            'name'                          => ['required', 'string', 'max:100'],
            'vat_category'                  => ['required', Rule::enum(VatCategory::class)],
            'is_active'                     => ['boolean'],
            'sort_order'                    => ['nullable', 'integer', 'min:0', 'max:999'],
            'components'                    => ['present', 'array', 'max:' . self::MAX_COMPONENTS],
            'components.*.tax_code_ref'     => ['required', 'string', 'distinct', Rule::exists('core_taxes', 'code')],
            'components.*.is_compound'      => ['boolean'],
            'components.*.is_recoverable'   => ['boolean'],
        ];
    }

    /** A standard-rated code needs at least one tax; the 0 % categories carry none. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $category = VatCategory::tryFrom((string) $this->input('vat_category'));
            $count    = count((array) $this->input('components', []));

            if ($category === VatCategory::Standard && $count === 0) {
                $v->errors()->add('components', 'A standard-rated code needs at least one tax. Use "No Tax Invoice", "Exempt" or "Import" for 0 %.');
            }

            if ($category !== null && ! $category->carriesTax() && $count > 0) {
                $v->errors()->add('components', "A {$category->label()} code carries no tax. Remove the taxes, or choose Standard rated.");
            }
        });
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'vat_category'              => 'VAT category',
            'components.*.tax_code_ref' => 'tax',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.regex'                         => 'The code may only contain letters, numbers, hyphens and underscores.',
            'components.max'                     => 'A code can hold at most ' . self::MAX_COMPONENTS . ' taxes.',
            'components.*.tax_code_ref.distinct' => 'The same tax is listed twice.',
            'components.*.tax_code_ref.exists'   => 'That tax does not exist in Master Data > Taxes.',
        ];
    }
}
