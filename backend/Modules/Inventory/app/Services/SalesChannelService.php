<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Inventory\DTOs\SalesChannelData;
use Modules\Inventory\Models\SalesChannel;

class SalesChannelService
{
    public function paginate(int $perPage = 50): LengthAwarePaginator
    {
        return SalesChannel::orderBy('sales_channel_name')->paginate($perPage);
    }

    public function find(int $id): SalesChannel
    {
        return SalesChannel::findOrFail($id);
    }

    public function create(SalesChannelData $data): SalesChannel
    {
        return SalesChannel::create($this->toAttributes($data));
    }

    public function update(SalesChannel $channel, SalesChannelData $data): SalesChannel
    {
        $channel->update($this->toAttributes($data));

        return $channel->fresh();
    }

    public function delete(SalesChannel $channel): void
    {
        $channel->delete();
    }

    /** @return array<string, mixed> */
    private function toAttributes(SalesChannelData $data): array
    {
        return [
            'type'               => $data->type,
            'sales_channel_name' => $data->salesChannelName,
            'max_qty'            => $data->maxQty,
            'applicable_from'    => $data->applicableFrom,
            'applicable_to'      => $data->applicableTo,
            'description'        => $data->description,
            'status'             => $data->status,
        ];
    }
}
