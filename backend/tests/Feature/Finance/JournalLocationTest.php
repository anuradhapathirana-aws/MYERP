<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Contracts\DocumentLocationSource;
use App\Contracts\JournalPoster;
use App\Services\SettingsService;
use App\Support\Journal\NullDocumentLocationSource;
use App\Support\Journal\PostingRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Database\Seeders\FinanceChartOfAccountsSeeder;
use Modules\Finance\Database\Seeders\FinanceGlAccountsSeeder;
use Modules\Finance\Models\JournalEntry;
use Modules\Finance\Services\JournalPostingService;
use Modules\Inventory\Models\CustomerMaster;
use Modules\Inventory\Models\CustomerReturn;
use Modules\Inventory\Models\Invoice;
use Modules\Inventory\Models\InvoiceItem;
use Modules\Inventory\Posting\CustomerReturnJournal;
use Modules\Inventory\Posting\InvoiceJournal;
use Modules\Inventory\Support\InventoryDocumentLocations;
use Tests\TestCase;

/**
 * Sales journals carry the location the goods left from (the DO's) or came
 * back to (the return's), so a location-filtered P&L sees that location's
 * sales. Journals posted before that are filled by
 * finance:backfill-journal-locations, which only ever fills blanks.
 */
class JournalLocationTest extends TestCase
{
    use RefreshDatabase;

