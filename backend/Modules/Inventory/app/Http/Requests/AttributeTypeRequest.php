<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttributeTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'category_id'          => ['required', 'integer', 'exists:inv_categories,id'],
            'product_service_type' => ['required', Rule::in(['product', 'service'])],
            'attribute_type_name'  => ['required', 'string', 'max:100'],
            'description'          => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'category_id'          => 'category',
            'product_service_type' => 'type',
            'attribute_type_name'  => 'attribute type name',
        ];
    }
}
