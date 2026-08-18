<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveUnitConversionRatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category_id'          => ['required', 'integer', 'exists:inv_unit_categories,id'],
            'base_unit_type_id'    => ['required', 'integer', 'exists:inv_unit_types,id'],
            'rates'                => ['required', 'array', 'min:1'],
            'rates.*.unit_type_id' => ['required', 'integer', 'exists:inv_unit_types,id'],
            'rates.*.rate'         => ['required', 'numeric', 'min:0.0000000001'],
        ];
    }

    public function messages(): array
    {
        return [
            'rates.*.rate.min' => 'Rate must be a positive number.',
        ];
    }
}
