<?php

declare(strict_types=1);

namespace Tests\Feature\MasterData;

use App\Models\Bank;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Settlements link to the bank master by id AND keep a snapshot of the name.
 *
 * This mirrors how payment_mode_id / payment_mode_name already work on the same
 * row, and is the pattern every major ERP uses for posted financial documents:
 * the id is for joins and reporting, the text is the audit record of what was
 * printed. These tests pin both halves.
 */
class SettlementBankLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private int $receiptId;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsService::class)->set('module.inventory', true);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (['view_banks', 'edit_banks'] as $perm) {
            Permission::create(['name' => $perm, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create(['active_modules' => ['inventory']]);
        $this->user->givePermissionTo(['view_banks', 'edit_banks']);

        // receipt_id carries a real foreign key, so settlements need a parent.
        $this->receiptId = DB::table('inv_customer_receipts')->insertGetId([
            'receipt_no'   => 'RCP-TEST-1',
            'receipt_date' => '2026-10-07',
            'customer_id'  => 1,
            'status'       => 'draft',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    /** @return list<array{0:string}> */
    public static function settlementTables(): array
    {
        return [
            'customer receipts' => ['inv_customer_receipt_settlements'],
            'supplier payments' => ['inv_supplier_payment_settlements'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('settlementTables')]
    public function test_settlement_table_has_both_the_link_and_the_snapshot(string $table): void
    {
        $this->assertTrue(Schema::hasColumn($table, 'bank_id'), "$table should link to the bank master.");
        $this->assertTrue(
            Schema::hasColumn($table, 'bank_name'),
            "$table must keep bank_name — the receipt PDF prints it, so it is the audit record.",
        );
    }

    /**
     * The failure this change exists to prevent. Renaming a bank used to strand
     * every settlement that referenced it by name; a one-character typo in the
     * Banks screen did exactly that. The link must survive, and the printed
     * name must NOT change.
     */
    public function test_renaming_a_bank_keeps_history_linked_and_printed_name_unchanged(): void
    {
        $bank = Bank::create(['bank_name' => 'Testable Bank', 'bank_code' => 'TSTB']);

        DB::table('inv_customer_receipt_settlements')->insert([
            'receipt_id'        => $this->receiptId,
            'payment_mode_id'   => 1,
            'payment_mode_code' => 'cheque',
            'payment_mode_name' => 'Cheque',
            'amount'            => 1000,
            'bank_id'           => $bank->id,
            'bank_name'         => 'Testable Bank',
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $bank->update(['bank_name' => 'Renamed Bank PLC']);

        $row = DB::table('inv_customer_receipt_settlements')->where('bank_id', $bank->id)->first();

        $this->assertNotNull($row, 'The settlement must still be linked to the bank after a rename.');
        $this->assertSame(
            'Testable Bank',
            $row->bank_name,
            'The printed bank name on a posted document must never change when the master is renamed.',
        );
        $this->assertSame('Renamed Bank PLC', $bank->fresh()->bank_name);
    }

    public function test_cash_settlements_carry_no_bank(): void
    {
        DB::table('inv_customer_receipt_settlements')->insert([
            'receipt_id'        => $this->receiptId,
            'payment_mode_id'   => 1,
            'payment_mode_code' => 'cash',
            'payment_mode_name' => 'Cash',
            'amount'            => 500,
            'bank_id'           => null,
            'bank_name'         => null,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $row = DB::table('inv_customer_receipt_settlements')->where('payment_mode_code', 'cash')->first();

        $this->assertNull($row->bank_id);
        $this->assertNull($row->bank_name);
    }

    public function test_bank_id_is_nullable_so_legacy_rows_remain_valid(): void
    {
        // A settlement recorded before the master existed keeps its text and no
        // id. It must still be insertable and readable.
        DB::table('inv_customer_receipt_settlements')->insert([
            'receipt_id'        => $this->receiptId,
            'payment_mode_id'   => 1,
            'payment_mode_code' => 'cheque',
            'payment_mode_name' => 'Cheque',
            'amount'            => 750,
            'bank_id'           => null,
            'bank_name'         => 'Some Bank Not In Master',
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $row = DB::table('inv_customer_receipt_settlements')->where('amount', 750)->first();

        $this->assertNull($row->bank_id);
        $this->assertSame('Some Bank Not In Master', $row->bank_name);
    }
}
