<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tax vs Non-Tax invoices.
 *
 *   tax      — lines carry the costing's BEFORE-tax price and VAT is added
 *              per line on the invoice (Tax % column).
 *   non_tax  — lines carry the costing's AFTER-tax price (VAT already inside)
 *              and the invoice adds no VAT (Tax % shown as 0).
 *
 * Existing invoices were priced at the after-tax SO price with no VAT added,
 * which is exactly what non_tax now means — so they are backfilled as such.
 * New invoices default to tax.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_invoices', function (Blueprint $table): void {
            $table->string('invoice_type', 10)->default('tax')->after('status');
        });

        DB::table('inv_invoices')->update(['invoice_type' => 'non_tax']);
    }

    public function down(): void
    {
        Schema::table('inv_invoices', function (Blueprint $table): void {
            $table->dropColumn('invoice_type');
        });
    }
};
