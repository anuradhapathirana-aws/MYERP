<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Finance\Enums\AccountRole;
use Modules\Finance\Enums\PartyLedgerMode;
use Modules\Finance\Enums\AccountType;
use Modules\Finance\Models\AccountDefault;
use Modules\Finance\Models\LedgerAccount;

/**
 * Reads and writes the role-to-account mapping behind Finance > Settings.
 *
 * The mapping arrives complete from FinanceGlAccountsSeeder, so this screen is
 * there to SHOW what each role resolves to, to handle a chart that genuinely
 * differs, and to flip the posting mode. It is not a setup wizard.
 *
 * ── The type guard ───────────────────────────────────────────────────────────
 * Mapping Accounts Payable to Sales Revenue would produce a wrong Balance
 * Sheet a month later, with nothing erroring in between. So each role declares
 * the account types it accepts and a mismatch is refused at save time. That
 * turns the most expensive mistake available on this screen from a silent one
 * into a 422.
 *
 * ── Changing a mapping that has been used ────────────────────────────────────
 * Allowed, and deliberately so: blocking it would make an early setup mistake
 * unfixable. Existing journals keep the account they were posted with, because
 * history must not change retroactively -- usageCounts() exists so the UI can
 * say how many journals that is before anyone confirms.
 */
class AccountMappingService
{
    public function __construct(
        private readonly SettingsService        $settings,
        private readonly JournalPostingService  $posting,
        private readonly AccountResolver        $resolver,
        private readonly PartyLedgerProvisioner $parties,
    ) {}

