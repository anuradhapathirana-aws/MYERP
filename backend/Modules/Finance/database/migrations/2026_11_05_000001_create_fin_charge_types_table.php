<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Charge Types: what a supplier-bill line is for (Freight, Customs Duty,
 * Import VAT, Clearing...) and therefore where it goes:
 *
 *   landed_cost      part of what the goods cost  -> its account, and pulled
 *                    into the shipment's Costing
 *   recoverable_tax  tax we claim back (Import VAT) -> Input Tax Recoverable;
 *                    cost instead while VAT is off for us
 *   expense          a period cost (demurrage, bank charges) -> its account,
 *                    never a product cost
 *
 * ledger_account_id is a hard FK (same module). fob/cif_expense_type_id point
 * at Inventory's Costing rows, so they are soft links: no FK, and Finance runs
 * without Inventory.
 *
 * Built-in rows are seeded WITHOUT accounts on purpose: the finance admin
 * creates the import cost accounts in their own chart and assigns them (user
 * decision). Until then a bill line picks its account by hand.
 */
return new class extends Migration
{
    /** code, name, treatment, FOB Costing row name, CIF Costing row name */
    private const TYPES = [
        ['FREIGHT',         'Freight',                   'landed_cost',     'Freight Charges',            null],
        ['INSURANCE',       'Insurance',                 'landed_cost',     'Insurance',                  null],
        ['CUSTOMS_DUTY',    'Customs Duty',              'landed_cost',     'Custom Duty/SSCL',           'Import Customs Duty/SSCL'],
        ['CUSTOMS_SSCL',    'SSCL (Customs)',            'landed_cost',     'Custom Duty/SSCL',           'Import Customs Duty/SSCL'],
        ['IMPORT_VAT',      'Import VAT',                'recoverable_tax', 'Custom Duty/SSCL',           'Import Customs Duty/SSCL'],
        ['CLEARING',        'Clearing & Transport',      'landed_cost',     'Clearance & Transportation', 'Clearance & Transportation'],
        ['BANK_CHARGES',    'Bank Charges',              'expense',         'Bank Charges',               'Bank Charges'],
        ['BANK_COMMISSION', 'Bank Commission (LC / TT)', 'expense',         'Bank Commission',            'Bank Commission'],
        ['DEMURRAGE',       'Demurrage & Port Penalties', 'expense',        null,                         null],
    ];

    public function up(): void
    {
        Schema::create('fin_charge_types', function (Blueprint $table): void {
            $table->id();

            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->string('treatment', 20);

            $table->foreignId('ledger_account_id')->nullable()->constrained('fin_ledger_accounts')->nullOnDelete();

            // Soft links to inv_costing_expense_types (Inventory).
            $table->unsignedBigInteger('fob_expense_type_id')->nullable();
            $table->unsignedBigInteger('cif_expense_type_id')->nullable();

            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();
        });

        $now = now();

        foreach (self::TYPES as $i => [$code, $name, $treatment, $fob, $cif]) {
            DB::table('fin_charge_types')->insert([
                'code'                => $code,
                'name'                => $name,
                'treatment'           => $treatment,
                'fob_expense_type_id' => $this->costingRow($fob, 'fob'),
                'cif_expense_type_id' => $this->costingRow($cif, 'cif'),
                'is_active'           => true,
                'is_system'           => true,
                'sort_order'          => $i + 1,
                'created_at'          => $now,
                'updated_at'          => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_charge_types');
    }

    /** The Costing row by name, when Inventory's table is there. Read-only: nothing in Inventory changes. */
    private function costingRow(?string $name, string $costingType): ?int
    {
        if ($name === null || ! Schema::hasTable('inv_costing_expense_types')) {
            return null;
        }

        $id = DB::table('inv_costing_expense_types')
            ->where('name', $name)
            ->where('costing_type', $costingType)
            ->value('id');

        return $id === null ? null : (int) $id;
    }
};
