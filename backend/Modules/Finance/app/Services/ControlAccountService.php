<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Finance\DTOs\ControlAccountData;
use Modules\Finance\Models\AccountCategory;
use Modules\Finance\Models\ControlAccount;

class ControlAccountService
{
    public function __construct(private readonly AccountCodeGenerator $codes) {}

    /** @param array<string, mixed> $filters */
    public function paginate(int $perPage = 50, array $filters = []): LengthAwarePaginator
    {
        // Eager-loaded: the list renders the parent category and its account
        // type on every row, which would otherwise be two queries per row.
        $query = ControlAccount::with('category')
            ->withCount('ledgerAccounts')
            ->orderBy('code');

        if (! empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $query->where(fn ($q) => $q->where('control_account_name', 'like', $term)->orWhere('code', 'like', $term));
        }

        if (! empty($filters['account_category_id'])) {
            $query->where('account_category_id', (int) $filters['account_category_id']);
        }

        // Filtering by type means filtering on the parent, since the type is
        // not duplicated onto this table.
        if (! empty($filters['account_type'])) {
            $query->whereHas('category', fn ($q) => $q->where('account_type', $filters['account_type']));
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        return $query->paginate($perPage);
    }

    /** @param array<string, mixed> $filters */
    public function all(array $filters = []): Collection
    {
        return ControlAccount::with('category')
            ->where('is_active', true)
            ->when(
                ! empty($filters['account_category_id']),
                fn ($q) => $q->where('account_category_id', (int) $filters['account_category_id']),
            )
            ->when(
                ! empty($filters['account_type']),
                fn ($q) => $q->whereHas('category', fn ($c) => $c->where('account_type', $filters['account_type'])),
            )
            ->orderBy('code')
            ->get();
    }

    public function create(ControlAccountData $data): ControlAccount
    {
        return DB::transaction(function () use ($data): ControlAccount {
            $category = AccountCategory::findOrFail($data->account_category_id);

            $control = new ControlAccount([
                'account_category_id'  => $category->id,
                'control_account_name' => $data->control_account_name,
                'description'          => $data->description,
                'is_active'            => $data->is_active,
            ]);

            $control->code = $this->codes->nextControlAccountCode($category, lock: true);
            $control->save();

            return $control->load('category');
        });
    }

    public function update(ControlAccount $control, ControlAccountData $data): ControlAccount
    {
        // Same reasoning as the account type on a category: the parent's code
        // is the first three digits of this one. Re-parenting would need the
        // whole subtree renumbered, and codes are immutable.
        if ($control->account_category_id !== $data->account_category_id) {
            throw ValidationException::withMessages([
                'account_category_id' => "The account category cannot be changed once a code is assigned. Code "
                    . "{$control->code} sits under category {$control->category?->code}. Create a new control "
                    . 'account under the other category instead.',
            ]);
        }

        $control->update([
            'control_account_name' => $data->control_account_name,
            'description'          => $data->description,
            'is_active'            => $data->is_active,
        ]);

        return $control->fresh()->load('category');
    }

    public function delete(ControlAccount $control): void
    {
        $children = $control->ledgerAccounts()->count();

        if ($children > 0) {
            throw ValidationException::withMessages([
                'id' => "This control account cannot be deleted because it has {$children} ledger account(s). "
                    . 'Delete those first, or set the control account to inactive to hide it from new entries.',
            ]);
        }

        $control->delete();
    }

    public function nextCode(AccountCategory $category): string
    {
        return $this->codes->nextControlAccountCode($category);
    }
}
