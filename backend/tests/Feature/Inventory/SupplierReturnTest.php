<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\PaymentMode;
use App\Models\TaxCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Enums\CreditNoteStatus;
use Modules\Inventory\Enums\CreditNoteType;
use Modules\Inventory\Models\GrnItemPiece;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\ProductLocationStore;
use Modules\Inventory\Models\StockReferenceType;
use Modules\Inventory\Models\StockTransaction;
use Modules\Inventory\Models\SupplierCreditNote;
use Modules\Inventory\Models\SupplierReturn;
use Modules\Inventory\Services\GrnBalanceService;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Inventory\Concerns\VariantFixtures;
use Tests\TestCase;

/**
 * Supplier returns: pick a supplier's confirmed GRN, send back part of what it received
 * (whole or partial rolls, or a typed quantity). Confirm takes the stock out and debits
 * the supplier — against the GRN's outstanding first, any excess as an open
 * supplier_return credit note for a later supplier payment to set off.
 */
class SupplierReturnTest extends TestCase
{
    use RefreshDatabase;
    use VariantFixtures;

    private const RETURN_PERMISSIONS = [
        'view_supplier_returns', 'create_supplier_returns', 'edit_supplier_returns',
        'delete_supplier_returns', 'confirm_supplier_returns',
    ];

