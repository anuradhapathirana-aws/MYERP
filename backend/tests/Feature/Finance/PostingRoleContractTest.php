<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Support\Journal\PostingRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Finance\Database\Seeders\FinanceChartOfAccountsSeeder;
use Modules\Finance\Database\Seeders\FinanceGlAccountsSeeder;
use Modules\Finance\Enums\AccountRole;
use Modules\Finance\Models\AccountDefault;
use Tests\TestCase;

/**
 * The contract across the module boundary.
 *
 * Inventory names accounts with App\Support\Journal\PostingRole (core strings).
 * Finance resolves them with Modules\Finance\Enums\AccountRole. Neither can see
 * the other, which is the whole point -- and also the hazard: the two lists are
 * maintained by hand in separate modules, so they can drift silently.
 *
 * A drift does not fail at compile time. It fails at 11pm when a document
 * refuses to confirm because a role resolves to nothing, or -- worse -- it
 * posts somewhere unintended. These tests make the drift fail here instead.
 */
class PostingRoleContractTest extends TestCase
{
    use RefreshDatabase;

    /** Every role Inventory can ask for must be a role Finance knows. */
    public function test_every_posting_role_has_a_matching_account_role(): void
    {
        $orphans = array_diff(PostingRole::all(), AccountRole::values());

        $this->assertSame(
            [],
            array_values($orphans),
            'These core role keys have no AccountRole case, so Inventory could '
            . 'name an account Finance cannot resolve: ' . implode(', ', $orphans),
        );
    }

    /** And nothing Finance understands is unreachable from core. */
    public function test_every_account_role_is_reachable_from_core(): void
    {
        $unreachable = array_diff(AccountRole::values(), PostingRole::all());

        $this->assertSame(
            [],
            array_values($unreachable),
            'These AccountRole cases have no PostingRole constant, so no '
            . 'document outside Finance can ever post to them: ' . implode(', ', $unreachable),
        );
    }

    /**
     * The seeder must cover every role it claims to.
     *
     * A role that exists but is never mapped is a 422 waiting for whichever
     * document happens to need it first. Deliberate exceptions are listed
     * explicitly rather than skipped silently.
     */
    public function test_the_seeder_maps_every_required_role(): void
    {
        $this->seed(FinanceChartOfAccountsSeeder::class);
        $this->seed(FinanceGlAccountsSeeder::class);

        $mapped = AccountDefault::pluck('rule_key')
            ->map(fn ($k): string => $k instanceof AccountRole ? $k->value : (string) $k)
            ->all();

        $required = array_values(array_map(
            fn (AccountRole $r): string => $r->value,
            array_filter(AccountRole::cases(), fn (AccountRole $r): bool => $r->isRequired()),
        ));

        $missing = array_diff($required, $mapped);

        $this->assertSame(
            [],
            array_values($missing),
            'Required roles left unmapped by the seeder: ' . implode(', ', $missing),
        );
    }

    /**
     * Each mapped account must be of a type its role accepts.
     *
     * The API blocks a bad mapping at save time, but the seeder writes straight
     * to the table. This catches a starter chart that drifts away from the
     * roles -- renaming or re-parenting an account the seeder adopts by name.
     */
    public function test_every_seeded_mapping_respects_its_type_guard(): void
    {
        $this->seed(FinanceChartOfAccountsSeeder::class);
        $this->seed(FinanceGlAccountsSeeder::class);

        $defaults = AccountDefault::with('ledgerAccount.controlAccount.category')->get();

        $this->assertNotEmpty($defaults, 'The seeder wrote nothing at all.');

        foreach ($defaults as $default) {
            $role = $default->rule_key instanceof AccountRole
                ? $default->rule_key
                : AccountRole::from((string) $default->rule_key);

            $type = $default->ledgerAccount?->controlAccount?->category?->account_type;

            $this->assertNotNull($type, "{$role->value} is mapped to an account with no category.");

            $this->assertContains(
                $type,
                $role->acceptedTypes(),
                sprintf(
                    '%s is mapped to "%s", which is a %s account. %s accepts only: %s.',
                    $role->label(),
                    $default->ledgerAccount->ledger_account_name,
                    $type->value,
                    $role->label(),
                    implode(', ', array_map(fn ($t): string => $t->value, $role->acceptedTypes())),
                ),
            );
        }
    }

    /** The party and source vocabularies are contracts too. */
    public function test_party_and_source_types_fit_their_columns(): void
    {
        // party_type is varchar(20), source_type varchar(40). A longer constant
        // would be silently truncated by MySQL and then never match on read.
        foreach ([PostingRole::PARTY_SUPPLIER, PostingRole::PARTY_CUSTOMER, PostingRole::PARTY_EMPLOYEE] as $party) {
            $this->assertLessThanOrEqual(20, strlen($party), "party_type '{$party}' exceeds its column.");
        }

        foreach ([
            PostingRole::SOURCE_GRN,
            PostingRole::SOURCE_INVOICE,
            PostingRole::SOURCE_SUPPLIER_PAYMENT,
            PostingRole::SOURCE_CUSTOMER_RECEIPT,
        ] as $source) {
            $this->assertLessThanOrEqual(40, strlen($source), "source_type '{$source}' exceeds its column.");
        }
    }
}
