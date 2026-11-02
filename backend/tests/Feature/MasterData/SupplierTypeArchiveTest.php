<?php

declare(strict_types=1);

namespace Tests\Feature\MasterData;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Dropping supplier_type must not lose a single client value: it is archived
 * on the way out and restored exactly on rollback.
 */
class SupplierTypeArchiveTest extends TestCase
{
    use RefreshDatabase;

    /** @return object{up: callable, down: callable} the anonymous migration */
    private function migration(string $name): object
    {
        return require database_path("migrations/{$name}.php");
    }

    private function masters(): object
    {
        return $this->migration('2026_09_25_000008_drop_supplier_type_from_inv_supplier_masters');
    }

    private function payments(): object
    {
        return $this->migration('2026_09_25_000009_drop_supplier_type_from_inv_supplier_payments');
    }

    /** Puts the database back to the live schema, where the column still exists. */
    private function restoreLiveSchema(): void
    {
        $this->payments()->down();
        $this->masters()->down();
    }

    private function supplier(string $code, ?string $type): int
    {
        $id = (int) DB::table('inv_supplier_masters')->insertGetId([
            'supplier_code' => $code, 'supplier_name' => 'Supplier ' . $code,
            'supplier_group_id' => DB::table('core_supplier_groups')->where('code', 'TRADE_LOCAL')->value('id'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('inv_supplier_masters')->where('id', $id)->update(['supplier_type' => $type]);

        return $id;
    }

    public function test_values_are_archived_before_the_drop_and_restored_on_rollback(): void
    {
        $this->restoreLiveSchema();

        $trade   = $this->supplier('SUP-1', 'Trade');
        $service = $this->supplier('SUP-2', 'Service');
        $blank   = $this->supplier('SUP-3', null);

        $payment = (int) DB::table('inv_supplier_payments')->insertGetId([
            'payment_no' => 'PAY-0001', 'payment_date' => '2026-10-01', 'supplier_id' => $trade,
            'supplier_type' => 'Trade', 'status' => 'confirmed', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->masters()->up();
        $this->payments()->up();

        $this->assertFalse(Schema::hasColumn('inv_supplier_masters', 'supplier_type'));
        $this->assertFalse(Schema::hasColumn('inv_supplier_payments', 'supplier_type'));

        $archive = DB::table('core_archived_column_values')->get()
            ->mapWithKeys(fn ($r) => ["{$r->table_name}#{$r->record_id}" => $r->value]);

        $this->assertEquals([
            "inv_supplier_masters#{$trade}"    => 'Trade',
            "inv_supplier_masters#{$service}"  => 'Service',
            "inv_supplier_payments#{$payment}" => 'Trade',
        ], $archive->all()); // blank values are not archived: nothing to keep
        $this->assertArrayNotHasKey("inv_supplier_masters#{$blank}", $archive->all());

        // Rollback brings every value back and empties the archive.
        $this->restoreLiveSchema();

        $this->assertSame('Trade', DB::table('inv_supplier_masters')->where('id', $trade)->value('supplier_type'));
        $this->assertSame('Service', DB::table('inv_supplier_masters')->where('id', $service)->value('supplier_type'));
        $this->assertNull(DB::table('inv_supplier_masters')->where('id', $blank)->value('supplier_type'));
        $this->assertSame('Trade', DB::table('inv_supplier_payments')->where('id', $payment)->value('supplier_type'));
        $this->assertSame(0, DB::table('core_archived_column_values')->count());
    }

    public function test_running_twice_is_harmless(): void
    {
        $this->restoreLiveSchema();
        $this->supplier('SUP-9', 'Trade');

        $this->masters()->up();
        $this->masters()->up(); // column already gone: no-op

        $this->assertSame(1, DB::table('core_archived_column_values')->count());
    }
}
