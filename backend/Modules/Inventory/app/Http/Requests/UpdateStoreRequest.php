<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $storeId = $this->route('store')?->id;

        return [
            // Required core fields
            'store_type_id'  => ['required', 'integer', 'exists:inv_store_types,id'],
            'store_code'     => [
                'required', 'string', 'max:50',
                Rule::unique('inv_stores', 'store_code')->ignore($storeId)->whereNull('deleted_at'),
            ],
            'store_name'     => ['required', 'string', 'max:150'],
            'uom'            => ['nullable', 'string', 'max:50'],
            'capacity'       => ['nullable', 'numeric', 'min:0'],

            // Optional relations
            'location_id'      => ['nullable', 'integer', 'exists:inv_locations,id'],
            'parent_store_id'  => ['nullable', 'integer', 'exists:inv_stores,id'],

            // Address
            'address_line_1' => ['nullable', 'string', 'max:150'],
            'address_line_2' => ['nullable', 'string', 'max:150'],
            'city'           => ['nullable', 'string', 'max:100'],
            'state'          => ['nullable', 'string', 'max:100'],
            'country'        => ['nullable', 'string', 'max:100'],
            'postal_code'    => ['nullable', 'string', 'max:20'],

            // Contact
            'manager_name'   => ['nullable', 'string', 'max:100'],
            'phone'          => ['nullable', 'string', 'max:30'],
            'email'          => ['nullable', 'email', 'max:100'],

            // Misc
            'description'    => ['nullable', 'string'],
            'is_active'      => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'store_type_id'  => 'store type',
            'store_code'     => 'store code',
            'store_name'     => 'store name',
            'location_id'    => 'location',
            'address_line_1' => 'address line 1',
            'address_line_2' => 'address line 2',
            'manager_name'   => 'manager name',
            'postal_code'    => 'postal code',
        ];
    }
}
