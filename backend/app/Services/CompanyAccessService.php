<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Which companies a user may see and act on.
 *
 * The rule is "no rows = every company", so the feature is invisible until a
 * client admin restricts someone. super_admin is never restricted.
 *
 * Enforced by Finance today. Inventory can adopt it by calling scope() in its
 * own list queries -- nothing here knows about any module.
 */
class CompanyAccessService
{
    /** @var array<int, list<int>|null> Per-request memo, keyed by user id. */
    private array $cache = [];

    /** @return list<int>|null  null means unrestricted. */
    public function allowedCompanyIds(?User $user): ?array
    {
        if ($user === null || $user->hasRole('super_admin')) {
            return null;
        }

        if (! array_key_exists($user->id, $this->cache)) {
            $ids = DB::table('user_company_access')
                ->where('user_id', $user->id)
                ->pluck('company_id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $this->cache[$user->id] = $ids === [] ? null : $ids;
        }

        return $this->cache[$user->id];
    }

    public function canAccess(?User $user, ?int $companyId): bool
    {
        $allowed = $this->allowedCompanyIds($user);

        // A document with no company is group-wide, so it is visible to all.
        return $allowed === null || $companyId === null || in_array($companyId, $allowed, true);
    }

    /**
     * Restrict a query to the user's companies.
     *
     * Rows with a NULL company stay visible when $includeNull is true -- they
     * are group-wide entries (most automatic journals) rather than another
     * company's data.
     */
    public function scope(Builder $query, ?User $user, string $column = 'company_id', bool $includeNull = true): Builder
    {
        $allowed = $this->allowedCompanyIds($user);

        if ($allowed === null) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($allowed, $column, $includeNull): void {
            $q->whereIn($column, $allowed);

            if ($includeNull) {
                $q->orWhereNull($column);
            }
        });
    }

    /** @return list<int> */
    public function assignedCompanyIds(User $user): array
    {
        return DB::table('user_company_access')
            ->where('user_id', $user->id)
            ->orderBy('company_id')
            ->pluck('company_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Replace a user's company list and audit the change.
     *
     * @param list<int> $companyIds Empty restores access to every company.
     */
    public function sync(User $user, array $companyIds): void
    {
        $companyIds = array_values(array_unique(array_map('intval', $companyIds)));
        sort($companyIds);

        $before = $this->assignedCompanyIds($user);

        if ($before === $companyIds) {
            return;
        }

        DB::transaction(function () use ($user, $companyIds, $before): void {
            DB::table('user_company_access')->where('user_id', $user->id)->delete();

            $now = now();

            DB::table('user_company_access')->insert(array_map(fn (int $id): array => [
                'user_id'    => $user->id,
                'company_id' => $id,
                'created_at' => $now,
                'updated_at' => $now,
            ], $companyIds));

            app(AuditLogger::class)->record(
                'user',
                (int) $user->id,
                'company_access_changed',
                ['company_ids' => $before],
                ['company_ids' => $companyIds],
            );
        });

        unset($this->cache[$user->id]);
    }
}