    private CustomerMaster $customer;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsService::class)->set('module.inventory', true);
        app(SettingsService::class)->set('module.finance', true);
        app(SettingsService::class)->set(JournalPostingService::SETTING_POSTING_MODE, JournalPostingService::MODE_IMMEDIATE);

        $this->customer = CustomerMaster::create([
            'customer_code' => 'CUS-0001', 'customer_name' => 'Nimal Stores', 'customer_type' => 'Retail',
        ]);

        $this->seed(FinanceChartOfAccountsSeeder::class);
        $this->seed(FinanceGlAccountsSeeder::class);
    }

    // â”€â”€ Fixtures â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    private function deliveryOrder(int $locationId): int
    {
        static $n = 0;
        $n++;

        return (int) DB::table('inv_delivery_orders')->insertGetId([
            'do_no' => "DO-LOC-{$n}", 'so_id' => $n, 'customer_id' => $this->customer->id,
            'document_date' => '2026-11-10', 'delivery_date' => '2026-11-10', 'status' => 'confirmed',
            'location_id' => $locationId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function postedInvoice(?int $doId): Invoice
    {
        static $n = 0;
        $n++;

        $invoice = Invoice::create([
            'invoice_no' => sprintf('INV-LOC-%04d', $n), 'so_id' => $n, 'do_id' => $doId,
            'customer_id' => $this->customer->id, 'invoice_date' => '2026-11-10', 'status' => 'issued',
            'invoice_type' => 'tax', 'subtotal' => 1180, 'transport_charge' => 0, 'grand_total' => 1180,
        ]);
        InvoiceItem::create([
            'invoice_id' => $invoice->id, 'so_item_id' => $n, 'product_id' => 1, 'quantity' => 1,
            'unit_price' => 1000, 'discount' => 0, 'tax' => 18, 'line_total' => 1180,
        ]);

        app(JournalPoster::class)->post(InvoiceJournal::build($invoice->fresh()));

        return $invoice;
    }

    private function postedReturn(Invoice $invoice, int $locationId): CustomerReturn
    {
        $return = CustomerReturn::create([
            'return_no' => 'RET-LOC-' . $invoice->id, 'return_date' => '2026-11-12',
            'customer_id' => $this->customer->id, 'invoice_id' => $invoice->id, 'store_id' => 1,
            'location_id' => $locationId, 'status' => 'confirmed', 'total_amount' => 0, 'applied_to_invoice' => 0,
        ]);
        DB::table('inv_customer_return_items')->insert([
            'return_id' => $return->id, 'invoice_item_id' => $invoice->items()->value('id'), 'product_id' => 1,
            'quantity' => 1, 'unit_price' => 1180, 'discount' => 0, 'tax' => 18, 'line_total' => 1180,
            'store_id' => 1, 'location_id' => $locationId, 'condition' => 'good', 'reason' => 'damaged',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $return->update(['total_amount' => 1180, 'applied_to_invoice' => 1180]);

        app(JournalPoster::class)->post(CustomerReturnJournal::build($return->fresh()));

        return $return;
    }

    private function journal(string $sourceType, int $sourceId): JournalEntry
    {
        return JournalEntry::where('source_type', $sourceType)->where('source_id', $sourceId)->with('lines')->firstOrFail();
    }

    /** @return list<int|null> */
    private function lineLocations(JournalEntry $j): array
    {
        return $j->fresh('lines')->lines->pluck('location_id')->map(fn ($v) => $v === null ? null : (int) $v)->unique()->values()->all();
    }

    /** Old data: a journal posted before its builder set a location. */
    private function blank(JournalEntry $j): void
    {
        DB::table('fin_journal_entries')->where('id', $j->id)->update(['location_id' => null]);
        DB::table('fin_journal_lines')->where('journal_entry_id', $j->id)->update(['location_id' => null]);
    }

    // â”€â”€ Posting â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function test_an_invoice_journal_takes_its_dos_location(): void
    {
        $invoice = $this->postedInvoice($this->deliveryOrder(4));
        $journal = $this->journal(PostingRole::SOURCE_INVOICE, $invoice->id);

        $this->assertSame(4, (int) $journal->location_id);
        $this->assertSame([4], $this->lineLocations($journal), 'Every line, Sales Revenue included.');
    }

    public function test_a_direct_so_invoice_has_no_location(): void
    {
        $journal = $this->journal(PostingRole::SOURCE_INVOICE, $this->postedInvoice(null)->id);

        $this->assertNull($journal->location_id);
        $this->assertSame([null], $this->lineLocations($journal));
    }

    public function test_a_customer_return_journal_takes_the_returns_location(): void
    {
        $invoice = $this->postedInvoice($this->deliveryOrder(4));
        $return  = $this->postedReturn($invoice, 5);

        $this->assertSame([5], $this->lineLocations($this->journal(PostingRole::SOURCE_CUSTOMER_RETURN, $return->id)));
    }

    // â”€â”€ Backfill â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function test_the_backfill_is_a_dry_run_unless_applied(): void
    {
        $journal = $this->journal(PostingRole::SOURCE_INVOICE, $this->postedInvoice($this->deliveryOrder(4))->id);
        $this->blank($journal);

        $this->artisan('finance:backfill-journal-locations')
            ->expectsOutputToContain('Dry run: 1 journal(s), 3 line(s) would get a location')
            ->assertSuccessful();

        $this->assertSame([null], $this->lineLocations($journal));
        $this->assertSame(0, DB::table('core_audit_logs')->where('event', 'location_backfilled')->count());
    }

    public function test_the_backfill_fills_invoices_returns_and_their_reversals(): void
    {
        $invoice  = $this->postedInvoice($this->deliveryOrder(4));
        $return   = $this->postedReturn($invoice, 5);
        $direct   = $this->postedInvoice(null);

        $invJ = $this->journal(PostingRole::SOURCE_INVOICE, $invoice->id);
        $retJ = $this->journal(PostingRole::SOURCE_CUSTOMER_RETURN, $return->id);
        $dirJ = $this->journal(PostingRole::SOURCE_INVOICE, $direct->id);

        app(JournalPostingService::class)->reverse($invJ->fresh());
        $reversal = JournalEntry::where('reverses_journal_id', $invJ->id)->firstOrFail();

        foreach ([$invJ, $retJ, $dirJ, $reversal] as $j) {
            $this->blank($j);
        }

        $before = DB::table('fin_journal_lines')->orderBy('id')->get(['id', 'ledger_account_id', 'debit', 'credit'])->toArray();

        $this->artisan('finance:backfill-journal-locations', ['--apply' => true])
            ->expectsOutputToContain('Done: 3 journal(s)')
            ->assertSuccessful();

        $this->assertSame([4], $this->lineLocations($invJ));
        $this->assertSame([4], $this->lineLocations($reversal), 'A reversal follows its original.');
        $this->assertSame([5], $this->lineLocations($retJ));
        $this->assertSame([null], $this->lineLocations($dirJ), 'No DO, no location: left blank.');
        $this->assertSame(4, (int) $invJ->fresh()->location_id);

        // Nothing but the location moved.
        $this->assertEquals($before, DB::table('fin_journal_lines')->orderBy('id')->get(['id', 'ledger_account_id', 'debit', 'credit'])->toArray());
        $this->assertSame(3, DB::table('core_audit_logs')->where('event', 'location_backfilled')->count());

        // Run again: nothing left to do.
        $this->artisan('finance:backfill-journal-locations')
            ->expectsOutputToContain('Nothing to fill')
            ->assertSuccessful();
    }

    public function test_the_backfill_never_overwrites_a_location(): void
    {
        $journal = $this->journal(PostingRole::SOURCE_INVOICE, $this->postedInvoice($this->deliveryOrder(4))->id);

        // One line was tagged by hand to another location; the rest are blank.
        $this->blank($journal);
        $first = $journal->lines->first()->id;
        DB::table('fin_journal_lines')->where('id', $first)->update(['location_id' => 9]);

        $this->artisan('finance:backfill-journal-locations', ['--apply' => true])->assertSuccessful();

        $this->assertSame(9, (int) DB::table('fin_journal_lines')->where('id', $first)->value('location_id'));
        $this->assertSame(2, DB::table('fin_journal_lines')->where('journal_entry_id', $journal->id)->where('location_id', 4)->count());
    }

    public function test_without_inventory_nothing_is_filled(): void
    {
        $journal = $this->journal(PostingRole::SOURCE_INVOICE, $this->postedInvoice($this->deliveryOrder(4))->id);
        $this->blank($journal);

        $this->app->instance(DocumentLocationSource::class, new NullDocumentLocationSource());

        $this->artisan('finance:backfill-journal-locations', ['--apply' => true])
            ->expectsOutputToContain('Nothing to fill')
            ->assertSuccessful();

        $this->assertSame([null], $this->lineLocations($journal));
    }

    public function test_inventory_is_bound_as_the_location_source(): void
    {
        $this->assertInstanceOf(InventoryDocumentLocations::class, app(DocumentLocationSource::class));
        $this->assertSame([], app(DocumentLocationSource::class)->locationsFor('grn', [1]));
    }
}
