<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\BankBranchData;
use App\Models\BankBranch;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class BankBranchService
{
    /** @param array<string, mixed> $filters */
    public function paginate(int $perPage = 50, array $filters = []): LengthAwarePaginator
    {
        // Eager-load the bank so the resource's bank_name never lazy-loads (N+1).
        $query = BankBranch::with('bank:id,bank_name,bank_code')
            ->orderBy('branch_name');

        if (! empty($filters['bank_id'])) {
            $query->where('bank_id', (int) $filters['bank_id']);
        }

        if (! empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $query->where(fn ($q) => $q
                ->where('branch_name', 'like', $term)
                ->orWhere('branch_code', 'like', $term)
                ->orWhere('swift_code', 'like', $term));
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        return $query->paginate($perPage);
    }

    public function all(?int $bankId = null): Collection
    {
        return BankBranch::with('bank:id,bank_name,bank_code')
            ->where('is_active', true)
            ->when($bankId !== null, fn ($q) => $q->where('bank_id', $bankId))
            ->orderBy('branch_name')
            ->get();
    }

    public function create(BankBranchData $data): BankBranch
    {
        return BankBranch::create($this->toAttributes($data))->load('bank:id,bank_name,bank_code');
    }

    public function update(BankBranch $branch, BankBranchData $data): BankBranch
    {
        $branch->update($this->toAttributes($data));

        return $branch->fresh()->load('bank:id,bank_name,bank_code');
    }

    public function delete(BankBranch $branch): void
    {
        $branch->delete();
    }

    /** @return array<string, mixed> */
    private function toAttributes(BankBranchData $data): array
    {
        return [
            'bank_id'     => $data->bank_id,
            'branch_name' => $data->branch_name,
            'branch_code' => $data->branch_code,
            'swift_code'  => $data->swift_code,
            'address'     => $data->address,
            'is_active'   => $data->is_active,
        ];
    }
}
