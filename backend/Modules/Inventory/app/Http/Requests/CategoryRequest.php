<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'product_service_type' => ['required', Rule::in(['product', 'service'])],
            'industry_id'          => ['nullable', 'integer', 'exists:inv_industries,id'],
            'company_id'           => ['nullable', 'integer', 'exists:inv_companies,id'],
            'parent_category_id'   => ['nullable', 'integer', 'exists:inv_categories,id'],
            'category_name'        => ['required', 'string', 'max:100'],
            'reference_name'       => ['nullable', 'string', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'category_name'        => 'category name',
            'product_service_type' => 'type',
            'parent_category_id'   => 'parent category',
            'industry_id'          => 'industry',
            'company_id'           => 'company',
            'reference_name'       => 'reference name',
        ];
    }
}
