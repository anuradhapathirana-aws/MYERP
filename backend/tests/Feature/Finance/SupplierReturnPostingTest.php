<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Services\SettingsService;
use App\Support\Journal\PostingRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Database\Seeders\FinanceChartOfAccountsSeeder;
use Modules\Finance\Database\Seeders\FinanceGlAccountsSeeder;
use Modules\Finance\Enums\AccountRole;
use Modules\Finance\Models\AccountDefault;
use Modules\Finance\Models\JournalEntry;
use Modules\Finance\Services\JournalPostingService;
use Modules\Inventory\Enums\CreditNoteStatus;
use Modules\Inventory\Enums\CreditNoteType;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\SupplierCreditNote;
use Modules\Inventory\Models\SupplierReturn;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Inventory\Concerns\VariantFixtures;
use Tests\TestCase;

/**
 * A confirmed supplier return runs the GRN's journal backwards for the returned
 * quantity: Dr Accounts Payable (what the GRN still owed) and Supplier Advances
 * (the rest, held as a credit note), Cr Purchase Returns and Input Tax.
 */
class SupplierReturnPostingTest extends TestCase
{
    use RefreshDatabase;
    use VariantFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootVariantWorld();

        app(SettingsService::class)->set('module.finance', true);
        app(SettingsService::class)->set(
            JournalPostingService::SETTING_POSTING_MODE,
            JournalPostingService::MODE_IMMEDIATE,
        );

        $permissions = [
            'view_supplier_returns', 'create_supplier_returns', 'confirm_supplier_returns',
            'view_supplier_payments', 'create_supplier_payments', 'confirm_supplier_payments',
        ];
        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $this->user->update(['active_modules' => ['inventory', 'finance']]);
        $this->user->givePermissionTo($permissions);

