<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStoreTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'store_type_name' => ['required', 'string', 'max:100', Rule::unique('inv_store_types', 'store_type_name')->whereNull('deleted_at')],
            'description'     => ['nullable', 'string'],
            'is_active'       => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'store_type_name' => 'store type name',
            'description'     => 'description',
            'is_active'       => 'active status',
        ];
    }
}
