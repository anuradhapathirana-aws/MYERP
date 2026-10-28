<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\PaymentModeType;
use App\Models\PaymentMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePaymentModeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $paymentModeId = $this->route('payment_mode')?->id;

        return [
            'payment_mode_name' => [
                'required', 'string', 'max:50',
                Rule::unique('inv_payment_modes', 'payment_mode_name')->ignore($paymentModeId)->whereNull('deleted_at'),
            ],
            'code' => [
                'required', 'string', 'max:30',
                Rule::unique('inv_payment_modes', 'code')->ignore($paymentModeId)->whereNull('deleted_at'),
            ],
            'type'                  => ['required', Rule::enum(PaymentModeType::class)],
            'requires_bank_details' => ['boolean'],
            'requires_reference_no' => ['boolean'],
            'requires_date'         => ['boolean'],
            'sort_order'            => ['nullable', 'integer'],
            'is_active'             => ['boolean'],
        ];
    }

    /**
     * A built-in mode keeps its type: Inventory reads its code, and Finance
     * reads its type, so the two must keep agreeing.
     *
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [
            function (\Illuminate\Validation\Validator $v): void {
                $mode = $this->route('payment_mode');

                if (! $mode instanceof PaymentMode || ! $mode->isBuiltIn() || $v->errors()->has('type')) {
                    return;
                }

                if ($mode->type !== null && $this->input('type') !== $mode->type->value) {
                    $v->errors()->add('type', 'The type of a built-in mode cannot be changed.');
                }
            },
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
