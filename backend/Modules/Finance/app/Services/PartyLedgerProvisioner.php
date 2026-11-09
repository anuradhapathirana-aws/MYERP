<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

use App\Services\SettingsService;
use Illuminate\Validation\ValidationException;
use Modules\Finance\Enums\AccountRole;
use Modules\Finance\Enums\PartyLedgerMode;
use Modules\Finance\Models\AccountDefault;
use Modules\Finance\Models\LedgerAccount;

/**
 * Finds, or creates, the ledger account that represents one party.
 *
 * Only does anything when the installation is in per-party mode for that side.
 * In shared mode every method returns null and resolution falls straight
 * through to fin_account_defaults, exactly as before this class existed.
 *
 * ── When the account is created ─────────────────────────────────────────────
 * A SUPPLIER gets it on save (SupplierLedgerObserver), so a Journal Voucher can
 * name a supplier nothing has posted to yet. SupplierMaster is core, which is
 * what lets Finance watch it without Inventory changing.
 *
 * A CUSTOMER gets it at first posting. CustomerMaster belongs to Inventory, so
 * Finance watching it would break the Golden Rule. It also keeps the chart as
 * small as the business: import 4,000 customers and the chart grows as they
 * trade, not by 4,000 rows.
 *
 * resolve() still creates on first posting for either side. That covers
 * suppliers saved before per-party mode was switched on, and any whose account
 * could not be created at save time.
 *
 * Both are safe inside a transaction: the code generator takes lockForUpdate(),
 * and if the supplier or document fails, the account creation rolls back too.
 *
 * ── Where the account is placed ─────────────────────────────────────────────
 * Under the SAME control account as the shared role account. So if
 * accounts_payable maps to 20201001 under "20201 Trade & Other Payables", a
 * supplier's account becomes 20201004 under that same control account -- which
 * is precisely Tally's shape, where parties are ledgers under the Sundry
 * Creditors group. Moving the shared mapping moves where new party accounts
 * are created, with no code change.
 */
final class PartyLedgerProvisioner
{
    public const SETTING_SUPPLIER_MODE = 'finance.supplier_ledger_mode';
    public const SETTING_CUSTOMER_MODE = 'finance.customer_ledger_mode';

    public const PARTY_SUPPLIER = 'supplier';
    public const PARTY_CUSTOMER = 'customer';

    /**
     * Roles whose balance belongs to a party.
     *
     * Deliberately short. A payment's bank line also carries no party, but even
     * if it did, Cash at Bank must never resolve to a party account.
     */
    private const PARTY_ROLES = [
        self::PARTY_SUPPLIER => AccountRole::AccountsPayable,
        self::PARTY_CUSTOMER => AccountRole::AccountsReceivable,
    ];

    public function __construct(
        private readonly SettingsService      $settings,
        private readonly LedgerAccountService $ledgers,
    ) {}

    public function modeFor(string $partyType): PartyLedgerMode
    {
        $key = match ($partyType) {
            self::PARTY_SUPPLIER => self::SETTING_SUPPLIER_MODE,
            self::PARTY_CUSTOMER => self::SETTING_CUSTOMER_MODE,
            // Employees and anything added later stay shared. Petty cash
            // reimbursements post to one Staff Advances account, which is what
            // you want -- an account per employee would be noise.
            default              => null,
        };

        if ($key === null) {
            return PartyLedgerMode::Shared;
        }

        return PartyLedgerMode::tryFrom((string) $this->settings->get($key, PartyLedgerMode::Shared->value))
            ?? PartyLedgerMode::Shared;
    }

    /**
     * The party's own account id, or null to fall through to the shared one.
     *
     * Returns null rather than throwing in every "not applicable" case --
     * shared mode, a non-party role, a line with no party. A missing party
     * account must never stop a document from confirming, which is why the
     * fall-through exists at all.
     */
    public function resolve(AccountRole $role, ?string $partyType, ?int $partyId, ?string $partyName = null): ?int
    {
        if ($partyType === null || $partyId === null) {
            return null;
        }

        if ((self::PARTY_ROLES[$partyType] ?? null) !== $role) {
            return null;
        }

        if ($this->modeFor($partyType) !== PartyLedgerMode::PerParty) {
            return null;
        }

        $existing = LedgerAccount::where('party_type', $partyType)
            ->where('party_id', $partyId)
            ->first(['id', 'is_active']);

        if ($existing !== null) {
            // An inactive party account is a deliberate act -- someone closed
            // it. Falling back to the shared account would quietly undo that
            // decision, so refuse instead and say why.
            if (! $existing->is_active) {
                throw ValidationException::withMessages([
                    'lines' => 'The ledger account for this ' . $partyType . ' is inactive, so nothing '
                        . 'can be posted to it. Reactivate it, or switch this side back to a shared account.',
                ]);
            }

            return (int) $existing->id;
        }

        return $this->create($role, $partyType, $partyId, $partyName);
    }

    /**
     * Create the party's account ahead of any posting, if it should have one.
     *
     * Unlike resolve(), an existing account is left exactly as it is: nothing
     * is being posted here, so an inactive one is not an error. withTrashed()
     * because the unique index on (party_type, party_id) still counts an
     * account someone deleted from the chart.
     *
     * @throws ValidationException when the account cannot be created.
     */
    public function provision(string $partyType, int $partyId, ?string $partyName = null): void
    {
        $role = self::PARTY_ROLES[$partyType] ?? null;

        if ($role === null || $this->modeFor($partyType) !== PartyLedgerMode::PerParty) {
            return;
        }

        if (LedgerAccount::withTrashed()->forParty($partyType, $partyId)->exists()) {
            return;
        }

        $this->create($role, $partyType, $partyId, $partyName);
    }

    /**
     * Create the party's account under the shared role account's parent.
     *
     * The NAME is a best effort and intentionally editable afterwards: the code
     * is immutable but the name is not, and account names are never snapshotted
     * onto journal lines, so correcting a typo later propagates to every
     * historical report automatically.
     */
    private function create(AccountRole $role, string $partyType, int $partyId, ?string $partyName): int
    {
        $shared = AccountDefault::with('ledgerAccount')
            ->where('rule_key', $role->value)
            ->whereNull('company_id')
            ->first();

        $control = $shared?->ledgerAccount?->control_account_id;

        if ($control === null) {
            throw ValidationException::withMessages([
                'lines' => 'Per-party accounts are switched on, but ' . $role->label() . ' is not mapped, '
                    . 'so there is no control account to create the new account under. Map it on the '
                    . 'Account Mapping screen first.',
            ]);
        }

        return (int) $this->ledgers->createForParty(
            controlAccountId: (int) $control,
            name:             $partyName ?: ucfirst($partyType) . ' #' . $partyId,
            partyType:        $partyType,
            partyId:          $partyId,
        )->id;
    }
}
