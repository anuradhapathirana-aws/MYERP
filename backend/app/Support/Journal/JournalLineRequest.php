<?php

declare(strict_types=1);

namespace App\Support\Journal;

use InvalidArgumentException;

/**
 * One side of a posting: a debit OR a credit, never both.
 *
 * Lives in core, not in Finance, because Inventory has to build these and
 * Inventory must not reference the Finance module. For the same reason $role
 * is a plain STRING here rather than Modules\Finance\Enums\AccountRole — core
 * cannot depend on Finance either. Finance resolves the string to its enum
 * when it posts, and an unknown role fails loudly there.
 *
 * ── Role versus explicit account ─────────────────────────────────────────────
 * A line names its account one of two ways:
 *
 *   debit('accounts_payable', 100000)   a ROLE, which Finance maps through
 *                                       fin_account_defaults
 *   credit(37, 60000)                   an explicit ledger account id, for the
 *                                       bank account a user picked on screen
 *
 * The role form is what keeps Inventory from reading fin_ledger_accounts. The
 * explicit form exists because the system genuinely cannot know which of two
 * bank accounts the money left — only the person paying knows that.
 *
 * The two named constructors are the only way to build a line, so a row with
 * both a debit and a credit, or with neither, cannot be expressed.
 *
 * ── Why a line can carry the party's NAME ────────────────────────────────────
 * $partyName is a display hint, never a stored value. When an installation
 * keeps one ledger account per supplier or customer, Finance has to NAME the
 * account it creates -- and it cannot look the name up, because
 * inv_customer_masters belongs to Inventory and Finance must run without it.
 *
 * The document already knows the name, so it simply passes it. That is the
 * whole point of this port: data flows core-wards, never sideways. Omit it and
 * the account is still created, just named "Customer #4" until renamed.
 */
final class JournalLineRequest
{
    private function __construct(
        public readonly ?string $role,
        public readonly ?int    $ledgerAccountId,
        public readonly float   $debit,
        public readonly float   $credit,
        public readonly ?string $description,
        public readonly ?string $partyType,
        public readonly ?int    $partyId,
        public readonly ?string $partyName,
        public readonly ?string $sourceType,
        public readonly ?int    $sourceId,
        public readonly ?int    $companyId,
        public readonly ?int    $locationId,
    ) {}

    /**
     * @param string|int $account A role key (string) or a ledger account id (int).
     */
    public static function debit(
        string|int $account,
        float      $amount,
        ?string    $description = null,
        ?string    $partyType   = null,
        ?int       $partyId     = null,
        ?string    $sourceType  = null,
        ?string    $partyName   = null,
        ?int       $sourceId    = null,
        ?int       $companyId   = null,
        ?int       $locationId  = null,
    ): self {
        return self::make($account, $amount, 0.0, $description, $partyType, $partyId, $partyName, $sourceType, $sourceId, $companyId, $locationId);
    }

    /**
     * @param string|int $account A role key (string) or a ledger account id (int).
     */
    public static function credit(
        string|int $account,
        float      $amount,
        ?string    $description = null,
        ?string    $partyType   = null,
        ?int       $partyId     = null,
        ?string    $sourceType  = null,
        ?string    $partyName   = null,
        ?int       $sourceId    = null,
        ?int       $companyId   = null,
        ?int       $locationId  = null,
    ): self {
        return self::make($account, 0.0, $amount, $description, $partyType, $partyId, $partyName, $sourceType, $sourceId, $companyId, $locationId);
    }

    private static function make(
        string|int $account,
        float      $debit,
        float      $credit,
        ?string    $description,
        ?string    $partyType,
        ?int       $partyId,
        ?string    $partyName,
        ?string    $sourceType,
        ?int       $sourceId,
        ?int       $companyId,
        ?int       $locationId,
    ): self {
        // Rounded to cents at the boundary. Money is stored at 4 decimals but
        // every existing decision in the payment services rounds to 2 with a
        // 1c tolerance, so posting 4dp values would drift the ledger away from
        // the documents it is meant to mirror. Residue goes to the rounding
        // account, which JournalPostingService handles.
        $debit  = round($debit, 2);
        $credit = round($credit, 2);

        if ($debit < 0 || $credit < 0) {
            throw new InvalidArgumentException(
                'A journal line amount cannot be negative. Swap the debit and credit instead.',
            );
        }

        if ($debit === 0.0 && $credit === 0.0) {
            throw new InvalidArgumentException('A journal line must carry a non-zero debit or credit.');
        }

        if (is_string($account) && trim($account) === '') {
            throw new InvalidArgumentException('A journal line needs either a role key or a ledger account id.');
        }

        return new self(
            role:            is_string($account) ? $account : null,
            ledgerAccountId: is_int($account) ? $account : null,
            debit:           $debit,
            credit:          $credit,
            description:     $description,
            partyType:       $partyType,
            partyId:         $partyId,
            partyName:       $partyName === null ? null : (trim($partyName) ?: null),
            sourceType:      $sourceType,
            sourceId:        $sourceId,
            companyId:       $companyId,
            locationId:      $locationId,
        );
    }

    /** Signed value, positive for a debit. Used only for balance arithmetic. */
    public function signedAmount(): float
    {
        return $this->debit - $this->credit;
    }
}
