<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Finance\DTOs\AccountCategoryData;
use Modules\Finance\Models\AccountCategory;

class AccountCategoryService
{
    public function __construct(private readonly AccountCodeGenerator $codes) {}

    /** @param array<string, mixed> $filters */
    public function paginate(int $perPage = 50, array $filters = []): LengthAwarePaginator
    {
        // Ordering by code is ordering by account type then by creation order
        // within it — the conventional chart-of-accounts sequence, for free.
        $query = AccountCategory::withCount('controlAccounts')->orderBy('code');

        if (! empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $query->where(fn ($q) => $q->where('category_name', 'like', $term)->orWhere('code', 'like', $term));
        }

        if (! empty($filters['account_type'])) {
            $query->where('account_type', $filters['account_type']);
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        return $query->paginate($perPage);
    }

    /** @param array<string, mixed> $filters */
    public function all(array $filters = []): Collection
    {
        return AccountCategory::where('is_active', true)
            ->when(! empty($filters['account_type']), fn ($q) => $q->where('account_type', $filters['account_type']))
            ->orderBy('code')
            ->get();
    }

    public function create(AccountCategoryData $data): AccountCategory
    {
        // The code is allocated and the row written in one transaction, with the
        // sibling range locked, so two concurrent saves cannot both be handed
        // the same code.
        return DB::transaction(function () use ($data): AccountCategory {
            $category = new AccountCategory([
                'account_type'  => $data->account_type,
                'category_name' => $data->category_name,
                'description'   => $data->description,
                'is_active'     => $data->is_active,
            ]);

            // Not mass-assignable by design — see the model.
            $category->code = $this->codes->nextCategoryCode($data->account_type, lock: true);
            $category->save();

            return $category;
        });
    }

    public function update(AccountCategory $category, AccountCategoryData $data): AccountCategory
    {
        // The account type is baked into the first digit of the code, and the
        // code is already printed on journals and reports. Changing the type
        // would make every descendant code describe the wrong section of the
        // chart. Standard ERPs lock this the same way (SAP will not let you
        // change a G/L account's account group once the number is assigned).
        if ($category->account_type !== $data->account_type) {
            throw ValidationException::withMessages([
                'account_type' => "The account type cannot be changed once a code is assigned. Code {$category->code} "
                    . "belongs to {$category->account_type->label()}. Create a new category under "
                    . "{$data->account_type->label()} instead.",
            ]);
        }

        $category->update([
            'category_name' => $data->category_name,
            'description'   => $data->description,
            'is_active'     => $data->is_active,
        ]);

        return $category->fresh();
    }

    /**
     * Deleting a category that still has control accounts would orphan an
     * entire branch of the chart, so it is blocked. The DB would refuse it too
     * (restrictOnDelete) — this turns that into a message the user can act on.
     */
    public function delete(AccountCategory $category): void
    {
        $children = $category->controlAccounts()->count();

        if ($children > 0) {
            throw ValidationException::withMessages([
                'id' => "This account category cannot be deleted because it has {$children} control account(s). "
                    . 'Delete those first, or set the category to inactive to hide it from new entries.',
            ]);
        }

        $category->delete();
    }

    public function nextCode(\Modules\Finance\Enums\AccountType $type): string
    {
        // Display only — no lock, never persisted. The real code is allocated
        // again inside create()'s transaction.
        return $this->codes->nextCategoryCode($type);
    }
}