    /**
     * Every role, with whatever it currently points at.
     *
     * Returns all 16 whether mapped or not, because an unmapped required role
     * is the single most useful thing this screen can show.
     *
     * @return list<array<string, mixed>>
     */
    public function all(?int $companyId = null): array
    {
        $mappings = AccountDefault::with('ledgerAccount.controlAccount.category')
            ->where(function ($q) use ($companyId): void {
                $q->whereNull('company_id');

                if ($companyId !== null) {
                    $q->orWhere('company_id', $companyId);
                }
            })
            ->get()
            ->keyBy(fn (AccountDefault $d): string => $d->rule_key instanceof AccountRole
                ? $d->rule_key->value
                : (string) $d->rule_key);

        $usage = $this->usageCounts();

        $rows = [];

        foreach (AccountRole::cases() as $role) {
            $mapping = $mappings->get($role->value);
            $account = $mapping?->ledgerAccount;

            $rows[] = [
                'rule_key'       => $role->value,
                'label'          => $role->label(),
                'hint'           => $role->hint(),
                'required'       => $role->isRequired(),
                'accepted_types' => array_map(
                    fn (AccountType $t): array => ['value' => $t->value, 'label' => $t->label()],
                    $role->acceptedTypes(),
                ),

                'ledger_account_id'   => $account?->id,
                'ledger_account_code' => $account?->code,
                'ledger_account_name' => $account?->ledger_account_name,
                'account_type'        => $account?->accountType()?->value,
                'is_mapped'           => $account !== null,
                'is_active'           => (bool) ($account?->is_active ?? false),

                // How many posted lines already used this account, so the UI
                // can warn before a change rather than after.
                'journal_lines_using' => $account === null ? 0 : ($usage[$account->id] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * Apply a set of changes.
     *
     * @param  array<string, int|null> $mappings  rule_key => ledger_account_id, or null to unmap
     */
    public function update(array $mappings, ?int $companyId = null): void
    {
        DB::transaction(function () use ($mappings, $companyId): void {
            foreach ($mappings as $ruleKey => $accountId) {
                $role = AccountRole::tryFrom((string) $ruleKey);

                if ($role === null) {
                    throw ValidationException::withMessages([
                        'mappings' => 'Unknown posting role [' . $ruleKey . '].',
                    ]);
                }

                if ($accountId === null) {
                    $this->unmap($role, $companyId);
                    continue;
                }

                $this->map($role, (int) $accountId, $companyId);
            }
        });

        // The resolver memoises within a request, so a change made in this one
        // must not be served from before it.
        $this->resolver->flush();
    }

    private function map(AccountRole $role, int $accountId, ?int $companyId): void
    {
        $account = LedgerAccount::with('controlAccount.category')->find($accountId);

        if ($account === null) {
            throw ValidationException::withMessages([
                "mappings.{$role->value}" => 'That ledger account does not exist.',
            ]);
        }

        if (! $account->is_active) {
            throw ValidationException::withMessages([
                "mappings.{$role->value}" => $account->code . ' (' . $account->ledger_account_name
                    . ') is inactive. Re-activate it or choose another account.',
            ]);
        }

        $type = $account->accountType();

        if ($type === null || ! in_array($type, $role->acceptedTypes(), true)) {
            $accepts = implode(' or ', array_map(
                fn (AccountType $t): string => strtoupper($t->label()),
                $role->acceptedTypes(),
            ));

            throw ValidationException::withMessages([
                "mappings.{$role->value}" => $role->label() . ' must be mapped to a ' . $accepts
                    . ' account. ' . $account->code . ' (' . $account->ledger_account_name . ') is '
                    . strtoupper($type?->label() ?? 'untyped') . '.',
            ]);
        }

        AccountDefault::updateOrCreate(
            ['rule_key' => $role->value, 'company_id' => $companyId],
            ['ledger_account_id' => $account->id, 'description' => $role->hint()],
        );
    }

    private function unmap(AccountRole $role, ?int $companyId): void
    {
        if ($role->isRequired() && $companyId === null) {
            throw ValidationException::withMessages([
                "mappings.{$role->value}" => $role->label()
                    . ' is required for posting and cannot be left unmapped.',
            ]);
        }

        AccountDefault::where('rule_key', $role->value)
            ->where('company_id', $companyId)
            ->delete();
    }

    /**
     * Posted line counts per ledger account, in one query.
     *
     * @return array<int, int>
     */
    private function usageCounts(): array
    {
        return DB::table('fin_journal_lines')
            ->select('ledger_account_id', DB::raw('COUNT(*) as total'))
            ->groupBy('ledger_account_id')
            ->pluck('total', 'ledger_account_id')
            ->map(fn ($v): int => (int) $v)
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public function missingRequired(?int $companyId = null): array
    {
        return array_map(
            fn (AccountRole $r): array => ['rule_key' => $r->value, 'label' => $r->label()],
            $this->resolver->missingRequiredRoles($companyId),
        );
    }

    public function postingMode(): string
    {
        return $this->posting->postingMode();
    }

    public function setPostingMode(string $mode): string
    {
        $mode = $mode === JournalPostingService::MODE_IMMEDIATE
            ? JournalPostingService::MODE_IMMEDIATE
            : JournalPostingService::MODE_REVIEW;

        $this->settings->set(JournalPostingService::SETTING_POSTING_MODE, $mode);

        return $mode;
    }

    /** @return array{supplier:string,customer:string} */
    public function partyLedgerModes(): array
    {
        return [
            'supplier' => $this->parties->modeFor(PartyLedgerProvisioner::PARTY_SUPPLIER)->value,
            'customer' => $this->parties->modeFor(PartyLedgerProvisioner::PARTY_CUSTOMER)->value,
        ];
    }

    /**
     * Switch one side of the chart between a shared account and one per party.
     *
     * Deliberately NOT retrospective. Existing journal lines keep pointing
     * wherever they pointed, which is the only honest option for a posted
     * entry. Because fin_journal_lines.party_id is written in both modes,
     * ageing reports read identically across the change; only a party's own
     * ledger shows a partial history until someone transfers the balance with
     * a journal voucher.
     *
     * @return array{supplier:string,customer:string}
     */
    public function setPartyLedgerMode(string $partyType, string $mode): array
    {
        $key = match ($partyType) {
            PartyLedgerProvisioner::PARTY_SUPPLIER => PartyLedgerProvisioner::SETTING_SUPPLIER_MODE,
            PartyLedgerProvisioner::PARTY_CUSTOMER => PartyLedgerProvisioner::SETTING_CUSTOMER_MODE,
            default => throw ValidationException::withMessages([
                'party_type' => 'Only supplier and customer balances can be held per party.',
            ]),
        };

        $resolved = PartyLedgerMode::tryFrom($mode);

        if ($resolved === null) {
            throw ValidationException::withMessages([
                'mode' => 'Unknown party ledger mode [' . $mode . '].',
            ]);
        }

        $this->settings->set($key, $resolved->value);
        $this->resolver->flush();

        return $this->partyLedgerModes();
    }
}
