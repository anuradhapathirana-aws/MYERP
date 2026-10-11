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
 * ── Created lazily, on first posting ────────────────────────────────────────
 * Not when a supplier or customer is saved, for two reasons.
 *
 * The decisive one is the Golden Rule: provisioning at save time would mean
 * Inventory's CustomerService calling into Finance, and Inventory has to run
 * with Finance uninstalled. Creating the account at POSTING time keeps the
 * whole feature inside Finance -- not one line of Inventory changes.
 *
 * The happy accident is that it is also better behaviour. A party who has
 * never transacted gets no account, so the chart stays as small as the
 * business actually is. Import 4,000 customers and the chart does not grow by
 * 4,000 rows; it grows as they trade.
 *
 * It is safe inside the document's transaction: the code generator takes
 * lockForUpdate(), and if the document later fails to post, the account
 * creation rolls back with it.
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
