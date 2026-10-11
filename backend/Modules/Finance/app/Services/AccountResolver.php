<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

use App\Models\SupplierMaster;
use Illuminate\Validation\ValidationException;
use Modules\Finance\Enums\AccountRole;
use Modules\Finance\Models\AccountDefault;
use Modules\Finance\Models\LedgerAccount;

/**
 * Turns a semantic role into a ledger account id.
 *
 * This is the piece that lets Inventory ask for accounts_payable instead of
 * account 20101001, which is what keeps Inventory from reading any fin_ table
 * and therefore what keeps the two modules independently sellable.
 *
 * ── Resolution order, most specific first ────────────────────────────────────
 *   0. the party's OWN ledger account, when this side of the chart is in
 *      per-party mode. Created on first use. Returns null in shared mode, so
 *      an installation that never switches it on behaves exactly as before
 *   1. the supplier group override, for payables only
 *      (core_supplier_groups.default_payable_account_id)
 *   2. fin_account_defaults for (role, this company)
 *   3. fin_account_defaults for (role, NULL) -- the global default
 *   4. FAIL with a readable 422
 *
 * Step 4 is deliberate and important: an unmapped role must stop the document
 * from confirming, never fall back to a guessed account. A payment that posts
 * to the wrong account is far more expensive to unpick than one that refused
 * to save.
 *
 * ── Golden Rule ──────────────────────────────────────────────────────────────
 * SupplierMaster and SupplierGroup both live in App\Models -- core, which is
 * always installed. Reading them here is core-to-core, not a reach into
 * Inventory, even though core_supplier_groups predates this module and
 * inv_supplier_masters still carries its legacy table name.
 *
 * ── Caching ──────────────────────────────────────────────────────────────────
 * Memoised per request only, deliberately not through the cache driver. A
 * posting resolves the same handful of roles several times in one transaction,
 * which the memo covers; a persistent cache would instead serve a stale
 * account after someone changes a mapping, and that is the one kind of
 * staleness nobody would think to look for.
 */
final class AccountResolver
{
    /** @var array<string, int> */
    private array $memo = [];

    public function __construct(private readonly PartyLedgerProvisioner $parties) {}

    public function resolve(
        AccountRole $role,
        ?int        $companyId = null,
        ?string     $partyType = null,
        ?int        $partyId   = null,
        ?string     $partyName = null,
    ): int {
        $key = implode('|', [$role->value, $companyId ?? '-', $partyType ?? '-', $partyId ?? '-']);

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $accountId = $this->parties->resolve($role, $partyType, $partyId, $partyName)
            ?? $this->fromSupplierGroup($role, $partyType, $partyId)
            ?? $this->fromDefaults($role, $companyId)
            ?? $this->fail($role);

        $this->assertPostable($role, $accountId);

        return $this->memo[$key] = $accountId;
    }

    /**
     * Step 1 -- a supplier group may name its own payables account, so a group
     * such as Statutory/Government can post to a different liability than
     * ordinary trade creditors without a per-document decision.
     */
    private function fromSupplierGroup(AccountRole $role, ?string $partyType, ?int $partyId): ?int
    {
        if ($role !== AccountRole::AccountsPayable || $partyType !== 'supplier' || $partyId === null) {
            return null;
        }

        $accountId = SupplierMaster::query()
            ->where('inv_supplier_masters.id', $partyId)
            ->join('core_supplier_groups as g', 'g.id', '=', 'inv_supplier_masters.supplier_group_id')
            ->value('g.default_payable_account_id');

        $accountId = $accountId === null ? null : (int) $accountId;

        // A group pointing at an account that has since been deleted must not
        // silently win over the global default -- fall through instead.
        if ($accountId !== null && ! LedgerAccount::whereKey($accountId)->exists()) {
            return null;
        }

        return $accountId;
    }

    /** Steps 2 and 3 -- company-specific row, then the global one. */
    private function fromDefaults(AccountRole $role, ?int $companyId): ?int
    {
        $rows = AccountDefault::where('rule_key', $role->value)
            ->where(function ($q) use ($companyId): void {
                $q->whereNull('company_id');

                if ($companyId !== null) {
                    $q->orWhere('company_id', $companyId);
                }
            })
            ->get(['ledger_account_id', 'company_id']);

        // Company-specific wins over the global row. Chosen in PHP rather than
        // with an ORDER BY because NULL ordering differs between MySQL and
        // SQLite, and this has to behave identically in tests and production.
        $chosen = $companyId === null
            ? $rows->firstWhere('company_id', null)
            : ($rows->firstWhere('company_id', $companyId) ?? $rows->firstWhere('company_id', null));

        return $chosen === null ? null : (int) $chosen->ledger_account_id;
    }

    /**
     * The resolved account must still be usable.
     *
     * Catches the case where a mapped account was later deactivated: the
     * mapping row is valid but posting to a retired account would quietly put
     * balances somewhere nobody is looking.
     */
    private function assertPostable(AccountRole $role, int $accountId): void
    {
        $account = LedgerAccount::select(['id', 'code', 'ledger_account_name', 'is_active'])
            ->find($accountId);

        if ($account === null) {
            throw ValidationException::withMessages([
                'account_mapping' => "The account mapped to {$role->label()} no longer exists. "
                    . 'Re-map it under Finance > Settings before confirming this document.',
            ]);
        }

        if (! $account->is_active) {
            throw ValidationException::withMessages([
                'account_mapping' => "{$role->label()} is mapped to {$account->code} "
                    . "({$account->ledger_account_name}), which is inactive. Re-activate it or map a "
                    . 'different account under Finance > Settings.',
            ]);
        }
    }

    private function fail(AccountRole $role): never
    {
        throw ValidationException::withMessages([
            'account_mapping' => "No ledger account is mapped to {$role->label()}. "
                . 'Set it under Finance > Settings > Account Mapping, then confirm this document again.',
        ]);
    }

    /**
     * Roles that a posting needs but nothing is mapped to.
     *
     * Drives the warning banner on the Finance Settings screen, so a missing
     * mapping is noticed before a document fails rather than after.
     *
     * @return list<AccountRole>
     */
    public function missingRequiredRoles(?int $companyId = null): array
    {
        $mapped = AccountDefault::query()
            ->where(fn ($q) => $q->whereNull('company_id')->when(
                $companyId !== null,
                fn ($inner) => $inner->orWhere('company_id', $companyId),
            ))
            ->pluck('rule_key')
            ->map(fn ($k): string => $k instanceof AccountRole ? $k->value : (string) $k)
            ->all();

        return array_values(array_filter(
            AccountRole::cases(),
            fn (AccountRole $r): bool => $r->isRequired() && ! in_array($r->value, $mapped, true),
        ));
    }

    /** Drop the per-request memo. Used by tests and after a mapping change. */
    public function flush(): void
    {
        $this->memo = [];
    }
}
