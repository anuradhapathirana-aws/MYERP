<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where each account category lands on the Profit & Loss (PlSection).
 *
 * Nullable: a category without a section still reports, under Other Income or
 * Other Expenses, so nothing is lost while the chart is being classified.
 *
 * The backfill only touches the starter chart's own category names, matched
 * together with the account type, and only where no section is set yet. A
 * renamed or client-made category is left for the accountant to classify on
 * the Account Categories screen. No other column or row is changed.
 */
return new class extends Migration
{
    /** account_type => [category_name => pl_section] */
    private const STARTER = [
        'income' => [
            'Revenue'      => 'revenue',
            'Other Income' => 'other_income',
        ],
        'expense' => [
            'Cost of Sales'                   => 'cost_of_sales',
            'Administrative Expenses'         => 'administration',
            'Selling & Distribution Expenses' => 'selling_distribution',
            'Finance Costs'                   => 'finance',
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('fin_account_categories', 'pl_section')) {
            Schema::table('fin_account_categories', function (Blueprint $table): void {
                $table->string('pl_section', 30)->nullable()->after('category_name');
            });
        }

        foreach (self::STARTER as $type => $names) {
            foreach ($names as $name => $section) {
                DB::table('fin_account_categories')
                    ->where('account_type', $type)
                    ->where('category_name', $name)
                    ->whereNull('pl_section')
                    ->update(['pl_section' => $section]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('fin_account_categories', 'pl_section')) {
            Schema::table('fin_account_categories', function (Blueprint $table): void {
                $table->dropColumn('pl_section');
            });
        }
    }
};
