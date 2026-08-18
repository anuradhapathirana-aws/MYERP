<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentModeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'payment_mode_name'     => ['required', 'string', 'max:50', Rule::unique('inv_payment_modes', 'payment_mode_name')->whereNull('deleted_at')],
            'code'                  => ['required', 'string', 'max:30', Rule::unique('inv_payment_modes', 'code')->whereNull('deleted_at')],
            'requires_bank_details' => ['boolean'],
            'requires_reference_no' => ['boolean'],
            'requires_date'         => ['boolean'],
            'sort_order'            => ['nullable', 'integer'],
            'is_active'             => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'payment_mode_name' => 'payment mode name',
            'code'               => 'code',
        ];
    }
}
