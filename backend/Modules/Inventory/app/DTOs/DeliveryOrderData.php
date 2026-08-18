<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

use Modules\Inventory\Http\Requests\StoreDeliveryOrderRequest;
use Modules\Inventory\Http\Requests\UpdateDeliveryOrderRequest;

final class DeliveryOrderData
{
    /** @param array<array{so_item_id:int, quantity:?float, piece_ids:?array<int>, remarks:?string}> $items */
    public function __construct(
        public readonly int     $soId,
        public readonly ?string $documentDate,
        public readonly string  $deliveryDate,
        public readonly ?int    $driverId,
        public readonly ?int    $vehicleId,
        public readonly ?int    $storeId,
        public readonly ?int    $locationId,
        public readonly ?string $deliveryMode,
        public readonly ?string $deliveryVehicle,
        public readonly ?string $responsiblePerson,
        public readonly ?string $deliveryAddress,
        public readonly ?string $remarks,
        public readonly array   $items,
    ) {}

    public static function fromRequest(
        StoreDeliveryOrderRequest|UpdateDeliveryOrderRequest $request,
    ): self {
        return new self(
            soId:              (int) $request->validated('so_id'),
            documentDate:      $request->validated('document_date'),
            deliveryDate:      $request->validated('delivery_date'),
            driverId:          $request->validated('driver_id') !== null
                                   ? (int) $request->validated('driver_id')
                                   : null,
            vehicleId:         $request->validated('vehicle_id') !== null
                                   ? (int) $request->validated('vehicle_id')
                                   : null,
            storeId:           $request->validated('store_id') !== null
                                   ? (int) $request->validated('store_id')
                                   : null,
            locationId:        $request->validated('location_id') !== null
                                   ? (int) $request->validated('location_id')
                                   : null,
            deliveryMode:      $request->validated('delivery_mode'),
            deliveryVehicle:   $request->validated('delivery_vehicle'),
            responsiblePerson: $request->validated('responsible_person'),
            deliveryAddress:   $request->validated('delivery_address'),
            remarks:           $request->validated('remarks'),
            items:             (array) ($request->validated('items') ?? []),
        );
    }
}
