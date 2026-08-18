<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\Enums\SalesChannelType;

class SalesChannelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'type'               => ['required', Rule::enum(SalesChannelType::class)],
            'sales_channel_name' => ['required', 'string', 'max:100'],
            'max_qty'            => ['nullable', 'numeric', 'min:0'],
            'applicable_from'    => ['nullable', 'date'],
            'applicable_to'      => ['nullable', 'date', 'after_or_equal:applicable_from'],
            'description'        => ['nullable', 'string', 'max:255'],
            'status'             => ['nullable', 'string', Rule::in(['Active', 'Inactive'])],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'type'               => 'channel type',
            'sales_channel_name' => 'sales channel name',
            'max_qty'            => 'maximum quantity',
            'applicable_from'    => 'applicable from date',
            'applicable_to'      => 'applicable to date',
        ];
    }
}
