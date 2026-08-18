<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Label-only rename of two costing expense types — "/SSCL" is part of the
 * NAME the business uses for the duty charge, not a tax computation (the
 * real SSCL % line of the costing is untouched).
 *
 * Renamed in place so existing inv_costing_item_expenses rows keep pointing
 * at the same expense-type ids.
 */
return new class extends Migration
{
    private const RENAMES = [
        ['costing_type' => 'fob', 'from' => 'Custom Duty',         'to' => 'Custom Duty/SSCL'],
        ['costing_type' => 'cif', 'from' => 'Import Customs Duty', 'to' => 'Import Customs Duty/SSCL'],
    ];

    public function up(): void
    {
        foreach (self::RENAMES as $rename) {
            DB::table('inv_costing_expense_types')
                ->where('costing_type', $rename['costing_type'])
                ->where('name', $rename['from'])
                ->update(['name' => $rename['to'], 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (self::RENAMES as $rename) {
            DB::table('inv_costing_expense_types')
                ->where('costing_type', $rename['costing_type'])
                ->where('name', $rename['to'])
                ->update(['name' => $rename['from'], 'updated_at' => now()]);
        }
    }
};
