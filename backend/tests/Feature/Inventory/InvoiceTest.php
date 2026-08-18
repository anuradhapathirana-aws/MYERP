<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\CustomerMaster;
use Modules\Inventory\Models\GoodsReceivedNote;
use Modules\Inventory\Models\GoodsReceivedNoteItem;
use Modules\Inventory\Models\GrnItemPiece;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\SalesOrder;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class InvoiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private CustomerMaster $customer;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsService::class)->set('module.inventory', true);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'view_sales_orders', 'create_sales_orders', 'edit_sales_orders', 'delete_sales_orders',
            'view_delivery_orders', 'create_delivery_orders', 'edit_delivery_orders', 'delete_delivery_orders',
            'view_invoices', 'create_invoices', 'edit_invoices', 'delete_invoices',
        ];
        foreach ($permissions as $perm) {
            Permission::create(['name' => $perm, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create(['active_modules' => ['inventory']]);
        $this->user->givePermissionTo($permissions);

        $this->customer = CustomerMaster::create([
            'customer_code' => 'CUS-0001',
            'customer_name' => 'Test Customer',
            'customer_type' => 'Retail',
        ]);
    }

    /** @return array{so: SalesOrder, pieces: array<GrnItemPiece>} */
    private function makeConfirmedSoWithRolls(
        array $weights = [10.0, 20.0],
        float $unitPrice = 1000.0,
        float $discount = 0,
        float $transport = 0,
    ): array {
        static $seq = 0;
        $seq++;

        $product = Product::factory()->create();

        $grn = GoodsReceivedNote::create([
            'grn_no'   => sprintf('GRN-INV-%04d', $seq),
            'grn_date' => '2026-07-01',
            'status'   => 'confirmed',
        ]);
        $grnItem = GoodsReceivedNoteItem::create([
            'grn_id'            => $grn->id,
            'product_id'        => $product->id,
            'quantity_received' => array_sum($weights),
            'unit_price'        => 400,
        ]);

        $pieces = [];
        foreach ($weights as $i => $weight) {
            $pieces[] = GrnItemPiece::create([
                'grn_item_id' => $grnItem->id,
                'grn_id'      => $grn->id,
                'product_id'  => $product->id,
                'piece_no'    => $i + 1,
                'weight'      => $weight,
                'piece_code'  => sprintf('%s-I001-P%03d', $grn->grn_no, $i + 1),
                'status'      => GrnItemPiece::STATUS_IN_STOCK,
            ]);
        }

        // Simulate the GRN-posted stock balance so DO confirm passes availability
        $pivot = \Modules\Inventory\Models\ProductLocationStore::firstOrCreate(
            ['product_id' => $product->id, 'store_id' => null, 'location_id' => null],
            ['current_stock' => 0],
        );
        $pivot->increment('current_stock', array_sum($weights));

        $response = $this->actingAs($this->user)->postJson('/api/v1/sales-orders', [
            'order_date'       => '2026-07-09',
            'customer_id'      => $this->customer->id,
            'sales_person_id'  => $this->user->id,
            'status'           => 'confirmed',
            'transport_charge' => $transport,
            'items'            => [[
                'product_id'  => $product->id,
                'unit_price'  => $unitPrice,
                'discount'    => $discount,
                'piece_codes' => array_map(fn (GrnItemPiece $p) => $p->piece_code, $pieces),
            ]],
        ]);
        $response->assertCreated();

        return ['so' => SalesOrder::findOrFail($response->json('data.id')), 'pieces' => $pieces];
    }

    /** Create + confirm a DO for a subset of the SO's rolls; returns the DO id. */
    private function makeConfirmedDo(SalesOrder $so, array $pieces): int
    {
        $doId = $this->actingAs($this->user)
            ->postJson('/api/v1/delivery-orders', [
                'so_id'         => $so->id,
                'delivery_date' => '2026-07-10',
                'items'         => [[
                    'so_item_id' => $so->items()->first()->id,
                    'piece_ids'  => array_map(fn (GrnItemPiece $p) => $p->id, $pieces),
                ]],
            ])->json('data.id');

        $this->actingAs($this->user)
            ->patchJson("/api/v1/delivery-orders/{$doId}/status", ['status' => 'confirmed'])
            ->assertOk();

        return $doId;
    }

    /** Attach a confirmed costing pricing the given GRN item (all figures per base unit). */
    private function giveConfirmedCosting(int $grnItemId, float $beforeTax, float $afterTax, float $vatPct): void
    {
        $grnLine = DB::table('inv_goods_received_note_items')->where('id', $grnItemId)->first(['grn_id', 'product_id']);

        $costingId = DB::table('inv_costings')->insertGetId([
            'document_no'  => 'CST-INV-' . $grnItemId,
            'reference_no' => 'CRef-INV-' . $grnItemId,
            'supplier_id'  => 1,
            'costing_type' => 'fob',
            'status'       => 'confirmed',
            'apply_vat'    => 1,
            'vat_pct'      => $vatPct,
            'confirmed_at' => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        DB::table('inv_costing_items')->insert([
            'costing_id'            => $costingId,
            'grn_id'                => $grnLine->grn_id,
            'grn_item_id'           => $grnItemId,
            'product_id'            => $grnLine->product_id,
            'quantity'              => 30,
            'conversion_factor'     => 1,
            'base_quantity'         => 30,
            'unit_price'            => 400,
            'before_tax_price'      => $beforeTax,
            'before_tax_price_base' => $beforeTax,
            'selling_price'         => $afterTax,
            'selling_price_base'    => $afterTax,
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);
    }

    // ── Auth & permissions ──────────────────────────────────────────────────

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/invoices')->assertUnauthorized();
    }

    public function test_user_without_view_permission_is_forbidden(): void
    {
        $restricted = User::factory()->create(['active_modules' => ['inventory']]);

        $this->actingAs($restricted)
            ->getJson('/api/v1/invoices')
            ->assertForbidden();
    }

    // ── Create from DO ──────────────────────────────────────────────────────

    public function test_non_tax_invoice_from_confirmed_do_copies_qty_and_so_prices(): void
    {
        ['so' => $so, 'pieces' => $pieces] = $this->makeConfirmedSoWithRolls([10.0, 20.0], unitPrice: 1000, discount: 10, transport: 500);
        $doId = $this->makeConfirmedDo($so, $pieces);

        // Non-Tax: the SO (after-tax) price as-is, no VAT added
        $this->actingAs($this->user)
            ->postJson('/api/v1/invoices', ['do_id' => $doId, 'invoice_date' => '2026-07-11', 'invoice_type' => 'non_tax'])
            ->assertCreated()
            ->assertJsonPath('data.invoice_no', '26JUL_CUS-0001_0001')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.invoice_type', 'non_tax')
            ->assertJsonPath('data.items.0.quantity', 30)
            ->assertJsonPath('data.items.0.unit_price', 1000)
            ->assertJsonPath('data.items.0.discount', 10)
            ->assertJsonPath('data.items.0.tax', 0)
            // 30 * 1000 = 30000 gross, -10% = 27000, + transport 500 (first invoice of the SO)
            ->assertJsonPath('data.subtotal', 30000)
            ->assertJsonPath('data.transport_charge', 500)
            ->assertJsonPath('data.grand_total', 27500);
    }

    /** The invoice form's "Recall DO" picker: invoiced=0 lists only confirmed DOs still awaiting billing. */
    public function test_do_list_invoiced_filter_splits_billed_and_unbilled_dos(): void
    {
        ['so' => $soA, 'pieces' => $piecesA] = $this->makeConfirmedSoWithRolls([10.0]);
        ['so' => $soB, 'pieces' => $piecesB] = $this->makeConfirmedSoWithRolls([20.0]);
        $doA = $this->makeConfirmedDo($soA, $piecesA);
        $doB = $this->makeConfirmedDo($soB, $piecesB);

        $this->actingAs($this->user)
            ->postJson('/api/v1/invoices', ['do_id' => $doA, 'invoice_date' => '2026-07-11'])
            ->assertCreated();

        $unbilled = $this->actingAs($this->user)
            ->getJson('/api/v1/delivery-orders?status=confirmed&invoiced=0')
            ->assertOk()
            ->json('data.*.id');
        $this->assertSame([$doB], $unbilled);

        $billed = $this->actingAs($this->user)
            ->getJson('/api/v1/delivery-orders?status=confirmed&invoiced=1')
            ->assertOk()
            ->json('data.*.id');
        $this->assertSame([$doA], $billed);
    }

    public function test_tax_invoice_bills_costing_before_tax_price_plus_vat(): void
    {
        // SO priced at the costing's AFTER-tax price (1180 = 1000 + 18% VAT)
        ['so' => $so, 'pieces' => $pieces] = $this->makeConfirmedSoWithRolls([10.0, 20.0], unitPrice: 1180);
        $this->giveConfirmedCosting($pieces[0]->grn_item_id, beforeTax: 1000, afterTax: 1180, vatPct: 18);
        $doId = $this->makeConfirmedDo($so, $pieces);

        // Tax (the default): Before-Tax price per line + the costing's VAT %
        $this->actingAs($this->user)
            ->postJson('/api/v1/invoices', ['do_id' => $doId, 'invoice_date' => '2026-07-11'])
            ->assertCreated()
            ->assertJsonPath('data.invoice_type', 'tax')
            ->assertJsonPath('data.items.0.unit_price', 1000)
            ->assertJsonPath('data.items.0.tax', 18)
            // 30 × 1000 = 30000, +18% VAT = 35400 — same money as 30 × 1180
            ->assertJsonPath('data.subtotal', 30000)
            ->assertJsonPath('data.grand_total', 35400);
    }

    public function test_tax_invoice_backcomputes_price_when_rolls_are_uncosted(): void
    {
        ['so' => $so, 'pieces' => $pieces] = $this->makeConfirmedSoWithRolls([10.0, 20.0], unitPrice: 1180);
        $doId = $this->makeConfirmedDo($so, $pieces);

        // No costing: strip the default 18% out of the SO price (1180 ÷ 1.18 = 1000),
        // then the 18% Tax puts it back — the customer total stays the SO's.
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/invoices', ['do_id' => $doId, 'invoice_date' => '2026-07-11'])
            ->assertCreated()
            ->assertJsonPath('data.invoice_type', 'tax')
            ->assertJsonPath('data.items.0.tax', 18);

        $this->assertEqualsWithDelta(1000.0, (float) $response->json('data.items.0.unit_price'), 0.0001);
        $this->assertEqualsWithDelta(35400.0, (float) $response->json('data.grand_total'), 0.01);
    }

    public function test_non_tax_invoice_forces_line_tax_to_zero(): void
    {
        ['so' => $so, 'pieces' => $pieces] = $this->makeConfirmedSoWithRolls([10.0, 20.0], unitPrice: 1000);
        $doId = $this->makeConfirmedDo($so, $pieces);

        $created = $this->actingAs($this->user)
            ->postJson('/api/v1/invoices', ['do_id' => $doId, 'invoice_date' => '2026-07-11', 'invoice_type' => 'non_tax'])
            ->assertCreated();

        // Even a typed Tax % is discarded on a Non-Tax invoice
        $this->actingAs($this->user)
            ->putJson('/api/v1/invoices/' . $created->json('data.id'), [
                'invoice_date' => '2026-07-11',
                'items'        => [[
                    'so_item_id' => $created->json('data.items.0.so_item_id'),
                    'unit_price' => 1000,
                    'tax'        => 15,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.items.0.tax', 0)
            ->assertJsonPath('data.grand_total', 30000);
    }

    public function test_invoice_from_draft_do_is_rejected(): void
    {
        ['so' => $so, 'pieces' => $pieces] = $this->makeConfirmedSoWithRolls();

        $doId = $this->actingAs($this->user)
            ->postJson('/api/v1/delivery-orders', [
                'so_id'         => $so->id,
                'delivery_date' => '2026-07-10',
                'items'         => [[
                    'so_item_id' => $so->items()->first()->id,
                    'piece_ids'  => array_map(fn ($p) => $p->id, $pieces),
                ]],
            ])->json('data.id');

        $this->actingAs($this->user)
            ->postJson('/api/v1/invoices', ['do_id' => $doId, 'invoice_date' => '2026-07-11'])
            ->assertStatus(422);
    }

    public function test_second_invoice_for_same_do_is_rejected_and_cancellation_frees_it(): void
    {
        ['so' => $so, 'pieces' => $pieces] = $this->makeConfirmedSoWithRolls();
        $doId = $this->makeConfirmedDo($so, $pieces);

        $invoiceId = $this->actingAs($this->user)
            ->postJson('/api/v1/invoices', ['do_id' => $doId, 'invoice_date' => '2026-07-11'])
            ->json('data.id');

        $this->actingAs($this->user)
            ->postJson('/api/v1/invoices', ['do_id' => $doId, 'invoice_date' => '2026-07-11'])
            ->assertStatus(422);

        $this->actingAs($this->user)
            ->patchJson("/api/v1/invoices/{$invoiceId}/status", ['status' => 'cancelled'])
            ->assertOk();

        $this->actingAs($this->user)
            ->postJson('/api/v1/invoices', ['do_id' => $doId, 'invoice_date' => '2026-07-11'])
            ->assertCreated();
    }

    // ── Direct-SO invoices are retired — invoices bill confirmed DOs only ───

    public function test_direct_so_invoice_creation_is_rejected(): void
    {
        ['so' => $so] = $this->makeConfirmedSoWithRolls();

        // so_id is no longer accepted; do_id is required
        $this->actingAs($this->user)
            ->postJson('/api/v1/invoices', ['so_id' => $so->id, 'invoice_date' => '2026-07-11'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['do_id']);
    }

    /** Legacy direct invoices (created before retirement) still lock their SO's billing mode. */
    public function test_per_do_invoice_blocked_when_legacy_direct_invoice_exists(): void
    {
        ['so' => $so, 'pieces' => $pieces] = $this->makeConfirmedSoWithRolls();

        \Modules\Inventory\Models\Invoice::create([
            'invoice_no'   => 'INV-LEGACY-0001',
            'so_id'        => $so->id,
            'do_id'        => null,
            'customer_id'  => $so->customer_id,
            'invoice_date' => '2026-07-10',
            'status'       => 'draft',
            'subtotal'     => 0,
            'grand_total'  => 0,
        ]);

        $doId = $this->makeConfirmedDo($so->fresh(), $pieces);

        $this->actingAs($this->user)
            ->postJson('/api/v1/invoices', ['do_id' => $doId, 'invoice_date' => '2026-07-11'])
            ->assertStatus(422);
    }

    // ── Transport default ───────────────────────────────────────────────────

    public function test_transport_defaults_to_so_transport_on_first_invoice_only(): void
    {
        ['so' => $so, 'pieces' => $pieces] = $this->makeConfirmedSoWithRolls([10.0, 20.0], transport: 800);

        // First DO ships one roll, second DO ships the other
        $do1 = $this->makeConfirmedDo($so, [$pieces[0]]);
        $do2 = $this->makeConfirmedDo($so->fresh(), [$pieces[1]]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/invoices', ['do_id' => $do1, 'invoice_date' => '2026-07-11'])
            ->assertCreated()
            ->assertJsonPath('data.transport_charge', 800);

        $this->actingAs($this->user)
            ->postJson('/api/v1/invoices', ['do_id' => $do2, 'invoice_date' => '2026-07-12'])
            ->assertCreated()
            ->assertJsonPath('data.transport_charge', 0);
    }

    public function test_transport_override_is_respected(): void
    {
        ['so' => $so, 'pieces' => $pieces] = $this->makeConfirmedSoWithRolls(transport: 800);
        $doId = $this->makeConfirmedDo($so, $pieces);

        $this->actingAs($this->user)
            ->postJson('/api/v1/invoices', ['do_id' => $doId, 'invoice_date' => '2026-07-11', 'transport_charge' => 123])
            ->assertCreated()
            ->assertJsonPath('data.transport_charge', 123);
    }

    // ── Status transitions & immutability ───────────────────────────────────

    public function test_status_flow_draft_issued_paid_with_timestamps(): void
    {
        ['so' => $so, 'pieces' => $pieces] = $this->makeConfirmedSoWithRolls();
        $doId = $this->makeConfirmedDo($so, $pieces);

        $invoiceId = $this->actingAs($this->user)
            ->postJson('/api/v1/invoices', ['do_id' => $doId, 'invoice_date' => '2026-07-11'])
            ->json('data.id');

        // draft → paid is illegal
        $this->actingAs($this->user)
            ->patchJson("/api/v1/invoices/{$invoiceId}/status", ['status' => 'paid'])
            ->assertStatus(422);

        $issued = $this->actingAs($this->user)
            ->patchJson("/api/v1/invoices/{$invoiceId}/status", ['status' => 'issued'])
            ->assertOk();
        $this->assertNotNull($issued->json('data.issued_at'));

        $paid = $this->actingAs($this->user)
            ->patchJson("/api/v1/invoices/{$invoiceId}/status", ['status' => 'paid'])
            ->assertOk();
        $this->assertNotNull($paid->json('data.paid_at'));

        // paid is terminal
        $this->actingAs($this->user)
            ->patchJson("/api/v1/invoices/{$invoiceId}/status", ['status' => 'cancelled'])
            ->assertStatus(422);
    }

    public function test_issued_invoice_is_immutable(): void
    {
        ['so' => $so, 'pieces' => $pieces] = $this->makeConfirmedSoWithRolls();
        $doId = $this->makeConfirmedDo($so, $pieces);

        $invoiceId = $this->actingAs($this->user)
            ->postJson('/api/v1/invoices', ['do_id' => $doId, 'invoice_date' => '2026-07-11'])
            ->json('data.id');
        $this->actingAs($this->user)
            ->patchJson("/api/v1/invoices/{$invoiceId}/status", ['status' => 'issued'])
            ->assertOk();

        $this->actingAs($this->user)
            ->putJson("/api/v1/invoices/{$invoiceId}", ['invoice_date' => '2026-07-12'])
            ->assertStatus(422);

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/invoices/{$invoiceId}")
            ->assertStatus(422);
    }

    public function test_draft_edit_reprices_lines_and_recalculates_totals(): void
    {
        ['so' => $so, 'pieces' => $pieces] = $this->makeConfirmedSoWithRolls([10.0, 20.0], unitPrice: 1000);
        $doId = $this->makeConfirmedDo($so, $pieces);

        $created = $this->actingAs($this->user)
            ->postJson('/api/v1/invoices', ['do_id' => $doId, 'invoice_date' => '2026-07-11', 'invoice_type' => 'non_tax'])
            ->assertCreated();
        $invoiceId = $created->json('data.id');
        $soItemId  = $created->json('data.items.0.so_item_id');

        $this->actingAs($this->user)
            ->putJson("/api/v1/invoices/{$invoiceId}", [
                'invoice_date'     => '2026-07-11',
                'transport_charge' => 100,
                'items'            => [[
                    'so_item_id' => $soItemId,
                    'unit_price' => 1100,
                    'discount'   => 5,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.items.0.unit_price', 1100)
            // 30 * 1100 = 33000, -5% = 31350, +100 transport
            ->assertJsonPath('data.grand_total', 31450);
    }

    public function test_next_invoice_no_preview_is_null_without_a_delivery_order(): void
    {
        // The serial's QQQQ segment is the billed customer's code, so there is
        // nothing to preview until a delivery order (and its customer) is picked.
        $this->actingAs($this->user)
            ->getJson('/api/v1/invoices/next-invoice-no')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_next_invoice_no_preview_uses_customer_code_and_invoice_date(): void
    {
        ['so' => $so, 'pieces' => $pieces] = $this->makeConfirmedSoWithRolls();
        $doId = $this->makeConfirmedDo($so, $pieces);

        $this->actingAs($this->user)
            ->getJson("/api/v1/invoices/next-invoice-no?do_id={$doId}&invoice_date=2026-10-05")
            ->assertOk()
            ->assertJsonPath('data', '26OCT_CUS-0001_0001');
    }

    public function test_billing_source_endpoints_return_previews(): void
    {
        ['so' => $so, 'pieces' => $pieces] = $this->makeConfirmedSoWithRolls();
        $doId = $this->makeConfirmedDo($so, $pieces);

        $this->actingAs($this->user)
            ->getJson("/api/v1/invoices/billing-source/do/{$doId}")
            ->assertOk()
            ->assertJsonPath('data.source', 'do')
            ->assertJsonPath('data.guards.blocked', false)
            ->assertJsonPath('data.items.0.quantity', 30);

        $this->actingAs($this->user)
            ->getJson("/api/v1/invoices/billing-source/so/{$so->id}")
            ->assertOk()
            ->assertJsonPath('data.source', 'so');
    }
}
