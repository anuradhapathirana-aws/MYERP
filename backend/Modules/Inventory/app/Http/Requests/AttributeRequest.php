<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AttributeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        // attribute_name accepts an unbounded comma-separated list on both create
        // and update; per-item max:100 (the inv_attributes column width) is
        // enforced in the controller after parsing.
        return [
            'attribute_type_id' => ['required', 'integer', 'exists:inv_attribute_types,id'],
            'attribute_name'    => ['required', 'string'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'attribute_type_id' => 'attribute type',
            'attribute_name'    => 'attribute name',
        ];
    }
}
