<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

use Modules\Inventory\Enums\SalesChannelType;
use Modules\Inventory\Http\Requests\SalesChannelRequest;

final class SalesChannelData
{
    public function __construct(
        public readonly SalesChannelType $type,
        public readonly string           $salesChannelName,
        public readonly ?float           $maxQty,
        public readonly ?string          $applicableFrom,
        public readonly ?string          $applicableTo,
        public readonly ?string          $description,
        public readonly ?string          $status,
    ) {}

    public static function fromRequest(SalesChannelRequest $request): self
    {
        return new self(
            type:             SalesChannelType::from($request->input('type')),
            salesChannelName: trim($request->input('sales_channel_name')),
            maxQty:           $request->filled('max_qty') ? (float) $request->input('max_qty') : null,
            applicableFrom:   $request->input('applicable_from') ?: null,
            applicableTo:     $request->input('applicable_to') ?: null,
            description:      $request->filled('description') ? trim($request->input('description')) : null,
            status:           $request->input('status') ?: null,
        );
    }
}
