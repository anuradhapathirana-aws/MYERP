<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\TaxCode;
use App\Services\SettingsService;
use App\Support\Journal\PostingRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Finance\Database\Seeders\FinanceChartOfAccountsSeeder;
use Modules\Finance\Database\Seeders\FinanceGlAccountsSeeder;
use Modules\Finance\Enums\AccountRole;
use Modules\Finance\Models\AccountDefault;
use Modules\Finance\Models\JournalEntry;
use Modules\Finance\Services\JournalPostingService;
use Modules\Inventory\Models\Product;
use Tests\Feature\Inventory\Concerns\VariantFixtures;
use Tests\TestCase;

/**
 * A confirmed GRN's journal follows its tax codes: claimable VAT to Input Tax
 * Recoverable, everything else — the goods, supplier SSCL, VAT without a Tax
 * Invoice — to Purchases. The payable is always the full invoice.
 */
class GrnTaxPostingTest extends TestCase
{
    use RefreshDatabase;
    use VariantFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootVariantWorld();

        app(SettingsService::class)->set('module.finance', true);
        app(SettingsService::class)->set(JournalPostingService::SETTING_POSTING_MODE, JournalPostingService::MODE_IMMEDIATE);
        $this->user->update(['active_modules' => ['inventory', 'finance']]);

        $this->seed(FinanceChartOfAccountsSeeder::class);
        $this->seed(FinanceGlAccountsSeeder::class);
    }

    public function test_vat_with_a_tax_invoice_is_claimed_as_input_tax(): void
    {
        $grnId = $this->receivedWith('VAT-IN');

        $this->assertSame([
            AccountRole::Purchases->value           => 1000.0,
            AccountRole::InputTaxRecoverable->value => 180.0,
            AccountRole::AccountsPayable->value     => -1180.0,
        ], $this->journal($grnId));
    }

    public function test_supplier_sscl_stays_in_purchases_and_only_vat_is_claimed(): void
    {
        $grnId = $this->receivedWith('SSCL-VAT-IN');

        $this->assertSame([
            AccountRole::Purchases->value           => 1025.0,
            AccountRole::InputTaxRecoverable->value => 184.5,
            AccountRole::AccountsPayable->value     => -1209.5,
        ], $this->journal($grnId));
    }

    public function test_a_vat_inclusive_invoice_posts_the_same_split(): void
    {
        $grnId = $this->receivedWith('VAT-IN', price: 118, inclusive: true);

        $this->assertSame([
            AccountRole::Purchases->value           => 1000.0,
            AccountRole::InputTaxRecoverable->value => 180.0,
            AccountRole::AccountsPayable->value     => -1180.0,
        ], $this->journal($grnId));
    }

    public function test_no_tax_invoice_and_import_post_no_tax_line(): void
    {
        foreach (['NO-TAX-INV', 'IMPORT'] as $code) {
            $this->assertSame([
                AccountRole::Purchases->value       => 1000.0,
                AccountRole::AccountsPayable->value => -1000.0,
            ], $this->journal($this->receivedWith($code)), $code);
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function receivedWith(string $code, float $price = 100, bool $inclusive = false): int
    {
        static $seq = 0;
        $seq++;

        $product = Product::factory()->quantityTracked()->create();
        $payload = $this->grnPayload([
            $this->grnLine($product, 10, null, $price, ['tax_code_id' => TaxCode::where('code', $code)->value('id')]),
        ], "SHIP-T{$seq}") + ['prices_include_tax' => $inclusive];

        $id = $this->actingAs($this->user)->postJson('/api/v1/goods-received-notes', $payload)->assertCreated()->json('data.id');
        $this->actingAs($this->user)->postJson("/api/v1/goods-received-notes/{$id}/confirm")->assertOk();

        return (int) $id;
    }

    /** @return array<string, float> role => debit minus credit */
    private function journal(int $grnId): array
    {
        $roleByAccount = AccountDefault::whereNull('company_id')->pluck('rule_key', 'ledger_account_id');

        return JournalEntry::forSource(PostingRole::SOURCE_GRN, $grnId)->sole()
            ->lines
            ->groupBy(fn ($line) => $roleByAccount[$line->ledger_account_id] ?? (string) $line->ledger_account_id)
            ->map(fn ($lines) => round((float) $lines->sum(fn ($l) => (float) $l->debit - (float) $l->credit), 2))
            ->all();
    }
}
