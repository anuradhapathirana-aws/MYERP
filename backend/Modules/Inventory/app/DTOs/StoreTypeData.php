<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

use Modules\Inventory\Http\Requests\StoreStoreTypeRequest;
use Modules\Inventory\Http\Requests\UpdateStoreTypeRequest;

final class StoreTypeData
{
    public function __construct(
        public readonly string  $store_type_name,
        public readonly ?string $description,
        public readonly bool    $is_active,
    ) {}

    public static function fromStoreRequest(StoreStoreTypeRequest $request): self
    {
        return new self(
            store_type_name: trim($request->input('store_type_name')),
            description:     $request->filled('description') ? trim($request->input('description')) : null,
            is_active:       (bool) $request->input('is_active', true),
        );
    }

    public static function fromUpdateRequest(UpdateStoreTypeRequest $request): self
    {
        return new self(
            store_type_name: trim($request->input('store_type_name')),
            description:     $request->filled('description') ? trim($request->input('description')) : null,
            is_active:       (bool) $request->input('is_active', true),
        );
    }
}
