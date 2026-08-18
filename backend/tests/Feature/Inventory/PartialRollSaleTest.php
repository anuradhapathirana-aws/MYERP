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
use Modules\Inventory\Models\ProductLocationStore;
use Modules\Inventory\Models\SalesOrder;
use Modules\Inventory\Models\SalesOrderPiece;
use Modules\Inventory\Models\StockTransaction;
use Modules\Inventory\Models\UnitCategory;
use Modules\Inventory\Models\UnitConversion;
use Modules\Inventory\Models\UnitType;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Fabric is bought by the metre and sold by the yard, and a customer rarely wants a
 * whole roll. So a sales order sells a quantity in Yard, the stock ledger reduces in
 * metres, and a roll that is only partly sold gets CUT: the offcut returns to stock as
 * a new roll with its own QR label.
 */
class PartialRollSaleTest extends TestCase
{
    use RefreshDatabase;

    /** 1 m = 1.09361 yd (and back), the rate a user would enter in Unit Conversions. */
    private const YARDS_PER_METRE = 1.09361;

    private User $user;
    private CustomerMaster $customer;
    private UnitType $metre;
    private UnitType $yard;
    private int $storeId;
    private int $locationId;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsService::class)->set('module.inventory', true);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'view_grns', 'create_grns', 'confirm_grns',
            'view_sales_orders', 'create_sales_orders',
            'view_delivery_orders', 'create_delivery_orders', 'edit_delivery_orders',
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

        // Length category, stocked in metres, sellable in yards.
        $length      = UnitCategory::create(['name' => 'Length']);
        $this->metre = UnitType::create(['unit_category_id' => $length->id, 'name' => 'Metre', 'symbol' => 'm']);
        $this->yard  = UnitType::create(['unit_category_id' => $length->id, 'name' => 'Yard', 'symbol' => 'yd']);
        $length->update(['base_unit_type_id' => $this->metre->id]);

        UnitConversion::create([
            'from_unit_type_id' => $this->metre->id,
            'to_unit_type_id'   => $this->yard->id,
            'multiplier'        => self::YARDS_PER_METRE,
        ]);
        UnitConversion::create([
            'from_unit_type_id' => $this->yard->id,
            'to_unit_type_id'   => $this->metre->id,
            'multiplier'        => 1 / self::YARDS_PER_METRE,
        ]);

        [$this->storeId, $this->locationId] = $this->makeStoreAndLocation();
    }

    /** @return array{0: int, 1: int} */
    private function makeStoreAndLocation(): array
    {
        $locationId = DB::table('inv_locations')->insertGetId([
            'company_id'          => 1,
            'industry_id'         => 1,
            'location_code'       => 'LOC-1',
            'location_name'       => 'Test Location',
            'country'             => 'LK',
            'loc_street_address'  => 'Street',
            'loc_city'            => 'City',
            'loc_country'         => 'LK',
            'loc_state'           => 'State',
            'loc_postal_zip_code' => '00000',
            'financial_year'      => '2026',
            'stock_releasing_method' => 'FIFO',
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        $storeId = DB::table('inv_stores')->insertGetId([
            'store_type_id' => 1,
            'location_id'   => $locationId,
            'store_code'    => 'STR-1',
            'store_name'    => 'Test Store',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        return [$storeId, $locationId];
    }

    /** A metre-stocked product with the given rolls already in stock (weights in metres). */
    private function productWithRolls(array $metres): array
    {
        static $seq = 0;
        $seq++;

        $product = Product::factory()->create(['base_unit_type_id' => $this->metre->id]);

        $grn = GoodsReceivedNote::create([
            'grn_no'      => sprintf('GRN-ROLL-%04d', $seq),
            'grn_date'    => '2026-07-01',
            'status'      => 'confirmed',
            'store_id'    => $this->storeId,
            'location_id' => $this->locationId,
        ]);

        $item = GoodsReceivedNoteItem::create([
            'grn_id'            => $grn->id,
            'product_id'        => $product->id,
            'unit_id'           => $this->metre->id,
            'quantity_received' => array_sum($metres),
            'base_quantity'     => array_sum($metres),
            'conversion_factor' => 1,
            'unit_price'        => 100,
        ]);

        $rolls = [];
        foreach ($metres as $i => $length) {
            $rolls[] = GrnItemPiece::create([
                'grn_item_id' => $item->id,
                'grn_id'      => $grn->id,
                'product_id'  => $product->id,
                'store_id'    => $this->storeId,
                'location_id' => $this->locationId,
                'piece_no'    => $i + 1,
                'roll_no'     => 'R-' . ($i + 1),
                'weight'      => $length,
                'piece_code'  => sprintf('%s-I001-P%03d', $grn->grn_no, $i + 1),
                'status'      => GrnItemPiece::STATUS_IN_STOCK,
            ]);
        }

        ProductLocationStore::create([
            'product_id'    => $product->id,
            'store_id'      => $this->storeId,
            'location_id'   => $this->locationId,
            'current_stock' => array_sum($metres),
        ]);

        return ['product' => $product, 'rolls' => $rolls];
    }

    /** Sell $quantity yards of $product off the given rolls. */
    private function sellYards(Product $product, array $rolls, float $quantity, array $takes = []): SalesOrder
    {
        $line = [
            'product_id'  => $product->id,
            'unit_id'     => $this->yard->id,
            'quantity'    => $quantity,
            'unit_price'  => 40,
            'piece_codes' => array_map(fn (GrnItemPiece $r) => $r->piece_code, $rolls),
        ];

        if ($takes !== []) {
            $line['piece_takes'] = $takes;
        }

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/sales-orders', [
                'order_date'      => '2026-07-09',
                'customer_id'     => $this->customer->id,
                'sales_person_id' => $this->user->id,
                'status'          => 'confirmed',
                'items'           => [$line],
            ]);

        $response->assertCreated();

        return SalesOrder::findOrFail($response->json('data.id'));
    }

    private function dispatch(SalesOrder $so, array $rolls): void
    {
        $soItem = $so->items()->sole();

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/delivery-orders', [
                'so_id'         => $so->id,
                'delivery_date' => '2026-07-10',
                'items'         => [[
                    'so_item_id' => $soItem->id,
                    'piece_ids'  => array_map(fn (GrnItemPiece $r) => $r->id, $rolls),
                ]],
            ]);
        $response->assertCreated();

        $this->actingAs($this->user)
            ->patchJson("/api/v1/delivery-orders/{$response->json('data.id')}/status", ['status' => 'confirmed'])
            ->assertOk();
    }

    private function currentStock(Product $product): float
    {
        return (float) ProductLocationStore::where('product_id', $product->id)->value('current_stock');
    }

    // ── The headline case ───────────────────────────────────────────────────

    public function test_selling_50_yards_off_a_100_metre_roll_cuts_it_and_leaves_a_remnant(): void
    {
        ['product' => $product, 'rolls' => $rolls] = $this->productWithRolls([100.0]);

        // 50 yd = 45.720046 m
        $expectedMetres = round(50 / self::YARDS_PER_METRE, 6);

        $so     = $this->sellYards($product, $rolls, 50);
        $soItem = $so->items()->sole();

        // The line speaks the customer's language; the ledger speaks the warehouse's.
        $this->assertSame(50.0, (float) $soItem->quantity);
        $this->assertSame($this->yard->id, (int) $soItem->unit_id, 'the SO line stays in Yard');
        $this->assertSame($expectedMetres, (float) $soItem->base_quantity);

        // The whole roll is reserved even though only part of it is sold.
        $soPiece = SalesOrderPiece::where('so_id', $so->id)->sole();
        $this->assertSame(100.0, (float) $soPiece->weight);
        $this->assertSame($expectedMetres, (float) $soPiece->taken_quantity);

        $this->dispatch($so, $rolls);

        // Only the sold length leaves stock — NOT the whole 100 m roll.
        $this->assertSame(round(100 - $expectedMetres, 6), $this->currentStock($product));

        $ledger = StockTransaction::where('product_id', $product->id)->where('qty_out', '>', 0)->sole();
        $this->assertSame($expectedMetres, (float) $ledger->qty_out);
        $this->assertSame($this->metre->id, (int) $ledger->unit_id, 'stock reduces in the base UOM');

        // The original roll is gone; a remnant took its place.
        $original = $rolls[0]->fresh();
        $this->assertSame(GrnItemPiece::STATUS_DELIVERED, $original->status);

        $remnant = GrnItemPiece::where('parent_piece_id', $original->id)->sole();
        $this->assertSame(round(100 - $expectedMetres, 6), (float) $remnant->weight);
        $this->assertSame(GrnItemPiece::STATUS_IN_STOCK, $remnant->status);
        $this->assertSame($original->piece_code . '-C1', $remnant->piece_code);
        $this->assertNull($remnant->printed_at, 'the remnant needs a fresh QR label');

        // Cutting a roll re-labels stock that is already on hand — it must not post an
        // inbound movement, or the remnant's length would be counted into stock twice.
        $this->assertSame(
            0,
            StockTransaction::where('product_id', $product->id)->where('qty_in', '>', 0)->count(),
            'cutting a roll must not receive stock',
        );
    }

    public function test_a_roll_sold_whole_is_not_cut(): void
    {
        ['product' => $product, 'rolls' => $rolls] = $this->productWithRolls([100.0]);

        // No quantity typed — scanning the roll means selling it whole.
        $so = $this->sellYards($product, $rolls, round(100 * self::YARDS_PER_METRE, 4));

        $this->dispatch($so, $rolls);

        $this->assertSame(0.0, $this->currentStock($product));
        $this->assertSame(GrnItemPiece::STATUS_DELIVERED, $rolls[0]->fresh()->status);
        $this->assertSame(0, GrnItemPiece::whereNotNull('parent_piece_id')->count(), 'nothing should be cut');
    }

    /**
     * 510 m has no exact 4-decimal equivalent in yards, and the quantity box holds 4 —
     * so the yard figure converts back to 510.000016 m, sixteen micrometres that were
     * never in stock. The rolls are the physical truth: they hold 510 m, and 510 m is
     * what leaves. Anything else strands a sliver of stock that can never be sold.
     */
    public function test_a_yard_quantity_takes_the_rolls_exact_length_not_the_round_trip(): void
    {
        ['product' => $product, 'rolls' => $rolls] = $this->productWithRolls([510.0]);

        $so = $this->sellYards($product, $rolls, round(510 * self::YARDS_PER_METRE, 4));

        $this->assertSame(510.0, (float) $so->items()->sole()->base_quantity);

        $this->dispatch($so, $rolls);

        $ledger = StockTransaction::where('product_id', $product->id)->where('qty_out', '>', 0)->sole();
        $this->assertSame(510.0, (float) $ledger->qty_out);
        $this->assertSame(0.0, $this->currentStock($product));
        $this->assertSame(0, GrnItemPiece::whereNotNull('parent_piece_id')->count(), 'no sliver left behind');
    }

    // ── The sticker the cut owes the warehouse ──────────────────────────────

    /**
     * The offcut is a physical roll wearing its parent's sticker, which now overstates it.
     * It must therefore surface as a label the warehouse still owes — and say which sticker
     * to peel off — or the roll ends up carrying two live QR codes.
     */
    public function test_a_cut_roll_joins_the_pending_label_queue_naming_the_sticker_it_replaces(): void
    {
        ['product' => $product, 'rolls' => $rolls] = $this->productWithRolls([100.0]);

        // The received roll's sticker is already on it — nothing owed before the cut.
        GrnItemPiece::whereIn('id', collect($rolls)->pluck('id'))->update(['printed_at' => now()]);
        $this->assertCount(0, $this->pendingLabels());

        $so = $this->sellYards($product, $rolls, 50);
        $this->dispatch($so, $rolls);

        $offcut = GrnItemPiece::whereNotNull('parent_piece_id')->sole();
        $labels = $this->pendingLabels();

        $this->assertCount(1, $labels, 'only the offcut is owed a sticker');
        $this->assertSame($offcut->piece_code, $labels[0]['piece_code']);
        $this->assertSame($rolls[0]->piece_code, $labels[0]['replaces'], 'names the stale sticker to peel off');
    }

    public function test_printing_the_pending_labels_clears_the_queue(): void
    {
        ['product' => $product, 'rolls' => $rolls] = $this->productWithRolls([100.0]);
        GrnItemPiece::whereIn('id', collect($rolls)->pluck('id'))->update(['printed_at' => now()]);

        $this->dispatch($this->sellYards($product, $rolls, 50), $rolls);
        $this->assertCount(1, $this->pendingLabels());

        $this->actingAs($this->user)
            ->get('/api/v1/piece-labels/pdf?print_status=pending')
            ->assertOk();

        $this->assertCount(0, $this->pendingLabels(), 'printing stamps printed_at, so the queue empties itself');
    }

    /** @return array<int, array<string, mixed>> labels for rolls whose sticker does not exist yet */
    private function pendingLabels(): array
    {
        return $this->actingAs($this->user)
            ->getJson('/api/v1/piece-labels?print_status=pending')
            ->assertOk()
            ->json('data.labels');
    }

    // ── Distribution across several rolls ───────────────────────────────────

    public function test_quantity_fills_the_oldest_rolls_first_and_cuts_only_the_last(): void
    {
        ['product' => $product, 'rolls' => $rolls] = $this->productWithRolls([100.0, 100.0, 100.0]);

        // 250 m worth of yards: rolls 1 and 2 go whole, roll 3 is cut to 50 m.
        $so = $this->sellYards($product, $rolls, round(250 * self::YARDS_PER_METRE, 4));

        $taken = SalesOrderPiece::where('so_id', $so->id)
            ->orderBy('piece_id')
            ->pluck('taken_quantity', 'piece_id')
            ->map(fn ($q) => round((float) $q, 2))
            ->values()
            ->all();

        $this->assertSame([100.0, 100.0, 50.0], $taken);

        $this->dispatch($so, $rolls);

        // Two rolls shipped whole, one cut — so exactly one remnant.
        $this->assertSame(2, GrnItemPiece::whereIn('id', [$rolls[0]->id, $rolls[1]->id])
            ->where('status', GrnItemPiece::STATUS_DELIVERED)->count());

        $remnant = GrnItemPiece::whereNotNull('parent_piece_id')->sole();
        $this->assertSame((int) $rolls[2]->id, (int) $remnant->parent_piece_id);
        $this->assertSame(50.0, round((float) $remnant->weight, 2));
        $this->assertSame(50.0, round($this->currentStock($product), 2));
    }

    public function test_the_picker_can_choose_how_much_comes_off_each_roll(): void
    {
        ['product' => $product, 'rolls' => $rolls] = $this->productWithRolls([100.0, 100.0]);

        // Take 30 m from each roll (in yards, as the picker sends them), cutting both.
        $thirtyMetresInYards = round(30 * self::YARDS_PER_METRE, 4);

        $so = $this->sellYards(
            $product,
            $rolls,
            quantity: $thirtyMetresInYards * 2,
            takes: [
                $rolls[0]->piece_code => $thirtyMetresInYards,
                $rolls[1]->piece_code => $thirtyMetresInYards,
            ],
        );

        $taken = SalesOrderPiece::where('so_id', $so->id)
            ->pluck('taken_quantity')
            ->map(fn ($q) => round((float) $q, 2))
            ->all();

        $this->assertSame([30.0, 30.0], $taken);

        $this->dispatch($so, $rolls);

        // Both rolls cut — two remnants, two labels to print.
        $this->assertSame(2, GrnItemPiece::whereNotNull('parent_piece_id')->count());
        $this->assertSame(140.0, round($this->currentStock($product), 2));
    }

    // ── Conversion rounding must not strand stock ───────────────────────────

    public function test_the_remainder_of_a_cut_roll_can_be_sold_in_the_selling_uom(): void
    {
        // A 1.828799 m remnant is what a previous yard-denominated sale leaves behind:
        // it is "2 yd" of fabric, but the conversion cannot land on it exactly.
        ['product' => $product, 'rolls' => $rolls] = $this->productWithRolls([1.828799]);

        // 2 yd converts to 1.828801 m — a hair MORE than the roll holds. Rejecting this
        // as an over-sell (which it did) leaves the remnant unsellable forever.
        $so = $this->sellYards($product, $rolls, 2);

        $soItem = $so->items()->sole();
        $this->assertSame(2.0, (float) $soItem->quantity, 'the customer still buys 2 yd');
        $this->assertSame(1.828799, (float) $soItem->base_quantity, 'stock gives what the roll actually holds');

        $this->dispatch($so, $rolls);

        // The roll is gone entirely — no sliver left behind, no phantom remnant.
        $this->assertSame(0.0, $this->currentStock($product));
        $this->assertSame(GrnItemPiece::STATUS_DELIVERED, $rolls[0]->fresh()->status);
        $this->assertSame(0, GrnItemPiece::whereNotNull('parent_piece_id')->count());
    }

    public function test_a_cut_never_leaves_dust_behind_as_a_phantom_roll(): void
    {
        ['product' => $product, 'rolls' => $rolls] = $this->productWithRolls([100.0]);

        // Sell the whole roll expressed in yards. The round-trip m -> yd -> m does not
        // land back on 100 exactly, and the difference must not become a 0.000008 m roll.
        $so = $this->sellYards($product, $rolls, round(100 * self::YARDS_PER_METRE, 4));

        $this->dispatch($so, $rolls);

        $this->assertSame(0.0, $this->currentStock($product));
        $this->assertSame(
            0,
            GrnItemPiece::whereNotNull('parent_piece_id')->count(),
            'rounding dust must not be labelled as a remnant roll',
        );
    }

    // ── Prices carry their unit ─────────────────────────────────────────────

    public function test_prices_are_returned_per_the_stocking_uom_not_the_unit_they_were_filed_in(): void
    {
        ['product' => $product, 'rolls' => $rolls] = $this->productWithRolls([100.0]);

        // The price list quotes 40 per YARD, but the product is stocked in metres.
        $channel = \Modules\Inventory\Models\SalesChannel::firstOrCreate(['sales_channel_name' => 'Default']);
        DB::table('inv_product_sales_channels')->insert([
            'product_id'       => $product->id,
            'sales_channel_id' => $channel->id,
            'unit_type_id'     => $this->yard->id,
            'selling_price'    => 40,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        // Handing "40" straight to a metre-denominated line would under-price by 9%.
        // 40 per yd is 43.7444 per m.
        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/sales-orders/product-price/{$product->id}")
            ->assertOk();

        $this->assertSame(
            round(40 / (1 / self::YARDS_PER_METRE), 8),
            round((float) $response->json('data.unit_price'), 8),
            'the price list price must be re-expressed per the stocking UOM',
        );
        $this->assertSame('m', $response->json('data.base_uom'));

        // And the roll's GRN cost (100 per m on the seeded line) comes back per metre too.
        $rolls = $this->actingAs($this->user)
            ->getJson("/api/v1/sales-orders/available-pieces/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.base_uom', 'm');

        $this->assertSame(100.0, (float) $rolls->json('data.pieces.0.grn_unit_price'));
    }

    // ── Guard rails ─────────────────────────────────────────────────────────

    public function test_cannot_sell_more_than_the_selected_rolls_hold(): void
    {
        ['product' => $product, 'rolls' => $rolls] = $this->productWithRolls([100.0]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/sales-orders', [
                'order_date'      => '2026-07-09',
                'customer_id'     => $this->customer->id,
                'sales_person_id' => $this->user->id,
                'status'          => 'confirmed',
                'items'           => [[
                    'product_id'  => $product->id,
                    'unit_id'     => $this->yard->id,
                    'quantity'    => 200, // 200 yd = 182.9 m, more than the roll holds
                    'unit_price'  => 40,
                    'piece_codes' => [$rolls[0]->piece_code],
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The selected rolls hold only 100 — add another roll or reduce the quantity.');
    }

    public function test_cannot_take_more_from_a_roll_than_it_holds(): void
    {
        ['product' => $product, 'rolls' => $rolls] = $this->productWithRolls([100.0, 100.0]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/sales-orders', [
                'order_date'      => '2026-07-09',
                'customer_id'     => $this->customer->id,
                'sales_person_id' => $this->user->id,
                'status'          => 'confirmed',
                'items'           => [[
                    'product_id'  => $product->id,
                    'unit_id'     => $this->yard->id,
                    'quantity'    => round(150 * self::YARDS_PER_METRE, 4),
                    'unit_price'  => 40,
                    'piece_codes' => [$rolls[0]->piece_code, $rolls[1]->piece_code],
                    'piece_takes' => [
                        // 120 m off a 100 m roll
                        $rolls[0]->piece_code => round(120 * self::YARDS_PER_METRE, 4),
                        $rolls[1]->piece_code => round(30 * self::YARDS_PER_METRE, 4),
                    ],
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', "Roll {$rolls[0]->piece_code} holds only 100 — cannot take 120.");
    }

    public function test_per_roll_takes_must_add_up_to_the_line_quantity(): void
    {
        ['product' => $product, 'rolls' => $rolls] = $this->productWithRolls([100.0, 100.0]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/sales-orders', [
                'order_date'      => '2026-07-09',
                'customer_id'     => $this->customer->id,
                'sales_person_id' => $this->user->id,
                'status'          => 'confirmed',
                'items'           => [[
                    'product_id'  => $product->id,
                    'unit_id'     => $this->yard->id,
                    'quantity'    => round(100 * self::YARDS_PER_METRE, 4), // line says 100 m
                    'unit_price'  => 40,
                    'piece_codes' => [$rolls[0]->piece_code, $rolls[1]->piece_code],
                    'piece_takes' => [
                        // but the rolls give 60 m
                        $rolls[0]->piece_code => round(30 * self::YARDS_PER_METRE, 4),
                        $rolls[1]->piece_code => round(30 * self::YARDS_PER_METRE, 4),
                    ],
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'The roll quantities add up to 60 but the line says 100 — they must match.']);
    }
}
