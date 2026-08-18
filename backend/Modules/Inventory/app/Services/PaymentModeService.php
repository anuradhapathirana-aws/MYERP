<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Inventory\DTOs\PaymentModeData;
use Modules\Inventory\Models\PaymentMode;

class PaymentModeService
{
    public function paginate(int $perPage = 50): LengthAwarePaginator
    {
        return PaymentMode::orderBy('sort_order')->orderBy('payment_mode_name')->paginate($perPage);
    }

    public function all(): Collection
    {
        return PaymentMode::where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('payment_mode_name')
            ->get();
    }

    public function create(PaymentModeData $data): PaymentMode
    {
        return PaymentMode::create($this->toAttributes($data));
    }

    public function update(PaymentMode $paymentMode, PaymentModeData $data): PaymentMode
    {
        $paymentMode->update($this->toAttributes($data));

        return $paymentMode->fresh();
    }

    public function delete(PaymentMode $paymentMode): void
    {
        $paymentMode->delete();
    }

    /** @return array<string, mixed> */
    private function toAttributes(PaymentModeData $data): array
    {
        return [
            'payment_mode_name'     => $data->payment_mode_name,
            'code'                  => $data->code,
            'requires_bank_details' => $data->requires_bank_details,
            'requires_reference_no' => $data->requires_reference_no,
            'requires_date'         => $data->requires_date,
            'sort_order'            => $data->sort_order,
            'is_active'             => $data->is_active,
        ];
    }
}
