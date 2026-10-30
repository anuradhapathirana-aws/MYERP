<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Http\Controllers\PieceLabelController;
use Modules\Inventory\Models\GoodsReceivedNote;
use Modules\Inventory\Models\GoodsReceivedNoteItem;
use Modules\Inventory\Models\GrnItemPiece;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\UnitCategory;
use Modules\Inventory\Models\UnitType;
use Modules\Inventory\Services\PieceQrCodeService;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Piece labels are drawn straight onto the PDF canvas with vector QR codes,
 * because the old HTML + PNG labels took ~0.2 s each and a large GRN's print
 * outlived the live server's request time limit.
 */
class PieceLabelPdfTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private UnitType $metre;
    private int $storeId;
    private int $locationId;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsService::class)->set('module.inventory', true);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::create(['name' => 'view_grns', 'guard_name' => 'web']);
        $this->user = User::factory()->create(['active_modules' => ['inventory']]);
        $this->user->givePermissionTo('view_grns');

        $length      = UnitCategory::create(['name' => 'Length']);
        $this->metre = UnitType::create(['unit_category_id' => $length->id, 'name' => 'Metre', 'symbol' => 'm']);
        $length->update(['base_unit_type_id' => $this->metre->id]);

        $this->locationId = DB::table('inv_locations')->insertGetId([
            'company_id' => 1, 'industry_id' => 1, 'location_code' => 'LOC-1', 'location_name' => 'Test Location',
            'country' => 'LK', 'loc_street_address' => 'Street', 'loc_city' => 'City', 'loc_country' => 'LK',
            'loc_state' => 'State', 'loc_postal_zip_code' => '00000', 'financial_year' => '2026',
            'stock_releasing_method' => 'FIFO', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->storeId = DB::table('inv_stores')->insertGetId([
            'store_type_id' => 1, 'location_id' => $this->locationId, 'store_code' => 'STR-1',
            'store_name' => 'Test Store', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── Print Piece Labels page (2-up sticker roll) ─────────────────────────

    public function test_pdf_draws_one_sticker_row_per_roll_and_stamps_printed_at(): void
    {
        $grn = $this->grnWithRolls(3);

        $response = $this->actingAs($this->user)
            ->get("/api/v1/piece-labels/pdf?grn_no={$grn->grn_no}")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertSame(3, $this->pageCount($response->getContent()));
        $this->assertSame(0, GrnItemPiece::whereNull('printed_at')->count(), 'printing stamps every roll');
    }

    public function test_pdf_prints_past_the_500_preview_limit(): void
    {
        // A GRN bigger than the screen preview — the case that used to fail on live.
        $grn = $this->grnWithRolls(501, seedQr: true);

        $content = $this->actingAs($this->user)
            ->get("/api/v1/piece-labels/pdf?grn_no={$grn->grn_no}")
            ->assertOk()
            ->getContent();

        $this->assertSame(501, $this->pageCount($content));
        $this->assertGreaterThanOrEqual(2000, PieceLabelController::MAX_PDF_LABELS);
    }

    public function test_preview_shows_500_but_reports_the_full_total(): void
    {
        $grn = $this->grnWithRolls(501, seedQr: true);

        $data = $this->actingAs($this->user)
            ->getJson("/api/v1/piece-labels?grn_no={$grn->grn_no}")
            ->assertOk()
            ->json('data');

        $this->assertCount(500, $data['labels']);
        $this->assertTrue($data['truncated']);
        $this->assertSame(501, $data['total']);
        $this->assertSame(PieceLabelController::MAX_PDF_LABELS, $data['pdf_limit']);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $data['labels'][0]['qr_data_uri']);
    }

    // ── GRN form button (A4 sheet) ──────────────────────────────────────────

    public function test_grn_a4_sheet_is_a_pdf_of_all_rolls(): void
    {
        $grn = $this->grnWithRolls(40, seedQr: true);

        $response = $this->actingAs($this->user)
            ->get("/api/v1/goods-received-notes/{$grn->id}/piece-labels/pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        // 18 cells per A4 page -> 40 rolls fill 3 pages.
        $this->assertSame(3, $this->pageCount($response->getContent()));
        $this->assertSame(0, GrnItemPiece::whereNull('printed_at')->count());
    }

    public function test_grn_a4_sheet_refuses_a_draft_grn(): void
    {
        $grn = $this->grnWithRolls(1);
        $grn->update(['status' => 'draft']);

        $this->actingAs($this->user)
            ->getJson("/api/v1/goods-received-notes/{$grn->id}/piece-labels/pdf")
            ->assertStatus(422);
    }

    // ── QR cache ────────────────────────────────────────────────────────────

    public function test_a_rolls_qr_is_encoded_once_and_keyed_by_its_url(): void
    {
        $qr  = app(PieceQrCodeService::class);
        $key = 'piece-qr:' . sha1($qr->url('GRN-TEST-I001-P001'));

        $this->assertFalse(Cache::has($key));

        $matrix = $qr->matrix('GRN-TEST-I001-P001');

        $this->assertTrue(Cache::has($key), 'the encoded grid is cached');
        $this->assertGreaterThanOrEqual(21, $matrix['modules'], 'a real QR grid (version 1 is 21x21)');
        $this->assertNotEmpty($matrix['runs']);
        $this->assertSame($matrix, $qr->matrix('GRN-TEST-I001-P001'), 'the cached grid decodes to the same code');

        // A new frontend URL changes what the sticker must encode -> a new cache entry.
        config(['app.frontend_url' => 'https://erp.example.com']);
        $this->assertFalse(Cache::has('piece-qr:' . sha1($qr->url('GRN-TEST-I001-P001'))));
    }

    public function test_warm_command_encodes_only_the_missing_codes(): void
    {
        $this->grnWithRolls(3);

        $this->artisan('inventory:warm-piece-qr')
            ->expectsOutputToContain('Done: 3 encoded, 0 already cached.')
            ->assertSuccessful();

        $this->artisan('inventory:warm-piece-qr')
            ->expectsOutputToContain('Done: 0 encoded, 3 already cached.')
            ->assertSuccessful();
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * A confirmed GRN with $count sealed rolls. $seedQr pre-caches a tiny QR grid
     * per roll so a bulk test does not spend ~30 ms encoding each real one.
     */
    private function grnWithRolls(int $count, bool $seedQr = false): GoodsReceivedNote
    {
        $product = Product::factory()->create(['base_unit_type_id' => $this->metre->id]);

        $grn = GoodsReceivedNote::create([
            'grn_no'      => 'GRN-LBL-0001',
            'grn_date'    => '2026-10-30',
            'status'      => 'confirmed',
            'store_id'    => $this->storeId,
            'location_id' => $this->locationId,
        ]);

        $item = GoodsReceivedNoteItem::create([
            'grn_id'            => $grn->id,
            'product_id'        => $product->id,
            'unit_id'           => $this->metre->id,
            'quantity_received' => $count * 10,
            'base_quantity'     => $count * 10,
            'conversion_factor' => 1,
            'unit_price'        => 100,
        ]);

        $qr   = app(PieceQrCodeService::class);
        $rows = [];

        for ($i = 1; $i <= $count; $i++) {
            $code   = sprintf('%s-I001-P%03d', $grn->grn_no, $i);
            $rows[] = [
                'grn_item_id' => $item->id,
                'grn_id'      => $grn->id,
                'product_id'  => $product->id,
                'store_id'    => $this->storeId,
                'location_id' => $this->locationId,
                'piece_no'    => $i,
                'roll_no'     => (string) $i,
                'weight'      => 10,
                'piece_code'  => $code,
                'status'      => GrnItemPiece::STATUS_IN_STOCK,
                'sale_cycle'  => 0,
                'created_at'  => now(),
                'updated_at'  => now(),
            ];

            if ($seedQr) {
                Cache::forever('piece-qr:' . sha1($qr->url($code)), "101\n010\n101");
            }
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            GrnItemPiece::insert($chunk);
        }

        return $grn;
    }

    private function pageCount(string $pdf): int
    {
        return preg_match_all('#/Type\s*/Page[^s]#', $pdf);
    }
}
