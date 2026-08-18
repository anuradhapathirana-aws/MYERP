<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSupplierPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'payment_date'      => ['required', 'date'],
            'transaction_date'  => ['nullable', 'date'],
            'reference_no'      => ['nullable', 'string', 'max:100'],
            'supplier_type'     => ['nullable', 'string', 'max:50'],
            'supplier_id'       => ['required', 'integer', 'exists:inv_supplier_masters,id'],
            'payment_remark'    => ['nullable', 'string'],

            'is_advance'        => ['required', 'boolean'],
            'advance_amount'    => ['required_if:is_advance,true', 'nullable', 'numeric', 'min:0.01'],

            'allocations'                        => ['required_if:is_advance,false', 'array'],
            'allocations.*.reference_type'       => ['required_with:allocations', 'string', 'in:grn'],
            'allocations.*.reference_id'         => ['required_with:allocations', 'integer'],
            'allocations.*.due_date'             => ['nullable', 'date'],
            'allocations.*.discount'             => ['nullable', 'numeric', 'min:0'],
            // Optional — omitting it (or sending null) defaults to paying the GRN in full (outstanding − discount).
            // A smaller value pays only part of it; the rest stays outstanding for a future payment.
            'allocations.*.payment_amount'       => ['nullable', 'numeric', 'min:0'],
            'allocations.*.line_remark'          => ['nullable', 'string'],

            'setoffs'                     => ['nullable', 'array'],
            'setoffs.*.setoff_type'       => ['required_with:setoffs', 'string', 'in:customer_return,over_payment,advance'],
            'setoffs.*.credit_note_id'    => ['nullable', 'integer', 'exists:inv_supplier_credit_notes,id'],
            'setoffs.*.amount'            => ['required_with:setoffs', 'numeric', 'min:0.01'],
            'setoffs.*.remark'            => ['nullable', 'string'],

            'settlements'                          => ['nullable', 'array'],
            'settlements.*.payment_mode_id'        => ['required_with:settlements', 'integer', 'exists:inv_payment_modes,id'],
            'settlements.*.amount'                 => ['required_with:settlements', 'numeric', 'min:0.01'],
            'settlements.*.bank_name'              => ['nullable', 'string', 'max:100'],
            'settlements.*.bank_account_no'        => ['nullable', 'string', 'max:50'],
            'settlements.*.reference_no'           => ['nullable', 'string', 'max:50'],
            'settlements.*.instrument_date'        => ['nullable', 'date'],
            'settlements.*.is_thirdparty'          => ['nullable', 'boolean'],
            'settlements.*.remark'                 => ['nullable', 'string'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'supplier_id'                     => 'supplier',
            'advance_amount'                   => 'advance amount',
            'allocations.*.reference_id'       => 'GRN',
            'allocations.*.discount'           => 'discount',
            'setoffs.*.setoff_type'            => 'setoff type',
            'setoffs.*.credit_note_id'         => 'credit note',
            'setoffs.*.amount'                 => 'setoff amount',
            'settlements.*.payment_mode_id'    => 'payment mode',
            'settlements.*.amount'             => 'settlement amount',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $isAdvance   = (bool) $this->input('is_advance');
            $allocations = (array) $this->input('allocations', []);
            $setoffs     = (array) $this->input('setoffs', []);
            $settlements = (array) $this->input('settlements', []);

            $this->validateChequeNumbers($validator, $settlements);

            if ($isAdvance && count($allocations) > 0) {
                $validator->errors()->add('allocations', 'A standalone advance payment cannot also include GRN allocations.');
            }

            if (!$isAdvance && count($allocations) === 0) {
                $validator->errors()->add('allocations', 'Select at least one GRN to pay, or mark this payment as a standalone advance.');
            }

            foreach ($setoffs as $index => $setoff) {
                $type = $setoff['setoff_type'] ?? null;

                if (in_array($type, ['over_payment', 'advance'], true) && empty($setoff['credit_note_id'])) {
                    $validator->errors()->add("setoffs.{$index}.credit_note_id", 'A credit note must be selected for this setoff type.');
                }

                if ($type === 'customer_return' && empty($setoff['remark'])) {
                    $validator->errors()->add("setoffs.{$index}.remark", 'A remark is required for customer return setoffs.');
                }
            }
        });
    }

    /**
     * A settlement line paid by cheque must carry a 6-digit cheque number in reference_no,
     * and a cheque date in instrument_date.
     * @param array<int, array<string, mixed>> $settlements
     */
    private function validateChequeNumbers(Validator $validator, array $settlements): void
    {
        if (empty($settlements)) {
            return;
        }

        $chequeModeIds = \Modules\Inventory\Models\PaymentMode::where('code', 'cheque')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach ($settlements as $index => $settlement) {
            $isCheque = in_array((int) ($settlement['payment_mode_id'] ?? 0), $chequeModeIds, true);

            if ($isCheque && !preg_match('/^\d{6}$/', (string) ($settlement['reference_no'] ?? ''))) {
                $validator->errors()->add("settlements.{$index}.reference_no", 'Cheque number must be exactly 6 digits.');
            }

            if ($isCheque && empty($settlement['instrument_date'])) {
                $validator->errors()->add("settlements.{$index}.instrument_date", 'Cheque date is required.');
            }
        }
    }
}
