<?php

declare(strict_types=1);

namespace Modules\Finance\Enums;

/**
 * How party balances are held in the chart of accounts.
 *
 * Set per side, because the right answer often differs: a client with 15
 * suppliers and 4,000 walk-in customers wants a ledger per supplier and one
 * shared Accounts Receivable, since 4,000 accounts would make the chart
 * unusable while party_id already gives ageing per customer.
 *
 * ── Switching is a cut-over, not a rewrite ──────────────────────────────────
 * fin_journal_lines.party_id is written in BOTH modes, deliberately. That is
 * what keeps the two interoperable: Aged Receivables groups by party, so it
 * reads identically whether a balance sits in one shared account or twenty
 * separate ones. Old journals keep pointing at the shared account and new ones
 * at party accounts; a mixed state is correct and expected. Only the BALANCE
 * needs moving, with one transfer voucher, and only if someone wants each
 * party's own ledger to show its full history.
 *
 * Drop party_id in per-party mode and that reversibility would be gone
 * forever, along with every report that depends on it.
 */
enum PartyLedgerMode: string
{
    /** One shared control account; the party lives on the journal line. */
    case Shared = 'shared';

    /** One ledger account per party, under that same control account. */
    case PerParty = 'per_party';

    public function label(): string
    {
        return match ($this) {
            self::Shared   => 'One shared account',
            self::PerParty => 'One account per party',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Shared => 'Every supplier or customer posts to a single control account, '
                . 'and is told apart by the party recorded on each journal line. This is how '
                . 'SAP, Oracle and Odoo work, and it keeps the chart of accounts small.',
            self::PerParty => 'Each supplier or customer gets its own ledger account under the '
                . 'same control account, created automatically the first time they are posted to. '
                . 'This is how Tally works, so every party balance is a ledger you can open directly.',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return list<array{value:string,label:string,description:string}> */
    public static function options(): array
    {
        return array_map(fn (self $m): array => [
            'value'       => $m->value,
            'label'       => $m->label(),
            'description' => $m->description(),
        ], self::cases());
    }
}
