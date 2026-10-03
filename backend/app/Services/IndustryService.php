<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use App\DTOs\IndustryData;
use App\Models\Industry;

class IndustryService
{
    public function paginate(int $perPage = 50): LengthAwarePaginator
    {
        return Industry::orderBy('name')->paginate($perPage);
    }

    public function find(int $id): Industry
    {
        return Industry::findOrFail($id);
    }

    public function create(IndustryData $data): Industry
    {
        return Industry::create($this->toAttributes($data));
    }

    public function update(Industry $industry, IndustryData $data): Industry
    {
        $industry->update($this->toAttributes($data));

        return $industry->fresh();
    }

    public function delete(Industry $industry): void
    {
        $industry->delete();
    }

    /** @return array<string, mixed> */
    private function toAttributes(IndustryData $data): array
    {
        return [
            'name'        => $data->name,
            'description' => $data->description,
        ];
    }
}