    private const PAYMENT_PERMISSIONS = [
        'view_supplier_payments', 'create_supplier_payments', 'edit_supplier_payments', 'confirm_supplier_payments',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootVariantWorld();

        foreach ([...self::RETURN_PERMISSIONS, ...self::PAYMENT_PERMISSIONS] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $this->user->givePermissionTo([...self::RETURN_PERMISSIONS, ...self::PAYMENT_PERMISSIONS]);
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    /** A quantity-tracked product received on a confirmed GRN. */
    private function receivedQuantity(float $qty = 10, float $price = 100, ?string $taxCode = null): array
    {
        $product = Product::factory()->quantityTracked()->create();
        $extra   = $taxCode !== null ? ['tax_code_id' => TaxCode::where('code', $taxCode)->value('id')] : [];
        $grnId   = $this->receive([$this->grnLine($product, $qty, null, $price, $extra)], $this->shippingCode());

        return [$product, $grnId, $this->grnItemId($grnId)];
    }

    /**
     * A roll-tracked product received as the given rolls.
     *
     * @param  array<float> $weights
     * @return array{0: Product, 1: int, 2: int, 3: array<GrnItemPiece>}
     */
    private function receivedRolls(array $weights, float $price = 10): array
    {
        $product = Product::factory()->create(['stock_tracking' => 'roll']);
        $rolls   = array_map(fn (float $w, int $i) => ['roll_no' => 'R' . ($i + 1), 'weight' => $w], $weights, array_keys($weights));

        $grnId = $this->receive([$this->grnLine($product, array_sum($weights), null, $price, ['rolls' => $rolls])], $this->shippingCode());

        return [
            $product,
            $grnId,
            $this->grnItemId($grnId),
            GrnItemPiece::where('grn_id', $grnId)->orderBy('id')->get()->all(),
        ];
    }

    /** GRN shipping codes are unique, so every fixture GRN gets its own. */
    private function shippingCode(): string
    {
        static $seq = 0;

        return 'SHIP-R' . ++$seq;
    }

    private function grnItemId(int $grnId): int
    {
        return (int) DB::table('inv_goods_received_note_items')->where('grn_id', $grnId)->value('id');
    }

    /** @param array<int, array<string, mixed>> $items */
    private function payload(int $grnId, array $items, array $overrides = []): array
    {
        return array_merge([
            'return_date' => now()->toDateString(),
            'supplier_id' => $this->supplierId,
            'grn_id'      => $grnId,
            'store_id'    => $this->storeId,
            'remarks'     => 'Sent back',
            'items'       => $items,
        ], $overrides);
    }

    private function createReturn(array $payload): SupplierReturn
    {
        $id = $this->actingAs($this->user)
            ->postJson('/api/v1/supplier-returns', $payload)
            ->assertCreated()
            ->json('data.id');

        return SupplierReturn::findOrFail($id);
    }

    private function confirm(SupplierReturn $return): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->postJson("/api/v1/supplier-returns/{$return->id}/confirm");
    }

    private function stockAt(Product $product): float
    {
        return (float) ProductLocationStore::where('product_id', $product->id)
            ->where('store_id', $this->storeId)
            ->value('current_stock');
    }

    private function outstanding(int $grnId): float
    {
        return round(app(GrnBalanceService::class)->outstanding($grnId), 2);
    }

    // ── Access ──────────────────────────────────────────────────────────────

    public function test_returns_need_the_supplier_return_permissions(): void
    {
        $outsider = User::factory()->create(['active_modules' => ['inventory']]);

        $this->actingAs($outsider)->getJson('/api/v1/supplier-returns')->assertForbidden();
        $this->actingAs($outsider)->postJson('/api/v1/supplier-returns', [])->assertForbidden();
    }

    // ── Picking the GRN ─────────────────────────────────────────────────────

    public function test_returnable_grns_and_items_show_what_can_still_go_back(): void
    {
        [, $grnId, $itemId] = $this->receivedQuantity(10, 100);

        $this->actingAs($this->user)
            ->getJson("/api/v1/supplier-returns/returnable-grns/{$this->supplierId}")
            ->assertOk()
            ->assertJsonPath('data.0.grn_id', $grnId)
            ->assertJsonPath('data.0.outstanding', 1000);

        $this->actingAs($this->user)
            ->getJson("/api/v1/supplier-returns/returnable-items/{$grnId}")
            ->assertOk()
            ->assertJsonPath('data.grn.default_store_id', $this->storeId)
            ->assertJsonPath('data.items.0.grn_item_id', $itemId)
            ->assertJsonPath('data.items.0.is_scanned', false)
            ->assertJsonPath('data.items.0.returnable_qty', 10);
    }

    public function test_a_fully_returned_grn_drops_off_the_picker(): void
    {
        [, $grnId, $itemId] = $this->receivedQuantity(4, 100);

        $return = $this->createReturn($this->payload($grnId, [['grn_item_id' => $itemId, 'quantity' => 4, 'reason' => 'damaged']]));
        $this->confirm($return)->assertOk();

        $this->actingAs($this->user)
            ->getJson("/api/v1/supplier-returns/returnable-grns/{$this->supplierId}")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // ── Drafts ──────────────────────────────────────────────────────────────

    public function test_a_draft_is_numbered_priced_off_the_grn_and_moves_nothing(): void
    {
        [$product, $grnId, $itemId] = $this->receivedQuantity(10, 100, taxCode: 'VAT-IN');

        $return = $this->createReturn($this->payload($grnId, [['grn_item_id' => $itemId, 'quantity' => 3, 'reason' => 'damaged']]));

        $this->assertSame('PRN-0001', $return->return_no);
        $this->assertSame('draft', $return->status->value);
        // 3 of 10 received at 1180 (1000 + 18% VAT) = 354
        $this->assertSame(354.0, (float) $return->total_amount);
        $this->assertSame(10.0, $this->stockAt($product));
        $this->assertSame(1180.0, $this->outstanding($grnId));
    }

    public function test_a_draft_can_be_edited_and_deleted_but_a_confirmed_return_cannot(): void
    {
        [, $grnId, $itemId] = $this->receivedQuantity();

        $return = $this->createReturn($this->payload($grnId, [['grn_item_id' => $itemId, 'quantity' => 2, 'reason' => 'damaged']]));

        $this->actingAs($this->user)
            ->putJson("/api/v1/supplier-returns/{$return->id}", $this->payload($grnId, [['grn_item_id' => $itemId, 'quantity' => 5, 'reason' => 'excess']]))
            ->assertOk()
            ->assertJsonPath('data.items.0.quantity', 5)
            ->assertJsonPath('data.items.0.reason', 'excess');

        $this->confirm($return)->assertOk();

        $this->actingAs($this->user)
            ->putJson("/api/v1/supplier-returns/{$return->id}", $this->payload($grnId, [['grn_item_id' => $itemId, 'quantity' => 1, 'reason' => 'damaged']]))
            ->assertStatus(422);
        $this->actingAs($this->user)->deleteJson("/api/v1/supplier-returns/{$return->id}")->assertStatus(422);

        $draft = $this->createReturn($this->payload($grnId, [['grn_item_id' => $itemId, 'quantity' => 1, 'reason' => 'damaged']]));
        $this->actingAs($this->user)->deleteJson("/api/v1/supplier-returns/{$draft->id}")->assertNoContent();
        $this->assertSoftDeleted('inv_supplier_returns', ['id' => $draft->id]);
    }

    // ── Validation ──────────────────────────────────────────────────────────

    public function test_header_and_lines_are_validated(): void
    {
        [, $grnId, $itemId] = $this->receivedQuantity();

        $this->actingAs($this->user)
            ->postJson('/api/v1/supplier-returns', $this->payload($grnId, [], ['return_date' => now()->addDay()->toDateString()]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['return_date', 'items']);

        $this->actingAs($this->user)
            ->postJson('/api/v1/supplier-returns', $this->payload($grnId, [['grn_item_id' => $itemId, 'quantity' => 0, 'reason' => 'nonsense']]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.quantity', 'items.0.reason']);
    }

    public function test_a_grn_of_another_supplier_or_a_date_before_the_grn_is_refused(): void
    {
        [, $grnId, $itemId] = $this->receivedQuantity();

        $otherSupplier = DB::table('inv_supplier_masters')->insertGetId([
            'supplier_code' => 'SUP-0002', 'supplier_name' => 'Other', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $line = [['grn_item_id' => $itemId, 'quantity' => 1, 'reason' => 'damaged']];

        $this->actingAs($this->user)
            ->postJson('/api/v1/supplier-returns', $this->payload($grnId, $line, ['supplier_id' => $otherSupplier]))
            ->assertStatus(422);

        $this->actingAs($this->user)
            ->postJson('/api/v1/supplier-returns', $this->payload($grnId, $line, ['return_date' => '2026-01-01']))
            ->assertStatus(422);
    }

    public function test_more_than_is_still_returnable_is_refused(): void
    {
        [, $grnId, $itemId] = $this->receivedQuantity(10);

        $this->actingAs($this->user)
            ->postJson('/api/v1/supplier-returns', $this->payload($grnId, [['grn_item_id' => $itemId, 'quantity' => 11, 'reason' => 'damaged']]))
            ->assertStatus(422);

        // Two drafts may each ask for 6 of 10, but only the first can be confirmed.
        $first  = $this->createReturn($this->payload($grnId, [['grn_item_id' => $itemId, 'quantity' => 6, 'reason' => 'damaged']]));
        $second = $this->createReturn($this->payload($grnId, [['grn_item_id' => $itemId, 'quantity' => 6, 'reason' => 'damaged']]));

        $this->confirm($first)->assertOk();
        $this->confirm($second)->assertStatus(422);
    }

    // ── Quantity lines ──────────────────────────────────────────────────────

    public function test_confirming_takes_the_stock_out_at_grn_cost_and_reduces_the_grn_balance(): void
    {
        [$product, $grnId, $itemId] = $this->receivedQuantity(10, 100);

        $return = $this->createReturn($this->payload($grnId, [['grn_item_id' => $itemId, 'quantity' => 4, 'reason' => 'quality_issue']]));
        $this->confirm($return)->assertOk()->assertJsonPath('data.status', 'confirmed');

        $return->refresh();
        $this->assertSame(400.0, (float) $return->applied_to_grn);
        $this->assertNull($return->credit_note_id);

        $this->assertSame(6.0, $this->stockAt($product));
        $this->assertSame(600.0, $this->outstanding($grnId));

        $txn = StockTransaction::where('reference_type', StockReferenceType::CODE_SUPPLIER_RETURN)->sole();
        $this->assertSame($return->id, (int) $txn->reference_id);
        $this->assertSame(4.0, (float) $txn->qty_out);
        $this->assertSame(100.0, (float) $txn->unit_price);

        $this->actingAs($this->user)
            ->getJson("/api/v1/supplier-returns/returnable-items/{$grnId}")
            ->assertJsonPath('data.items.0.returned_qty', 4)
            ->assertJsonPath('data.items.0.returnable_qty', 6);
    }

    public function test_stock_that_is_no_longer_there_cannot_be_returned(): void
    {
        [$product, $grnId, $itemId] = $this->receivedQuantity(10);

        // 8 of the 10 have since left the store.
        ProductLocationStore::where('product_id', $product->id)->decrement('current_stock', 8);

        $return = $this->createReturn($this->payload($grnId, [['grn_item_id' => $itemId, 'quantity' => 5, 'reason' => 'damaged']]));

        $this->confirm($return)
            ->assertStatus(422)
            ->assertJsonFragment(['message' => sprintf('Insufficient stock for %s in the selected store: available 2 %s, required 5 %s.', $product->name, $product->baseUnit?->symbol ?? $product->baseUnit?->name, $product->baseUnit?->symbol ?? $product->baseUnit?->name)]);

        $this->assertSame('draft', $return->fresh()->status->value);
    }

    // ── Rolls ───────────────────────────────────────────────────────────────

    public function test_a_whole_roll_goes_back_as_returned(): void
    {
        [$product, $grnId, $itemId, $rolls] = $this->receivedRolls([50, 40]);

        $this->actingAs($this->user)
            ->getJson("/api/v1/supplier-returns/returnable-items/{$grnId}")
            ->assertJsonPath('data.items.0.is_scanned', true)
            ->assertJsonCount(2, 'data.items.0.pieces');

        $return = $this->createReturn($this->payload($grnId, [[
            'grn_item_id' => $itemId,
            'reason'      => 'wrong_item',
            'pieces'      => [['piece_id' => $rolls[0]->id, 'quantity' => 50]],
        ]]));
        $this->confirm($return)->assertOk();

        $this->assertSame(GrnItemPiece::STATUS_RETURNED, $rolls[0]->fresh()->status);
        $this->assertSame(GrnItemPiece::STATUS_IN_STOCK, $rolls[1]->fresh()->status);
        $this->assertNull($return->pieces()->sole()->remnant_piece_id);
        $this->assertSame(40.0, $this->stockAt($product));
        // 50 of 90 received at 10 = 500
        $this->assertSame(400.0, $this->outstanding($grnId));
    }

    public function test_part_of_a_roll_goes_back_and_the_remnant_stays_in_stock(): void
    {
        [$product, $grnId, $itemId, $rolls] = $this->receivedRolls([50]);

        $return = $this->createReturn($this->payload($grnId, [[
            'grn_item_id' => $itemId,
            'reason'      => 'damaged',
            'pieces'      => [['piece_id' => $rolls[0]->id, 'quantity' => 20]],
        ]]));
        $this->confirm($return)->assertOk();

        $remnant = GrnItemPiece::findOrFail($return->pieces()->sole()->remnant_piece_id);

        $this->assertSame(GrnItemPiece::STATUS_RETURNED, $rolls[0]->fresh()->status);
        $this->assertSame(GrnItemPiece::STATUS_IN_STOCK, $remnant->status);
        $this->assertSame(30.0, (float) $remnant->weight);
        $this->assertStringEndsWith('-C1', $remnant->piece_code);
        $this->assertSame(30.0, $this->stockAt($product));

        // The remnant is itself returnable on the same GRN line.
        $this->actingAs($this->user)
            ->getJson("/api/v1/supplier-returns/returnable-items/{$grnId}")
            ->assertJsonPath('data.items.0.pieces.0.piece_id', $remnant->id)
            ->assertJsonPath('data.items.0.returnable_qty', 30);
    }

    public function test_returned_rolls_drop_off_the_piece_label_page(): void
    {
        [$product, $grnId, $itemId, $rolls] = $this->receivedRolls([50, 40]);

        $return = $this->createReturn($this->payload($grnId, [[
            'grn_item_id' => $itemId,
            'reason'      => 'damaged',
            'pieces'      => [
                ['piece_id' => $rolls[0]->id, 'quantity' => 50],
                ['piece_id' => $rolls[1]->id, 'quantity' => 10],
            ],
        ]]));
        $this->confirm($return)->assertOk();

        $remnant = GrnItemPiece::where('parent_piece_id', $rolls[1]->id)->sole();

        // Both returned rolls are gone; only the partial return's remnant is left to label.
        $this->actingAs($this->user)
            ->getJson("/api/v1/piece-labels?product_id={$product->id}")
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.labels.0.piece_code', $remnant->piece_code)
            ->assertJsonPath('data.labels.0.replaces', $rolls[1]->piece_code);
    }

    public function test_a_roll_cannot_give_back_more_than_it_holds(): void
    {
        [, $grnId, $itemId, $rolls] = $this->receivedRolls([50]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/supplier-returns', $this->payload($grnId, [[
                'grn_item_id' => $itemId,
                'reason'      => 'damaged',
                'pieces'      => [['piece_id' => $rolls[0]->id, 'quantity' => 60]],
            ]]))
            ->assertStatus(422);
    }

    public function test_a_roll_allocated_after_the_draft_was_saved_blocks_the_confirm(): void
    {
        [, $grnId, $itemId, $rolls] = $this->receivedRolls([50]);

        $return = $this->createReturn($this->payload($grnId, [[
            'grn_item_id' => $itemId,
            'reason'      => 'damaged',
            'pieces'      => [['piece_id' => $rolls[0]->id, 'quantity' => 50]],
        ]]));

        $rolls[0]->update(['status' => GrnItemPiece::STATUS_ALLOCATED]);

        $this->confirm($return)->assertStatus(422);
        $this->assertSame('draft', $return->fresh()->status->value);
        $this->assertSame(0, StockTransaction::where('reference_type', StockReferenceType::CODE_SUPPLIER_RETURN)->count());
    }

    // ── Money ───────────────────────────────────────────────────────────────

    public function test_a_return_on_a_paid_grn_becomes_a_credit_note_that_a_payment_can_set_off(): void
    {
        [, $paidGrn, $itemId] = $this->receivedQuantity(10, 100);
        [, $openGrn]          = $this->receivedQuantity(5, 100);

        $cash = PaymentMode::create(['payment_mode_name' => 'Cash', 'code' => 'cash', 'is_active' => true]);

        // Pay the first GRN in full.
        $paymentId = $this->actingAs($this->user)->postJson('/api/v1/supplier-payments', [
            'payment_date' => now()->toDateString(),
            'supplier_id'  => $this->supplierId,
            'is_advance'   => false,
            'allocations'  => [['reference_type' => 'grn', 'reference_id' => $paidGrn]],
            'setoffs'      => [],
            'settlements'  => [['payment_mode_id' => $cash->id, 'amount' => 1000]],
        ])->assertCreated()->json('data.id');
        $this->actingAs($this->user)->postJson("/api/v1/supplier-payments/{$paymentId}/confirm")->assertOk();

        $return = $this->createReturn($this->payload($paidGrn, [['grn_item_id' => $itemId, 'quantity' => 3, 'reason' => 'damaged']]));
        $this->confirm($return)->assertOk();

        $note = SupplierCreditNote::where('source_return_id', $return->id)->sole();
        $this->assertSame(CreditNoteType::SupplierReturn, $note->credit_type);
        $this->assertSame(300.0, (float) $note->remaining_balance);
        $this->assertSame(0.0, (float) $return->fresh()->applied_to_grn);
        $this->assertSame(0.0, $this->outstanding($paidGrn));

        // The note pays 300 of the second GRN's 500; cash covers the rest.
        $setoffPayment = $this->actingAs($this->user)->postJson('/api/v1/supplier-payments', [
            'payment_date' => now()->toDateString(),
            'supplier_id'  => $this->supplierId,
            'is_advance'   => false,
            'allocations'  => [['reference_type' => 'grn', 'reference_id' => $openGrn]],
            'setoffs'      => [['setoff_type' => 'supplier_return', 'credit_note_id' => $note->id, 'amount' => 300]],
            'settlements'  => [['payment_mode_id' => $cash->id, 'amount' => 200]],
        ])->assertCreated()->json('data.id');
        $this->actingAs($this->user)->postJson("/api/v1/supplier-payments/{$setoffPayment}/confirm")->assertOk();

        $note->refresh();
        $this->assertSame(0.0, (float) $note->remaining_balance);
        $this->assertSame(CreditNoteStatus::Exhausted, $note->status);
        $this->assertSame(0.0, $this->outstanding($openGrn));
    }

    public function test_the_manual_customer_return_setoff_is_no_longer_accepted(): void
    {
        [, $grnId] = $this->receivedQuantity(10, 100);

        $this->actingAs($this->user)->postJson('/api/v1/supplier-payments', [
            'payment_date' => now()->toDateString(),
            'supplier_id'  => $this->supplierId,
            'is_advance'   => false,
            'allocations'  => [['reference_type' => 'grn', 'reference_id' => $grnId]],
            'setoffs'      => [['setoff_type' => 'customer_return', 'amount' => 100, 'remark' => 'Typed by hand']],
            'settlements'  => [],
        ])->assertStatus(422)->assertJsonValidationErrors(['setoffs.0.setoff_type']);
    }

    public function test_the_payment_screen_sees_the_grn_net_of_returns(): void
    {
        [, $grnId, $itemId] = $this->receivedQuantity(10, 100);

        $return = $this->createReturn($this->payload($grnId, [['grn_item_id' => $itemId, 'quantity' => 2, 'reason' => 'damaged']]));
        $this->confirm($return)->assertOk();

        $this->actingAs($this->user)
            ->getJson("/api/v1/supplier-payments/outstanding-grns/{$this->supplierId}")
            ->assertOk()
            ->assertJsonPath('data.0.outstanding', 800);
    }

    // ── Documents ───────────────────────────────────────────────────────────

    public function test_the_grn_lists_its_returns_and_the_bin_card_names_the_return(): void
    {
        [$product, $grnId, $itemId] = $this->receivedQuantity(10, 100);

        $return = $this->createReturn($this->payload($grnId, [['grn_item_id' => $itemId, 'quantity' => 1, 'reason' => 'damaged']]));
        $this->confirm($return)->assertOk();

        $this->actingAs($this->user)
            ->getJson("/api/v1/goods-received-notes/{$grnId}")
            ->assertOk()
            ->assertJsonPath('data.returns.0.return_no', 'PRN-0001')
            ->assertJsonPath('data.returns.0.applied_to_grn', 100);

        $card = app(\Modules\Inventory\Services\BinCardService::class)->build(['product_id' => $product->id]);
        $this->assertContains('PRN-0001', collect($card['rows'])->pluck('document_no')->all());
    }

    public function test_only_a_confirmed_return_can_be_printed(): void
    {
        [, $grnId, $itemId] = $this->receivedQuantity();

        $return = $this->createReturn($this->payload($grnId, [['grn_item_id' => $itemId, 'quantity' => 1, 'reason' => 'damaged']]));

        $this->actingAs($this->user)->get("/api/v1/supplier-returns/{$return->id}/pdf")->assertStatus(422);

        $this->confirm($return)->assertOk();

        $this->actingAs($this->user)->get("/api/v1/supplier-returns/{$return->id}/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }
}
