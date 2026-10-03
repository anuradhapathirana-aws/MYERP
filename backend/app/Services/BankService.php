<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\BankData;
use App\Models\Bank;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class BankService
{
    /** @param array<string, mixed> $filters */
    public function paginate(int $perPage = 50, array $filters = []): LengthAwarePaginator
    {
        $query = Bank::withCount('branches')->orderBy('bank_name');

        if (! empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $query->where(fn ($q) => $q->where('bank_name', 'like', $term)->orWhere('bank_code', 'like', $term));
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        return $query->paginate($perPage);
    }

    public function all(): Collection
    {
        return Bank::where('is_active', true)->orderBy('bank_name')->get();
    }

    public function create(BankData $data): Bank
    {
        return Bank::create($this->toAttributes($data));
    }

    public function update(Bank $bank, BankData $data): Bank
    {
        $bank->update($this->toAttributes($data));

        return $bank->fresh();
    }

    /**
     * Deleting a bank would cascade-delete its branches at the DB level, so it
     * is blocked while any branch exists — the user must remove them knowingly.
     */
    public function delete(Bank $bank): void
    {
        $branches = $bank->branches()->count();

        if ($branches > 0) {
            throw ValidationException::withMessages([
                'id' => "This bank cannot be deleted because it has {$branches} branch(es). Delete the branches first.",
            ]);
        }

        $bank->delete();
    }

    /** @return array<string, mixed> */
    private function toAttributes(BankData $data): array
    {
        return [
            'bank_name' => $data->bank_name,
            'bank_code' => $data->bank_code,
            'address'   => $data->address,
            'is_active' => $data->is_active,
        ];
    }
}
