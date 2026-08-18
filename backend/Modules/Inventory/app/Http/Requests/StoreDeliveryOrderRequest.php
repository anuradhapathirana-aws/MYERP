<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDeliveryOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'so_id'              => ['required', 'integer', 'exists:inv_sales_orders,id'],
            'document_date'      => ['nullable', 'date'],
            'delivery_date'      => ['required', 'date'],
            'driver_id'          => ['nullable', 'integer', 'exists:inv_drivers,id'],
            'vehicle_id'         => ['nullable', 'integer', 'exists:inv_vehicle_masters,id'],
            'store_id'           => ['nullable', 'integer', 'exists:inv_stores,id'],
            'location_id'        => ['nullable', 'integer', 'exists:inv_locations,id'],
            'delivery_mode'      => ['nullable', 'string', 'max:40'],
            'delivery_vehicle'   => ['nullable', 'string', 'max:150'],
            'responsible_person' => ['nullable', 'string', 'max:150'],
            'delivery_address'   => ['nullable', 'string', 'max:2000'],
            'remarks'            => ['nullable', 'string', 'max:2000'],

            'items'              => ['required', 'array', 'min:1'],
            'items.*.so_item_id' => ['required', 'integer', 'exists:inv_sales_order_items,id'],
            'items.*.quantity'   => ['nullable', 'numeric', 'min:0'],
            'items.*.piece_ids'  => ['nullable', 'array'],
            'items.*.piece_ids.*' => ['integer', 'distinct'],
            'items.*.remarks'    => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'so_id'              => 'sales order',
            'document_date'      => 'date',
            'delivery_date'      => 'delivery date',
            'driver_id'          => 'driver',
            'vehicle_id'         => 'vehicle',
            'store_id'           => 'store',
            'location_id'        => 'location',
            'delivery_mode'      => 'delivery mode',
            'delivery_vehicle'   => 'delivery vehicle & number',
            'responsible_person' => 'responsible person',
            'items.*.so_item_id' => 'sales order line',
            'items.*.quantity'   => 'quantity',
        ];
    }
}
