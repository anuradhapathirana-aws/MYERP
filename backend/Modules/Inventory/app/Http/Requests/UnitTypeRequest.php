<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\Enums\UnitPosition;

class UnitTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'unit_category_id' => ['required', 'integer', 'exists:inv_unit_categories,id'],
            'name'             => ['required', 'string', 'max:100'],
            'symbol'           => ['required', 'string', 'max:45'],
            'country'          => ['nullable', 'string', 'max:45'],
            'unit_position'    => ['required', 'string', Rule::enum(UnitPosition::class)],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'unit_category_id' => 'category',
            'unit_position'    => 'unit position',
        ];
    }
}