        $this->seed(FinanceChartOfAccountsSeeder::class);
        $this->seed(FinanceGlAccountsSeeder::class);
    }

    public function test_a_return_on_an_unpaid_grn_clears_payables_and_reverses_tax(): void
    {
        // 10 x 100 + 10% tax = 1100
        [$grnId, $itemId] = $this->receivedGrn(10, 100, 10);

        $return = $this->confirmedReturn($grnId, $itemId, 3);

        $this->assertSame([
            AccountRole::AccountsPayable->value     => 330.0,
            AccountRole::PurchaseReturns->value     => -300.0,
            AccountRole::InputTaxRecoverable->value => -30.0,
        ], $this->journalFor($return));

        $apLine = JournalEntry::forSource(PostingRole::SOURCE_SUPPLIER_RETURN, $return->id)->sole()
            ->lines()->where('ledger_account_id', $this->mapped(AccountRole::AccountsPayable))->sole();
        $this->assertSame(PostingRole::SOURCE_GRN, $apLine->source_type);
        $this->assertSame($grnId, (int) $apLine->source_id);
    }

    public function test_a_return_on_a_paid_grn_is_held_as_a_supplier_advance_and_a_payment_releases_it(): void
    {
        [$paidGrn, $itemId] = $this->receivedGrn(10, 100);
        [$openGrn]          = $this->receivedGrn(5, 100);

        // Settle the first GRN entirely with an advance already held by the supplier.
        $advance = $this->advanceNote(1000);
        $this->confirmPayment($paidGrn, [['setoff_type' => 'advance', 'credit_note_id' => $advance->id, 'amount' => 1000]]);

        $return = $this->confirmedReturn($paidGrn, $itemId, 3);

        $this->assertSame([
            AccountRole::SupplierAdvance->value => 300.0,
            AccountRole::PurchaseReturns->value => -300.0,
        ], $this->journalFor($return));

        // The return's credit note, topped up by another advance, pays the second GRN.
        $note  = SupplierCreditNote::where('source_return_id', $return->id)->sole();
        $topUp = $this->advanceNote(200);

        $payment = $this->confirmPayment($openGrn, [
            ['setoff_type' => 'supplier_return', 'credit_note_id' => $note->id,  'amount' => 300],
            ['setoff_type' => 'advance',         'credit_note_id' => $topUp->id, 'amount' => 200],
        ]);

        $lines = $this->journalFor($payment, PostingRole::SOURCE_SUPPLIER_PAYMENT);
        $this->assertSame(500.0, $lines[AccountRole::AccountsPayable->value]);
        $this->assertSame(-500.0, $lines[AccountRole::SupplierAdvance->value]);
    }

    public function test_a_grn_confirmed_before_finance_was_switched_on_posts_no_return_journal(): void
    {
        app(SettingsService::class)->set('module.finance', false);
        [$grnId, $itemId] = $this->receivedGrn(10, 100);
        app(SettingsService::class)->set('module.finance', true);

        $return = $this->confirmedReturn($grnId, $itemId, 2);

        $this->assertSame(200.0, (float) $return->applied_to_grn);
        $this->assertFalse(JournalEntry::forSource(PostingRole::SOURCE_SUPPLIER_RETURN, $return->id)->exists());
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** @return array{0: int, 1: int} [grnId, grnItemId] */
    private function receivedGrn(float $qty, float $price, float $tax = 0): array
    {
        static $seq = 0;
        $seq++;

        $product = Product::factory()->quantityTracked()->create();
        $grnId   = $this->receive([$this->grnLine($product, $qty, null, $price, ['tax' => $tax])], "SHIP-P{$seq}");

        return [$grnId, (int) DB::table('inv_goods_received_note_items')->where('grn_id', $grnId)->value('id')];
    }

    private function confirmedReturn(int $grnId, int $itemId, float $qty): SupplierReturn
    {
        $id = $this->actingAs($this->user)->postJson('/api/v1/supplier-returns', [
            'return_date' => now()->toDateString(),
            'supplier_id' => $this->supplierId,
            'grn_id'      => $grnId,
            'store_id'    => $this->storeId,
            'items'       => [['grn_item_id' => $itemId, 'quantity' => $qty, 'reason' => 'damaged']],
        ])->assertCreated()->json('data.id');

        $this->actingAs($this->user)->postJson("/api/v1/supplier-returns/{$id}/confirm")->assertOk();

        return SupplierReturn::findOrFail($id);
    }

    /** An open advance note, as a confirmed advance payment would have left it. */
    private function advanceNote(float $amount): SupplierCreditNote
    {
        static $seq = 0;
        $seq++;

        return SupplierCreditNote::create([
            'credit_note_no'    => "CN-ADV{$seq}",
            'supplier_id'       => $this->supplierId,
            'credit_type'       => CreditNoteType::Advance,
            'amount'            => $amount,
            'remaining_balance' => $amount,
            'status'            => CreditNoteStatus::Open,
        ]);
    }

    /** @param array<int, array<string, mixed>> $setoffs */
    private function confirmPayment(int $grnId, array $setoffs): \Modules\Inventory\Models\SupplierPayment
    {
        $id = $this->actingAs($this->user)->postJson('/api/v1/supplier-payments', [
            'payment_date' => now()->toDateString(),
            'supplier_id'  => $this->supplierId,
            'is_advance'   => false,
            'allocations'  => [['reference_type' => 'grn', 'reference_id' => $grnId]],
            'setoffs'      => $setoffs,
            'settlements'  => [],
        ])->assertCreated()->json('data.id');

        $this->actingAs($this->user)->postJson("/api/v1/supplier-payments/{$id}/confirm")->assertOk();

        return \Modules\Inventory\Models\SupplierPayment::findOrFail($id);
    }

    /**
     * Debit minus credit per role on the document's journal.
     *
     * @return array<string, float>
     */
    private function journalFor(object $document, string $sourceType = PostingRole::SOURCE_SUPPLIER_RETURN): array
    {
        $roleByAccount = AccountDefault::whereNull('company_id')->pluck('rule_key', 'ledger_account_id');

        return JournalEntry::forSource($sourceType, (int) $document->id)->sole()
            ->lines
            ->groupBy(fn ($line) => $roleByAccount[$line->ledger_account_id] ?? (string) $line->ledger_account_id)
            ->map(fn ($lines) => round((float) $lines->sum(fn ($l) => (float) $l->debit - (float) $l->credit), 2))
            ->all();
    }

    private function mapped(AccountRole $role): int
    {
        return (int) AccountDefault::where('rule_key', $role->value)->value('ledger_account_id');
    }
}
