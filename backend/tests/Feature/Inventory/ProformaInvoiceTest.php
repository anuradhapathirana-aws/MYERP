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

class ProformaInvoiceTest extends TestCase
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
            'view_proforma_invoices', 'create_proforma_invoices', 'edit_proforma_invoices', 'delete_proforma_invoices',
        ];
        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
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
            'grn_no'   => sprintf('GRN-PI-%04d', $seq),
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

    private function giveConfirmedCosting(int $grnItemId, float $beforeTax, float $afterTax, float $vatPct): void
    {
        $grnLine = DB::table('inv_goods_received_note_items')->where('id', $grnItemId)->first(['grn_id', 'product_id']);

        $costingId = DB::table('inv_costings')->insertGetId([
            'document_no'  => 'CST-PI-' . $grnItemId,
            'reference_no' => 'CRef-PI-' . $grnItemId,
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

    private function createProforma(SalesOrder $so, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->postJson('/api/v1/proforma-invoices', array_merge([
            'so_id'         => $so->id,
            'proforma_date' => '2026-07-09',
        ], $extra));
    }

    private function issue(int $proformaId): void
    {
        $this->actingAs($this->user)
            ->patchJson("/api/v1/proforma-invoices/{$proformaId}/status", ['status' => 'issued'])
            ->assertOk()
            ->assertJsonPath('data.status', 'issued');
    }

    private function proformaStatus(int $proformaId): string
    {
        return $this->actingAs($this->user)->getJson("/api/v1/proforma-invoices/{$proformaId}")->json('data.status');
    }

    // ── Auth & permissions ──────────────────────────────────────────────────

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/proforma-invoices')->assertUnauthorized();
    }

    public function test_user_without_view_permission_is_forbidden(): void
    {
        $restricted = User::factory()->create(['active_modules' => ['inventory']]);

        $this->actingAs($restricted)->getJson('/api/v1/proforma-invoices')->assertForbidden();
    }

    // ── Create ──────────────────────────────────────────────────────────────

    public function test_proforma_quotes_full_so_with_locked_prices_and_no_stock_effect(): void
    {
        ['so' => $so] = $this->makeConfirmedSoWithRolls([10.0, 20.0], unitPrice: 1000, discount: 10, transport: 500);
        $stockRows = DB::table('inv_stock_transactions')->count();

        $this->createProforma($so, ['invoice_type' => 'non_tax'])
            ->assertCreated()
            ->assertJsonPath('data.proforma_no', 'PI-26JUL_CUS-0001_0001')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.source_type', 'sales_order')
            ->assertJsonPath('data.source_id', $so->id)
            ->assertJsonPath('data.valid_until', '2026-07-23')
            ->assertJsonPath('data.items.0.quantity', 30)
            ->assertJsonPath('data.items.0.unit_price', 1000)
            ->assertJsonPath('data.items.0.discount', 10)
            ->assertJsonPath('data.items.0.tax', 0)
            ->assertJsonPath('data.subtotal', 30000)
            ->assertJsonPath('data.transport_charge', 500)
            ->assertJsonPath('data.grand_total', 27500);

        $this->assertSame($stockRows, DB::table('inv_stock_transactions')->count());
        $this->assertSame(0, DB::table('inv_customer_receipts')->count());
    }

    public function test_proforma_number_is_never_taken_from_input(): void
    {
        ['so' => $so] = $this->makeConfirmedSoWithRolls();

        $this->createProforma($so, ['proforma_no' => 'HACKED'])
            ->assertCreated()
            ->assertJsonPath('data.proforma_no', 'PI-26JUL_CUS-0001_0001');
    }

    public function test_proforma_from_unconfirmed_so_is_rejected(): void
    {
        ['so' => $so] = $this->makeConfirmedSoWithRolls();
        DB::table('inv_sales_orders')->where('id', $so->id)->update(['status' => 'draft']);

        $this->createProforma($so)->assertStatus(422);
    }

    public function test_only_one_live_proforma_per_so_and_cancel_frees_it(): void
    {
        ['so' => $so] = $this->makeConfirmedSoWithRolls();

        $id = $this->createProforma($so)->assertCreated()->json('data.id');
        $this->createProforma($so)->assertStatus(422);

        $this->actingAs($this->user)
            ->patchJson("/api/v1/proforma-invoices/{$id}/status", ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->createProforma($so)
            ->assertCreated()
            ->assertJsonPath('data.proforma_no', 'PI-26JUL_CUS-0001_0002');
    }

    public function test_proforma_rejected_once_so_is_already_invoiced(): void
    {
        ['so' => $so, 'pieces' => $pieces] = $this->makeConfirmedSoWithRolls();
        $doId = $this->makeConfirmedDo($so, [$pieces[0]]);
        $this->actingAs($this->user)
            ->postJson('/api/v1/invoices', ['do_id' => $doId, 'invoice_date' => '2026-07-11'])
            ->assertCreated();

        $this->createProforma($so)->assertStatus(422);
    }

    // ── Edit / status ───────────────────────────────────────────────────────

    public function test_draft_edit_updates_header_and_resyncs_lines_from_so(): void
    {
        ['so' => $so] = $this->makeConfirmedSoWithRolls([10.0, 20.0], unitPrice: 1180);
        $id = $this->createProforma($so, ['invoice_type' => 'non_tax'])->json('data.id');

        $this->actingAs($this->user)
            ->putJson("/api/v1/proforma-invoices/{$id}", [
                'proforma_date' => '2026-07-09',
                'valid_until'   => '2026-08-09',
                'payment_terms' => '50% advance',
                'invoice_type'  => 'tax',
            ])
            ->assertOk()
            ->assertJsonPath('data.valid_until', '2026-08-09')
            ->assertJsonPath('data.payment_terms', '50% advance')
            ->assertJsonPath('data.invoice_type', 'tax')
            // Uncosted rolls: 1180 ÷ 1.18 = 1000 + 18% VAT
            ->assertJsonPath('data.items.0.unit_price', 1000)
            ->assertJsonPath('data.items.0.tax', 18)
            ->assertJsonPath('data.grand_total', 35400);
    }

    public function test_issued_proforma_is_immutable_and_undeletable(): void
    {
        ['so' => $so] = $this->makeConfirmedSoWithRolls();
        $id = $this->createProforma($so)->json('data.id');
        $this->issue($id);

        $this->actingAs($this->user)
            ->putJson("/api/v1/proforma-invoices/{$id}", ['proforma_date' => '2026-07-09'])
            ->assertStatus(422);

        $this->actingAs($this->user)->deleteJson("/api/v1/proforma-invoices/{$id}")->assertStatus(422);
    }

    public function test_draft_proforma_can_be_deleted(): void
    {
        ['so' => $so] = $this->makeConfirmedSoWithRolls();
        $id = $this->createProforma($so)->json('data.id');

        $this->actingAs($this->user)->deleteJson("/api/v1/proforma-invoices/{$id}")->assertNoContent();
        $this->assertSoftDeleted('inv_proforma_invoices', ['id' => $id]);
        $this->assertSame(0, DB::table('inv_proforma_invoice_items')->where('proforma_id', $id)->count());
    }

    public function test_system_statuses_cannot_be_set_by_user(): void
    {
        ['so' => $so] = $this->makeConfirmedSoWithRolls();
        $id = $this->createProforma($so)->json('data.id');

        $this->actingAs($this->user)
            ->patchJson("/api/v1/proforma-invoices/{$id}/status", ['status' => 'converted'])
            ->assertStatus(422);
    }

    // ── Invoice auto-link ───────────────────────────────────────────────────

    public function test_do_invoice_auto_links_and_bills_proforma_tax_price_even_after_costing_changes(): void
    {
        ['so' => $so, 'pieces' => $pieces] = $this->makeConfirmedSoWithRolls([10.0, 20.0], unitPrice: 1180);
        $this->giveConfirmedCosting($pieces[0]->grn_item_id, beforeTax: 1000, afterTax: 1180, vatPct: 18);

        $pf = $this->createProforma($so, ['invoice_type' => 'tax'])
            ->assertJsonPath('data.items.0.unit_price', 1000)
            ->assertJsonPath('data.items.0.tax', 18);
        $id = $pf->json('data.id');
        $this->issue($id);

        // Costing is revised after the quote went out
        DB::table('inv_costing_items')->update(['before_tax_price' => 900, 'before_tax_price_base' => 900]);

        $doId = $this->makeConfirmedDo($so, $pieces);

        $this->actingAs($this->user)
            ->getJson("/api/v1/invoices/billing-source/do/{$doId}")
            ->assertOk()
            ->assertJsonPath('data.proforma.id', $id)
            ->assertJsonPath('data.default_invoice_type', 'tax')
            ->assertJsonPath('data.items.0.tax_unit_price', 1000);

        $invoice = $this->actingAs($this->user)
            ->postJson('/api/v1/invoices', ['do_id' => $doId, 'invoice_date' => '2026-07-11'])
            ->assertCreated()
            ->assertJsonPath('data.proforma_id', $id)
            ->assertJsonPath('data.proforma.proforma_no', $pf->json('data.proforma_no'))
            ->assertJsonPath('data.invoice_type', 'tax')
            ->assertJsonPath('data.items.0.unit_price', 1000)
            ->assertJsonPath('data.items.0.tax', 18)
            ->assertJsonPath('data.items.0.proforma_item_id', $pf->json('data.items.0.id'))
            ->assertJsonPath('data.grand_total', 35400);

        $this->assertSame('converted', $this->proformaStatus($id));
        $this->assertNotNull($invoice->json('data.id'));
    }

    public function test_partial_deliveries_move_proforma_to_partially_invoiced_then_converted_and_back(): void
    {
        ['so' => $so, 'pieces' => $pieces] = $this->makeConfirmedSoWithRolls([10.0, 20.0], unitPrice: 1000);
        $id = $this->createProforma($so, ['invoice_type' => 'non_tax'])->json('data.id');
        $this->issue($id);

        $do1 = $this->makeConfirmedDo($so, [$pieces[0]]);
        $this->actingAs($this->user)
            ->postJson('/api/v1/invoices', ['do_id' => $do1, 'invoice_date' => '2026-07-11'])
            ->assertCreated()
            ->assertJsonPath('data.proforma_id', $id);
        $this->assertSame('partially_invoiced', $this->proformaStatus($id));

        $this->actingAs($this->user)
            ->getJson("/api/v1/proforma-invoices/{$id}")
            ->assertJsonPath('data.items.0.invoiced_quantity', 10)
            ->assertJsonCount(1, 'data.invoices');

        $do2 = $this->makeConfirmedDo($so, [$pieces[1]]);
        $inv2 = $this->actingAs($this->user)
            ->postJson('/api/v1/invoices', ['do_id' => $do2, 'invoice_date' => '2026-07-12'])
            ->assertCreated()
            ->json('data.id');
        $this->assertSame('converted', $this->proformaStatus($id));

        // Cancelling an invoice rolls the proforma back
        $this->actingAs($this->user)
            ->patchJson("/api/v1/invoices/{$inv2}/status", ['status' => 'cancelled'])
            ->assertOk();
        $this->assertSame('partially_invoiced', $this->proformaStatus($id));
    }

    public function test_deleting_draft_invoice_rolls_proforma_back_to_issued(): void
    {
        ['so' => $so, 'pieces' => $pieces] = $this->makeConfirmedSoWithRolls();
        $id = $this->createProforma($so)->json('data.id');
        $this->issue($id);

        $doId = $this->makeConfirmedDo($so, $pieces);
        $invId = $this->actingAs($this->user)
            ->postJson('/api/v1/invoices', ['do_id' => $doId, 'invoice_date' => '2026-07-11'])
            ->json('data.id');
        $this->assertSame('converted', $this->proformaStatus($id));

        $this->actingAs($this->user)->deleteJson("/api/v1/invoices/{$invId}")->assertNoContent();
        $this->assertSame('issued', $this->proformaStatus($id));
    }

    public function test_proforma_with_live_invoices_cannot_be_cancelled(): void
    {
        ['so' => $so, 'pieces' => $pieces] = $this->makeConfirmedSoWithRolls();
        $id = $this->createProforma($so)->json('data.id');
        $this->issue($id);

        $doId = $this->makeConfirmedDo($so, [$pieces[0]]);
        $this->actingAs($this->user)
            ->postJson('/api/v1/invoices', ['do_id' => $doId, 'invoice_date' => '2026-07-11'])
            ->assertCreated();

        $this->actingAs($this->user)
            ->patchJson("/api/v1/proforma-invoices/{$id}/status", ['status' => 'cancelled'])
            ->assertStatus(422);
    }

    public function test_draft_proforma_is_not_linked_to_invoices(): void
    {
        ['so' => $so, 'pieces' => $pieces] = $this->makeConfirmedSoWithRolls();
        $this->createProforma($so)->assertCreated(); // left in draft

        $doId = $this->makeConfirmedDo($so, $pieces);
        $this->actingAs($this->user)
            ->postJson('/api/v1/invoices', ['do_id' => $doId, 'invoice_date' => '2026-07-11'])
            ->assertCreated()
            ->assertJsonPath('data.proforma_id', null);
    }

    // ── Preview & PDF ───────────────────────────────────────────────────────

    public function test_next_no_and_source_preview(): void
    {
        ['so' => $so] = $this->makeConfirmedSoWithRolls([10.0, 20.0], unitPrice: 1180);

        $this->actingAs($this->user)
            ->getJson("/api/v1/proforma-invoices/next-no?so_id={$so->id}&proforma_date=2026-08-01")
            ->assertOk()
            ->assertJsonPath('data', 'PI-26AUG_CUS-0001_0001');

        $this->actingAs($this->user)
            ->getJson("/api/v1/proforma-invoices/source/so/{$so->id}")
            ->assertOk()
            ->assertJsonPath('data.guards.so_confirmed', true)
            ->assertJsonPath('data.guards.has_live_proforma', false)
            ->assertJsonPath('data.items.0.unit_price', 1180)
            ->assertJsonPath('data.items.0.tax_unit_price', 1000)
            ->assertJsonPath('data.items.0.tax_vat_pct', 18);
    }

    public function test_pdf_downloads(): void
    {
        ['so' => $so] = $this->makeConfirmedSoWithRolls();
        $id = $this->createProforma($so, ['payment_terms' => '100% advance'])->json('data.id');

        $this->actingAs($this->user)
            ->get("/api/v1/proforma-invoices/{$id}/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }
}
